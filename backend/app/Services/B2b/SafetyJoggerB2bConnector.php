<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * order.safetyjogger.com — sklep B2B producenta Safety Jogger (marki Safety Jogger, Oxypas, Safety Workwear, Tiger Grip,
 * Maxguard). Sprawdzone na zalogowanym koncie 01.10.2026 (SafetyJoggerB2bClient opisuje logowanie i nagłówki): 528
 * wyrobów, konto polskie, ceny w PLN.
 *
 * Lista (/action/product, strony po PAGE_SIZE) podaje wyrób (model) ze wszystkimi atrybutami katalogu — normy,
 * kategorię ochrony, certyfikowane funkcje, materiały, wyniki badań, opisy — oraz kolory. Atrybuty są kluczami
 * tłumaczeń; teksty po polsku bierzemy z tłumaczeń sklepu (/i18n/SAFETY/pl.json), a brak polskiego — z angielskiego,
 * jak robi to sam sklep (domyślny język „en”). Opis karty to wyłącznie polskie teksty sklepu (tytuł w nazwie, krótki
 * i długi opis); angielskie teksty atrybutów opisowych na kartę nie trafiają (brak = pusty opis, w podsumowaniu).
 *
 * Karta = model ze wszystkimi kolorami i rozmiarami (decyzje użytkownika 28.09.2026: rozmiary i kolory jednego wyrobu
 * to jedna karta). Pozycja = rozmiar koloru z /action/shoppingcart/itemsForProductColor/{kolor} (jedno zapytanie na
 * kolor): EAN, rozmiar (assortment.sizeRange), cena konta (customerPrice), cena cennikowa (defaultPrice) i stan
 * magazynu regionu konta. Kolory wyprzedaży (stockType „L”) sklep pokazuje tylko w „Outlet”, gdy wyrób ma inne kolory
 * — tak samo tutaj: na kartę idą tylko wtedy, gdy wyrób nie ma innych. Pozycje z ograniczonym stanem („L”, „E”) bez
 * ilości do sprzedaży (stockToSell 0) nie dają się zamówić — poza kartą. Sprzedaż w wielokrotnościach (buyPer, pole
 * ilości koszyka z walidatorem „multiple”) = warunek zamawiania (min i krok).
 *
 * remote_id pozycji = EAN (bez EAN-u albo przy powtórzonym — „sku-{id}”), SKU karty = kod modelu z listy („FYTS1PSL”),
 * producent = marka katalogu (simplified-brand: „Safety Jogger”), marka linii (Oxypas, Safety Workwear…) w nazwie
 * i tabelce. Normy: wiersz „Norma” na każdą normę (B2bShopFieldNormSource), z poziomem z PERFORMANCELEVEL_{norma}
 * („EN 388:2016 4X42F”) — poziom należy do normy wprost w danych sklepu. Pliki: polska karta produktu PDF (bez niej —
 * angielska), polska deklaracja zgodności, certyfikaty europejskie (EN/CE); zdjęcie katalogowe na kolor.
 */
final class SafetyJoggerB2bConnector implements B2bConnector, B2bDocumentSource, B2bGroupsSizes, B2bImageGallery, B2bListProgressAware, B2bManufacturerSite, B2bRunSummaryAware, B2bShopFieldNormSource, B2bShopFieldSource, B2bSizePriceSource
{
    public const BRAND = 'Safety Jogger';

    private const PAGE_SIZE = 100;

    private const LIST_BUDGET_SECONDS = 20 * 60;

    private const MAX_LIST_PAGES = 100;

    private const PROGRESS_EVERY = 50;

    private const INCONSISTENT = 7301;

    /** Tyle wyrobów bez ceny konta, zanim pojawi się pierwsza cena = sesja bez cen (albo zmiana sklepu). */
    private const MAX_FIRST_WITHOUT_PRICE = 20;

    /** Wiersz normy w tabelce (normShopFieldNames). */
    private const NORM_ROW = 'Norma';

    private const SECTION_MAIN = 'Informacje ze sklepu Safety Jogger';

    private const SECTION_NORMS = 'Normy';

    private const SECTION_MATERIALS = 'Materiały';

    private const SECTION_FEATURES = 'Specyfikacja produktu';

    private const SECTION_TESTS = 'Wyniki testu';

    /** Kolory i pozycje z ograniczonym stanem — sklep sprzedaje je tylko do wyczerpania zapasu (isNOS w skrypcie sklepu). */
    private const LIMITED_STOCK_TYPES = ['L', 'E'];

    /**
     * Wiersze tabelki: atrybut → klucz tłumaczenia etykiety (jak w eksporcie danych sklepu, catalogattribute/
     * exportableColumns z 01.10.2026), w kolejności sekcji. Wartości atrybutów z członami — tłumaczenia członów; atrybuty
     * tekstowe (zakres rozmiarów, waga, wyniki badań) — tekst; „N/A” w wynikach badań = nie dotyczy, bez wiersza.
     */
    private const MAIN_ROWS = [
        'articletype' => 'ca-articletype',
        'shoes_product_category' => 'ca-shoes_product_category',
        'shoes_product_subcategory' => 'ca-shoes_product_subcategory',
        'workwear_product_category' => 'ca-workwear_product_category',
        'workwear_product_subcategory' => 'ca-workwear_product_subcategory',
        'headprotection_product_category' => 'ca-headprotection_product_category',
        'headprotection_product_subcategory' => 'ca-headprotection_product_subcategory',
        'fallprotection_product_category' => 'ca-fallprotection_product_category',
        'product_family' => 'ca-product_family',
        'gender' => 'ca-gender',
        'model' => 'ca-model',
        'available-size-range_eu' => 'ca-available-size-range_eu',
        'sample-weight' => 'ca-sample-weight',
    ];

    private const NORM_ROWS = [
        'standards' => 'ca-standards',
        'features' => 'ca-features',
    ];

    private const MATERIAL_ROWS = [
        'upper' => 'ca-upper',
        'lining' => 'ca-lining',
        'sock' => 'ca-footbed',
        'midsole' => 'ca-midsole',
        'outsole' => 'ca-sole',
        'sole' => 'ca-sole',
        'toecap' => 'ca-toecap',
        'closure' => 'ca-closure',
        'liner' => 'ca-liner',
        'coating' => 'ca-coating',
        'thickness' => 'ca-thickness',
        'fabric' => 'ca-fabrics',
        'outside_layer' => 'ca-outside_layer',
        'mid_layer' => 'ca-mid_layer',
        'inner_layer' => 'ca-inner_layer',
        'nose_strip' => 'ca-nose_strip',
        'shell' => 'ca-shell',
        'harness' => 'ca-harness',
        'headband' => 'ca-headband',
        'headstrap' => 'ca-headstrap',
        'ratchet' => 'ca-ratchet',
        'sweat_absorber' => 'ca-sweat_absorber',
        'cushions' => 'ca-cushions',
        'foam' => 'ca-foam',
        'body' => 'ca-body',
        'lenses' => 'ca-lenses',
        'frame' => 'ca-frame',
        'temples' => 'ca-temples',
        'valve' => 'ca-valve',
        'sealing' => 'ca-sealing',
        'lock' => 'ca-lock',
        'materials' => 'ca-materials',
    ];

    private const FEATURE_ROWS = [
        'benefits' => 'ca-benefits',
        'key_features' => 'ca-key_features',
        'gloves_key_features' => 'ca-gloves_key_features',
        'workwear_key_features' => 'ca-workwear_key_features',
        'headprotection-helmets_key_features' => 'ca-headprotection-helmets_key_features',
        'headprotection-eyeface_key_features' => 'ca-headprotection-eyeface_key_features',
        'headprotection-hearing_key_features' => 'ca-headprotection-hearing_key_features',
        'headprotection-respiratory_key_features' => 'ca-headprotection-respiratory_key_features',
        'workwear_product_details' => 'ca-workwear_product_details',
        'productspecs' => 'ca-productspecs',
        'protection_gloves' => 'ca-protection_gloves',
        'environments' => 'ca-environments',
    ];

    private const TEST_ROWS = [
        'upper-wvp' => 'ca-upper-wvp',
        'upper-wvc' => 'ca-upper-wvc',
        'lining_wvp' => 'ca-lining_wvp',
        'lining_wvc' => 'ca-lining_wvc',
        'insole_abrasion_resistance' => 'ca-insole_abrasion_resistance-',
        'outsole_abrasion_resistance' => 'ca-outsole_abrasion_resistance',
        'outsole_SRA_heel' => 'ca-outsole_sra',
        'outsole_SRA_flat' => 'ca-outsole_sra_flat',
        'outsole_SRB_heel' => 'ca-outsole_srb',
        'outsole_SRB_flat' => 'ca-outsole_srb_flat',
        'outsole-antistatic' => 'ca-outsole-antistatic',
        'outsole_ESD' => 'ca-outsole_esd',
        'outsole_energy_absorption' => 'ca-outsole_energy_absorption',
        'toecap_impact_resistance_100J' => 'ca-toecap_impact_resistance_100j',
        'toecap_compression_resistance_10kN' => 'ca-toecap_compression_resistance_10kn',
        'toecap_impact_resistance_200J' => 'ca-toecap_impact_resistance',
        'toecap_compression_resistance_15kN' => 'ca-toecap_compression_resistance_15kn',
    ];

    /** Atrybuty z polskim tekstem opisu (tłumaczenie klucza „cav_…” członu). */
    private const TITLE_ATTRIBUTE = 'product-title';

    private const DESCRIPTION_ATTRIBUTES = ['short-description', 'product-long-description', 'product_details'];

    /** Kategorie katalogu od najwęższej — pierwsza obecna daje rodzaj wyrobu w nazwie karty (cardName). */
    private const NAME_CATEGORIES = [
        'shoes_product_subcategory',
        'workwear_product_subcategory',
        'headprotection_product_subcategory',
        'fallprotection_product_category',
        'headprotection_product_category',
        'workwear_product_category',
        'shoes_product_category',
    ];

    private const PERFORMANCE_LEVEL_PREFIX = 'PERFORMANCELEVEL_';

    private int $total = 0;

    private int $cards = 0;

    private int $withPrice = 0;

    private int $multiPrice = 0;

    /** @var array<string, string> tłumaczenia pl / en (klucze ca-, cag-, cav_) */
    private array $polish = [];

    /** @var array<string, string> */
    private array $english = [];

    /** @var list<string> */
    private array $summary = [];

    /** @var list<string> */
    private array $withoutPrice = [];

    /** @var list<string> */
    private array $withoutDescription = [];

    /** @var list<string> kolory wyprzedaży pominięte („FYTS1PSL-419”) */
    private array $outletColours = [];

    /** @var list<string> pozycje bez ceny albo nie do zamówienia („FYTS1PSL-BLK 47”) */
    private array $unorderable = [];

    /** @var list<string> */
    private array $promotions = [];

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    /**
     * @param  int  $pageSize  wyrobów na stronę listy (testy stronicują drobniej)
     */
    public function __construct(
        private readonly SafetyJoggerB2bClient $client,
        private readonly int $pageSize = self::PAGE_SIZE,
    ) {}

    public static function key(): string
    {
        return 'safetyjogger';
    }

    public static function label(): string
    {
        return self::BRAND;
    }

    public static function host(): string
    {
        return SafetyJoggerB2bClient::HOST;
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
        return new self(new SafetyJoggerB2bClient((string) $account->username, (string) $account->password, $delayMs));
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
        $this->outletColours = [];
        $this->unorderable = [];
        $this->promotions = [];
        $this->cards = 0;
        $this->withPrice = 0;
        $this->multiPrice = 0;

        if (! $this->client->isLoggedIn()) {
            $this->client->login();
        }
        $currency = $this->client->currency();
        if ($currency !== 'PLN') {
            throw new RuntimeException('Konto '.SafetyJoggerB2bClient::HOST.' ma ceny w walucie „'.$currency.'”, a katalog przyjmuje ceny w PLN — przebieg przerwany bez zapisu');
        }
        if (! $this->client->seesPrices()) {
            throw new RuntimeException('Konto '.SafetyJoggerB2bClient::HOST.' nie ma uprawnienia do cen (SEE_PRICES) — przebieg przerwany bez zapisu');
        }

        $this->polish = self::usefulTranslations($this->client->translations('pl'));
        $this->english = self::usefulTranslations($this->client->translations('en'));

        $rows = $this->listRows();
        $this->total = count($rows);
        $this->summary[] = 'Lista Safety Jogger: '.count($rows).' wyrobów';

        $done = 0;
        foreach ($rows as $row) {
            $product = $this->productFor($row);
            if ($this->withPrice === 0 && count($this->withoutPrice) >= self::MAX_FIRST_WITHOUT_PRICE) {
                throw new B2bFatalException(count($this->withoutPrice).' pierwszych wyrobów '.SafetyJoggerB2bClient::HOST.' bez ceny konta — sesja konta nie pokazuje cen albo sklep zmienił dane wyrobu; przebieg przerwany');
            }
            $done++;
            if ($done % self::PROGRESS_EVERY === 0) {
                $this->progress('Wyroby Safety Jogger: '.$done.'/'.count($rows));
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
        if ($this->outletColours !== []) {
            $lines[] = 'Kolory wyprzedaży (Outlet) poza kartą, bo wyrób ma inne kolory: '.self::listing($this->outletColours);
        }
        if ($this->unorderable !== []) {
            $lines[] = 'Pozycje bez ceny konta albo wyprzedane do zera (poza kartą): '.self::listing($this->unorderable);
        }
        if ($this->promotions !== []) {
            $lines[] = 'Pozycje z ceną promocyjną (na karcie cena konta bez promocji): '.self::listing($this->promotions);
        }
        if ($this->withoutDescription !== []) {
            $lines[] = 'Bez polskiego opisu w sklepie: '.self::listing($this->withoutDescription);
        }

        return $lines;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        $brand = trim((string) ($product->raw['manufacturer'] ?? ''));

        return $brand !== '' ? $brand : self::BRAND;
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
     * Zdjęcie katalogowe każdego koloru karty (kolor wiodący pierwszy).
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
     * Tekst HTML sklepu („<p>…<strong>…</strong></p>”) jako tekst: akapity i podziały w osobnych liniach, encje
     * rozwinięte, odstępy zwinięte.
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
     * Oznaczenie normy z tłumaczenia sklepu: bez dopisku regionu („EN ISO 20347:2022(Europe)” → „EN ISO 20347:2022”)
     * i bez tytułu normy po „ - ” („EN 360: 2023 - Personal fall protection…” → „EN 360: 2023”, „Rain Protection -
     * EN343:2019” → „EN343:2019”). Zapis bez oznaczenia EN po żadnej stronie zostaje dosłownie.
     */
    public static function normDesignation(string $text): string
    {
        $text = self::clean($text);
        $text = self::clean((string) preg_replace('/\s*\((?:Europe|US|USA)\)\s*$/iu', '', $text));
        $parts = preg_split('/\s+-\s+/u', $text, 2) ?: [$text];
        if (count($parts) === 2) {
            $designation = '/^(?:PN[\s\-]+)?EN(?:\s?ISO)?\s?\d/iu';
            if (preg_match($designation, $parts[0]) === 1) {
                return self::clean($parts[0]);
            }
            if (preg_match($designation, $parts[1]) === 1) {
                return self::clean($parts[1]);
            }
        }

        return $text;
    }

    /**
     * Poziom normy ze sklepu: kod ze znaków rozdzielonych spacjami („4 X 4 2 F”) — zwarty („4X42F”), jak w nazwach
     * poziomów w tłumaczeniach sklepu; inny zapis („A5 4 4”) dosłownie.
     */
    public static function performanceLevel(string $text): string
    {
        $text = self::clean($text);

        return preg_match('/^[0-9A-FXP](?:\s+[0-9A-FXP])+$/iu', $text) === 1 ? (string) preg_replace('/\s+/u', '', $text) : $text;
    }

    /**
     * Data dostawy ze sklepu (milisekundy od 1970) po polsku: „15.11.2026”.
     */
    public static function arrivalDate(int|float $millis): string
    {
        return (new DateTimeImmutable('@'.intdiv((int) $millis, 1000)))->setTimezone(new DateTimeZone('Europe/Warsaw'))->format('d.m.Y');
    }

    /**
     * Cała lista wyrobów; niespójna (liczba wyrobów zmieniła się w trakcie, wyrób na dwóch stronach) — jedno ponowne
     * pobranie od początku.
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

            throw new RuntimeException('Lista wyrobów '.SafetyJoggerB2bClient::HOST.' niespójna także po ponownym pobraniu: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scanList(): array
    {
        $started = microtime(true);
        $rows = [];
        $total = 0;
        $pages = 1;
        for ($page = 1; $page <= $pages; $page++) {
            if (microtime(true) - $started > self::LIST_BUDGET_SECONDS) {
                throw new RuntimeException('Pobieranie listy '.SafetyJoggerB2bClient::HOST.' trwa ponad '.(self::LIST_BUDGET_SECONDS / 60).' min — przerwane bez zapisu');
            }
            $json = $this->client->listPage($page, $this->pageSize);
            $pageTotal = is_int($json['numberOfItems'] ?? null) ? $json['numberOfItems'] : -1;
            $products = is_array($json['products'] ?? null) ? $json['products'] : null;
            if ($products === null) {
                throw new RuntimeException('Strona '.$page.' listy '.SafetyJoggerB2bClient::HOST.' bez wyrobów — zmiana sklepu?');
            }
            if ($page === 1) {
                $total = $pageTotal;
                if ($total <= 0) {
                    throw new RuntimeException('Lista wyrobów '.SafetyJoggerB2bClient::HOST.' pusta albo bez licznika (numberOfItems '.$pageTotal.') — pusta lista konta albo zmiana sklepu');
                }
                $pages = min((int) ceil($total / $this->pageSize), self::MAX_LIST_PAGES);
                $this->progress('Lista wyrobów Safety Jogger: '.$total.' na '.$pages.' stronach');
            } elseif ($pageTotal !== $total) {
                throw new RuntimeException('liczba wyrobów zmieniła się z '.$total.' na '.$pageTotal.' (strona '.$page.')', self::INCONSISTENT);
            }
            foreach ($products as $item) {
                $row = is_array($item) ? self::compactRow($item) : null;
                if ($row === null) {
                    throw new RuntimeException('Strona '.$page.' listy '.SafetyJoggerB2bClient::HOST.': wyrób bez id albo kodu — zmiana sklepu?');
                }
                if (isset($rows[$row['id']])) {
                    throw new RuntimeException('wyrób '.$row['code'].' na dwóch stronach', self::INCONSISTENT);
                }
                $rows[$row['id']] = $row;
            }
        }
        if (count($rows) !== $total) {
            throw new RuntimeException('pobrano '.count($rows).' z '.$total.' wyrobów', self::INCONSISTENT);
        }

        return array_values($rows);
    }

    /**
     * Wyrób z listy tylko z polami, których łącznik używa (cała strona listy to ~1 MB na 100 wyrobów).
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    private static function compactRow(array $item): ?array
    {
        $id = $item['id'] ?? null;
        $code = self::clean((string) ($item['name'] ?? ''));
        if (! is_int($id) || $code === '') {
            return null;
        }

        $colours = [];
        foreach (is_array($item['colors'] ?? null) ? $item['colors'] : [] as $index => $colour) {
            if (! is_array($colour) || ! is_int($colour['id'] ?? null)) {
                continue;
            }
            $colours[] = [
                'id' => $colour['id'],
                'ref' => self::clean((string) ($colour['ref'] ?? '')),
                'code' => self::clean((string) ($colour['code'] ?? '')),
                'description' => self::clean((string) ($colour['description'] ?? '')),
                'stock_type' => strtoupper(self::clean((string) ($colour['stockType'] ?? ''))),
                'catalog_image' => is_array($colour['images'] ?? null) && array_key_exists('CTLG', $colour['images']),
                'sequence' => is_int($colour['sequence'] ?? null) ? $colour['sequence'] : PHP_INT_MAX,
                'index' => $index,
            ];
        }
        usort($colours, static fn (array $a, array $b): int => [$a['sequence'], $a['index']] <=> [$b['sequence'], $b['index']]);

        $wanted = array_merge(
            array_keys(self::MAIN_ROWS),
            array_keys(self::NORM_ROWS),
            array_keys(self::MATERIAL_ROWS),
            array_keys(self::FEATURE_ROWS),
            array_keys(self::TEST_ROWS),
            [self::TITLE_ATTRIBUTE, 'norms', 'simplified-brand'],
            self::DESCRIPTION_ATTRIBUTES,
        );
        $attributes = [];
        foreach ($item as $key => $value) {
            if (! is_string($key) || ! is_array($value) || ! array_is_list($value)
                || (! in_array($key, $wanted, true) && ! str_starts_with($key, self::PERFORMANCE_LEVEL_PREFIX))) {
                continue;
            }
            $members = [];
            foreach ($value as $member) {
                if (! is_array($member)) {
                    continue;
                }
                $members[] = [
                    'name' => is_scalar($member['name'] ?? null) ? self::clean((string) $member['name']) : null,
                    'text' => is_string($member['text'] ?? null) ? $member['text'] : null,
                    'key' => self::clean((string) ($member['translationKey'] ?? '')),
                ];
            }
            if ($members !== []) {
                $attributes[$key] = $members;
            }
        }

        return [
            'id' => $id,
            'code' => $code,
            'commercial' => self::clean((string) ($item['commercialName'] ?? '')),
            'brand' => self::clean((string) ($item['brand'] ?? '')),
            'brand_name' => self::clean((string) ($item['brandDescription'] ?? '')),
            'country' => self::clean((string) ($item['countryOfOrigin']['name'] ?? '')),
            'colours' => $colours,
            'attributes' => $attributes,
        ];
    }

    /**
     * Karta wyrobu ze wszystkimi kolorami i rozmiarami do zamówienia; bez ceny konta — pominięty z powodem.
     *
     * @param  array<string, mixed>  $row
     */
    private function productFor(array $row): B2bRemoteProduct
    {
        $code = $row['code'];
        $baseName = $this->cardName($row);

        $colours = array_values(array_filter($row['colours'], static fn (array $c): bool => $c['stock_type'] !== 'L'));
        if ($colours === []) {
            // sam „Outlet” — sklep pokazuje wtedy kolory wyprzedaży w katalogu
            $colours = $row['colours'];
        } else {
            foreach ($row['colours'] as $colour) {
                if ($colour['stock_type'] === 'L') {
                    $this->outletColours[] = $code.'-'.$colour['code'];
                }
            }
        }
        if ($colours === []) {
            return $this->skipped($row, $baseName, 'wyrób bez kolorów na liście');
        }

        // najpierw pozycje do zamówienia każdego koloru: błąd odczytu któregokolwiek koloru = wyrób pominięty w całości
        // (bez jednego koloru karta zmieniłaby tabelę i cenę), a „kilka kolorów” liczy się z kolorów z pozycjami
        $regionId = $this->client->stockRegionId();
        $orderable = [];
        foreach ($colours as $colour) {
            try {
                $items = $this->client->colourItems($colour['id']);
            } catch (B2bFatalException $e) {
                throw $e;
            } catch (RuntimeException $e) {
                return $this->skipped($row, $baseName, 'rozmiary koloru '.$colour['code'].': '.$e->getMessage());
            }
            $positions = [];
            foreach ($items as $item) {
                $position = is_array($item) ? $this->position($item, $regionId) : null;
                if ($position === null) {
                    continue;
                }
                $label = $code.'-'.$colour['code'].' '.$position['size'];
                if ($position['net'] === null) {
                    $this->unorderable[] = $label.' (bez ceny)';
                } elseif ($position['sold_out']) {
                    $this->unorderable[] = $label.' (wyprzedane)';
                } else {
                    $positions[] = $position;
                }
            }
            if ($positions !== []) {
                $orderable[] = ['colour' => $colour, 'positions' => $positions];
            }
        }

        $manyColours = count($orderable) > 1;
        $members = [];
        $identifiers = [new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_SOURCE_CODE, value: $code, field: 'name')];
        $images = [];
        $colourLabels = [];
        $sizeNames = [];
        $buyPers = [];
        $usedIds = [];
        $statuses = [];
        $cheapest = null;
        $leadColour = null;
        foreach ($orderable as ['colour' => $colour, 'positions' => $positions]) {
            $colourLabel = $colour['description'] !== '' ? $colour['description'].' ('.$colour['code'].')' : $colour['code'];
            $firstOfColour = null;
            foreach ($positions as $position) {
                $size = $position['size'];
                $label = $code.'-'.$colour['code'].' '.$size;
                if ($position['promo']) {
                    $this->promotions[] = $label;
                }
                $remoteId = $position['ean'] !== '' && ! isset($usedIds[$position['ean']]) ? $position['ean'] : 'sku-'.$position['sku_id'];
                if (isset($usedIds[$remoteId])) {
                    continue;
                }
                $usedIds[$remoteId] = true;
                $price = new B2bRemotePrice(net: (float) $position['net'], base: $position['base'], currency: 'PLN');
                $sizeLabel = $manyColours ? $colourLabel.' / '.$size : $size;
                $members[] = [
                    'remote_id' => $remoteId,
                    'sku' => $label,
                    'name' => $baseName.', '.$colourLabel.' '.$size,
                    'availability' => $position['availability'],
                    'size' => $sizeLabel,
                    'price' => $price,
                ];
                $statuses[$position['status']][] = $sizeLabel;
                if ($remoteId === $position['ean']) {
                    $identifiers[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_EAN, value: $position['ean'], remoteId: $remoteId, label: $sizeLabel, field: 'eanCode');
                }
                $firstOfColour ??= $remoteId;
                $sizeNames[$size] = true;
                $buyPers[(string) $position['buy_per']] = $position['buy_per'];
                if ($cheapest === null || $price->net < $cheapest->net) {
                    $cheapest = $price;
                }
            }
            if ($firstOfColour === null) {
                continue;
            }
            $colourLabels[] = $colourLabel;
            $leadColour ??= $colour['code'];
            if ($colour['ref'] !== '') {
                $identifiers[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_ALT_CODE, value: $colour['ref'], remoteId: $firstOfColour, label: $colourLabel, field: 'ref');
            }
            if ($colour['catalog_image'] && $row['brand'] !== '' && $colour['code'] !== '') {
                $url = SafetyJoggerB2bClient::fileUrl('/picture/big/'.$row['brand'].'/'.$code.'-'.$colour['code'].'-CTLG.JPG');
                if ($url !== null) {
                    $images[] = $url;
                }
            }
        }

        if ($members === [] || $cheapest === null) {
            $this->withoutPrice[] = $code;

            return $this->skipped($row, $baseName, 'żadna pozycja wyrobu nie ma ceny konta do zamówienia');
        }
        $this->withPrice++;
        $this->cards++;
        $nets = array_map(static fn (array $m): float => $m['price']->net, $members);
        $this->multiPrice += count(array_unique(array_map('strval', $nets))) > 1 ? 1 : 0;

        $order = count($buyPers) > 1
            ? new B2bOrderQuantity(min: null, step: null, varies: true)
            : self::orderQuantity((int) array_values($buyPers)[0]);
        $description = $this->descriptionText($row);
        if ($description === '') {
            $this->withoutDescription[] = $code;
        }
        // kolor wiodący = pierwszy kolor z pozycjami na karcie (adres strony, karta produktu koloru)
        $leadColour = (string) $leadColour;

        return new B2bRemoteProduct(
            remoteId: $members[0]['remote_id'],
            sku: $code,
            name: $baseName,
            category: $this->category($row),
            sourceUrl: SafetyJoggerB2bClient::BASE.'/pl/product/'.rawurlencode($code).($leadColour !== '' ? '/'.rawurlencode($leadColour) : ''),
            raw: [
                'status' => 'ok',
                // cena karty = najniższa cena pozycji (B2bCatalogSync liczy ją też z members[].price)
                'price' => new B2bRemotePrice(net: $cheapest->net, base: $cheapest->base, currency: 'PLN', order: $order),
                // marka katalogu dosłownie (nazwa członu, nie tłumaczenie): „Safety Jogger” także dla linii Oxypas
                'manufacturer' => self::clean((string) ($row['attributes']['simplified-brand'][0]['name'] ?? '')),
                'description' => $description,
                'fields' => $this->fields($row),
                'documents' => $this->documentList($row, $leadColour),
                'images' => $images,
            ],
            availability: self::groupAvailability($statuses),
            variantSummary: ($manyColours ? 'Kolory: '.implode(', ', $colourLabels) : 'Kolor: '.($colourLabels[0] ?? ''))
                .'; rozmiary: '.implode(', ', array_map('strval', array_keys($sizeNames))),
            members: $members,
            identifiers: $identifiers,
        );
    }

    /**
     * Pozycja koloru (rozmiar) z odpowiedzi koszyka; null = to nie pozycja (brak sku).
     *
     * @param  array<string, mixed>  $item
     * @return array{sku_id: int, ean: string, size: string, net: float|null, base: float|null, promo: bool, buy_per: int, availability: string, status: string, sold_out: bool}|null
     */
    private function position(array $item, ?int $regionId): ?array
    {
        $sku = is_array($item['sku'] ?? null) ? $item['sku'] : null;
        if ($sku === null || ! is_int($sku['id'] ?? null)) {
            return null;
        }
        $stocks = array_values(array_filter(is_array($sku['skuStocks'] ?? null) ? $sku['skuStocks'] : [], 'is_array'));
        $stock = $stocks[0] ?? [];
        foreach ($stocks as $candidate) {
            if ($regionId !== null && ($candidate['stockRegion']['id'] ?? null) === $regionId) {
                $stock = $candidate;
                break;
            }
        }
        $size = self::clean((string) ($sku['assortment']['sizeRange'] ?? ''));
        $limited = in_array(strtoupper((string) ($stock['stockType'] ?? '')), self::LIMITED_STOCK_TYPES, true);
        $onHand = (int) ($stock['stock'] ?? 0);
        $toSell = (int) ($stock['stockToSell'] ?? 0);
        $arrival = $stock['firstArrival'] ?? null;
        if ($limited) {
            $status = 'Do wyczerpania zapasu';
            $availability = $status.': '.max(0, $toSell);
        } elseif ($onHand > 0) {
            $status = 'Na stanie';
            $availability = $status.': '.$onHand;
        } elseif (is_int($arrival) || is_float($arrival)) {
            $status = 'Brak na stanie, dostawa od '.self::arrivalDate($arrival);
            $availability = $status;
        } else {
            $status = 'Brak na stanie, na zamówienie';
            $availability = $status;
        }
        $currency = strtoupper((string) ($sku['customerPrice']['currency'] ?? ''));
        $net = self::amount($sku['customerPrice']['value'] ?? null);
        $base = self::amount($sku['defaultPrice']['value'] ?? null);
        if ($currency !== 'PLN' || strtoupper((string) ($sku['defaultPrice']['currency'] ?? 'PLN')) !== 'PLN') {
            // waluta inna niż waluta konta — takiej ceny nie zapisujemy
            $net = null;
            $base = null;
        }
        $buyPer = (int) ($stock['buyPer'] ?? 1);

        return [
            'sku_id' => $sku['id'],
            'ean' => self::clean((string) ($sku['eanCode'] ?? '')),
            'size' => $size !== '' ? $size : (string) $sku['id'],
            'net' => $net,
            'base' => $base,
            'promo' => self::amount($sku['promoPrice']['value'] ?? null) !== null,
            'buy_per' => max(1, $buyPer),
            'availability' => $availability,
            'status' => $status,
            'sold_out' => $limited && $toSell <= 0,
        ];
    }

    private static function amount(mixed $value): ?float
    {
        return (is_int($value) || is_float($value)) && $value > 0 ? round((float) $value, 2) : null;
    }

    /** Sprzedaż w wielokrotnościach buyPer (pole ilości koszyka: min i krok); 1 = bez ograniczeń. */
    private static function orderQuantity(int $buyPer): B2bOrderQuantity
    {
        return $buyPer > 1 ? new B2bOrderQuantity(min: (float) $buyPer, step: (float) $buyPer) : new B2bOrderQuantity(min: 1.0, step: null);
    }

    /**
     * Nazwa karty: rodzaj wyrobu po polsku, marka linii, nazwa handlowa i kod modelu, gdy różni się od nazwy handlowej:
     * „Obuwie ochronne z ochroną podnoska Safety Jogger FREEDOM S1PS LOW FYTS1PSL”. Rodzaj = najwęższa kategoria
     * katalogu; wyrób bez kategorii (rękawice, akcesoria) — polski tytuł ze sklepu („Ciepła i stylowa czapka”), bez
     * niego — rodzaj produktu. Tytuł nie idzie pierwszy, bo bywa hasłem reklamowym („innowacyjne i niezwykle wygodne
     * obuwie…”), a kategoria to słownik katalogu.
     *
     * @param  array<string, mixed>  $row
     */
    private function cardName(array $row): string
    {
        $title = '';
        foreach (self::NAME_CATEGORIES as $attribute) {
            $title = $this->firstValue($row, $attribute) ?? '';
            if ($title !== '') {
                break;
            }
        }
        if ($title === '') {
            $title = $this->polishText($row, self::TITLE_ATTRIBUTE);
        }
        if ($title === '') {
            $title = $this->firstValue($row, 'articletype') ?? '';
        }
        $commercial = $row['commercial'] !== '' ? $row['commercial'] : $row['code'];
        $parts = [$title, $row['brand_name'], $commercial];
        if (self::squashed($commercial) !== self::squashed($row['code'])) {
            $parts[] = $row['code'];
        }

        return self::clean(implode(' ', array_filter($parts, static fn (string $p): bool => $p !== '')));
    }

    /**
     * Kategoria: rodzaj > kategoria > podkategoria po polsku („Buty > Obuwie ochronne > Półbuty”).
     *
     * @param  array<string, mixed>  $row
     */
    private function category(array $row): ?string
    {
        $parts = [];
        foreach ([
            'articletype',
            'shoes_product_category', 'workwear_product_category', 'headprotection_product_category', 'fallprotection_product_category',
            'shoes_product_subcategory', 'workwear_product_subcategory', 'headprotection_product_subcategory',
        ] as $attribute) {
            $value = $this->firstValue($row, $attribute);
            if ($value !== null && ! in_array($value, $parts, true)) {
                $parts[] = $value;
            }
        }

        return $parts !== [] ? implode(' > ', $parts) : null;
    }

    /**
     * Opis: polski długi opis sklepu (bez niego — krótki, który jest jego streszczeniem) i polskie szczegóły wyrobu,
     * dosłownie.
     *
     * @param  array<string, mixed>  $row
     */
    private function descriptionText(array $row): string
    {
        $main = $this->polishText($row, 'product-long-description');
        if ($main === '') {
            $main = $this->polishText($row, 'short-description');
        }
        $details = $this->polishText($row, 'product_details');
        $parts = array_values(array_unique(array_filter([$main, $details], static fn (string $p): bool => $p !== '')));

        return mb_substr(implode("\n", $parts), 0, 10000);
    }

    /**
     * Polski tekst atrybutu opisowego — tłumaczenie klucza członu (pl.json); bez polskiego — ''.
     *
     * @param  array<string, mixed>  $row
     */
    private function polishText(array $row, string $attribute): string
    {
        foreach ($row['attributes'][$attribute] ?? [] as $member) {
            $text = $member['key'] !== '' ? self::htmlText($this->polish[$member['key']] ?? '') : '';
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * Wiersze tabelki w sekcjach: dane ogólne, normy (wiersz na normę z poziomem), materiały, cechy, wyniki badań.
     *
     * @param  array<string, mixed>  $row
     * @return list<array{section: string, name: string, value: string}>
     */
    private function fields(array $row): array
    {
        $fields = [];
        $add = static function (string $section, string $name, string $value) use (&$fields): void {
            $name = self::clean($name);
            $value = trim($value);
            if ($name !== '' && $value !== '') {
                $fields[] = ['section' => $section, 'name' => $name, 'value' => $value];
            }
        };

        $add(self::SECTION_MAIN, 'Marka', $row['brand_name']);
        $add(self::SECTION_MAIN, 'Kod modelu', $row['code']);
        $add(self::SECTION_MAIN, 'Nazwa handlowa', $row['commercial']);
        foreach (self::MAIN_ROWS as $attribute => $labelKey) {
            $add(self::SECTION_MAIN, $this->fieldLabel($labelKey, $attribute), $this->attributeValue($row, $attribute));
        }
        $add(self::SECTION_MAIN, 'Kraj pochodzenia', $row['country']);

        foreach ($this->normRows($row) as $norm) {
            $add(self::SECTION_NORMS, self::NORM_ROW, $norm);
        }
        foreach (self::NORM_ROWS as $attribute => $labelKey) {
            $add(self::SECTION_NORMS, $this->fieldLabel($labelKey, $attribute), $this->attributeValue($row, $attribute));
        }
        foreach ([self::SECTION_MATERIALS => self::MATERIAL_ROWS, self::SECTION_FEATURES => self::FEATURE_ROWS, self::SECTION_TESTS => self::TEST_ROWS] as $section => $rows) {
            foreach ($rows as $attribute => $labelKey) {
                $add($section, $this->fieldLabel($labelKey, $attribute), $this->attributeValue($row, $attribute));
            }
        }

        return $fields;
    }

    /**
     * Normy wyrobu: oznaczenie z tłumaczenia (normDesignation) i poziom z PERFORMANCELEVEL_{nazwa normy}; poziom normy
     * spoza listy norm wyrobu — osobny wiersz z nazwą normy z etykiety poziomu.
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function normRows(array $row): array
    {
        $levels = [];
        foreach ($row['attributes'] as $attribute => $members) {
            if (! str_starts_with($attribute, self::PERFORMANCE_LEVEL_PREFIX)) {
                continue;
            }
            $values = [];
            foreach ($members as $member) {
                $level = self::performanceLevel((string) ($member['text'] ?? ''));
                if ($level !== '' && ! in_array($level, $values, true)) {
                    $values[] = $level;
                }
            }
            if ($values !== []) {
                $levels[substr($attribute, strlen(self::PERFORMANCE_LEVEL_PREFIX))] = implode(' ', $values);
            }
        }

        $rows = [];
        foreach ($row['attributes']['norms'] ?? [] as $member) {
            $name = (string) ($member['name'] ?? '');
            $text = $this->translate($member['key']);
            if ($text === null || $text === $name) {
                // klucz bez tłumaczenia („N_0026”) nie mówi, jaka to norma
                continue;
            }
            $norm = self::normDesignation($text);
            if (isset($levels[$name])) {
                $norm .= ' '.$levels[$name];
                unset($levels[$name]);
            }
            if (! in_array($norm, $rows, true)) {
                $rows[] = $norm;
            }
        }
        foreach ($levels as $name => $level) {
            $label = $this->translate('ca-performancelevel-'.$name);
            if ($label !== null) {
                $rows[] = self::normDesignation($label).' '.$level;
            }
        }

        return $rows;
    }

    /**
     * Wartość atrybutu do tabelki: człony z kluczem — tłumaczenie (bez niego nazwa członu), człony tekstowe — tekst
     * (z tłumaczeniem klucza, gdy jest); „N/A” pomijane. Kilka członów — po średniku.
     *
     * @param  array<string, mixed>  $row
     */
    private function attributeValue(array $row, string $attribute): string
    {
        $values = [];
        foreach ($row['attributes'][$attribute] ?? [] as $member) {
            if ($member['text'] !== null) {
                $value = $member['key'] !== '' ? ($this->translate($member['key']) ?? $member['text']) : $member['text'];
                $value = self::htmlText($value);
            } else {
                $value = $member['key'] !== '' ? ($this->translate($member['key']) ?? (string) $member['name']) : (string) $member['name'];
                $value = self::clean($value);
            }
            if ($value !== '' && strtoupper($value) !== 'N/A' && ! in_array($value, $values, true)) {
                $values[] = $value;
            }
        }

        return implode('; ', $values);
    }

    /**
     * Pierwsza wartość członowa atrybutu po polsku (albo po angielsku, albo nazwa członu); null = brak.
     *
     * @param  array<string, mixed>  $row
     */
    private function firstValue(array $row, string $attribute): ?string
    {
        foreach ($row['attributes'][$attribute] ?? [] as $member) {
            $value = $member['key'] !== '' ? ($this->translate($member['key']) ?? (string) $member['name']) : (string) ($member['name'] ?? $member['text'] ?? '');
            $value = self::clean($value);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function fieldLabel(string $key, string $attribute): string
    {
        return $this->translate($key) ?? $attribute;
    }

    /** Tłumaczenie jak w sklepie: polskie, bez niego angielskie; null = klucz bez tłumaczenia. */
    private function translate(string $key): ?string
    {
        foreach ([$this->polish, $this->english] as $translations) {
            $text = self::clean((string) ($translations[$key] ?? ''));
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    /**
     * Pliki wyrobu: polska karta produktu PDF (bez koloru, inaczej koloru wiodącego; bez polskiej — angielska), polska
     * deklaracja zgodności (bez niej — angielska) i certyfikaty europejskie (etykieta normy EN/CE, bez krajowych:
     * KOSHA, ASTM, UKCA, DCTU…). Błąd listy plików — wyrób bez plików w tym przebiegu (zapisane pliki zostają).
     *
     * @return list<array{title: string, url: string, kind: string}>
     */
    private function documentList(array $row, string $leadColour): array
    {
        try {
            $documents = $this->client->documents($row['id']);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $this->summary[] = 'Lista plików '.$row['code'].' nieodczytana: '.$e->getMessage();

            return [];
        }

        $sheets = [];
        $declarations = [];
        $certificates = [];
        foreach ($documents as $document) {
            if (! is_array($document) || ($document['productId'] ?? null) !== $row['id']) {
                continue;
            }
            $url = SafetyJoggerB2bClient::fileUrl((string) ($document['path'] ?? ''));
            $title = self::clean((string) ($document['fileName'] ?? ''));
            if ($url === null || $title === '') {
                continue;
            }
            $file = ['title' => $title, 'url' => $url, 'colour' => self::clean((string) ($document['color'] ?? '')), 'path' => strtolower((string) $document['path'])];
            $type = (string) ($document['type'] ?? '');
            if ($type === 'PRODUCT_SHEET') {
                $sheets[] = $file;
            } elseif ($type === 'DeclarationofConformity') {
                $declarations[] = $file;
            } elseif ($type === 'CERTIFICATE' && self::isEuropeanCertificate((string) ($this->english[(string) ($document['label'] ?? '')] ?? ''))) {
                // etykieta certyfikatu to klucz tłumaczenia („ca-certificates-C_2011”) — nazwa normy jest po angielsku
                $certificates[] = $file;
            }
        }

        $out = [];
        $sheet = self::pickSheet($sheets, $leadColour);
        if ($sheet !== null) {
            $out[] = ['title' => $sheet['title'], 'url' => $sheet['url'], 'kind' => ProductDocument::KIND_DATASHEET];
        }
        $declaration = self::firstWithLanguage($declarations, '_pl.pdf') ?? self::firstWithLanguage($declarations, '_en.pdf');
        if ($declaration !== null) {
            $out[] = ['title' => $declaration['title'], 'url' => $declaration['url'], 'kind' => ProductDocument::KIND_CERTIFICATE];
        }
        $titles = [];
        foreach ($certificates as $certificate) {
            // ten sam certyfikat bywa przypięty do dwóch norm (rękawice: EN ISO 21420 i EN 388 — „… CE CERT-2024 CTC.pdf”)
            if (isset($titles[$certificate['title']])) {
                continue;
            }
            $titles[$certificate['title']] = true;
            $out[] = ['title' => $certificate['title'], 'url' => $certificate['url'], 'kind' => ProductDocument::KIND_CERTIFICATE];
        }

        return array_values(array_reduce($out, static function (array $carry, array $file): array {
            $carry[$file['url']] ??= $file;

            return $carry;
        }, []));
    }

    /**
     * @param  list<array{title: string, url: string, colour: string, path: string}>  $sheets
     * @return array{title: string, url: string, colour: string, path: string}|null
     */
    private static function pickSheet(array $sheets, string $leadColour): ?array
    {
        foreach (['/pl/', '/en/'] as $language) {
            foreach (['', $leadColour] as $colour) {
                foreach ($sheets as $sheet) {
                    if ($sheet['colour'] === $colour && str_contains($sheet['path'], $language)) {
                        return $sheet;
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array{title: string, url: string, colour: string, path: string}>  $files
     * @return array{title: string, url: string, colour: string, path: string}|null
     */
    private static function firstWithLanguage(array $files, string $suffix): ?array
    {
        foreach ($files as $file) {
            if (str_ends_with($file['path'], $suffix)) {
                return $file;
            }
        }

        return null;
    }

    /** Certyfikat europejski z etykiety sklepu: „EN ISO 20345:2022+A1:2024 (Europe)”, „CE MODULE D for Gloves”. */
    private static function isEuropeanCertificate(string $label): bool
    {
        $label = self::clean($label);
        if ($label === '' || preg_match('/\b(?:DCTU|UKCA|BS\s+EN|ASTM|ANSI|KOSHA|JSAA|SNI|SIRIM|NRCS|CSA|TP\s*TC)\b/iu', $label) === 1) {
            return false;
        }

        return preg_match('/^(?:EN|CEN)\b|\(Europe\)|^CE\s+MODULE/iu', $label) === 1;
    }

    /**
     * Jeden stan dla wszystkich pozycji — dosłownie; różne — „Na stanie: 39, 40; Brak na stanie, dostawa od 15.11.2026: 47”.
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
     * Wyrób, którego nie da się zapisać w tym przebiegu — pozycja bez ceny, widoczna jako pominięta.
     *
     * @param  array<string, mixed>  $row
     */
    private function skipped(array $row, string $name, string $reason): B2bRemoteProduct
    {
        return new B2bRemoteProduct(
            remoteId: $row['code'],
            sku: $row['code'],
            name: $name !== '' ? $name : $row['code'],
            sourceUrl: SafetyJoggerB2bClient::BASE.'/pl/product/'.rawurlencode($row['code']),
            raw: ['status' => 'skipped', 'reason' => $reason],
        );
    }

    /**
     * Tłumaczenia potrzebne łącznikowi (etykiety ca-, sekcje cag-, teksty cav_) — reszta pliku to napisy interfejsu.
     *
     * @param  array<string, string>  $translations
     * @return array<string, string>
     */
    private static function usefulTranslations(array $translations): array
    {
        return array_filter(
            $translations,
            static fn (string $value, string $key): bool => $value !== '' && (str_starts_with($key, 'ca-') || str_starts_with($key, 'cav_')),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** Kod bez odstępów i znaków innych niż litery i cyfry, wielkimi literami (FREEDOM S1PS LOW ≠ FYTS1PSL). */
    private static function squashed(string $text): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $text));
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
