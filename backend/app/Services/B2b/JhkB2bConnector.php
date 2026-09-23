<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

/**
 * Sklep B2B JHK Polska jhkpolska.pl (silnik SolEx B2B) — polski oddział producenta odzieży JHK, w ofercie także
 * linia JHK PROFESSIONAL (odzież ostrzegawcza HV…) i wyroby MOONTEX. Sprawdzone na koncie 22.09.2026.
 *
 * Lista (/pl/p?strona=N, 50 pozycji na stronie, 33 strony ≈ 1636 pozycji) to WYROBY W KOLORZE: pozycja prowadzi do
 * strony jednego rozmiaru, a ta pokazuje wszystkie rozmiary koloru w tabeli wariantów. Sklep ma dwa układy listy
 * (kafle i tabelę rodzin — patrz listTiles) i łącznik czyta oba, bo widok jest ustawieniem konta. Pełna lista idzie
 * przed pierwszym zapisem, z kontrolami spójności jak u Procery (liczba stron stała, tyle samo pozycji na każdej
 * stronie poza ostatnią, unikalne adresy; zmiana w trakcie = jedno ponowne pobranie). Potem strona każdego
 * koloru — raz na kolor; dwie pozycje listy prowadzące do tej samej rodziny rozmiarów dają jedną kartę.
 *
 * Ceny: strona konta podaje w tabeli wariantów „Twoją cenę” każdego rozmiaru (cena zakupu). Ceny katalogowej
 * (detalicznej) sklep zalogowanemu podaje tylko przy rozmiarze otwartej karty, więc tę samą stronę pobieramy
 * drugi raz bez logowania — tam każdy rozmiar ma „Cenę detaliczną”. Wyrób bez rozmiarów (czapka, koc) ma obie ceny
 * w bloku głównym strony konta i drugiego pobrania nie wymaga. Wyjątek: wyrób sprzedawany progami ilościowymi
 * (kamizelki ostrzegawcze, narzuty — tabela „Progi cenowe”) nie ma bloku „Twoja cena” ani ceny przed rabatem;
 * ceną konta jest próg, po którym sklep sprzedaje teraz („Twoja cena” w tabeli), a cena katalogowa pochodzi ze
 * strony gościa. Pozostałe progi idą do tabelki sklepu — w przetargu cena zależy od zamawianej ilości.
 * Cena katalogowa niższa od ceny konta albo rozmiar, którego strona gościa nie zna, to karta bez ceny katalogowej
 * (liczone w podsumowaniu przebiegu).
 *
 * Karta = rozmiary jednego koloru w tej samej parze cen (decyzja użytkownika 15.09.2026: rozmiar w innej cenie to
 * osobna karta; w JHK typowo XS–XXL w jednej cenie, 3XL droższy). SKU: kod wyrobu bez rozmiaru („JT SWCR BK”), gdy
 * wszystkie rozmiary mają ten sam kod z dopisanym rozmiarem i kolor jest jedną kartą; inaczej — i przy podziale
 * cenami — symbol pierwszego rozmiaru karty („JT SWCR BK 3XL”), żeby kod nie przechodził między kartami przy zmianie
 * podziału. Pozycje (members) i remote_id = symbole rozmiarów (sklep ma je na każdej karcie).
 *
 * Opis: pole OPIS dosłownie; sekcja „Załączniki” z odnośnikami do plików do opisu nie wchodzi (to lista plików,
 * nie opis wyrobu). Pliki: karty produktu i certyfikaty ze strony (blok „Karty do pobrania” i odnośniki w opisie);
 * przy komplecie językowym zostaje wersja polska. Sklep nie podaje norm ani certyfikatów jako danych — łącznik
 * ich nie dopisuje, zostają w plikach PDF.
 *
 * Producent — ZAŁOŻENIE (sklep nie ma pola producenta): MOONTEX dla wyrobów z tą marką w nazwie albo symbolu
 * (MOO…), w pozostałych JHK. Witryna należy do producenta JHK (B2bManufacturerSite, marka JHK), więc opis stąd
 * może nadpisać opis kart JHK — kart MOONTEX już nie.
 */
final class JhkB2bConnector implements B2bConnector, B2bDocumentSource, B2bGroupsSizes, B2bImageGallery, B2bListProgressAware, B2bManufacturerSite, B2bRunSummaryAware, B2bShopFieldSource
{
    public const BRAND = 'JHK';

    /** Druga marka w sklepie; rozpoznawana w nazwie albo w symbolu (MOO410HV, MOONTEX KOC…). */
    public const BRAND_MOONTEX = 'MOONTEX';

    /** Nieprzerwane pobieranie listy dłużej = błąd (przebieg bez postępu uznałby b2b:sync-due za przerwany). */
    private const LIST_BUDGET_SECONDS = 25 * 60;

    private const PROGRESS_EVERY_PAGES = 5;

    private const INCONSISTENT = 7301;

    private const DOCUMENTS_LIMIT = 8;

    private const IMAGES_LIMIT = 8;

    private const SHOP_SECTION_MARKS = 'Oznaczenia';

    private const SHOP_SECTION_FEATURES = 'Cechy';

    private const SHOP_SECTION_TRADE = 'Informacje handlowe';

    /** Wiersz cech sklepu wyliczany z historii zakupów konta, nie z wyrobu. */
    private const BOUGHT_ATTRIBUTE = 'Produkt niekupiony';

    /** Nagłówek listy plików w opisie — od niego opis się kończy. */
    private const ATTACHMENTS_HEADING = 'Załączniki';

    /** Magazyny sklepu dosłownie z podpowiedzi przy stanie. */
    private const STOCK_LOCAL = 'Magazyn w Polsce - dostępne 24 h';

    private const STOCK_CENTRAL = 'Magazyn producenta - dostępne 14 dni';

    private const STOCK_NONE = 'Brak na stanie';

    /** Końcówki nazw plików w innych językach — pomijane, gdy wyrób ma ten sam plik po polsku. */
    private const FOREIGN_SUFFIXES = ['de', 'en', 'cz', 'sk', 'hu', 'fr', 'es', 'it', 'ua', 'ro'];

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    /** Rozszerzenia plików karty (odnośnik bez rozszerzenia prowadzi do strony sklepu, nie do pliku). */
    private const DOCUMENT_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'odt', 'ods', 'ppt', 'pptx', 'zip', 'rar', '7z', 'txt'];

    private const SKIPPED_TAGS = ['input', 'button', 'select', 'option', 'textarea', 'img', 'iframe', 'svg', 'script', 'style', 'noscript'];

    private const BLOCK_TAGS = ['p', 'div', 'section', 'article', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'dl', 'dt', 'dd', 'table', 'thead', 'tbody', 'tr', 'blockquote', 'pre', 'hr'];

    private int $total = 0;

    /** @var list<string> */
    private array $summary = [];

    /** @var list<string> karty bez ceny katalogowej */
    private array $withoutBase = [];

    /** @var list<string> symbole rozmiarów bez ceny konta (poza kartami swojego koloru) */
    private array $sizesWithoutPrice = [];

    /** @var array<string, true> karty z producentem MOONTEX */
    private array $moontex = [];

    /** @var array<string, string> rodziny rozmiarów już wydane: symbol pierwszego rozmiaru => adres kafla */
    private array $families = [];

    private int $cards = 0;

    private int $splitProducts = 0;

    private int $skippedProducts = 0;

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    public function __construct(private readonly JhkB2bClient $client) {}

    public static function key(): string
    {
        return 'jhk';
    }

    public static function label(): string
    {
        return 'JHK Polska';
    }

    public static function host(): string
    {
        return JhkB2bClient::HOST;
    }

    public static function ownBrand(): string
    {
        return self::BRAND;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new JhkB2bClient(
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
        $this->summary = [];
        $this->withoutBase = [];
        $this->sizesWithoutPrice = [];
        $this->moontex = [];
        $this->families = [];
        $this->cards = 0;
        $this->splitProducts = 0;
        $this->skippedProducts = 0;

        if (! $this->client->isLoggedIn()) {
            $this->client->login();
        }

        $rows = $this->listRows();
        $this->total = count($rows);
        $this->summary[] = 'Lista JHK Polska: '.count($rows).' wyrobów w kolorach';

        foreach ($rows as $row) {
            $products = $this->productsFor($row);
            // kolor w kilku cenach to kilka kart — licznik postępu rośnie, zanim je wydamy
            $this->total += max(0, count($products) - 1);
            yield from $products;
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
            'Karty: %d (%d wyrobów w kilku cenach — rozmiar w innej cenie to osobna karta; %d pozycji pominiętych)',
            $this->cards,
            $this->splitProducts,
            $this->skippedProducts,
        );
        if ($this->moontex !== []) {
            $lines[] = 'Karty z producentem MOONTEX (reszta: JHK): '.count($this->moontex);
        }
        if ($this->withoutBase !== []) {
            $lines[] = 'Bez ceny katalogowej (sklep bez logowania nie podał ceny detalicznej rozmiaru): '.self::listing($this->withoutBase);
        }
        if ($this->sizesWithoutPrice !== []) {
            $lines[] = 'Rozmiary bez ceny konta (poza kartami): '.self::listing($this->sizesWithoutPrice);
        }

        return $lines;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        if (self::isMoontex($product->name, $product->sku, (string) ($product->raw['symbol'] ?? ''))) {
            $this->moontex[$product->remoteId] = true;

            return self::BRAND_MOONTEX;
        }

        return self::BRAND;
    }

    /** Marka MOONTEX w nazwie, kodzie albo symbolu karty (MOONTEX KOC…, MOO410HV) — reszta to JHK. */
    private static function isMoontex(string $name, string $sku, string $symbol): bool
    {
        $text = mb_strtoupper($name.' '.$sku.' '.$symbol);

        return str_contains($text, self::BRAND_MOONTEX) || preg_match('/(?<![\p{L}\p{N}])MOO\d/u', $text) === 1;
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            throw new RuntimeException((string) ($product->raw['reason'] ?? 'strona wyrobu nieodczytana'));
        }
        $net = (float) $product->raw['price'];
        $base = $product->raw['base_price'] ?? null;

        return new B2bRemotePrice(
            net: round($net, 2),
            base: is_float($base) ? round($base, 2) : null,
            discountPercent: is_float($base) && $base > 0 ? round((1 - $net / $base) * 100, 2) : 0.0,
        );
    }

    /** Opis ze sklepu dosłownie (bez listy załączników); '' gdy strona opisu nie ma. */
    public function description(B2bRemoteProduct $product): string
    {
        return (string) ($product->raw['description'] ?? '');
    }

    /**
     * Symbol, EAN i pozostałe pola karty dosłownie ze sklepu, cechy (kolor, rozmiar, waga), stany magazynowe
     * rozmiarów i oznaczenia kafla („NOWOŚĆ”). Wszystko z raz pobranej strony — metoda niczego nie pobiera.
     *
     * @return list<B2bRemoteShopField>
     */
    public function shopFields(B2bRemoteProduct $product): array
    {
        $raw = $product->raw;
        if (($raw['status'] ?? null) !== 'ok') {
            return [];
        }

        $fields = [];
        $add = static function (string $section, string $name, string $value) use (&$fields): void {
            if ($name !== '' && $value !== '') {
                $fields[] = new B2bRemoteShopField($section, $name, $value);
            }
        };

        $add(self::SHOP_SECTION_MARKS, 'Symbol', (string) ($raw['symbol'] ?? ''));
        foreach ($raw['fields'] ?? [] as [$label, $value]) {
            $add(self::SHOP_SECTION_MARKS, $label, $value);
        }
        foreach ($raw['attributes'] ?? [] as [$label, $value]) {
            $add(self::SHOP_SECTION_FEATURES, $label, $value);
        }
        $add(self::SHOP_SECTION_TRADE, 'Kategoria w sklepie', (string) ($raw['category_path'] ?? ''));
        $add(self::SHOP_SECTION_TRADE, 'VAT', (string) ($raw['vat'] ?? ''));
        $add(self::SHOP_SECTION_TRADE, 'Progi cenowe konta', implode('; ', array_map(
            static fn (array $tier): string => $tier['label'].' szt.: '.number_format($tier['cents'] / 100, 2, ',', ' ').' PLN',
            $raw['tiers'] ?? [],
        )));
        $add(self::SHOP_SECTION_TRADE, 'Stan magazynowy', implode('; ', $raw['stock'] ?? []));
        $add(self::SHOP_SECTION_TRADE, 'Oznaczenia sklepu', implode('; ', $raw['labels'] ?? []));

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

    /**
     * @return array{bytes: string, mime: string}
     */
    public function documentBytes(B2bRemoteDocument $document): array
    {
        $file = $this->client->fileBytes($document->sourceUrl, 'pliku wyrobu');
        if (str_starts_with($file['bytes'], '%PDF-')) {
            $file['mime'] = 'application/pdf';
        }

        return $file;
    }

    /**
     * @return list<string>
     */
    public function imageUrls(B2bRemoteProduct $product): array
    {
        return $product->raw['image_urls'] ?? [];
    }

    public function imageAt(string $url): ?B2bRemoteImage
    {
        $file = $this->client->imageBytes($url);
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

    /** „46,00 PLN /szt.” → 4600; inny zapis (bez waluty, inna waluta) = null — nie zgadujemy. */
    public static function priceCents(string $text): ?int
    {
        $text = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
        if (preg_match('/^(\d{1,3}(?: \d{3})*|\d+),(\d{2}) PLN(?: ?\/.*)?$/u', $text, $m) !== 1) {
            return null;
        }

        return (int) preg_replace('/\D/', '', $m[1]) * 100 + (int) $m[2];
    }

    /**
     * Kod wyrobu z symboli rozmiarów: każdy symbol bez swojego rozmiaru na końcu („JT SWCR BK XS” przy rozmiarze
     * „XS” → „JT SWCR BK”). Odzież dziecięca zapisuje ten sam rozmiar raz z ukośnikiem, raz z myślnikiem („3/4”
     * w symbolu, „3-4” w tabeli) — to nadal ten sam rozmiar. Wszystkie symbole muszą dać ten sam kod; inaczej null
     * (nie zgadujemy rdzenia).
     *
     * @param  list<array{symbol: string, size: string}>  $rows
     */
    public static function productCode(array $rows): ?string
    {
        $code = null;
        foreach ($rows as $row) {
            $size = trim($row['size']);
            $symbol = trim($row['symbol']);
            if ($size === '' || $symbol === '') {
                return null;
            }
            $at = mb_strrpos($symbol, ' ');
            if ($at === false || self::sizeKey(mb_substr($symbol, $at + 1)) !== self::sizeKey($size)) {
                return null;
            }
            $stripped = trim(mb_substr($symbol, 0, $at));
            if ($stripped === '' || ($code !== null && $stripped !== $code)) {
                return null;
            }
            $code = $stripped;
        }

        return $code;
    }

    /** Rozmiar do porównania: bez wielkości liter, z ukośnikiem jako myślnikiem („3/4” = „3-4”). */
    private static function sizeKey(string $size): string
    {
        return str_replace('/', '-', mb_strtoupper(trim($size)));
    }

    /**
     * Strona wyrobu → nazwa, kategoria, symbol i pola karty, opis, pliki, zdjęcia i rozmiary z cenami. Ceny czytane
     * są z bloku ceny konta („Twoja cena”) albo detalicznej — zależnie od tego, czyja to strona ($account).
     * Nieczytelna budowa = wyjątek z powodem (pozycja pominięta, reszta przebiegu idzie dalej).
     *
     * @return array{name: string, categories: list<string>, symbol: string, fields: list<array{0: string, 1: string}>, attributes: list<array{0: string, 1: string}>, labels: list<string>, description: string, documents: list<array{title: string, url: string, kind: string}>, images: list<string>, price_cents: int|null, tiers: list<array{label: string, cents: int}>, stock: string, rows: list<array{path: string, symbol: string, size: string, ean: string, cents: int|null, vat: string, stock: string}>}
     */
    public static function parsePage(string $html, bool $account): array
    {
        $xpath = JspB2bClient::dom($html);
        $name = self::text($xpath->query('//*['.JspB2bClient::classPredicate('kontrolka-NaglowekNazwaProduktu').']//h1')->item(0));
        if ($name === '') {
            throw new RuntimeException('strona bez nazwy wyrobu (kafel prowadzi poza kartę wyrobu?)');
        }

        $fields = self::fieldRows($xpath);
        $symbol = '';
        foreach ($fields as $i => [$label, $value]) {
            if (mb_strtolower(rtrim($label, ':')) === 'symbol') {
                $symbol = $value;
                unset($fields[$i]);
            }
        }
        if ($symbol === '') {
            throw new RuntimeException('strona wyrobu bez symbolu');
        }

        // pliki przed opisem: odnośniki do nich znikają z drzewa, żeby nazwa pliku nie weszła w opis wyrobu
        $documents = self::documentsOf($xpath);
        $tiers = self::priceTiers($xpath);
        $price = self::priceCents(self::text($xpath->query(
            '//*['.JspB2bClient::classPredicate($account ? 'ceny-twoja' : 'ceny-detaliczna').']'
            .'//*['.JspB2bClient::classPredicate('netto').']'
        )->item(0)));
        // wyrób sprzedawany progami ilościowymi (kamizelki ostrzegawcze) nie ma bloku „Twoja cena” — cena konta
        // to próg, po którym sklep sprzedaje teraz (wiersz „Twoja cena”)
        if ($price === null && $account && $tiers !== []) {
            $price = $tiers[0]['cents'];
        }

        return [
            'name' => $name,
            'categories' => self::categoryPaths($xpath),
            'symbol' => $symbol,
            'fields' => array_values($fields),
            'attributes' => self::attributeRows($xpath),
            'labels' => self::productLabels($xpath),
            'description' => self::descriptionOf($xpath),
            'documents' => $documents,
            'images' => self::galleryUrls($html, $xpath),
            'price_cents' => $price,
            'tiers' => $tiers,
            'stock' => self::stockText($xpath, $xpath->query('//*['.JspB2bClient::classPredicate('produkt-karta-stany').']')->item(0)),
            'rows' => self::variantRows($xpath, $account),
        ];
    }

    /**
     * Progi ilościowe konta („1 - 99”, „100 - 499”, …) z tabeli gradacji, w kolejności ze sklepu i z ceną netto
     * progu. Pierwszy wiersz to próg, po którym sklep sprzedaje teraz (sklep zaznacza go „Twoja cena”) — dlatego
     * wiersz bieżący idzie na początek. Wyrób bez progów = [].
     *
     * @return list<array{label: string, cents: int}>
     */
    private static function priceTiers(DOMXPath $xpath): array
    {
        $tiers = [];
        $current = null;
        foreach ($xpath->query('//table['.JspB2bClient::classPredicate('gradacje').']//tr') ?: [] as $tr) {
            if (! $tr instanceof DOMElement) {
                continue;
            }
            $cells = [];
            foreach ($xpath->query('./td', $tr) ?: [] as $td) {
                $cells[] = self::text($td);
            }
            // wiersz progu: zakres, „Zamów jeszcze”, cena netto, cena brutto
            if (count($cells) < 4) {
                continue;
            }
            $cents = self::priceCents($cells[2]);
            if ($cents === null || $cells[0] === '') {
                continue;
            }
            $tier = ['label' => $cells[0], 'cents' => $cents];
            $classes = ' '.preg_replace('/\s+/', ' ', $tr->getAttribute('class')).' ';
            if ($current === null && str_contains($classes, ' aktualna-cena ')) {
                $current = $tier;

                continue;
            }
            $tiers[] = $tier;
        }
        if ($current !== null) {
            array_unshift($tiers, $current);
        }

        return $tiers;
    }

    /**
     * Cena katalogowa (przed rabatem) z bloku głównego strony konta; null gdy sklep jej nie pokazuje.
     * Na stronie gościa tego bloku nie ma — tam ceną katalogową jest cena detaliczna.
     */
    public static function accountBaseCents(string $html): ?int
    {
        $xpath = JspB2bClient::dom($html);

        return self::priceCents(self::text($xpath->query(
            '//*['.JspB2bClient::classPredicate('ceny-przed-rabatem').']//*['.JspB2bClient::classPredicate('netto').']'
        )->item(0)));
    }

    /**
     * Cała lista; niespójna — jedno ponowne pobranie od początku.
     *
     * @return list<array{path: string, name: string, color: string, price_text: string}>
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

            throw new RuntimeException('Lista produktów '.JhkB2bClient::HOST.' niespójna także po ponownym pobraniu: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return list<array{path: string, name: string, color: string, price_text: string}>
     */
    private function scanList(): array
    {
        $started = microtime(true);
        $rows = [];
        $pages = 1;
        $pageSize = 0;

        for ($page = 1; $page <= $pages; $page++) {
            if (microtime(true) - $started > self::LIST_BUDGET_SECONDS) {
                throw new RuntimeException('Pobieranie listy '.JhkB2bClient::HOST.' trwa ponad '.(self::LIST_BUDGET_SECONDS / 60).' min — przerwane bez zapisu');
            }
            $html = $this->client->listPage($page);
            $xpath = JspB2bClient::dom($html);
            $pageCount = self::pageCount($xpath);
            $pageRows = self::listTiles($xpath);

            if ($page === 1) {
                if ($pageCount === null || $pageRows === []) {
                    throw new RuntimeException('Lista produktów '.JhkB2bClient::HOST.' pusta albo bez stronicowania — pusta lista konta albo zmiana sklepu');
                }
                $pages = $pageCount;
                $pageSize = count($pageRows);
                $this->progress('Lista produktów JHK Polska: '.$pages.' stron po '.$pageSize.' kafli');
            } elseif ($pageCount !== $pages) {
                throw new RuntimeException('liczba stron zmieniła się z '.$pages.' na '.($pageCount ?? 0).' (strona '.$page.')', self::INCONSISTENT);
            } elseif (count($pageRows) !== $pageSize && $page !== $pages) {
                throw new RuntimeException('strona '.$page.': '.count($pageRows).' kafli, oczekiwano '.$pageSize, self::INCONSISTENT);
            } elseif ($pageRows === []) {
                throw new RuntimeException('strona '.$page.' bez kafli', self::INCONSISTENT);
            }

            foreach ($pageRows as $row) {
                if (isset($rows[$row['path']])) {
                    throw new RuntimeException('wyrób '.$row['path'].' na dwóch stronach', self::INCONSISTENT);
                }
                $rows[$row['path']] = $row;
            }

            if ($page > 1 && ($page % self::PROGRESS_EVERY_PAGES === 0 || $page === $pages)) {
                $this->progress('Lista produktów JHK Polska: strona '.$page.'/'.$pages);
            }
        }

        return array_values($rows);
    }

    /** Liczba stron listy z pola stronicowania (data-max); null = strona bez stronicowania. */
    private static function pageCount(DOMXPath $xpath): ?int
    {
        foreach ($xpath->query('//*['.JspB2bClient::classPredicate('stronicowanie').']//*[@data-max]') ?: [] as $node) {
            if ($node instanceof DOMElement && preg_match('/^\d+$/', trim($node->getAttribute('data-max'))) === 1) {
                return max(1, (int) $node->getAttribute('data-max'));
            }
        }

        return null;
    }

    /**
     * Pozycje listy (bez karuzel „polecane”, które mają te same wyroby w innych kolorach): adres strony wyrobu,
     * nazwa, kolor i cena konta, jeśli lista ją pokazuje.
     *
     * Sklep ma dwa układy listy, przełączane w widoku konta: kafle (article.kaf — tak widzi ją też gość)
     * i tabelę, w której wyrób z rozmiarami jest nagłówkiem rodziny (td.naglowek-rodzina, rozmiary dociągane
     * dopiero po rozwinięciu), a wyrób bez rozmiarów zwykłym wierszem z ceną. Czytamy oba, bo przebieg nie ma
     * wpływu na to, który układ konto ma ustawiony.
     *
     * @return list<array{path: string, name: string, color: string, price_text: string}>
     */
    private static function listTiles(DOMXPath $xpath): array
    {
        $tiles = [];
        $add = static function (?DOMNode $link, ?DOMNode $scope, string $priceQuery) use (&$tiles, $xpath): void {
            if (! $link instanceof DOMElement || $scope === null) {
                return;
            }
            $path = trim(html_entity_decode($link->getAttribute('href'), ENT_QUOTES | ENT_HTML5));
            if (! str_starts_with($path, JhkB2bClient::PREFIX) || isset($tiles[$path])) {
                return;
            }
            $tiles[$path] = [
                'path' => $path,
                'name' => self::text($link),
                'color' => self::text($xpath->query('.//*['.JspB2bClient::classPredicate('produkt-opis-2linia').']', $scope)->item(0)),
                'price_text' => $priceQuery === '' ? '' : self::text($xpath->query($priceQuery, $scope)->item(0)),
            ];
        };

        // układ kafelkowy
        foreach ($xpath->query('//article['.JspB2bClient::classPredicate('kaf').']') ?: [] as $article) {
            if ($xpath->query('ancestor::*['.JspB2bClient::classPredicate('kaf-slick').']', $article)->item(0) !== null) {
                continue;
            }
            $add(
                $xpath->query('.//*['.JspB2bClient::classPredicate('nazwa-produktu').']//a[@href]', $article)->item(0),
                $article,
                './/*['.JspB2bClient::classPredicate('kaf-waluta').']//*['.JspB2bClient::classPredicate('netto').']',
            );
        }

        // układ tabeli: nagłówek rodziny rozmiarów (cena jest dopiero przy rozmiarach, więc jej tu nie ma)
        foreach ($xpath->query('//tr[td['.JspB2bClient::classPredicate('naglowek-rodzina').']]') ?: [] as $tr) {
            $add($xpath->query('.//h5//a[@href]', $tr)->item(0), $tr, '');
        }

        // układ tabeli: wyrób bez rodziny (wiersz rozmiaru rozwiniętej rodziny pomijamy — rozmiary bierzemy ze
        // strony rodziny)
        foreach ($xpath->query('//tr['.JspB2bClient::classPredicate('lista-produktow-produkt').']') ?: [] as $tr) {
            if (! $tr instanceof DOMElement || $xpath->query('self::*['.JspB2bClient::classPredicate('rodzina-dziecko').']', $tr)->item(0) !== null) {
                continue;
            }
            $add(
                $xpath->query('.//a['.JspB2bClient::classPredicate('produkt-nazwa').'][@href]', $tr)->item(0),
                $tr,
                './/td['.JspB2bClient::classPredicate('CenaPoRabacie').']//*['.JspB2bClient::classPredicate('netto').']',
            );
        }

        return array_values($tiles);
    }

    /**
     * Karty jednego koloru (jedna na każdą parę cen); strona nieczytelna albo niezgodna z kaflem — jedna pozycja
     * pominięta z powodem.
     *
     * @param  array{path: string, name: string, color: string, price_text: string}  $row
     * @return list<B2bRemoteProduct>
     */
    private function productsFor(array $row): array
    {
        try {
            $html = $this->client->productPage($row['path']);
            $page = self::parsePage($html, account: true);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return [$this->skipped($row, 'strona wyrobu: '.$e->getMessage())];
        }

        $single = $page['rows'] === [];
        $sizes = $single
            ? [[
                'path' => $row['path'],
                'symbol' => $page['symbol'],
                'size' => '',
                'ean' => self::fieldValue($page['fields'], 'Kod kreskowy EAN'),
                'cents' => $page['price_cents'],
                'vat' => '',
                'stock' => $page['stock'],
            ]]
            : $page['rows'];

        // kafel i strona muszą mówić o tym samym wyrobie: cena kafla to cena rozmiaru, do którego kafel prowadzi
        $tileCents = self::priceCents($row['price_text']);
        $opened = null;
        foreach ($sizes as $size) {
            if ($size['path'] === $row['path']) {
                $opened = $size;
            }
        }
        if ($opened === null) {
            return [$this->skipped($row, 'strona wyrobu nie pokazuje rozmiaru z listy')];
        }
        if ($tileCents !== null && $opened['cents'] !== null && $tileCents !== $opened['cents']) {
            return [$this->skipped($row, 'cena z listy („'.$row['price_text'].'”) inna niż cena rozmiaru '.$opened['symbol'].' na stronie wyrobu')];
        }

        // dwa kafle tej samej rodziny rozmiarów dałyby dwa razy tę samą kartę (ten sam symbol) — drugi pomijamy
        $family = $sizes[0]['symbol'];
        if (isset($this->families[$family])) {
            return [$this->skipped($row, 'ta sama karta co kafel '.$this->families[$family].' (rodzina '.$family.')')];
        }
        $this->families[$family] = $row['path'];

        $catalog = $this->catalogCents($row, $page, $single, $html);

        /** @var array<string, list<array{path: string, symbol: string, size: string, ean: string, cents: int|null, vat: string, stock: string}>> $groups */
        $groups = [];
        foreach ($sizes as $size) {
            if ($size['cents'] === null || $size['cents'] <= 0) {
                $this->sizesWithoutPrice[] = $size['symbol'];

                continue;
            }
            $base = $catalog[$size['symbol']] ?? null;
            // cena katalogowa niższa od ceny konta nie jest ceną przed rabatem — karta zostaje bez niej
            $groups[$size['cents'].'/'.($base !== null && $base > $size['cents'] ? $base : 'brak')][] = $size;
        }
        if ($groups === []) {
            return [$this->skipped($row, 'żaden rozmiar nie ma ceny konta')];
        }
        $code = $single ? $page['symbol'] : self::productCode($page['rows']);
        $split = count($groups) > 1;
        $this->splitProducts += $split ? 1 : 0;

        $products = [];
        foreach ($groups as $key => $group) {
            [$cents, $baseKey] = explode('/', $key, 2);
            $products[] = $this->productFor(
                $row,
                $page,
                $group,
                (int) $cents,
                $baseKey === 'brak' ? null : (int) $baseKey,
                $code,
                $split,
                $single,
            );
        }
        $this->cards += count($products);

        return $products;
    }

    /**
     * Ceny katalogowe rozmiarów: wyrób bez rozmiarów ma cenę przed rabatem na stronie konta, wyrób z rozmiarami —
     * cenę detaliczną na tej samej stronie bez logowania. Brak strony gościa albo brak rozmiaru = karta bez ceny
     * katalogowej (podsumowanie przebiegu to liczy).
     *
     * @param  array{path: string, name: string, color: string, price_text: string}  $row
     * @param  array<string, mixed>  $page
     * @return array<string, int>
     */
    private function catalogCents(array $row, array $page, bool $single, string $html): array
    {
        if ($single) {
            $base = self::accountBaseCents($html);
            if ($base !== null) {
                return [$page['symbol'] => $base];
            }
            // wyrób sprzedawany progami ilościowymi nie ma na stronie konta ceny przed rabatem — ma ją strona gościa
            if ($page['tiers'] === []) {
                return [];
            }
        }

        try {
            $guest = self::parsePage($this->client->guestProductPage($row['path']), account: false);
            if ($single) {
                return $guest['price_cents'] !== null ? [$page['symbol'] => $guest['price_cents']] : [];
            }
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $this->summaryOnce('Ceny katalogowe bez części stron gościa (np. '.$row['path'].': '.$e->getMessage().')');

            return [];
        }
        $cents = [];
        foreach ($guest['rows'] as $guestRow) {
            if ($guestRow['cents'] !== null && $guestRow['cents'] > 0) {
                $cents[$guestRow['symbol']] = $guestRow['cents'];
            }
        }

        return $cents;
    }

    /**
     * @param  array{path: string, name: string, color: string, price_text: string}  $row
     * @param  array<string, mixed>  $page
     * @param  list<array{path: string, symbol: string, size: string, ean: string, cents: int|null, vat: string, stock: string}>  $group
     */
    private function productFor(
        array $row,
        array $page,
        array $group,
        int $cents,
        ?int $baseCents,
        ?string $code,
        bool $split,
        bool $single,
    ): B2bRemoteProduct {
        $first = $group[0];
        $sku = $split || $code === null ? $first['symbol'] : $code;
        if ($baseCents === null) {
            $this->withoutBase[] = $sku;
        }

        $name = self::cardName($row['name'] !== '' ? $row['name'] : $page['name'], $row['color'], $code ?? $first['symbol']);
        $sizes = array_values(array_filter(array_column($group, 'size'), static fn (string $s): bool => $s !== ''));
        if ($split && $sizes !== []) {
            $name .= ' (rozm. '.implode(', ', $sizes).')';
        }

        $members = [];
        $summary = '';
        if (! $single) {
            foreach ($group as $size) {
                $members[] = [
                    'remote_id' => $size['symbol'],
                    'sku' => $size['symbol'],
                    'name' => trim($name.' '.$size['size']),
                ];
            }
            $summary = 'Rozmiary: '.implode('; ', array_map(
                static fn (array $s): string => ($s['size'] !== '' ? $s['size'] : '—').' ('.$s['symbol']
                    .($s['ean'] !== '' ? ', EAN '.$s['ean'] : '').')',
                $group,
            ));
        }

        $eans = array_values(array_filter(array_column($group, 'ean'), static fn (string $e): bool => $e !== ''));
        $fields = $page['fields'];
        if ($single === false) {
            // EAN i rozmiar z bloku otwartego rozmiaru dotyczą jednego rozmiaru — karta ma je z tabeli wariantów
            $fields = array_values(array_filter(
                $fields,
                static fn (array $field): bool => mb_strtolower(rtrim($field[0], ':')) !== 'kod kreskowy ean',
            ));
        }

        $symbols = $single ? $page['symbol'] : implode('; ', array_column($group, 'symbol'));

        return new B2bRemoteProduct(
            remoteId: $first['symbol'],
            sku: $sku,
            name: $name,
            category: $page['categories'][0] ?? null,
            sourceUrl: JhkB2bClient::BASE.$row['path'],
            raw: [
                'status' => 'ok',
                'price' => (float) $cents / 100,
                'base_price' => $baseCents !== null ? (float) $baseCents / 100 : null,
                'symbol' => $symbols,
                'description' => $page['description'],
                'fields' => $single ? $fields : array_merge($fields, $eans !== [] ? [['EAN', implode('; ', array_map(
                    static fn (array $s): string => $s['size'].': '.$s['ean'],
                    array_values(array_filter($group, static fn (array $s): bool => $s['ean'] !== '')),
                ))]] : []),
                'attributes' => self::cardAttributes($page['attributes'], $single),
                'category_path' => implode(' | ', $page['categories']),
                // progi tylko na karcie wyrobu bez rozmiarów — tam, gdzie sklep je pokazuje
                'tiers' => $single ? $page['tiers'] : [],
                'vat' => $first['vat'],
                'stock' => array_map(
                    static fn (array $s): string => ($s['size'] !== '' ? $s['size'].': ' : '').$s['stock'],
                    $group,
                ),
                'labels' => $page['labels'],
                'documents' => $page['documents'],
                'image_urls' => $page['images'],
            ],
            availability: self::groupAvailability($group),
            variantSummary: $summary,
            members: $members,
            identifiers: self::identifiers($group, $single, self::isMoontex($name, $sku, $symbols)),
        );
    }

    /**
     * Symbol i EAN każdego rozmiaru karty; pozycja = symbol rozmiaru (remote_id powiązania), a wyrób bez rozmiarów
     * ma jedną pozycję — swój symbol, zarazem remoteId karty. Symbol to własny kod sklepu producenta: przy wyrobie
     * JHK jest kodem producenta, przy MOONTEX (inna marka w tym sklepie) tylko kodem źródła. Kod wyrobu bez rozmiaru
     * (productCode) składamy sami z symboli — nie jest identyfikatorem ze źródła i tu nie trafia.
     *
     * @param  list<array{path: string, symbol: string, size: string, ean: string, cents: int|null, vat: string, stock: string}>  $group
     * @return list<B2bRemoteIdentifier>
     */
    private static function identifiers(array $group, bool $single, bool $moontex): array
    {
        $codeType = $moontex ? ProductIdentifier::TYPE_SOURCE_CODE : ProductIdentifier::TYPE_MANUFACTURER_CODE;
        $out = [];
        foreach ($group as $size) {
            $position = $size['symbol'];
            $label = $size['size'] !== '' ? $size['size'] : null;
            $out[] = new B2bRemoteIdentifier(type: $codeType, value: $position, remoteId: $position, label: $label, field: 'Symbol');
            if ($size['ean'] !== '') {
                $out[] = new B2bRemoteIdentifier(
                    type: ProductIdentifier::TYPE_EAN,
                    value: $size['ean'],
                    remoteId: $position,
                    label: $label,
                    // wyrób bez rozmiarów ma EAN w polu karty, rozmiar — w tabeli wariantów („EAN:”)
                    field: $single ? 'Kod kreskowy EAN' : 'EAN',
                );
            }
        }

        return $out;
    }

    /**
     * Cechy karty: wiersz „Produkt niekupiony” to historia zakupów konta, nie wyrób; rozmiar z otwartej strony
     * dotyczy jednego rozmiaru, a karta z rozmiarami ma je w podsumowaniu rozmiarów.
     *
     * @param  list<array{0: string, 1: string}>  $attributes
     * @return list<array{0: string, 1: string}>
     */
    private static function cardAttributes(array $attributes, bool $single): array
    {
        $skip = [mb_strtolower(self::BOUGHT_ATTRIBUTE)];
        if (! $single) {
            $skip[] = 'rozmiar';
        }

        return array_values(array_filter(
            $attributes,
            static fn (array $pair): bool => ! in_array(mb_strtolower(rtrim($pair[0], ':')), $skip, true),
        ));
    }

    /** „JHK Bluza JT SWCR BK, BK - Black” — marka tylko wtedy, gdy nazwa i kod jej nie mają. */
    private static function cardName(string $name, string $color, string $code): string
    {
        $name = self::clean($name);
        $text = mb_strtoupper($name.' '.$code);
        if (! str_contains($text, self::BRAND) && ! str_contains($text, self::BRAND_MOONTEX)) {
            $name = self::BRAND.' '.$name;
        }

        return $color !== '' ? $name.', '.$color : $name;
    }

    /**
     * @param  array{path: string, name: string, color: string, price_text: string}  $row
     */
    private function skipped(array $row, string $reason): B2bRemoteProduct
    {
        $this->skippedProducts++;
        $name = $row['name'] !== '' ? $row['name'] : $row['path'];

        return new B2bRemoteProduct(
            remoteId: $row['path'],
            sku: $name,
            name: $name,
            sourceUrl: JhkB2bClient::BASE.$row['path'],
            raw: ['status' => 'skipped', 'reason' => $reason],
        );
    }

    /**
     * Jeden stan dla wszystkich rozmiarów — dosłownie; różne — „Magazyn w Polsce - dostępne 24 h: XS, S; Brak na
     * stanie: 3XL”.
     *
     * @param  list<array{path: string, symbol: string, size: string, ean: string, cents: int|null, vat: string, stock: string}>  $group
     */
    private static function groupAvailability(array $group): ?string
    {
        $statuses = [];
        foreach ($group as $size) {
            $statuses[$size['stock']][] = $size['size'] !== '' ? $size['size'] : $size['symbol'];
        }
        if (count($statuses) === 1) {
            $only = (string) array_key_first($statuses);

            return $only !== '' ? $only : null;
        }
        $parts = [];
        foreach ($statuses as $status => $sizes) {
            $parts[] = ($status !== '' ? $status : 'brak informacji').': '.implode(', ', $sizes);
        }

        return implode('; ', $parts);
    }

    /**
     * Wiersze tabeli wariantów: rozmiar, symbol, EAN, cena (konta albo detaliczna) i stan magazynowy.
     * Brak tabeli = [] (wyrób bez rozmiarów).
     *
     * @return list<array{path: string, symbol: string, size: string, ean: string, cents: int|null, vat: string, stock: string}>
     */
    private static function variantRows(DOMXPath $xpath, bool $account): array
    {
        $rows = [];
        $priceClass = $account ? 'CenaPoRabacie' : 'CenaProduktu';
        foreach ($xpath->query('//table['.JspB2bClient::classPredicate('lista-wariantow').']//tr['.JspB2bClient::classPredicate('rodzina-dziecko').']') ?: [] as $tr) {
            $link = $xpath->query('.//a[@href]', $tr)->item(0);
            $path = $link instanceof DOMElement ? trim(html_entity_decode($link->getAttribute('href'), ENT_QUOTES | ENT_HTML5)) : '';
            $symbol = self::labelledValue(self::text($xpath->query('.//*['.JspB2bClient::classPredicate('kod').']', $tr)->item(0)), 'Symbol:');
            if ($symbol === '') {
                throw new RuntimeException('wiersz tabeli rozmiarów bez symbolu');
            }
            $rows[] = [
                'path' => $path,
                'symbol' => $symbol,
                'size' => self::text($xpath->query('.//*['.JspB2bClient::classPredicate('rodzina-dziecko-cechy').']//*['.JspB2bClient::classPredicate('atrybut-opis').']', $tr)->item(0)),
                'ean' => self::labelledValue(self::text($xpath->query('.//*['.JspB2bClient::classPredicate('kod-kreskowy').']', $tr)->item(0)), 'EAN:'),
                'cents' => self::priceCents(self::text($xpath->query(
                    './/td['.JspB2bClient::classPredicate($priceClass).']//*['.JspB2bClient::classPredicate('netto').']',
                    $tr,
                )->item(0))),
                'vat' => self::clean(str_replace('VAT', '', self::text($xpath->query('.//*['.JspB2bClient::classPredicate('produkt-cena-klienta-vat').']', $tr)->item(0)))),
                'stock' => self::stockText($xpath, $xpath->query('.//td['.JspB2bClient::classPredicate('kolumna-stany-produktow').']', $tr)->item(0)),
            ];
        }

        return $rows;
    }

    /**
     * Stan magazynowy dosłownie z podpowiedzi sklepu: „Magazyn w Polsce - dostępne 24 h: 45 szt.; Magazyn producenta
     * - dostępne 14 dni: 216 szt.”. Sklep pokazuje tylko magazyny ze sztukami — brak obu = „Brak na stanie”.
     */
    private static function stockText(DOMXPath $xpath, ?DOMNode $cell): string
    {
        if ($cell === null) {
            return '';
        }
        $parts = [];
        foreach ($xpath->query('.//*['.JspB2bClient::classPredicate('stany').']', $cell) ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $classes = ' '.preg_replace('/\s+/', ' ', $node->getAttribute('class')).' ';
            $name = str_contains($classes, ' centrala ') ? self::STOCK_CENTRAL : self::STOCK_LOCAL;
            $quantity = self::clean(str_replace('24H', '', self::text($node)));
            if ($quantity !== '') {
                $parts[] = $name.': '.$quantity.' szt.';
            }
        }

        return $parts === [] ? self::STOCK_NONE : implode('; ', $parts);
    }

    /**
     * Pola karty („Symbol”, „Kod kreskowy EAN”, „Kod HS”, „Waga:”) — etykieta i wartość z dwóch kolumn wiersza.
     * Pole opisu ma inną budowę i tutaj nie trafia.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function fieldRows(DOMXPath $xpath): array
    {
        $fields = [];
        foreach ($xpath->query('//*['.JspB2bClient::classPredicate('kontrolka-PoleProduktu').']') ?: [] as $control) {
            if ($xpath->query('.//*['.JspB2bClient::classPredicate('pole-opis-wartosc').']', $control)->item(0) !== null) {
                continue;
            }
            $cells = $xpath->query('.//*['.JspB2bClient::classPredicate('row').']/*', $control);
            if ($cells === false || $cells->length < 2) {
                continue;
            }
            $label = rtrim(self::text($cells->item(0)), ':');
            $value = self::text($cells->item(1));
            if ($label !== '' && $value !== '') {
                $fields[] = [$label, $value];
            }
        }

        return $fields;
    }

    /**
     * Cechy wyrobu (Kolor, Rozmiar, także „Produkt niekupiony”) — pary etykieta/wartość jedna po drugiej.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function attributeRows(DOMXPath $xpath): array
    {
        $pairs = [];
        $label = null;
        foreach ($xpath->query('//*['.JspB2bClient::classPredicate('lista-atrybuty').']//*['.JspB2bClient::classPredicate('row').']/*') ?: [] as $cell) {
            if (! $cell instanceof DOMElement) {
                continue;
            }
            $classes = ' '.preg_replace('/\s+/', ' ', $cell->getAttribute('class')).' ';
            if (str_contains($classes, ' atrybut-nazwa ')) {
                $label = rtrim(self::text($cell), ':');
            } elseif (str_contains($classes, ' atrybut-cechy ') && $label !== null) {
                $value = self::text($cell);
                if ($label !== '' && $value !== '') {
                    $pairs[] = [$label, $value];
                }
                $label = null;
            }
        }

        return $pairs;
    }

    /**
     * Ścieżki kategorii sklepu, każda osobno („Bluzy Dresowe > Męskie > JHK JT SWEATSHIRT CR”). Wyrób bywa
     * w kilku miejscach drzewa (kamizelka polarowa HV jest w Polarach i w JHK PROFESSIONAL).
     *
     * @return list<string>
     */
    private static function categoryPaths(DOMXPath $xpath): array
    {
        $paths = [];
        foreach ($xpath->query('//*['.JspB2bClient::classPredicate('kontrolka-KategorieProduktu').']//*['.JspB2bClient::classPredicate('atrybut-opis').']') ?: [] as $node) {
            $parts = [];
            foreach ($xpath->query('.//a', $node) ?: [] as $link) {
                $text = self::text($link);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
            $path = implode(' > ', $parts);
            if ($path !== '' && ! in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * Oznaczenia kafla wyrobu („NOWOŚĆ”, „Towar dostępny pod zamówienie specjalne ok. 10-14 dni”).
     *
     * @return list<string>
     */
    private static function productLabels(DOMXPath $xpath): array
    {
        $labels = [];
        foreach ($xpath->query('//*['.JspB2bClient::classPredicate('metka-produktu').']') ?: [] as $node) {
            $text = self::text($node);
            if ($text !== '' && ! in_array($text, $labels, true)) {
                $labels[] = $text;
            }
        }

        return $labels;
    }

    /** Opis ze sklepu, z liniami ze źródła; lista załączników („Załączniki:” i dalej) do opisu nie wchodzi. */
    private static function descriptionOf(DOMXPath $xpath): string
    {
        $node = $xpath->query('//*['.JspB2bClient::classPredicate('pole-opis-wartosc').']')->item(0);
        if ($node === null) {
            return '';
        }

        $lines = [];
        foreach (self::blockLines($node) as $line) {
            if (rtrim($line, ':') === self::ATTACHMENTS_HEADING) {
                break;
            }
            $lines[] = $line;
        }

        return mb_substr(implode("\n", $lines), 0, 10000);
    }

    /**
     * Pliki wyrobu: blok „Karty do pobrania” i odnośniki do plików w opisie (linia „Załączniki”). Przy komplecie
     * językowym (…-pl, …-en, …-de) zostaje wersja polska.
     *
     * @return list<array{title: string, url: string, kind: string}>
     */
    private static function documentsOf(DOMXPath $xpath): array
    {
        $documents = [];
        $nodes = [
            ...iterator_to_array($xpath->query('//*['.JspB2bClient::classPredicate('pliki-do-produktu').']//a[@href]') ?: [], false),
            ...iterator_to_array($xpath->query('//*['.JspB2bClient::classPredicate('pole-opis-wartosc').']//a[@href]') ?: [], false),
        ];
        foreach ($nodes as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }
            $url = self::fileUrl($link->getAttribute('href'));
            if ($url === null) {
                continue;
            }
            $file = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));
            $extension = mb_strtolower(pathinfo($file, PATHINFO_EXTENSION));
            // plikiem karty jest tylko adres pliku — odnośnik do innej strony sklepu zostaje w opisie
            if (! in_array($extension, self::DOCUMENT_EXTENSIONS, true)) {
                continue;
            }
            $title = self::clean((string) preg_replace('/[\x{1F300}-\x{1FAFF}\x{2190}-\x{27BF}\x{FE0F}]/u', '', self::text($link)));
            if (! isset($documents[$url])) {
                $documents[$url] = [
                    'title' => mb_substr($title !== '' ? $title : $file, 0, 255),
                    'url' => $url,
                    'kind' => self::documentKind($file, $extension),
                ];
            }
            // odnośnik do pliku w opisie („Karta produktu dostępna tutaj!”) to nie opis wyrobu
            $link->parentNode?->removeChild($link);
        }

        return array_slice(array_values(self::withoutForeignCopies($documents)), 0, self::DOCUMENTS_LIMIT);
    }

    /**
     * Ten sam plik w kilku językach („…-karta-produktu-pl/-en/-de”) → zostaje polski. Plik bez polskiego
     * odpowiednika zostaje bez zmian (wielojęzyczne „HV06U01_PL_EN_DE.pdf” to jeden plik).
     *
     * @param  array<string, array{title: string, url: string, kind: string}>  $documents
     * @return array<string, array{title: string, url: string, kind: string}>
     */
    private static function withoutForeignCopies(array $documents): array
    {
        $polish = [];
        foreach ($documents as $document) {
            $file = mb_strtolower(rawurldecode(basename((string) parse_url($document['url'], PHP_URL_PATH))));
            $name = pathinfo($file, PATHINFO_FILENAME);
            if (preg_match('/^(.*)[-_]pl$/u', $name, $m) === 1) {
                $polish[$m[1]] = true;
            }
        }
        if ($polish === []) {
            return $documents;
        }

        return array_filter($documents, static function (array $document) use ($polish): bool {
            $file = mb_strtolower(rawurldecode(basename((string) parse_url($document['url'], PHP_URL_PATH))));
            $name = pathinfo($file, PATHINFO_FILENAME);
            if (preg_match('/^(.*)[-_]('.implode('|', self::FOREIGN_SUFFIXES).')$/u', $name, $m) !== 1) {
                return true;
            }

            return ! isset($polish[$m[1]]);
        });
    }

    private static function documentKind(string $file, string $extension): string
    {
        $name = mb_strtolower($file);
        if (str_contains($name, 'certyfikat') || str_contains($name, 'certificate') || str_contains($name, 'deklaracj') || str_contains($name, 'declaration')) {
            return ProductDocument::KIND_CERTIFICATE;
        }
        if (str_contains($name, 'instrukcj') || str_contains($name, 'manual')) {
            return ProductDocument::KIND_MANUAL;
        }
        if (str_contains($name, 'rozmiar') || str_contains($name, 'size')) {
            return ProductDocument::KIND_SIZE_CHART;
        }

        return $extension === 'pdf' ? ProductDocument::KIND_DATASHEET : ProductDocument::KIND_OTHER;
    }

    /**
     * Zdjęcia galerii: adresy plików (fancyboxUrl) z JSON-a galerii, w kolejności ze sklepu. JSON jest w <script>,
     * którego parser DOM nie widzi — czytamy go z treści strony. Brak galerii = główne zdjęcie karty.
     *
     * @return list<string>
     */
    private static function galleryUrls(string $html, DOMXPath $xpath): array
    {
        $urls = [];
        if (preg_match_all('/"fancyboxUrl"\s*:\s*"([^"]+)"/', $html, $matches) === false) {
            $matches = [1 => []];
        }
        foreach ($matches[1] as $raw) {
            $url = self::fileUrl(stripslashes($raw));
            if ($url !== null && self::isImageUrl($url)) {
                $urls[$url] = true;
            }
            if (count($urls) >= self::IMAGES_LIMIT) {
                break;
            }
        }
        if ($urls === []) {
            $main = $xpath->query('//*['.JspB2bClient::classPredicate('product--gallery--main--image').']//img[@src]')->item(0);
            $url = $main instanceof DOMElement ? self::fileUrl($main->getAttribute('src')) : null;
            if ($url !== null && self::isImageUrl($url)) {
                // adres miniatury ma parametry podglądu (?preset=…) — plik jest pod adresem bez nich
                $urls[explode('?', $url)[0]] = true;
            }
        }

        return array_keys($urls);
    }

    private static function isImageUrl(string $url): bool
    {
        $extension = mb_strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return in_array($extension, self::IMAGE_EXTENSIONS, true);
    }

    /**
     * Adres pliku ze sklepu (względny albo pełny) → pełny adres; null = adres spoza sklepu albo pusty.
     * Nazwy plików mają spacje i polskie znaki — segmenty ścieżki kodujemy raz (dekodowanie przed kodowaniem).
     */
    public static function fileUrl(string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));
        if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:')) {
            return null;
        }

        $url = preg_match('#^https?://#i', $href) === 1 ? $href : JhkB2bClient::BASE.'/'.ltrim($href, '/');
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return null;
        }
        $path = implode('/', array_map(
            static fn (string $segment): string => rawurlencode(rawurldecode($segment)),
            explode('/', $parts['path']),
        ));
        $url = $parts['scheme'].'://'.$parts['host'].$path.(isset($parts['query']) ? '?'.$parts['query'] : '');

        return JhkB2bClient::isShopUrl($url) ? $url : null;
    }

    /**
     * Element → linie tekstu: nowa linia przy <br>, elementach blokowych i znaku nowej linii w tekście; pozycja
     * listy jako „- …”. Białe znaki w linii zwinięte, puste linie pominięte.
     *
     * @return list<string>
     */
    private static function blockLines(DOMNode $root): array
    {
        $lines = [];
        $buffer = '';
        $flush = static function () use (&$lines, &$buffer): void {
            foreach (preg_split('/\R/u', $buffer) ?: [] as $part) {
                $line = trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $part));
                if ($line !== '' && $line !== '-') {
                    $lines[] = $line;
                }
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

    /** „Symbol: JT SWCR BK XS” → „JT SWCR BK XS”; tekst bez etykiety zostaje bez zmian. */
    private static function labelledValue(string $text, string $label): string
    {
        $text = self::clean($text);

        return str_starts_with($text, $label) ? trim(mb_substr($text, mb_strlen($label))) : $text;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $fields
     */
    private static function fieldValue(array $fields, string $label): string
    {
        foreach ($fields as [$name, $value]) {
            if (mb_strtolower(rtrim($name, ':')) === mb_strtolower($label)) {
                return $value;
            }
        }

        return '';
    }

    private function summaryOnce(string $line): void
    {
        if (! in_array($line, $this->summary, true)) {
            $this->summary[] = $line;
        }
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
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $value));
    }

    private static function text(?DOMNode $node): string
    {
        return $node === null ? '' : self::clean($node->textContent);
    }
}
