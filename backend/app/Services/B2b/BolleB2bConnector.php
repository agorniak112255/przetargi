<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductIdentifier;
use App\Support\ProductIdentifierCode;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * b2b.bolle-safety.com — jedna pozycja NetSuite (internalid) = jedna karta. Pozycje z liści drzewa kategorii
 * (/api/items, fieldset=details), bez materiałów marketingowych. Ta sama pozycja bywa w kilku liściach
 * (210 z 819 pozycji, 15.09.2026) — liczy się raz, z kategorią pierwszego liścia w kolejności drzewa.
 *
 * Całość jest zbierana przed podaniem pierwszego produktu: totalProducts() musi być znane od pierwszego
 * produktu, a niepełna lista kategorii przerywa przebieg (B2bFatalException) — decyzja, których pozycji
 * brakuje, byłaby zgadywaniem.
 *
 * Bezpieczeństwo sesji: /api/items odpowiada też gościowi, z ceną katalogową w miejscu ceny konta. Cena
 * kontrolna = pierwsza pozycja z ceną konta niższą od katalogowej (pricelevel1). Po zebraniu każdego liścia
 * pozycja kontrolna jest pobierana ponownie — inna cena albo brak pozycji = ponowne logowanie i ponowne zebranie
 * liścia; drugi raz źle = B2bFatalException (nic nie zapisujemy). Dopóki ceny kontrolnej brak, po liściu
 * sprawdzany jest profil sesji. Brak w całym zakresie pozycji z ceną konta niższą od katalogowej = nie da się
 * potwierdzić, że ceny są cenami konta → B2bFatalException.
 *
 * Nazwa istniejącej karty nie jest nadpisywana (B2bKeepsExistingNames): 254 karty Bolle mają polskie nazwy
 * z cennika EMEA, sklep podaje angielskie nazwy rodzin.
 *
 * Sklep podaje teksty po angielsku (B2bForeignLanguageSource, decyzja użytkownika 15.09.2026): opis zapisany
 * przez import i nazwa nowej karty są tłumaczone na polski po zapisie (TranslateB2bProductTextJob). Łącznik
 * podaje tekst dosłownie; tylko etykiety cech w tabelce „Parametry” (shopFields) są naszymi stałymi polskimi
 * odpowiednikami.
 *
 * Normy producenta (B2bNormFactSource, 28.09.2026) — z karty technicznej PDF rodziny, którą API podaje przy pozycji
 * (custitem_c25_web_nextdelivery → item.downloads, karta techniczna po angielsku), z tabeli wersji: kolumna STANDARD
 * wiersza z kodem pozycji (BolleDatasheetTable). Dotąd karty Bolle miały normy tylko ze wzbogacania AI, często
 * błędne (BAXCSP: AI „EN166, EN169”, karta techniczna „EN166 - EN172”). Witryny bolle-safety.com (DataDome, 403)
 * nie pobieramy — tylko pliki z b2b.bolle-safety.com. Jeden PDF opisuje całą rodzinę (ok. 400 pozycji, ok. 120
 * plików), więc odczyt tabeli trzymamy w pamięci podręcznej na adres pliku (adres zawiera hash treści h=).
 */
final class BolleB2bConnector implements B2bConnector, B2bForeignLanguageSource, B2bImageGallery, B2bKeepsExistingNames, B2bManufacturerSite, B2bNormFactProvenance, B2bNormFactSource, B2bShopFieldSource
{
    private const SESSION_LOST = 'Utracono sesję konta bolle-safety.com — ceny konta niedostępne';

    private const REASON_MATRIX = 'pozycja z wariantami (macierz) — importer ich nie obsługuje';

    private const EPSILON = 0.005;

    /** Sekcje karty wyrobu u dostawcy (B2bShopFieldSource). */
    private const SHOP_SECTION_TRADE = 'Informacje handlowe';

    private const SHOP_SECTION_PARAMETERS = 'Parametry';

    /**
     * Pola cech, które mają etykiety filtrów sklepu (SC.CONFIGURATION.facets, odczyt 15.09.2026: Frame Material,
     * Frame Technology, Lens coating, Lens colour). Etykiety to nasze stałe polskie odpowiedniki tych etykiet
     * (15.09.2026) — wartości cech zostają dosłownie ze źródła, po angielsku.
     * Pozostałe custitem_* nie mają etykiet w źródle (część to pola wewnętrzne: segmenty klientów, najbliższa
     * dostawa) — nie trafiają na kartę wyrobu u dostawcy.
     */
    private const PARAMETERS = [
        'custitem_bb_fm_product_material' => 'Materiał oprawki',
        'custitem_bb_specific_technology' => 'Technologia oprawki',
        'custitem_bb_safety_lens_coating' => 'Powłoka soczewki',
        'custitem_bb_safety_lens_shade' => 'Kolor soczewki',
    ];

    /**
     * Pola pozycji zachowywane w raw (reszta odpowiedzi nie jest potrzebna). upccode = EAN sztuki (BAXCSP:
     * 3660740007768, ten sam co w wierszu karty technicznej) — tylko do sprawdzenia wiersza tabeli norm.
     * Z custitem_c25_web_nextdelivery bierzemy wyłącznie listę plików (raw['downloads']), bez stanów i zamówień.
     */
    private const RAW_FIELDS = [
        'internalid', 'itemid', 'upccode', 'storedisplayname2', 'displayname', 'storedescription', 'storedetaileddescription',
        'featureddescription', 'onlinecustomerprice_detail', 'onlinecustomerprice', 'pricelevel1', 'dontshowprice',
        'ispurchasable', 'urlcomponent', 'custitem_b2bminimum', 'custitem_atlas_item_image',
        'custitem_bb_item_image_2', 'custitem_bb_item_image_3', 'custitem_bb_item_image_4', 'custitem_bb_item_image_5',
        'custitem_bb_item_image_6', 'custitem_bb_item_image_7', 'custitem_bb_item_image_8', 'custitem_bb_item_image_9',
        'custitem_bb_item_image_10', 'custitem_bb_item_image_11',
        'custitem_bb_fm_product_material', 'custitem_bb_specific_technology', 'custitem_bb_safety_lens_coating', 'custitem_bb_safety_lens_shade',
    ];

    /** Pole pozycji z napisem JSON: {"v":5,"item":{"downloads":{…},"badges":…,"allocated":…},"POFS…":…}. */
    private const DOWNLOADS_FIELD = 'custitem_c25_web_nextdelivery';

    private const DATASHEET_DISPLAYNAME = 'Technical Sheet';

    /** Odczyt tabeli karty technicznej na adres pliku — plik rodziny pobierany najwyżej raz na ten okres. */
    private const DATASHEET_CACHE_DAYS = 30;

    private int $total = 0;

    /** @var array<string, string> adres karty technicznej → błąd pobrania/odczytu w tym przebiegu (bez ponawiania) */
    private array $failedDatasheets = [];

    /** @var array{remote_id: string, fields: array<string, mixed>}|null źródło par z ostatniego normFacts() */
    private ?array $lastNormProvenance = null;

    private ?int $controlId = null;

    private ?float $controlPrice = null;

    public function __construct(private readonly BolleB2bClient $client) {}

    public static function key(): string
    {
        return 'bolle';
    }

    public static function label(): string
    {
        return 'Bolle';
    }

    public static function host(): string
    {
        return BolleB2bClient::HOST;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new BolleB2bClient($account->username, (string) $account->password, $delayMs));
    }

    public function login(): void
    {
        $this->client->login();
    }

    public function products(): iterable
    {
        $this->total = 0;
        $this->controlId = null;
        $this->controlPrice = null;

        if (! $this->client->sessionAlive()) {
            // login() potwierdza sesję w profilu — nieudane = B2bFatalException
            $this->client->relogin();
        }

        /** @var array<int, array{item: array<string, mixed>, path: string}> $found */
        $found = [];
        foreach ($this->client->leafCategories() as $leaf) {
            $items = $this->leafItems($leaf);
            if (! $this->sessionHolds()) {
                $this->client->relogin();
                $items = $this->leafItems($leaf);
                if (! $this->sessionHolds()) {
                    throw new B2bFatalException(self::SESSION_LOST.' (kontrola po kategorii '.$leaf['path'].' po ponownym logowaniu)');
                }
            }
            foreach ($items as $id => $item) {
                $found[$id] ??= ['item' => $item, 'path' => $leaf['path']];
            }
        }

        if ($this->controlId === null) {
            throw new B2bFatalException(
                'W katalogu konta '.BolleB2bClient::HOST.' nie ma żadnej pozycji z ceną konta niższą od katalogowej — nie da się potwierdzić, że ceny są cenami konta'
            );
        }

        $this->total = count($found);
        foreach ($found as $entry) {
            yield $this->productFor($entry['item'], $entry['path']);
        }
    }

    public function totalProducts(): int
    {
        return $this->total;
    }

    /** Tak nazywają się istniejące karty (254, cennik EMEA) — B2bCatalogSync porównuje producenta z kartą. */
    /** Witryna nalezy do tej marki — tylko jej karty wolno nadpisac opisem stad. */
    public static function ownBrand(): string
    {
        return 'Bolle';
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return 'Bolle';
    }

    /**
     * Cena konta = onlinecustomerprice (sklep podaje ją bez oznaczenia VAT — przyjmujemy jako netto zakupu, jak
     * w JSP). Katalogowa = pricelevel1: 15.09.2026 równa cenie katalogowej z cennika EMEA dla 194 z 207 wspólnych
     * kodów (13 różnic ±0,1 i jedna zmiana ceny) i zawsze ≥ cena konta. pricelevel1 niższe od ceny konta = brak
     * katalogowej (null), nie zgadujemy. Waluta z profilu konta. Warunek zamawiania — orderQuantity().
     */
    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            throw new RuntimeException((string) ($product->raw['reason'] ?? 'pozycja nieodczytana'));
        }
        if (($product->raw['dontshowprice'] ?? false) === true) {
            return null;
        }
        $net = self::accountPrice($product->raw);
        if ($net === null || $net <= 0) {
            return null;
        }
        $net = round($net, 2);

        $catalog = $product->raw['pricelevel1'] ?? null;
        $base = is_numeric($catalog) && (float) $catalog > 0 && round((float) $catalog, 2) >= $net
            ? round((float) $catalog, 2)
            : null;

        return new B2bRemotePrice(
            net: $net,
            base: $base,
            discountPercent: 0.0,
            currency: $this->client->currency(),
            order: $this->orderQuantity($product->raw),
        );
    }

    /**
     * Warunek zamawiania jak w skrypcie sklepu (extensions/shopping_5.js, odczyt 05.10.2026): minimum pozycji =
     * custitem_b2bminimum, a gdy puste lub 0 — 1; koszyk przyjmuje tylko wielokrotności minimum (ilość % minimum == 0,
     * „Quantity - Multiples of 10”), więc minimum jest też krokiem. 05.10.2026 w trzech kategoriach okularów: 140
     * pozycji po 10, 70 po 1, 31 bez pola. Konto zwolnione z minimum (allowsLowQuantity) i wartość nieliczbowa = null:
     * warunku nie znamy, zapisany zostaje. Bez jednostki — sklep jej nie podaje.
     *
     * @param  array<string, mixed>  $raw
     */
    private function orderQuantity(array $raw): ?B2bOrderQuantity
    {
        if ($this->client->allowsLowQuantity()) {
            return null;
        }
        $value = $raw['custitem_b2bminimum'] ?? null;
        if ($value === null || $value === '' || $value === false) {
            return new B2bOrderQuantity(min: 1.0, step: null);
        }
        if (! is_numeric($value)) {
            return null;
        }
        $minimum = (float) $value;
        if ($minimum <= 1) {
            return new B2bOrderQuantity(min: 1.0, step: null);
        }

        return new B2bOrderQuantity(min: $minimum, step: $minimum);
    }

    /**
     * Opis dosłownie ze sklepu (tłumaczenie dopiero po zapisie karty — B2bForeignLanguageSource), sekcje
     * oddzielone pustą linią: krótki opis (storedescription), opis szczegółowy (storedetaileddescription), opis
     * wyróżniony (featureddescription) — sekcja identyczna z wcześniejszą pominięta. Cech z PARAMETERS tu nie ma:
     * to pary „nazwa → wartość”, które podaje shopFields() jako tabelkę „Parametry”. Pozycja bez żadnego z trzech
     * pól opisowych zostaje bez opisu; pusty opis niczego nie nadpisuje (B2bCatalogSync::applyCardDetails).
     */
    public function description(B2bRemoteProduct $product): string
    {
        $sections = [];
        foreach (['storedescription', 'storedetaileddescription', 'featureddescription'] as $field) {
            $text = self::htmlToText((string) ($product->raw[$field] ?? ''));
            if ($text !== '' && ! in_array($text, $sections, true)) {
                $sections[] = $text;
            }
        }

        return mb_substr(implode("\n\n", $sections), 0, 10000);
    }

    /**
     * Karta wyrobu u dostawcy z tej samej odpowiedzi /api/items, którą products() już pobrał (żadnego zapytania
     * więcej): kod towaru i cztery cechy z etykietami filtrów sklepu (PARAMETERS) — wartości dosłownie ze
     * źródła, po angielsku, jak w opisie. Pozostałych pól custitem_* sklep nie opisuje etykietą, więc na kartę
     * nie trafiają. Ceny nie dokładamy — karta ma na nie własną sekcję.
     *
     * @return list<B2bRemoteShopField>
     */
    public function shopFields(B2bRemoteProduct $product): array
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            return [];
        }

        $fields = [];
        $sku = trim((string) ($product->raw['itemid'] ?? $product->sku));
        if ($sku !== '') {
            $fields[] = new B2bRemoteShopField(self::SHOP_SECTION_TRADE, 'Kod towaru', $sku);
        }
        foreach (self::PARAMETERS as $field => $label) {
            $value = $product->raw[$field] ?? null;
            if (! is_scalar($value)) {
                continue;
            }
            $value = self::inlineText((string) $value);
            if ($value !== '') {
                $fields[] = new B2bRemoteShopField(self::SHOP_SECTION_PARAMETERS, $label, $value);
            }
        }

        return $fields;
    }

    /**
     * Zdjęcie z custitem_atlas_item_image. API podaje ścieżkę bez domeny („/core/media/media.nl?id=…&c=…&h=…”,
     * sprawdzone na żywym sklepie 15.09.2026 — przebiegi #14 i #16 odrzuciły przez to wszystkie zdjęcia), więc ścieżkę
     * zaczynającą się od „/” uzupełniamy adresem sklepu; pełny adres innego hosta dalej odrzuca klient. Adres zapisujemy
     * w pełni, z parametrem h — to hash pliku w NetSuite (bez niego HTTP 403), nie token sesji: plik pobiera się bez logowania.
     */
    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        $url = $this->imageUrls($product)[0] ?? null;

        return $url === null ? null : $this->imageAt($url);
    }

    /**
     * Galeria pozycji (B2bImageGallery, 28.09.2026): zdjęcie główne z custitem_atlas_item_image, potem kolejne ujęcia
     * z custitem_bb_item_image_2…11 (w sklepie 0–6 dodatkowych na pozycję). Galeria, a nie pojedyncze zdjęcie, bo
     * synchronizacja dokłada wtedy zdjęcie producenta także karcie, która ma już zdjęcia — 207 kart z importu pliku
     * z 12.09 miało tylko zdjęcia wyłowione z obcych sklepów (specshop.pl, e-militaria.eu…), a pojedyncze zdjęcie
     * zapisujemy tylko karcie bez żadnego. Zdjęcie spod adresu Bolle, które karta już ma, dostaje stempel konta
     * i miejsce z galerii (ProductImage::resequence stawia zdjęcia producenta przed resztą).
     *
     * @return list<string>
     */
    public function imageUrls(B2bRemoteProduct $product): array
    {
        $fields = ['custitem_atlas_item_image'];
        for ($i = 2; $i <= 11; $i++) {
            $fields[] = 'custitem_bb_item_image_'.$i;
        }
        $urls = [];
        foreach ($fields as $field) {
            $url = trim((string) ($product->raw[$field] ?? ''));
            if ($url === '') {
                continue;
            }
            if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
                $url = BolleB2bClient::BASE.$url;
            }
            if (! in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    public function imageAt(string $url): ?B2bRemoteImage
    {
        $file = $this->client->imageBytes($url);
        if ($file['bytes'] === '' || ! str_starts_with($file['mime'], 'image/')) {
            return null;
        }

        return new B2bRemoteImage(bytes: $file['bytes'], mime: $file['mime'], sourceUrl: $url);
    }

    /**
     * Normy z karty technicznej pozycji (BolleDatasheetTable::match): jedna para na oznaczenie z kolumny STANDARD,
     * dosłownie i bez poziomu („EN166”, „EN ISO 16321-1”), oraz „Oznaczenie soczewki” z kolumny LENS MARKING, gdy
     * jest. Karta = europejska karta techniczna po angielsku („…_EN.pdf”, „…-EN.pdf”), a gdy jej nie ma — karta rynku
     * USA („…_EN-US.pdf”); wybór w englishDatasheets(). Kilka takich kart — wiersz pozycji musi dać ten sam odczyt we
     * wszystkich, w których jest.
     *
     * [] = pozycja bez karty, bez swojego wiersza (TRYON RX: w kolumnie REFERENCE nazwa rodziny), wiersz bez normy
     * (VOLT 2.0 HEADGEAR), inny EAN w wierszu albo komórka nieczytelna — zapisane normy zostają bez zmian.
     * Błąd pobrania albo nieczytelny PDF = wyjątek (synchronizacja zgłasza ostrzeżenie i też niczego nie zmienia).
     *
     * @return list<B2bRemoteNormFact>
     */
    public function normFacts(B2bRemoteProduct $product): array
    {
        $this->lastNormProvenance = null;
        if (($product->raw['status'] ?? null) !== 'ok') {
            return [];
        }
        $reference = trim((string) ($product->raw['itemid'] ?? $product->sku));
        $sheets = self::englishDatasheets($product->raw['downloads'] ?? null);
        if ($reference === '' || $sheets === []) {
            return [];
        }
        $upc = $product->raw['upccode'] ?? null;
        $ean = is_scalar($upc) && preg_match('/^\d{8,14}$/', trim((string) $upc)) === 1 ? trim((string) $upc) : null;

        $found = null;
        foreach ($sheets as $sheet) {
            $match = BolleDatasheetTable::match($this->datasheetRows($sheet['url']), $reference, $ean);
            if ($match === null) {
                continue;
            }
            if ($found !== null && ($found['match']['norms'] !== $match['norms'] || $found['match']['lens'] !== $match['lens'])) {
                return [];
            }
            $found ??= ['sheet' => $sheet, 'match' => $match];
        }
        if ($found === null) {
            return [];
        }

        $facts = array_map(static fn (string $norm): B2bRemoteNormFact => new B2bRemoteNormFact($norm), $found['match']['norms']);
        if ($found['match']['lens'] !== null) {
            $facts[] = new B2bRemoteNormFact(BolleDatasheetTable::LENS_LABEL, $found['match']['lens']);
        }

        $row = $found['match']['row'];
        $this->lastNormProvenance = [
            'remote_id' => $product->remoteId,
            'fields' => [
                'kind' => 'datasheet',
                'document_url' => $found['sheet']['url'],
                'document_name' => $found['sheet']['name'],
                'page' => $row['page'],
                'identity' => array_filter(
                    ['by' => 'reference', 'value' => $reference, 'ean' => $ean !== null ? $row['ean'] : null],
                    static fn (?string $value): bool => $value !== null,
                ),
                'block' => $row['block'],
                'block_sha256' => hash('sha256', $row['block']),
            ],
        ];

        return $facts;
    }

    /**
     * Karta techniczna, wiersz i dosłowny tekst wiersza, z których normFacts() wziął pary tego produktu.
     *
     * @return array<string, mixed>
     */
    public function normFactProvenance(B2bRemoteProduct $product): array
    {
        return $this->lastNormProvenance !== null && $this->lastNormProvenance['remote_id'] === $product->remoteId
            ? $this->lastNormProvenance['fields']
            : [];
    }

    /**
     * Wiersze tabeli karty technicznej: z pamięci podręcznej (klucz = wersja odczytu + adres pliku z hashem treści),
     * inaczej pobranie i odczyt — jeden plik naraz, bajty PDF nie zostają w pamięci po odczycie. Błąd pobrania albo
     * odczytu zapamiętujemy do końca przebiegu, żeby kolejne pozycje tej rodziny nie pobierały pliku ponownie; do
     * pamięci podręcznej błąd nie trafia (następny przebieg próbuje znowu).
     *
     * @return list<array{page: int, reference: string, lens: string|null, standard: string|null, ean: string|null, ambiguous: list<string>, block: string}>
     */
    private function datasheetRows(string $url): array
    {
        $key = 'bolle-datasheet:v'.BolleDatasheetTable::VERSION.':'.sha1($url);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        if (isset($this->failedDatasheets[$url])) {
            throw new RuntimeException($this->failedDatasheets[$url]);
        }

        try {
            $rows = BolleDatasheetTable::parse($this->client->documentBytes($url)['bytes']);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->failedDatasheets[$url] = 'karta techniczna '.$url.': '.$e->getMessage();

            throw new RuntimeException($this->failedDatasheets[$url], 0, $e);
        }
        Cache::put($key, $rows, now()->addDays(self::DATASHEET_CACHE_DAYS));

        return $rows;
    }

    /**
     * Pliki pozycji z pola DOWNLOADS_FIELD (napis JSON) — tylko nazwa, nazwa wyświetlana i adres (pełny, ze ścieżki
     * sklepu). Pole w innym kształcie (data najbliższej dostawy w starszych pozycjach) = brak plików.
     *
     * @return list<array{name: string, displayname: string, url: string}>
     */
    private static function downloads(mixed $field): array
    {
        $json = is_string($field) ? json_decode($field, true) : null;
        $downloads = is_array($json) && is_array($json['item'] ?? null) ? ($json['item']['downloads'] ?? null) : null;
        if (! is_array($downloads)) {
            return [];
        }

        $out = [];
        foreach ($downloads as $download) {
            if (! is_array($download) || ! is_string($download['url'] ?? null)) {
                continue;
            }
            $url = trim($download['url']);
            if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
                $url = BolleB2bClient::BASE.$url;
            }
            if ($url === '') {
                continue;
            }
            $out[] = [
                'name' => trim((string) ($download['name'] ?? '')),
                'displayname' => trim((string) ($download['displayname'] ?? '')),
                'url' => $url,
            ];
        }

        return $out;
    }

    /**
     * @return list<array{name: string, displayname: string, url: string}>
     */
    private static function englishDatasheets(mixed $downloads): array
    {
        if (! is_array($downloads)) {
            return [];
        }

        $sheets = array_values(array_filter($downloads, static fn (mixed $download): bool => is_array($download)
            && is_string($download['url'] ?? null)
            && self::isDatasheet($download)));
        $named = static fn (string $pattern): array => array_values(array_filter(
            $sheets,
            static fn (array $sheet): bool => preg_match($pattern, trim((string) ($sheet['name'] ?? ''))) === 1,
        ));

        // Europejska karta po angielsku („…-FT-EMEA_EN.pdf”, „…-FT-EMEA-EN.pdf”). Amerykańska (…_EN-US.pdf) tylko
        // gdy europejskiej nie ma (10 pozycji, 28.09.2026) — ten sam kod i EAN w wierszu to ten sam wyrób.
        return $named('/[_-]EN\.pdf$/i') ?: $named('/[_-]EN-US\.pdf$/i');
    }

    /**
     * Karta techniczna: plik opisany „Technical Sheet”, a bez opisu — z nazwą karty technicznej („SWIFT
     * OTG-DATASHEET_EN.pdf”, „…-FT-…”).
     *
     * @param  array<mixed>  $download
     */
    private static function isDatasheet(array $download): bool
    {
        $label = trim((string) ($download['displayname'] ?? ''));
        if ($label !== '') {
            return strcasecmp($label, self::DATASHEET_DISPLAYNAME) === 0;
        }

        return preg_match('/DATASHEET|-FT[-_]/i', (string) ($download['name'] ?? '')) === 1;
    }

    /**
     * Wszystkie strony liścia, bez powtórzeń po internalid. Liczba pozycji ≠ total sklepu = B2bFatalException.
     * Przy okazji zapamiętuje cenę kontrolną (pierwsza pozycja z ceną konta niższą od katalogowej).
     *
     * @param  array{url: string, path: string}  $leaf
     * @return array<int, array<string, mixed>>
     */
    private function leafItems(array $leaf): array
    {
        $items = [];
        $offset = 0;
        $total = 0;
        do {
            try {
                $page = $this->client->itemsPage($leaf['url'], $offset);
            } catch (B2bFatalException $e) {
                throw $e;
            } catch (RuntimeException $e) {
                throw new B2bFatalException('Nie udało się pobrać listy kategorii '.$leaf['path'].': '.$e->getMessage(), 0, $e);
            }
            $total = $page['total'];
            foreach ($page['items'] as $item) {
                $id = $item['internalid'] ?? null;
                if (! is_int($id) || $id <= 0) {
                    continue;
                }
                $items[$id] ??= $item;
                if ($this->controlId === null) {
                    $this->rememberControl($id, $item);
                }
            }
            $offset += count($page['items']);
        } while ($page['items'] !== [] && $offset < $total);

        if (count($items) !== $total) {
            throw new B2bFatalException('Lista kategorii '.$leaf['path'].' niepełna ('.count($items).' z '.$total.')');
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function rememberControl(int $id, array $item): void
    {
        $price = self::accountPrice($item);
        $catalog = $item['pricelevel1'] ?? null;
        if ($price !== null && $price > 0 && is_numeric($catalog) && $price < (float) $catalog - self::EPSILON) {
            $this->controlId = $id;
            $this->controlPrice = $price;
        }
    }

    private function sessionHolds(): bool
    {
        if ($this->controlId === null || $this->controlPrice === null) {
            return $this->client->sessionAlive();
        }
        try {
            $item = $this->client->item($this->controlId);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException) {
            return false;
        }
        $price = $item !== null ? self::accountPrice($item) : null;

        return $price !== null && abs($price - $this->controlPrice) < self::EPSILON;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function productFor(array $item, string $path): B2bRemoteProduct
    {
        $id = (string) $item['internalid'];
        $sku = trim((string) ($item['itemid'] ?? ''));
        $url = $this->client->productUrl((string) ($item['urlcomponent'] ?? ''));

        $matrix = $item['matrixchilditems_detail'] ?? null;
        if (is_array($matrix) && $matrix !== []) {
            return new B2bRemoteProduct(
                remoteId: $id,
                sku: $sku,
                name: $sku,
                category: $path,
                sourceUrl: $url,
                raw: ['status' => 'skipped', 'internalid' => $item['internalid'], 'itemid' => $sku, 'reason' => self::REASON_MATRIX],
            );
        }

        $raw = ['status' => 'ok'];
        foreach (self::RAW_FIELDS as $field) {
            if (array_key_exists($field, $item)) {
                $raw[$field] = $item[$field];
            }
        }
        $downloads = self::downloads($item[self::DOWNLOADS_FIELD] ?? null);
        if ($downloads !== []) {
            $raw['downloads'] = $downloads;
        }

        return new B2bRemoteProduct(
            remoteId: $id,
            sku: $sku,
            name: self::name($item),
            category: $path,
            sourceUrl: $url,
            raw: $raw,
            // Kod towaru Bolle (itemid), dosłownie — sklep producenta, karta = jedna pozycja, więc to kod producenta
            // pozycji karty (internalid). internalid to wewnętrzny numer NetSuite, nie identyfikator wyrobu.
            // upccode to EAN sztuki: 28.09.2026 zgodny z EAN-em wiersza karty technicznej producenta dla 172 pozycji
            // (kolumna SINGLE) — tylko z poprawną sumą kontrolną GTIN.
            identifiers: self::identifiers($sku, $id, $item['upccode'] ?? null),
        );
    }

    /**
     * @return list<B2bRemoteIdentifier>
     */
    private static function identifiers(string $sku, string $remoteId, mixed $upc): array
    {
        $out = [];
        if ($sku !== '') {
            $out[] = new B2bRemoteIdentifier(
                type: ProductIdentifier::TYPE_MANUFACTURER_CODE,
                value: $sku,
                remoteId: $remoteId,
                field: 'itemid',
            );
        }
        $ean = is_scalar($upc) ? trim((string) $upc) : '';
        if (preg_match('/^\d{8,14}$/', $ean) === 1 && ProductIdentifierCode::gtin($ean) !== null) {
            $out[] = new B2bRemoteIdentifier(
                type: ProductIdentifier::TYPE_EAN,
                value: $ean,
                remoteId: $remoteId,
                field: 'upccode',
            );
        }

        return $out;
    }

    /**
     * Nazwa rodziny (storedisplayname2, gdy pusta — displayname) + krótki opis, gdy go wyróżnia, np.
     * „TRYON BSSI – Copper safety glasses”. Bez rodziny — sam krótki opis.
     *
     * @param  array<string, mixed>  $item
     */
    private static function name(array $item): string
    {
        $family = trim((string) ($item['storedisplayname2'] ?? ''));
        if ($family === '') {
            $family = trim((string) ($item['displayname'] ?? ''));
        }
        $short = self::inlineText((string) ($item['storedescription'] ?? ''));

        if ($family === '') {
            return $short;
        }

        return $short !== '' && mb_strtolower($short) !== mb_strtolower($family) ? $family.' – '.$short : $family;
    }

    /**
     * Cena konta z pozycji: onlinecustomerprice_detail.onlinecustomerprice, a gdy jej brak — onlinecustomerprice.
     *
     * @param  array<string, mixed>  $item
     */
    private static function accountPrice(array $item): ?float
    {
        $detail = $item['onlinecustomerprice_detail'] ?? null;
        $value = is_array($detail) && is_numeric($detail['onlinecustomerprice'] ?? null)
            ? $detail['onlinecustomerprice']
            : ($item['onlinecustomerprice'] ?? null);

        return is_numeric($value) ? (float) $value : null;
    }

    /** HTML → linie tekstu (jak AnroB2bConnector::description): encje zdekodowane, &nbsp; → spacja, bez pustych linii. */
    private static function htmlToText(string $html): string
    {
        $text = preg_replace('#<\s*br\s*/?>#i', "\n", $html) ?? $html;
        $text = preg_replace('#<\s*li[^>]*>#i', "\n- ", $text) ?? $text;
        $text = preg_replace('#</\s*(p|div|h[1-6]|li|ul|ol|tr|table)\s*>#i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $line) ?? $line);
            if ($line !== '' && $line !== '-') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /** Tekst w jednej linii (nazwa, wartość cechy). */
    private static function inlineText(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', self::htmlToText($html)));
    }
}
