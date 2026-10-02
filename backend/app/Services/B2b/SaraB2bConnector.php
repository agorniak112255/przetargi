<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use RuntimeException;

/**
 * b2b.saraworkwear.com — platforma B2B producenta odzieży roboczej Sara Workwear (marka własna, pole producenta w API
 * puste). Sprawdzone 02.10.2026 na API sklepu (SaraB2bClient opisuje logowanie i pułapkę cen gościa): 25 kategorii
 * głównych, 8462 pozycje, 416 modeli, 869 kolorów, ceny w PLN.
 *
 * Pozycja sklepu („produkt” w API) to jeden rozmiar jednego koloru: właściwości „Skrót” (shortCode, model:
 * „1-64-620”), „Identyfikator” (ID, model i kolor: „1-64-620-22-70”), „Rozmiar”, „Kolor”, wariant z indeksem
 * magazynowym („1-64-620-22-70-S”), EAN-em i stanem. Lista idzie po kategoriach głównych (API nie ma listy „wszystkich”);
 * pozycja bywa w kilku kategoriach — liczy się raz.
 *
 * Karta = model ze wszystkimi kolorami i rozmiarami (decyzje użytkownika 28.09.2026), ale tylko pozycje o tej samej
 * nazwie: pod jednym Skrótem sklep trzyma czasem inny wyrób (1-07-790 „ALPHA WINTER” i „ALPHA WINTER HV” z normą
 * EN ISO 20471) albo kolor w nazwie („BREDA CELADON” / „BREDA ORANGE”) — takie zostają osobnymi kartami, a SKU karty
 * to wtedy Identyfikator jej pierwszego koloru (inaczej Skrót). remote_id pozycji = indeks magazynowy.
 *
 * Cena pozycji: sellPrice = cena konta, listPrice = cennikowa. Pozycje z flagą „Wyprzedaż” bez możliwości zamówienia
 * (stan 0) sklep już nie uzupełni — poza kartą; pozostałe pozycje bez stanu zostają („Brak na stanie”). Sprzedaż
 * w wielokrotnościach (rękawice po 6/12 par: individualUnitInterval) = warunek zamawiania. Opis — tekst opisu sklepu
 * (jednakowy dla pozycji modelu), tabelka — właściwości sklepu dosłownie, poza rozmiarowymi (wymiary rozmiaru
 * A–F, kolor, rozmiar); właściwość różna między kolorami — z kolorem przy wartości. Norma: wiersz „Norma” na każdą
 * wartość („EN ISO 20471:2013 Klasa 3”, „EN388 3121X”) — dosłownie, z poziomem, tak jak podaje sklep. Pliki: polskie
 * deklaracje, karty produktu i instrukcje (angielskie bliźniaki „Declaration…”, „Product data sheet…” pomijane);
 * zdjęcia z CDN sklepu (1600 px), po jednym na kolor.
 */
final class SaraB2bConnector implements B2bConnector, B2bDocumentSource, B2bGroupsSizes, B2bImageGallery, B2bListProgressAware, B2bManufacturerSite, B2bRunSummaryAware, B2bShopFieldNormSource, B2bShopFieldSource, B2bSizePriceSource
{
    public const BRAND = 'Sara Workwear';

    private const PAGE_SIZE = 100;

    private const LIST_BUDGET_SECONDS = 25 * 60;

    private const MAX_PAGES_PER_CATEGORY = 300;

    private const PROGRESS_EVERY = 50;

    private const INCONSISTENT = 7302;

    /**
     * Sortowania kolejnych przejść kategorii (sortOptions sklepu): domyślne, potem inne, aż zbiór pozycji będzie pełny.
     * Sortowanie „id” sklep pomija (wraca domyślna kolejność), więc unikalnego klucza kolejności nie ma.
     */
    private const SORTS = [null, '-created_at', 'created_at', 'name'];

    /** Tyle kart bez ceny konta, zanim pojawi się pierwsza cena = sesja bez cen (albo zmiana sklepu). */
    private const MAX_FIRST_WITHOUT_PRICE = 20;

    private const NORM_ROW = 'Norma';

    private const SECTION_MAIN = 'Informacje ze sklepu Sara Workwear';

    private const SALE_FLAG = 'Wyprzedaż';

    /** Zdjęcia jednego koloru (karta jednego koloru) — więcej sklep i tak nie pokazuje. */
    private const MAX_IMAGES_ONE_COLOUR = 6;

    /** Rozmiar zdjęcia z CDN sklepu (Thumbor: fit-in, białe tło); oryginał PNG ma do kilku MB. */
    private const IMAGE_PATH = '/picture/fit-in/1600x1600/smart/filters:fill(white)/';

    /**
     * Właściwości pozycji, nie modelu: rozmiar, kolor, kody i wymiary rozmiaru (Rozmiar A klatka … F długość wkładki) —
     * poza tabelką karty (rozmiar i kolor są w tabeli pozycji, kody w identyfikatorach).
     */
    private const ITEM_SYMBOLS = ['size', 'color', 'ID', 'shortCode', 'size-a', 'size-b', 'size-c', 'size-d', 'size-e', 'size-f'];

    /** Kolejność rozmiarów literowych (z dopiskami wzrostu „S”/„A”/„B” po nich: „XLS”, „XXLB”). */
    private const LETTER_SIZES = ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL', '4XL', '5XL'];

    /** Angielskie bliźniaki polskich plików (ten sam dokument po angielsku). */
    private const ENGLISH_FILE_PREFIXES = ['declaration', 'product data sheet'];

    private int $total = 0;

    private int $cards = 0;

    private int $withPrice = 0;

    private int $multiPrice = 0;

    /** @var array<string, array<string, mixed>> karty z listy (klucz: Skrót + nazwa) */
    private array $groups = [];

    /** @var array<string, int> Skrót → liczba kart z tym Skrótem */
    private array $shortCodeCards = [];

    /** @var list<string> */
    private array $summary = [];

    /** @var list<string> */
    private array $withoutPrice = [];

    /** @var list<string> */
    private array $withoutDescription = [];

    /** @var list<string> pozycje wyprzedaży bez stanu i pozycje bez ceny (poza kartą) */
    private array $unorderable = [];

    /** @var list<string> pozycje bez Skrótu, Identyfikatora, rozmiaru albo indeksu (poza listą) */
    private array $incomplete = [];

    /** @var list<string> */
    private array $saleItems = [];

    /** @var list<string> pozycje z tym samym kolorem i rozmiarem co inna pozycja karty */
    private array $duplicateSizes = [];

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    /**
     * @param  int  $pageSize  pozycji na stronę listy (testy stronicują drobniej)
     */
    public function __construct(
        private readonly SaraB2bClient $client,
        private readonly int $pageSize = self::PAGE_SIZE,
    ) {}

    public static function key(): string
    {
        return 'sara';
    }

    public static function label(): string
    {
        return self::BRAND;
    }

    public static function host(): string
    {
        return SaraB2bClient::HOST;
    }

    public static function ownBrand(): string
    {
        return self::BRAND;
    }

    public static function normShopFieldNames(): array
    {
        return [self::NORM_ROW];
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new SaraB2bClient((string) $account->username, (string) $account->password, $delayMs));
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
        $this->summary = [];
        $this->withoutPrice = [];
        $this->withoutDescription = [];
        $this->unorderable = [];
        $this->incomplete = [];
        $this->saleItems = [];
        $this->duplicateSizes = [];
        $this->cards = 0;
        $this->withPrice = 0;
        $this->multiPrice = 0;

        if (! $this->client->isLoggedIn()) {
            $this->client->login();
        }

        $items = $this->listGroups();
        ksort($this->groups, SORT_STRING);
        $this->shortCodeCards = [];
        foreach ($this->groups as $group) {
            $this->shortCodeCards[$group['short_code']] = ($this->shortCodeCards[$group['short_code']] ?? 0) + 1;
        }
        $this->total = count($this->groups);
        $colours = array_sum(array_map(static fn (array $g): int => count($g['colours']), $this->groups));
        $this->summary[] = 'Lista Sara Workwear: '.$items.' pozycji (rozmiarów), '.count($this->shortCodeCards).' modeli, '.$colours.' kolorów → '.$this->total.' kart';

        $done = 0;
        foreach (array_keys($this->groups) as $key) {
            $group = $this->groups[$key];
            unset($this->groups[$key]);
            $product = $this->productFor($group);
            if ($this->withPrice === 0 && count($this->withoutPrice) >= self::MAX_FIRST_WITHOUT_PRICE) {
                throw new B2bFatalException(count($this->withoutPrice).' pierwszych kart '.SaraB2bClient::HOST.' bez ceny konta — sesja konta nie pokazuje cen albo sklep zmienił dane wyrobu; przebieg przerwany');
            }
            $done++;
            if ($done % self::PROGRESS_EVERY === 0) {
                $this->progress('Karty Sara Workwear: '.$done.'/'.$this->total);
            }
            yield $product;
        }
    }

    public function totalProducts(): int
    {
        return $this->total;
    }

    public function runSummary(): array
    {
        $lines = $this->summary;
        $lines[] = sprintf(
            'Karty: %d (%d z rozmiarami albo kolorami w różnych cenach — cena karty = najniższa, ceny pozycji w tabeli karty)',
            $this->cards,
            $this->multiPrice,
        );
        if ($this->withoutPrice !== []) {
            $lines[] = 'Bez ceny konta albo bez pozycji do zamówienia (pominięte): '.self::listing($this->withoutPrice);
        }
        if ($this->unorderable !== []) {
            $lines[] = 'Pozycje poza kartą (wyprzedane do zera albo bez ceny w PLN): '.self::listing($this->unorderable);
        }
        if ($this->saleItems !== []) {
            $lines[] = 'Pozycje z flagą „Wyprzedaż” (na karcie, do wyczerpania stanu): '.self::listing($this->saleItems);
        }
        if ($this->incomplete !== []) {
            $lines[] = 'Pozycje bez Skrótu, Identyfikatora, rozmiaru albo indeksu (pominięte): '.self::listing($this->incomplete);
        }
        if ($this->duplicateSizes !== []) {
            $lines[] = 'Ten sam kolor i rozmiar w dwóch pozycjach (rozmiar z indeksem): '.self::listing($this->duplicateSizes);
        }
        if ($this->withoutDescription !== []) {
            $lines[] = 'Bez opisu w sklepie: '.self::listing($this->withoutDescription);
        }

        return $lines;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return self::BRAND;
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            throw new RuntimeException((string) ($product->raw['reason'] ?? 'wyrób nieodczytany'));
        }
        $price = $product->raw['price'] ?? null;
        if (! $price instanceof B2bRemotePrice) {
            throw new RuntimeException('wyrób bez ceny konta');
        }

        return $price;
    }

    public function description(B2bRemoteProduct $product): string
    {
        return (string) ($product->raw['description'] ?? '');
    }

    /**
     * @return list<B2bRemoteShopField>
     */
    public function shopFields(B2bRemoteProduct $product): array
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            return [];
        }

        return array_map(
            static fn (array $row): B2bRemoteShopField => new B2bRemoteShopField($row['section'], $row['name'], $row['value']),
            $product->raw['fields'] ?? [],
        );
    }

    /**
     * @return list<B2bRemoteDocument>
     */
    public function documents(B2bRemoteProduct $product): array
    {
        return array_map(
            static fn (array $file): B2bRemoteDocument => new B2bRemoteDocument($file['title'], $file['url'], $file['kind']),
            $product->raw['documents'] ?? [],
        );
    }

    public function documentBytes(B2bRemoteDocument $document): array
    {
        return $this->client->fileBytes($document->sourceUrl);
    }

    /**
     * Zdjęcia karty: po jednym na kolor (kolor wiodący pierwszy); karta jednego koloru — galeria pozycji wiodącej.
     *
     * @return list<string>
     */
    public function imageUrls(B2bRemoteProduct $product): array
    {
        return $product->raw['images'] ?? [];
    }

    public function imageAt(string $url): ?B2bRemoteImage
    {
        $file = $this->client->fileBytes($url);
        if ($file['bytes'] === '' || ! str_starts_with($file['mime'], 'image/')) {
            return null;
        }

        return new B2bRemoteImage(bytes: $file['bytes'], mime: $file['mime'], sourceUrl: $url);
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        $urls = $this->imageUrls($product);

        return $urls === [] ? null : $this->imageAt($urls[0]);
    }

    /**
     * Opis HTML sklepu jako tekst: akapity i podziały w osobnych liniach, encje rozwinięte, odstępy zwinięte.
     */
    public static function htmlText(string $html): string
    {
        $html = (string) preg_replace('#<\s*br\s*/?>|</\s*(?:p|div|li|h[1-6]|tr)\s*>#iu', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = self::clean($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Adres zdjęcia z CDN sklepu ze ścieżki z API („{imageSafeUri}/44e4….png”); null = inny zapis (nie zgadujemy).
     */
    public static function imageUrl(string $picture): ?string
    {
        if (preg_match('#^\{imageSafeUri\}/([A-Za-z0-9._-]+\.(?:png|jpe?g|webp|gif))$#i', trim($picture), $m) !== 1) {
            return null;
        }

        return SaraB2bClient::BASE.self::IMAGE_PATH.$m[1];
    }

    /**
     * Klucz kolejności rozmiaru: literowe (XS…5XL, z dopiskiem wzrostu po nich), potem liczbowe rosnąco, potem reszta
     * alfabetycznie.
     *
     * @return array{int, float, string}
     */
    public static function sizeOrder(string $size): array
    {
        $upper = strtoupper(trim($size));
        if (preg_match('/^(\d+(?:[.,]\d+)?)$/', $upper, $m) === 1) {
            return [1, (float) str_replace(',', '.', $m[1]), ''];
        }
        // najdłuższy pasujący rozmiar literowy na początku („XXLB” → XXL + B, nie XL)
        $best = null;
        foreach (self::LETTER_SIZES as $index => $letters) {
            if (str_starts_with($upper, $letters) && preg_match('/^[SAB]?$/', substr($upper, strlen($letters))) === 1
                && ($best === null || strlen($letters) > strlen(self::LETTER_SIZES[$best]))) {
                $best = $index;
            }
        }
        if ($best !== null) {
            return [0, (float) $best, substr($upper, strlen(self::LETTER_SIZES[$best]))];
        }

        return [2, 0.0, $upper];
    }

    /**
     * Cała lista pozycji pogrupowana w karty; niespójna (liczba pozycji kategorii zmieniła się w trakcie, pozycja dwa razy
     * w jednej kategorii) — jedno ponowne pobranie od początku. Zwraca liczbę pozycji.
     */
    private function listGroups(): int
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

            throw new RuntimeException('Lista wyrobów '.SaraB2bClient::HOST.' niespójna także po ponownym pobraniu: '.$e->getMessage(), 0, $e);
        }
    }

    private function scanList(): int
    {
        $this->groups = [];
        $this->incomplete = [];
        $started = microtime(true);
        $seen = [];
        $categories = $this->client->categories();
        $this->progress('Kategorie Sara Workwear: '.count($categories));
        foreach ($categories as $category) {
            // Kolejność listy nie jest stała między stronami (domyślne sortowanie ma remisy — 02.10.2026 pozycja
            // 16544 wróciła na dwóch stronach „Bluz męskich”), więc strony mogą powtórzyć jedne pozycje i pominąć
            // inne. Zbiór pozycji kategorii zbieramy do skutku: przejście domyślnym sortowaniem, a gdy zbiór jest
            // mniejszy niż licznik sklepu — kolejne przejścia innym sortowaniem; powtórki liczą się raz.
            $inCategory = [];
            $total = null;
            $passes = 0;
            foreach (self::SORTS as $sort) {
                $passes++;
                $total = $this->scanCategory($category, $sort, $total, $started, $inCategory, $seen);
                if (count($inCategory) >= $total) {
                    break;
                }
                $this->progress('Kategoria '.$category['name'].': po przejściu '.$passes.' jest '.count($inCategory).' z '.$total.' pozycji (kolejność sklepu zmienna) — kolejne przejście innym sortowaniem');
            }
            if (count($inCategory) < $total) {
                throw new RuntimeException('kategoria '.$category['name'].': po '.$passes.' przejściach '.count($inCategory).' z '.$total.' pozycji', self::INCONSISTENT);
            }
            $this->progress('Kategoria '.$category['name'].': '.$total.' pozycji (razem bez powtórzeń: '.count($seen).')');
        }
        if ($seen === []) {
            throw new RuntimeException('Lista wyrobów '.SaraB2bClient::HOST.' pusta — pusta oferta konta albo zmiana sklepu');
        }

        return count($seen);
    }

    /**
     * Jedno przejście wszystkich stron kategorii jednym sortowaniem; pozycje dopisane do zbioru kategorii ($inCategory)
     * i — nowe w całej liście ($seen) — do grup kart. Zwraca licznik pozycji kategorii; licznik inny niż w poprzednich
     * stronach albo przejściach = lista zmieniła się w trakcie (INCONSISTENT).
     *
     * @param  array{id: string, name: string}  $category
     * @param  array<string, true>  $inCategory
     * @param  array<string, true>  $seen
     */
    private function scanCategory(array $category, ?string $sort, ?int $total, float $started, array &$inCategory, array &$seen): int
    {
        $pages = 1;
        for ($page = 1; $page <= $pages; $page++) {
            if (microtime(true) - $started > self::LIST_BUDGET_SECONDS) {
                throw new RuntimeException('Pobieranie listy '.SaraB2bClient::HOST.' trwa ponad '.(self::LIST_BUDGET_SECONDS / 60).' min — przerwane bez zapisu');
            }
            $json = $this->client->categoryPage($category['id'], $page, $this->pageSize, $sort);
            $pageTotal = is_int($json['pagination']['itemsCount'] ?? null) ? $json['pagination']['itemsCount'] : -1;
            if ($pageTotal < 0) {
                throw new RuntimeException('Kategoria '.$category['name'].' ('.$category['id'].') bez licznika pozycji — zmiana sklepu?');
            }
            if ($total === null) {
                $total = $pageTotal;
            } elseif ($pageTotal !== $total) {
                throw new RuntimeException('liczba pozycji kategorii '.$category['name'].' zmieniła się z '.$total.' na '.$pageTotal.' (strona '.$page.')', self::INCONSISTENT);
            }
            if ($page === 1) {
                $pages = min(max(1, (int) ceil($total / $this->pageSize)), self::MAX_PAGES_PER_CATEGORY);
            }
            foreach ($json['items'] as $item) {
                $id = trim((string) ($item['id'] ?? ''));
                if ($id === '') {
                    throw new RuntimeException('Kategoria '.$category['name'].', strona '.$page.': pozycja bez id — zmiana sklepu?');
                }
                $inCategory[$id] = true;
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $this->addItem($item);
            }
        }

        return (int) $total;
    }

    /**
     * Pozycja listy do grupy karty — tylko pola, których łącznik używa (opis, kategoria i właściwości raz na kartę albo
     * kolor, nie na rozmiar: lista ma ok. 8,5 tys. pozycji, a przebieg na serwerze ma 128 MB).
     *
     * @param  array<string, mixed>  $item
     */
    private function addItem(array $item): void
    {
        $properties = self::properties($item);
        $shortCode = self::firstValue($properties, 'shortCode');
        $colourId = self::firstValue($properties, 'ID');
        $size = self::firstValue($properties, 'size');
        $variant = is_array($item['variants'][0] ?? null) ? $item['variants'][0] : [];
        $symbol = self::clean((string) ($variant['warehouseSymbol'] ?? ''));
        $name = self::clean((string) ($item['name'] ?? ''));
        if ($shortCode === '' || $colourId === '' || $size === '' || $symbol === '' || $name === '') {
            $this->incomplete[] = ($symbol !== '' ? $symbol : 'id '.$item['id']).($name !== '' ? ' ('.$name.')' : '');

            return;
        }

        $key = $shortCode."\n".$name;
        if (! isset($this->groups[$key])) {
            $this->groups[$key] = [
                'short_code' => $shortCode,
                'name' => $name,
                'description' => self::htmlText((string) ($item['description'] ?? '')),
                'paths' => [],
                'url' => self::clean((string) ($item['niceUrl'] ?? '')),
                'colours' => [],
                'documents' => [],
            ];
        }
        $group = &$this->groups[$key];

        $path = implode(' > ', array_filter(array_map(
            static fn (mixed $c): string => is_array($c) ? self::clean((string) ($c['name'] ?? '')) : '',
            is_array($item['categoryPath'] ?? null) ? $item['categoryPath'] : [],
        ), static fn (string $n): bool => $n !== ''));
        // „BRAK” = sklep nie przypisał kategorii
        if ($path !== '' && mb_strtoupper($path) !== 'BRAK') {
            $group['paths'][$path] = ($group['paths'][$path] ?? 0) + 1;
        }

        if (! isset($group['colours'][$colourId])) {
            $pictures = [];
            foreach (is_array($item['pictures'] ?? null) ? $item['pictures'] : [] as $picture) {
                $url = is_string($picture) ? self::imageUrl($picture) : null;
                if ($url !== null && ! in_array($url, $pictures, true)) {
                    $pictures[] = $url;
                }
            }
            $group['colours'][$colourId] = [
                'colour' => self::firstValue($properties, 'color'),
                'properties' => array_values(array_filter(
                    $properties,
                    static fn (array $p): bool => ! in_array($p['symbol'], self::ITEM_SYMBOLS, true),
                )),
                'pictures' => array_slice($pictures, 0, self::MAX_IMAGES_ONE_COLOUR),
                'items' => [],
            ];
        }

        foreach (is_array($item['attachments'] ?? null) ? $item['attachments'] : [] as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }
            $file = self::document((string) ($attachment['name'] ?? ''), (string) ($attachment['url'] ?? ''));
            if ($file !== null) {
                $group['documents'][$file['file_id']] ??= $file;
            }
        }

        $sellCurrency = strtoupper(trim((string) ($item['prices']['sellPrice']['currency'] ?? '')));
        $listCurrency = strtoupper(trim((string) ($item['prices']['listPrice']['currency'] ?? $sellCurrency)));
        $pln = $sellCurrency === 'PLN' && ($listCurrency === 'PLN' || $listCurrency === '');
        $stock = $variant['availability']['stock']['amount'] ?? null;
        $flags = array_map(static fn (mixed $f): string => is_array($f) ? self::clean((string) ($f['name'] ?? '')) : '', is_array($item['flags'] ?? null) ? $item['flags'] : []);
        $interval = $item['individualUnitInterval'] ?? ($item['unit']['interval'] ?? 1);

        $group['colours'][$colourId]['items'][] = [
            'id' => (string) $item['id'],
            'symbol' => $symbol,
            'ean' => self::clean((string) ($variant['ean'] ?? '')),
            'size' => $size,
            'net' => $pln ? self::amount($item['prices']['sellPrice']['nett'] ?? null) : null,
            'base' => $pln ? self::amount($item['prices']['listPrice']['nett'] ?? null) : null,
            'buyable' => ($variant['availability']['buyable'] ?? false) === true,
            'stock' => is_int($stock) || is_float($stock) ? (float) $stock : null,
            'unit' => self::clean((string) ($item['unit']['name'] ?? '')),
            'interval' => is_int($interval) || is_float($interval) ? max(1.0, (float) $interval) : 1.0,
            'sale' => in_array(self::SALE_FLAG, $flags, true),
            'url' => self::clean((string) ($item['niceUrl'] ?? '')),
        ];
        unset($group);
    }

    /**
     * Karta modelu ze wszystkimi kolorami i rozmiarami do zamówienia; bez ceny konta — pominięta z powodem.
     *
     * @param  array<string, mixed>  $group
     */
    private function productFor(array $group): B2bRemoteProduct
    {
        $name = $group['name'];
        $colourIds = array_keys($group['colours']);
        sort($colourIds, SORT_STRING);
        $sku = ($this->shortCodeCards[$group['short_code']] ?? 1) > 1 ? (string) $colourIds[0] : $group['short_code'];

        // pozycje do zamówienia każdego koloru; „kilka kolorów” liczy się z kolorów z pozycjami
        $orderable = [];
        foreach ($colourIds as $colourId) {
            $colour = $group['colours'][$colourId];
            $items = $colour['items'];
            usort($items, static fn (array $a, array $b): int => self::sizeOrder($a['size']) <=> self::sizeOrder($b['size']) ?: strcmp($a['symbol'], $b['symbol']));
            $positions = [];
            foreach ($items as $item) {
                if ($item['net'] === null) {
                    $this->unorderable[] = $item['symbol'].' (bez ceny w PLN)';
                } elseif ($item['sale'] && ! $item['buyable']) {
                    $this->unorderable[] = $item['symbol'].' (wyprzedaż, stan 0)';
                } else {
                    $positions[] = $item;
                }
            }
            if ($positions !== []) {
                $orderable[] = ['id' => (string) $colourId, 'colour' => $colour, 'positions' => $positions];
            }
        }

        if ($orderable === []) {
            $this->withoutPrice[] = $sku;

            return $this->skipped($group, $sku, 'żadna pozycja wyrobu nie ma ceny konta do zamówienia');
        }

        $manyColours = count($orderable) > 1;
        $members = [];
        $identifiers = [new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_SOURCE_CODE, value: $group['short_code'], field: 'Skrót')];
        $images = [];
        $colourLabels = [];
        $sizeNames = [];
        $intervals = [];
        $units = [];
        $statuses = [];
        $usedLabels = [];
        $cheapest = null;
        foreach ($orderable as ['id' => $colourId, 'colour' => $colour, 'positions' => $positions]) {
            $colourLabel = $colour['colour'] !== '' ? $colour['colour'] : $colourId;
            $colourLabels[] = $colourLabel;
            $firstOfColour = null;
            foreach ($positions as $item) {
                $size = $item['size'];
                $sizeLabel = $manyColours ? $colourLabel.' / '.$size : $size;
                if (isset($usedLabels[$sizeLabel])) {
                    // ten sam kolor i rozmiar w dwóch pozycjach (RSL007: rozmiar „8” przy indeksie …-9) — indeks rozstrzyga
                    $this->duplicateSizes[] = $item['symbol'];
                    $sizeLabel .= ' ('.$item['symbol'].')';
                }
                $usedLabels[$sizeLabel] = true;
                $price = new B2bRemotePrice(net: (float) $item['net'], base: $item['base'], currency: 'PLN');
                if ($item['buyable'] && $item['stock'] !== null && $item['stock'] > 0) {
                    $status = 'Na stanie';
                    $availability = $status.': '.B2bOrderQuantity::format($item['stock']).($item['unit'] !== '' ? ' '.$item['unit'] : '');
                } elseif ($item['buyable']) {
                    $status = 'Dostępny';
                    $availability = $status;
                } else {
                    $status = 'Brak na stanie';
                    $availability = $status;
                }
                if ($item['sale']) {
                    $this->saleItems[] = $item['symbol'];
                }
                $members[] = [
                    'remote_id' => $item['symbol'],
                    'sku' => $item['symbol'],
                    'name' => $name.', '.$colourLabel.' '.$size,
                    'availability' => $availability,
                    'size' => $sizeLabel,
                    'price' => $price,
                ];
                $statuses[$status][] = $sizeLabel;
                if ($item['ean'] !== '') {
                    $identifiers[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_EAN, value: $item['ean'], remoteId: $item['symbol'], label: $sizeLabel, field: 'ean');
                }
                $firstOfColour ??= $item['symbol'];
                $sizeNames[$size] = true;
                $intervals[(string) $item['interval']] = $item['interval'];
                if ($item['unit'] !== '') {
                    $units[$item['unit']] = true;
                }
                if ($cheapest === null || $price->net < $cheapest->net) {
                    $cheapest = $price;
                }
            }
            $identifiers[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_ALT_CODE, value: $colourId, remoteId: $firstOfColour, label: $colourLabel, field: 'Identyfikator');
            if ($manyColours) {
                if (($colour['pictures'][0] ?? null) !== null) {
                    $images[] = $colour['pictures'][0];
                }
            } elseif ($images === []) {
                $images = $colour['pictures'];
            }
        }

        $this->withPrice++;
        $this->cards++;
        $nets = array_map(static fn (array $m): float => $m['price']->net, $members);
        $this->multiPrice += count(array_unique(array_map('strval', $nets))) > 1 ? 1 : 0;

        $unit = count($units) === 1 ? (string) array_key_first($units) : null;
        $order = count($intervals) > 1
            ? new B2bOrderQuantity(min: null, step: null, unit: $unit, varies: true)
            : self::orderQuantity((float) array_values($intervals)[0], $unit);
        $description = $group['description'];
        if ($description === '') {
            $this->withoutDescription[] = $sku;
        }
        $leadUrl = $orderable[0]['positions'][0]['url'];

        return new B2bRemoteProduct(
            remoteId: $members[0]['remote_id'],
            sku: $sku,
            name: $name,
            category: self::category($group['paths']),
            sourceUrl: self::pageUrl($leadUrl !== '' ? $leadUrl : $group['url'], $orderable[0]['positions'][0]['id']),
            raw: [
                'status' => 'ok',
                // cena karty = najniższa cena pozycji (B2bCatalogSync liczy ją też z members[].price)
                'price' => new B2bRemotePrice(net: $cheapest->net, base: $cheapest->base, currency: 'PLN', order: $order),
                'description' => mb_substr($description, 0, 10000),
                'fields' => self::fields($group['short_code'], array_map(static fn (array $o): array => $o['colour'], $orderable)),
                'documents' => array_values(array_map(
                    static fn (array $f): array => ['title' => $f['title'], 'url' => $f['url'], 'kind' => $f['kind']],
                    $group['documents'],
                )),
                'images' => array_values(array_unique($images)),
            ],
            availability: self::groupAvailability($statuses),
            variantSummary: ($manyColours ? 'Kolory: '.implode(', ', $colourLabels) : 'Kolor: '.$colourLabels[0])
                .'; rozmiary: '.implode(', ', array_map('strval', array_keys($sizeNames))),
            members: $members,
            identifiers: $identifiers,
        );
    }

    /**
     * Wiersze tabelki: Skrót modelu, potem właściwości sklepu w kolejności ze sklepu (koloru wiodącego). Wartość
     * jednakowa we wszystkich kolorach — dosłownie; różna — „kolor: wartość” dla każdego koloru z wartością. Norma —
     * wiersz na każdą wartość (jak wiersze norm innych producentów, B2bShopFieldNormSource).
     *
     * @param  list<array<string, mixed>>  $colours
     * @return list<array{section: string, name: string, value: string}>
     */
    private static function fields(string $shortCode, array $colours): array
    {
        $fields = [['section' => self::SECTION_MAIN, 'name' => 'Skrót (model)', 'value' => $shortCode]];

        // nazwy właściwości w kolejności pierwszego wystąpienia; wartości każdego koloru
        $order = [];
        $values = [];
        foreach ($colours as $colour) {
            foreach ($colour['properties'] as $property) {
                $order[$property['name']] ??= $property['symbol'];
                $values[$property['name']][$colour['colour']] = $property['values'];
            }
        }

        foreach ($order as $name => $symbol) {
            $byColour = array_filter($values[$name], static fn (array $v): bool => $v !== []);
            if ($byColour === []) {
                continue;
            }
            // wspólna dla karty tylko wtedy, gdy mają ją wszystkie kolory (norma jednego koloru odblaskowego nie
            // należy do pozostałych)
            $distinct = array_unique(array_map(static fn (array $v): string => implode("\n", $v), $byColour));
            if (count($byColour) < count($colours)) {
                $distinct[] = '';
            }
            if ($symbol === 'standard' && count($distinct) === 1) {
                foreach (reset($byColour) as $norm) {
                    $fields[] = ['section' => self::SECTION_MAIN, 'name' => self::NORM_ROW, 'value' => $norm];
                }

                continue;
            }
            if (count($distinct) === 1) {
                $fields[] = ['section' => self::SECTION_MAIN, 'name' => $name, 'value' => implode('; ', reset($byColour))];

                continue;
            }
            $parts = [];
            foreach ($byColour as $colour => $list) {
                $parts[] = ($colour !== '' ? $colour.': ' : '').implode(', ', $list);
            }
            // norma różna w kolorach nie idzie do wierszy „Norma” — nie należy do całej karty
            $fields[] = ['section' => self::SECTION_MAIN, 'name' => $symbol === 'standard' ? 'Norma (zależnie od koloru)' : $name, 'value' => implode('; ', $parts)];
        }

        return $fields;
    }

    /**
     * Właściwości pozycji dosłownie: nazwa (bez spacji na końcu), symbol, lista wartości (puste pominięte). Właściwość
     * bez wartości („Materiał / powłoka”: null) — pominięta.
     *
     * @param  array<string, mixed>  $item
     * @return list<array{name: string, symbol: string, values: list<string>}>
     */
    private static function properties(array $item): array
    {
        $out = [];
        foreach (is_array($item['properties'] ?? null) ? $item['properties'] : [] as $property) {
            if (! is_array($property)) {
                continue;
            }
            $name = self::clean((string) ($property['name'] ?? ''));
            $value = is_array($property['value'] ?? null) ? $property['value'] : [];
            $raw = $value['other'] ?? $value['yes'] ?? null;
            $list = [];
            foreach (is_array($raw) ? $raw : (is_scalar($raw) ? [$raw] : []) as $entry) {
                $text = is_bool($entry) ? ($entry ? 'Tak' : 'Nie') : (is_scalar($entry) ? self::clean((string) $entry) : '');
                if ($text !== '' && ! in_array($text, $list, true)) {
                    $list[] = $text;
                }
            }
            if ($name === '' || $list === []) {
                continue;
            }
            $out[] = ['name' => $name, 'symbol' => self::clean((string) ($property['symbol'] ?? '')), 'values' => $list];
        }

        return $out;
    }

    /**
     * @param  list<array{name: string, symbol: string, values: list<string>}>  $properties
     */
    private static function firstValue(array $properties, string $symbol): string
    {
        foreach ($properties as $property) {
            if ($property['symbol'] === $symbol) {
                return $property['values'][0] ?? '';
            }
        }

        return '';
    }

    /**
     * Plik pozycji: polski (deklaracja, karta produktu, instrukcja) z adresu sklepu „/product/attachment/{pozycja}/{plik}_{wariant}”;
     * angielski bliźniak albo adres spoza sklepu — null. Ten sam plik przy każdej pozycji ma inny adres — kluczem jest
     * numer pliku.
     *
     * @return array{file_id: string, title: string, url: string, kind: string}|null
     */
    private static function document(string $name, string $url): ?array
    {
        $name = self::clean($name);
        $url = trim($url);
        if ($name === '' || ! SaraB2bClient::isShopUrl($url)
            || preg_match('#^/product/attachment/\d+/(\d+)_\d+$#', (string) parse_url($url, PHP_URL_PATH), $m) !== 1) {
            return null;
        }
        $lower = mb_strtolower($name);
        foreach (self::ENGLISH_FILE_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return null;
            }
        }
        $kind = match (true) {
            str_starts_with($lower, 'deklaracja') => ProductDocument::KIND_CERTIFICATE,
            str_starts_with($lower, 'karta') => ProductDocument::KIND_DATASHEET,
            str_starts_with($lower, 'instrukcja') => ProductDocument::KIND_MANUAL,
            default => ProductDocument::KIND_OTHER,
        };

        return ['file_id' => $m[1], 'title' => $name, 'url' => $url, 'kind' => $kind];
    }

    /**
     * Kategoria karty: najgłębsza ścieżka kategorii jej pozycji („Rękawice > Skórzane”, nie sama kolekcja „RSL007”,
     * którą sklep podaje przy części pozycji), przy remisie — najczęstsza, potem pierwsza z listy; null = brak.
     *
     * @param  array<string, int>  $paths  ścieżka → liczba pozycji
     */
    private static function category(array $paths): ?string
    {
        $best = null;
        foreach ($paths as $path => $count) {
            $rank = [substr_count((string) $path, ' > '), $count];
            if ($best === null || $rank > $best[0]) {
                $best = [$rank, (string) $path];
            }
        }

        return $best[1] ?? null;
    }

    /** Adres strony pozycji w sklepie („/{niceUrl}”, bez niego „/product/{id}”). */
    private static function pageUrl(string $niceUrl, string $id): string
    {
        $niceUrl = trim($niceUrl, '/ ');

        return SaraB2bClient::BASE.'/'.($niceUrl !== '' ? implode('/', array_map('rawurlencode', explode('/', $niceUrl))) : 'product/'.rawurlencode($id));
    }

    private static function amount(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && $value > 0 ? round((float) $value, 2) : null;
    }

    /** Sprzedaż w wielokrotnościach (rękawice po 6/12 par): min i krok; 1 = bez ograniczeń. */
    private static function orderQuantity(float $interval, ?string $unit): B2bOrderQuantity
    {
        return $interval > 1 ? new B2bOrderQuantity(min: $interval, step: $interval, unit: $unit) : new B2bOrderQuantity(min: 1.0, step: null, unit: $unit);
    }

    /**
     * Jeden stan dla wszystkich pozycji — dosłownie; różne — „Na stanie: S, M; Brak na stanie: XL”.
     *
     * @param  array<string, list<string>>  $statuses
     */
    private static function groupAvailability(array $statuses): ?string
    {
        if ($statuses === []) {
            return null;
        }
        if (count($statuses) === 1) {
            return (string) array_key_first($statuses);
        }
        $parts = [];
        foreach ($statuses as $status => $labels) {
            $parts[] = $status.': '.implode(', ', $labels);
        }

        return mb_substr(implode('; ', $parts), 0, 1000);
    }

    /**
     * Karta, której nie da się zapisać w tym przebiegu — pozycja bez ceny, widoczna jako pominięta.
     *
     * @param  array<string, mixed>  $group
     */
    private function skipped(array $group, string $sku, string $reason): B2bRemoteProduct
    {
        return new B2bRemoteProduct(
            remoteId: $sku,
            sku: $sku,
            name: $group['name'],
            sourceUrl: self::pageUrl($group['url'], $sku),
            raw: ['status' => 'skipped', 'reason' => $reason],
        );
    }

    private function progress(string $message): void
    {
        if ($this->listProgress !== null) {
            ($this->listProgress)($message);
        }
    }

    /**
     * @param  list<string>  $items
     */
    private static function listing(array $items): string
    {
        return count($items).', np. '.implode(', ', array_slice($items, 0, 10));
    }

    /** Tekst ze sklepu: bez znaków zerowej szerokości, odstępy (także twarde spacje) zwinięte. */
    private static function clean(string $text): string
    {
        $text = str_replace(["\u{200B}", "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{FEFF}"], '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));
    }
}
