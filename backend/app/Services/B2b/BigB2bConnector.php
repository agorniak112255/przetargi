<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use App\Support\BrandKey;
use App\Support\XlsxStreamReader;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

/**
 * www.big-arbeitsschutz.de — sklep B2B BIG Arbeitsschutz GmbH (marki własne teXXor®, 4PROTECT®, RUNNEX®; w cenniku
 * także rękawice TOWA® i noże Klever®/Pacific Handy Cutter® pod marką handlową SPG®). Sprawdzone na zalogowanym koncie
 * #34 01.10.2026 (BigB2bClient opisuje logowanie i adresy).
 *
 * Lista i ceny: cennik konta XLSX z „Ihre Preislisten” (Preisliste_RRRRMMDD, ok. 5,9 tys. wierszy: artykuł × rozmiar;
 * 701 artykułów). Wyroby sklepu spoza cennika konta (ATG, ADIDAS PRO WORK…) nie mają ceny konta i nie trafiają do
 * katalogu. Kolumny: Preis = cena przy pełnej jednostce wysyłkowej (VE_Menge, karton), Anbruchpreis = cena napoczętego
 * kartonu — sklep pokazuje je jako progi „ab 12 Paar 1,15 €, ab 96 Paar 0,92 €” (netto, „zzgl. MwSt.”, EUR).
 * Cena konta = Anbruchpreis: obowiązuje od minimum zamówienia (Mindestmenge = „Abnahmeeinheit”), czyli przy każdej
 * ilości, którą wolno zamówić; cena pełnego kartonu idzie do tabelki (jak progi JHK). Basispreis > 0 = cena cennikowa
 * przed rabatem konta (4PROTECT, RUNNEX: 63,00 → 50,40). Minimum zamówienia jest też krokiem („Die Bestellmenge muss
 * ein Vielfaches von 12 Paar sein”). Pozycja z ceną 0,00 € („auf Anfrage”) nie trafia na kartę.
 *
 * Karta = artykuł (SKU = numer artykułu), pozycje (members) = rozmiary z ceną (remote_id „1102/10”; artykuł bez
 * rozmiaru — sam numer). Noże Klever mają kolor w kolumnie rozmiaru — etykieta pozycji dosłownie z kolumny.
 *
 * Teksty w cenniku są ucięte na 255 znakach (połowa opisów), a normy nie mają poziomów — dlatego dla każdego artykułu
 * czytamy stronę wyrobu /item-1-{numer}.html: pełny opis (product-description), cechy („Eigenschaften”), zastosowania
 * („Einsatzgebiete”), normy z poziomami (bloki plg_big_normen-norm: oznaczenie + dopisek, np. „3143XX”), pary
 * Material/Eigenschaften/Stammdaten i pliki do pobrania. 94 artykuły cennika nie mają strony (404) — ich karta powstaje
 * z samego cennika: pole ucięte na 255 znakach przycinamy do ostatniego pełnego zdania albo pozycji listy (nie
 * zostawiamy uciętego słowa), normy bez poziomów. Strona, której nie udało się odczytać (błąd sieci), wstrzymuje
 * artykuł do następnego przebiegu — inaczej opis i tabelka spadłyby do uciętych danych z cennika.
 *
 * Język: sklep jest tylko po niemiecku (B2bForeignLanguageSource) — opis i nazwa idą po zapisie do tłumaczenia;
 * łącznik oddaje tekst dosłownie. Nazwa także karty już w katalogu, dopóki jest nazwą ze źródła (B2bKeepsExistingNames,
 * 05.10.2026) — inaczej nazwa nieprzetłumaczona przy pierwszym przebiegu zostawała po niemiecku na zawsze (05.10.2026:
 * 652 z 701 kart, np. „teXXor® Rindkernspaltleder-Handschuhe TAUNUS”). Producent = marka z cennika bez ®/™; SPG to marka handlowa BIG, nie
 * producent — dla niej producentem jest marka z nazwy (Klever, Pacific Handy Cutter). Witryna producenta marek teXXor,
 * 4PROTECT i RUNNEX (B2bManufacturerSite + B2bManufacturerBrands); TOWA i noże — BIG jest dla nich dystrybutorem.
 *
 * Pliki: wersja PL (karta danych technicznych, informacje producenta, deklaracja), a typ pliku bez wersji PL — wersja
 * DE; pozostałe języki i wzory etykiet pomijane. Zdjęcia: Bild_1…Bild_6 z cennika (duże, publiczne).
 */
final class BigB2bConnector implements B2bConnector, B2bDocumentSource, B2bForeignLanguageSource, B2bGroupsSizes, B2bImageGallery, B2bKeepsExistingNames, B2bListProgressAware, B2bManufacturerBrands, B2bManufacturerSite, B2bRunSummaryAware, B2bShopFieldNormSource, B2bShopFieldSource, B2bSizePriceSource
{
    public const BRAND = 'teXXor';

    /** Pozostałe marki własne BIG (marka główna — BRAND). */
    private const OWN_BRANDS = ['4PROTECT', 'RUNNEX'];

    /** Marka handlowa BIG dla cudzych noży — producent z nazwy wyrobu. */
    private const TRADE_BRAND = 'spg';

    private const SECTION = 'Informacje ze sklepu BIG';

    private const NORM_FIELD = 'Norma';

    public const MIN_DELAY_MS = 1000;

    /**
     * Cennik krótszy niż tyle artykułów albo wierszy = ucięty plik albo zmiana konta (01.10.2026: 701 artykułów, 5928
     * wierszy) — przebieg przerwany, inaczej sprzątanie rozmiarów oznaczyłoby brakujące pozycje jako usunięte.
     */
    public const MIN_ARTICLES = 500;

    public const MIN_ROWS = 4000;

    /** Strażnik adresów stron: tyle pierwszych stron 404 bez żadnej strony wyrobu — zmiana adresów sklepu. */
    private const MAX_FIRST_MISSING_PAGES = 20;

    /**
     * Udział artykułów bez strony po co najmniej MISSING_CHECK_AFTER. 01.10.2026: 94 z 701 (13%), ale skupione na
     * początku cennika (stare rękawice skórzane 11xx–12xx) — po 100 artykułach 36%, najwięcej 40%.
     */
    private const MAX_MISSING_PAGE_SHARE = 0.6;

    private const MISSING_CHECK_AFTER = 100;

    /** Tyle artykułów bez strony z rzędu — 01.10.2026 najdłuższa seria to 11. */
    private const MAX_MISSING_IN_ROW = 40;

    /** Cennik starszy niż tyle dni — ostrzeżenie w podsumowaniu. */
    private const STALE_FILE_DAYS = 14;

    /** Przedrostek SKU karty: goły numer artykułu (1102) w 120 z 701 przypadków jest już SKU karty innej marki (01.10.2026). */
    public const SKU_PREFIX = 'BIG-';

    /** Długość, na której cennik ucina teksty. */
    private const CUT_LENGTH = 255;

    /** Kolumny tekstowe cennika, które bywają ucięte na CUT_LENGTH znakach. */
    private const TEXT_COLUMNS = ['Marketingtext', 'Artikelbeschreibung', 'Material', 'Einsatzgebiete'];

    private const PROGRESS_EVERY = 50;

    private const REQUIRED_COLUMNS = [
        'Artikel', 'Größe', 'Marke', 'Bezeichnung', 'Farbe', 'Status', 'VE_Menge', 'Preis', 'Anbruchpreis', 'Basispreis',
        'Preiseinheit', 'EAN_Artikel', 'EAN_VE', 'Mindestmenge', 'Herstellerartikelnummer', 'Ursprungsland',
        'Artikelbeschreibung', 'Marketingtext', 'Material', 'Einsatzgebiete', 'PSA_Kategorie', 'Normen',
        'Bild_1_URL', 'Bild_2_URL', 'Bild_3_URL', 'Bild_4_URL', 'Bild_5_URL', 'Bild_6_URL',
    ];

    /** Pary ze strony wyrobu, których nie bierzemy do tabelki (opis albo dane celne). */
    private const SKIPPED_PAIRS = ['eigenschaften', 'zolltarif'];

    /** Status „-” w cenniku nie mówi nic o dostępności. */
    private const EMPTY_STATUS = ['', '-'];

    private int $total = 0;

    private int $cards = 0;

    private int $positions = 0;

    /** @var list<string> */
    private array $summary = [];

    /** @var list<string> */
    private array $withoutPrice = [];

    /** @var list<string> */
    private array $withoutPage = [];

    /** @var list<string> */
    private array $cutTexts = [];

    /** @var list<string> */
    private array $conflicting = [];

    /** @var list<string> */
    private array $pageErrors = [];

    private int $duplicates = 0;

    private int $pagesFound = 0;

    private int $pagesMissing = 0;

    private int $missingInRow = 0;

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    /**
     * @param  int  $minArticles  strażnik długości cennika (testy podają mniejszy)
     */
    public function __construct(
        private readonly BigB2bClient $client,
        private readonly int $minArticles = self::MIN_ARTICLES,
        private readonly int $minRows = self::MIN_ROWS,
    ) {}

    public static function key(): string
    {
        return 'big';
    }

    public static function label(): string
    {
        return 'BIG Arbeitsschutz';
    }

    public static function host(): string
    {
        return 'big-arbeitsschutz.de';
    }

    public static function ownBrand(): string
    {
        return self::BRAND;
    }

    public static function ownBrands(): array
    {
        return self::OWN_BRANDS;
    }

    public static function normShopFieldNames(): array
    {
        return [self::NORM_FIELD];
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        // zwykła przerwa przebiegu (150 ms) podniesiona do sekundy; 0 (testy, jawne --delay=0) zostaje
        return new self(new BigB2bClient((string) $account->username, (string) $account->password, $delayMs > 0 ? max($delayMs, self::MIN_DELAY_MS) : 0));
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
        $this->withoutPage = [];
        $this->cutTexts = [];
        $this->conflicting = [];
        $this->pageErrors = [];
        $this->duplicates = 0;
        $this->pagesFound = 0;
        $this->pagesMissing = 0;
        $this->missingInRow = 0;
        $this->cards = 0;
        $this->positions = 0;

        if (! $this->client->isLoggedIn()) {
            $this->client->login();
        }

        $file = $this->client->priceListXlsx();
        $articles = self::parsePriceList($file['bytes']);
        unset($file['bytes']);
        $rows = array_sum(array_map('count', $articles));
        if (count($articles) < $this->minArticles || $rows < $this->minRows) {
            throw new B2bFatalException('Cennik konta '.$file['name'].' ma tylko '.count($articles).' artykułów i '.$rows.' wierszy (oczekiwano co najmniej '.$this->minArticles.' i '.$this->minRows.') — ucięty plik albo zmiana konta? Przebieg przerwany');
        }
        $this->total = count($articles);
        $this->summary[] = 'Lista BIG: cennik '.$file['name'].($file['date'] !== '' ? ' z '.$file['date'] : '').', '
            .$rows.' wierszy, '.count($articles).' artykułów';
        $this->progress('Cennik BIG '.$file['name'].': '.$rows.' wierszy, '.count($articles).' artykułów');
        $age = self::fileAgeDays($file['name']);
        if ($age !== null && $age > self::STALE_FILE_DAYS) {
            $this->summary[] = 'Uwaga: cennik konta ma '.$age.' dni ('.$file['name'].') — sklep nie wystawił nowszego';
        }

        $done = 0;
        foreach ($articles as $number => $rows) {
            $product = $this->productFor((string) $number, $rows);
            $done++;
            if ($done % self::PROGRESS_EVERY === 0) {
                $this->progress('Strony wyrobów BIG: '.$done.'/'.$this->total);
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
        $lines[] = 'Karty: '.$this->cards.', pozycji (rozmiarów): '.$this->positions;
        if ($this->duplicates > 0) {
            $lines[] = 'Powtórzone identyczne wiersze cennika (pominięte): '.$this->duplicates;
        }
        if ($this->conflicting !== []) {
            $lines[] = 'Rozmiar powtórzony z innymi danymi (wzięty pierwszy wiersz): '.self::listing($this->conflicting);
        }
        if ($this->withoutPrice !== []) {
            $lines[] = 'Bez ceny konta (0,00 € — poza kartą): '.self::listing($this->withoutPrice);
        }
        if ($this->withoutPage !== []) {
            $lines[] = 'Artykuły bez strony w sklepie (karta z samego cennika, normy bez poziomów): '.self::listing($this->withoutPage);
        }
        if ($this->cutTexts !== []) {
            $lines[] = 'Teksty cennika ucięte na '.self::CUT_LENGTH.' znakach (przycięte do pełnego zdania): '.self::listing($this->cutTexts);
        }
        if ($this->pageErrors !== []) {
            $lines[] = 'Strony nieodczytane (artykuł wstrzymany do następnego przebiegu): '.self::listing($this->pageErrors);
        }

        return $lines;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return (string) ($product->raw['manufacturer'] ?? '');
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            throw new RuntimeException((string) ($product->raw['reason'] ?? 'artykuł nieodczytany'));
        }
        $price = $product->raw['price'] ?? null;
        if (! $price instanceof B2bRemotePrice) {
            throw new RuntimeException('artykuł bez ceny konta');
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

        $fields = [];
        foreach ($product->raw['fields'] as [$name, $value]) {
            if ($value !== '') {
                $fields[] = new B2bRemoteShopField(self::SECTION, $name, $value);
            }
        }

        return $fields;
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
     * @return list<string>
     */
    public function imageUrls(B2bRemoteProduct $product): array
    {
        return $product->raw['images'] ?? [];
    }

    public function imageAt(string $url): ?B2bRemoteImage
    {
        // zdjęcie z cennika, którego serwer nie ma (404) — dostawca go nie wydał, bez błędu w dzienniku
        $file = $this->client->fileBytesOrNull($url);
        if ($file === null || $file['bytes'] === '' || ! str_starts_with($file['mime'], 'image/')) {
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
     * Cennik XLSX → artykuły w kolejności pliku, każdy z wierszami (kolumna → wartość dosłownie). Brak wymaganej
     * kolumny, wiersz z ceną w innej walucie niż euro albo nieczytelna cena = B2bFatalException (zmiana pliku — ceny
     * nie mogą wejść po cichu źle).
     *
     * @return array<string, list<array<string, string>>>
     */
    public static function parsePriceList(string $xlsxBytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'big-xlsx');
        if ($path === false) {
            throw new RuntimeException('brak pliku tymczasowego na cennik BIG');
        }
        try {
            file_put_contents($path, $xlsxBytes);
            $columns = null;
            $articles = [];
            foreach (XlsxStreamReader::rows($path) as $line => $cells) {
                if ($columns === null) {
                    $columns = self::columnsFrom($cells);

                    continue;
                }
                $row = [];
                foreach ($columns as $name => $index) {
                    $raw = (string) ($cells[$index] ?? '');
                    $row[$name] = self::clean($raw, keepLines: true);
                    if (in_array($name, self::TEXT_COLUMNS, true) && self::looksCut($raw)) {
                        $row['_cut_'.$name] = '1';
                    }
                }
                if ($row['Artikel'] === '' && $row['Bezeichnung'] === '') {
                    continue;
                }
                if (preg_match('/^\d{1,8}$/', $row['Artikel']) !== 1) {
                    throw new B2bFatalException('Cennik BIG: wiersz '.$line.' ma numer artykułu „'.$row['Artikel'].'” (oczekiwano liczby) — zmiana pliku?');
                }
                foreach (['Preis', 'Anbruchpreis'] as $priceColumn) {
                    if ($row[$priceColumn] !== '' && self::euro($row[$priceColumn]) === null) {
                        throw new B2bFatalException('Cennik BIG: wiersz '.$line.', kolumna '.$priceColumn.' = „'.$row[$priceColumn].'” — cena nie w euro albo nieczytelna; przebieg przerwany');
                    }
                }
                $articles[$row['Artikel']][] = $row;
            }
        } finally {
            @unlink($path);
        }
        if ($columns === null) {
            throw new B2bFatalException('Cennik BIG jest pusty');
        }

        return $articles;
    }

    /**
     * Czy komórka cennika jest ucięta. Eksport BIG ucina tekst na 255 znakach, licząc nową linię jako dwa (CRLF), i potem
     * obcina odstępy z końca (01.10.2026: 3415 — 254 znaki z jedną nową linią, „…Front-Reißversch”; 8401 — 254 znaki,
     * „…Gesundheit. Die”). Dlatego ucięta jest komórka o długości ≥ 255 (z CRLF) i komórka ≥ 250 znaków, która nie kończy
     * się znakiem końca zdania.
     */
    public static function looksCut(string $raw): bool
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $length = mb_strlen(str_replace("\n", "\r\n", $raw));
        if ($length >= self::CUT_LENGTH) {
            return true;
        }

        return $length >= self::CUT_LENGTH - 5 && preg_match('/[.!?)"“”]$/u', rtrim($raw)) !== 1;
    }

    /** „1,15 €”, „1.234,50 €” → 1.15 / 1234.5; inna waluta albo zapis — null. */
    public static function euro(string $value): ?float
    {
        $value = str_replace("\u{00A0}", ' ', trim($value));
        if (preg_match('/^(\d{1,3}(?:\.\d{3})+|\d+),(\d{1,2})\s*€$/u', $value, $m) !== 1) {
            return null;
        }

        return (float) (str_replace('.', '', $m[1]).'.'.$m[2]);
    }

    /**
     * Strona wyrobu zalogowanego konta.
     *
     * @return array{title: string, sales_unit: array{qty: float, unit: string}|null, marketing: string, features: string, domain: string, norms: list<string>, marks: list<string>, psa: string, pairs: list<array{0: string, 1: string}>, documents: list<array{title: string, url: string, kind: string}>}
     */
    public static function parseItemPage(string $html): array
    {
        $xpath = self::xpath($html);

        $norms = [];
        $marks = [];
        foreach ($xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " plg_big_normen-norm ")]') ?: [] as $block) {
            $designation = self::clean((string) $xpath->query('.//td[contains(@class, "plg_big_normen-bezeichnung")]', $block)?->item(0)?->textContent);
            if ($designation === '') {
                continue;
            }
            $level = self::clean((string) $xpath->query('.//td[contains(@class, "plg_big_normen-zusatz")]', $block)?->item(0)?->textContent);
            if (preg_match('/^(?:PN[\s\-]+|DIN[\s\-]+)?(?:EN|ISO|IEC)(?:\s?ISO)?\s?\d/u', $designation) === 1) {
                $norms[] = trim($designation.' '.$level);
            } else {
                $marks[] = trim($designation.' '.$level);
            }
        }
        $psa = self::clean((string) $xpath->query('//div[contains(@class, "plg_big_normen-psa")]')?->item(0)?->textContent);
        $psa = trim((string) preg_replace('/^PSA-Kategorie:\s*/u', '', $psa));

        $pairs = [];
        $features = '';
        foreach (['Material', 'Eigenschaften', 'Stammdaten'] as $tab) {
            foreach ($xpath->query('//div[@id="'.$tab.'"]//div[contains(concat(" ", normalize-space(@class), " "), " stammdaten-row ")]') ?: [] as $row) {
                $name = self::clean((string) $xpath->query('.//div[contains(@class, "stammdaten-name")]', $row)?->item(0)?->textContent);
                $valueNode = $xpath->query('.//div[contains(@class, "stammdaten-value")]', $row)?->item(0);
                $lines = $valueNode !== null ? self::blockLines($valueNode) : [];
                if ($name === '' || $lines === []) {
                    continue;
                }
                if (mb_strtolower($name) === 'eigenschaften') {
                    $features = implode("\n", $lines);

                    continue;
                }
                if (in_array(mb_strtolower($name), self::SKIPPED_PAIRS, true)) {
                    continue;
                }
                $pairs[] = [$name, implode('; ', $lines)];
            }
        }

        $domainNode = $xpath->query('//div[@id="Einsatzgebiete"]//div[contains(@class, "product-domain")]')?->item(0);
        $descriptionNode = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " product-description ")]')?->item(0);

        return [
            'title' => self::clean((string) $xpath->query('//h1[contains(@class, "product-title")]')?->item(0)?->textContent),
            'marketing' => $descriptionNode !== null ? implode("\n", self::blockLines($descriptionNode)) : '',
            'features' => $features,
            'domain' => $domainNode !== null ? self::clean(implode(' ', self::blockLines($domainNode))) : '',
            'norms' => array_values(array_unique($norms)),
            'marks' => array_values(array_unique($marks)),
            'psa' => $psa,
            'pairs' => $pairs,
            'documents' => self::documentLinks($xpath),
            'sales_unit' => self::salesUnit($xpath),
        ];
    }

    /**
     * „Abnahmeeinheit: 12 Paar” (title: „Die Bestellmenge muss ein Vielfaches von 12 Paar sein”) — minimum i krok
     * zamówienia, które sklep wymusza; null = strona go nie podaje (zamówienie po sztuce).
     *
     * @return array{qty: float, unit: string}|null
     */
    private static function salesUnit(DOMXPath $xpath): ?array
    {
        $text = self::clean((string) $xpath->query('//span[contains(concat(" ", normalize-space(@class), " "), " sales_unit ")]')?->item(0)?->textContent);
        if (preg_match('/^Abnahmeeinheit:\s*(\d+(?:[.,]\d+)?)\s*(\S.*)?$/u', $text, $m) !== 1) {
            return null;
        }
        $qty = B2bOrderQuantity::attribute($m[1]);

        return $qty !== null ? ['qty' => $qty, 'unit' => trim($m[2] ?? '')] : null;
    }

    /**
     * Pliki ze strony: wersja PL każdego typu, a typ bez wersji PL — wersja DE. Tytuł „1102_PL_Arkusz_danych_technicznych”
     * (bez rozmiaru pliku). Inne języki i pliki bez kodu języka (wzór etykiety) — pomijane.
     *
     * @param  list<array{title: string, url: string}>  $links
     * @return list<array{title: string, url: string, kind: string}>
     */
    public static function chooseDocuments(array $links): array
    {
        $byLanguage = ['PL' => [], 'DE' => []];
        foreach ($links as $link) {
            if (preg_match('/^[0-9A-Za-z\-]+_([A-Z]{2})_(.+)$/u', $link['title'], $m) !== 1 || ! isset($byLanguage[$m[1]])) {
                continue;
            }
            $kind = self::documentKind($m[2]);
            if ($kind === null) {
                continue;
            }
            $byLanguage[$m[1]][] = ['title' => $link['title'], 'url' => $link['url'], 'kind' => $kind];
        }
        $polishKinds = array_column($byLanguage['PL'], 'kind');
        $out = $byLanguage['PL'];
        foreach ($byLanguage['DE'] as $file) {
            if (! in_array($file['kind'], $polishKinds, true)) {
                $out[] = $file;
            }
        }

        return $out;
    }

    /**
     * Tekst z cennika: pole ucięte na 255 znakach ($cut — surowa komórka miała tyle znaków) przycięte do ostatniego
     * pełnego zdania (kropka, wykrzyknik, pytajnik) albo — lista po przecinkach — do ostatniej pełnej pozycji. Bez takiej
     * granicy ucięte pole odpada w całości. Pole nieucięte — bez zmian.
     *
     * @return array{text: string, cut: bool}
     */
    public static function uncut(string $text, bool $cut): array
    {
        if (! $cut) {
            return ['text' => $text, 'cut' => false];
        }
        if (preg_match('/^(.*[.!?])(?=\s)/su', $text, $m) === 1 && mb_strlen($m[1]) >= 40) {
            return ['text' => trim($m[1]), 'cut' => true];
        }
        // pole w liniach (Material: „Obermaterial: …\nFußbett: …\nPassende Fußbetten:”) — bez ostatniej, uciętej linii
        $newline = mb_strrpos($text, "\n");
        if ($newline !== false && $newline >= 20) {
            return ['text' => trim(mb_substr($text, 0, $newline)), 'cut' => true];
        }
        if (preg_match('/^(.*\S)\s*,\s/su', $text, $m) === 1 && mb_strlen($m[1]) >= 40) {
            return ['text' => trim($m[1]), 'cut' => true];
        }

        return ['text' => '', 'cut' => true];
    }

    /** Producent wiersza: marka bez ®/™; marka handlowa SPG → marka z nazwy przed „®” (Klever, Pacific Handy Cutter). */
    public static function manufacturerOf(string $brand, string $name): string
    {
        $brand = self::brandText($brand);
        if (BrandKey::of($brand) === self::TRADE_BRAND && preg_match('/^([^®™]{2,40}?)\s*[®™]/u', $name, $m) === 1) {
            return self::brandText($m[1]);
        }

        return $brand;
    }

    /**
     * @param  list<array<string, string>>  $rows  wiersze cennika jednego artykułu
     */
    private function productFor(string $number, array $rows): B2bRemoteProduct
    {
        $lead = $rows[0];
        $name = $lead['Bezeichnung'] !== '' ? $lead['Bezeichnung'] : $number;

        // ten sam rozmiar dwa razy: identyczny wiersz po cichu raz, inny — pierwszy i wpis w podsumowaniu
        $bySize = [];
        foreach ($rows as $row) {
            $size = $row['Größe'];
            if (! isset($bySize[$size])) {
                $bySize[$size] = $row;
            } elseif ($bySize[$size] === $row) {
                $this->duplicates++;
            } else {
                $this->conflicting[] = $number.($size !== '' ? '/'.$size : '');
            }
        }

        $priced = [];
        foreach ($bySize as $size => $row) {
            $size = (string) $size;
            $net = self::euro($row['Anbruchpreis']);
            if ($net === null || $net <= 0) {
                $this->withoutPrice[] = $number.($size !== '' ? '/'.$size : '').($row['Status'] !== '' && $row['Status'] !== '-' ? ' ('.$row['Status'].')' : '');

                continue;
            }
            $priced[$size] = $row + ['_net' => $net];
        }
        if ($priced === []) {
            return $this->skipped($number, $name, 'artykuł bez ceny konta w cenniku (0,00 €)');
        }

        try {
            $html = $this->client->itemPage($number);
            $page = $html !== null ? self::parseItemPage($html) : null;
            if ($page !== null && $page['title'] === '') {
                throw new RuntimeException('strona bez nagłówka wyrobu (zmiana strony?)');
            }
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $this->pageErrors[] = $number;

            return $this->skipped($number, $name, 'strona artykułu nieodczytana: '.$e->getMessage());
        }
        if ($page === null) {
            $this->withoutPage[] = $number;
            $this->pagesMissing++;
            $this->missingInRow++;
            $this->guardMissingPages();
        } else {
            $this->pagesFound++;
            $this->missingInRow = 0;
        }

        $manufacturer = self::manufacturerOf($lead['Marke'], $name);
        $members = [];
        $identifiers = [];
        $cheapest = null;
        $byStatus = [];
        foreach ($priced as $size => $row) {
            $size = (string) $size;
            $remoteId = $size === '' ? $number : $number.'/'.$size;
            $price = self::priceOf($row, $page['sales_unit'] ?? null, $page !== null);
            $status = in_array($row['Status'], self::EMPTY_STATUS, true) ? '' : $row['Status'];
            $members[] = [
                'remote_id' => $remoteId,
                'sku' => $remoteId,
                'name' => $size === '' ? $name : $name.', '.$size,
                'availability' => $status,
                'size' => $size === '' ? $number : $size,
                'price' => $price,
            ];
            $byStatus[$status][] = $size;
            if ($cheapest === null || $price->net < $cheapest->net - 0.0049) {
                $cheapest = $price;
            }
        }
        $grouped = count($members) > 1;
        foreach ($priced as $size => $row) {
            $size = (string) $size;
            $remoteId = $size === '' ? $number : $number.'/'.$size;
            $label = $size !== '' ? $size : null;
            foreach ([['EAN_Artikel', ProductIdentifier::TYPE_EAN], ['EAN_VE', ProductIdentifier::TYPE_PACK_EAN], ['Herstellerartikelnummer', ProductIdentifier::TYPE_MANUFACTURER_CODE]] as [$column, $type]) {
                $value = trim($row[$column]);
                if ($value === '' || ($type !== ProductIdentifier::TYPE_MANUFACTURER_CODE && preg_match('/^\d{8,14}$/', $value) !== 1)) {
                    continue;
                }
                $identifiers[] = new B2bRemoteIdentifier(
                    type: $type,
                    value: $value,
                    remoteId: $grouped ? $remoteId : null,
                    label: $label,
                    field: $column,
                );
            }
        }
        // numer artykułu: przy markach BIG (teXXor, 4PROTECT, RUNNEX) to kod producenta, przy TOWA i nożach — kod sklepu
        $identifiers[] = new B2bRemoteIdentifier(
            type: B2bManufacturerSiteBrands::matching(self::class, $manufacturer) !== null ? ProductIdentifier::TYPE_MANUFACTURER_CODE : ProductIdentifier::TYPE_SOURCE_CODE,
            value: $number,
            field: 'Artikel',
        );

        if ($cheapest === null) {
            return $this->skipped($number, $name, 'artykuł bez ceny konta w cenniku');
        }
        // warunek zamawiania karty idzie z price(): różne minimum albo jednostka rozmiarów = „zależy od rozmiaru”
        $orders = array_unique(array_map(static fn (array $m): string => serialize($m['price']->order), $members));
        if (count($orders) > 1) {
            $cheapest = new B2bRemotePrice(
                net: $cheapest->net,
                base: $cheapest->base,
                discountPercent: $cheapest->discountPercent,
                currency: $cheapest->currency,
                order: new B2bOrderQuantity(min: null, step: null, unit: null, varies: true),
                carton: $cheapest->carton,
            );
        }
        $this->cards++;
        $this->positions += count($members);

        $description = $page !== null ? self::pageDescription($page) : $this->fileDescription($number, $lead);
        $sizes = array_values(array_filter(array_map('strval', array_keys($priced)), static fn (string $s): bool => $s !== ''));

        return new B2bRemoteProduct(
            remoteId: $members[0]['remote_id'],
            sku: self::SKU_PREFIX.$number,
            name: $name,
            sourceUrl: $page !== null ? BigB2bClient::BASE.'/item-1-'.$number.'.html' : null,
            raw: [
                'status' => 'ok',
                'manufacturer' => $manufacturer,
                // cena karty = najniższa cena pozycji (B2bCatalogSync liczy ją też z members[].price)
                'price' => $cheapest,
                'description' => $description,
                'fields' => $this->fieldsFor($number, $lead, $priced, $page, $cheapest->order),
                'documents' => $page !== null ? $page['documents'] : [],
                'images' => self::imagesOf($lead),
            ],
            availability: self::availabilityText($byStatus, $grouped),
            variantSummary: $grouped && $sizes !== [] ? 'Rozmiary: '.implode(', ', $sizes) : null,
            members: $grouped ? $members : [],
            identifiers: $identifiers,
        );
    }

    /**
     * Cena pozycji: Anbruchpreis (od minimum zamówienia), cena cennikowa z Basispreis, minimum = krok zamówienia.
     * Druga cena (decyzja właściciela 01.10.2026): Preis = cena przy pełnej jednostce wysyłkowej VE_Menge („96 Paar”) —
     * tylko gdy niższa od ceny konta (4PROTECT i RUNNEX mają jedną cenę; wtedy pole czyszczone).
     * Minimum ze strony („Abnahmeeinheit”, które sklep wymusza; strona bez niego = po sztuce), a artykuł bez strony —
     * Mindestmenge z cennika (01.10.2026 w 2368 cennik ma 6 = opakowanie, a sklep wymaga wielokrotności 12).
     *
     * @param  array<string, mixed>  $row
     * @param  array{qty: float, unit: string}|null  $salesUnit
     */
    private static function priceOf(array $row, ?array $salesUnit, bool $hasPage): B2bRemotePrice
    {
        $net = (float) $row['_net'];
        $base = preg_match('/^\d+(?:\.\d+)?$/', (string) $row['Basispreis']) === 1 ? round((float) $row['Basispreis'], 2) : null;
        // Basispreis 0 = brak ceny cennikowej; niższy od ceny konta — nie jest ceną przed rabatem
        if ($base !== null && ($base <= 0 || $base < $net)) {
            $base = null;
        }
        $minimum = $hasPage ? ($salesUnit['qty'] ?? 1.0) : B2bOrderQuantity::attribute((string) $row['Mindestmenge']);
        $unit = $hasPage && $salesUnit !== null && $salesUnit['unit'] !== '' ? $salesUnit['unit'] : (string) $row['Preiseinheit'];

        $carton = self::euro((string) $row['Preis']);
        $cartonQty = preg_match('/^(\d+(?:[.,]\d+)?)\s/u', (string) $row['VE_Menge'].' ', $m) === 1 ? B2bOrderQuantity::attribute($m[1]) : null;

        return new B2bRemotePrice(
            net: $net,
            base: $base,
            discountPercent: $base !== null && $base > $net ? round((1 - $net / $base) * 100, 2) : 0.0,
            currency: 'EUR',
            order: $minimum !== null ? new B2bOrderQuantity(min: $minimum, step: $minimum, unit: $unit !== '' ? $unit : null) : null,
            carton: new B2bCartonPrice($carton !== null && $carton > 0 && $carton < $net - 0.0049 ? $carton : null, $cartonQty),
        );
    }

    /**
     * @param  array{marketing: string, features: string, domain: string}  $page
     */
    private static function pageDescription(array $page): string
    {
        $parts = [];
        if ($page['marketing'] !== '') {
            $parts[] = $page['marketing'];
        }
        // 4PROTECT powtarza opis w „Eigenschaften” słowo w słowo
        if ($page['features'] !== '' && self::clean($page['features']) !== self::clean($page['marketing'])) {
            $parts[] = $page['features'];
        }
        if ($page['domain'] !== '') {
            $parts[] = 'Einsatzgebiete: '.$page['domain'];
        }

        return mb_substr(implode("\n\n", $parts), 0, 10000);
    }

    /**
     * Opis artykułu bez strony w sklepie: Marketingtext, Artikelbeschreibung i Einsatzgebiete z cennika, pola ucięte
     * przycięte do pełnego zdania.
     *
     * @param  array<string, string>  $row
     */
    private function fileDescription(string $number, array $row): string
    {
        $parts = [];
        $cut = false;
        foreach (['Marketingtext' => '', 'Artikelbeschreibung' => '', 'Einsatzgebiete' => 'Einsatzgebiete: '] as $column => $prefix) {
            $text = self::uncut($row[$column], isset($row['_cut_'.$column]));
            $cut = $cut || $text['cut'];
            if ($text['text'] !== '' && ! in_array($prefix.$text['text'], $parts, true)) {
                $parts[] = $prefix.$text['text'];
            }
        }
        if ($cut) {
            $this->cutTexts[] = $number;
        }

        return implode("\n\n", $parts);
    }

    /**
     * Tabelka sklepu: normy (wiersz na normę z poziomem), kategoria ŚOI, inne oznaczenia, kolor, rozmiary, pary ze strony
     * (Material, Eigenschaften, Stammdaten), status, jednostka i minimum zamówienia. Cena przy pełnym kartonie jest polem
     * ceny (B2bCartonPrice), nie wierszem tabelki.
     *
     * @param  array<string, string>  $lead
     * @param  array<string, array<string, mixed>>  $priced
     * @param  array{norms: list<string>, marks: list<string>, psa: string, pairs: list<array{0: string, 1: string}>}|null  $page
     * @return list<array{0: string, 1: string}>
     */
    private function fieldsFor(string $number, array $lead, array $priced, ?array $page, ?B2bOrderQuantity $orderOf): array
    {
        $fields = [['Numer artykułu', $number]];
        if ($page !== null) {
            foreach ($page['norms'] as $norm) {
                $fields[] = [self::NORM_FIELD, $norm];
            }
            $fields[] = ['Kategoria ŚOI', $page['psa']];
            $fields[] = ['Inne oznaczenia', implode(', ', $page['marks'])];
        } else {
            // cennik: normy bez poziomów („EN ISO 21420:2020, EN 388:2016+A1:2018”) — ShopCardNormFacts dzieli listę
            $fields[] = [self::NORM_FIELD, $lead['Normen']];
            $fields[] = ['Kategoria ŚOI', $lead['PSA_Kategorie']];
        }
        $colours = array_values(array_unique(array_filter(array_map(static fn (array $row): string => (string) $row['Farbe'], $priced))));
        $fields[] = ['Kolor', implode(', ', $colours)];
        $sizes = array_values(array_filter(array_map('strval', array_keys($priced)), static fn (string $s): bool => $s !== ''));
        $fields[] = ['Rozmiary', implode(', ', $sizes)];
        if ($page !== null) {
            foreach ($page['pairs'] as $pair) {
                $fields[] = $pair;
            }
        } else {
            $material = self::uncut($lead['Material'], isset($lead['_cut_Material']))['text'];
            $fields[] = ['Material', str_replace("\n", '; ', $material)];
            $fields[] = ['Kraj pochodzenia', $lead['Ursprungsland']];
        }
        $statuses = [];
        foreach ($priced as $size => $row) {
            if (! in_array($row['Status'], self::EMPTY_STATUS, true)) {
                $statuses[$row['Status']][] = (string) $size;
            }
        }
        $statusText = [];
        foreach ($statuses as $status => $statusSizes) {
            $statusText[] = count($statusSizes) === count($priced) || $statusSizes === [''] ? $status : $status.': '.implode(', ', $statusSizes);
        }
        $fields[] = ['Status', implode('; ', $statusText)];
        $order = $orderOf;
        if ($order !== null && ! $order->varies && $order->min !== null && $order->min > 1) {
            $fields[] = ['Minimum i wielokrotność zamówienia', B2bOrderQuantity::format($order->min).($order->unit !== null ? ' '.$order->unit : '')];
        }
        $fields[] = ['Jednostka ceny', $lead['Preiseinheit']];

        return $fields;
    }

    /**
     * @param  array<string, string>  $row
     * @return list<string>
     */
    private static function imagesOf(array $row): array
    {
        $out = [];
        for ($i = 1; $i <= 6; $i++) {
            $url = self::encodedUrl($row['Bild_'.$i.'_URL'] ?? '');
            if ($url !== null && ! in_array($url, $out, true)) {
                $out[] = $url;
            }
        }

        return $out;
    }

    /** Adres zdjęcia z cennika z zakodowaną ścieżką („1108_Handrücken_s.jpg” → „1108_Handr%C3%BCcken_s.jpg”); null = spoza witryny. */
    private static function encodedUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^(https://[^/]+)(/[^?\#]*)$#u', $url, $m) !== 1) {
            return null;
        }
        $path = implode('/', array_map(static fn (string $segment): string => rawurlencode(rawurldecode($segment)), explode('/', $m[2])));
        $encoded = $m[1].$path;

        return BigB2bClient::isSiteUrl($encoded) ? $encoded : null;
    }

    /**
     * Dostępność karty: status z cennika („Auslauf”, „Restposten”, „auf Anfrage”) — dla całej karty albo z rozmiarami;
     * null = żadna pozycja nie ma statusu.
     *
     * @param  array<string, list<string>>  $byStatus  status ('' = bez) → rozmiary
     */
    private static function availabilityText(array $byStatus, bool $withSizes): ?string
    {
        $known = array_filter($byStatus, static fn (string $status): bool => $status !== '', ARRAY_FILTER_USE_KEY);
        if ($known === []) {
            return null;
        }
        if (! $withSizes || count($byStatus) === 1) {
            return (string) array_key_first($known);
        }
        $parts = [];
        foreach ($known as $status => $sizes) {
            $parts[] = $status.': '.implode(', ', $sizes);
        }

        return mb_substr(implode(' | ', $parts), 0, 1000);
    }

    /**
     * @return list<array{title: string, url: string, kind: string}>
     */
    private static function documentLinks(DOMXPath $xpath): array
    {
        $links = [];
        foreach ($xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " artikelDokumente-link ")][@href]') ?: [] as $a) {
            if (! $a instanceof DOMElement) {
                continue;
            }
            $url = trim($a->getAttribute('href'));
            if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
                $url = BigB2bClient::BASE.$url;
            }
            if (! BigB2bClient::isSiteUrl($url)) {
                continue;
            }
            // spacje w nazwie pliku (rzadkie) kodowane jak w przeglądarce; plusy z adresu zostają
            $url = str_replace(' ', '%20', $url);
            $title = self::clean((string) preg_replace('/\s*\(\s*[\d.,]+\s*[kMG]?B\s*\)\s*$/u', '', self::clean($a->textContent)));
            if ($title !== '' && ! isset($links[$url])) {
                $links[$url] = ['title' => $title, 'url' => $url];
            }
        }

        return self::chooseDocuments(array_values($links));
    }

    /** Typ pliku z części nazwy po kodzie języka; null = plik, którego nie bierzemy. */
    private static function documentKind(string $type): ?string
    {
        $type = mb_strtolower($type);

        return match (true) {
            str_contains($type, 'carelabel') || str_contains($type, 'artwork') => null,
            str_contains($type, 'konformit') || str_contains($type, 'deklaracja') || str_contains($type, 'declaration') => ProductDocument::KIND_CERTIFICATE,
            str_contains($type, 'datenblatt') || str_contains($type, 'datasheet') || str_contains($type, 'data_sheet') || str_contains($type, 'arkusz') || str_contains($type, 'technsiches') => ProductDocument::KIND_DATASHEET,
            str_contains($type, 'informationen') || str_contains($type, 'informacje') || str_contains($type, 'information') => ProductDocument::KIND_MANUAL,
            str_contains($type, 'größe') || str_contains($type, 'groeße') || str_contains($type, 'groesse') || str_contains($type, 'rozmiar') => ProductDocument::KIND_SIZE_CHART,
            default => ProductDocument::KIND_OTHER,
        };
    }

    /**
     * Kolumny cennika po nagłówku; brak wymaganej = B2bFatalException.
     *
     * @param  list<string>  $cells
     * @return array<string, int>
     */
    private static function columnsFrom(array $cells): array
    {
        $columns = [];
        foreach ($cells as $index => $cell) {
            $name = self::clean($cell);
            if ($name !== '' && ! isset($columns[$name])) {
                $columns[$name] = $index;
            }
        }
        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, array_keys($columns)));
        if ($missing !== []) {
            throw new B2bFatalException('Cennik BIG bez kolumn: '.implode(', ', $missing).' — zmiana pliku? Przebieg przerwany');
        }

        return array_intersect_key($columns, array_flip(self::REQUIRED_COLUMNS));
    }

    /**
     * Wiele artykułów bez strony = zmiana adresów sklepu, a nie brak stron: karty zastąpiłyby pełne opisy uciętymi
     * tekstami z cennika — przebieg przerwany.
     */
    private function guardMissingPages(): void
    {
        $checked = $this->pagesFound + $this->pagesMissing;
        if ($this->pagesFound === 0 && $this->pagesMissing >= self::MAX_FIRST_MISSING_PAGES) {
            throw new B2bFatalException($this->pagesMissing.' pierwszych artykułów bez strony w '.BigB2bClient::HOST.' (404) — zmiana adresów stron? Przebieg przerwany');
        }
        if ($this->missingInRow >= self::MAX_MISSING_IN_ROW) {
            throw new B2bFatalException($this->missingInRow.' kolejnych artykułów bez strony w '.BigB2bClient::HOST.' (404) — zmiana adresów stron? Przebieg przerwany');
        }
        if ($checked >= self::MISSING_CHECK_AFTER && $this->pagesMissing > $checked * self::MAX_MISSING_PAGE_SHARE) {
            throw new B2bFatalException($this->pagesMissing.' z '.$checked.' artykułów bez strony w '.BigB2bClient::HOST.' (404) — zmiana adresów stron? Przebieg przerwany');
        }
    }

    /** Wiek cennika w dniach z nazwy „Preisliste_20260930”; null = nazwa bez daty. */
    private static function fileAgeDays(string $name): ?int
    {
        if (preg_match('/(20\d{2})(\d{2})(\d{2})/', $name, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        $date = CarbonImmutable::create((int) $m[1], (int) $m[2], (int) $m[3], 0, 0, 0, B2bAccount::SYNC_TIMEZONE);

        return $date === null ? null : (int) $date->diffInDays(CarbonImmutable::now(B2bAccount::SYNC_TIMEZONE)->startOfDay(), false);
    }

    /** Marka bez znaków ® i ™, odstępy zwinięte. */
    private static function brandText(string $value): string
    {
        return self::clean(str_replace(['®', '™'], '', $value));
    }

    /**
     * Tekst bloku: punkt listy, akapit i tekst między <br> = osobna linia; treść dosłownie, odstępy w linii zwinięte.
     *
     * @return list<string>
     */
    private static function blockLines(DOMNode $node): array
    {
        $lines = [];
        $current = '';
        $flush = static function () use (&$lines, &$current): void {
            $text = self::clean($current);
            if ($text !== '') {
                $lines[] = $text;
            }
            $current = '';
        };
        $walk = static function (DOMNode $node) use (&$walk, &$current, $flush): void {
            foreach ($node->childNodes as $child) {
                if (! $child instanceof DOMElement) {
                    $current .= str_replace(["\r", "\n"], ' ', $child->textContent);

                    continue;
                }
                $tag = strtolower($child->tagName);
                if ($tag === 'br') {
                    $flush();
                } elseif (in_array($tag, ['li', 'p', 'div', 'ul', 'ol', 'h3', 'h4', 'table', 'tr'], true)) {
                    $flush();
                    $walk($child);
                    $flush();
                } elseif (! in_array($tag, ['script', 'style', 'svg'], true)) {
                    $walk($child);
                }
            }
        };
        $walk($node);
        $flush();

        return $lines;
    }

    private static function xpath(string $html): DOMXPath
    {
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($doc);
    }

    /**
     * Artykuł, którego nie da się zapisać w tym przebiegu — pozycja bez ceny, widoczna jako pominięta.
     */
    private function skipped(string $sku, string $name, string $reason): B2bRemoteProduct
    {
        return new B2bRemoteProduct(
            remoteId: $sku,
            sku: $sku,
            name: $name !== '' ? $name : $sku,
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

    /**
     * Tekst ze strony albo z cennika: bez znaków zerowej szerokości, twarde spacje jako zwykłe, odstępy zwinięte;
     * $keepLines — podział na linie zostaje (pola cennika z kilkoma liniami, np. Material).
     */
    private static function clean(string $text, bool $keepLines = false): string
    {
        $text = str_replace(["\u{200B}", "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{FEFF}"], '', $text);
        $text = str_replace("\u{00A0}", ' ', $text);
        if (! $keepLines) {
            return trim((string) preg_replace('/\s+/u', ' ', $text));
        }
        $lines = array_map(static fn (string $line): string => trim((string) preg_replace('/[^\S\n]+/u', ' ', $line)), explode("\n", str_replace(["\r\n", "\r"], "\n", $text)));

        return trim(implode("\n", array_filter($lines, static fn (string $line): bool => $line !== '')));
    }
}
