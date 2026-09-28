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
 * koloru — raz na kolor, kolory jednego wyrobu jeden po drugim; dwie pozycje listy prowadzące do tej samej rodziny
 * rozmiarów dają jedną kartę.
 *
 * Ceny: strona konta podaje w tabeli wariantów „Twoją cenę” każdego rozmiaru (cena zakupu). Ceny katalogowej
 * (detalicznej) sklep zalogowanemu podaje tylko przy rozmiarze otwartej karty, więc tę samą stronę pobieramy
 * drugi raz bez logowania — tam każdy rozmiar ma „Cenę detaliczną”. Wyrób bez rozmiarów (czapka, koc) ma obie ceny
 * w bloku głównym strony konta i drugiego pobrania nie wymaga. Wyjątek: wyrób sprzedawany progami ilościowymi
 * (kamizelki ostrzegawcze, narzuty — tabela „Progi cenowe”) nie ma bloku „Twoja cena” ani ceny przed rabatem;
 * ceną konta jest próg, po którym sklep sprzedaje teraz („Twoja cena” w tabeli), a cena katalogowa pochodzi ze
 * strony gościa. Pozostałe progi idą do tabelki sklepu — w przetargu cena zależy od zamawianej ilości.
 * Cena katalogowa rozmiaru niższa od jego ceny konta albo rozmiar, którego strona gościa nie zna, to rozmiar bez ceny
 * katalogowej (liczone w podsumowaniu przebiegu) — ceny innego rozmiaru nie przepisujemy.
 *
 * Karta = wyrób ze wszystkimi kolorami i rozmiarami z ceną konta (decyzje użytkownika 28.09.2026: rozmiary w różnych
 * cenach to jedna karta — w JHK typowo XS–XXL w jednej cenie, 3XL droższy; wieczorem: kolory jednego wyrobu też).
 * Kolory łączą się ostrożnie (modelBatches, colourModel, colourCard): ten sam kod wyrobu bez koloru („FLRA 340” z „FLRA
 * 340 BK/BK”, „FLRA 340 CM”), ta sama nazwa z listy bez koloru, ta sama pierwsza kategoria sklepu i ten sam rodzaj
 * wyrobu (z rozmiarami albo bez); kolor musi być kodem z listy („BK - Black”) i ostatnim słowem kodu wyrobu. Wszystko
 * inne (kolor w środku kodu „CZA 5P BK ZAP MET”, kolor bez kodu „Jasny szary”, powtórzony kolor) zostaje kartą koloru
 * jak dotąd. Do 28.09.2026 karta była kolorem, a do tego dnia (decyzja 15.09.2026) rozmiar w innej cenie osobną kartą
 * z kodem pierwszego rozmiaru („JT SWCR BK 3XL”) — takie karty zostają, dopóki nie scali ich „Scal rozmiary”
 * (synchronizacja daje każdej jej pozycje, B2bCatalogSync::syncMembersByCard).
 *
 * SKU karty koloru: kod wyrobu bez rozmiaru („JT SWCR BK”), gdy wszystkie rozmiary mają ten sam kod z dopisanym
 * rozmiarem; inaczej symbol pierwszego rozmiaru. SKU karty z kolorami: kod bez koloru („JT SWCR”), nazwa nowej karty
 * bez koloru (cardName), nazwa ze źródła = nazwa koloru prowadzącego (pierwszego na liście). Pozycje (members)
 * i remote_id = symbole rozmiarów (sklep ma je na każdej karcie; wyrób bez rozmiarów — jego symbol), z rozmiarem
 * („BK - Black / XS” na karcie z kolorami), stanem i ceną pozycji (konta i katalogowa); cena karty = najniższa cena
 * pozycji (raw['price'], price()), pozostałe ceny — wiersze wariantów karty. Sklep podaje ceny tylko w PLN (inny zapis
 * = rozmiar bez ceny) i nie podaje jednostki sprzedaży przy rozmiarze — tabela wariantów to rozmiary jednego wyrobu.
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
final class JhkB2bConnector implements B2bConnector, B2bDocumentSource, B2bGroupsSizes, B2bImageGallery, B2bListProgressAware, B2bManufacturerSite, B2bRunSummaryAware, B2bShopFieldSource, B2bSizePriceSource
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

    /** Dostępność karty z kolorami dłuższa niż tyle znaków = liczby wierszy w magazynach (colourAvailability). */
    private const AVAILABILITY_LIMIT = 1000;

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

    /** @var list<string> rozmiary (symbole) bez ceny katalogowej */
    private array $withoutBase = [];

    /** @var list<string> symbole rozmiarów bez ceny konta (poza kartami swojego koloru) */
    private array $sizesWithoutPrice = [];

    /** @var array<string, true> karty z producentem MOONTEX */
    private array $moontex = [];

    /** @var array<string, string> rodziny rozmiarów już wydane: symbol pierwszego rozmiaru => adres kafla */
    private array $families = [];

    private int $cards = 0;

    /** kolory z rozmiarami w różnych cenach (jedna karta, cena od najniższej) */
    private int $multiPriceProducts = 0;

    private int $skippedProducts = 0;

    /** Karty z kilkoma kolorami jednego wyrobu w przebiegu i liczba ich kolorów (runSummary). */
    private int $colourCards = 0;

    private int $colourCount = 0;

    /** @var array<string, true> SKU kart wydanych w przebiegu (małymi literami) — kod wyrobu bez koloru nie może ich powtórzyć */
    private array $skus = [];

    /** @var array<string, int> wyroby, których kolory mają różne opisy (osobne karty): klucz wyrobu => liczba kolorów */
    private array $descriptionSplits = [];

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
        $this->multiPriceProducts = 0;
        $this->skippedProducts = 0;
        $this->colourCards = 0;
        $this->colourCount = 0;
        $this->skus = [];
        $this->descriptionSplits = [];

        if (! $this->client->isLoggedIn()) {
            $this->client->login();
        }

        $rows = $this->listRows();
        // na start każda pozycja listy to karta; kolory złączone w jedną kartę zmniejszają liczbę w trakcie przebiegu
        $this->total = count($rows);
        $this->summary[] = 'Lista JHK Polska: '.count($rows).' wyrobów w kolorach';

        foreach (self::modelBatches($rows) as $batch) {
            if (count($batch) === 1) {
                yield $this->productFor($batch[0]);

                continue;
            }
            $products = $this->batchProducts($batch);
            $this->total -= count($batch) - count($products);
            foreach ($products as $product) {
                yield $product;
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
        $lines[] = sprintf(
            'Karty: %d (%d wyrobów z rozmiarami w różnych cenach — jedna karta, cena karty = najniższa cena rozmiaru, ceny rozmiarów w tabeli rozmiarów karty; %d pozycji pominiętych)',
            $this->cards,
            $this->multiPriceProducts,
            $this->skippedProducts,
        );
        if ($this->moontex !== []) {
            $lines[] = 'Karty z producentem MOONTEX (reszta: JHK): '.count($this->moontex);
        }
        if ($this->withoutBase !== []) {
            $lines[] = 'Bez ceny katalogowej (sklep bez logowania nie podał ceny detalicznej rozmiaru albo podał niższą od ceny konta; symbole rozmiarów): '.self::listing($this->withoutBase);
        }
        if ($this->sizesWithoutPrice !== []) {
            $lines[] = 'Rozmiary bez ceny konta (poza kartami): '.self::listing($this->sizesWithoutPrice);
        }
        if ($this->colourCards > 0) {
            $lines[] = 'Warianty kolorystyczne JHK: '.$this->colourCards.' wyrobów z '.$this->colourCount.' kolorów (jedna karta z tabelą kolorów i rozmiarów)';
        }
        if ($this->descriptionSplits !== []) {
            $lines[] = sprintf(
                'Kolory jednego wyrobu z różnymi opisami — karty osobno: %d wyrobów (%d kolorów), np. %s',
                count($this->descriptionSplits),
                array_sum($this->descriptionSplits),
                implode(', ', array_map(static fn (string $key): string => explode('|', $key)[1], array_slice(array_keys($this->descriptionSplits), 0, 10))),
            );
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
        $base = $product->raw['base_price'] ?? null;

        return self::accountPrice((float) $product->raw['price'], is_float($base) ? $base : null);
    }

    /** Cena konta z ceną katalogową (gdy jest) i rabatem wyliczonym z obu — karta i każdy rozmiar tak samo. */
    private static function accountPrice(float $net, ?float $base): B2bRemotePrice
    {
        return new B2bRemotePrice(
            net: round($net, 2),
            base: $base !== null ? round($base, 2) : null,
            discountPercent: $base !== null && $base > 0 ? round((1 - $net / $base) * 100, 2) : 0.0,
        );
    }

    /**
     * Najtańszy rozmiar: najniższa cena konta; przy remisie reguła B2bCatalogSync::winsSizePriceTie (wygrywa rozmiar ze
     * znaną ceną katalogową, z dwóch znanych niższa), dalej pierwszy w tabeli — tak samo jak w silniku, żeby price()
     * i cena karty z pozycji się zgadzały.
     *
     * @param  non-empty-list<array{cents: int, base: int|null}>  $group
     * @return array{cents: int, base: int|null}
     */
    private static function cheapest(array $group): array
    {
        $best = $group[0];
        foreach ($group as $size) {
            if ($size['cents'] < $best['cents']
                || ($size['cents'] === $best['cents'] && B2bCatalogSync::winsSizePriceTie(
                    $size['base'] !== null ? $size['base'] / 100 : null,
                    $best['base'] !== null ? $best['base'] / 100 : null,
                ))) {
                $best = $size;
            }
        }

        return $best;
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
        $raw = self::colourRaw($product);
        if (($raw['status'] ?? null) !== 'ok') {
            return [];
        }

        $fields = [];
        $add = static function (string $section, string $name, string $value) use (&$fields): void {
            if ($name !== '' && $value !== '') {
                $fields[] = new B2bRemoteShopField($section, $name, $value);
            }
        };

        // karta z kilkoma kolorami: symbole i EAN-y są w wierszach wariantów i identyfikatorach pozycji — lista wszystkich
        // kolorów i rozmiarów w jednym polu tabelki byłaby przycięta (ProductShopCard::MAX_VALUE_CHARS), a więc niepełna
        if (count($raw['colours'] ?? []) <= 1) {
            $add(self::SHOP_SECTION_MARKS, 'Symbol', (string) ($raw['symbol'] ?? ''));
        }
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
            self::colourRaw($product)['documents'] ?? [],
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
     * Karta z kolorami — jedno (główne) zdjęcie na kolor, karta jednego koloru — jego galeria (colourRaw).
     *
     * @return list<string>
     */
    public function imageUrls(B2bRemoteProduct $product): array
    {
        return self::colourRaw($product)['image_urls'] ?? [];
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
     * Karta jednego koloru ze wszystkimi rozmiarami z ceną konta; strona nieczytelna albo niezgodna z kaflem —
     * pozycja pominięta z powodem.
     *
     * @param  array{path: string, name: string, color: string, price_text: string}  $row
     */
    private function productFor(array $row): B2bRemoteProduct
    {
        $part = $this->prepared($row);

        return $part instanceof B2bRemoteProduct ? $part : $this->singleColourCard($part);
    }

    /**
     * Karta koloru z odczytanej strony (jak do 28.09.2026: karta = kolor) — gdy wyrób nie ma na liście innych kolorów
     * albo nie da się ich pewnie złączyć (colourModel).
     *
     * @param  array{row: array{path: string, name: string, color: string, price_text: string}, page: array<string, mixed>, priced: non-empty-list<array{path: string, symbol: string, size: string, ean: string, cents: int, base: int|null, vat: string, stock: string}>, code: string|null, single: bool}  $part
     */
    private function singleColourCard(array $part): B2bRemoteProduct
    {
        $this->multiPriceProducts += count(array_unique(array_column($part['priced'], 'cents'))) > 1 ? 1 : 0;
        $this->cards++;
        // kod jak dotąd; gdy przebieg już go wydał (np. karcie z kolorami jako kod bez koloru) — symbol pierwszego
        // rozmiaru, jak karta bez wspólnego kodu wyrobu
        $sku = $this->uniqueSku(array_values(array_unique(array_filter(
            [(string) $part['code'], $part['priced'][0]['symbol']],
            static fn (string $candidate): bool => $candidate !== '',
        ))));

        return $this->cardFor($part['row'], $part['page'], $part['priced'], $sku, $part['single']);
    }

    /**
     * Strona koloru z listy → rozmiary z ceną konta i katalogową, kod wyrobu (bez rozmiaru) i znacznik wyrobu bez
     * rozmiarów; strona nieczytelna albo niezgodna z kaflem = pozycja pominięta z powodem (B2bRemoteProduct).
     *
     * @param  array{path: string, name: string, color: string, price_text: string}  $row
     * @return B2bRemoteProduct|array{row: array{path: string, name: string, color: string, price_text: string}, page: array<string, mixed>, priced: non-empty-list<array{path: string, symbol: string, size: string, ean: string, cents: int, base: int|null, vat: string, stock: string}>, code: string|null, single: bool}
     */
    private function prepared(array $row): B2bRemoteProduct|array
    {
        try {
            $html = $this->client->productPage($row['path']);
            $page = self::parsePage($html, account: true);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            // strona nieodczytana (błąd pobrania albo budowy) — nie wiemy, czy kolor nadal jest w sklepie (batchProducts)
            return $this->skipped($row, 'strona wyrobu: '.$e->getMessage(), readError: true);
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
            return $this->skipped($row, 'strona wyrobu nie pokazuje rozmiaru z listy');
        }
        if ($tileCents !== null && $opened['cents'] !== null && $tileCents !== $opened['cents']) {
            return $this->skipped($row, 'cena z listy („'.$row['price_text'].'”) inna niż cena rozmiaru '.$opened['symbol'].' na stronie wyrobu');
        }

        // dwa kafle tej samej rodziny rozmiarów dałyby dwa razy tę samą kartę (ten sam symbol) — drugi pomijamy
        $family = $sizes[0]['symbol'];
        if (isset($this->families[$family])) {
            return $this->skipped($row, 'ta sama karta co kafel '.$this->families[$family].' (rodzina '.$family.')');
        }
        $this->families[$family] = $row['path'];

        $catalog = $this->catalogCents($row, $page, $single, $html);

        /** @var list<array{path: string, symbol: string, size: string, ean: string, cents: int, base: int|null, vat: string, stock: string}> $priced */
        $priced = [];
        foreach ($sizes as $size) {
            if ($size['cents'] === null || $size['cents'] <= 0) {
                $this->sizesWithoutPrice[] = $size['symbol'];

                continue;
            }
            $base = $catalog[$size['symbol']] ?? null;
            // cena katalogowa rozmiaru niższa od jego ceny konta nie jest ceną przed rabatem — rozmiar zostaje bez niej
            $priced[] = [...$size, 'cents' => $size['cents'], 'base' => $base !== null && $base > $size['cents'] ? $base : null];
        }
        if ($priced === []) {
            return $this->skipped($row, 'żaden rozmiar nie ma ceny konta');
        }
        foreach ($priced as $size) {
            if ($size['base'] === null) {
                $this->withoutBase[] = $size['symbol'];
            }
        }

        return [
            'row' => $row,
            'page' => $page,
            'priced' => $priced,
            'code' => $single ? $page['symbol'] : self::productCode($page['rows']),
            'single' => $single,
        ];
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
     * Karta koloru: rozmiary z ceną konta jako pozycje (members), każda ze swoją ceną konta i — gdy strona gościa ją
     * podała dla tego rozmiaru i jest wyższa od ceny konta — swoją ceną katalogową. Cena karty (raw price/base_price)
     * = najtańszy rozmiar (remis — self::cheapest), tak jak liczy ją B2bCatalogSync.
     * Wyrób bez rozmiarów (czapka, kamizelka z progami) ma jedną pozycję — siebie — i members = [] jak dotąd.
     *
     * @param  array{path: string, name: string, color: string, price_text: string}  $row
     * @param  array<string, mixed>  $page
     * @param  list<array{path: string, symbol: string, size: string, ean: string, cents: int, base: int|null, vat: string, stock: string}>  $group
     */
    private function cardFor(array $row, array $page, array $group, ?string $code, bool $single): B2bRemoteProduct
    {
        $first = $group[0];
        $sku = $code ?? $first['symbol'];
        $cheapest = self::cheapest($group);

        $name = self::cardName($row['name'] !== '' ? $row['name'] : $page['name'], $row['color'], $sku);

        $members = [];
        $summary = '';
        if (! $single) {
            foreach ($group as $size) {
                $members[] = [
                    'remote_id' => $size['symbol'],
                    'sku' => $size['symbol'],
                    'name' => trim($name.' '.$size['size']),
                    'availability' => $size['stock'],
                    'size' => $size['size'],
                    'price' => self::accountPrice($size['cents'] / 100, $size['base'] !== null ? $size['base'] / 100 : null),
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
                // cena karty = najtańszy rozmiar (B2bCatalogSync liczy ją też z members[].price)
                'price' => (float) $cheapest['cents'] / 100,
                'base_price' => $cheapest['base'] !== null ? (float) $cheapest['base'] / 100 : null,
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
     * Pozycje listy w paczkach jednego wyrobu: kafle o tej samej nazwie bez koloru (withoutColour) idą razem,
     * w kolejności pierwszego z nich — ich strony są pobierane jedna po drugiej i mogą dać jedną kartę z kolorami,
     * a w pamięci jest naraz tylko jedna paczka stron. Kafel bez rozpoznanego koloru to paczka jednej pozycji.
     *
     * @param  list<array{path: string, name: string, color: string, price_text: string}>  $rows
     * @return list<non-empty-list<array{path: string, name: string, color: string, price_text: string}>>
     */
    private static function modelBatches(array $rows): array
    {
        $batches = [];
        foreach ($rows as $i => $row) {
            $colour = self::colourOf($row['color']);
            $model = $colour !== null && $row['name'] !== '' ? self::withoutColour($row['name'], $colour) : null;
            $batches[$model !== null ? 'm:'.mb_strtoupper($model) : '#'.$i][] = $row;
        }

        return array_values($batches);
    }

    /**
     * Paczka kafli jednego wyrobu (modelBatches): najpierw strony wszystkich kolorów, potem kolory o tym samym kluczu
     * (colourModel; co najmniej dwa, każdy kolor raz) → jedna karta z kolorami (colourCard) na miejscu pierwszego z nich.
     * Reszta jak dotąd: karta koloru albo pozycja pominięta z powodem. Powtórzony kolor w grupie = nie wiemy, który
     * wiersz jest którym wyrobem — cała grupa zostaje kartami kolorów (jak 3M). Kolory tego samego wyrobu z innym
     * opisem zostają osobno (liczone w podsumowaniu przebiegu).
     *
     * Strona któregoś koloru nieodczytana (błąd pobrania albo budowy, nie pominięcie z powodu treści): o postaci kart
     * rozstrzyga lista (paczka ma kilka kafli), nie liczba odczytanych stron — kolory, które mogłyby tworzyć kartę
     * z kolorami, są w tym przebiegu wstrzymane (heldColours), a karty kolorów bez rozpoznanego koloru idą jak dotąd.
     *
     * Karty kolorów dostają SKU przed kartami z kolorami (uniqueSku) — kod wyrobu bez koloru nie zabierze kodu
     * prawdziwej pozycji tej paczki.
     *
     * @param  non-empty-list<array{path: string, name: string, color: string, price_text: string}>  $batch
     * @return list<B2bRemoteProduct>
     */
    private function batchProducts(array $batch): array
    {
        $parts = array_map(fn (array $row): B2bRemoteProduct|array => $this->prepared($row), $batch);
        $models = [];
        $groups = [];
        $failed = [];
        foreach ($parts as $i => $part) {
            if ($part instanceof B2bRemoteProduct && ($part->raw['read_error'] ?? false) === true) {
                $failed[] = $part->remoteId;
            }
            $model = is_array($part) ? self::colourModel($part) : null;
            if ($model !== null) {
                $models[$i] = $model;
                $groups[$model['key']][] = $i;
            }
        }

        $out = [];
        if ($failed !== [] && $models !== []) {
            $held = [];
            foreach (array_keys($models) as $i) {
                /** @var array{row: array{path: string, name: string, color: string, price_text: string}, page: array<string, mixed>, priced: non-empty-list<array{path: string, symbol: string, size: string, ean: string, cents: int, base: int|null, vat: string, stock: string}>, code: string|null, single: bool} $part */
                $part = $parts[$i];
                $held[] = $part;
            }
            $out[array_key_first($models)] = $this->heldColours($held, $failed);
            $groups = [];
        }

        $groupOf = [];
        $byModel = [];
        foreach ($groups as $key => $indexes) {
            $first = $models[$indexes[0]];
            $byModel[$first['model_key']][] = count($indexes);
            $colours = array_map(static fn (int $i): string => $models[$i]['colour']['key'], $indexes);
            if (count($indexes) >= 2 && count(array_unique($colours)) === count($colours)) {
                foreach ($indexes as $i) {
                    $groupOf[$i] = $key;
                }
            }
        }
        foreach ($byModel as $model => $sizes) {
            if (count($sizes) > 1) {
                $this->descriptionSplits[$model] = array_sum($sizes);
            }
        }

        // karty kolorów najpierw (ich SKU to kody ze sklepu), potem karty z kolorami — na miejscu pierwszego koloru
        foreach ($parts as $i => $part) {
            if ($part instanceof B2bRemoteProduct) {
                $out[$i] = $part;
            } elseif (! isset($groupOf[$i]) && ! isset($out[$i]) && ($failed === [] || ! isset($models[$i]))) {
                $out[$i] = $this->singleColourCard($part);
            }
        }
        foreach ($groupOf as $i => $key) {
            if ($groups[$key][0] !== $i) {
                continue;
            }
            $colours = [];
            foreach ($groups[$key] as $j) {
                /** @var array{row: array{path: string, name: string, color: string, price_text: string}, page: array<string, mixed>, priced: non-empty-list<array{path: string, symbol: string, size: string, ean: string, cents: int, base: int|null, vat: string, stock: string}>, code: string|null, single: bool} $member */
                $member = $parts[$j];
                $colours[] = ['part' => $member, 'model' => $models[$j]];
            }
            $out[$i] = $this->colourCard($colours);
        }
        ksort($out);

        return array_values($out);
    }

    /**
     * Kolor pozycji listy (druga linia kafla — ta sama co cecha „Kolor” strony): kod koloru sklepu i nazwa, np.
     * „BK - Black”, „BK/BK - Black/Black”, „NY/SYF - Navy / Gold Fluor”, „BT-Buttercream”, „WH White”. Kod to wielkie
     * litery i cyfry (człony z ukośnikiem albo myślnikiem), z co najmniej jedną literą; key = kod bez znaków
     * rozdzielających, bo sklep zapisuje ten sam kolor różnie w nazwie, symbolu i cesze („BK-SYF”, „BK/SYF”, „BKWH”).
     * Kolor bez kodu („Jasny szary”, „NIEBIESKA”, „25/35”) = null — takiej pozycji z innymi nie łączymy.
     *
     * @return array{text: string, code: string, name: string, key: string}|null
     */
    private static function colourOf(string $color): ?array
    {
        $text = self::clean($color);
        if (preg_match('/^(\S+)\s+-\s+(\S.*)$/u', $text, $m) !== 1
            && preg_match('/^([A-Z0-9]{1,5}(?:\/[A-Z0-9]{1,5})*)(?:-|\s+)(\p{L}.*)$/u', $text, $m) !== 1) {
            return null;
        }
        if (preg_match('/^[A-Z0-9]+(?:[\/-][A-Z0-9]+)*$/', $m[1]) !== 1 || preg_match('/[A-Z]/', $m[1]) !== 1) {
            return null;
        }

        return ['text' => $text, 'code' => $m[1], 'name' => trim($m[2]), 'key' => self::colourKey($m[1])];
    }

    /** Tekst do porównania koloru: wielkie litery i cyfry bez odstępów i znaków („BK/SYF” = „BK-SYF” = „BKSYF”). */
    private static function colourKey(string $text): string
    {
        return (string) preg_replace('/[^\p{L}\p{N}]/u', '', mb_strtoupper($text));
    }

    /**
     * Nazwa wyrobu bez koloru: znika ostatnie słowo nazwy, które jest kodem koloru („JHK FLRA 340 BK-BK” →
     * „JHK FLRA 340”, „JHK KOC 360 BK PREMIUM” → „JHK KOC 360 PREMIUM”), a gdy takiego nie ma — nazwa koloru na końcu
     * („Czapka Moontex trucker 5P Royal Blue-White” przy „RBWH - Royal Blue/White”). Pierwsze słowo zostaje zawsze.
     * Nazwa bez żadnego z nich = null (nie wiemy, co w nazwie jest kolorem).
     *
     * @param  array{text: string, code: string, name: string, key: string}  $colour
     */
    private static function withoutColour(string $name, array $colour): ?string
    {
        $words = explode(' ', self::clean($name));
        for ($i = count($words) - 1; $i > 0; $i--) {
            if (self::colourKey($words[$i]) === $colour['key']) {
                array_splice($words, $i, 1);

                return implode(' ', $words);
            }
        }
        $wanted = self::colourKey($colour['name']);
        for ($k = 1; $wanted !== '' && $k < count($words); $k++) {
            if (self::colourKey(implode(' ', array_slice($words, -$k))) === $wanted) {
                return implode(' ', array_slice($words, 0, -$k));
            }
        }

        return null;
    }

    /**
     * Wyrób koloru, gdy strona potwierdza kolor z listy: kod wyrobu (kod bez rozmiaru albo symbol wyrobu bez rozmiarów)
     * kończy się słowem, które jest kodem koloru („FLRA 340 BK/BK” przy „BK/BK - Black/Black”, „CZ 5 P TRUCKER BG/WH”
     * przy „BGWH - Bottle Green/White”), a nazwa z listy ma kolor (withoutColour). Kolory łączą się tylko przy tym samym
     * kluczu: kod bez koloru, nazwa bez koloru, rodzaj wyrobu (z rozmiarami albo bez) i opis (bez różnic białych
     * znaków). Kategorii sklepu klucz nie ma: kolor bywa dodatkowo w „Sublimacja” czy „Wysoka Widoczność”, a karta ma
     * ścieżki wszystkich kolorów. Pola karty różne między kolorami (Waga otwartego rozmiaru, Kod HS) nie rozdzielają
     * kolorów — karta z kolorami pokazuje tylko pola wspólne (colourRaw); na produkcji 28.09.2026 różniły się w 48
     * ze 129 wyrobów. Inaczej null — kolor zostaje osobną kartą (np. „CZA 5P BK ZAP MET” — kolor w środku kodu, body
     * „TSRB BODY BK 3 M” bez kodu wyrobu, bo rozmiar ma spację).
     *
     * @param  array{row: array{path: string, name: string, color: string, price_text: string}, page: array<string, mixed>, priced: non-empty-list<array<string, mixed>>, code: string|null, single: bool}  $part
     * @return array{key: string, model_key: string, code: string, name: string, colour: array{text: string, code: string, name: string, key: string}}|null
     */
    private static function colourModel(array $part): ?array
    {
        $colour = self::colourOf($part['row']['color']);
        $code = $part['code'];
        if ($colour === null || $code === null || $part['row']['name'] === '') {
            return null;
        }
        $name = self::withoutColour($part['row']['name'], $colour);
        $at = mb_strrpos($code, ' ');
        if ($name === null || $at === false || self::colourKey(mb_substr($code, $at + 1)) !== $colour['key']) {
            return null;
        }
        $model = trim(mb_substr($code, 0, $at));
        if ($model === '') {
            return null;
        }

        $modelKey = implode('|', [mb_strtoupper($name), mb_strtoupper($model), $part['single'] ? 'bez rozmiarów' : 'rozmiary']);

        return [
            // opis to cecha wyrobu (skład, gramatura) — inny opis koloru = osobna karta; białe znaki bez znaczenia
            'key' => $modelKey.'|'.sha1(self::clean((string) $part['page']['description'])),
            'model_key' => $modelKey,
            'code' => $model,
            'name' => $name,
            'colour' => $colour,
        ];
    }

    /**
     * Karta wyrobu z kolorami (decyzja użytkownika 28.09.2026: kolory jednego wyrobu to jedna karta z tabelą wariantów,
     * jak rozmiary w różnych cenach). Pozycje (members) = każdy rozmiar każdego koloru z ceną konta — remote_id, kod
     * i nazwa jak na dotychczasowej karcie koloru (powiązania zostają), etykieta wiersza „{kolor ze sklepu} / {rozmiar}”
     * (wyrób bez rozmiarów — sam kolor), cena konta i katalogowa rozmiaru. Kolory w kolejności najniższego symbolu
     * rozmiaru (nie kolejności listy, która w sklepie się zmienia); kolor prowadzący = pierwszy z nich: daje remoteId
     * (swój pierwszy rozmiar), nazwę ze źródła i adres; opis jest wspólny (klucz colourModel). Cena karty = najtańsza
     * pozycja wszystkich kolorów (self::cheapest, jak w silniku). SKU = kod wyrobu bez koloru („FLRA 340”), a gdy
     * przebieg już go wydał — kod koloru prowadzącego (jak jego karta koloru); nazwa nowej karty (cardName) = nazwa bez
     * koloru. Zdjęcia, pliki, kategorie, oznaczenia, progi, pola i cechy każdego koloru zostają przy kolorach (raw
     * colour_parts) i liczy je colourRaw z pozycji podanego produktu. Dostępność karty — colourAvailability.
     *
     * @param  non-empty-list<array{part: array{row: array{path: string, name: string, color: string, price_text: string}, page: array<string, mixed>, priced: non-empty-list<array{path: string, symbol: string, size: string, ean: string, cents: int, base: int|null, vat: string, stock: string}>, code: string|null, single: bool}, model: array{key: string, code: string, name: string, colour: array{text: string, code: string, name: string, key: string}}}>  $colours
     */
    private function colourCard(array $colours): B2bRemoteProduct
    {
        // kolejność kolorów (i kolor prowadzący) nie zależy od kolejności listy: najniższy symbol rozmiaru koloru
        $lowest = static fn (array $colour): string => min(array_column($colour['part']['priced'], 'symbol'));
        usort($colours, static fn (array $a, array $b): int => strcmp($lowest($a), $lowest($b)));
        $lead = $colours[0]['part'];
        $members = [];
        $rows = [];
        $sizes = [];
        $texts = [];
        $codes = [];
        $colourParts = [];
        $memberColours = [];
        foreach ($colours as $index => ['part' => $part, 'model' => $model]) {
            $color = $part['row']['color'];
            $texts[] = $color;
            $codes[] = $model['colour']['code'];
            $name = self::colourCardName($part);
            $colourRows = [];
            foreach ($part['priced'] as $size) {
                $memberColours[$size['symbol']] = $index;
                $colourRows[$size['symbol']] = ['size' => $size['size'], 'ean' => $size['ean'], 'stock' => $size['stock']];
                $label = self::rowLabel($color, $size['size']);
                $members[] = [
                    'remote_id' => $size['symbol'],
                    'sku' => $size['symbol'],
                    'name' => trim($name.' '.$size['size']),
                    'availability' => $size['stock'],
                    'size' => $label,
                    'price' => self::accountPrice($size['cents'] / 100, $size['base'] !== null ? $size['base'] / 100 : null),
                ];
                $rows[] = [...$size, 'size' => $label];
                if ($size['size'] !== '' && ! in_array($size['size'], $sizes, true)) {
                    $sizes[] = $size['size'];
                }
            }
            $colourParts[] = [
                'text' => $color,
                'single' => $part['single'],
                'rows' => $colourRows,
                'images' => $part['page']['images'],
                'documents' => $part['page']['documents'],
                'categories' => $part['page']['categories'],
                'labels' => $part['page']['labels'],
                'tiers' => $part['page']['tiers'],
                // EAN z pola karty (wyrób bez rozmiarów) i cecha „Kolor” należą do koloru — colourRaw dokłada je tylko
                // karcie jednego koloru
                'fields' => array_values(array_filter(
                    $part['page']['fields'],
                    static fn (array $field): bool => mb_strtolower(rtrim($field[0], ':')) !== 'kod kreskowy ean',
                )),
                'attributes' => array_values(array_filter(
                    self::cardAttributes($part['page']['attributes'], $part['single']),
                    static fn (array $pair): bool => mb_strtolower(rtrim($pair[0], ':')) !== 'kolor',
                )),
            ];
        }

        $sizeList = $sizes !== [] ? '; rozmiary: '.implode(', ', $sizes) : '';
        $summary = 'Kolory: '.implode(', ', $texts).$sizeList;
        if (mb_strlen($summary) > B2bCatalogSync::VARIANT_SUMMARY_LIMIT) {
            // bardzo wiele kolorów — same kody, żeby lista nie została ucięta w połowie
            $summary = 'Kolory: '.implode(', ', $codes).$sizeList;
        }

        $sku = $this->uniqueSku([$colours[0]['model']['code'], (string) $lead['code']]);
        $name = self::colourCardName($lead);
        $symbols = implode('; ', array_column($rows, 'symbol'));
        $moontex = self::isMoontex($name, $sku, $symbols);
        $identifiers = [];
        foreach ($colours as ['part' => $part]) {
            array_push($identifiers, ...self::identifiers($part['priced'], $part['single'], $moontex, $part['row']['color']));
        }
        $cheapest = self::cheapest($rows);
        $this->colourCards++;
        $this->colourCount += count($colours);
        $this->cards++;
        $this->multiPriceProducts += count(array_unique(array_column($rows, 'cents'))) > 1 ? 1 : 0;

        return new B2bRemoteProduct(
            remoteId: $lead['priced'][0]['symbol'],
            sku: $sku,
            name: $name,
            category: $lead['page']['categories'][0] ?? null,
            sourceUrl: JhkB2bClient::BASE.$lead['row']['path'],
            raw: [
                'status' => 'ok',
                // cena karty = najtańsza pozycja wszystkich kolorów (B2bCatalogSync liczy ją też z members[].price)
                'price' => (float) $cheapest['cents'] / 100,
                'base_price' => $cheapest['base'] !== null ? (float) $cheapest['base'] / 100 : null,
                // symbole do rozpoznania marki (manufacturer); w tabelce tylko przy jednym kolorze (colourRaw)
                'symbol' => $symbols,
                // dane każdego koloru i kolor każdej pozycji — zdjęcia, pliki i pola liczone z pozycji podanego produktu
                // (colourRaw); dawna karta koloru dostaje przy zdjęciach i plikach tylko swoje pozycje
                'colour_parts' => $colourParts,
                'member_colours' => $memberColours,
                // opis taki sam we wszystkich kolorach (klucz colourModel)
                'description' => $lead['page']['description'],
                'vat' => $lead['priced'][0]['vat'],
            ],
            availability: self::colourAvailability($rows),
            variantSummary: $summary,
            members: $members,
            identifiers: $identifiers,
            cardName: self::cardName($colours[0]['model']['name'], '', $sku),
        );
    }

    /**
     * Pola karty z kolorami dla kolorów pozycji TEGO produktu: members, bez nich — pozycja remoteId. Cała grupa ma
     * wszystkie kolory. Dawna karta koloru przed „Scal rozmiary”: B2bCatalogSync::syncMembersByCard podaje jej produkt
     * z jej pozycjami przy zdjęciach (imageUrls) i plikach (documents), więc nie dostaje zdjęć ani plików innego koloru;
     * tabelkę (shopFields) silnik zapisuje z produktu całej grupy (storeShopFields z $origin), więc tam ma pola całego
     * wyrobu. Pozycja o nieznanym kolorze = wszystkie kolory (bez filtrowania). Zdjęcia: jeden kolor — jego galeria
     * (IMAGES_LIMIT); kilka (decyzja właściciela 28.09.2026: karta z kolorami ma dokładnie jedno zdjęcie na kolor) —
     * tylko główne zdjęcie każdego koloru w kolejności wierszy, bez powtórzeń i bez limitu (wyrób bywa w 87 kolorach;
     * B2bCatalogSync::storeGallery pobiera każdy podany adres, którego karta nie ma, więc reszta galerii wracałaby
     * co przebieg); pliki bez powtórzeń (DOCUMENTS_LIMIT);
     * progi ilościowe, pola i cechy — tylko takie same we wszystkich kolorach. Jeden kolor — tabelka jak na karcie
     * koloru: jego pola, symbole, EAN-y, stany i cecha „Kolor” (tylko pozycje produktu); kilka — bez list wierszy (są
     * przy wierszach wariantów, w jednym polu zostałyby przycięte). Produkt bez kolorów (karta koloru) — raw bez zmian.
     *
     * @return array<string, mixed>
     */
    private static function colourRaw(B2bRemoteProduct $product): array
    {
        $raw = $product->raw;
        $parts = $raw['colour_parts'] ?? null;
        if (! is_array($parts) || $parts === []) {
            return $raw;
        }
        $positions = $product->members !== []
            ? array_map(static fn (array $member): string => (string) ($member['remote_id'] ?? ''), $product->members)
            : [$product->remoteId];
        $chosen = [];
        $present = [];
        foreach ($positions as $id) {
            $index = $raw['member_colours'][$id] ?? null;
            if (! is_int($index) || ! isset($parts[$index])) {
                $chosen = array_fill_keys(array_keys($parts), true);
                $present = null;
                break;
            }
            $chosen[$index] = true;
            $present[$id] = true;
        }
        ksort($chosen);
        $selected = array_values(array_intersect_key($parts, $chosen));

        // jeden kolor — jego galeria (IMAGES_LIMIT); kilka — tylko główne zdjęcie każdego koloru, bez limitu
        $images = [];
        if (count($selected) === 1) {
            $images = array_slice(array_values(array_unique($selected[0]['images'])), 0, self::IMAGES_LIMIT);
        } else {
            foreach ($selected as $part) {
                if (isset($part['images'][0])) {
                    $images[] = $part['images'][0];
                }
            }
            $images = array_values(array_unique($images));
        }
        $documents = [];
        $categories = [];
        $labels = [];
        $tiers = $selected[0]['tiers'];
        foreach ($selected as $part) {
            foreach ($part['documents'] as $document) {
                $documents[$document['url']] ??= $document;
            }
            foreach ($part['categories'] as $path) {
                $categories[$path] = true;
            }
            foreach ($part['labels'] as $text) {
                $labels[$text] = true;
            }
            if ($part['tiers'] !== $tiers) {
                $tiers = [];
            }
        }

        $raw['colours'] = array_column($selected, 'text');
        $raw['image_urls'] = $images;
        $raw['documents'] = array_slice(array_values($documents), 0, self::DOCUMENTS_LIMIT);
        $raw['category_path'] = implode(' | ', array_keys($categories));
        $raw['labels'] = array_keys($labels);
        $raw['tiers'] = $selected[0]['single'] ? $tiers : [];
        $raw['stock'] = [];
        // pola i cechy wspólne wszystkim kolorom (Waga otwartego rozmiaru czy Kod HS bywają różne — wtedy ich nie ma)
        $common = static function (string $key) use ($selected): array {
            $out = $selected[0][$key];
            foreach (array_slice($selected, 1) as $part) {
                $out = array_values(array_filter($out, static fn (array $pair): bool => in_array($pair, $part[$key], true)));
            }

            return $out;
        };
        $raw['fields'] = $common('fields');
        $raw['attributes'] = $common('attributes');
        if (count($selected) === 1) {
            $part = $selected[0];
            $rows = $present === null ? $part['rows'] : array_intersect_key($part['rows'], $present);
            $raw['symbol'] = implode('; ', array_keys($rows));
            $raw['stock'] = array_values(array_map(
                static fn (array $row): string => ($row['size'] !== '' ? $row['size'].': ' : '').$row['stock'],
                $rows,
            ));
            $eans = array_filter($rows, static fn (array $row): bool => $row['ean'] !== '');
            if ($eans !== []) {
                $raw['fields'][] = $part['single']
                    ? ['Kod kreskowy EAN', implode('; ', array_column($eans, 'ean'))]
                    : ['EAN', implode('; ', array_map(static fn (array $row): string => $row['size'].': '.$row['ean'], $eans))];
            }
            $raw['attributes'][] = ['Kolor', $part['text']];
        }

        return $raw;
    }

    /**
     * Nazwa karty koloru ze źródła (jak dotąd: „JHK Bluza JT SWCR BK, BK - Black”) — na karcie z kolorami nazwa koloru
     * prowadzącego i nazwy pozycji.
     *
     * @param  array{row: array{path: string, name: string, color: string, price_text: string}, page: array<string, mixed>, priced: non-empty-list<array{symbol: string}>, code: string|null, single: bool}  $part
     */
    private static function colourCardName(array $part): string
    {
        return self::cardName(
            $part['row']['name'] !== '' ? $part['row']['name'] : (string) $part['page']['name'],
            $part['row']['color'],
            $part['code'] ?? $part['priced'][0]['symbol'],
        );
    }

    /**
     * Dostępność karty z kolorami: jak groupAvailability (dosłownie ze sklepu), a gdy taka lista wierszy byłaby dłuższa
     * niż AVAILABILITY_LIMIT (dziesiątki kolorów po kilka rozmiarów) — w ilu wierszach karty sklep pokazuje towar
     * w każdym magazynie (nazwy magazynów dosłownie). Stan każdego wiersza zostaje przy wierszu wariantu.
     *
     * @param  list<array{size: string, symbol: string, stock: string}>  $rows
     */
    private static function colourAvailability(array $rows): ?string
    {
        $literal = self::groupAvailability($rows);
        if ($literal === null || mb_strlen($literal) <= self::AVAILABILITY_LIMIT) {
            return $literal;
        }
        $counts = [];
        foreach ($rows as $row) {
            foreach (explode('; ', $row['stock']) as $part) {
                $place = preg_match('/^(.+): \d+ szt\.$/u', $part, $m) === 1 ? $m[1] : ($part !== '' ? $part : 'brak informacji');
                $counts[$place] = ($counts[$place] ?? 0) + 1;
            }
        }
        $parts = [];
        foreach ($counts as $place => $count) {
            $parts[] = $place.': '.$count.' z '.count($rows).' wierszy';
        }

        return implode('; ', $parts).' (stan każdego koloru i rozmiaru w tabeli wariantów)';
    }

    /** Etykieta wiersza karty z kolorami: „BK - Black / XS”; wyrób bez rozmiarów — sam kolor. */
    private static function rowLabel(string $colour, string $size): string
    {
        return $size !== '' ? $colour.' / '.$size : $colour;
    }

    /**
     * Pierwszy kod z kandydatów, którego przebieg jeszcze nie wydał — dwie karty przebiegu z tym samym SKU złamałyby
     * UNIQUE products.sku i synchronizacja pominęłaby drugą. Ostatni kandydat (kod koloru ze sklepu) zostaje także
     * zajęty — jak karta tego koloru do tej pory.
     *
     * @param  non-empty-list<string>  $candidates
     */
    private function uniqueSku(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $key = mb_strtolower($candidate);
            if ($candidate !== '' && ! isset($this->skus[$key])) {
                $this->skus[$key] = true;

                return $candidate;
            }
        }

        return $candidates[array_key_last($candidates)];
    }

    /**
     * Symbol i EAN każdego rozmiaru karty; pozycja = symbol rozmiaru (remote_id powiązania), a wyrób bez rozmiarów
     * ma jedną pozycję — swój symbol, zarazem remoteId karty. Symbol to własny kod sklepu producenta: przy wyrobie
     * JHK jest kodem producenta, przy MOONTEX (inna marka w tym sklepie) tylko kodem źródła. Kod wyrobu bez rozmiaru
     * (productCode) składamy sami z symboli — nie jest identyfikatorem ze źródła i tu nie trafia.
     *
     * Na karcie z kolorami etykieta pozycji to wiersz wariantu („BK - Black / XS”, rowLabel) — $colour = kolor strony.
     *
     * @param  list<array{path: string, symbol: string, size: string, ean: string, cents: int|null, vat: string, stock: string}>  $group
     * @return list<B2bRemoteIdentifier>
     */
    private static function identifiers(array $group, bool $single, bool $moontex, ?string $colour = null): array
    {
        $codeType = $moontex ? ProductIdentifier::TYPE_SOURCE_CODE : ProductIdentifier::TYPE_MANUFACTURER_CODE;
        $out = [];
        foreach ($group as $size) {
            $position = $size['symbol'];
            $label = $colour !== null ? self::rowLabel($colour, $size['size']) : ($size['size'] !== '' ? $size['size'] : null);
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
    private function skipped(array $row, string $reason, bool $readError = false): B2bRemoteProduct
    {
        $this->skippedProducts++;
        $name = $row['name'] !== '' ? $row['name'] : $row['path'];

        return new B2bRemoteProduct(
            remoteId: $row['path'],
            sku: $name,
            name: $name,
            sourceUrl: JhkB2bClient::BASE.$row['path'],
            raw: ['status' => 'skipped', 'reason' => $reason, 'read_error' => $readError],
        );
    }

    /**
     * Kolory wyrobu wstrzymane w tym przebiegu, bo strona innego koloru tego wyrobu nie dała się odczytać: pozycja
     * pominięta z powodem, której pozycje (members) to wiersze odczytanych kolorów. Synchronizacja uznaje je za nieudane
     * (B2bCatalogSync: pozycje produktu pominiętego) — karta wyrobu i dawne karty kolorów zostają bez zmian, a ich
     * wiersze nie są oznaczane jako usunięte na końcu przebiegu. Karta z samych odczytanych kolorów zmieniłaby tabelę
     * wariantów (bez koloru nieodczytanego) albo wróciła do postaci karty koloru — i z powrotem w następnym przebiegu.
     *
     * @param  non-empty-list<array{row: array{path: string, name: string, color: string, price_text: string}, page: array<string, mixed>, priced: non-empty-list<array{symbol: string}>, code: string|null, single: bool}>  $parts
     * @param  list<string>  $failed  adresy kolorów, których strony się nie odczytały
     */
    private function heldColours(array $parts, array $failed): B2bRemoteProduct
    {
        $this->skippedProducts++;
        $members = [];
        foreach ($parts as $part) {
            $name = self::colourCardName($part);
            foreach ($part['priced'] as $size) {
                $members[] = ['remote_id' => $size['symbol'], 'sku' => $size['symbol'], 'name' => $name];
            }
        }
        $lead = $parts[0]['row'];

        return new B2bRemoteProduct(
            remoteId: $members[0]['remote_id'],
            sku: $lead['name'] !== '' ? $lead['name'] : $lead['path'],
            name: $members[0]['name'],
            sourceUrl: JhkB2bClient::BASE.$lead['path'],
            raw: [
                'status' => 'skipped',
                'reason' => 'kolory wyrobu bez zmian w tym przebiegu — nie odczytano strony koloru '.implode(', ', $failed),
            ],
            members: $members,
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
