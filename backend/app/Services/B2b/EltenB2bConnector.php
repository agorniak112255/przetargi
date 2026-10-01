<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * b2b.elten.com — sklep B2B producenta obuwia ELTEN GmbH (marki ELTEN, JORI Professional, LOWA WORK). Sprawdzone na
 * zalogowanym koncie #26 01.10.2026 (EltenB2bClient opisuje logowanie i język): 842 artykuły, ceny konta w EUR.
 *
 * Lista (/search, 24 artykuły na stronę, liczba w nagłówku „(842 article(s))”) podaje id strony artykułu, nazwę,
 * numer („0005304-0”) i cenę cennikową; strona za ostatnią oddaje ostatnią jeszcze raz, więc czytamy do liczby
 * z nagłówka. Strona artykułu (/detail/{id}) — cechy (Brand, Colour, Standard, Upper material…; po angielsku, bez
 * tłumaczenia), podtytuł („Leather-free equipment”), cenę cennikową („List price”), galerię i w atrybucie data-article
 * rozmiary: cenę konta rozmiaru (trzy miejsca po przecinku — zaokrąglamy połówki w górę jak strona: 107.185 → 107,19)
 * i ilości dostępne w kolejnych tygodniach (pierwszy tydzień = stan teraz). Pole ilości koszyka: min 1, krok 1 — bez
 * warunku zamawiania. Sklep nie ma opisu, plików ani EAN-ów.
 *
 * Karta = artykuły jednego wyrobu (decyzje właściciela 28.09.2026: rozmiary i kolory jednego wyrobu to jedna karta).
 * Sklep prowadzi kolor jako osobny artykuł („ZEPHYR Work GTX® black|wolf|coyote Mid ESD S3S WR”), a rozmiary damskie
 * albo dodatkowe — jako artykuł o tej samej nazwie z innym numerem (TILL BOA® 0076651 37–48, 0176651 36, 0276651 49).
 * Artykuły łączymy ostrożnie: nazwa bez słów koloru (COLOUR_WORDS, tylko człony małymi literami: „black-red”,
 * „ranger green”) taka sama i WSZYSTKIE cechy poza „Colour” i „Article name” oraz podtytuł identyczne; artykuły o tej
 * samej nazwie (bez innego koloru) — tylko przy rozłącznych rozmiarach. Różna norma koloru (LARROX black Lo SRC,
 * black-blue Lo SRA) = osobne karty. Kolejność deterministyczna: artykuły i karty według numeru, karta wiodąca =
 * najniższy numer (SKU karty dosłownie, numer to kod producenta), nie zależy od kolejności listy.
 *
 * Pozycja (members) = rozmiar artykułu: remote_id „{numer}/{rozmiar}”, cena konta rozmiaru i cena cennikowa artykułu
 * (EUR, jak Ejendals i Bolle — katalog przyjmuje walutę producenta). „VE” (opakowanie, sznurówki „SU=50 p.”) i „Stck”
 * (sztuka) to nie rozmiary, a jednostka sprzedaży — warunek zamawiania z jednostką, bez rozmiaru na karcie.
 *
 * Opis: polska lista cech producenta z elten.com („Nasza opinia” na /pl/products/…-{numer}/, numer = numer artykułu
 * bez zer wiodących i „-0”), dosłownie, z pierwszego artykułu karty, który ma polską stronę. Sekcja „Details” bywa
 * cudza (na stronie LARROX niemiecki tekst o ADAM ESD S1) — nie bierzemy jej. Strona innego numeru (tytuł) = bez opisu.
 * Pliki z przycisków tej strony: polski arkusz danych technicznych („ADT”: oznaczenia normy rozpisane, zastosowanie,
 * materiały, podnosek, antyprzebicie, podeszwa) i polski PDF wyrobu jako karty katalogowe — arkusz pierwszy — oraz
 * certyfikat CE (decyzja właściciela 01.10.2026: pobieramy mimo zakazu w robots.txt, EltenB2bClient).
 *
 * Lista cech to 300–900 znaków, a treść wyrobu jest w arkuszu — dlatego B2bDescribesFromDatasheet (jak Tegro i Polstar,
 * decyzja użytkownika 21.09.2026): opis karty pisze model wyłącznie z listy cech i arkusza, bez internetu. Karta bez
 * polskiej strony nie ma ani listy, ani arkusza — zostaje bez opisu.
 *
 * Producent karty = „ELTEN” dla wszystkich marek (JORI i LOWA WORK produkuje ELTEN GmbH; marka zostaje w tabelce
 * i na początku nazwy nowej karty), żeby normy z tabelki, opis i zdjęcia producenta obowiązywały wszystkie karty.
 */
final class EltenB2bConnector implements B2bConnector, B2bDescribesFromDatasheet, B2bDocumentSource, B2bGroupsSizes, B2bImageGallery, B2bListProgressAware, B2bManufacturerSite, B2bRunSummaryAware, B2bShopFieldNormSource, B2bShopFieldSource, B2bSizePriceSource
{
    public const BRAND = 'ELTEN';

    public const CURRENCY = 'EUR';

    private const MAX_LIST_PAGES = 200;

    private const LIST_BUDGET_SECONDS = 20 * 60;

    private const PROGRESS_EVERY = 50;

    private const INCONSISTENT = 7302;

    /** Tyle artykułów bez ceny konta, zanim pojawi się pierwsza cena = sesja bez cen (albo zmiana sklepu). */
    private const MAX_FIRST_WITHOUT_PRICE = 20;

    private const IMAGES_LIMIT = 8;

    private const AVAILABILITY_LIMIT = 1000;

    /**
     * Tyle kolejnych nieudanych stron elten.com wyłącza witrynę do końca przebiegu: opis to dodatek do cen, a każda
     * nieudana strona kosztuje do minuty ponowień — awaria witryny producenta nie może zatrzymać cennika.
     */
    private const SITE_FAILURE_LIMIT = 5;

    /** Wiersz normy w tabelce (normShopFieldNames). */
    private const NORM_ROW = 'Norma';

    private const SECTION_MAIN = 'Informacje ze sklepu ELTEN';

    private const SECTION_NORMS = 'Normy';

    /** Cechy strony artykułu, które nie należą do wyrobu (kolor i nazwa artykułu) albo idą osobnym wierszem. */
    private const FEATURE_COLOUR = 'Colour';

    private const FEATURE_NAME = 'Article name';

    private const FEATURE_STANDARD = 'Standard';

    private const FEATURE_BRAND = 'Brand';

    private const FEATURE_COMMODITY = 'Commodity group';

    private const FEATURE_PRODUCT_GROUP = 'Product group';

    /**
     * Słowa koloru w nazwach artykułów (zawsze małymi literami, także złożone: „black-red”, „ranger green”) — zebrane
     * z 842 nazw sklepu 01.10.2026. Słowo spoza listy zostaje częścią nazwy wyrobu (artykuły się nie łączą).
     */
    private const COLOUR_WORDS = [
        'anthracite', 'aqua', 'beige', 'black', 'blue', 'brown', 'coyote', 'creme', 'darkblue', 'gold', 'green', 'grey',
        'jeans', 'khaki', 'lime', 'mint', 'navy', 'olive', 'orange', 'petrol', 'pink', 'ranger', 'red', 'sand', 'silver',
        'turquoise', 'white', 'wolf', 'yellow',
    ];

    /** „Rozmiary”, które są jednostką sprzedaży artykułu: jednostka warunku zamawiania. */
    private const UNIT_SIZES = ['VE' => 'opak.', 'Stck' => 'szt.'];

    /** Marka w nazwie nowej karty: krótka nazwa marki ze sklepu. */
    private const BRAND_PREFIX = ['ELTEN' => 'ELTEN', 'JORI Professional' => 'JORI', 'LOWA WORK' => 'LOWA'];

    /** Grupa towarowa sklepu po polsku (pierwszy człon kategorii); nieznana — dosłownie. */
    private const COMMODITY_GROUPS = ['shoes' => 'Obuwie', 'Zubehör' => 'Akcesoria'];

    private int $total = 0;

    /** Artykułów na liście (postęp czytania stron artykułów). */
    private int $listCount = 0;

    private int $cards = 0;

    private int $colourCards = 0;

    private int $sizeExtensionCards = 0;

    private int $multiPrice = 0;

    private int $withPrice = 0;

    private int $articlesDone = 0;

    /** @var list<string> */
    private array $summary = [];

    /** @var list<string> */
    private array $withoutPrice = [];

    /** @var list<string> */
    private array $readErrors = [];

    /** @var list<string> */
    private array $heldArticles = [];

    /** @var list<string> */
    private array $withoutPolishPage = [];

    /** @var list<string> */
    private array $polishPageProblems = [];

    /** @var list<string> */
    private array $priceTextMismatches = [];

    /** @var list<string> */
    private array $withoutListPrice = [];

    /** @var array<string, true> nazwy kart przebiegu (małymi literami) */
    private array $cardNames = [];

    /** @var array<string, string>|null numer elten.com → adres polskiej strony; null = mapa jeszcze nie wczytana */
    private ?array $polishPages = null;

    /** Kolejne nieudane strony elten.com; po SITE_FAILURE_LIMIT przebieg przestaje pytać witrynę (opisy bez zmian). */
    private int $siteFailures = 0;

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    public function __construct(private readonly EltenB2bClient $client) {}

    public static function key(): string
    {
        return 'elten';
    }

    public static function label(): string
    {
        return self::BRAND;
    }

    public static function host(): string
    {
        return EltenB2bClient::HOST;
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
        return new self(new EltenB2bClient(
            (string) $account->username,
            (string) $account->password,
            (string) ($account->contractor_code ?? ''),
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
        $this->total = 0;
        $this->cards = 0;
        $this->colourCards = 0;
        $this->sizeExtensionCards = 0;
        $this->multiPrice = 0;
        $this->withPrice = 0;
        $this->articlesDone = 0;
        $this->summary = [];
        $this->withoutPrice = [];
        $this->readErrors = [];
        $this->heldArticles = [];
        $this->withoutPolishPage = [];
        $this->polishPageProblems = [];
        $this->priceTextMismatches = [];
        $this->withoutListPrice = [];
        $this->cardNames = [];
        $this->polishPages = null;
        $this->siteFailures = 0;

        if (! $this->client->isLoggedIn()) {
            $this->client->login();
        }

        $rows = $this->listRows();
        // na start każdy artykuł to karta; artykuły złączone w jedną kartę zmniejszają liczbę w trakcie przebiegu
        $this->total = count($rows);
        $this->listCount = count($rows);
        $this->summary[] = 'Lista ELTEN: '.count($rows).' artykułów';

        foreach (self::batches($rows) as $batch) {
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
            'Karty: %d (%d z kolorami w jednej karcie, %d z rozmiarami z kilku numerów artykułu, %d z rozmiarami albo kolorami w różnych cenach — cena karty = najniższa, ceny pozycji w tabeli karty)',
            $this->cards,
            $this->colourCards,
            $this->sizeExtensionCards,
            $this->multiPrice,
        );
        if ($this->withoutPrice !== []) {
            $lines[] = 'Bez ceny konta albo w innej walucie niż EUR (pominięte): '.self::listing($this->withoutPrice);
        }
        if ($this->readErrors !== []) {
            $lines[] = 'Strony artykułów nieodczytane (pominięte w tym przebiegu): '.self::listing($this->readErrors);
        }
        if ($this->heldArticles !== []) {
            $lines[] = 'Artykuły wstrzymane, bo inny artykuł tego wyrobu był nieodczytany (podział na karty niepewny): '.self::listing($this->heldArticles);
        }
        if ($this->withoutListPrice !== []) {
            $lines[] = 'Bez ceny cennikowej (karta tylko z ceną konta): '.self::listing($this->withoutListPrice);
        }
        if ($this->priceTextMismatches !== []) {
            $lines[] = 'Cena konta na stronie inna niż cena rozmiarów (zapisana cena rozmiarów): '.self::listing($this->priceTextMismatches);
        }
        if ($this->withoutPolishPage !== []) {
            $lines[] = 'Karty bez polskiej strony elten.com (bez opisu i PDF): '.self::listing($this->withoutPolishPage);
        }
        if ($this->polishPageProblems !== []) {
            $lines[] = 'Polskie strony elten.com pominięte: '.self::listing($this->polishPageProblems);
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

        return array_map(
            static fn (array $row): B2bRemoteShopField => new B2bRemoteShopField($row['section'], $row['name'], $row['value']),
            $product->raw['fields'] ?? [],
        );
    }

    /**
     * Arkusz danych technicznych, PDF wyrobu i certyfikat CE z elten.com — tylko gdy artykuł, z którego pochodzą, jest
     * wśród pozycji podanego produktu (dawna karta jednego koloru nie dostaje plików innego koloru).
     *
     * @return list<B2bRemoteDocument>
     */
    public function documents(B2bRemoteProduct $product): array
    {
        $out = [];
        foreach ($product->raw['documents'] ?? [] as $document) {
            if (isset(self::productArticles($product)[$document['article']])) {
                $out[] = new B2bRemoteDocument($document['title'], $document['url'], $document['kind']);
            }
        }

        return $out;
    }

    public function documentBytes(B2bRemoteDocument $document): array
    {
        return $this->client->siteFileBytes($document->sourceUrl);
    }

    /**
     * Zdjęcia kolorów podanego produktu (kolor = pierwszy artykuł tego koloru; rozmiary dodatkowe pod innym numerem to
     * ten sam kolor): jeden kolor — galeria (IMAGES_LIMIT); kilka — główne zdjęcie każdego koloru (decyzja właściciela
     * 28.09.2026: karta z kolorami ma jedno zdjęcie na kolor), bez powtórzeń.
     *
     * @return list<string>
     */
    public function imageUrls(B2bRemoteProduct $product): array
    {
        $galleries = [];
        foreach (array_keys(self::productArticles($product)) as $number) {
            $colour = (string) ($product->raw['article_colours'][$number] ?? $number);
            if (! isset($galleries[$colour]) || $galleries[$colour] === []) {
                $galleries[$colour] = $product->raw['images'][$number] ?? [];
            }
        }
        $galleries = array_values($galleries);
        if (count($galleries) === 1) {
            return array_slice($galleries[0], 0, self::IMAGES_LIMIT);
        }
        $out = [];
        foreach ($galleries as $gallery) {
            if (isset($gallery[0])) {
                $out[] = $gallery[0];
            }
        }

        return array_values(array_unique($out));
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
     * Kwota ze sklepu w groszach (centach), połówki w górę jak na stronie: 107.185 → 10719, 47.385000000000005 → 4739,
     * 26.732999999999997 → 2673. Liczba z data-article ma trzy miejsca po przecinku; zaokrąglamy na zapisie dziesiętnym
     * (sprintf), nie na liczbie zmiennoprzecinkowej — „%F” zawsze z kropką: „%f” bierze separator z ustawień regionalnych
     * procesu (na serwerze przecinek: „107,185” dawało 0,11 EUR — przebieg na sucho 01.10.2026). Brak, zero albo nie
     * liczba = null.
     */
    public static function priceCents(mixed $value): ?int
    {
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }
        $mills = (int) str_replace('.', '', sprintf('%.3F', (float) $value));
        if ($mills <= 0) {
            return null;
        }

        return intdiv($mills + 5, 10);
    }

    /** Kwota z tekstu strony („1.930,53 EUR”, „164,90 EUR”) w centach; inny zapis = null. */
    public static function textCents(string $text): ?int
    {
        if (preg_match('/(\d{1,3}(?:\.\d{3})*|\d+),(\d{2})\s*EUR/u', $text, $m) !== 1) {
            return null;
        }
        $cents = (int) str_replace('.', '', $m[1]) * 100 + (int) $m[2];

        return $cents > 0 ? $cents : null;
    }

    /**
     * Nazwa artykułu bez słów koloru i same słowa koloru: „ZEPHYR Work GTX® ranger green Mid ESD S3S WR” →
     * [„ZEPHYR Work GTX® Mid ESD S3S WR”, „ranger green”]. Słowo koloru = człon małymi literami, którego wszystkie części
     * (po „-”) są na liście COLOUR_WORDS.
     *
     * @return array{0: string, 1: string}
     */
    public static function splitColour(string $name): array
    {
        $base = [];
        $colour = [];
        foreach (preg_split('/\s+/u', self::clean($name)) ?: [] as $token) {
            if ($token !== '' && self::isColourWord($token)) {
                $colour[] = $token;
            } elseif ($token !== '') {
                $base[] = $token;
            }
        }

        return [implode(' ', $base), implode(' ', $colour)];
    }

    /**
     * Strona artykułu: nazwa, numer, podtytuł, cechy, ceny i rozmiary. Brak nazwy albo atrybutu data-article =
     * wyjątek (zmiana sklepu albo nie ta strona).
     *
     * @return array{name: string, number: string, subtitle: string, features: list<array{0: string, 1: string}>, list_cents: int|null, account_text_cents: int|null, currency: string, sizes: list<array{size: string, cents: int|null, stocks: array<string, int>}>, images: list<string>}
     */
    public static function parseDetail(string $html): array
    {
        if (preg_match('#<h2>(.*?)<sub>(.*?)</sub>\s*</h2>#s', $html, $heading) !== 1) {
            throw new RuntimeException('strona artykułu bez nazwy i numeru');
        }
        if (preg_match('#data-article="([^"]*)"#', $html, $data) !== 1) {
            throw new RuntimeException('strona artykułu bez tabeli rozmiarów (data-article)');
        }
        $article = json_decode(html_entity_decode($data[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'), true);
        if (! is_array($article) || ! is_array($article['article'] ?? null)) {
            throw new RuntimeException('nieczytelna tabela rozmiarów (data-article)');
        }

        $features = [];
        preg_match_all('#feature-name">\s*(.*?)\s*</div>\s*<div class="col-6 feature-value">\s*(.*?)\s*</div>#s', $html, $rows, PREG_SET_ORDER);
        foreach ($rows as $row) {
            $label = rtrim(self::text($row[1]), ': ');
            $value = self::text($row[2]);
            if ($label !== '' && $value !== '') {
                $features[] = [$label, $value];
            }
        }

        $sizes = [];
        foreach ($article['article'] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $size = self::clean((string) ($item['size'] ?? ''));
            if ($size === '') {
                continue;
            }
            $stocks = [];
            foreach (is_array($item['availableStocks'] ?? null) ? $item['availableStocks'] : [] as $date => $qty) {
                if (is_string($date) && preg_match('#^\d{4}/\d{2}/\d{2}$#', $date) === 1 && is_numeric($qty)) {
                    $stocks[$date] = (int) $qty;
                }
            }
            ksort($stocks);
            $sizes[] = ['size' => $size, 'cents' => self::priceCents($item['price'] ?? null), 'stocks' => $stocks];
        }

        $images = [];
        preg_match_all('#data-fancybox="detailpage"\s+href="(/[^"\#]+)"#', $html, $gallery);
        foreach ($gallery[1] as $href) {
            $url = EltenB2bClient::absoluteUrl($href);
            if (! in_array($url, $images, true)) {
                $images[] = $url;
            }
        }

        return [
            'name' => self::text($heading[1]),
            'number' => self::text($heading[2]),
            'subtitle' => preg_match('#<strong class="d-block mb-1">(.*?)</strong>#s', $html, $sub) === 1 ? self::text($sub[1]) : '',
            'features' => $features,
            'list_cents' => preg_match('#<strong>List price:</strong>\s*<span>(.*?)</span>\s*</span>#s', $html, $list) === 1 ? self::textCents(self::text($list[1])) : null,
            'account_text_cents' => preg_match('#<strong>Purchase price:</strong>\s*<span>(.*?)</span>#s', $html, $own) === 1 ? self::textCents(self::text($own[1])) : null,
            'currency' => mb_strtoupper(self::clean((string) ($article['currency'] ?? ''))),
            'sizes' => $sizes,
            'images' => $images,
        ];
    }

    /**
     * Polska strona wyrobu elten.com: lista „Nasza opinia” (wiersze dosłownie) i pliki z przycisków strony w kolejności
     * zapisu na karcie — arkusz danych technicznych („TD-button”), PDF wyrobu („PDF-button”), certyfikat CE
     * („CE-button”). Arkusz i PDF muszą mieć numer wyrobu w nazwie pliku („TD PL 5304 …”, „PL 5304 …” — strona bywa
     * składana z cudzych części, jak sekcja „Details”); certyfikat obejmuje typ wyrobu i numer w nazwie ma tylko czasem
     * („Typ 412_3_22_KE….pdf”, „5304 530408 C29 … .pdf”), więc bez tego warunku. Strona
     * innego numeru (tytuł „… - {numer} - ELTEN GmbH”) = null.
     *
     * @return array{opinion: list<string>, files: list<array{url: string, kind: string}>}|null
     */
    public static function parsePolishPage(string $html, string $number): ?array
    {
        if (preg_match('#<title>[^<]*?-\s*(\d+)\s*-\s*ELTEN GmbH#u', $html, $title) !== 1 || $title[1] !== $number) {
            return null;
        }
        $opinion = [];
        // lista musi stać na początku treści zakładki — bez niej nie sięgamy po listę następnej zakładki („Orto / wkładek”)
        if (preg_match("#>Nasza opinia</div>\\s*<div[^>]*class='tab_content[^']*'[^>]*>\\s*<div[^>]*>\\s*<ul>(.*?)</ul>#s", $html, $tab) === 1) {
            preg_match_all('#<li[^>]*>(.*?)</li>#s', $tab[1], $items);
            foreach ($items[1] as $item) {
                $line = self::text($item);
                if ($line !== '') {
                    $opinion[] = $line;
                }
            }
        }
        $files = [];
        foreach (['TD' => true, 'PDF' => true, 'CE' => false] as $button => $numbered) {
            if (preg_match('#id\s*="'.$button.'-button"\s+href="([^"]+)"#', $html, $link) !== 1) {
                continue;
            }
            $url = EltenB2bClient::absoluteUrl($link[1], EltenB2bClient::SITE);
            $file = basename(rawurldecode((string) parse_url($url, PHP_URL_PATH)));
            if (! EltenB2bClient::isSiteFileUrl($url)
                || ($numbered && preg_match('/(?<!\d)'.preg_quote($number, '/').'(?!\d)/', $file) !== 1)) {
                continue;
            }
            $files[] = ['url' => $url, 'kind' => $button === 'CE' ? ProductDocument::KIND_CERTIFICATE : ProductDocument::KIND_DATASHEET];
        }

        return ['opinion' => $opinion, 'files' => $files];
    }

    /**
     * Numer artykułu na elten.com: numer sklepu bez zer wiodących i bez „-0” („0005304-0” → „5304”). Inny dopisek
     * po myślniku (długość sznurówek „-3”, wkładki „-1”) — elten.com takich numerów nie ma, null.
     */
    public static function siteNumber(string $number): ?string
    {
        if (preg_match('/^(\d+)-0$/', $number, $m) !== 1) {
            return null;
        }
        $trimmed = ltrim($m[1], '0');

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Cała lista artykułów; niespójna (liczba z nagłówka nieosiągnięta) — jedno ponowne pobranie od początku.
     *
     * @return list<array{id: string, name: string, number: string}>
     */
    private function listRows(): array
    {
        try {
            return $this->scanList();
        } catch (RuntimeException $e) {
            if ($e->getCode() !== self::INCONSISTENT) {
                throw $e;
            }
            $this->progress('Lista ELTEN niespójna ('.$e->getMessage().') — pobieram jeszcze raz');

            return $this->scanList();
        }
    }

    /**
     * @return list<array{id: string, name: string, number: string}>
     */
    private function scanList(): array
    {
        $started = microtime(true);
        $html = $this->client->listPage(1);
        if (preg_match('#\((\d+) article\(s\)\)#', $html, $m) !== 1) {
            throw new RuntimeException('lista '.EltenB2bClient::HOST.' bez liczby artykułów w nagłówku (zmiana sklepu albo inny język)');
        }
        $expected = (int) $m[1];
        $rows = [];
        $page = 1;
        while (true) {
            $new = 0;
            foreach (self::parseListPage($html) as $row) {
                if (! isset($rows[$row['id']])) {
                    $rows[$row['id']] = $row;
                    $new++;
                }
            }
            $this->progress('Lista ELTEN: strona '.$page.' ('.count($rows).'/'.$expected.' artykułów)');
            if (count($rows) >= $expected || $new === 0) {
                break;
            }
            $page++;
            if ($page > self::MAX_LIST_PAGES || microtime(true) - $started > self::LIST_BUDGET_SECONDS) {
                throw new RuntimeException('lista '.EltenB2bClient::HOST.' za długa: '.count($rows).' z '.$expected.' artykułów po '.($page - 1).' stronach');
            }
            $html = $this->client->listPage($page);
        }

        if (count($rows) !== $expected) {
            throw new RuntimeException(count($rows).' z '.$expected.' artykułów', self::INCONSISTENT);
        }

        return array_values($rows);
    }

    /**
     * Kafle strony listy: id strony artykułu (data-matid), nazwa i numer.
     *
     * @return list<array{id: string, name: string, number: string}>
     */
    public static function parseListPage(string $html): array
    {
        $out = [];
        $parts = preg_split('/<div class="col product-list-col product-scroll-box" data-matid="/', $html) ?: [];
        array_shift($parts);
        foreach ($parts as $part) {
            if (preg_match('/^(\d+)"/', $part, $id) !== 1) {
                continue;
            }
            if (preg_match('#<h3><a[^>]*>(.*?)</a></h3>\s*<span>(.*?)</span>#s', $part, $m) !== 1) {
                continue;
            }
            $out[] = ['id' => $id[1], 'name' => self::text($m[1]), 'number' => self::text($m[2])];
        }

        return $out;
    }

    /**
     * Artykuły w paczkach jednego wyrobu: ta sama nazwa bez słów koloru (bez wielkości liter), artykuły i paczki według
     * numeru — strony paczki są pobierane jedna po drugiej, w pamięci jest naraz jedna paczka.
     *
     * @param  list<array{id: string, name: string, number: string}>  $rows
     * @return list<non-empty-list<array{id: string, name: string, number: string}>>
     */
    private static function batches(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => strcmp($a['number'], $b['number']));
        $batches = [];
        foreach ($rows as $row) {
            $batches['m:'.mb_strtolower(self::splitColour($row['name'])[0])][] = $row;
        }

        return array_values($batches);
    }

    /**
     * Paczka artykułów jednego wyrobu: strony wszystkich artykułów, potem karty (clusters). Nieodczytana strona któregoś
     * artykułu w paczce kilku artykułów = cała paczka wstrzymana w tym przebiegu (podział na karty byłby inny niż
     * w pełnym przebiegu i zostawiłby karty do scalania).
     *
     * @param  non-empty-list<array{id: string, name: string, number: string}>  $batch
     * @return list<B2bRemoteProduct>
     */
    private function batchProducts(array $batch): array
    {
        $articles = [];
        $out = [];
        $failed = false;
        foreach ($batch as $row) {
            try {
                $article = $this->readArticle($row);
            } catch (B2bFatalException $e) {
                throw $e;
            } catch (RuntimeException $e) {
                $this->readErrors[] = $row['number'].' ('.$e->getMessage().')';
                $out[$row['number']] = $this->skipped($row, 'strona artykułu nieodczytana: '.$e->getMessage());
                $failed = true;

                continue;
            }
            $reason = self::unpricedReason($article);
            if ($reason !== null) {
                $this->withoutPrice[] = $row['number'];
                $out[$row['number']] = $this->skipped($row, $reason);

                continue;
            }
            $this->withPrice++;
            $articles[] = $article;
        }
        if ($this->withPrice === 0 && $this->articlesDone >= self::MAX_FIRST_WITHOUT_PRICE) {
            throw new B2bFatalException($this->articlesDone.' pierwszych artykułów '.EltenB2bClient::HOST.' bez ceny konta w EUR — sesja konta nie pokazuje cen albo sklep zmienił stronę artykułu; przebieg przerwany');
        }

        if ($failed && count($batch) > 1) {
            foreach ($articles as $article) {
                $this->heldArticles[] = $article['number'];
                $out[$article['number']] = $this->skipped($article['row'], 'inny artykuł tego wyrobu nieodczytany — podział na karty w następnym przebiegu');
            }
            ksort($out, SORT_STRING);

            return array_values($out);
        }

        foreach (self::clusters($articles) as $cluster) {
            $out[$cluster[0]['number']] = $this->cardFor($cluster);
        }
        ksort($out, SORT_STRING);

        return array_values($out);
    }

    /**
     * @param  array{id: string, name: string, number: string}  $row
     * @return array{row: array{id: string, name: string, number: string}, id: string, name: string, number: string, base: string, colour: string, subtitle: string, features: list<array{0: string, 1: string}>, list_cents: int|null, account_text_cents: int|null, currency: string, sizes: list<array{size: string, cents: int|null, stocks: array<string, int>}>, images: list<string>}
     */
    private function readArticle(array $row): array
    {
        $this->articlesDone++;
        if ($this->articlesDone % self::PROGRESS_EVERY === 0) {
            $this->progress('Artykuły ELTEN: '.$this->articlesDone.'/'.$this->listCount);
        }
        $detail = self::parseDetail($this->client->detailPage($row['id']));
        if ($detail['number'] !== $row['number']) {
            throw new RuntimeException('strona '.$row['id'].' ma numer '.$detail['number'].' zamiast '.$row['number']);
        }
        [$base, $colour] = self::splitColour($detail['name']);

        return ['row' => $row, 'id' => $row['id'], ...$detail, 'base' => $base, 'colour' => $colour];
    }

    /**
     * Powód pominięcia artykułu bez ceny: żaden rozmiar z ceną konta albo waluta inna niż EUR; null = artykuł z ceną.
     *
     * @param  array{currency: string, sizes: list<array{cents: int|null}>}  $article
     */
    private static function unpricedReason(array $article): ?string
    {
        if ($article['currency'] !== self::CURRENCY) {
            return 'ceny artykułu w walucie „'.$article['currency'].'”, konto ma ceny w '.self::CURRENCY;
        }
        if ($article['sizes'] === []) {
            return 'artykuł bez rozmiarów w tabeli zamówienia';
        }
        foreach ($article['sizes'] as $size) {
            if ($size['cents'] === null) {
                return 'rozmiar '.$size['size'].' bez ceny konta';
            }
        }

        return null;
    }

    /**
     * Karty z artykułów paczki (artykuły według numeru): artykuł dołącza do pierwszej karty, z którą ma te same cechy
     * (featureKey) i z której każdym artykułem się zgadza — inny kolor albo, przy tej samej nazwie, rozłączne rozmiary.
     *
     * @param  list<array<string, mixed>>  $articles
     * @return list<non-empty-list<array<string, mixed>>>
     */
    private static function clusters(array $articles): array
    {
        $clusters = [];
        foreach ($articles as $article) {
            $sizes = array_column($article['sizes'], 'size');
            foreach ($clusters as $n => $cluster) {
                if (self::featureKey($cluster[0]) !== self::featureKey($article)) {
                    continue;
                }
                foreach ($cluster as $member) {
                    if ($member['colour'] === $article['colour'] && array_intersect(array_column($member['sizes'], 'size'), $sizes) !== []) {
                        continue 2;
                    }
                }
                $clusters[$n][] = $article;

                continue 2;
            }
            $clusters[] = [$article];
        }

        return $clusters;
    }

    /**
     * Cechy wyrobu bez koloru i nazwy artykułu, z podtytułem — artykuły jednej karty muszą je mieć identyczne
     * (brak cechy u jednego = różnica).
     *
     * @param  array{features: list<array{0: string, 1: string}>, subtitle: string}  $article
     */
    private static function featureKey(array $article): string
    {
        $pairs = array_values(array_filter(
            $article['features'],
            static fn (array $pair): bool => ! in_array($pair[0], [self::FEATURE_COLOUR, self::FEATURE_NAME], true),
        ));
        sort($pairs);

        return json_encode([$pairs, $article['subtitle']], JSON_UNESCAPED_UNICODE) ?: '';
    }

    /**
     * @param  non-empty-list<array<string, mixed>>  $cluster
     */
    private function cardFor(array $cluster): B2bRemoteProduct
    {
        $lead = $cluster[0];
        $colours = array_values(array_unique(array_column($cluster, 'colour')));
        $manyColours = count($colours) > 1;
        $today = CarbonImmutable::now('Europe/Berlin')->startOfDay();

        $members = [];
        $identifiers = [];
        $sizeNames = [];
        $statuses = [];
        $images = [];
        $articleColours = [];
        $cheapest = null;
        $unit = null;
        foreach ($cluster as $article) {
            $number = $article['number'];
            $base = $article['list_cents'];
            if ($base === null) {
                $this->withoutListPrice[] = $number;
            }
            $unitOnly = count($article['sizes']) === 1 && isset(self::UNIT_SIZES[$article['sizes'][0]['size']]);
            if ($unitOnly) {
                $unit = self::UNIT_SIZES[$article['sizes'][0]['size']];
            }
            $colourLabel = $article['colour'] !== '' ? $article['colour'] : $number;
            $firstOfArticle = null;
            $articleCents = [];
            foreach ($article['sizes'] as $size) {
                $label = $unitOnly ? $number : $size['size'];
                if ($manyColours) {
                    $label = $colourLabel.' / '.$label;
                }
                [$status, $availability] = self::stock($size['stocks'], $today);
                $price = self::accountPrice((int) $size['cents'], $base);
                $remoteId = $number.'/'.$size['size'];
                $members[] = [
                    'remote_id' => $remoteId,
                    'sku' => $unitOnly ? $number : $number.' '.$size['size'],
                    'name' => $unitOnly ? $article['name'] : $article['name'].' '.$size['size'],
                    'availability' => $availability,
                    'size' => $label,
                    'price' => $price,
                ];
                $firstOfArticle ??= $remoteId;
                $statuses[$status][] = $label;
                $articleCents[] = (int) $size['cents'];
                if (! $unitOnly) {
                    $sizeNames[$size['size']] = true;
                }
                if ($cheapest === null || $price->net < $cheapest->net
                    || ($price->net === $cheapest->net && B2bCatalogSync::winsSizePriceTie($price->base, $cheapest->base))) {
                    $cheapest = $price;
                }
            }
            if ($article['account_text_cents'] !== null && ! in_array($article['account_text_cents'], $articleCents, true)) {
                $this->priceTextMismatches[] = $number;
            }
            $identifiers[] = new B2bRemoteIdentifier(
                type: ProductIdentifier::TYPE_SOURCE_CODE,
                value: $number,
                remoteId: $firstOfArticle,
                label: $manyColours ? $colourLabel : null,
                field: 'Article no.',
            );
            $images[$number] = $article['images'];
            $articleColours[$number] = $article['colour'];
        }
        /** @var B2bRemotePrice $cheapest */
        $nets = array_map(static fn (array $m): float => $m['price']->net, $members);
        $this->multiPrice += count(array_unique(array_map('strval', $nets))) > 1 ? 1 : 0;
        $this->cards++;
        if ($manyColours) {
            $this->colourCards++;
        } elseif (count($cluster) > 1) {
            $this->sizeExtensionCards++;
        }

        [$description, $documents] = $this->polishContent($cluster);
        $sizes = array_map('strval', array_keys($sizeNames));
        $summary = $manyColours
            ? 'Kolory: '.implode(', ', array_values(array_unique(array_map(static fn (array $a): string => $a['colour'] !== '' ? $a['colour'] : $a['number'], $cluster)))).($sizes !== [] ? '; rozmiary: '.implode(', ', $sizes) : '')
            : ($sizes !== [] ? 'Rozmiary: '.implode(', ', $sizes) : '');

        $cardName = $this->distinctCardName(
            self::withBrand($manyColours ? $lead['base'] : $lead['name'], self::feature($lead, self::FEATURE_BRAND)),
            $manyColours ? array_column($cluster, 'colour') : array_column($cluster, 'number'),
        );

        return new B2bRemoteProduct(
            remoteId: $members[0]['remote_id'],
            sku: $lead['number'],
            name: $lead['name'],
            category: self::category($lead),
            sourceUrl: EltenB2bClient::BASE.'/detail/'.$lead['id'],
            raw: [
                'status' => 'ok',
                // cena karty = najniższa cena pozycji (B2bCatalogSync liczy ją też z members[].price)
                'price' => new B2bRemotePrice(
                    net: $cheapest->net,
                    base: $cheapest->base,
                    discountPercent: $cheapest->discountPercent,
                    currency: self::CURRENCY,
                    order: new B2bOrderQuantity(min: 1.0, step: null, unit: $unit),
                ),
                'description' => $description,
                'fields' => self::fields($cluster),
                'documents' => $documents,
                'images' => $images,
                'article_colours' => $articleColours,
                // numer artykułu każdej pozycji — zdjęcia i pliki liczone z pozycji podanego produktu
                'member_articles' => array_combine(
                    array_column($members, 'remote_id'),
                    array_map(static fn (string $id): string => explode('/', $id, 2)[0], array_column($members, 'remote_id')),
                ),
            ],
            availability: self::groupAvailability($statuses),
            variantSummary: $summary,
            members: $members,
            identifiers: $identifiers,
            cardName: $cardName !== $lead['name'] ? $cardName : null,
        );
    }

    /**
     * Opis i pliki z polskiej strony elten.com pierwszego artykułu karty (według numeru), który ją ma.
     *
     * @param  non-empty-list<array<string, mixed>>  $cluster
     * @return array{0: string, 1: list<array{title: string, url: string, kind: string, article: string}>}
     */
    private function polishContent(array $cluster): array
    {
        $pages = $this->polishPages();
        foreach ($cluster as $article) {
            $number = self::siteNumber($article['number']);
            $url = $number !== null ? ($pages[$number] ?? null) : null;
            if ($url === null) {
                continue;
            }
            if ($this->siteFailures >= self::SITE_FAILURE_LIMIT) {
                $this->polishPageProblems[] = $article['number'].' (elten.com wyłączone w tym przebiegu po '.self::SITE_FAILURE_LIMIT.' kolejnych błędach)';

                return ['', []];
            }
            try {
                $html = $this->client->sitePage($url);
                $this->siteFailures = 0;
            } catch (B2bFatalException $e) {
                throw $e;
            } catch (RuntimeException $e) {
                $this->siteFailures++;
                $this->polishPageProblems[] = $article['number'].' (nieodczytana: '.$e->getMessage().')';

                continue;
            }
            if ($html === null) {
                $this->polishPageProblems[] = $article['number'].' (strony nie ma — HTTP 404/410)';

                continue;
            }
            $page = self::parsePolishPage($html, $number);
            if ($page === null) {
                $this->polishPageProblems[] = $article['number'].' (strona innego numeru)';

                continue;
            }
            $documents = array_map(static fn (array $file): array => [
                'title' => basename(rawurldecode((string) parse_url($file['url'], PHP_URL_PATH))),
                'url' => $file['url'],
                'kind' => $file['kind'],
                'article' => $article['number'],
            ], $page['files']);

            return [implode("\n", $page['opinion']), $documents];
        }
        $this->withoutPolishPage[] = $cluster[0]['number'];

        return ['', []];
    }

    /**
     * Mapa polskich stron wyrobów elten.com (numer → adres), raz na przebieg. Mapa nieczytelna = karty bez opisu
     * (wpis w podsumowaniu), nie błąd przebiegu.
     *
     * @return array<string, string>
     */
    private function polishPages(): array
    {
        if ($this->polishPages !== null) {
            return $this->polishPages;
        }
        $this->polishPages = [];
        foreach (EltenB2bClient::SITEMAPS as $sitemap) {
            try {
                $xml = $this->client->sitePage($sitemap);
            } catch (B2bFatalException $e) {
                throw $e;
            } catch (RuntimeException $e) {
                $xml = null;
                $this->summary[] = 'Mapa elten.com nieodczytana ('.$sitemap.'): '.$e->getMessage();
            }
            if ($xml === null) {
                continue;
            }
            preg_match_all('#<loc>\s*(https://elten\.com/pl/products/[^<\s]*?-(\d+)/)\s*</loc>#', $xml, $locs, PREG_SET_ORDER);
            foreach ($locs as $loc) {
                $this->polishPages[$loc[2]] ??= html_entity_decode($loc[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }

        return $this->polishPages;
    }

    /**
     * Stan rozmiaru z ilości tygodni (data poniedziałku → ilość): tygodnie do dziś = stan teraz, potem najbliższa
     * dostawa z ilością. [status karty bez ilości, dostępność pozycji z ilością].
     *
     * @param  array<string, int>  $stocks
     * @return array{0: string, 1: string}
     */
    private static function stock(array $stocks, CarbonImmutable $today): array
    {
        $now = 0;
        foreach ($stocks as $date => $qty) {
            $day = CarbonImmutable::createFromFormat('Y/m/d', $date, 'Europe/Berlin')?->startOfDay();
            if ($day !== null && $day->lessThanOrEqualTo($today)) {
                $now += max(0, $qty);
            }
        }
        if ($now > 0) {
            return ['Na stanie', 'Na stanie: '.$now];
        }
        foreach ($stocks as $date => $qty) {
            $day = CarbonImmutable::createFromFormat('Y/m/d', $date, 'Europe/Berlin')?->startOfDay();
            if ($day !== null && $day->greaterThan($today) && $qty > 0) {
                $status = 'Brak na stanie, dostawa od '.$day->format('d.m.Y');

                return [$status, $status.': '.$qty];
            }
        }

        return ['Brak na stanie', 'Brak na stanie'];
    }

    private static function accountPrice(int $cents, ?int $baseCents): B2bRemotePrice
    {
        return new B2bRemotePrice(
            net: $cents / 100,
            base: $baseCents !== null ? $baseCents / 100 : null,
            discountPercent: $baseCents !== null && $baseCents > 0 ? round((1 - $cents / $baseCents) * 100, 2) : 0.0,
            currency: self::CURRENCY,
        );
    }

    /**
     * Tabelka karty: cechy sklepu dosłownie (wspólne dla wszystkich artykułów — tak powstała karta), kolor każdego
     * artykułu, podtytuł, numery artykułów; norma osobnym wierszem „Norma” (B2bShopFieldNormSource).
     *
     * @param  non-empty-list<array<string, mixed>>  $cluster
     * @return list<array{section: string, name: string, value: string}>
     */
    private static function fields(array $cluster): array
    {
        $lead = $cluster[0];
        $fields = [];
        $add = static function (string $section, string $name, string $value) use (&$fields): void {
            if ($value !== '') {
                $fields[] = ['section' => $section, 'name' => $name, 'value' => $value];
            }
        };
        foreach ($lead['features'] as [$label, $value]) {
            if ($label === self::FEATURE_NAME || $label === self::FEATURE_STANDARD) {
                continue;
            }
            if ($label === self::FEATURE_COLOUR) {
                $values = array_values(array_unique(array_filter(array_map(
                    static fn (array $article): string => self::feature($article, self::FEATURE_COLOUR),
                    $cluster,
                ))));
                $add(self::SECTION_MAIN, $label, implode('; ', $values));

                continue;
            }
            $add(self::SECTION_MAIN, $label, $value);
        }
        $add(self::SECTION_MAIN, 'Uwagi', $lead['subtitle']);
        $add(self::SECTION_MAIN, 'Numer artykułu', implode(', ', array_column($cluster, 'number')));
        $add(self::SECTION_NORMS, self::NORM_ROW, self::feature($lead, self::FEATURE_STANDARD));

        return $fields;
    }

    /**
     * @param  array{features: list<array{0: string, 1: string}>}  $article
     */
    private static function feature(array $article, string $label): string
    {
        foreach ($article['features'] as [$name, $value]) {
            if ($name === $label) {
                return $value;
            }
        }

        return '';
    }

    /** Kategoria: grupa towarowa po polsku > linia wyrobów dosłownie („Obuwie > BIOMEX DYNAMICS”). */
    private static function category(array $article): ?string
    {
        $commodity = self::feature($article, self::FEATURE_COMMODITY);
        $parts = array_values(array_filter([
            self::COMMODITY_GROUPS[$commodity] ?? $commodity,
            self::feature($article, self::FEATURE_PRODUCT_GROUP),
        ], static fn (string $part): bool => $part !== ''));

        return $parts !== [] ? implode(' > ', $parts) : null;
    }

    /** Nazwa nowej karty z marką na początku, gdy jej w nazwie nie ma („LOWA ZEPHYR Work GTX®…”, „JORI jo_SWIFT…”). */
    private static function withBrand(string $name, string $brand): string
    {
        $prefix = self::BRAND_PREFIX[$brand] ?? (preg_split('/\s+/u', trim($brand))[0] ?? '');
        if ($prefix === '' || preg_match('/(?<![\p{L}\p{N}])'.preg_quote($prefix, '/').'(?![\p{L}\p{N}])/iu', $name) === 1) {
            return $name;
        }

        return $prefix.' '.$name;
    }

    /**
     * Nazwa karty niepowtórzona w przebiegu: druga karta o tej samej nazwie (kolory jednego wyrobu w różnych normach,
     * ta sama nazwa z innymi cechami) dostaje dopisek z kolorami albo numerami artykułów.
     *
     * @param  list<string>  $distinguishers
     */
    private function distinctCardName(string $name, array $distinguishers): string
    {
        $key = mb_strtolower($name);
        if (isset($this->cardNames[$key])) {
            $list = array_values(array_unique(array_filter($distinguishers, static fn (string $d): bool => $d !== '')));
            $name .= ' – '.implode(', ', array_slice($list, 0, 3)).(count($list) > 3 ? '…' : '');
            $key = mb_strtolower($name);
        }
        $this->cardNames[$key] = true;

        return $name;
    }

    /**
     * Numery artykułów pozycji podanego produktu (members, bez nich — pozycja remoteId) w kolejności pozycji. Pozycja
     * o nieznanym numerze = wszystkie artykuły karty.
     *
     * @return array<string, true>
     */
    private static function productArticles(B2bRemoteProduct $product): array
    {
        $map = $product->raw['member_articles'] ?? [];
        $ids = $product->members !== []
            ? array_map(static fn (array $member): string => (string) ($member['remote_id'] ?? ''), $product->members)
            : [$product->remoteId];
        $out = [];
        foreach ($ids as $id) {
            if (! isset($map[$id])) {
                return array_fill_keys(array_values(array_unique(array_values($map))), true);
            }
            $out[$map[$id]] = true;
        }

        return $out;
    }

    /**
     * Dostępność karty: status (bez ilości, ilości zmieniają się codziennie — są przy pozycjach) z listą pozycji;
     * za długa — liczba pozycji w każdym statusie.
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
        $text = implode('; ', $parts);
        if (mb_strlen($text) <= self::AVAILABILITY_LIMIT) {
            return $text;
        }
        $total = array_sum(array_map('count', $statuses));

        return implode('; ', array_map(
            static fn (string $status, array $labels): string => $status.': '.count($labels).' z '.$total.' pozycji',
            array_keys($statuses),
            $statuses,
        )).' (stan każdej pozycji w tabeli wariantów)';
    }

    /**
     * Artykuł, którego nie da się zapisać w tym przebiegu — widoczny jako pominięty z powodem.
     *
     * @param  array{id: string, name: string, number: string}  $row
     */
    private function skipped(array $row, string $reason): B2bRemoteProduct
    {
        return new B2bRemoteProduct(
            remoteId: $row['number'],
            sku: $row['number'],
            name: $row['name'] !== '' ? $row['name'] : $row['number'],
            sourceUrl: EltenB2bClient::BASE.'/detail/'.$row['id'],
            raw: ['status' => 'skipped', 'reason' => $reason],
        );
    }

    private static function isColourWord(string $token): bool
    {
        if (preg_match('/^[a-z]+(?:-[a-z]+)*$/', $token) !== 1) {
            return false;
        }
        foreach (explode('-', $token) as $part) {
            if (! in_array($part, self::COLOUR_WORDS, true)) {
                return false;
            }
        }

        return true;
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

    /** Fragment HTML jako tekst: bez znaczników, encje rozwinięte, odstępy zwinięte. */
    private static function text(string $html): string
    {
        return self::clean(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /** Tekst ze strony: bez znaków zerowej szerokości, odstępy (także twarde spacje) zwinięte. */
    private static function clean(string $text): string
    {
        $text = str_replace(["\u{200B}", "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{FEFF}"], '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));
    }
}
