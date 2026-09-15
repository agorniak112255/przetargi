<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

/**
 * Panel UVEX izam.system-b2b.pl — cena konta, dostępność, nazwa i kod z listy wszystkich produktów; opis i zdjęcie
 * ze strony produktu (opis tylko gdy synchronizacja może go zapisać). Sprawdzone na koncie 15.09.2026.
 *
 * Lista: 5750 pozycji po 20 na stronę, zbierana w całości przed pierwszym zapisem (rozmiary jednego wyrobu bywają
 * na sąsiednich stronach). Każda strona musi mieć licznik „Wyświetlanie rekordów od X do Y z Z” zgodny z numerem
 * strony i liczbą wierszy; zmiana w trakcie (produkt dodany/usunięty) = jedno ponowne pobranie całej listy,
 * potem błąd przebiegu bez zapisu. Wiersz bez ceny konta = błąd (utrata widoku cen), nie „brak ceny”.
 *
 * Rozmiary o tej samej cenie są jedną kartą (UvexSizeGroups); karta podaje wszystkie swoje kody (members), więc
 * synchronizacja wiąże każdy kod z kartą. Pozycja pojedyncza też podaje swój kod — dwie osobne pozycje nigdy nie
 * trafią na jedną kartę. Kod i remoteId karty = pierwszy kod grupy (sortowanie naturalne) — prawdziwy kod, bez
 * wymyślonego rdzenia; pozostałe kody i rozmiary są w variant_summary (wyszukiwanie).
 *
 * Ceny: „Twoja cena netto” w PLN (jedyna waluta widoczna na koncie); katalogowa = zakupu (sklep nie pokazuje ceny
 * bazowej); 0,00 PLN (np. pozycje „Cenniki”, „Karty charakterystyki”) = brak ceny. Dostępność dosłownie
 * („Dostępny” / „Na zamówienie”), dla grupy z podziałem na rozmiary, gdy się różni.
 *
 * Producent — ZAŁOŻENIE (sklep nie ma pola producenta): „HECKEL” gdy kod lub nazwa zawiera heckel, „HexArmor” gdy
 * nazwa zawiera hexarmor, inaczej „UVEX” (sklep firmy UVEX; większość nazw zawiera „uvex”).
 */
final class UvexB2bConnector implements B2bConnector, B2bListProgressAware
{
    /** Nieprzerwane pobieranie listy dłużej = błąd (przebieg bez postępu uznałby b2b:sync-due za przerwany). */
    private const LIST_BUDGET_SECONDS = 25 * 60;

    private const PROGRESS_EVERY_PAGES = 20;

    private const INCONSISTENT = 7001;

    private const SKIPPED_TAGS = ['input', 'button', 'select', 'option', 'textarea', 'img', 'iframe', 'svg', 'script', 'style', 'noscript'];

    private const BLOCK_TAGS = ['p', 'div', 'section', 'article', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'dl', 'dt', 'dd', 'table', 'thead', 'tbody', 'tr', 'blockquote', 'pre', 'hr'];

    private int $total = 0;

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    public function __construct(
        private readonly UvexB2bClient $client,
        private readonly UvexSizeGroups $groups = new UvexSizeGroups,
    ) {}

    public static function key(): string
    {
        return 'uvex';
    }

    public static function label(): string
    {
        return 'UVEX';
    }

    public static function host(): string
    {
        return UvexB2bClient::HOST;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new UvexB2bClient(
            (string) $account->contractor_code,
            (string) $account->username,
            (string) $account->password,
            $delayMs,
        ));
    }

    public function onListProgress(callable $callback): void
    {
        $this->listProgress = $callback;
    }

    public function login(): void
    {
        $this->client->login();
    }

    public function products(): iterable
    {
        $rows = $this->listRows();
        [$rows, $skipped] = self::withoutConflictingCodes($rows);

        $products = $skipped;
        $grouped = 0;
        foreach ($this->groups->group($rows) as $group) {
            $products[] = $this->productFor($group);
            if (count($group) > 1) {
                $grouped++;
            }
        }
        $this->total = count($products);
        $this->progress(sprintf(
            'Lista UVEX: %d pozycji → %d kart (%d grup rozmiarów o tej samej cenie)',
            count($rows) + count($skipped),
            $this->total,
            $grouped,
        ));

        foreach ($products as $product) {
            yield $product;
        }
    }

    public function totalProducts(): int
    {
        return $this->total;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        $texts = [];
        foreach ($product->raw['rows'] ?? [] as $row) {
            $texts[] = ['code' => (string) ($row['code'] ?? ''), 'name' => (string) ($row['name'] ?? '')];
        }
        if ($texts === []) {
            $texts[] = ['code' => $product->sku, 'name' => $product->name];
        }
        foreach ($texts as $text) {
            if (mb_stripos($text['code'].' '.$text['name'], 'heckel') !== false) {
                return 'HECKEL';
            }
        }
        foreach ($texts as $text) {
            if (mb_stripos($text['name'], 'hexarmor') !== false) {
                return 'HexArmor';
            }
        }

        return 'UVEX';
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            throw new RuntimeException((string) ($product->raw['reason'] ?? 'pozycja listy nieodczytana'));
        }
        $text = (string) ($product->raw['price_text'] ?? '');
        $cents = self::priceCents($text);
        if ($cents === null) {
            throw new RuntimeException('nie udało się odczytać ceny „'.$text.'” (oczekiwano np. „1 133,05 PLN”)');
        }
        if ($cents === 0) {
            return null;
        }

        return new B2bRemotePrice(net: $cents / 100, base: null, discountPercent: 0.0, currency: 'PLN');
    }

    /**
     * Opis ze strony produktu pierwszej pozycji karty (dosłownie, akapity i pozycje list jako linie) i jednostka
     * sprzedaży z listy. Strona z innym kodem niż lista = wyjątek (opis innego produktu nie może trafić na kartę).
     */
    public function description(B2bRemoteProduct $product): string
    {
        $url = (string) ($product->raw['detail_url'] ?? '');
        if (($product->raw['status'] ?? null) !== 'ok' || $url === '') {
            return '';
        }

        $xpath = JspB2bClient::dom($this->client->productPage($url));
        $shownCode = self::text($xpath->query('//td['.JspB2bClient::classPredicate('codeViewProductDane').']')->item(0));
        $code = (string) ($product->raw['code'] ?? '');
        if ($shownCode === '') {
            throw new RuntimeException('brak kodu na stronie produktu '.$code);
        }
        if ($shownCode !== $code) {
            throw new RuntimeException('kod na stronie produktu ('.$shownCode.') inny niż kod z listy ('.$code.')');
        }

        $sections = [];
        $node = $xpath->query('//*[@id="description"]')->item(0);
        $lines = $node !== null ? self::blockLines($node) : [];
        if ($lines !== []) {
            $sections[] = implode("\n", $lines);
        }
        $unit = (string) ($product->raw['unit'] ?? '');
        if ($unit !== '') {
            $sections[] = 'Jednostka: '.$unit;
        }

        return mb_substr(implode("\n\n", $sections), 0, 10000);
    }

    /**
     * Zdjęcie z listy. Adres z listy (…/public/get-preview/…) sklep zwraca jako 404 — działa ta sama ścieżka bez
     * „/public” (sprawdzone 15.09.2026, obrazy 500–1000 px). Zaślepka „blank.jpg” = brak zdjęcia.
     */
    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        $url = self::imageUrl((string) ($product->raw['image_url'] ?? ''));
        if ($url === null) {
            return null;
        }
        $file = $this->client->imageBytes($url);
        if ($file['bytes'] === '' || ! str_starts_with($file['mime'], 'image/')) {
            return null;
        }

        return new B2bRemoteImage(bytes: $file['bytes'], mime: $file['mime'], sourceUrl: $url);
    }

    public static function imageUrl(string $listUrl): ?string
    {
        if (! UvexB2bClient::isShopUrl($listUrl)) {
            return null;
        }
        $path = (string) parse_url($listUrl, PHP_URL_PATH);
        if (str_starts_with($path, '/public/get-preview/')) {
            $path = substr($path, strlen('/public'));
        }
        if (! str_starts_with($path, '/get-preview/')) {
            return null;
        }

        return UvexB2bClient::BASE.$path;
    }

    /** „1 133,05 PLN” → 113305; inny zapis (np. bez waluty, inna waluta) = null — nie zgadujemy. */
    public static function priceCents(string $text): ?int
    {
        if (preg_match('/^(\d{1,3}(?:[ \x{00A0}]\d{3})*),(\d{2})\s*PLN$/u', trim($text), $m) !== 1) {
            return null;
        }

        return (int) preg_replace('/\D/', '', $m[1]) * 100 + (int) $m[2];
    }

    /**
     * Cała lista; niespójna — jedno ponowne pobranie od początku.
     *
     * @return list<array<string, mixed>>
     */
    private function listRows(): array
    {
        try {
            return $this->scanList();
        } catch (RuntimeException $e) {
            if ($e->getCode() !== self::INCONSISTENT) {
                throw $e;
            }
            $this->progress('Lista zmieniła się w trakcie pobierania ('.$e->getMessage().') — pobieram od nowa');
        }

        try {
            return $this->scanList();
        } catch (RuntimeException $e) {
            if ($e->getCode() !== self::INCONSISTENT) {
                throw $e;
            }

            throw new RuntimeException('Lista produktów '.UvexB2bClient::HOST.' niespójna także po ponownym pobraniu: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scanList(): array
    {
        $started = microtime(true);
        $rows = [];
        $seen = [];
        $total = 0;
        $pageSize = 0;
        $pages = 1;

        for ($page = 1; $page <= $pages; $page++) {
            if (microtime(true) - $started > self::LIST_BUDGET_SECONDS) {
                throw new RuntimeException('Pobieranie listy '.UvexB2bClient::HOST.' trwa ponad '.(self::LIST_BUDGET_SECONDS / 60).' min — przerwane bez zapisu');
            }
            $xpath = JspB2bClient::dom($this->client->listPage($page));
            $info = self::paginationInfo($xpath);
            $pageRows = self::parseRows($xpath, $page);

            if ($info === null) {
                if ($page === 1) {
                    throw new RuntimeException('Lista produktów '.UvexB2bClient::HOST.' bez licznika rekordów — pusta lista konta albo zmiana strony sklepu');
                }

                throw new RuntimeException('strona '.$page.' bez licznika rekordów', self::INCONSISTENT);
            }
            [$from, $to, $pageTotal] = $info;
            if ($page === 1) {
                $total = $pageTotal;
                $pageSize = $to - $from + 1;
                if ($total <= 0 || $from !== 1 || $pageSize <= 0) {
                    throw new RuntimeException('Lista produktów '.UvexB2bClient::HOST.' ma nieoczekiwany licznik: od '.$from.' do '.$to.' z '.$total);
                }
                $pages = (int) ceil($total / $pageSize);
                $this->progress('Lista produktów UVEX: '.$total.' pozycji na '.$pages.' stronach');
            } elseif ($pageTotal !== $total) {
                throw new RuntimeException('liczba produktów zmieniła się z '.$total.' na '.$pageTotal.' (strona '.$page.')', self::INCONSISTENT);
            }

            $expectedFrom = ($page - 1) * $pageSize + 1;
            $expectedTo = min($page * $pageSize, $total);
            if ($from !== $expectedFrom || $to !== $expectedTo || count($pageRows) !== $to - $from + 1) {
                throw new RuntimeException(sprintf(
                    'strona %d: licznik od %d do %d, oczekiwano od %d do %d, wierszy %d',
                    $page, $from, $to, $expectedFrom, $expectedTo, count($pageRows),
                ), self::INCONSISTENT);
            }

            foreach ($pageRows as $row) {
                if ($row['price_text'] === '') {
                    throw new RuntimeException('Strona '.$page.' listy '.UvexB2bClient::HOST.': pozycja '.$row['code'].' bez ceny konta — pobieranie przerwane (utracony widok cen konta?)');
                }
                if (isset($seen[$row['id']])) {
                    throw new RuntimeException('produkt id '.$row['id'].' ('.$row['code'].') na dwóch stronach', self::INCONSISTENT);
                }
                $seen[$row['id']] = true;
                $rows[] = $row;
            }

            if ($page > 1 && ($page % self::PROGRESS_EVERY_PAGES === 0 || $page === $pages)) {
                $this->progress('Lista produktów UVEX: strona '.$page.'/'.$pages);
            }
        }

        if (count($rows) !== $total) {
            throw new RuntimeException('pobrano '.count($rows).' z '.$total.' pozycji', self::INCONSISTENT);
        }

        return $rows;
    }

    /**
     * Ten sam kod pod kilkoma pozycjami: ta sama cena — zostaje pierwsza; różne ceny — wszystkie pomijane z powodem
     * (nie wiadomo, która cena należy do kodu).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: list<array<string, mixed>>, 1: list<B2bRemoteProduct>}
     */
    private static function withoutConflictingCodes(array $rows): array
    {
        $byCode = [];
        foreach ($rows as $row) {
            $byCode[mb_strtolower($row['code'])][] = $row;
        }

        $kept = [];
        $skipped = [];
        foreach ($rows as $row) {
            $same = $byCode[mb_strtolower($row['code'])];
            if (count($same) === 1) {
                $kept[] = $row;

                continue;
            }
            if ($same[0]['id'] !== $row['id']) {
                continue;
            }
            $prices = array_unique(array_map(static fn (array $r): string => $r['price_text'], $same));
            if (count($prices) === 1) {
                $kept[] = $row;

                continue;
            }
            $skipped[] = new B2bRemoteProduct(
                remoteId: $row['code'],
                sku: $row['code'],
                name: $row['name'] !== '' ? $row['name'] : $row['code'],
                sourceUrl: $row['detail_url'] !== '' ? $row['detail_url'] : null,
                raw: [
                    'status' => 'skipped',
                    'code' => $row['code'],
                    'reason' => 'kod występuje na liście '.count($same).' razy z różnymi cenami ('.implode(', ', $prices).')',
                ],
            );
        }

        return [$kept, $skipped];
    }

    /**
     * @param  list<array{row: array<string, mixed>, size: array{size: string, expr: string|null, stem: string|null}|null}>  $group
     */
    private function productFor(array $group): B2bRemoteProduct
    {
        $first = $group[0]['row'];
        $members = array_map(static fn (array $m): array => [
            'remote_id' => (string) $m['row']['code'],
            'sku' => (string) $m['row']['code'],
            'name' => (string) $m['row']['name'],
        ], $group);
        $rows = array_map(static fn (array $m): array => [
            'id' => $m['row']['id'],
            'code' => $m['row']['code'],
            'name' => $m['row']['name'],
            'price_text' => $m['row']['price_text'],
            'availability' => $m['row']['availability'],
            'unit' => $m['row']['unit'],
            'size' => $m['size']['size'] ?? null,
        ], $group);

        $name = $first['name'];
        $availability = $first['availability'] !== '' ? $first['availability'] : null;
        $summary = '';
        if (count($group) > 1) {
            $name = $this->groups->groupName($first['name'], $first['code'], $group[0]['size']);
            $bySize = $group;
            usort($bySize, static fn (array $a, array $b): int => UvexSizeGroups::compareSizes($a['size']['size'], $b['size']['size']));
            $summary = 'Rozmiary: '.implode('; ', array_map(
                static fn (array $m): string => $m['size']['size'].' ('.$m['row']['code'].')',
                $bySize,
            ));
            $availability = self::groupAvailability($bySize);
        }

        return new B2bRemoteProduct(
            remoteId: $first['code'],
            sku: $first['code'],
            name: $name,
            sourceUrl: $first['detail_url'] !== '' ? $first['detail_url'] : null,
            raw: [
                'status' => 'ok',
                'code' => $first['code'],
                'price_text' => $first['price_text'],
                'unit' => $first['unit'],
                'detail_url' => $first['detail_url'],
                'image_url' => $first['image_url'],
                'rows' => $rows,
            ],
            availability: $availability,
            variantSummary: $summary,
            members: $members,
        );
    }

    /**
     * Jedna dostępność dla wszystkich rozmiarów — dosłownie; różne — „Dostępny: 39, 41; Na zamówienie: 40, 42”.
     *
     * @param  list<array{row: array<string, mixed>, size: array{size: string}}>  $bySize
     */
    private static function groupAvailability(array $bySize): ?string
    {
        $statuses = [];
        foreach ($bySize as $member) {
            $status = (string) $member['row']['availability'];
            $statuses[$status !== '' ? $status : 'brak informacji'][] = $member['size']['size'];
        }
        if (count($statuses) === 1) {
            $only = (string) array_key_first($statuses);

            return $only === 'brak informacji' ? null : $only;
        }
        $parts = [];
        foreach ($statuses as $status => $sizes) {
            $parts[] = $status.': '.implode(', ', $sizes);
        }

        return implode('; ', $parts);
    }

    /**
     * Wiersze listy: id pozycji z klasy odnośnika „urlViewProductCart_{id}”, każde pole z elementu z klasą albo id
     * z tym samym numerem. Nie przez <tr>: wiersz ma formularz koszyka w komórce tabeli, a parser HTML (libxml)
     * przestawia takie zagnieżdżenie — odnośniki pojawiają się podwójnie i poza swoim wierszem. Pozycja = unikalne id.
     *
     * @return list<array{id: string, code: string, name: string, price_text: string, price_cents: int|null, availability: string, unit: string, detail_url: string, image_url: string, page: int}>
     */
    private static function parseRows(DOMXPath $xpath, int $page): array
    {
        $rows = [];
        foreach ($xpath->query('//a[starts-with(@class, "urlViewProductCart_")]') ?: [] as $link) {
            if (! $link instanceof DOMElement || preg_match('/^urlViewProductCart_(\d+)$/', trim($link->getAttribute('class')), $m) !== 1) {
                continue;
            }
            $id = $m[1];
            if (isset($rows[$id])) {
                continue;
            }
            $byClass = static fn (string $class): string => '//*['.JspB2bClient::classPredicate($class.$id).']';
            $codeNode = $xpath->query($byClass('codeViewProductDane_'))->item(0);
            // nazwa: pierwszy <span> komórki z klasą urlViewProductCart_{id}, w której jest kod
            $nameNode = $codeNode !== null
                ? $xpath->query('ancestor::*['.JspB2bClient::classPredicate('urlViewProductCart_'.$id).'][1]/span[1]', $codeNode)->item(0)
                : null;
            $priceText = self::text($xpath->query($byClass('twojaCenaNetto_'))->item(0));
            $image = $xpath->query('//*[@id="B2B_fotorama-'.$id.'"]//a[@data-full]')->item(0);

            $rows[$id] = [
                'id' => $id,
                'code' => self::text($codeNode),
                'name' => self::text($nameNode),
                'price_text' => $priceText,
                'price_cents' => self::priceCents($priceText),
                'availability' => self::text($xpath->query($byClass('stanViewProductDane_'))->item(0)),
                'unit' => self::text($xpath->query($byClass('panelDodawaniaProduktuDoKoszyka_').'//*['.JspB2bClient::classPredicate('input-group-addon').']')->item(0)),
                'detail_url' => UvexB2bClient::isShopUrl($link->getAttribute('href')) ? $link->getAttribute('href') : '',
                'image_url' => $image instanceof DOMElement ? trim($image->getAttribute('data-full')) : '',
                'page' => $page,
            ];
        }

        return array_values($rows);
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function paginationInfo(DOMXPath $xpath): ?array
    {
        $text = self::text($xpath->query('//*['.JspB2bClient::classPredicate('pagination-info').']')->item(0));
        if (preg_match('/od\s+(\d+)\s+do\s+(\d+)\s+z\s+(\d+)/u', $text, $m) !== 1) {
            return null;
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }

    /**
     * Element → linie tekstu: nowa linia przy <br> i elementach blokowych, pozycja listy jako „- …”. Białe znaki
     * zwinięte, puste linie pominięte.
     *
     * @return list<string>
     */
    private static function blockLines(DOMNode $root): array
    {
        $lines = [];
        $buffer = '';
        $flush = static function () use (&$lines, &$buffer): void {
            $line = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $buffer));
            if ($line !== '' && $line !== '-') {
                $lines[] = $line;
            }
            $buffer = '';
        };
        $walk = static function (DOMNode $node) use (&$walk, &$buffer, $flush): void {
            foreach ($node->childNodes as $child) {
                if ($child->nodeType === XML_TEXT_NODE || $child->nodeType === XML_CDATA_SECTION_NODE) {
                    $buffer .= $child->nodeValue;

                    continue;
                }
                if (! $child instanceof DOMElement) {
                    continue;
                }
                $tag = strtolower($child->nodeName);
                if (in_array($tag, self::SKIPPED_TAGS, true)) {
                    continue;
                }
                if ($tag === 'br') {
                    $flush();
                } elseif ($tag === 'li') {
                    $flush();
                    $buffer = '- ';
                    $walk($child);
                    $flush();
                } elseif (in_array($tag, self::BLOCK_TAGS, true)) {
                    $flush();
                    $walk($child);
                    $flush();
                } else {
                    $walk($child);
                }
            }
        };
        $walk($root);
        $flush();

        return $lines;
    }

    private function progress(string $message): void
    {
        if ($this->listProgress !== null) {
            ($this->listProgress)($message);
        }
    }

    private static function text(?DOMNode $node): string
    {
        return $node === null ? '' : trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $node->textContent));
    }
}
