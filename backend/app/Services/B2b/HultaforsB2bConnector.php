<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use RuntimeException;

/**
 * partnerportal.hultaforsgroup.pl — portal partnerski Hultafors Group (marki Snickers Workwear, Hultafors, Emma Safety
 * Footwear, Solid Gear, Hellberg Safety, CLC, W.steps, Tradeport). Sprawdzone na zalogowanym koncie 01.10.2026
 * (HultaforsB2bClient opisuje logowanie): 1674 wyroby, konto polskie, ceny w PLN. Decyzja użytkownika 01.10.2026:
 * wszystkie marki portalu.
 *
 * Lista (POST ProductUpdateList z polami nawigacji ze strony /pl/catalog/node/products, strony po PAGE_SIZE) podaje
 * wyrób wirtualny: kod („6220”, „E0023”), markę, adres strony i cenę konta (albo żadnej — wyrób bez pozycji
 * w sprzedaży na konto). Wyroby idą w kolejności kodu, żeby przypisanie pozycji wspólnych nie zależało od kolejności
 * listy portalu.
 *
 * Karta = wyrób wirtualny, pozycja karty = artykuł z tabeli pozycji wyrobu (sekcja listy „4 Virtual” na stronie
 * wyrobu albo w jego zakładkach): kod artykułu, EAN, rozmiar, dostępność, cena brutto (cennikowa), rabat konta i cena
 * netto (cena konta). Cena „0,00 zł” = pozycja bez ceny konta — poza kartą. Tabela ma dwa układy: Snickers, Emma
 * i Solid Gear — wiersze ładowane osobno stronami po 100 i kolor tylko w matrycy kolor × rozmiar; Hellberg i Hultafors
 * — wiersze w stronie, kolumny techniczne w zakładce „Dodaj do koszyka”, EAN i dane transportowe w drugiej tabeli.
 * Dwa wyroby wirtualne bywają zbudowane z tych samych artykułów (kaski Sector: żółty i biały mają po 8 kolorów) —
 * artykuł należy do pierwszej karty przebiegu, a wyrób bez własnych artykułów jest pomijany z powodem.
 *
 * remote_id pozycji = kod artykułu (EAN bywa wspólny dla dwóch kodów), SKU karty = kod wyrobu, producent = marka
 * z listy dosłownie. Opis: sekcje opisowe strony przed pierwszym nagłówkiem („Rozmiar”, „Material”) — dosłownie;
 * portal pokazuje tekst angielski tam, gdzie brak polskiego tłumaczenia (B2bForeignTextCards). Tabelka: sekcje pod
 * nagłówkami, oznaczenia z ikon, certyfikacja z zakładki „Certification” (wiersz „Norma” na każdą normę EN, dosłownie
 * z poziomami), kolumny techniczne tabeli pozycji. Pliki: dokumenty wyrobu (bez szablonu znakowania „profiling”
 * i deklaracji UKCA), tabela rozmiarów; zdjęcia: galeria strony.
 */
final class HultaforsB2bConnector implements B2bConnector, B2bDocumentSource, B2bForeignTextCards, B2bGroupsSizes, B2bImageGallery, B2bListProgressAware, B2bManufacturerBrands, B2bManufacturerSite, B2bRunSummaryAware, B2bShopFieldNormSource, B2bShopFieldSource, B2bSizePriceSource
{
    public const LABEL = 'Hultafors Group';

    /** Marka główna portalu (B2bManufacturerSite::ownBrand); pozostałe marki grupy — ownBrands(). */
    public const BRAND = 'Snickers Workwear';

    /**
     * Marki Hultafors Group dosłownie jak na liście portalu (01.10.2026) i w nazwach eksportów danych portalu. Bez
     * „W.steps”: klucz marki „w” pasowałby do każdej marki na „W” (B2bManufacturerSiteBrands) — 4 drabiny W.steps mają
     * producenta dosłownie z portalu, bez pierwszeństwa producenta.
     */
    private const GROUP_BRANDS = [
        'Hultafors', 'Emma Safety Footwear', 'Solid Gear', 'Hellberg Safety', 'CLC', 'Tradeport', 'Toe Guard',
        'Telesteps', 'Scangrip',
    ];

    private const CATALOG_PAGE = '/pl/catalog/node/products';

    private const PAGE_SIZE = 100;

    private const MAX_LIST_PAGES = 100;

    private const MAX_TABLE_PAGES = 50;

    private const PROGRESS_EVERY = 50;

    private const INCONSISTENT = 7302;

    private const VIRTUAL_RELATION = '4 Virtual';

    /** Kolumny tabeli pozycji: EAN i rozmiar (atrybuty portalu attr-field-641 i attr-field-2). */
    private const EAN_FIELD = 'attr-field-641';

    private const SIZE_FIELD = 'attr-field-2';

    /** Wiersz normy w tabelce (normShopFieldNames). */
    private const NORM_ROW = 'Norma';

    private const SECTION_MAIN = 'Informacje z portalu Hultafors Group';

    private const SECTION_PRODUCT = 'Opis wyrobu';

    private const SECTION_CERTIFICATION = 'Certyfikacja';

    private const SECTION_TECHNICAL = 'Dane techniczne pozycji';

    /** Dłuższy podpis ikony to objaśnienie materiału (CORDURA, Armortex, OEKO-TEX), nie cecha wyrobu. */
    private const MAX_ICON_LABEL = 100;

    /** Oznaczenie normy na początku etykiety certyfikacji: „EN 14404”, „EN ISO 20471”. */
    private const NORM_DESIGNATION = '/^(?:PN[\s\-]+)?EN(?:\s?ISO)?\s?\d{2,6}(?:-\d{1,3})*(?:\s?:\s?(?:19|20)\d{2}(?!\d))?/u';

    /**
     * Angielskie słowa funkcyjne (jak B2bDescriptionSupplement::looksUntranslated, bez „by”, które jest też polskie) —
     * sekcja bez polskich liter z co najmniej dwoma z nich to tekst nieprzetłumaczony.
     */
    private const ENGLISH_WORDS = '/\b(?:the|and|with|for|of|is|are|from|your|you|this|that|which|can)\b/u';

    private const POLISH_LETTERS = '/[ąćęłńóśźż]/u';

    /** Tytuł pliku zakończony kodem języka innego niż polski i angielski („App Getting Started Guide DE”). */
    private const OTHER_LANGUAGE_TITLE = '/\s(?:DE|DK|DA|SE|SV|NO|NL|FI|FR|IT|ES|CZ|CS)$/u';

    private int $total = 0;

    private int $cards = 0;

    private int $multiPrice = 0;

    /** @var array<string, string> kod artykułu → kod wyrobu, którego karta go wzięła w tym przebiegu */
    private array $claimed = [];

    /** @var array<string, string> kod artykułu z kafelka → kod wyrobu, którego strony nie odczytano w tym przebiegu */
    private array $unread = [];

    /** @var list<string> */
    private array $summary = [];

    /** @var array<string, int> marka → liczba wyrobów listy */
    private array $brands = [];

    /** @var list<string> */
    private array $withoutPrice = [];

    /** @var list<string> */
    private array $unpricedPositions = [];

    /** @var list<string> */
    private array $sharedPositions = [];

    /** @var list<string> */
    private array $foreignText = [];

    /** @var list<string> */
    private array $foreignBrands = [];

    /** @var list<string> */
    private array $withoutDescription = [];

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    public function __construct(
        private readonly HultaforsB2bClient $client,
        private readonly int $pageSize = self::PAGE_SIZE,
    ) {}

    public static function key(): string
    {
        return 'hultafors';
    }

    public static function label(): string
    {
        return self::LABEL;
    }

    public static function host(): string
    {
        return HultaforsB2bClient::HOST;
    }

    public static function ownBrand(): string
    {
        return self::BRAND;
    }

    public static function ownBrands(): array
    {
        return self::GROUP_BRANDS;
    }

    public static function normShopFieldNames(): array
    {
        return [self::NORM_ROW];
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new HultaforsB2bClient((string) $account->username, (string) $account->password, $delayMs));
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
        $this->brands = [];
        $this->withoutPrice = [];
        $this->unpricedPositions = [];
        $this->sharedPositions = [];
        $this->foreignText = [];
        $this->foreignBrands = [];
        $this->withoutDescription = [];
        $this->claimed = [];
        $this->unread = [];
        $this->cards = 0;
        $this->multiPrice = 0;

        if (! $this->client->isLoggedIn()) {
            $this->client->login();
        }

        $rows = $this->listRows();
        if (array_filter($rows, static fn (array $row): bool => $row['priced']) === []) {
            throw new B2bFatalException('Lista '.HultaforsB2bClient::HOST.' bez żadnej ceny konta ('.count($rows).' wyrobów) — sesja konta nie pokazuje cen albo portal zmienił listę; przebieg przerwany');
        }
        usort($rows, static fn (array $a, array $b): int => strnatcasecmp($a['code'], $b['code']));
        $this->total = count($rows);
        foreach ($rows as $row) {
            $this->brands[$row['brand']] = ($this->brands[$row['brand']] ?? 0) + 1;
        }
        arsort($this->brands);
        $this->summary[] = 'Lista Hultafors Group: '.count($rows).' wyrobów ('.implode(', ', array_map(
            static fn (string $brand, int $count): string => ($brand !== '' ? $brand : 'bez marki').' '.$count,
            array_keys($this->brands),
            $this->brands,
        )).')';

        $done = 0;
        foreach ($rows as $row) {
            $product = $this->productFor($row);
            $done++;
            if ($done % self::PROGRESS_EVERY === 0) {
                $this->progress('Wyroby Hultafors Group: '.$done.'/'.count($rows));
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
            'Karty: %d (%d z pozycjami w różnych cenach — cena karty = najniższa, ceny pozycji w tabeli karty)',
            $this->cards,
            $this->multiPrice,
        );
        if ($this->withoutPrice !== []) {
            $lines[] = 'Bez ceny konta (pominięte): '.self::listing($this->withoutPrice);
        }
        if ($this->unpricedPositions !== []) {
            $lines[] = 'Pozycje z ceną 0,00 zł (poza kartą): '.self::listing($this->unpricedPositions);
        }
        if ($this->sharedPositions !== []) {
            $lines[] = 'Pozycje wspólne z wcześniejszą kartą (zostają na niej): '.self::listing($this->sharedPositions);
        }
        if ($this->foreignText !== []) {
            $lines[] = 'Opis z tekstem angielskim (do tłumaczenia): '.self::listing($this->foreignText);
        }
        if ($this->withoutDescription !== []) {
            $lines[] = 'Bez opisu na stronie wyrobu: '.self::listing($this->withoutDescription);
        }
        if ($this->foreignBrands !== []) {
            $lines[] = 'Marki spoza listy marek Hultafors Group (producent dosłownie z portalu): '.self::listing($this->foreignBrands);
        }

        return $lines;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        $brand = trim((string) ($product->raw['brand'] ?? ''));

        // kafelek bez marki (01.10.2026 — żaden): grupa, do której należy portal, nie zgadywana marka
        return $brand !== '' ? $brand : self::LABEL;
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

    public function hasForeignDescription(B2bRemoteProduct $product): bool
    {
        return ($product->raw['foreign'] ?? false) === true;
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
     * Galeria strony wyrobu w kolejności portalu (pierwsze = zdjęcie główne).
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
     * Kwota z portalu („1 267,50 zł”, spacje i twarde spacje tysięcy); null = nie kwota w złotych.
     */
    public static function amount(string $text): ?float
    {
        $text = self::clean($text);
        if (preg_match('/^(\d{1,3}(?:[ \x{202F}]\d{3})*|\d+),(\d{2})\s*zł$/u', $text, $m) !== 1) {
            return null;
        }

        return round((float) (preg_replace('/\D/', '', $m[1]).'.'.$m[2]), 2);
    }

    /**
     * Tekst po angielsku (nieprzetłumaczony w portalu): bez polskich liter i z co najmniej dwoma angielskimi słowami
     * funkcyjnymi. Krótkie nazwy („Noise Protection Level”, „Funkcja ESD”, „GORE-TEX®”) tej reguły nie spełniają.
     */
    public static function isEnglishText(string $text): bool
    {
        $low = mb_strtolower($text);

        return preg_match(self::POLISH_LETTERS, $low) !== 1 && preg_match_all(self::ENGLISH_WORDS, $low) >= 2;
    }

    /**
     * Cała lista wyrobów; niespójna (liczba zmieniła się w trakcie, wyrób na dwóch stronach) — jedno ponowne pobranie.
     *
     * @return list<array{id: string, code: string, brand: string, url: string, priced: bool, range: list<string>}>
     */
    private function listRows(): array
    {
        $navigation = self::navigationFields($this->client->page(self::CATALOG_PAGE));
        if ($navigation === null) {
            throw new RuntimeException('Strona katalogu '.HultaforsB2bClient::HOST.' bez pól nawigacji listy — zmiana portalu?');
        }

        try {
            return $this->scanList($navigation);
        } catch (RuntimeException $e) {
            if ($e->getCode() !== self::INCONSISTENT) {
                throw $e;
            }
            $this->progress('Lista zmieniła się w trakcie pobierania ('.$e->getMessage().') — pobieram od nowa');
        }

        try {
            return $this->scanList($navigation);
        } catch (RuntimeException $e) {
            if ($e->getCode() !== self::INCONSISTENT) {
                throw $e;
            }

            throw new RuntimeException('Lista wyrobów '.HultaforsB2bClient::HOST.' niespójna także po ponownym pobraniu: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @param  array<string, string>  $navigation
     * @return list<array{id: string, code: string, brand: string, url: string, priced: bool, range: list<string>}>
     */
    private function scanList(array $navigation): array
    {
        $rows = [];
        $total = 0;
        $pages = 1;
        for ($page = 1; $page <= $pages; $page++) {
            $html = $this->client->listFragment(array_merge($navigation, [
                'SelectedListTypeModel' => 'Grid',
                'sortBy' => '',
                'PageSize' => $this->pageSize,
                'page' => $page,
            ]));
            $pageTotal = self::hiddenNumber($html, 'total');
            if ($page === 1) {
                $total = $pageTotal ?? 0;
                if ($total <= 0) {
                    throw new RuntimeException('Lista wyrobów '.HultaforsB2bClient::HOST.' pusta albo bez licznika — pusta lista konta albo zmiana portalu');
                }
                $pages = min((int) ceil($total / $this->pageSize), self::MAX_LIST_PAGES);
                $this->progress('Lista wyrobów Hultafors Group: '.$total.' na '.$pages.' stronach');
            } elseif ($pageTotal !== $total) {
                throw new RuntimeException('liczba wyrobów zmieniła się z '.$total.' na '.($pageTotal ?? '?').' (strona '.$page.')', self::INCONSISTENT);
            }
            $tiles = self::listTiles($html);
            if ($tiles === [] && $page === 1) {
                throw new RuntimeException('Strona 1 listy '.HultaforsB2bClient::HOST.' bez wyrobów — zmiana portalu?');
            }
            if ($tiles === []) {
                // lista skurczyła się w trakcie pobierania
                throw new RuntimeException('strona '.$page.' listy bez wyrobów', self::INCONSISTENT);
            }
            foreach ($tiles as $tile) {
                if (isset($rows[$tile['code']])) {
                    throw new RuntimeException('wyrób '.$tile['code'].' na dwóch stronach', self::INCONSISTENT);
                }
                $rows[$tile['code']] = $tile;
            }
        }
        if (count($rows) !== $total) {
            throw new RuntimeException('pobrano '.count($rows).' z '.$total.' wyrobów', self::INCONSISTENT);
        }

        return array_values($rows);
    }

    /**
     * Kafelki listy: id, kod wyrobu, marka, adres strony (może być pusty) i czy kafelek ma cenę konta.
     *
     * @return list<array{id: string, code: string, brand: string, url: string, priced: bool, range: list<string>}>
     */
    private static function listTiles(string $html): array
    {
        $tiles = [];
        // kafelek to <div class="hover-product brandid-1 …"> — wewnątrz są bloki „hover-product-image-wrapper” itp.
        $parts = preg_split('/(?=<div class="hover-product\s)/', $html) ?: [];
        array_shift($parts);
        foreach ($parts as $part) {
            if (preg_match('/^<div class="hover-product\s[^"]*"[^>]*data-productid="([^"]*)"[^>]*data-productstockcode="([^"]*)"/', $part, $m) !== 1) {
                continue;
            }
            $code = self::clean(self::decode($m[2]));
            if ($code === '') {
                continue;
            }
            $brand = preg_match('/<h5 class="product-brandname[^"]*"[^>]*>(.*?)<\/h5>/s', $part, $b) === 1 ? self::text($b[1]) : '';
            $url = preg_match('/<a href="([^"]+)" class="js-product-detail"/', $part, $u) === 1 ? self::decode($u[1]) : '';
            // najtańsza i najdroższa pozycja wyrobu („32501-001|32506-001”) — kody artykułów znane bez strony wyrobu
            $range = preg_match('/id="lowestandhighestvirtualproduct" value="([^"]*)"/', $part, $r) === 1
                ? array_values(array_unique(array_filter(array_map(
                    static fn (string $c): string => self::clean($c),
                    explode('|', self::decode($r[1])),
                ), static fn (string $c): bool => $c !== '')))
                : [];
            $tiles[] = [
                'id' => $m[1],
                'code' => $code,
                'brand' => $brand,
                'url' => $url,
                'priced' => preg_match('/class="product-netprice[^"]*"[^>]*>\s*[^<\s]/', $part) === 1,
                'range' => $range,
            ];
        }

        return $tiles;
    }

    /**
     * Karta wyrobu ze wszystkimi pozycjami z ceną konta; bez nich — pominięty z powodem.
     *
     * @param  array{id: string, code: string, brand: string, url: string, priced: bool, range: list<string>}  $row
     */
    private function productFor(array $row): B2bRemoteProduct
    {
        $code = $row['code'];
        if (B2bManufacturerSiteBrands::matching(self::class, $row['brand']) === null) {
            $this->foreignBrands[] = $code.' ('.($row['brand'] !== '' ? $row['brand'] : 'bez marki').')';
        }
        if ($row['url'] === '') {
            $this->withoutPrice[] = $code;

            return $this->skipped($row, $code, 'kafelek listy bez strony wyrobu');
        }

        try {
            $page = $this->client->page($row['url']);
            $tabs = '';
            $tabsUrl = self::lazyTabsUrl($page);
            if ($tabsUrl !== null) {
                $tabs = $this->client->page($tabsUrl, true);
            }
            $positions = $this->positions($code, $page.$tabs);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            // Pozycje wyrobu ze stroną nieodczytaną w tym przebiegu nie przechodzą na późniejszy wyrób z tymi samymi
            // artykułami (kask Sector biały po żółtym): karta przeskakiwałaby między wersjami przy każdym potknięciu.
            // Znamy tylko najtańszą i najdroższą pozycję z kafelka — wyrób z tymi samymi artykułami ma i te dwie.
            foreach ($row['range'] as $stockCode) {
                $this->unread[$stockCode] ??= $code;
            }

            return $this->skipped($row, $code, 'strona wyrobu: '.$e->getMessage());
        }
        $html = $page.$tabs;
        $title = self::pageTitle($page);
        $baseName = $title !== '' ? $title : $code;
        if ($positions === []) {
            $this->withoutPrice[] = $code;

            return $this->skipped($row, $baseName, 'strona wyrobu bez tabeli pozycji');
        }

        $unread = array_values(array_unique(array_filter(array_map(
            fn (string $stockCode): ?string => $this->unread[$stockCode] ?? null,
            array_map('strval', array_keys($positions)),
        ))));
        if ($unread !== []) {
            // w całości: część pozycji znalazłaby kartę wyrobu nieodczytanego i nadpisała ją tym wyrobem
            return $this->skipped($row, $baseName, 'pozycje wspólne z wyrobem '.implode(', ', $unread).', którego strony nie udało się odczytać w tym przebiegu');
        }

        $colours = self::matrixColours($html);
        $priced = [];
        foreach ($positions as $stockCode => $position) {
            $stockCode = (string) $stockCode;
            if ($position['net'] === null) {
                $this->unpricedPositions[] = $code.' '.$stockCode;

                continue;
            }
            if (isset($this->claimed[$stockCode])) {
                $this->sharedPositions[] = $code.' '.$stockCode.' (karta '.$this->claimed[$stockCode].')';

                continue;
            }
            $priced[$stockCode] = $position + ['colour' => $colours[$stockCode] ?? ''];
        }
        if ($priced === []) {
            $owners = array_values(array_unique(array_filter(array_map(
                fn (string $stockCode): ?string => $this->claimed[$stockCode] ?? null,
                array_map('strval', array_keys($positions)),
            ))));
            if ($owners !== []) {
                return $this->skipped($row, $baseName, 'te same pozycje co karta '.implode(', ', $owners));
            }
            $this->withoutPrice[] = $code;

            return $this->skipped($row, $baseName, 'żadna pozycja wyrobu nie ma ceny konta');
        }
        foreach (array_keys($priced) as $stockCode) {
            $this->claimed[$stockCode] = $code;
        }

        $colourNames = array_values(array_unique(array_filter(array_column($priced, 'colour'))));
        $manyColours = count($colourNames) > 1;
        $members = [];
        $identifiers = [new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_SOURCE_CODE, value: $code, field: 'Kod wyrobu')];
        $eans = [];
        $sizes = [];
        $statuses = [];
        $cheapest = null;
        $labels = [];
        foreach ($priced as $stockCode => $position) {
            $labels[$stockCode] = self::positionLabel($position, $manyColours);
        }
        $repeated = array_filter(array_count_values($labels), static fn (int $count): bool => $count > 1);
        foreach ($priced as $stockCode => $position) {
            // kod z samych cyfr jest w PHP kluczem liczbowym tablicy — do pozycji karty wraca jako tekst
            $stockCode = (string) $stockCode;
            // ta sama etykieta dwóch pozycji (np. rozmiar dwóch kolorów bez matrycy) — z kodem artykułu
            $label = isset($repeated[$labels[$stockCode]]) ? $labels[$stockCode].' ('.$stockCode.')' : $labels[$stockCode];
            $price = new B2bRemotePrice(
                net: $position['net'],
                base: $position['gross'],
                discountPercent: $position['discount'] ?? 0.0,
                currency: 'PLN',
            );
            $name = $position['name'] !== '' ? $position['name'] : $baseName.' '.$stockCode;
            if ($manyColours && $position['colour'] !== '' && ! str_contains($name, $position['colour'])) {
                $name .= ', '.$position['colour'];
            }
            $members[] = [
                'remote_id' => $stockCode,
                'sku' => $stockCode,
                'name' => $name,
                'availability' => $position['availability'],
                'size' => $label,
                'price' => $price,
            ];
            $statuses[$position['availability']][] = $label;
            if ($position['ean'] !== '' && ! isset($eans[$position['ean']])) {
                $eans[$position['ean']] = true;
                $identifiers[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_EAN, value: $position['ean'], remoteId: $stockCode, label: $label, field: 'EAN');
            }
            if ($position['size'] !== '') {
                $sizes[$position['size']] = true;
            }
            if ($cheapest === null || $price->net < $cheapest->net) {
                $cheapest = $price;
            }
        }
        $this->cards++;
        $nets = array_map(static fn (array $m): string => (string) $m['price']->net, $members);
        $this->multiPrice += count(array_unique($nets)) > 1 ? 1 : 0;

        $content = self::pageContent($page);
        $description = mb_substr(implode("\n", $content['description']), 0, 10000);
        if ($description === '') {
            $this->withoutDescription[] = $code;
        }
        $foreign = false;
        foreach ($content['description'] as $text) {
            if (self::isEnglishText($text)) {
                $foreign = true;
                $this->foreignText[] = $code;
                break;
            }
        }
        $category = self::category($page);

        return new B2bRemoteProduct(
            remoteId: $members[0]['remote_id'],
            sku: $code,
            name: $baseName,
            category: $category,
            sourceUrl: HultaforsB2bClient::urlFor($row['url']),
            raw: [
                'status' => 'ok',
                // cena karty = najniższa cena pozycji (B2bCatalogSync liczy ją też z members[].price)
                'price' => $cheapest,
                'brand' => $row['brand'],
                'description' => $description,
                'foreign' => $foreign,
                'fields' => self::fields($row, $category, $content, self::certification($html), $priced, $manyColours),
                'documents' => self::documentList($html),
                'images' => self::gallery($page),
            ],
            availability: self::groupAvailability($statuses),
            variantSummary: self::variantSummary($priced, $colourNames, array_keys($sizes)),
            members: $members,
            identifiers: $identifiers,
            // nazwa z tekstem angielskim zostaje dosłowna — tłumaczenie nazwy nowej karty działa tylko, gdy nazwa karty
            // to nazwa ze źródła (TranslateB2bProductTextJob::pending)
            cardName: $foreign ? null : self::cardName($baseName, $row['brand'], $code),
        );
    }

    /**
     * Pozycje wyrobu z jego sekcji listy „4 Virtual” (listvalue = kod wyrobu): wiersze z HTML, gdy są wszystkie,
     * inaczej z ProductUpdateList stronami po 100. Wiersze kilku tabel tego samego wyrobu (układ Hellberg/Hultafors:
     * kolumny techniczne i EAN w osobnych tabelach) łączone po kodzie artykułu.
     *
     * @return array<string, array{name: string, ean: string, size: string, attributes: array<string, string>, availability: string, net: float|null, gross: float|null, discount: float|null}>
     */
    private function positions(string $code, string $html): array
    {
        $positions = [];
        foreach (self::relationSections($html) as ['navigation' => $navigation, 'html' => $section]) {
            if (($navigation['RelationType'] ?? '') !== self::VIRTUAL_RELATION || ($navigation['listvalue'] ?? '') !== $code) {
                continue;
            }
            $total = (int) ($navigation['total'] ?? 0);
            $table = self::tableRows($section);
            if ($total <= 0 || count($table['rows']) < $total) {
                $table = $this->fetchTable($navigation, $code);
            }
            foreach ($table['rows'] as $stockCode => $row) {
                if (! isset($positions[$stockCode])) {
                    $positions[$stockCode] = $row;

                    continue;
                }
                $known = $positions[$stockCode];
                $known['ean'] = $known['ean'] !== '' ? $known['ean'] : $row['ean'];
                $known['size'] = $known['size'] !== '' ? $known['size'] : $row['size'];
                $known['attributes'] += $row['attributes'];
                $positions[$stockCode] = $known;
            }
        }

        return $positions;
    }

    /**
     * Tabela pozycji z ProductUpdateList, wszystkie strony; liczba wierszy różna od licznika tabeli = błąd (karta bez
     * części rozmiarów zmieniłaby tabelę i cenę).
     *
     * @param  array<string, string>  $navigation
     * @return array{rows: array<string, array<string, mixed>>}
     */
    private function fetchTable(array $navigation, string $code): array
    {
        $rows = [];
        $pages = 1;
        $total = null;
        for ($page = 1; $page <= $pages; $page++) {
            $html = $this->client->listFragment(array_merge($navigation, [
                'SelectedListTypeModel' => 'Table',
                'sortBy' => '',
                'PageSize' => self::PAGE_SIZE,
                'page' => $page,
            ]));
            if ($page === 1) {
                $total = self::hiddenNumber($html, 'total');
                $pages = min(max(1, self::hiddenNumber($html, 'TotalPages') ?? 1), self::MAX_TABLE_PAGES);
            }
            $rows += self::tableRows($html)['rows'];
        }
        if ($total === null || count($rows) !== $total) {
            throw new RuntimeException('tabela pozycji '.$code.' niepełna ('.count($rows).' z '.($total ?? '?').')');
        }

        return ['rows' => $rows];
    }

    /**
     * Sekcje list wyrobów (section_ProductRelationList) z polami nawigacji; HTML sekcji do następnej sekcji strony.
     *
     * @return list<array{navigation: array<string, string>, html: string}>
     */
    private static function relationSections(string $html): array
    {
        $out = [];
        $parts = preg_split('/(?=<section id="section_\d+" class="section )/', $html) ?: [];
        foreach ($parts as $part) {
            if (! str_starts_with($part, '<section id="section_') || ! str_contains(substr($part, 0, 300), 'section_ProductRelationList')) {
                continue;
            }
            // wiersze tabeli leżą w zagnieżdżonej <section class="products"> — do następnej sekcji strony
            $navigation = self::navigationFields($part);
            if ($navigation !== null) {
                $out[] = ['navigation' => $navigation, 'html' => $part];
            }
        }

        return $out;
    }

    /**
     * Ukryte pola nawigacji listy (.js-productlist_navigation_data) — pierwsze w podanym HTML; null = brak.
     *
     * @return array<string, string>|null
     */
    private static function navigationFields(string $html): ?array
    {
        $start = strpos($html, 'js-productlist_navigation_data');
        if ($start === false) {
            return null;
        }
        $end = strpos($html, '</div>', $start);
        $block = substr($html, $start, $end === false ? 3000 : $end - $start);
        preg_match_all('/<input type="hidden" name="([A-Za-z]+)"[^>]*?value="([^"]*)"/', $block, $m, PREG_SET_ORDER);
        $fields = [];
        foreach ($m as $input) {
            $fields[$input[1]] ??= self::decode($input[2]);
        }

        return $fields !== [] ? $fields : null;
    }

    /**
     * Wiersze tabeli pozycji: kod artykułu, opis, EAN, rozmiar, kolumny atrybutów (etykieta z nagłówka), dostępność,
     * cena brutto, rabat i cena netto. Cena 0,00 zł = null (bez ceny konta).
     *
     * @return array{rows: array<string, array{name: string, ean: string, size: string, attributes: array<string, string>, availability: string, net: float|null, gross: float|null, discount: float|null}>}
     */
    private static function tableRows(string $html): array
    {
        $headers = [];
        if (preg_match('/<thead>(.*?)<\/thead>/s', $html, $head) === 1) {
            preg_match_all('/<th\s+class="[^"]*\b(attr-field-\d+)\b[^"]*"[^>]*>(.*?)<\/th>/s', $head[1], $th, PREG_SET_ORDER);
            foreach ($th as $cell) {
                $headers[$cell[1]] = self::text($cell[2]);
            }
        }

        $rows = [];
        // „<tr” i „class=” bywają w osobnych liniach (tabele w stronie wyrobu Hellberg i Hultafors)
        $parts = preg_split('/(?=<tr\s+class="productlistrow\b)/', $html) ?: [];
        array_shift($parts);
        foreach ($parts as $part) {
            $end = strpos($part, '</tr>');
            $tr = $end === false ? $part : substr($part, 0, $end);
            if (preg_match('/data-productstockcode="([^"]+)"/', $tr, $sc) !== 1) {
                continue;
            }
            $stockCode = self::clean(self::decode($sc[1]));
            $cells = [];
            preg_match_all('/<td\s+class="([^"]*)"[^>]*>(.*?)<\/td>/s', $tr, $td, PREG_SET_ORDER);
            foreach ($td as $cell) {
                $cells[] = ['class' => $cell[1], 'html' => $cell[2]];
            }
            $name = '';
            $ean = '';
            $size = '';
            $attributes = [];
            $available = null;
            $date = '';
            foreach ($cells as $cell) {
                $class = $cell['class'];
                if (str_contains($class, 'field-desc')) {
                    $name = self::text($cell['html']);
                } elseif (preg_match('/\b(attr-field-\d+)\b/', $class, $af) === 1) {
                    $value = self::text($cell['html']);
                    if ($af[1] === self::EAN_FIELD) {
                        $ean = $value;
                    } elseif ($af[1] === self::SIZE_FIELD) {
                        $size = $value;
                    } elseif ($value !== '' && isset($headers[$af[1]]) && $headers[$af[1]] !== '') {
                        $attributes[$headers[$af[1]]] = $value;
                    }
                } elseif (str_contains($class, 'field-avail')) {
                    if (preg_match('/<span class="fa[^"]*" title="([^"]*)"/', $cell['html'], $av) === 1) {
                        $available = mb_strtolower(self::clean(self::decode($av[1]))) === 'tak';
                    }
                } elseif (str_contains($class, 'field-deldate')) {
                    $date = self::text($cell['html']);
                }
            }
            $net = preg_match('/class="product-netprice[^"]*"[^>]*>(.*?)<span class="price-unit-code"/s', $tr, $n) === 1
                || preg_match('/class="product-netprice[^"]*"[^>]*>(.*?)<\/h4>/s', $tr, $n) === 1
                ? self::amount(self::text($n[1])) : null;
            $gross = preg_match('/class="product-grossprice[^"]*"[^>]*>(.*?)<\/p>/s', $tr, $g) === 1 ? self::amount(self::text($g[1])) : null;
            $discount = preg_match('/class="product-discount-percentage">\s*([\d\s,]+)%/', $tr, $d) === 1
                ? (float) str_replace([' ', ','], ['', '.'], trim($d[1])) : null;

            $rows[$stockCode] = [
                'name' => $name,
                'ean' => preg_match('/^\d{8,14}$/', $ean) === 1 ? $ean : '',
                'size' => $size,
                'attributes' => $attributes,
                'availability' => self::availability($available, $date),
                'net' => $net !== null && $net > 0 ? $net : null,
                'gross' => $gross !== null && $gross > 0 ? $gross : null,
                'discount' => $discount,
            ];
        }

        return ['rows' => $rows];
    }

    /** „Na stanie” albo „Brak na stanie, dostawa 26.11.2026” (data dostawy z tabeli portalu). */
    private static function availability(?bool $available, string $date): string
    {
        if ($available === true) {
            return 'Na stanie';
        }
        $date = preg_match('/^\d{2}\.\d{2}\.\d{4}$/', $date) === 1 ? $date : '';

        return $available === false
            ? 'Brak na stanie'.($date !== '' ? ', dostawa '.$date : '')
            : ($date !== '' ? 'Dostawa '.$date : 'Brak informacji o stanie');
    }

    /**
     * Kolor artykułu z matrycy kolor × rozmiar (Basket/_AddToBasket_Matrix): kolumny kolorów z nagłówka, w komórkach
     * kod artykułu. [] = wyrób bez matrycy.
     *
     * @return array<string, string>
     */
    private static function matrixColours(string $html): array
    {
        $start = strpos($html, 'add-to-basket-matrix-table');
        if ($start === false) {
            return [];
        }
        $end = strpos($html, '</table>', $start);
        $table = substr($html, $start, $end === false ? strlen($html) - $start : $end - $start);
        $rows = preg_split('/<tr\b/', $table) ?: [];
        array_shift($rows);
        if ($rows === []) {
            return [];
        }
        preg_match_all('/<th\b[^>]*>\s*<img title="([^"]*)"/', $rows[0], $heads);
        $colours = array_map(static fn (string $title): string => self::clean(self::decode($title)), $heads[1]);
        if ($colours === []) {
            return [];
        }
        $map = [];
        foreach (array_slice($rows, 1) as $row) {
            preg_match_all('/<td\b.*?<\/td>/s', $row, $cells);
            foreach ($cells[0] as $index => $cell) {
                if (isset($colours[$index]) && preg_match('/name="Products\[\d+\]\.StockCode" value="([^"]+)"/', $cell, $sc) === 1) {
                    $map[self::clean(self::decode($sc[1]))] = $colours[$index];
                }
            }
        }

        return $map;
    }

    /**
     * Etykieta pozycji na karcie: rozmiar (z kolorem, gdy karta ma kilka kolorów), bez rozmiaru — kolor, bez obu —
     * opis pozycji z tabeli portalu.
     *
     * @param  array{name: string, size: string, colour: string}  $position
     */
    private static function positionLabel(array $position, bool $manyColours): string
    {
        if ($position['size'] !== '') {
            return $manyColours && $position['colour'] !== '' ? $position['colour'].' / '.$position['size'] : $position['size'];
        }
        if ($position['colour'] !== '') {
            return $position['colour'];
        }

        return $position['name'];
    }

    /**
     * @param  array<string, array<string, mixed>>  $priced
     * @param  list<string>  $colours
     * @param  list<string|int>  $sizes
     */
    private static function variantSummary(array $priced, array $colours, array $sizes): string
    {
        $sizes = array_map('strval', $sizes);
        $parts = [];
        if (count($colours) > 1) {
            $parts[] = 'Kolory: '.implode(', ', $colours);
        } elseif ($colours !== []) {
            $parts[] = 'Kolor: '.$colours[0];
        }
        if ($sizes !== []) {
            $parts[] = ($parts === [] ? 'Rozmiary: ' : 'rozmiary: ').implode(', ', $sizes);
        }
        if ($parts === [] && count($priced) > 1) {
            $parts[] = 'Warianty: '.implode(', ', array_map(static fn (array $p): string => (string) $p['name'], $priced));
        }

        return mb_substr(implode('; ', $parts), 0, 2000);
    }

    /** Adres zakładek wyrobu ładowanych przez skrypt strony (układ Snickers); null = zakładki są w stronie. */
    private static function lazyTabsUrl(string $page): ?string
    {
        if (preg_match('/data-sectionurl=["\']?([^"\'\s>]+)["\']?[^>]*data-action="ProductDetail\/TabsSection"/', $page, $m) !== 1) {
            return null;
        }

        return self::decode($m[1]);
    }

    private static function pageTitle(string $page): string
    {
        return preg_match('/<h1 class="page-header">(.*?)<\/h1>/s', $page, $m) === 1 ? self::text($m[1]) : '';
    }

    /** Okruszki bez ostatniego (nazwa wyrobu) i bez powtórzeń sąsiednich: „Snickers Workwear > Spodnie”. */
    private static function category(string $page): ?string
    {
        if (preg_match('/<ol class="breadcrumb">(.*?)<\/ol>/s', $page, $m) !== 1) {
            return null;
        }
        preg_match_all('/<li\b[^>]*>(.*?)<\/li>/s', $m[1], $items);
        $parts = array_map(static fn (string $item): string => self::text($item), $items[1]);
        array_pop($parts);
        $out = [];
        foreach ($parts as $part) {
            if ($part !== '' && end($out) !== $part) {
                $out[] = $part;
            }
        }

        return $out !== [] ? implode(' > ', $out) : null;
    }

    /**
     * Treść strony wyrobu w kolejności sekcji: opis (sekcje opisowe przed pierwszym nagłówkiem), sekcje pod nagłówkami
     * („Rozmiar”, „Material”) i podpisy ikon. Sekcje treści portalu (uwagi o rozmiarach specjalnych, odnośniki) pomijane.
     *
     * @return array{description: list<string>, headed: list<array{name: string, value: string}>, icons: list<string>}
     */
    private static function pageContent(string $page): array
    {
        $description = [];
        $headed = [];
        $icons = [];
        $header = null;
        $parts = preg_split('/(?=<section id="section_\d+" class="section )/', $page) ?: [];
        foreach ($parts as $part) {
            if (preg_match('/^<section id="section_\d+" class="section (section_\w+)([^"]*)"[^>]*data-view="([^"]*)"/', $part, $m) !== 1) {
                continue;
            }
            [$type, $classes, $view] = [$m[1], $m[2], $m[3]];
            $end = strpos($part, '</section>');
            $inner = $end === false ? $part : substr($part, 0, $end);
            if ($type === 'section_TranslationText' && str_contains($classes, 'pdp-description-header')) {
                $header = self::text($inner);

                continue;
            }
            if ($type === 'section_ProductDetail_Description') {
                $text = self::sectionText($inner);
                if ($text === '') {
                    continue;
                }
                if ($header === null || $header === '') {
                    if (! in_array($text, $description, true)) {
                        $description[] = $text;
                    }
                } else {
                    $headed[] = ['name' => $header, 'value' => $text];
                }

                continue;
            }
            if ($type === 'section_ProductAttributes' && str_contains($view, '_Images')) {
                preg_match_all('/data-original-title="([^"]*)"/', $inner, $titles);
                foreach ($titles[1] as $title) {
                    $label = self::clean(self::decode($title));
                    if ($label !== '' && mb_strlen($label) <= self::MAX_ICON_LABEL && ! in_array($label, $icons, true)) {
                        $icons[] = $label;
                    }
                }
            }
        }

        return ['description' => $description, 'headed' => $headed, 'icons' => $icons];
    }

    /** Tekst sekcji opisu: pełny (#textlong), gdy portal go podaje, inaczej skrót (#textshort); bez stylów. */
    private static function sectionText(string $html): string
    {
        $html = (string) preg_replace('/<style\b.*?<\/style>/s', '', $html);
        $long = preg_match('/id="textlong\d+"\s*>(.*?)<\/div>/s', $html, $l) === 1 ? self::htmlText($l[1]) : '';
        if ($long !== '') {
            return $long;
        }

        return preg_match('/<div id="textshort\d+">(.*?)<\/div>/s', $html, $s) === 1 ? self::htmlText($s[1]) : '';
    }

    /**
     * Wiersze certyfikacji (listy dt/dd na stronie i w zakładkach) bez „Launch Season”.
     *
     * @return list<array{name: string, value: string}>
     */
    private static function certification(string $html): array
    {
        $rows = [];
        preg_match_all('/<dl class="dl-horizontal">(.*?)<\/dl>/s', $html, $lists);
        foreach ($lists[1] as $list) {
            preg_match_all('/<dt>(.*?)<\/dt>\s*<dd>(.*?)<\/dd>/s', $list, $pairs, PREG_SET_ORDER);
            foreach ($pairs as $pair) {
                $name = rtrim(self::text($pair[1]), ' :');
                $value = self::text($pair[2]);
                if ($name === '' || $value === '' || stripos($name, 'launch season') !== false) {
                    continue;
                }
                $row = ['name' => $name, 'value' => $value];
                if (! in_array($row, $rows, true)) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Tabelka karty: dane z listy, sekcje pod nagłówkami, oznaczenia z ikon, certyfikacja (z wierszem „Norma” na każdą
     * normę EN: oznaczenie z etykiety i wartość dosłownie), kolumny techniczne tabeli pozycji (bez danych
     * transportowych): wartość wspólna wszystkich pozycji — raz, różne — „etykieta pozycji: wartość” po średniku.
     *
     * @param  array{id: string, code: string, brand: string, url: string, priced: bool, range: list<string>}  $row
     * @param  array{description: list<string>, headed: list<array{name: string, value: string}>, icons: list<string>}  $content
     * @param  list<array{name: string, value: string}>  $certification
     * @param  array<string, array<string, mixed>>  $priced
     * @return list<array{section: string, name: string, value: string}>
     */
    private static function fields(array $row, ?string $category, array $content, array $certification, array $priced, bool $manyColours): array
    {
        $fields = [];
        $add = static function (string $section, string $name, string $value) use (&$fields): void {
            $name = self::clean($name);
            $value = trim($value);
            $field = ['section' => $section, 'name' => $name, 'value' => $value];
            if ($name !== '' && $value !== '' && ! in_array($field, $fields, true)) {
                $fields[] = $field;
            }
        };

        $add(self::SECTION_MAIN, 'Marka', $row['brand']);
        $add(self::SECTION_MAIN, 'Kod wyrobu', $row['code']);
        $add(self::SECTION_MAIN, 'Kategoria', (string) $category);
        foreach ($content['headed'] as $headed) {
            $add(self::SECTION_PRODUCT, $headed['name'], $headed['value']);
        }
        $add(self::SECTION_PRODUCT, 'Oznaczenia', implode('; ', $content['icons']));
        foreach ($certification as $cert) {
            if (preg_match(self::NORM_DESIGNATION, $cert['name'], $norm) === 1) {
                $add(self::SECTION_CERTIFICATION, self::NORM_ROW, self::clean($norm[0]).' '.$cert['value']);
            }
            $add(self::SECTION_CERTIFICATION, $cert['name'], $cert['value']);
        }

        $columns = [];
        foreach ($priced as $position) {
            foreach ($position['attributes'] as $label => $value) {
                if (stripos($label, 'transport') === false) {
                    $columns[$label][self::positionLabel($position, $manyColours)] = $value;
                }
            }
        }
        foreach ($columns as $label => $values) {
            $distinct = array_values(array_unique($values));
            if (count($distinct) === 1 && count($values) === count($priced)) {
                $add(self::SECTION_TECHNICAL, $label, $distinct[0]);

                continue;
            }
            $add(self::SECTION_TECHNICAL, $label, mb_substr(implode('; ', array_map(
                static fn (string $position, string $value): string => $position.': '.$value,
                array_keys($values),
                $values,
            )), 0, 2000));
        }

        return $fields;
    }

    /**
     * Pliki wyrobu z tabeli dokumentów i tabela rozmiarów ze strony. Szablon znakowania („profiling”) i deklaracje
     * UKCA (rynek brytyjski) pomijane. Rodzaj z tytułu albo nazwy pliku.
     *
     * @return list<array{title: string, url: string, kind: string}>
     */
    private static function documentList(string $html): array
    {
        $files = [];
        $add = static function (string $title, string $href, ?string $kind = null) use (&$files): void {
            $href = self::decode($href);
            if (! str_starts_with($href, '/Image/GetDocument/')) {
                return;
            }
            $url = HultaforsB2bClient::BASE.$href;
            $name = rawurldecode((string) basename((string) parse_url($href, PHP_URL_PATH)));
            $title = $title !== '' ? $title : $name;
            $low = mb_strtolower($title.' '.$name);
            // szablon znakowania, deklaracje rynku brytyjskiego i wersje językowe inne niż polska i angielska
            // („App Getting Started Guide DE”)
            if (str_contains($low, 'profiling') || str_contains($low, 'ukca') || preg_match(self::OTHER_LANGUAGE_TITLE, $title) === 1) {
                return;
            }
            $kind ??= match (true) {
                str_contains($low, 'declaration of conformity') || str_contains($low, 'deklaracja zgodno')
                    || str_contains($low, 'certificate') || str_contains($low, 'certyfikat') || str_contains($low, 'zertifikat')
                    || preg_match('/\bsgs\b/u', $low) === 1 => ProductDocument::KIND_CERTIFICATE,
                str_contains($low, 'product sheet') || str_contains($low, 'data sheet') || str_contains($low, 'datasheet')
                    || str_contains($low, 'karta produktu') || str_contains($low, 'karta techniczna') => ProductDocument::KIND_DATASHEET,
                str_contains($low, 'manual') || str_contains($low, 'instrukcja') => ProductDocument::KIND_MANUAL,
                str_contains($low, 'size guide') || str_contains($low, 'size chart') || str_contains($low, 'rozmiar') => ProductDocument::KIND_SIZE_CHART,
                default => ProductDocument::KIND_OTHER,
            };
            $files[$url] ??= ['title' => $title, 'url' => $url, 'kind' => $kind];
        };

        preg_match_all('/<table class="document-table">(.*?)<\/table>/s', $html, $tables);
        foreach ($tables[1] as $table) {
            preg_match_all('/<tr>(.*?)<\/tr>/s', $table, $trs);
            foreach ($trs[1] as $tr) {
                if (preg_match('/<a class="document[^"]*"[^>]*href="([^"]+)"/', $tr, $a) !== 1) {
                    continue;
                }
                preg_match_all('/<td\b[^>]*>(.*?)<\/td>/s', $tr, $tds);
                $title = '';
                foreach ($tds[1] as $td) {
                    if (! str_contains($td, '<a ') && ! str_contains($td, '<i ')) {
                        $title = self::text($td);
                        break;
                    }
                }
                $add($title, $a[1]);
            }
        }
        if (preg_match_all('/<a href="(\/Image\/GetDocument\/[^"]+)"[^>]*data-type="document"[^>]*>(.*?)<\/a>/s', $html, $links, PREG_SET_ORDER) > 0) {
            foreach ($links as $link) {
                if (str_contains(mb_strtolower(self::text($link[2])), 'rozmiar')) {
                    $add('Tabela rozmiarów', $link[1], ProductDocument::KIND_SIZE_CHART);
                }
            }
        }

        return array_values($files);
    }

    /**
     * Zdjęcia galerii strony (pełny rozmiar: adres odnośnika miniatury, bez szerokości i wysokości).
     *
     * @return list<string>
     */
    private static function gallery(string $page): array
    {
        $start = strpos($page, 'slider-for main');
        if ($start === false) {
            return [];
        }
        $end = strpos($page, 'download-product-image', $start);
        $block = substr($page, $start, $end === false ? 20000 : $end - $start);
        preg_match_all('/<a href="(\/pl\/image\/getthumbnail\/\d+[^"]*)"/', $block, $m);
        $urls = [];
        foreach ($m[1] as $href) {
            $url = HultaforsB2bClient::BASE.self::decode($href);
            if (! in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Nazwa nowej karty: nazwa ze strony, marka (gdy nazwa jej nie zawiera) i kod wyrobu (gdy nazwa go nie zawiera):
     * „RuffWork, Spodnie z workami kieszeniowymi Snickers Workwear 6220”, „SOLID GEAR REVOLUTION 2 GTX SG76010”.
     */
    private static function cardName(string $title, string $brand, string $code): string
    {
        $parts = [$title];
        // „Solid Gear” jest w „SOLID GEAR REVOLUTION 2 GTX” po pierwszym słowie; krótkie („W.steps”) — w całości
        $key = mb_strtolower(explode(' ', $brand)[0]);
        if (mb_strlen($key) < 3) {
            $key = mb_strtolower($brand);
        }
        if ($brand !== '' && ! str_contains(mb_strtolower($title), $key)) {
            $parts[] = $brand;
        }
        if (! str_contains(self::squashed($title), self::squashed($code))) {
            $parts[] = $code;
        }

        return self::clean(implode(' ', $parts));
    }

    /**
     * Jeden stan dla wszystkich pozycji — dosłownie; różne — „Na stanie: 44, 46; Brak na stanie, dostawa 26.11.2026: 40*”.
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
     * @param  array{id: string, code: string, brand: string, url: string, priced: bool, range: list<string>}  $row
     */
    private function skipped(array $row, string $name, string $reason): B2bRemoteProduct
    {
        return new B2bRemoteProduct(
            remoteId: $row['code'],
            sku: $row['code'],
            name: $name !== '' ? $name : $row['code'],
            sourceUrl: $row['url'] !== '' ? HultaforsB2bClient::urlFor($row['url']) : null,
            raw: ['status' => 'skipped', 'reason' => $reason, 'brand' => $row['brand']],
        );
    }

    private static function hiddenNumber(string $html, string $name): ?int
    {
        return preg_match('/<input type="hidden" name="'.preg_quote($name, '/').'"[^>]*value="(-?\d+)"/', $html, $m) === 1 ? (int) $m[1] : null;
    }

    /** Tekst HTML portalu jako tekst: akapity i podziały w osobnych liniach, encje rozwinięte, odstępy zwinięte. */
    private static function htmlText(string $html): string
    {
        $html = (string) preg_replace('#<\s*br\s*/?>|</\s*(?:p|div|li|h[1-6]|tr)\s*>#iu', "\n", $html);
        $lines = [];
        foreach (preg_split('/\R/u', self::decode(strip_tags($html))) ?: [] as $line) {
            $line = self::clean($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /** Tekst komórki albo nagłówka w jednej linii. */
    private static function text(string $html): string
    {
        return self::clean(self::decode(strip_tags($html)));
    }

    private static function decode(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
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

    /** Tekst z portalu: bez znaków zerowej szerokości, odstępy (także twarde spacje) zwinięte. */
    private static function clean(string $text): string
    {
        $text = str_replace(["\u{200B}", "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{FEFF}"], '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));
    }
}
