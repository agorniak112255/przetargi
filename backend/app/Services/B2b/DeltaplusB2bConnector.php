<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use DOMElement;
use DOMNode;
use DOMXPath;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * www.deltaplus.eu — witryna producenta Delta Plus z przestrzenią partnera (Liferay Commerce). Sprawdzone na koncie
 * 21.09.2026 (DeltaplusB2bClient opisuje logowanie i adresy).
 *
 * Lista oferty PL: strony kategorii ŚOI (DeltaplusB2bClient::CATEGORIES). Karta wyrobu (/pl/p/{slug}) to model
 * („APOLLON VV733”, Ref. VV733) z tabelą referencji (kolor × rozmiar), każda z EAN, dostępnością i — po zalogowaniu —
 * ceną konta w kolumnie „Cena jeśli cały karton*”. To cena PO RABACIE konta: VV733 5,70 zł wobec 12,01 zł w cenniku
 * publicznym 01/2026 (tak samo VV836, 22180, AM002 — ok. −52,5%). Headless API katalogu ma ceny 0 USD i wyroby
 * wszystkich krajów — nie używamy go.
 *
 * Cena katalogowa: z „Cennika publicznego” (xlsx ze strony „Cenniki i promocje”), wiersz MODEL + KOLOR. catalog_price_net
 * idzie jako cena do sklepu (PrestaProductExportService), więc bez niej karta sprzedawałaby się po cenie zakupu. Bierzemy
 * ją tylko przy jednoznacznym trafieniu każdej wersji karty w jedną cenę wyższą od ceny konta; inaczej jej nie ma, a karta
 * trafia do podsumowania przebiegu.
 *
 * Karta = wyrób w jednej cenie konta (decyzja użytkownika 15.09.2026, jak Raw-Pol): SKU = Ref. producenta dla największej
 * grupy cenowej, pozostałe grupy — referencja swojej pierwszej wersji. Pozycja (remote_id) = referencja wersji.
 *
 * Co bierzemy skąd na karcie: opis = krótki opis pod nazwą i „Zalety produktu” (proza producenta); linia skrótu i strefy
 * zakładki „Opis”, normy, sektory, zagrożenia i dane handlowe wersji — do tabelki sklepu (B2bShopFieldSource), nie do
 * opisu. Normy z poziomami także jako fakty producenta (B2bNormFactSource) — dosłownie („EN 388” → „2 1 2 1 X”).
 */
final class DeltaplusB2bConnector implements B2bConnector, B2bDocumentSource, B2bGroupsSizes, B2bImageGallery, B2bListProgressAware, B2bManufacturerSite, B2bNormFactSource, B2bRunSummaryAware, B2bShopFieldSource
{
    private const BRAND = 'Delta Plus';

    /** Pozycje ścieżki nawigacji, które nie są kategorią wyrobu. */
    private const BREADCRUMB_ROOTS = ['delta plus', 'ppe solutions'];

    /** Nagłówki tabeli referencji (wersja polska witryny). */
    private const COLUMN_REF = 'Referencja';

    private const COLUMN_COLOR = 'Kolor';

    private const COLUMN_SIZE = 'Rozmiar';

    private const COLUMN_MODEL = 'Model';

    private const COLUMN_EAN = 'EAN 13';

    /** GTIN kartonu wersji (na witrynie 14 cyfr) — identyfikator opakowania zbiorczego, nie sztuki. */
    private const COLUMN_CARTON_CODE = 'Kod kartonu';

    private const COLUMN_CARTON = 'Ilość w kart.';

    private const COLUMN_MIN_ORDER = 'Min. zam.';

    private const COLUMN_AVAILABILITY = 'Dostępność';

    /** Kolumna ceny konta — nagłówek zaczyna się od tego tekstu („Cena jeśli cały karton*”). */
    private const COLUMN_PRICE = 'Cena';

    /** Krótki opis dłuższy niż tyle znaków nie nadaje się na nazwę karty, której h1 jest samym kodem. */
    private const NAME_PREFIX_MAX = 60;

    /** Tyle zalogowanych stron z rzędu bez kolumny ceny = witryna zmieniła układ, dalsze pobieranie nie ma sensu. */
    private const MAX_PAGES_WITHOUT_PRICE_COLUMN = 20;

    /** Kolejność rodzajów plików przy karcie — z pierwszej karty technicznej bierzemy tekst do indeksu. */
    private const DOCUMENT_ORDER = [
        ProductDocument::KIND_DATASHEET => 0,
        ProductDocument::KIND_CERTIFICATE => 1,
        ProductDocument::KIND_MANUAL => 2,
        ProductDocument::KIND_OTHER => 3,
    ];

    private int $total = 0;

    /** @var array<string, list<float>> cennik publiczny: klucz MODEL|KOLOR → ceny */
    private array $publicPrices = [];

    /** @var list<string> */
    private array $summary = [];

    /** @var list<string> wyroby bez żadnej wersji z ceną konta */
    private array $withoutPrice = [];

    /** @var list<string> karty bez jednoznacznej ceny z cennika publicznego */
    private array $withoutBase = [];

    private int $pagesWithoutPriceColumn = 0;

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    public function __construct(private readonly DeltaplusB2bClient $client) {}

    public static function key(): string
    {
        return 'deltaplus';
    }

    public static function label(): string
    {
        return self::BRAND;
    }

    public static function host(): string
    {
        return DeltaplusB2bClient::HOST;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new DeltaplusB2bClient($account->username, (string) $account->password, $delayMs));
    }

    /** Witryna należy do tej marki — tylko jej karty wolno nadpisać opisem i normami stąd. */
    public static function ownBrand(): string
    {
        return self::BRAND;
    }

    public function login(): void
    {
        $this->client->login();
    }

    public function onListProgress(callable $callback): void
    {
        $this->listProgress = $callback;
    }

    public function products(): iterable
    {
        $this->summary = [];
        $this->withoutPrice = [];
        $this->withoutBase = [];
        $this->pagesWithoutPriceColumn = 0;

        $slugs = $this->listedSlugs();
        $this->total = count($slugs);
        $this->summary[] = 'Oferta Delta Plus (PL): '.count($slugs).' wyrobów na listach kategorii '
            .implode(', ', DeltaplusB2bClient::CATEGORIES);
        $this->loadPublicPrices();

        $cards = 0;
        $split = 0;
        foreach ($slugs as $slug) {
            $products = $this->productsFor($slug);
            $cards += count($products);
            $split += count($products) > 1 ? 1 : 0;
            // wyrób w kilku cenach to kilka kart — licznik postępu rośnie, zanim je wydamy
            $this->total += max(0, count($products) - 1);
            yield from $products;
        }
        $this->summary[] = 'Pozycje: '.$cards.' ('.$split.' wyrobów w kilku cenach — wersja w innej cenie to osobna karta)';
    }

    /**
     * Liczba pozycji: na początku liczba wyrobów z list, powiększana o każdą dodatkową kartę wyrobu w kilku cenach.
     */
    public function totalProducts(): int
    {
        return $this->total;
    }

    public function runSummary(): array
    {
        $lines = $this->summary;
        if ($this->withoutPrice !== []) {
            $lines[] = 'Bez ceny konta: '.self::listing($this->withoutPrice).' (pominięte)';
        }
        if ($this->withoutBase !== []) {
            $lines[] = 'Bez ceny katalogowej z cennika publicznego (cena katalogowa = cena konta): '.self::listing($this->withoutBase);
        }

        return $lines;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return self::BRAND;
    }

    /**
     * Cena konta z tabeli referencji; cena katalogowa z cennika publicznego tylko przy jednoznacznym trafieniu.
     * Strona nieodczytana — wyjątek z powodem (synchronizacja pyta o cenę pierwsza, więc tu pada powód pominięcia).
     */
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
            discountPercent: is_float($base) ? round((1 - $net / $base) * 100, 2) : 0.0,
            order: self::orderQuantity(is_array($product->raw['versions'] ?? null) ? $product->raw['versions'] : []),
            condition: self::priceCondition(
                (string) ($product->raw['price_note'] ?? ''),
                is_array($product->raw['versions'] ?? null) ? $product->raw['versions'] : [],
            ),
        );
    }

    /**
     * Warunek ceny konta: kolumna „Cena jeśli cały karton*” z przypisem „*Cena jednostkowa za pełny karton tego
     * samego rozmiaru i koloru” (na każdej z 999 kart, sprawdzone na koncie 24.09.2026) — cena obowiązuje przy
     * pełnych kartonach. Przypis dosłownie; ilość z kolumny „Ilość w kart.”, gdy wszystkie referencje karty mają ten
     * sam karton (14 kart ma różne kartony rozmiarów — wtedy null). Strona bez przypisu = warunku nie ma (czyści).
     *
     * @param  list<array<string, mixed>>  $versions
     */
    private static function priceCondition(string $note, array $versions): B2bPriceCondition
    {
        if (trim($note) === '') {
            return new B2bPriceCondition(null);
        }
        $cartons = [];
        foreach ($versions as $version) {
            $cartons[] = B2bOrderQuantity::attribute((string) ($version['carton'] ?? ''));
        }
        $distinct = array_unique(array_map(static fn (?float $qty): string => $qty === null ? '-' : (string) $qty, $cartons));

        return new B2bPriceCondition($note, count($distinct) === 1 && $cartons[0] !== null ? $cartons[0] : null);
    }

    /**
     * Warunek zamawiania z kolumny „Min. zam.” referencji karty — samo minimum, dosłownie. Kroku witryna nie podaje,
     * a koszyk go nie wymusza (productConfiguration każdej referencji: minOrderQuantity 1, multipleOrderQuantity 0
     * — sprawdzone na koncie 24.09.2026 na 50MAC z „Min. zam.” 12), więc step null. Jednostki tabela nie podaje
     * (cena „jednostkowa”) — unit null. Referencje karty z różnym minimum = „zależy od rozmiaru”; kolumna pusta albo
     * nieczytelna we wszystkich = null (źródło warunku nie podało).
     *
     * @param  list<array<string, mixed>>  $versions
     */
    private static function orderQuantity(array $versions): ?B2bOrderQuantity
    {
        $minimums = [];
        $unread = 0;
        foreach ($versions as $version) {
            $min = B2bOrderQuantity::attribute((string) ($version['min_order'] ?? ''));
            if ($min === null) {
                $unread++;
            } else {
                $minimums[(string) $min] = $min;
            }
        }
        if ($minimums === []) {
            return null;
        }
        if (count($minimums) > 1 || $unread > 0) {
            return new B2bOrderQuantity(null, null, varies: true);
        }

        return new B2bOrderQuantity(array_values($minimums)[0], null);
    }

    /** Krótki opis i zalety wyrobu — proza producenta, dosłownie; '' gdy strona żadnej nie ma. */
    public function description(B2bRemoteProduct $product): string
    {
        return (string) ($product->raw['description'] ?? '');
    }

    /**
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

        $add('Opis', 'Skrót', $raw['headline']);
        foreach ($raw['zones'] as $zone) {
            foreach ($zone['properties'] as $property) {
                $parts = explode(' : ', $property, 2);
                count($parts) === 2
                    ? $add($zone['title'], trim($parts[0]), trim($parts[1]))
                    : $add($zone['title'], $zone['title'], $property);
            }
        }
        $add('Normy', 'Kategoria ŚOI', $raw['ce']);
        foreach ($raw['norms'] as $norm) {
            $norm['value'] === ''
                ? $add('Normy', 'Norma', $norm['label'])
                : $add('Normy', $norm['label'], $norm['value']);
        }
        $add('Zastosowanie', 'Sektory', implode('; ', $raw['sectors']));
        $add('Zastosowanie', 'Zagrożenia', implode('; ', $raw['hazards']));
        foreach ($raw['versions'] as $version) {
            $add('Informacje handlowe', 'Referencja', $version['ref'].': '.trim($version['color'].' '.$version['size'])
                .($version['ean'] !== '' ? ', EAN '.$version['ean'] : ''));
        }
        $add('Informacje handlowe', 'Ref.', $raw['ref_source']);
        $add('Informacje handlowe', 'Ilość w kartonie', implode(', ', array_unique(array_filter(array_column($raw['versions'], 'carton')))));
        $add('Informacje handlowe', 'Minimalne zamówienie', implode(', ', array_unique(array_filter(array_column($raw['versions'], 'min_order')))));
        $add('Informacje handlowe', 'Cena', $raw['price_note']);

        return $fields;
    }

    /**
     * Kafelki norm z oznaczeniem i poziomem, dosłownie. Kafelek kategorii ŚOI nie jest normą (idzie do tabelki).
     *
     * @return list<B2bRemoteNormFact>
     */
    public function normFacts(B2bRemoteProduct $product): array
    {
        $facts = [];
        foreach ($product->raw['norms'] ?? [] as $norm) {
            $facts[] = new B2bRemoteNormFact($norm['label'], $norm['value'] === '' ? null : $norm['value']);
        }

        return $facts;
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
        $file = $this->client->fileBytes($document->sourceUrl);
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
     * Slugi wyrobów ze strony listy kategorii: odnośniki /p/{slug} (względne, z /pl/ albo pełne), bez powtórzeń.
     *
     * @return list<string>
     */
    public static function productSlugs(string $listHtml): array
    {
        if (preg_match_all('#href="(?:https://www\.deltaplus\.eu)?(?:/pl)?/p/([^"?\#/]+)[^"]*"#i', $listHtml, $m) < 1) {
            return [];
        }

        return array_values(array_unique(array_map(static fn (string $slug): string => html_entity_decode($slug), $m[1])));
    }

    /** Ostatnia strona listy — największe ?start=N w odnośnikach paginacji; 1, gdy listy nie dzielono. */
    public static function lastPage(string $listHtml): int
    {
        if (preg_match_all('#[?&](?:amp;)?start=(\d+)#', $listHtml, $m) < 1) {
            return 1;
        }

        return max(1, ...array_map('intval', $m[1]));
    }

    /** „5,70 zł‎” → 5.7, „1 234,56 zł” i „1.234,56 zł” → 1234.56; zero, pusta komórka albo brak liczby → null (brak ceny). */
    public static function parsePrice(string $cell): ?float
    {
        $text = str_replace(["\u{00A0}", "\u{202F}", ' '], '', self::clean($cell));
        // kropka przed przecinkiem dziesiętnym to separator tysięcy
        if (preg_match('/\d\.\d{3},\d/', $text) === 1) {
            $text = str_replace('.', '', $text);
        }
        if (preg_match('/\d+(?:[.,]\d+)?/', $text, $m) !== 1) {
            return null;
        }
        $value = (float) str_replace(',', '.', $m[0]);

        return $value > 0 ? $value : null;
    }

    /**
     * Cennik publiczny: MODEL|KOLOR → ceny netto. Nagłówek szukany po treści („MODEL”, „KOLOR”, „CENA…”), wiersze
     * grup (bez ceny) pomijane.
     *
     * @return array<string, list<float>>
     */
    public static function publicPrices(string $xlsxBytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'dp-cennik-');
        if ($path === false) {
            throw new RuntimeException('nie udało się utworzyć pliku tymczasowego cennika');
        }
        try {
            file_put_contents($path, $xlsxBytes);
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $rows = $reader->load($path)->getSheet(0)->toArray(null, true, false, false);
        } finally {
            @unlink($path);
        }

        $columns = null;
        $prices = [];
        foreach ($rows as $row) {
            $cells = array_map(static fn (mixed $cell): string => is_scalar($cell) ? self::clean((string) $cell) : '', $row);
            if ($columns === null) {
                $model = array_search('MODEL', array_map('mb_strtoupper', $cells), true);
                $color = array_search('KOLOR', array_map('mb_strtoupper', $cells), true);
                $price = null;
                foreach ($cells as $index => $cell) {
                    if (str_starts_with(mb_strtoupper($cell), 'CENA')) {
                        $price = $index;
                    }
                }
                if ($model !== false && $color !== false && $price !== null) {
                    $columns = ['model' => $model, 'color' => $color, 'price' => $price];
                }

                continue;
            }
            // cena zwykle liczbą; zapisana tekstem („12,01”) — przez ten sam odczyt co cena konta
            $value = $row[$columns['price']] ?? null;
            $value = is_int($value) || is_float($value) ? (float) $value : self::parsePrice(is_scalar($value) ? (string) $value : '');
            $model = $cells[$columns['model']] ?? '';
            if ($value === null || $value <= 0 || $model === '') {
                continue;
            }
            $prices[self::priceKey($model, $cells[$columns['color']] ?? '')][] = round($value, 2);
        }

        return $prices;
    }

    /**
     * Wersje z ceną konta w karty: jedna karta na cenę; SKU — patrz opis klasy.
     *
     * @param  array{ref: string, versions: list<array<string, mixed>>}  $page
     * @return list<array{sku: string, versions: list<array<string, mixed>>}>
     */
    public static function priceGroups(array $page): array
    {
        $groups = [];
        foreach ($page['versions'] as $version) {
            if ($version['price'] !== null) {
                $groups[sprintf('%.4F', $version['price'])][] = $version;
            }
        }
        if ($groups === []) {
            return [];
        }

        $main = array_key_first($groups);
        foreach ($groups as $key => $versions) {
            if (count($versions) > count($groups[$main])) {
                $main = $key;
            }
        }
        // Referencja wersji bywa równa Ref. („22180”): Ref. dostaje grupa z tą wersją, bo inna grupa wzięłaby jej
        // referencję jako własne SKU i dwie karty wyrobu miałyby ten sam kod.
        if ($page['ref'] !== '') {
            foreach ($groups as $key => $versions) {
                foreach ($versions as $version) {
                    if (strcasecmp($version['ref'], $page['ref']) === 0) {
                        $main = $key;
                    }
                }
            }
        }

        $cards = [];
        foreach ($groups as $key => $versions) {
            $sku = $key === $main && $page['ref'] !== '' ? $page['ref'] : $versions[0]['ref'];
            $cards[] = ['sku' => $sku, 'versions' => $versions];
        }

        return $cards;
    }

    /**
     * Karta wyrobu ze strony; null, gdy strona nie ma nazwy wyrobu (to nie karta).
     *
     * @return array<string, mixed>|null
     */
    public static function parseProductPage(string $html): ?array
    {
        $dom = self::dom($html);
        $name = self::first($dom, '//h1[contains(@class, "product-description__name")]');
        if ($name === '') {
            return null;
        }
        $ref = self::first($dom, '//span[contains(@class, "product-description__ref")]');
        $ref = trim((string) preg_replace('/^Ref\.?\s*:\s*/iu', '', $ref));

        $colors = [];
        foreach (self::nodes($dom, '//input[@name="colorOption"]') as $input) {
            $code = trim($input->getAttribute('value'));
            $label = self::clean($input->getAttribute('aria-label'));
            if ($code !== '' && $label !== '') {
                $colors[mb_strtolower($label)] = $code;
            }
        }

        $zones = [];
        foreach (self::nodes($dom, '//*[@id="product__tab_description__labels__container"]//div[contains(@class, "view__zone")]') as $zone) {
            $properties = [];
            foreach (self::nodes($dom, './/p[contains(@class, "view__zone_property")]', $zone) as $property) {
                $text = self::text($property);
                if ($text !== '') {
                    $properties[] = $text;
                }
            }
            $title = self::first($dom, './/p[contains(@class, "view__zone_title")]', $zone);
            if ($properties !== [] && $title !== '') {
                $zones[] = ['title' => $title, 'properties' => $properties];
            }
        }

        [$norms, $ce] = self::norms($dom);
        $table = self::referenceTable($dom);

        return [
            'name' => $name,
            // Ref. bywa zakończony podkreślnikiem („22180_”, „AM002X5V2_”) — na SKU karty bez niego; dosłownie w tabelce
            'ref' => mb_strtoupper(rtrim($ref, '_')),
            'ref_source' => $ref,
            'category_heading' => self::first($dom, '//h3[contains(@class, "product-description__category")]'),
            'short' => self::first($dom, '//p[contains(@class, "product-description__short-description")]'),
            'breadcrumb' => self::breadcrumb($dom),
            'colors' => $colors,
            'headline' => self::first($dom, '//*[@id="product__tab_description__container"]/div[1]/div[1]'),
            'zones' => $zones,
            'sectors' => self::listItems($dom, 0),
            'hazards' => self::listItems($dom, 1),
            'norms' => $norms,
            'ce' => $ce,
            'advantages' => self::advantages($dom),
            'has_table' => $table !== null,
            'price_column' => $table['price_column'] ?? false,
            'price_note' => ltrim(self::first($dom, '//div[contains(@class, "product-tables__tab-content-mention")]'), '* '),
            'versions' => $table['versions'] ?? [],
            'documents' => self::documentLinks($dom),
            'images' => self::galleryImages($dom),
        ];
    }

    /**
     * Slugi wyrobów ze wszystkich stron list kategorii, bez powtórzeń (wyrób bywa w dwóch kategoriach).
     *
     * @return list<string>
     */
    private function listedSlugs(): array
    {
        $slugs = [];
        foreach (DeltaplusB2bClient::CATEGORIES as $category) {
            $html = $this->client->categoryPage($category, 1);
            $last = self::lastPage($html);
            // licznik „218 Results” nad listą — do sprawdzenia, czy paginacja pokazała wszystkie strony
            $announced = preg_match('/(\d+)\s*Results/i', $html, $m) === 1 ? (int) $m[1] : null;
            $found = [];
            for ($page = 1; ; $page++) {
                foreach (self::productSlugs($html) as $slug) {
                    $slugs[$slug] = true;
                    $found[$slug] = true;
                }
                $this->progress('Lista: '.$category.' — strona '.$page.'/'.$last);
                if ($page >= $last) {
                    break;
                }
                $html = $this->client->categoryPage($category, $page + 1);
            }
            if ($announced !== null && count($found) < $announced) {
                $this->summary[] = 'Lista „'.$category.'”: zebrano '.count($found).' z '.$announced
                    .' wyrobów podanych przez witrynę — część oferty mogła zostać pominięta';
            }
        }
        if ($slugs === []) {
            throw new RuntimeException('Listy kategorii '.DeltaplusB2bClient::HOST.' nie zawierają żadnego wyrobu');
        }

        // slug z samych cyfr („22180”) PHP trzyma jako klucz liczbowy
        return array_map('strval', array_keys($slugs));
    }

    /** Cennik publiczny raz na przebieg; jego brak nie przerywa przebiegu — karty dostaną samą cenę konta. */
    private function loadPublicPrices(): void
    {
        $this->publicPrices = [];
        try {
            $file = $this->client->publicPriceListXlsx();
            if ($file === null) {
                $this->summary[] = 'Cennik publiczny: brak odnośnika na stronie „Cenniki i promocje” — karty bez ceny katalogowej';

                return;
            }
            $this->publicPrices = self::publicPrices($file['bytes']);
            $this->summary[] = $this->publicPrices === []
                ? 'Cennik publiczny '.$file['name'].' nie dał żadnej ceny (zmieniony układ arkusza?) — karty bez ceny katalogowej'
                : 'Cennik publiczny: '.$file['name'].' ('.count($this->publicPrices).' pozycji model+kolor)';
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->summary[] = 'Cennik publiczny nieodczytany ('.$e->getMessage().') — karty bez ceny katalogowej';
        }
    }

    /**
     * Pozycje z jednej strony wyrobu: karta na każdą cenę albo jedna pozycja pominięta z powodem; [] gdy wyrób nie ma
     * żadnej wersji z ceną (liczony w podsumowaniu).
     *
     * @return list<B2bRemoteProduct>
     */
    private function productsFor(string $slug): array
    {
        try {
            $html = $this->client->productPage($slug);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return [self::skipped($slug, 'nie udało się pobrać karty: '.$e->getMessage())];
        }
        if ($html === null) {
            return [self::skipped($slug, 'karta nie istnieje (martwy odnośnik na liście)')];
        }

        $page = self::parseProductPage($html);
        if ($page === null) {
            return [self::skipped($slug, 'strona nie zawiera karty wyrobu')];
        }
        if (! $page['has_table'] || ! $page['price_column']) {
            // zalogowana strona bez tabeli referencji albo bez kolumny ceny: jedna to wyjątek, seria to zmiana układu
            // witryny (np. nagłówki w innym języku) — wtedy przerywamy, zamiast pominąć po cichu całą ofertę
            if (++$this->pagesWithoutPriceColumn >= self::MAX_PAGES_WITHOUT_PRICE_COLUMN) {
                throw new B2bFatalException(
                    self::MAX_PAGES_WITHOUT_PRICE_COLUMN.' kolejnych kart '.DeltaplusB2bClient::HOST
                    .' bez tabeli referencji z kolumną ceny — pobieranie przerwane (zmiana układu strony?)'
                );
            }

            return [self::skipped($slug, $page['has_table'] ? 'tabela referencji bez kolumny ceny konta' : 'karta bez tabeli referencji')];
        }
        $this->pagesWithoutPriceColumn = 0;

        $groups = self::priceGroups($page);
        if ($groups === []) {
            $this->withoutPrice[] = $page['ref'] !== '' ? $page['ref'] : $slug;

            return [];
        }

        return array_map(fn (array $group): B2bRemoteProduct => $this->productFor($slug, $page, $group), $groups);
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array{sku: string, versions: list<array<string, mixed>>}  $group
     */
    private function productFor(string $slug, array $page, array $group): B2bRemoteProduct
    {
        $versions = $group['versions'];
        $first = $versions[0];
        $name = self::cardName($page);
        $base = $this->basePrice($page, $versions);
        if ($base === null) {
            $this->withoutBase[] = $group['sku'];
        }
        $colors = self::groupColorCodes($page['colors'], $versions);

        $items = array_map(static fn (array $version): array => [
            'ref' => $version['ref'],
            'label' => trim($version['color'].' '.$version['size']),
            'availability' => $version['availability'],
        ], $versions);
        $labelled = array_values(array_filter($items, static fn (array $item): bool => $item['label'] !== ''));

        return new B2bRemoteProduct(
            remoteId: $first['ref'],
            sku: $group['sku'],
            name: $name,
            category: $page['breadcrumb'] !== '' ? $page['breadcrumb'] : null,
            sourceUrl: DeltaplusB2bClient::productUrl($slug),
            raw: [
                'status' => 'ok',
                'price' => $first['price'],
                'base_price' => $base,
                'description' => self::prose($page),
                'headline' => $page['headline'],
                'ref_source' => $page['ref_source'],
                'zones' => $page['zones'],
                'ce' => $page['ce'],
                'norms' => $page['norms'],
                'sectors' => $page['sectors'],
                'hazards' => $page['hazards'],
                'versions' => $versions,
                'price_note' => $page['price_note'],
                'documents' => self::forColors($page['documents'], $colors),
                'image_urls' => array_column(self::forColors($page['images'], $colors), 'url'),
            ],
            availability: self::availabilityText($items),
            variantSummary: $labelled !== []
                ? 'Wersje: '.implode('; ', array_map(static fn (array $item): string => $item['label'].' ('.$item['ref'].')', $labelled))
                : null,
            members: count($versions) > 1
                ? array_map(static fn (array $item): array => [
                    'remote_id' => $item['ref'],
                    'sku' => $item['ref'],
                    'name' => trim($name.' '.$item['label']),
                ], $items)
                : [],
            identifiers: self::identifiers($page, $versions),
        );
    }

    /**
     * Identyfikatory karty dosłownie ze strony: Ref. wyrobu (model bez koloru i rozmiaru, bywa z „_” na końcu) dla
     * całej karty, a dla każdej wersji (pozycja = jej referencja) referencja jako kod producenta — witryna jest
     * producenta i wydaje tylko wyroby Delta Plus — oraz EAN 13 sztuki i kod kartonu (GTIN opakowania zbiorczego).
     * SKU karty nie jest identyfikatorem: przy kilku cenach to referencja wybrana przez nas.
     *
     * @param  array<string, mixed>  $page
     * @param  list<array<string, mixed>>  $versions
     * @return list<B2bRemoteIdentifier>
     */
    private static function identifiers(array $page, array $versions): array
    {
        $out = [];
        if ($page['ref_source'] !== '') {
            $out[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_MODEL_CODE, value: $page['ref_source'], field: 'Ref.');
        }
        foreach ($versions as $version) {
            $label = trim($version['color'].' '.$version['size']);
            $label = $label !== '' ? $label : null;
            foreach ([
                [ProductIdentifier::TYPE_MANUFACTURER_CODE, $version['ref_source'], self::COLUMN_REF],
                [ProductIdentifier::TYPE_EAN, $version['ean'], self::COLUMN_EAN],
                [ProductIdentifier::TYPE_PACK_EAN, $version['carton_code'], self::COLUMN_CARTON_CODE],
            ] as [$type, $value, $field]) {
                if ($value !== '') {
                    $out[] = new B2bRemoteIdentifier(type: $type, value: $value, remoteId: $version['ref'], label: $label, field: $field);
                }
            }
        }

        return $out;
    }

    /**
     * Nazwa h1; gdy h1 to sam kod (jedno słowo z cyfrą: „22180”, „AM002”) — z krótkim opisem („Sznurowadła okrągłe
     * 22180”), a gdy ten jest długim zdaniem albo go nie ma — z nagłówkiem kategorii („Ostrzegawcza zewnętrzna 208V2”).
     *
     * @param  array<string, mixed>  $page
     */
    private static function cardName(array $page): string
    {
        $name = $page['name'];
        if (str_contains($name, ' ') || preg_match('/\d/', $name) !== 1) {
            return $name;
        }
        if ($page['short'] !== '' && mb_strlen($page['short']) <= self::NAME_PREFIX_MAX) {
            return $page['short'].' '.$name;
        }

        return $page['category_heading'] !== '' ? $page['category_heading'].' '.$name : $name;
    }

    /**
     * Krótki opis i zalety (nagłówek zalety w osobnej linii nad jej tekstem), akapity rozdzielone pustą linią.
     *
     * @param  array<string, mixed>  $page
     */
    private static function prose(array $page): string
    {
        $parts = [$page['short']];
        foreach ($page['advantages'] as $advantage) {
            $parts[] = trim($advantage['title']."\n".$advantage['text']);
        }

        return mb_substr(implode("\n\n", array_filter($parts, static fn (string $part): bool => $part !== '')), 0, 10000);
    }

    /**
     * Cena z cennika publicznego: każda wersja karty musi trafić (model z h1 albo z kolumny Model tabeli + kolor)
     * i wszystkie trafienia muszą dać jedną cenę wyższą od ceny konta. Inaczej null — cennik nie rozstrzyga.
     *
     * @param  array<string, mixed>  $page
     * @param  list<array<string, mixed>>  $versions
     */
    private function basePrice(array $page, array $versions): ?float
    {
        if ($this->publicPrices === []) {
            return null;
        }
        $found = [];
        foreach ($versions as $version) {
            $prices = $this->publicPrices[self::priceKey($page['name'], $version['color'])]
                ?? $this->publicPrices[self::priceKey($version['model'], $version['color'])]
                ?? null;
            if ($prices === null) {
                return null;
            }
            foreach ($prices as $price) {
                $found[sprintf('%.2F', $price)] = $price;
            }
        }
        if (count($found) !== 1) {
            return null;
        }
        $base = reset($found);

        return $base > (float) $versions[0]['price'] ? $base : null;
    }

    /**
     * Kody kolorów wersji karty („c218”) z pól wyboru koloru; null, gdy któregoś koloru nie da się zmapować —
     * wtedy zdjęć i plików nie filtrujemy.
     *
     * @param  array<string, string>  $colors  nazwa koloru (małe litery) → kod
     * @param  list<array<string, mixed>>  $versions
     * @return list<string>|null
     */
    private static function groupColorCodes(array $colors, array $versions): ?array
    {
        $codes = [];
        foreach ($versions as $version) {
            $code = $colors[mb_strtolower($version['color'])] ?? null;
            if ($code === null) {
                return null;
            }
            $codes[$code] = $code;
        }

        return array_values($codes);
    }

    /**
     * Pozycje (zdjęcia, pliki) dla kolorów karty: bez koloru albo w kolorze którejś jej wersji.
     *
     * @param  list<array{colors: list<string>}&array<string, mixed>>  $entries
     * @param  list<string>|null  $codes
     * @return list<array<string, mixed>>
     */
    private static function forColors(array $entries, ?array $codes): array
    {
        $out = [];
        foreach ($entries as $entry) {
            // ten sam plik bywa podpięty pod kolor i bez koloru — rozstrzyga kolor; bez żadnego koloru = dla każdej wersji
            $specific = array_filter($entry['colors'], static fn (string $color): bool => $color !== '');
            if ($codes === null || $specific === [] || array_intersect($specific, $codes) !== []) {
                unset($entry['colors']);
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * @param  list<array{ref: string, availability: string}>  $items
     */
    private static function availabilityText(array $items): ?string
    {
        $texts = array_values(array_unique(array_filter(array_column($items, 'availability'))));
        if ($texts === []) {
            return null;
        }
        if (count($texts) === 1) {
            return $texts[0];
        }

        return mb_substr(implode('; ', array_map(
            static fn (array $item): string => $item['ref'].': '.($item['availability'] !== '' ? $item['availability'] : 'brak informacji'),
            $items,
        )), 0, 1000);
    }

    /** Ścieżka nawigacji bez korzenia witryny i bez samego wyrobu: „Ochrona rąk > … > Prace w środowisku mokrym”. */
    private static function breadcrumb(DOMXPath $dom): string
    {
        $parts = [];
        foreach (self::nodes($dom, '//nav[@aria-label="Breadcrumb"]//a[contains(@class, "ariane__link")]') as $link) {
            $text = self::text($link);
            if ($text !== '' && ! in_array(mb_strtolower($text), self::BREADCRUMB_ROOTS, true)) {
                $parts[] = $text;
            }
        }

        return implode(' > ', $parts);
    }

    /**
     * Pozycje zakładki „Sektory” (0) albo „Zagrożenia” (1) pod nazwą wyrobu.
     *
     * @return list<string>
     */
    private static function listItems(DOMXPath $dom, int $tab): array
    {
        $out = [];
        foreach (self::nodes($dom, '//div[contains(@class, "tabs__content")][@data-tab="'.$tab.'"]//li') as $item) {
            $text = self::text($item);
            if ($text !== '' && ! in_array($text, $out, true)) {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * Kafelki zakładki „Normy/certyfikaty”: normy (oznaczenie z nagłówka albo z opisu obrazka, poziom z drugiej komórki)
     * i osobno kategoria ŚOI z kafelka CE (bez oznaczenia normy, `test` z „ce”).
     *
     * @return array{0: list<array{label: string, value: string}>, 1: string}
     */
    private static function norms(DOMXPath $dom): array
    {
        $norms = [];
        $ce = '';
        foreach (self::nodes($dom, '//*[@id="product__tab_standard__container"]//div[contains(@class, "norm-item-card")]') as $card) {
            $tile = self::nodes($dom, './/td[@test]', $card)[0] ?? null;
            if ($tile === null) {
                continue;
            }
            $label = self::first($dom, './/h5', $tile);
            if ($label === '') {
                $label = self::clean((string) (self::nodes($dom, './/img', $tile)[0] ?? null)?->getAttribute('alt'));
            }
            $cells = self::nodes($dom, './td', $tile->parentNode);
            $value = isset($cells[1]) ? self::text($cells[1]) : '';
            if (preg_match('/(^|-)ce(-|$)/i', $tile->getAttribute('test')) === 1 && $label === '') {
                $ce = $ce !== '' ? $ce : $value;

                continue;
            }
            if ($label !== '') {
                $norms[] = ['label' => $label, 'value' => $value];
            }
        }

        return [$norms, $ce];
    }

    /**
     * @return list<array{title: string, text: string}>
     */
    private static function advantages(DOMXPath $dom): array
    {
        $out = [];
        foreach (self::nodes($dom, '//*[@id="product__tab_advantage__container"]//div[contains(@class, "product-advantage")]') as $block) {
            $title = self::first($dom, './/h5', $block);
            $text = self::first($dom, './/p', $block);
            if ($title !== '' || $text !== '') {
                $out[] = ['title' => $title, 'text' => $text];
            }
        }

        return $out;
    }

    /**
     * Tabela referencji: kolumny po nagłówkach (gość ich nie ma: Dostępność, Cena, Ilość), wiersze w kolejności strony.
     *
     * @return array{price_column: bool, versions: list<array<string, mixed>>}|null
     */
    private static function referenceTable(DOMXPath $dom): ?array
    {
        $table = self::nodes($dom, '//table[contains(@class, "logistic-table")]')[0] ?? null;
        if ($table === null) {
            return null;
        }
        $columns = [];
        foreach (self::nodes($dom, './/thead//th', $table) as $index => $head) {
            $columns[self::text($head)] = $index;
        }
        $priceColumn = null;
        foreach ($columns as $label => $index) {
            if (str_starts_with($label, self::COLUMN_PRICE)) {
                $priceColumn = $index;
            }
        }
        if (! isset($columns[self::COLUMN_REF])) {
            return null;
        }

        $versions = [];
        foreach (self::nodes($dom, './/tbody/tr', $table) as $row) {
            $cells = self::nodes($dom, './td', $row);
            $cell = static fn (string $column): string => isset($columns[$column], $cells[$columns[$column]])
                ? self::text($cells[$columns[$column]])
                : '';
            $ref = mb_strtoupper($cell(self::COLUMN_REF));
            if ($ref === '') {
                continue;
            }
            $versions[] = [
                'ref' => $ref,
                // referencja dosłownie ze strony (ref wyżej to pozycja: wielkie litery) — do identyfikatorów
                'ref_source' => $cell(self::COLUMN_REF),
                'color' => $cell(self::COLUMN_COLOR),
                'size' => $cell(self::COLUMN_SIZE),
                'model' => $cell(self::COLUMN_MODEL),
                'ean' => $cell(self::COLUMN_EAN),
                'carton_code' => $cell(self::COLUMN_CARTON_CODE),
                'carton' => $cell(self::COLUMN_CARTON),
                'min_order' => $cell(self::COLUMN_MIN_ORDER),
                'availability' => $cell(self::COLUMN_AVAILABILITY),
                'price' => $priceColumn !== null && isset($cells[$priceColumn]) ? self::parsePrice(self::text($cells[$priceColumn])) : null,
            ];
        }

        return ['price_column' => $priceColumn !== null, 'versions' => $versions];
    }

    /**
     * Pliki zakładki „Dokumentacja”: rodzaj z podpisu, nazwa z odnośnika, kolory pliku; karty techniczne pierwsze.
     *
     * @return list<array{title: string, url: string, kind: string, colors: list<string>}>
     */
    private static function documentLinks(DOMXPath $dom): array
    {
        $files = [];
        foreach (self::nodes($dom, '//*[@id="product__tab_document__container"]//div[contains(@class, "product-sheet")][@data-color]') as $sheet) {
            $link = self::nodes($dom, './/a[contains(@class, "product-sheet__link")]', $sheet)[0] ?? null;
            $url = $link !== null ? DeltaplusB2bClient::absoluteUrl($link->getAttribute('href')) : null;
            $title = $link !== null ? self::text($link) : '';
            if ($url === null || $title === '') {
                continue;
            }
            $color = trim($sheet->getAttribute('data-color'));
            if (isset($files[$url])) {
                $files[$url]['colors'][] = $color;

                continue;
            }
            $files[$url] = [
                'title' => $title,
                'url' => $url,
                'kind' => self::documentKind(self::first($dom, './/strong[contains(@class, "product-sheet__type")]', $sheet)),
                'colors' => [$color],
            ];
        }
        $files = array_values($files);
        usort($files, static fn (array $a, array $b): int => self::DOCUMENT_ORDER[$a['kind']] <=> self::DOCUMENT_ORDER[$b['kind']]);

        return $files;
    }

    private static function documentKind(string $type): string
    {
        $type = mb_strtolower($type);

        return match (true) {
            str_contains($type, 'karta techniczna') => ProductDocument::KIND_DATASHEET,
            str_contains($type, 'deklaracj') || str_contains($type, 'certyfikat') => ProductDocument::KIND_CERTIFICATE,
            str_contains($type, 'instrukcj') => ProductDocument::KIND_MANUAL,
            default => ProductDocument::KIND_OTHER,
        };
    }

    /**
     * Zdjęcia galerii (duży podgląd) w kolejności strony, z kolorami; obrót 360° nie ma podglądu i odpada.
     *
     * @return list<array{url: string, colors: list<string>}>
     */
    private static function galleryImages(DOMXPath $dom): array
    {
        $images = [];
        foreach (self::nodes($dom, '//img[contains(@class, "product-images__thumbnails__item")]') as $img) {
            $url = DeltaplusB2bClient::absoluteUrl($img->getAttribute('data-preview'));
            if ($url === null) {
                continue;
            }
            $images[$url] ??= ['url' => $url, 'colors' => []];
            $images[$url]['colors'][] = trim($img->getAttribute('data-color'));
        }

        return array_values($images);
    }

    private function progress(string $message): void
    {
        if ($this->listProgress !== null) {
            ($this->listProgress)($message);
        }
    }

    /**
     * Pozycja, której nie da się zapisać — kod i nazwa zastępczo ze sluga (B2bCatalogSync sprawdza je przed price(),
     * a dopiero price() zna prawdziwy powód).
     */
    private static function skipped(string $slug, string $reason): B2bRemoteProduct
    {
        return new B2bRemoteProduct(
            remoteId: $slug,
            sku: mb_strtoupper($slug),
            name: $slug,
            sourceUrl: DeltaplusB2bClient::productUrl($slug),
            raw: ['status' => 'skipped', 'reason' => $reason],
        );
    }

    /**
     * @param  list<string>  $items
     */
    private static function listing(array $items): string
    {
        return count($items).', np. '.implode(', ', array_slice($items, 0, 10));
    }

    private static function priceKey(string $model, string $color): string
    {
        return mb_strtoupper(self::clean($model)).'|'.mb_strtoupper(self::clean($color));
    }

    /**
     * @return list<DOMElement>
     */
    private static function nodes(DOMXPath $dom, string $query, ?DOMNode $context = null): array
    {
        $found = $dom->query($query, $context);
        $out = [];
        foreach ($found !== false ? $found : [] as $node) {
            if ($node instanceof DOMElement) {
                $out[] = $node;
            }
        }

        return $out;
    }

    private static function first(DOMXPath $dom, string $query, ?DOMNode $context = null): string
    {
        $node = self::nodes($dom, $query, $context)[0] ?? null;

        return $node !== null ? self::text($node) : '';
    }

    private static function text(DOMNode $node): string
    {
        return self::clean((string) $node->textContent);
    }

    /** Tekst ze strony: bez znaków sterujących kierunkiem i spacji zerowej szerokości (U+200B, U+200E…), odstępy zwinięte. */
    private static function clean(string $text): string
    {
        $text = str_replace(["\u{200B}", "\u{200C}", "\u{200D}", "\u{200E}", "\u{200F}", "\u{FEFF}"], '', $text);

        return trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)));
    }

    private static function dom(string $html): DOMXPath
    {
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // <br> dzieli wartości w komórce („3<br>1*” przy EN 343 to dwie klasy) — w tekście ma zostać odstęp
        $document->loadHTML('<?xml encoding="UTF-8">'.(preg_replace('#<br\s*/?>#i', ' ', $html) ?? $html));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }
}
