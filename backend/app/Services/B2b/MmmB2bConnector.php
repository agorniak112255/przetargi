<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use Illuminate\Contracts\Encryption\DecryptException;
use RuntimeException;
use Throwable;

/**
 * Sklep 3M Polska order.3m.com — witryna producenta (B2bManufacturerSite, marka 3M). Sprawdzone na koncie 22.09.2026.
 *
 * Lista: aktywne wyroby kategorii „Środki ochrony indywidualnej” (GPH10008) z wyszukiwarki 3M, po 100 na stronę,
 * w grupach podkategorii (i marek) — duże wyniki wyszukiwarka stronicuje niedeterministycznie (listItems). Cała lista
 * idzie przed pierwszym produktem, deduplikowana po numerze magazynowym (mmm_id), i musi się zgadzać z licznikami.
 *
 * Karta = jeden numer magazynowy 3M (SKU = mmm_id). Ceny konta w paczkach po 100 za JEDNOSTKĘ BAZOWĄ wyrobu
 * (materialUnits = baseUomCode) — 3M sam przelicza cenę kartonu na sztukę/parę, my niczego nie dzielimy. Odpowiedź
 * mówi, za co jest cena („pricePer”: „1 szt”); inna jednostka niż bazowa = brak ceny z powodem w podsumowaniu.
 *
 * Opis, parametry, zdjęcia i dokumenty z karty wyrobu wyszukiwarki (pdp) — pobieranej raz na produkt. Opis dosłownie
 * (krótki opis, długi opis, zalety); parametry i numery handlowe do tabelki sklepu.
 *
 * Logowanie kodem z e-maila (B2bCodeLoginSite): przebieg korzysta z sesji zapisanej na koncie, a po udanym login()
 * i na końcu przebiegu zapisuje odnowione ciasteczka z powrotem na koncie.
 */
final class MmmB2bConnector implements B2bCodeLoginSite, B2bConnector, B2bDocumentSource, B2bImageGallery, B2bListProgressAware, B2bManufacturerSite, B2bRunSummaryAware, B2bShopFieldSource
{
    public const BRAND = '3M';

    private const PRICE_CHUNK = 100;

    /** Górna granica listy — więcej pozycji niż tyle to błąd licznika, nie oferta ŚOI. */
    private const MAX_TOTAL = 20_000;

    /** Komunikat postępu co tyle grup kategorii. */
    private const PROGRESS_EVERY_PAGES = 10;

    /** Przejścia jednej grupy listy, zanim uznamy ją za niespójną (małe grupy przechodzą w całości za pierwszym razem). */
    private const MAX_GROUP_PASSES = 4;

    private const DOCUMENTS_LIMIT = 8;

    private const IMAGES_LIMIT = 8;

    /** Rodzaje dokumentów pomijane — wielkie katalogi i przewodniki wielu wyrobów. */
    private const SKIPPED_DOCUMENT_TYPES = ['katalog', 'przewodnik'];

    private const DATASHEET_TYPE = 'arkusze danych';

    private const SHOP_SECTION_TRADE = 'Informacje handlowe';

    private const SHOP_SECTION_TECHNICAL = 'Dane techniczne';

    private const SHOP_SECTION_PACKAGING = 'Opakowanie';

    private int $total = 0;

    /** @var list<string> */
    private array $summary = [];

    /** @var array<string, list<string>> powód braku ceny → numery magazynowe */
    private array $withoutPrice = [];

    /** @var list<string> */
    private array $withoutBase = [];

    /** @var array<string, true> */
    private array $withoutDescription = [];

    private ?string $pdpId = null;

    /** @var array<string, mixed> */
    private array $pdp = [];

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    public function __construct(
        private readonly MmmB2bClient $client,
        private readonly ?B2bAccount $account = null,
    ) {}

    public static function key(): string
    {
        return '3m';
    }

    public static function label(): string
    {
        return self::BRAND;
    }

    public static function host(): string
    {
        return MmmB2bClient::HOST;
    }

    public static function ownBrand(): string
    {
        return self::BRAND;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        try {
            $session = $account->connector_session;
        } catch (DecryptException) {
            // sesja zaszyfrowana innym kluczem — jak brak sesji (przebieg poprosi o logowanie kodem)
            $session = null;
        }

        return new self(new MmmB2bClient(
            (string) $account->username,
            (string) $account->password,
            is_array($session) ? $session : [],
            $delayMs,
        ), $account);
    }

    public function onListProgress(callable $callback): void
    {
        $this->listProgress = $callback;
    }

    public function login(): void
    {
        $this->client->login();
        $error = $this->saveSession();
        if ($error !== null) {
            $this->summary[] = $error;
        }
    }

    public function startCodeLogin(): array
    {
        return $this->client->startCodeLogin();
    }

    public function finishCodeLogin(array $state, string $code): array
    {
        return $this->client->finishCodeLogin($state, $code);
    }

    public function products(): iterable
    {
        $this->summary = array_values(array_filter($this->summary, static fn (string $line): bool => str_starts_with($line, 'Sesja 3M')));
        $this->withoutPrice = [];
        $this->withoutBase = [];
        $this->withoutDescription = [];
        $this->pdpId = null;
        $this->pdp = [];

        $items = $this->listItems();
        $this->total = count($items);

        foreach (array_chunk($items, self::PRICE_CHUNK) as $chunk) {
            [$prices, $error] = $this->chunkPrices($chunk);
            foreach ($chunk as $item) {
                yield $this->productFor($item, $prices, $error);
            }
        }
    }

    public function totalProducts(): int
    {
        return $this->total;
    }

    public function runSummary(): array
    {
        $lines = $this->summary;
        foreach ($this->withoutPrice as $reason => $ids) {
            $lines[] = 'Bez ceny konta — '.$reason.': '.self::listing($ids).' (pominięte)';
        }
        if ($this->withoutBase !== []) {
            $lines[] = 'Bez ceny katalogowej (cena katalogowa = cena konta): '.self::listing($this->withoutBase);
        }
        if ($this->withoutDescription !== []) {
            $lines[] = 'Karta 3M bez opisu (brak opisu, długiego opisu i zalet): '.self::listing(array_keys($this->withoutDescription));
        }
        if ($this->account !== null && $this->client->isLoggedIn()) {
            $lines[] = $this->saveSession() ?? 'Sesja 3M zapisana na koncie';
        }

        return $lines;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return self::BRAND;
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        $price = $product->raw['price'] ?? null;
        if (! is_array($price)) {
            return null;
        }
        if (($price['status'] ?? null) === 'error') {
            throw new RuntimeException((string) $price['reason']);
        }
        if (($price['status'] ?? null) !== 'ok') {
            return null;
        }
        $net = (float) $price['net'];
        $base = is_float($price['base'] ?? null) ? $price['base'] : null;

        return new B2bRemotePrice(
            net: round($net, 2),
            base: $base !== null ? round($base, 2) : null,
            discountPercent: $base !== null && $base > 0 ? round((1 - $net / $base) * 100, 2) : 0.0,
        );
    }

    /**
     * Dosłownie z karty 3M: krótki opis, długi opis (akapity rozdzielone pustą linią), zalety jako „- …”.
     * Pusty, gdy karta nie ma żadnego z nich (częste przy częściach zamiennych).
     */
    public function description(B2bRemoteProduct $product): string
    {
        $pdp = $this->pdpOf($product);
        $parts = [];

        $short = implode("\n", self::lines($pdp['description'] ?? null));
        if ($short !== '') {
            $parts[] = $short;
        }
        $long = implode("\n\n", self::lines($pdp['long_description'] ?? null));
        if ($long !== '' && $long !== $short) {
            $parts[] = $long;
        }
        $benefits = [];
        foreach (self::listOf($pdp['benefits'] ?? null) as $benefit) {
            $text = implode(' ', self::lines($benefit));
            if ($text !== '') {
                $benefits[] = '- '.$text;
            }
        }
        if ($benefits !== []) {
            $parts[] = implode("\n", $benefits);
        }

        $description = mb_substr(implode("\n\n", $parts), 0, 20000);
        if ($description === '') {
            $this->withoutDescription[$product->remoteId] = true;
        }

        return $description;
    }

    /**
     * Numery handlowe, marka linii, jednostki i minimalne zamówienie; parametry (classified) i dane opakowania
     * dosłownie z karty 3M.
     *
     * @return list<B2bRemoteShopField>
     */
    public function shopFields(B2bRemoteProduct $product): array
    {
        $item = $product->raw['item'] ?? [];
        $price = is_array($product->raw['price'] ?? null) ? $product->raw['price'] : [];
        $pdp = $this->pdpOf($product);

        $fields = [];
        $add = static function (string $section, string $name, string $value) use (&$fields): void {
            $name = self::clean($name);
            $value = self::clean($value);
            if ($name !== '' && $value !== '') {
                $fields[] = new B2bRemoteShopField($section, $name, $value);
            }
        };

        foreach (self::listOf($pdp['common'] ?? null) as $row) {
            if (is_array($row) && mb_strtolower(self::value($row['label'] ?? null)) === 'marka') {
                $add(self::SHOP_SECTION_TRADE, 'Marka', self::value($row['value'] ?? null));
            }
        }
        $add(self::SHOP_SECTION_TRADE, 'Numer katalogowy 3M', (string) ($item['catalog'] ?? ''));
        $add(self::SHOP_SECTION_TRADE, 'Numer magazynowy 3M', (string) ($item['id'] ?? ''));
        $add(self::SHOP_SECTION_TRADE, 'EAN', (string) ($item['gtin'] ?? ''));
        $add(self::SHOP_SECTION_TRADE, 'Poprzedni numer 3M', (string) ($item['legacy'] ?? ''));
        $add(self::SHOP_SECTION_TRADE, 'Cena za', (string) ($price['price_per'] ?? ''));
        $add(self::SHOP_SECTION_TRADE, 'Minimalne zamówienie', (string) ($price['min_order'] ?? ''));
        $add(self::SHOP_SECTION_TRADE, 'Jednostka sprzedaży', self::salesUnitText($item));

        foreach (self::listOf($pdp['classified'] ?? null) as $row) {
            if (is_array($row)) {
                $add(self::SHOP_SECTION_TECHNICAL, self::value($row['label'] ?? null), self::classifiedValue($row['value'] ?? null));
            }
        }
        foreach (self::listOf($pdp['packagingIdentificationDetails'] ?? null) as $row) {
            if (is_array($row)) {
                $add(self::SHOP_SECTION_PACKAGING, self::value($row['label'] ?? null), self::value($row['value'] ?? null));
            }
        }

        return $fields;
    }

    /**
     * PDF-y z karty 3M (adres zdjęcia podglądu „…{dmr}J/nazwa.jpg” → plik „…{dmr}O/nazwa.pdf”); bez katalogów
     * i przewodników wielu wyrobów.
     *
     * @return list<B2bRemoteDocument>
     */
    public function documents(B2bRemoteProduct $product): array
    {
        $documents = [];
        foreach (self::listOf($this->pdpOf($product)['media_links_documents'] ?? null) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $type = mb_strtolower(self::value($row['content_type'] ?? null));
            $mime = mb_strtolower(self::value($row['mime_type'] ?? null));
            if ($mime !== 'application/pdf' || self::startsWithAny($type, self::SKIPPED_DOCUMENT_TYPES)) {
                continue;
            }
            $url = self::pdfUrl(self::value($row['url'] ?? null));
            if ($url === null || isset($documents[$url])) {
                continue;
            }
            $title = self::value($row['title'] ?? null);
            $documents[$url] = new B2bRemoteDocument(
                mb_substr($title !== '' ? $title : rawurldecode(basename((string) parse_url($url, PHP_URL_PATH))), 0, 255),
                $url,
                $type === self::DATASHEET_TYPE ? ProductDocument::KIND_DATASHEET : ProductDocument::KIND_OTHER,
            );
            if (count($documents) >= self::DOCUMENTS_LIMIT) {
                break;
            }
        }

        return array_values($documents);
    }

    /**
     * @return array{bytes: string, mime: string}
     */
    public function documentBytes(B2bRemoteDocument $document): array
    {
        $file = $this->client->fileBytes($document->sourceUrl);
        if (! str_starts_with($file['bytes'], '%PDF-')) {
            throw new RuntimeException('plik 3M nie jest PDF-em ('.$file['mime'].'): '.$document->sourceUrl);
        }
        $file['mime'] = 'application/pdf';

        return $file;
    }

    /**
     * Zdjęcia z karty 3M w dużym wariancie (Z z url_pattern), główne pierwsze; bez karty — zdjęcie z listy.
     *
     * @return list<string>
     */
    public function imageUrls(B2bRemoteProduct $product): array
    {
        $rows = array_values(array_filter(self::listOf($this->pdpOf($product)['media_links_images'] ?? null), 'is_array'));
        usort($rows, static fn (array $a, array $b): int => (int) self::isTrue($b['is_main_image'] ?? null) <=> (int) self::isTrue($a['is_main_image'] ?? null));

        $urls = [];
        foreach ($rows as $row) {
            $pattern = html_entity_decode(self::value($row['url_pattern'] ?? null), ENT_QUOTES | ENT_HTML5);
            $url = str_contains($pattern, '<R>') ? str_replace('<R>', 'Z', $pattern) : self::value($row['url'] ?? null);
            if ($url !== '' && MmmB2bClient::isFileUrl($url)) {
                $urls[$url] = true;
            }
            if (count($urls) >= self::IMAGES_LIMIT) {
                break;
            }
        }
        if ($urls === []) {
            $listed = (string) ($product->raw['item']['image'] ?? '');
            if ($listed !== '' && MmmB2bClient::isFileUrl($listed)) {
                $urls[$listed] = true;
            }
        }

        return array_keys($urls);
    }

    public function imageAt(string $url): ?B2bRemoteImage
    {
        $file = $this->client->fileBytes($url);
        if ($file['bytes'] === '') {
            return null;
        }
        $mime = $file['mime'];
        if (! str_starts_with($mime, 'image/')) {
            $info = @getimagesizefromstring($file['bytes']);
            $mime = is_array($info) ? (string) $info['mime'] : '';
        }

        return str_starts_with($mime, 'image/') ? new B2bRemoteImage(bytes: $file['bytes'], mime: $mime, sourceUrl: $url) : null;
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        $urls = $this->imageUrls($product);

        return $urls === [] ? null : $this->imageAt($urls[0]);
    }

    /**
     * Kwota w zapisie sklepu 3M: „3 141,12 PLN / szt”, „0,4196PLN” (spacja tysięcy zwykła albo niełamliwa).
     * Inny zapis (bez PLN, kropka dziesiętna) = null — nie zgadujemy.
     *
     * @return array{amount: float, unit: string|null}|null
     */
    public static function parsePrice(string $text): ?array
    {
        $pattern = '/^(\d{1,3}(?:[ \x{00A0}\x{202F}]\d{3})+|\d+)(?:,(\d+))?[\s\x{00A0}\x{202F}]*PLN[\s\x{00A0}\x{202F}]*(?:\/[\s\x{00A0}\x{202F}]*(.+))?$/u';
        if (preg_match($pattern, self::clean($text), $m) !== 1) {
            return null;
        }
        $amount = (float) (preg_replace('/\D/u', '', $m[1]).'.'.(($m[2] ?? '') !== '' ? $m[2] : '0'));
        $unit = isset($m[3]) ? self::clean($m[3]) : '';

        return ['amount' => $amount, 'unit' => $unit !== '' ? $unit : null];
    }

    /**
     * Cała lista ŚOI złożona z grup po najwyżej kilkaset pozycji. Wyszukiwarka 3M stronicuje duże wyniki
     * niedeterministycznie (sprawdzone na żywo 22.09.2026: ta sama strona 2955 pozycji zapytana dwa razy potrafiła
     * mieć 0 wspólnych pozycji, a pełne przejście dawało ~2300 różnych z 2955; parametry sortowania i queryId nic
     * nie zmieniają), a małe wyniki (331 pozycji PELTOR) przechodzi w całości. Dlatego: drzewo kategorii do liści,
     * liść ponad 100 pozycji dzielony po marce; każda grupa musi dać tyle różnych pozycji, ile mówi jej licznik
     * (inaczej kolejne przejście, suma przejść), a suma grup — licznik całej kategorii.
     *
     * @return list<array<string, mixed>>
     */
    private function listItems(): array
    {
        $root = $this->searchPage(null, null, 0, 1);
        $total = $root['total'];
        if ($total <= 0 || $total > self::MAX_TOTAL) {
            throw new RuntimeException('Lista wyrobów '.MmmB2bClient::HOST.' ma nieoczekiwany licznik: '.$total);
        }
        $groups = $this->listGroups([MmmB2bClient::CATEGORY], $root);
        $grouped = array_sum(array_column($groups, 'total'));
        if ($grouped !== $total) {
            throw new RuntimeException('Lista wyrobów '.MmmB2bClient::HOST.': grupy kategorii dają '.$grouped.' pozycji przy liczniku '.$total);
        }
        $this->progress('Lista wyrobów 3M: '.$total.' pozycji w '.count($groups).' grupach kategorii');

        $items = [];
        $withoutId = 0;
        $repeated = 0;
        $done = 0;
        foreach ($groups as $i => $group) {
            $scan = $this->scanGroup($group);
            $withoutId += $scan['without_id'];
            $repeated += $scan['passes'] > 1 ? 1 : 0;
            $items += $scan['items'];
            $done += $group['total'];
            if (($i + 1) % self::PROGRESS_EVERY_PAGES === 0) {
                $this->progress('Lista wyrobów 3M: '.$done.'/'.$total);
            }
        }
        if (count($items) + $withoutId !== $total) {
            throw new RuntimeException('Lista wyrobów '.MmmB2bClient::HOST.' niespójna: zebrano '.count($items).' różnych pozycji'
                .($withoutId > 0 ? ' i '.$withoutId.' bez numeru' : '').' przy liczniku '.$total.' (pozycje w kilku grupach naraz?)');
        }

        $line = 'Lista 3M (ŚOI, aktywne): '.count($items).' wyrobów z '.count($groups).' grup kategorii';
        if ($repeated > 0) {
            $line .= ', grup pobranych więcej niż raz (zmienna kolejność stron): '.$repeated;
        }
        if ($withoutId > 0) {
            $line .= ', bez numeru magazynowego (pominięte): '.$withoutId;
        }
        $this->summary[] = $line;

        return array_values($items);
    }

    /**
     * Grupy do pobrania: gałąź do 100 pozycji albo bez podkategorii to jedna grupa; większa gałąź — jej podkategorie
     * (suma ich liczników musi dać licznik gałęzi); większy liść — marki, gdy ich suma daje licznik, inaczej cały liść.
     *
     * @param  list<string>  $path
     * @param  array{total: int, categories: array<string, int>, brands: array<string, int>}  $node
     * @return list<array{path: list<string>, brand: ?string, total: int}>
     */
    private function listGroups(array $path, array $node): array
    {
        if ($node['total'] === 0) {
            return [];
        }
        $children = array_diff_key($node['categories'], array_flip($path));
        if ($node['total'] <= MmmB2bClient::LIST_PAGE_SIZE || ($children === [] && $node['brands'] === [])) {
            return [['path' => $path, 'brand' => null, 'total' => $node['total']]];
        }
        if ($children !== []) {
            if (array_sum($children) !== $node['total']) {
                throw new RuntimeException('Lista wyrobów '.MmmB2bClient::HOST.': podkategorie '.implode('/', $path)
                    .' dają '.array_sum($children).' pozycji przy liczniku '.$node['total']);
            }
            $groups = [];
            foreach (array_keys($children) as $child) {
                $childPath = [...$path, (string) $child];
                $groups = [...$groups, ...$this->listGroups($childPath, $this->searchPage($childPath, null, 0, 1))];
            }

            return $groups;
        }
        if (array_sum($node['brands']) !== $node['total']) {
            return [['path' => $path, 'brand' => null, 'total' => $node['total']]];
        }
        $groups = [];
        foreach ($node['brands'] as $brand => $count) {
            $groups[] = ['path' => $path, 'brand' => (string) $brand, 'total' => $count];
        }

        return $groups;
    }

    /**
     * Wszystkie pozycje grupy: przejście stron aż do licznika; brakujące pozycje = kolejne przejście, a pozycje
     * z kolejnych przejść się sumują (wyszukiwarka zmienia kolejność). Po MAX_GROUP_PASSES przejściach bez kompletu
     * — błąd zamiast cennika bez części wyrobów.
     *
     * @param  array{path: list<string>, brand: ?string, total: int}  $group
     * @return array{items: array<string, array<string, mixed>>, without_id: int, passes: int}
     */
    private function scanGroup(array $group): array
    {
        $items = [];
        $withoutId = 0;
        for ($pass = 1; $pass <= self::MAX_GROUP_PASSES; $pass++) {
            $passWithoutId = 0;
            for ($start = 0; $start < $group['total']; $start += MmmB2bClient::LIST_PAGE_SIZE) {
                $page = $this->searchPage($group['path'], $group['brand'], $start, MmmB2bClient::LIST_PAGE_SIZE);
                if ($page['total'] !== $group['total']) {
                    throw new RuntimeException('Lista wyrobów '.MmmB2bClient::HOST.': licznik grupy '.self::groupName($group)
                        .' zmienił się w trakcie pobierania ('.$group['total'].' → '.$page['total'].')');
                }
                foreach ($page['rows'] as $row) {
                    $item = is_array($row) ? self::listItem($row) : null;
                    if ($item === null) {
                        $passWithoutId++;

                        continue;
                    }
                    $items[$item['id']] ??= $item;
                }
                if ($page['rows'] === []) {
                    break;
                }
            }
            // pozycji bez numeru nie da się odróżnić między przejściami — liczy się największa liczba z jednego
            $withoutId = max($withoutId, $passWithoutId);
            if (count($items) + $withoutId >= $group['total']) {
                return ['items' => $items, 'without_id' => $withoutId, 'passes' => $pass];
            }
        }

        throw new RuntimeException('Lista wyrobów '.MmmB2bClient::HOST.' niespójna: grupa '.self::groupName($group).' po '
            .self::MAX_GROUP_PASSES.' przejściach ma '.count($items).' różnych pozycji przy liczniku '.$group['total']);
    }

    /**
     * Strona wyszukiwarki z odczytanym licznikiem, pozycjami i podziałem na podkategorie i marki.
     *
     * @param  list<string>|null  $path
     * @return array{total: int, rows: list<mixed>, categories: array<string, int>, brands: array<string, int>}
     */
    private function searchPage(?array $path, ?string $brand, int $start, int $size): array
    {
        $json = $this->client->search($start, $size, $path, $brand);
        $rows = $json['items'] ?? null;
        $total = $json['total'] ?? null;
        if (! is_array($rows) || ! is_numeric($total)) {
            throw new RuntimeException('Lista wyrobów '.MmmB2bClient::HOST.': nieczytelna odpowiedź wyszukiwarki ('
                .implode('/', $path ?? [MmmB2bClient::CATEGORY]).($brand !== null ? ', '.$brand : '').', od '.$start.')');
        }
        $sticky = is_array($json['aggregations']['sticky'] ?? null) ? $json['aggregations']['sticky'] : [];
        $facets = static function (mixed $list, string $key): array {
            $out = [];
            foreach (is_array($list) ? $list : [] as $facet) {
                if (is_array($facet) && is_scalar($facet[$key] ?? null) && is_numeric($facet['count'] ?? null) && (string) $facet[$key] !== '') {
                    $out[(string) $facet[$key]] = (int) $facet['count'];
                }
            }

            return $out;
        };

        return [
            'total' => (int) $total,
            'rows' => array_values($rows),
            'categories' => $facets($sticky['categories']['facets'] ?? null, 'id'),
            'brands' => $facets($sticky['brand']['facets'] ?? null, 'value'),
        ];
    }

    /**
     * @param  array{path: list<string>, brand: ?string, total: int}  $group
     */
    private static function groupName(array $group): string
    {
        return implode('/', $group['path']).($group['brand'] !== null ? ' ('.$group['brand'].')' : '');
    }

    /**
     * Pozycja listy wyszukiwarki → pola używane przez łącznik; null = bez numeru magazynowego.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private static function listItem(array $row): ?array
    {
        $id = self::value($row['mmm_id'] ?? null);
        if ($id === '') {
            return null;
        }
        $units = [];
        foreach (self::listOf($row['orderUnits'] ?? null) as $unit) {
            if (! is_array($unit)) {
                continue;
            }
            $units[] = [
                'code' => self::value($unit['orderUnitCode'] ?? null),
                'name' => self::value($unit['orderUnitName'] ?? null),
                'default' => self::isTrue($unit['defaultOrderUnit'] ?? null),
                'conversion' => self::value(is_array($unit['uomData'] ?? null) ? ($unit['uomData']['conversion'] ?? null) : null),
            ];
        }
        $image = $row['main_image'] ?? null;

        return [
            'id' => $id,
            'catalog' => self::value($row['mmm_catalog_number'] ?? null),
            'name' => self::value($row['fml_mkpl_name'] ?? null),
            'gtin' => self::value($row['gtin_display'] ?? null),
            'legacy' => self::value($row['legacy_mmm_id'] ?? null),
            'image' => is_array($image) ? self::value($image['url'] ?? null) : '',
            'base_unit' => self::value($row['baseUomCode'] ?? null),
            'sales_unit' => self::value($row['salesUnit'] ?? null),
            'order_units' => $units,
        ];
    }

    /**
     * Ceny jednej paczki (za jednostkę bazową). Błąd pobrania (poza krytycznym) = wszystkie pozycje paczki z powodem.
     *
     * @param  list<array<string, mixed>>  $chunk
     * @return array{0: array<string, array<string, mixed>>, 1: string|null}
     */
    private function chunkPrices(array $chunk): array
    {
        $ids = [];
        $units = [];
        foreach ($chunk as $item) {
            if ($item['base_unit'] !== '') {
                $ids[] = (string) $item['id'];
                $units[] = (string) $item['base_unit'];
            }
        }
        try {
            return [$this->client->prices($ids, $units), null];
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return [[], 'ceny 3M nie zostały pobrane ('.$e->getMessage().')'];
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, array<string, mixed>>  $prices
     */
    private function productFor(array $item, array $prices, ?string $error): B2bRemoteProduct
    {
        $id = (string) $item['id'];
        $name = (string) $item['name'];

        return new B2bRemoteProduct(
            remoteId: $id,
            sku: $id,
            name: $name !== '' ? $name : ($item['catalog'] !== '' ? '3M '.$item['catalog'] : '3M '.$id),
            category: null,
            sourceUrl: MmmB2bClient::productUrl($id),
            raw: [
                'item' => $item,
                'price' => $error !== null ? ['status' => 'error', 'reason' => $error] : $this->priceOf($item, $prices[$id] ?? null),
            ],
        );
    }

    /**
     * Cena konta za jednostkę bazową: „value” (netto konta) i „listPrice” (katalogowa). Jednostka ceny („pricePer”)
     * musi być „1 {nazwa jednostki bazowej}”; inaczej brak ceny z powodem — nie zgadujemy przelicznika.
     *
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>|null  $response
     * @return array<string, mixed>
     */
    private function priceOf(array $item, ?array $response): array
    {
        $id = (string) $item['id'];
        $baseName = self::baseUnitName($item);
        if ($item['base_unit'] === '' || $baseName === null) {
            return $this->noPrice($id, 'brak jednostki bazowej na liście');
        }
        $price = is_array($response['price'] ?? null) ? $response['price'] : [];
        $valueText = self::value($price['value'] ?? null);
        if ($valueText === '') {
            return $this->noPrice($id, 'sklep nie podaje ceny');
        }
        $net = self::parsePrice($valueText);
        if ($net === null || $net['amount'] < 0.005) {
            return $this->noPrice($id, 'nieczytelna cena');
        }
        $pricePer = self::value($price['pricePer'] ?? null);
        if (! self::sameUnit($pricePer, '1 '.$baseName) || ($net['unit'] !== null && ! self::sameUnit($net['unit'], $baseName))) {
            return $this->noPrice($id, 'cena za inną jednostkę niż bazowa ('.$baseName.')', $id.' („'.$pricePer.'”)');
        }

        $base = null;
        $list = self::parsePrice(self::value($price['listPrice'] ?? null));
        if ($list !== null && ($list['unit'] === null || self::sameUnit($list['unit'], $baseName)) && $list['amount'] >= $net['amount']) {
            $base = $list['amount'];
        } else {
            $this->withoutBase[] = $id;
        }

        $min = is_array($response['minOrderQuantity'] ?? null) ? $response['minOrderQuantity'] : [];

        return [
            'status' => 'ok',
            'net' => $net['amount'],
            'base' => $base,
            'price_per' => $pricePer,
            'min_order' => self::clean(self::value($min['value'] ?? null).' '.self::value($min['unit'] ?? null)),
        ];
    }

    /**
     * @return array{status: string, reason: string}
     */
    private function noPrice(string $id, string $reason, ?string $entry = null): array
    {
        $this->withoutPrice[$reason][] = $entry ?? $id;

        return ['status' => 'none', 'reason' => $reason];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function baseUnitName(array $item): ?string
    {
        foreach ($item['order_units'] as $unit) {
            if ($unit['code'] === $item['base_unit'] && $unit['name'] !== '') {
                return $unit['name'];
            }
        }

        return null;
    }

    /**
     * Domyślna jednostka zamówienia z przelicznikiem na jednostkę bazową: „karton = 64 szt”; sama bazowa: „szt”.
     *
     * @param  array<string, mixed>  $item
     */
    private static function salesUnitText(array $item): string
    {
        if (! is_array($item['order_units'] ?? null)) {
            return '';
        }
        $baseName = self::baseUnitName($item);
        foreach ($item['order_units'] as $unit) {
            if (! $unit['default'] || $unit['name'] === '') {
                continue;
            }
            if ($unit['code'] === $item['base_unit'] || $baseName === null || $unit['conversion'] === '') {
                return $unit['name'];
            }

            return $unit['name'].' = '.$unit['conversion'].' '.$baseName;
        }

        return '';
    }

    /**
     * Karta wyrobu z wyszukiwarki — pobierana raz na produkt (pamięć ostatniej).
     *
     * @return array<string, mixed>
     */
    private function pdpOf(B2bRemoteProduct $product): array
    {
        if ($this->pdpId === $product->remoteId) {
            return $this->pdp;
        }
        $json = $this->client->pdp($product->remoteId);
        $record = $json;
        foreach (['item', 'product', 'data'] as $key) {
            if (! isset($record['name']) && is_array($json[$key] ?? null)) {
                $record = $json[$key];
            }
        }
        if (! isset($record['name']) && is_array($json['items'][0] ?? null)) {
            $record = $json['items'][0];
        }
        // nieznany kształt odpowiedzi to błąd, nie „karta bez opisu” — pusty opis producenta kasuje opis karty
        // (B2bCatalogSync::ownDescriptionIsGone), więc nie wolno go udawać
        if (array_intersect(['mmm_id', 'name', 'description', 'long_description', 'classified'], array_keys($record)) === []) {
            throw new RuntimeException('nieczytelna karta wyrobu 3M '.$product->remoteId);
        }
        $id = self::value($record['mmm_id'] ?? null);
        if ($id !== '' && $id !== $product->remoteId) {
            throw new RuntimeException('karta 3M innego wyrobu ('.$id.' zamiast '.$product->remoteId.')');
        }
        $this->pdpId = $product->remoteId;
        $this->pdp = $record;

        return $record;
    }

    /** Zapis sesji na koncie (tylko dwie kolumny); null = zapisano albo nie ma konta, inaczej komunikat. */
    private function saveSession(): ?string
    {
        if ($this->account === null || ! $this->account->exists) {
            return null;
        }
        try {
            $this->account->forceFill([
                'connector_session' => $this->client->session(),
                'connector_session_saved_at' => now(),
            ])->save();
        } catch (Throwable $e) {
            return 'Sesja 3M nie została zapisana na koncie ('.$e->getMessage().')';
        }

        return null;
    }

    /** Adres pliku PDF z adresu podglądu dokumentu („…/2064569J/x.jpg” → „…/2064569O/x.pdf”); null = inny adres. */
    private static function pdfUrl(string $url): ?string
    {
        if (! MmmB2bClient::isFileUrl($url)) {
            return null;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (str_ends_with(mb_strtolower($path), '.pdf')) {
            return $url;
        }
        $pdf = preg_replace('#/(\d+)J/([^/]+)\.jpe?g$#i', '/$1O/$2.pdf', $path, 1, $count);
        if ($count !== 1 || ! is_string($pdf)) {
            return null;
        }

        return 'https://'.MmmB2bClient::FILE_HOST.$pdf;
    }

    /**
     * Wartość parametru: enum [{value}] łączone „, ”, numeric [{uom_id, value}] z jednostką, tekst dosłownie.
     */
    private static function classifiedValue(mixed $value): string
    {
        $pieces = [];
        foreach (is_array($value) && array_is_list($value) ? $value : [$value] as $entry) {
            if (is_array($entry) && array_key_exists('value', $entry)) {
                $unit = self::value($entry['uom_id'] ?? null);
                $piece = trim(self::value($entry['value']).($unit !== '' ? ' '.$unit : ''));
            } else {
                $piece = self::value($entry);
            }
            if ($piece !== '') {
                $pieces[] = $piece;
            }
        }

        return implode(', ', $pieces);
    }

    /**
     * Tekst ze źródła (zwykły albo z prostym HTML) → linie bez pustych; tablica → linie każdego elementu.
     *
     * @return list<string>
     */
    private static function lines(mixed $value): array
    {
        if (is_array($value) && array_is_list($value)) {
            $lines = [];
            foreach ($value as $entry) {
                $text = implode(' ', self::lines($entry));
                if ($text !== '') {
                    $lines[] = $text;
                }
            }

            return $lines;
        }
        $text = self::value($value);
        if ($text === '') {
            return [];
        }
        if (preg_match('/<[a-z\/!]/i', $text) === 1) {
            $text = (string) preg_replace('#<\s*br\s*/?\s*>#i', "\n", $text);
            $text = (string) preg_replace('#<\s*li\b[^>]*>#i', "\n- ", $text);
            $text = (string) preg_replace('#</\s*(p|div|li|ul|ol|h[1-6]|tr)\s*>#i', "\n", $text);
            $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        }
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = self::clean($line);
            if ($line !== '' && $line !== '-') {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Wartość pola wyszukiwarki: tekst, liczba albo obiekt {value: …}; lista → wartości łączone „, ”.
     */
    private static function value(mixed $value): string
    {
        if (is_string($value)) {
            return trim($value);
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            if (array_key_exists('value', $value)) {
                return self::value($value['value']);
            }
            if (array_is_list($value)) {
                return implode(', ', array_values(array_filter(array_map(self::value(...), $value), static fn (string $v): bool => $v !== '')));
            }
        }

        return '';
    }

    /**
     * @return list<mixed>
     */
    private static function listOf(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? $value : [];
    }

    private static function isTrue(mixed $value): bool
    {
        return $value === true || (is_string($value) && strtolower(trim($value)) === 'true');
    }

    private static function sameUnit(string $a, string $b): bool
    {
        return mb_strtolower(self::clean($a)) === mb_strtolower(self::clean($b));
    }

    /**
     * @param  list<string>  $prefixes
     */
    private static function startsWithAny(string $text, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($text, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $items
     */
    private static function listing(array $items): string
    {
        return count($items).', np. '.implode(', ', array_slice($items, 0, 10));
    }

    private function progress(string $message): void
    {
        if ($this->listProgress !== null) {
            ($this->listProgress)($message);
        }
    }

    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $value));
    }
}
