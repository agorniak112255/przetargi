<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use DateTimeImmutable;
use RuntimeException;

/**
 * portwest.com/market — portal B2B producenta Portwest (marki Portwest i Base — obuwie grupy Portwest). Sprawdzone na
 * zalogowanym koncie 01.10.2026 (PortwestB2bClient opisuje logowanie i pliki): ceny konta w PLN, ok. 1860 modeli
 * Portwest i 220 modeli Base w sprzedaży.
 *
 * Katalog powstaje z plików konta, nie ze stron wyrobów:
 * - cenniki konta CSV (ProductRange, ProductGroup, Style, ItemCode, Currency, Price) — cena konta pozycji (rozmiaru
 *   koloru); cennika katalogowego portal nie podaje;
 * - Daily Data (sohPL.csv, Base: sohPLB.csv) — oferta sklepu: pozycja z kolorem, rozmiarem, krojem (Fit), EAN-em,
 *   stanem w Polsce, terminem dostawy, wielokrotnością zamówienia i zdjęciem koloru. Pozycji z cennika, których nie ma
 *   w Daily Data, sklep nie sprzedaje (sprawdzone: kolor 2201 RB tylko w cenniku nie ma siatki zamówienia, modele A020
 *   i FW09 przekierowują na stronę główną) — na kartę nie idą;
 * - polskie opisy (descPL.csv, Base: descbPL.csv): nazwa, opis i cechy modelu, rodzaj, linia, kolekcja;
 * - normy (stds.csv, Base: base_stds.csv): normy modelu dosłownie z poziomem („EN 388: 2016 + A1: 2018 (4X43D)”),
 *   jednostka certyfikująca i numer certyfikatu.
 *
 * Karta = model (Style) ze wszystkimi kolorami i rozmiarami (decyzje użytkownika 28.09.2026). Pozycja = ItemCode
 * (remote_id — EAN bywa pusty), cena karty = najniższa cena pozycji. Kolor pozycji to kod koloru z krojem, jak w adresie
 * strony sklepu (/products/view/2201/WHR): ItemCode bez kodu modelu i bez rozmiaru. Opis karty to wyłącznie polskie
 * teksty z pliku opisów; angielski opis z Daily Data na kartę nie trafia (brak = pusty opis, w podsumowaniu).
 * Pliki: polska karta produktu PDF koloru wiodącego i polska deklaracja zgodności UE (tylko model z normą europejską —
 * portal wydaje deklarację także wyrobom bez norm, jako pusty PDF); zdjęcie każdego koloru.
 *
 * Ceny kontraktowe konta (/account/downloadContractPrices) to ceny jednej wyceny z terminem ważności — nie są ceną
 * karty i łącznik ich nie pobiera.
 */
final class PortwestB2bConnector implements B2bConnector, B2bDocumentSource, B2bGroupsSizes, B2bImageGallery, B2bListProgressAware, B2bManufacturerSite, B2bRunSummaryAware, B2bShopFieldNormSource, B2bShopFieldSource, B2bSizePriceSource
{
    public const BRAND = 'Portwest';

    /** Marka obuwia grupy Portwest — osobne pliki konta; producent kart = Portwest. */
    public const BASE_BRAND = 'Base';

    /** Pliki Daily Data (nazwa pliku z „Linków marketingowych”) → marka. */
    private const DAILY_FILES = ['sohPL.csv' => self::BRAND, 'sohPLB.csv' => self::BASE_BRAND];

    /**
     * Cenniki konta (numer grupy w adresie exportCustomerPriceLists): „99” — wszystkie wyroby Portwest, „12” — Base.
     * Pozostałe grupy strony linków (odzież, obuwie, ŚOI…) to wycinki cennika „99” — bez nich.
     */
    private const PRICE_GROUPS = ['99' => self::BRAND, '12' => self::BASE_BRAND];

    private const DESCRIPTION_FILES = [
        self::BRAND => 'https://'.PortwestB2bClient::CDN_HOST.'/marketing_files/desc/descPL.csv',
        self::BASE_BRAND => 'https://'.PortwestB2bClient::CDN_HOST.'/marketing_files/desc/descbPL.csv',
    ];

    private const STANDARD_FILES = [
        self::BRAND => 'https://'.PortwestB2bClient::CDN_HOST.'/marketing_files/stds/stds.csv',
        self::BASE_BRAND => 'https://'.PortwestB2bClient::CDN_HOST.'/marketing_files/stds/base_stds.csv',
    ];

    private const PRICE_COLUMNS = ['ProductRange', 'ProductGroup', 'Style', 'ItemCode', 'Currency', 'Price'];

    private const DAILY_COLUMNS = ['Style', 'Item', 'Carton_Qty', 'Colour', 'Size', 'Fit', 'Product_Name', 'PL_SoH', 'Next_Delivery', 'EAN13', 'DUN14', 'Image_Path', 'Order_Multiple', 'Price'];

    private const DESCRIPTION_COLUMNS = ['Style', 'ProductType', 'Range', 'Collection', 'Product', 'Description', 'Features'];

    private const STANDARD_COLUMNS = ['Style', 'Test House', 'Cert No.', 'Standards (1)'];

    /**
     * Mniej pozycji = plik ucięty albo pusty, nie koniec oferty: przebieg bez zapisu (karty dostałyby okrojone rozmiary,
     * ceny i identyfikatory). 01.10.2026: cennik 28 202 + 3505 pozycji, Daily Data 21 573 + 3225 — próg razem i na plik.
     */
    private const MIN_PRICE_ROWS = 5000;

    private const MIN_DAILY_ROWS = 5000;

    private const MIN_ROWS_PER_FILE = 1000;

    /** Próg pliku opisów i norm marki (01.10.2026: Portwest 1860 i 5957 wierszy, Base 222 i 243). */
    private const MIN_ROWS = [self::BRAND => 1000, self::BASE_BRAND => 100];

    /**
     * Więcej pozycji Daily Data bez ceny w cenniku konta = cennik ucięty, innego konta albo zmiana kodów: przebieg
     * przerwany. 01.10.2026: 492 z 24 798 (2%).
     */
    private const MAX_UNPRICED_SHARE = 0.08;

    private const PROGRESS_EVERY = 100;

    /** Wiersz normy w tabelce (normShopFieldNames) — norma europejska albo międzynarodowa (EN, EN ISO, ISO, IEC). */
    private const NORM_ROW = 'Norma';

    /**
     * Pozostałe wpisy kolumn norm, dosłownie: normy spoza Europy (ANSI/ISEA, AS/NZS, ASTM, NFPA, RIS), krajowe (BS 8599),
     * oznakowanie („CE Cat 1”, „RoHS”), deklaracje producenta („Waterproof/Breathability (WP 10,000mm)”, „Fabric Conforms
     * to EN 388…”) — poza wierszem „Norma”, żeby nie stały się normą karty w dopasowaniu przetargów.
     */
    private const OTHER_NORM_ROW = 'Inne normy i oznaczenia';

    /** Oznaczenie normy europejskiej na początku wpisu, także z przedrostkiem krajowym („BS EN”, „UNI EN ISO”, „PN-EN”). */
    private const EUROPEAN_NORM = '/^(?:(?:PN|BS|UNI|CEI|DIN|NF|SN|ÖNORM)[\s\-]+)?(?:EN|ISO|IEC|CEN)(?=[\s\d]|$)/iu';

    /**
     * Kolejne oznaczenie EN po numerze poprzedniej normy („EN 61482-2 EN 61482-1-2 APC 1”) — dwie normy, dwa wiersze.
     * Przedrostek krajowy („BS EN 13034”) normy nie dzieli: przed nim nie ma cyfry.
     */
    private const NEXT_DESIGNATION = '/(?<=\d)\s+(?=(?:PN[\s\-]+)?EN\s?(?:ISO\s?)?\d)/u';

    /** Numer części normy oddzielony spacją („EN 1149 -5”, 233 wpisy) — część oznaczenia, nie poziom. */
    private const SPACED_PART = '/^((?:[A-Z]{2,5}[\s\-]+)?EN(?:\s?ISO)?\s?\d{2,6})\s+-(\d{1,3})(?!\d)/u';

    private const SECTION_MAIN = 'Informacje ze sklepu Portwest';

    private const SECTION_NORMS = 'Normy';

    /** Krój pozycji (Fit z Daily Data) poza zwykłym R — jak w nazwach kolorów portalu („Navy Tall”, „Black Short”). */
    private const FITS = ['S' => 'Short', 'T' => 'Tall', 'X' => 'X-Tall'];

    private const ONE_SIZE = 'jeden rozmiar';

    private int $total = 0;

    private int $cards = 0;

    private int $multiPrice = 0;

    /** @var array<string, float> ItemCode → cena konta (PLN) */
    private array $prices = [];

    /** @var array<string, array{0: string, 1: string}> Style → [ProductRange, ProductGroup] z cennika */
    private array $priceGroups = [];

    /**
     * Pozycje Daily Data po modelu, w kolejności pliku: [Item, Colour, Size, Fit, PL_SoH, Next_Delivery, EAN13, Image_Path,
     * Order_Multiple, Carton_Qty, DUN14, Price] — sama lista pól (wiersz z nazwami kolumn zajmowałby trzy razy więcej
     * pamięci).
     *
     * @var array<string, list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string, 6: string, 7: string, 8: string, 9: string, 10: string, 11: string}>>
     */
    private array $daily = [];

    /** @var array<string, string> Style → marka (Portwest / Base) */
    private array $brands = [];

    /** @var array<string, string> Style → Product_Name z Daily Data (angielska u Portwest, polska u Base) */
    private array $dailyNames = [];

    /** @var array<string, array{type: string, range: string, collection: string, product: string, description: string, features: list<string>}> */
    private array $descriptions = [];

    /** @var array<string, array{house: string, certificate: string, standards: list<string>}> */
    private array $standards = [];

    /** @var list<string> */
    private array $summary = [];

    /** @var list<string> */
    private array $withoutPrice = [];

    /** @var list<string> pozycje sklepu bez ceny w cenniku konta i w Daily Data („2201WHRL”) */
    private array $unpriced = [];

    /** @var list<string> pozycje z ceną tylko z Daily Data (brak w cenniku CSV) */
    private array $dailyPriced = [];

    /** @var list<string> pozycje z różną ceną w cenniku CSV i w Daily Data („2201WHRL 67.50 / 68.00”) */
    private array $priceMismatches = [];

    /** Wiersze Daily Data powtórzone (ten sam Item drugi raz — 01.10.2026: 7 identycznych wierszy Base B0820…). */
    private int $duplicateRows = 0;

    /** @var list<string> */
    private array $withoutDescription = [];

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    public function __construct(
        private readonly PortwestB2bClient $client,
    ) {}

    public static function key(): string
    {
        return 'portwest';
    }

    public static function label(): string
    {
        return self::BRAND;
    }

    public static function host(): string
    {
        return PortwestB2bClient::HOST;
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
        return new self(new PortwestB2bClient((string) $account->username, (string) $account->password, $delayMs));
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
        $this->unpriced = [];
        $this->dailyPriced = [];
        $this->withoutDescription = [];
        $this->cards = 0;
        $this->multiPrice = 0;

        if (! $this->client->isLoggedIn()) {
            $this->client->login();
        }
        $this->readFiles();

        $styles = array_keys($this->daily);
        $this->total = count($styles);
        $this->summary[] = sprintf(
            'Lista Portwest: %d modeli w Daily Data (Portwest %d, Base %d), %d pozycji; cennik konta: %d pozycji',
            count($styles),
            count(array_filter($this->brands, static fn (string $b): bool => $b === self::BRAND)),
            count(array_filter($this->brands, static fn (string $b): bool => $b === self::BASE_BRAND)),
            array_sum(array_map('count', $this->daily)),
            count($this->prices),
        );
        $priceOnly = array_diff_key($this->priceGroups, $this->daily);
        if ($priceOnly !== []) {
            $this->summary[] = 'Modele tylko w cenniku konta, bez oferty sklepu (Daily Data) — bez karty: '.self::listing(array_map('strval', array_keys($priceOnly)));
        }

        $done = 0;
        foreach ($styles as $style) {
            $style = (string) $style;
            $product = $this->productFor($style);
            // pozycje modelu nie są już potrzebne — pamięć zadania w tle to 128 MB
            unset($this->daily[$style]);
            $done++;
            if ($done % self::PROGRESS_EVERY === 0) {
                $this->progress('Modele Portwest: '.$done.'/'.count($styles));
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
            $lines[] = 'Modele bez żadnej pozycji z ceną (pominięte): '.self::listing($this->withoutPrice);
        }
        if ($this->unpriced !== []) {
            $lines[] = 'Pozycje sklepu bez ceny w cenniku konta i w Daily Data (poza kartą): '.self::listing($this->unpriced);
        }
        if ($this->dailyPriced !== []) {
            $lines[] = 'Pozycje z ceną z Daily Data, bez wiersza w cenniku CSV konta: '.self::listing($this->dailyPriced);
        }
        if ($this->duplicateRows > 0) {
            $lines[] = 'Powtórzone wiersze Daily Data (wzięty pierwszy): '.$this->duplicateRows;
        }
        if ($this->priceMismatches !== []) {
            $lines[] = 'Różna cena w cenniku CSV i w Daily Data (na karcie cena z cennika): '.self::listing($this->priceMismatches);
        }
        if ($this->withoutDescription !== []) {
            $lines[] = 'Bez polskiego opisu w pliku opisów Portwest: '.self::listing($this->withoutDescription);
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
            throw new RuntimeException((string) ($product->raw['reason'] ?? 'model nieodczytany'));
        }
        $price = $product->raw['price'] ?? null;
        if (! $price instanceof B2bRemotePrice) {
            throw new RuntimeException('model bez ceny konta');
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
     * Zdjęcie każdego koloru karty (kolor wiodący pierwszy).
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
     * Norma europejska albo międzynarodowa (EN, EN ISO, ISO, IEC, CEN, też z przedrostkiem krajowym „PN-EN”, „BS EN”).
     * Pozostałe wpisy (ANSI/ISEA, AS/NZS, RIS, „CE Cat 1”, „Waterproof/Breathability…”) idą do wiersza „Inne normy
     * i oznaczenia”, żeby nie stały się normą karty w dopasowaniu przetargów.
     */
    public static function isEuropeanNorm(string $text): bool
    {
        return preg_match(self::EUROPEAN_NORM, self::clean($text)) === 1;
    }

    /**
     * Wiersze tabelki z jednego wpisu kolumny norm: [nazwa wiersza, wartość]. Wpis z dwiema normami EN („EN 61482-2
     * EN 61482-1-2 APC 1”) — dwa wiersze „Norma”; numer części oddzielony spacją („EN 1149 -5”) — złączony („EN 1149-5”),
     * bo parser norm wziąłby „-5” za poziom normy EN 1149. Reszta dosłownie.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function normRows(string $text): array
    {
        $text = self::clean($text);
        if ($text === '') {
            return [];
        }
        if (! self::isEuropeanNorm($text)) {
            return [[self::OTHER_NORM_ROW, $text]];
        }
        $rows = [];
        foreach (preg_split(self::NEXT_DESIGNATION, $text) ?: [$text] as $part) {
            $part = trim((string) preg_replace(self::SPACED_PART, '$1-$2', trim($part)));
            if ($part !== '') {
                $rows[] = [self::NORM_ROW, $part];
            }
        }

        return $rows;
    }

    /** Data dostawy z Daily Data („27/11/2026”) po polsku: „27.11.2026”; inny zapis — ''. */
    public static function deliveryDate(string $text): string
    {
        $date = DateTimeImmutable::createFromFormat('!d/m/Y', trim($text));

        return $date !== false && $date->format('d/m/Y') === trim($text) ? $date->format('d.m.Y') : '';
    }

    /**
     * Kod koloru z krojem, jak w adresie strony sklepu: ItemCode bez kodu modelu i bez rozmiaru („2201WHRL” → „WHR”,
     * „A001BKR” → „BKR”). Rozmiar, który nie jest końcówką kodu („BL100BKG”, rozmiar „100cm”) — pierwsze trzy znaki
     * po kodzie modelu.
     */
    public static function colourCode(string $style, string $item, string $size): string
    {
        $tail = str_starts_with($item, $style) ? substr($item, strlen($style)) : $item;
        if ($size !== '' && str_ends_with($tail, $size) && strlen($tail) > strlen($size)) {
            return substr($tail, 0, strlen($tail) - strlen($size));
        }
        if ($size === '') {
            return $tail;
        }

        return substr($tail, 0, 3);
    }

    /**
     * Wszystkie pliki konta i katalogu: cenniki, Daily Data, opisy, normy. Plik pusty, ucięty albo o zmienionym układzie
     * przerywa przebieg (B2bFatalException) — przed pierwszym wyrobem, więc nic nie jest zapisane ani uznane za zniknięte.
     */
    private function readFiles(): void
    {
        $this->prices = [];
        $this->priceGroups = [];
        $this->daily = [];
        $this->brands = [];
        $this->dailyNames = [];
        $this->descriptions = [];
        $this->standards = [];
        $this->priceMismatches = [];
        $this->duplicateRows = 0;

        $currencies = [];
        $priceRows = 0;
        $urls = $this->client->priceListUrls();
        foreach (array_keys(self::PRICE_GROUPS) as $group) {
            $url = $urls[(string) $group] ?? null;
            if ($url === null) {
                throw new B2bFatalException('Strona „Linki marketingowe” konta '.PortwestB2bClient::HOST.' nie ma cennika CSV grupy '.$group.' ('.self::PRICE_GROUPS[$group].') — portal zmienił stronę; przebieg przerwany bez zapisu');
            }
            $rows = $this->client->eachCsvRow($url, self::PRICE_COLUMNS, function (array $row) use (&$currencies): void {
                $item = $row['ItemCode'];
                $style = $row['Style'];
                $price = self::amount($row['Price']);
                if ($item === '' || $style === '' || $price === null) {
                    return;
                }
                $currency = strtoupper($row['Currency']);
                $currencies[$currency] = true;
                if ($currency !== 'PLN') {
                    return;
                }
                // ta sama pozycja w dwóch cennikach (Portwest i Base) ma tę samą cenę (01.10.2026: 20 pozycji, 0 różnic)
                $this->prices[$item] ??= $price;
                $this->priceGroups[$style] ??= [$row['ProductRange'], $row['ProductGroup']];
            });
            self::requireRows('Cennik konta (grupa '.$group.')', $rows);
            $priceRows += $rows;
        }
        $foreign = array_values(array_diff(array_keys($currencies), ['PLN']));
        if ($foreign !== []) {
            throw new B2bFatalException('Cennik konta '.PortwestB2bClient::HOST.' ma ceny w walucie „'.implode('”, „', $foreign).'”, a katalog przyjmuje ceny w PLN — przebieg przerwany bez zapisu');
        }
        if ($priceRows < self::MIN_PRICE_ROWS || count($this->prices) < self::MIN_PRICE_ROWS) {
            throw new B2bFatalException('Cennik konta '.PortwestB2bClient::HOST.' ma tylko '.count($this->prices).' pozycji z ceną (oczekiwane co najmniej '.self::MIN_PRICE_ROWS.') — plik ucięty albo zmieniony; przebieg przerwany bez zapisu');
        }

        $dailyRows = 0;
        /** @var array<string, true> $seenItems */
        $seenItems = [];
        $priced = 0;
        $dataRows = 0;
        $dailyUrls = $this->client->dailyDataUrls();
        foreach (self::DAILY_FILES as $file => $brand) {
            $url = $dailyUrls[$file] ?? null;
            if ($url === null) {
                throw new B2bFatalException('Strona „Linki marketingowe” konta '.PortwestB2bClient::HOST.' nie ma pliku Daily Data '.$file.' ('.$brand.') — portal zmienił stronę; przebieg przerwany bez zapisu');
            }
            $rows = $this->client->eachCsvRow($url, self::DAILY_COLUMNS, function (array $row) use ($brand, &$priced, &$seenItems, &$dataRows): void {
                $style = $row['Style'];
                $item = $row['Item'];
                if ($style === '' || $item === '') {
                    return;
                }
                // strażnicy pliku (próg wierszy, udział bez ceny) liczą wiersze pliku — mają wykryć plik ucięty; karta
                // bierze każdą pozycję raz
                $dataRows++;
                if (isset($this->prices[$item])) {
                    $priced++;
                }
                if (isset($seenItems[$item])) {
                    $this->duplicateRows++;

                    return;
                }
                $seenItems[$item] = true;
                $this->brands[$style] ??= $brand;
                $name = self::clean($row['Product_Name']);
                if ($name !== '') {
                    $this->dailyNames[$style] ??= $name;
                }
                $this->daily[$style][] = [
                    $item,
                    self::clean($row['Colour']),
                    self::clean($row['Size']),
                    strtoupper(trim($row['Fit'])),
                    trim($row['PL_SoH']),
                    trim($row['Next_Delivery']),
                    trim($row['EAN13']),
                    trim($row['Image_Path']),
                    trim($row['Order_Multiple']),
                    trim($row['Carton_Qty']),
                    trim($row['DUN14']),
                    trim($row['Price']),
                ];
                if (isset($this->prices[$item])) {
                    $daily = self::amount($row['Price']);
                    if ($daily !== null && abs($daily - $this->prices[$item]) > 0.001) {
                        $this->priceMismatches[] = $item.' '.number_format($this->prices[$item], 2, '.', '').' / '.number_format($daily, 2, '.', '');
                    }
                }
            });
            self::requireRows('Daily Data '.$file, $rows);
            $dailyRows += $rows;
        }
        if ($dailyRows < self::MIN_DAILY_ROWS || $dataRows < self::MIN_DAILY_ROWS) {
            throw new B2bFatalException('Daily Data konta '.PortwestB2bClient::HOST.' ma tylko '.$dataRows.' pozycji (oczekiwane co najmniej '.self::MIN_DAILY_ROWS.') — plik ucięty albo zmieniony; przebieg przerwany bez zapisu');
        }
        if ($dataRows - $priced > $dataRows * self::MAX_UNPRICED_SHARE) {
            throw new B2bFatalException(($dataRows - $priced).' z '.$dataRows.' pozycji Daily Data nie ma ceny w cenniku konta '.PortwestB2bClient::HOST.' — cennik ucięty, innego konta albo zmiana kodów pozycji; przebieg przerwany bez zapisu');
        }

        foreach (self::DESCRIPTION_FILES as $brand => $url) {
            $rows = $this->client->eachCsvRow($url, self::DESCRIPTION_COLUMNS, function (array $row): void {
                $style = $row['Style'];
                if ($style === '' || ! isset($this->daily[$style]) || isset($this->descriptions[$style])) {
                    return;
                }
                $features = [];
                foreach ($row as $column => $value) {
                    if ($column !== 'Features' && ! str_starts_with((string) $column, '#')) {
                        continue;
                    }
                    $value = self::clean($value);
                    if ($value !== '' && ! in_array($value, $features, true)) {
                        $features[] = $value;
                    }
                }
                $this->descriptions[$style] = [
                    'type' => self::clean($row['ProductType']),
                    'range' => self::clean($row['Range']),
                    'collection' => self::clean($row['Collection']),
                    'product' => self::clean($row['Product']),
                    'description' => self::clean($row['Description']),
                    'features' => $features,
                ];
            });
            self::requireRows('Opisy '.$brand, $rows, self::MIN_ROWS[$brand]);
        }

        foreach (self::STANDARD_FILES as $brand => $url) {
            $rows = $this->client->eachCsvRow($url, self::STANDARD_COLUMNS, function (array $row): void {
                $style = $row['Style'];
                if ($style === '' || ! isset($this->daily[$style]) || isset($this->standards[$style])) {
                    return;
                }
                $standards = [];
                for ($i = 1; $i <= 20; $i++) {
                    // znaczniki HTML w zapisie jednostek („Cal/CM<sup>2</sup>”) — sam tekst
                    $value = self::clean(html_entity_decode(strip_tags($row['Standards ('.$i.')'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                    if ($value !== '' && ! in_array($value, $standards, true)) {
                        $standards[] = $value;
                    }
                }
                $house = self::clean($row['Test House']);
                $certificate = self::clean($row['Cert No.']);
                if ($standards !== [] || $house !== '' || $certificate !== '') {
                    $this->standards[$style] = ['house' => $house, 'certificate' => $certificate, 'standards' => $standards];
                }
            });
            self::requireRows('Normy '.$brand, $rows, self::MIN_ROWS[$brand]);
        }
    }

    /**
     * Plik z mniejszą liczbą wierszy niż próg = ucięty albo podmieniony: przebieg bez zapisu — karty dostałyby okrojone
     * rozmiary, opisy albo tabelki norm.
     */
    private static function requireRows(string $label, int $rows, int $min = self::MIN_ROWS_PER_FILE): void
    {
        if ($rows < $min) {
            throw new B2bFatalException('Plik „'.$label.'” z '.PortwestB2bClient::HOST.' ma tylko '.$rows.' wierszy (oczekiwane co najmniej '.$min.') — plik ucięty albo zmieniony; przebieg przerwany bez zapisu');
        }
    }

    /**
     * Karta modelu ze wszystkimi kolorami i rozmiarami z ceną konta; bez żadnej — pominięty z powodem.
     */
    private function productFor(string $style): B2bRemoteProduct
    {
        $brand = $this->brands[$style] ?? self::BRAND;
        $name = $this->cardName($style, $brand);

        // pozycje z ceną, pogrupowane po kolorze (kod koloru z krojem); nazwa koloru — pierwsza niepusta w grupie (część
        // wierszy Daily Data ma pusty kolor przy wypełnionym w sąsiednich rozmiarach)
        $colours = [];
        foreach ($this->daily[$style] ?? [] as $row) {
            [$item, $colourName, $size, $fit] = $row;
            // cena konta z cennika CSV, a bez wiersza w cenniku — z Daily Data: to ta sama cena konta, którą pokazuje siatka
            // zamówienia (01.10.2026: 0 różnic na 21 tys. pozycji obu plików; A147 jest w sprzedaży po 10,70, a w cenniku
            // CSV go nie ma)
            $price = $this->prices[$item] ?? null;
            if ($price === null) {
                $price = self::amount($row[11]);
                if ($price === null) {
                    $this->unpriced[] = $item;

                    continue;
                }
                $this->dailyPriced[] = $item;
            }
            $code = self::colourCode($style, $item, $size);
            $colours[$code] ??= ['name' => '', 'fit' => $fit, 'image' => '', 'rows' => []];
            if ($colours[$code]['name'] === '' && $colourName !== '') {
                $colours[$code]['name'] = $colourName;
                $colours[$code]['fit'] = $fit;
            }
            $colours[$code]['rows'][] = [...$row, $price];
            if ($colours[$code]['image'] === '' && PortwestB2bClient::isPublicFileUrl($row[7])) {
                $colours[$code]['image'] = $row[7];
            }
        }
        // kolejność kolorów po kodzie — kolor wiodący (adres strony i karty produktu PDF) nie zmienia się, gdy Daily Data
        // przestawi wiersze; inny kolor wiodący to nowy adres karty produktu, czyli drugi plik na karcie
        ksort($colours, SORT_STRING);
        foreach ($colours as $code => $colour) {
            $colours[$code]['label'] = self::colourLabel($colour['name'], (string) $code, $colour['fit']);
        }
        if ($colours === []) {
            $this->withoutPrice[] = $style;

            return $this->skipped($style, $name, 'żadna pozycja modelu z oferty sklepu nie ma ceny konta (ani w cenniku CSV, ani w Daily Data)');
        }

        $manyColours = count($colours) > 1;
        $members = [];
        $identifiers = [new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_MODEL_CODE, value: $style, field: 'Style')];
        $images = [];
        $sizeNames = [];
        $multiples = [];
        $cartons = [];
        $statuses = [];
        $usedIds = [];
        $cheapest = null;
        foreach ($colours as $colour) {
            foreach ($colour['rows'] as $row) {
                [$item, , $size, , $stock, $delivery, $ean, , $multiple, $carton, $dun, , $net] = $row;
                if (isset($usedIds[$item])) {
                    continue;
                }
                $usedIds[$item] = true;
                $sizeLabel = $size !== '' ? $size : self::ONE_SIZE;
                $label = $manyColours ? $colour['label'].' / '.$sizeLabel : $sizeLabel;
                $status = self::status($stock, $delivery);
                $price = new B2bRemotePrice(net: $net, currency: 'PLN');
                $members[] = [
                    'remote_id' => $item,
                    'sku' => $item,
                    'name' => $name.', '.$colour['label'].' '.$sizeLabel,
                    'availability' => $status['availability'],
                    'size' => $label,
                    'price' => $price,
                ];
                $statuses[$status['status']][] = $label;
                $identifiers[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_SOURCE_CODE, value: $item, remoteId: $item, label: $label, field: 'Item');
                if (preg_match('/^\d{8,14}$/', $ean) === 1) {
                    $identifiers[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_EAN, value: $ean, remoteId: $item, label: $label, field: 'EAN13');
                }
                if (preg_match('/^\d{14}$/', $dun) === 1) {
                    $identifiers[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_PACK_EAN, value: $dun, remoteId: $item, label: $label, field: 'DUN14');
                }
                $sizeNames[$sizeLabel] = true;
                $multiples[(string) max(1, (int) $multiple)] = max(1, (int) $multiple);
                if (ctype_digit($carton)) {
                    $cartons[$carton] = true;
                }
                if ($cheapest === null || $price->net < $cheapest->net) {
                    $cheapest = $price;
                }
            }
            if ($colour['image'] !== '' && ! in_array($colour['image'], $images, true)) {
                $images[] = $colour['image'];
            }
        }

        $this->cards++;
        $nets = array_map(static fn (array $m): float => $m['price']->net, $members);
        $this->multiPrice += count(array_unique(array_map('strval', $nets))) > 1 ? 1 : 0;

        $order = count($multiples) > 1
            ? new B2bOrderQuantity(min: null, step: null, varies: true)
            : self::orderQuantity((int) array_values($multiples)[0]);
        $description = $this->descriptionText($style);
        if ($description === '') {
            $this->withoutDescription[] = $style;
        }
        // kolor wiodący = pierwszy kolor z ceną w kolejności Daily Data (adres strony, karta produktu)
        $leadColour = (string) array_key_first($colours);
        $colourLabels = array_map(static fn (array $c): string => $c['label'], array_values($colours));

        return new B2bRemoteProduct(
            remoteId: $members[0]['remote_id'],
            sku: $style,
            name: $name,
            category: $this->category($style),
            sourceUrl: PortwestB2bClient::BASE.'/products/view/'.rawurlencode($style).'/'.rawurlencode($leadColour),
            raw: [
                'status' => 'ok',
                // cena karty = najniższa cena pozycji (B2bCatalogSync liczy ją też z members[].price)
                'price' => new B2bRemotePrice(net: (float) $cheapest?->net, currency: 'PLN', order: $order),
                'description' => $description,
                'fields' => $this->fields($style, $brand, count($cartons) === 1 ? (string) array_key_first($cartons) : ''),
                'documents' => $this->documentList($style, $leadColour),
                'images' => $images,
            ],
            availability: self::groupAvailability($statuses),
            variantSummary: ($manyColours ? 'Kolory: '.implode(', ', $colourLabels) : 'Kolor: '.$colourLabels[0])
                .'; rozmiary: '.implode(', ', array_map('strval', array_keys($sizeNames))),
            members: $members,
            identifiers: $identifiers,
        );
    }

    /**
     * Kolor pozycji: nazwa z Daily Data i kod koloru z krojem („Black (BKR)”); bez nazwy — sam kod. Krój inny niż zwykły,
     * którego nazwa koloru nie podaje (Fit „S” przy „Navy”) — dopisany: „Navy Short (NAS)”.
     */
    private static function colourLabel(string $name, string $code, string $fit): string
    {
        $fitName = self::FITS[$fit] ?? null;
        if ($name !== '' && $fitName !== null && ! str_contains(mb_strtolower($name), mb_strtolower($fitName))) {
            $name .= ' '.$fitName;
        }

        return $name !== '' ? $name.' ('.$code.')' : $code;
    }

    /**
     * Stan pozycji: na stanie w Polsce (PL_SoH), inaczej termin dostawy, inaczej brak.
     *
     * @return array{status: string, availability: string}
     */
    private static function status(string $stock, string $delivery): array
    {
        $onHand = ctype_digit($stock) ? (int) $stock : 0;
        if ($onHand > 0) {
            return ['status' => 'Na stanie', 'availability' => 'Na stanie: '.$onHand];
        }
        $date = self::deliveryDate($delivery);
        $status = $date !== '' ? 'Brak na stanie, dostawa od '.$date : 'Brak na stanie';

        return ['status' => $status, 'availability' => $status];
    }

    /** Sprzedaż w wielokrotnościach (Order_Multiple; pole ilości siatki ma wtedy step, A080 po 12); 1 = bez ograniczeń. */
    private static function orderQuantity(int $multiple): B2bOrderQuantity
    {
        return $multiple > 1 ? new B2bOrderQuantity(min: (float) $multiple, step: (float) $multiple) : new B2bOrderQuantity(min: 1.0, step: null);
    }

    /**
     * Nazwa karty: polska nazwa z pliku opisów (bez niej — Product_Name z Daily Data), marka i kod modelu:
     * „Kombinezon dla przemysłu spożywczego Portwest 2201”.
     */
    private function cardName(string $style, string $brand): string
    {
        $title = $this->descriptions[$style]['product'] ?? '';
        if ($title === '') {
            $title = $this->dailyNames[$style] ?? '';
        }
        $parts = [$title, $brand];
        if (! str_contains(self::squashed($title), self::squashed($style))) {
            $parts[] = $style;
        }

        return self::clean(implode(' ', array_filter($parts, static fn (string $p): bool => $p !== '')));
    }

    /**
     * Kategoria: rodzaj > linia > kolekcja z pliku opisów („Odzież > Odzież robocza”), bez opisu — grupa z cennika konta.
     */
    private function category(string $style): ?string
    {
        $description = $this->descriptions[$style] ?? null;
        $parts = $description !== null
            ? [$description['type'], $description['range'], $description['collection']]
            : ($this->priceGroups[$style] ?? []);
        $unique = [];
        foreach ($parts as $part) {
            $part = self::clean($part);
            if ($part !== '' && ! in_array($part, $unique, true)) {
                $unique[] = $part;
            }
        }

        return $unique !== [] ? implode(' > ', $unique) : null;
    }

    /** Opis: polski opis modelu i jego cechy (każda w osobnej linii), dosłownie z pliku opisów. */
    private function descriptionText(string $style): string
    {
        $description = $this->descriptions[$style] ?? null;
        if ($description === null) {
            return '';
        }
        $lines = array_values(array_filter(
            [$description['description'], ...$description['features']],
            static fn (string $line): bool => $line !== '',
        ));

        return mb_substr(implode("\n", $lines), 0, 10000);
    }

    /**
     * Wiersze tabelki: dane modelu z plików konta i normy (wiersz na normę, osobno normy spoza UE).
     *
     * @return list<array{section: string, name: string, value: string}>
     */
    private function fields(string $style, string $brand, string $carton): array
    {
        $fields = [];
        $add = static function (string $section, string $name, string $value) use (&$fields): void {
            $value = trim($value);
            if ($value !== '') {
                $fields[] = ['section' => $section, 'name' => $name, 'value' => $value];
            }
        };

        $description = $this->descriptions[$style] ?? null;
        $group = $this->priceGroups[$style] ?? ['', ''];
        $dailyName = $this->dailyNames[$style] ?? '';
        $add(self::SECTION_MAIN, 'Marka', $brand);
        $add(self::SECTION_MAIN, 'Kod modelu', $style);
        // nazwa z Daily Data (u Portwest angielska), gdy karta ma polską nazwę z pliku opisów — bez niej nazwa karty to ta sama nazwa
        if ($dailyName !== '' && ($description['product'] ?? '') !== '' && $dailyName !== $description['product']) {
            $add(self::SECTION_MAIN, 'Nazwa w danych sklepu', $dailyName);
        }
        $add(self::SECTION_MAIN, 'Rodzaj', $description['type'] ?? '');
        $add(self::SECTION_MAIN, 'Linia', $description['range'] ?? '');
        $add(self::SECTION_MAIN, 'Kolekcja', $description['collection'] ?? '');
        $add(self::SECTION_MAIN, 'Grupa w cenniku', implode(' > ', array_values(array_unique(array_filter([self::clean($group[0]), self::clean($group[1])])))));
        $add(self::SECTION_MAIN, 'Ilość w kartonie', $carton);

        $standards = $this->standards[$style] ?? null;
        if ($standards !== null) {
            $seen = [];
            foreach ($standards['standards'] as $norm) {
                foreach (self::normRows($norm) as [$row, $value]) {
                    if (! isset($seen[$row."\n".$value])) {
                        $seen[$row."\n".$value] = true;
                        $add(self::SECTION_NORMS, $row, $value);
                    }
                }
            }
            $add(self::SECTION_NORMS, 'Jednostka certyfikująca', $standards['house']);
            $add(self::SECTION_NORMS, 'Numer certyfikatu', $standards['certificate']);
        }

        return $fields;
    }

    /**
     * Polska karta produktu koloru wiodącego i polska deklaracja zgodności UE — deklaracja tylko dla modelu z normą
     * europejską (bez norm portal wydaje pusty PDF).
     *
     * @return list<array{title: string, url: string, kind: string}>
     */
    private function documentList(string $style, string $leadColour): array
    {
        $query = 'style='.rawurlencode($style).'&lang=PL&itemcol='.rawurlencode($style.$leadColour);
        $base = 'https://'.PortwestB2bClient::DOCUMENTS_HOST;
        $files = [[
            'title' => 'Karta produktu '.$style,
            'url' => $base.'/datasheet.php?'.$query.'&facility=920',
            'kind' => ProductDocument::KIND_DATASHEET,
        ]];
        $norms = $this->standards[$style]['standards'] ?? [];
        if (array_filter($norms, static fn (string $norm): bool => self::isEuropeanNorm($norm)) !== []) {
            $files[] = [
                'title' => 'Deklaracja zgodności UE '.$style,
                'url' => $base.'/declaration_eu.php?'.$query,
                'kind' => ProductDocument::KIND_CERTIFICATE,
            ];
        }

        return $files;
    }

    /**
     * Jeden stan dla wszystkich pozycji — dosłownie; różne — „Na stanie: S, M; Brak na stanie, dostawa od 27.11.2026: L”.
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

    /** Model, którego nie da się zapisać w tym przebiegu — pozycja bez ceny, widoczna jako pominięta. */
    private function skipped(string $style, string $name, string $reason): B2bRemoteProduct
    {
        return new B2bRemoteProduct(
            remoteId: $style,
            sku: $style,
            name: $name !== '' ? $name : $style,
            sourceUrl: PortwestB2bClient::BASE.'/products/view/'.rawurlencode($style),
            raw: ['status' => 'skipped', 'reason' => $reason],
        );
    }

    private static function amount(string $value): ?float
    {
        $value = trim($value);
        if (preg_match('/^\d+(?:\.\d+)?$/', $value) !== 1) {
            return null;
        }
        $amount = round((float) $value, 2);

        return $amount > 0 ? $amount : null;
    }

    /** Kod bez odstępów i znaków innych niż litery i cyfry, wielkimi literami. */
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

    /** Tekst z pliku: bez znaków zerowej szerokości, odstępy (także twarde spacje) zwinięte. */
    private static function clean(string $text): string
    {
        $text = str_replace(["\u{200B}", "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{FEFF}"], '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));
    }
}
