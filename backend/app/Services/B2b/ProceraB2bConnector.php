<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

/**
 * Panel B2B Procery b2b.procera.pl — producent i dystrybutor odzieży i ŚOI (własne linie X-…, PROMAN, PROCERA, EVO,
 * TEXO…) oraz wyroby 3M, Bollé, Lakeland, BLS. Sprawdzone na koncie 21.09.2026.
 *
 * Lista (1026 pozycji po 20 na stronę) to MODELE: wiersz pokazuje kod, cenę i dostępność jednego z rozmiarów. Pełna
 * lista idzie przed pierwszym zapisem, z tymi samymi kontrolami spójności co UVEX (licznik „od X do Y z Z”, liczba
 * wierszy, unikalne id; zmiana w trakcie = jedno ponowne pobranie). Potem strona każdego modelu — raz na model.
 *
 * Ceny konta bierzemy ze strony wyrobu: tabela rozmiarów (#variants-table) ma cenę netto każdego rozmiaru
 * (data-price-net), a rozmiary jednego modelu bywają w różnych cenach (ATLANTIS S1PL: 39 za 49 zł, 46 i 47 za 89 zł).
 * Model bez tabeli rozmiarów ma jedną cenę (#b2b-price-discounted). Cennik XML konta NIE jest źródłem ceny konta:
 * w 42 z 1026 modeli jego CENA_KLIENTA różni się od ceny na stronie (inna jednostka — skarpety za parę zamiast
 * opakowania, wyprzedaż rozmiarów). Z XML bierzemy tylko cenę katalogową (CENA_CENNIKOWA_HURT), i to wyłącznie gdy
 * kod modelu trafia dokładnie, a CENA_KLIENTA równa się cenie karty — wtedy wiadomo, że to ten sam wyrób w tej samej
 * jednostce. Inaczej karta nie ma ceny katalogowej (liczone w podsumowaniu przebiegu).
 *
 * Karta = rozmiary jednego modelu w jednej cenie konta (decyzja użytkownika 15.09.2026: rozmiar w innej cenie to
 * osobna karta). SKU: kod modelu (kod rozmiaru bez oznaczenia rozmiaru, np. „X-DUOMAX 9” → „X-DUOMAX”), gdy model
 * jest jedną kartą, a cennik XML zna ten kod; inaczej — i przy modelu podzielonym cenami — kod pierwszego rozmiaru
 * karty (kod modelu nie może przechodzić między kartami, gdy zmieni się podział). Pozycje (members) = kody rozmiarów.
 * Nowa karta z przebiegu bez cennika XML zostaje z kodem rozmiaru (synchronizacja nie zmienia SKU), a kolejne
 * przebiegi i tak ją znajdą po powiązaniach rozmiarów.
 *
 * Wiersz listy musi się zgadzać ze stroną: kod z listy jest jednym z kodów rozmiarów, a jego cena z listy jest ceną
 * tego rozmiaru na stronie. Rozjazd = model pominięty z powodem (czytalibyśmy złe pole albo cudzą stronę).
 *
 * Opis: szablon „product-description-modern” — wstęp (p.lead) i zalety (feature-box) jako proza; kafelki norm
 * i cech (icon-box) i tabela parametrów (spec-row) idą do tabelki sklepu (B2bShopFieldSource). Opis zwykły (3M,
 * Bollé, część własnych) dosłownie, z podziałem linii ze źródła.
 *
 * Producent — ZAŁOŻENIE (sklep nie ma pola producenta): marka z zamkniętej listy rozpoznana w kodzie albo nazwie
 * (3M, Bolle, Lakeland, BLS), inaczej dostawca „Procera” — także dla wyrobów bez marki w nazwie (znaki, gaśnice,
 * apteczki), co podsumowanie przebiegu liczy osobno. Dlatego łącznik nie jest B2bManufacturerSite: opis stąd nie
 * nadpisuje opisów z innych źródeł.
 */
final class ProceraB2bConnector implements B2bConnector, B2bDocumentSource, B2bImageGallery, B2bListProgressAware, B2bRunSummaryAware, B2bShopFieldSource
{
    public const SUPPLIER = 'Procera';

    /** Marki rozpoznawane w nazwie wyrobu (całe słowo) → nazwa producenta jak w pozostałych łącznikach. */
    private const BRANDS = [
        '3M' => '3M',
        'BOLLE' => 'Bolle',
        'BOLLÉ' => 'Bolle',
        'LAKELAND' => 'Lakeland',
        'BLS' => 'BLS',
    ];

    /** Nieprzerwane pobieranie listy dłużej = błąd (przebieg bez postępu uznałby b2b:sync-due za przerwany). */
    private const LIST_BUDGET_SECONDS = 25 * 60;

    private const PROGRESS_EVERY_PAGES = 10;

    private const DOCUMENTS_LIMIT = 8;

    private const IMAGES_LIMIT = 8;

    private const INCONSISTENT = 7101;

    /** Nagłówki kolumn tabeli rozmiarów (porównywane bez wielkości liter). */
    private const COLUMN_CODE = 'kod';

    private const COLUMN_AVAILABILITY = 'dostępność';

    private const COLUMN_DELIVERY = 'termin dostawy';

    private const SHOP_SECTION_TRADE = 'Informacje handlowe';

    private const SHOP_SECTION_TECHNICAL = 'Dane techniczne';

    private const SHOP_SECTION_MARKS = 'Oznaczenia';

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    private const SKIPPED_TAGS = ['input', 'button', 'select', 'option', 'textarea', 'img', 'iframe', 'svg', 'script', 'style', 'noscript'];

    private const BLOCK_TAGS = ['p', 'div', 'section', 'article', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'dl', 'dt', 'dd', 'table', 'thead', 'tbody', 'tr', 'blockquote', 'pre', 'hr'];

    private int $total = 0;

    /** @var array<string, array{client: float, catalog: float, ean: string}> cennik XML: kod modelu → ceny */
    private array $priceList = [];

    /** @var list<string> */
    private array $summary = [];

    /** @var list<string> karty bez ceny katalogowej */
    private array $withoutBase = [];

    /** @var list<string> kody rozmiarów bez ceny konta (pominięte na kartach swojego modelu) */
    private array $sizesWithoutPrice = [];

    /** @var array<string, true> karty z producentem przyjętym jako dostawca (remoteId) */
    private array $supplierAsManufacturer = [];

    private int $cards = 0;

    private int $splitModels = 0;

    private int $skippedModels = 0;

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    public function __construct(private readonly ProceraB2bClient $client) {}

    public static function key(): string
    {
        return 'procera';
    }

    public static function label(): string
    {
        return self::SUPPLIER;
    }

    public static function host(): string
    {
        return ProceraB2bClient::HOST;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new ProceraB2bClient(
            (string) $account->contractor_code,
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
        $this->supplierAsManufacturer = [];
        $this->cards = 0;
        $this->splitModels = 0;
        $this->skippedModels = 0;

        $rows = $this->listRows();
        $this->total = count($rows);
        $this->summary[] = 'Lista Procera: '.count($rows).' modeli';
        $this->loadPriceList();

        foreach ($rows as $row) {
            $products = $this->productsFor($row);
            // model w kilku cenach to kilka kart — licznik postępu rośnie, zanim je wydamy
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
            'Karty: %d (%d modeli w kilku cenach — rozmiary w innej cenie to osobna karta; %d modeli pominiętych)',
            $this->cards,
            $this->splitModels,
            $this->skippedModels,
        );
        if ($this->sizesWithoutPrice !== []) {
            $lines[] = 'Rozmiary bez ceny konta (poza kartami): '.self::listing($this->sizesWithoutPrice);
        }
        if ($this->withoutBase !== []) {
            $lines[] = 'Bez ceny katalogowej z cennika XML (cena katalogowa = cena konta): '.self::listing($this->withoutBase);
        }
        if ($this->supplierAsManufacturer !== []) {
            $lines[] = 'Bez marki w nazwie — producent przyjęty jako Procera: '.self::listing(array_keys($this->supplierAsManufacturer));
        }

        return $lines;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        $code = (string) ($product->raw['model_code'] ?? '');
        $code = mb_strtoupper($code !== '' ? $code : $product->sku);
        if (str_starts_with($code, '3M')) {
            return '3M';
        }
        $name = mb_strtoupper(($product->raw['model_name'] ?? $product->name).' '.$code);
        foreach (self::BRANDS as $word => $brand) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/u', $name) === 1) {
                return $brand;
            }
        }
        $this->supplierAsManufacturer[$product->remoteId] = true;

        return self::SUPPLIER;
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
            discountPercent: is_float($base) ? round((1 - $net / $base) * 100, 2) : 0.0,
        );
    }

    /** Proza wyrobu ze strony (wstęp i zalety albo zwykły opis), dosłownie; '' gdy strona nie ma opisu. */
    public function description(B2bRemoteProduct $product): string
    {
        return (string) ($product->raw['description'] ?? '');
    }

    /**
     * Kod modelu, jednostka i EAN z panelu; parametry (spec-row) i kafelki norm i cech (icon-box) dosłownie.
     * Wszystko z raz pobranej strony — metoda niczego nie pobiera.
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

        $add(self::SHOP_SECTION_TRADE, 'Kod modelu', (string) ($raw['model_code'] ?? ''));
        $add(self::SHOP_SECTION_TRADE, 'Jednostka sprzedaży', (string) ($raw['unit'] ?? ''));
        if (($raw['ean'] ?? '') !== '') {
            $add(self::SHOP_SECTION_TRADE, 'EAN', $raw['ean'].' ('.$raw['ean_code'].')');
        }
        foreach ($raw['specs'] ?? [] as [$label, $value]) {
            $add(self::SHOP_SECTION_TECHNICAL, $label, $value);
        }
        $add(self::SHOP_SECTION_MARKS, 'Piktogramy', implode('; ', $raw['icons'] ?? []));

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
     * Galeria strony wyrobu; miniatura z listy tylko wtedy, gdy strona galerii nie ma (to te same zdjęcia).
     *
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

    /** „1 133,05 PLN” → 113305; inny zapis (bez waluty, inna waluta) = null — nie zgadujemy. */
    public static function priceCents(string $text): ?int
    {
        if (preg_match('/^(\d{1,3}(?:[ \x{00A0}]\d{3})*|\d+),(\d{2})\s*PLN$/u', trim($text), $m) !== 1) {
            return null;
        }

        return (int) preg_replace('/\D/', '', $m[1]) * 100 + (int) $m[2];
    }

    /**
     * Kod modelu z kodów rozmiarów: każdy kod bez swojego oznaczenia rozmiaru (całe słowo, pierwsze wystąpienie)
     * — „X-DUOMAX 9” → „X-DUOMAX”, „EVO S1 36 PROMOCJA KARTON!” → „EVO S1 PROMOCJA KARTON!”. Wszystkie muszą dać ten
     * sam kod; inaczej null (nie zgadujemy rdzenia).
     *
     * @param  list<array{code: string, size: string}>  $variants
     */
    public static function modelCode(array $variants): ?string
    {
        $model = null;
        foreach ($variants as $variant) {
            $size = trim($variant['size']);
            if ($size === '') {
                return null;
            }
            $pattern = '/(?<=^|\s)'.preg_quote($size, '/').'(?=\s|$)/u';
            if (preg_match($pattern, $variant['code']) !== 1) {
                return null;
            }
            $stripped = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace($pattern, '', $variant['code'], 1)));
            if ($stripped === '' || ($model !== null && $stripped !== $model)) {
                return null;
            }
            $model = $stripped;
        }

        return $model;
    }

    /**
     * Strona wyrobu → kod i EAN pokazywanego rozmiaru, rozmiary z cenami, opis, parametry, pliki, zdjęcia.
     * Nieczytelna budowa = wyjątek z powodem (model pominięty, reszta przebiegu idzie dalej).
     *
     * @return array{code: string, ean: string, single: bool, variants: list<array{code: string, size: string, delivery: string, availability: string, cents: int|null}>, description: string, specs: list<array{0: string, 1: string}>, icons: list<string>, documents: list<array{title: string, url: string, kind: string}>, images: list<string>}
     */
    public static function parsePage(string $html): array
    {
        $xpath = JspB2bClient::dom($html);
        $code = self::text($xpath->query('//td['.JspB2bClient::classPredicate('codeViewProductDane').']')->item(0));
        if ($code === '') {
            throw new RuntimeException('brak kodu na stronie wyrobu');
        }

        $variants = self::variantRows($xpath);
        $single = $variants === [];
        if ($single) {
            $priceText = self::text($xpath->query('//*[@id="b2b-price-discounted"]')->item(0));
            $variants[] = [
                'code' => $code,
                'size' => '',
                'delivery' => '',
                'availability' => self::text($xpath->query('//table['.JspB2bClient::classPredicate('table-product-single').']//*['.JspB2bClient::classPredicate('stanViewProductDane').']')->item(0)),
                'cents' => self::priceCents($priceText),
            ];
        }

        return [
            'code' => $code,
            'ean' => self::text($xpath->query('//td['.JspB2bClient::classPredicate('eanViewProductDane').']')->item(0)),
            'single' => $single,
            'variants' => $variants,
            'description' => self::descriptionOf($xpath),
            'specs' => self::specPairs($xpath),
            'icons' => self::iconLabels($xpath),
            'documents' => self::documentsOf($xpath),
            'images' => self::galleryUrls($xpath),
        ];
    }

    /**
     * Wiersze tabeli rozmiarów — kolumny po nagłówku (Kod, cechy jak ROZMIAR, czasem TERMIN DOSTAWY, Dostępność),
     * cena z data-price-net pola ilości. Brak tabeli = [] (wyrób bez rozmiarów).
     *
     * @return list<array{code: string, size: string, delivery: string, availability: string, cents: int|null}>
     */
    private static function variantRows(DOMXPath $xpath): array
    {
        $rows = [];
        foreach ($xpath->query('//tbody[@id="variants-table-body"]/tr') ?: [] as $tr) {
            if ($xpath->query('.//input['.JspB2bClient::classPredicate('p-quantity').']', $tr)->item(0) !== null) {
                $rows[] = $tr;
            }
        }
        if ($rows === []) {
            return [];
        }

        $headers = [];
        foreach ($xpath->query('//thead[@id="variantsHeaderTemplate"]//th') ?: [] as $th) {
            $headers[] = mb_strtolower(self::text($th));
        }
        $codeAt = array_search(self::COLUMN_CODE, $headers, true);
        $availabilityAt = array_search(self::COLUMN_AVAILABILITY, $headers, true);
        if ($codeAt === false || $availabilityAt === false || $availabilityAt <= $codeAt) {
            throw new RuntimeException('tabela rozmiarów bez kolumn „Kod” i „Dostępność” ('.implode(', ', $headers).')');
        }
        $deliveryAt = null;
        $featureAt = [];
        for ($i = $codeAt + 1; $i < $availabilityAt; $i++) {
            if ($headers[$i] === self::COLUMN_DELIVERY) {
                $deliveryAt = $i;
            } else {
                $featureAt[] = $i;
            }
        }
        if ($featureAt === [] && count($rows) > 1) {
            throw new RuntimeException('tabela rozmiarów bez kolumny rozmiaru przy '.count($rows).' wierszach');
        }

        $variants = [];
        foreach ($rows as $tr) {
            $cells = [];
            foreach ($xpath->query('./td', $tr) ?: [] as $td) {
                $cells[] = self::text($td);
            }
            if (count($cells) <= $availabilityAt) {
                throw new RuntimeException('wiersz tabeli rozmiarów z '.count($cells).' kolumnami');
            }
            $input = $xpath->query('.//input['.JspB2bClient::classPredicate('p-quantity').']', $tr)->item(0);
            $net = $input instanceof DOMElement ? trim($input->getAttribute('data-price-net')) : '';
            $sizes = array_values(array_filter(array_map(static fn (int $i): string => $cells[$i], $featureAt), static fn (string $s): bool => $s !== ''));
            $variants[] = [
                'code' => $cells[$codeAt],
                'size' => implode(' / ', $sizes),
                'delivery' => $deliveryAt !== null ? $cells[$deliveryAt] : '',
                'availability' => $cells[$availabilityAt],
                'cents' => preg_match('/^\d+(?:\.\d+)?$/', $net) === 1 ? (int) round((float) $net * 100) : null,
            ];
            if ($cells[$codeAt] === '') {
                throw new RuntimeException('wiersz tabeli rozmiarów bez kodu');
            }
        }

        return $variants;
    }

    /**
     * Cała lista; niespójna — jedno ponowne pobranie od początku.
     *
     * @return list<array{id: string, code: string, name: string, price_text: string, availability: string, unit: string, detail_url: string, image_url: string}>
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

            throw new RuntimeException('Lista produktów '.ProceraB2bClient::HOST.' niespójna także po ponownym pobraniu: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return list<array{id: string, code: string, name: string, price_text: string, availability: string, unit: string, detail_url: string, image_url: string}>
     */
    private function scanList(): array
    {
        $started = microtime(true);
        $rows = [];
        $seen = [];
        $total = 0;
        $pageSize = 0;
        $pages = 1;

        for ($page = 1; $page <= $pages; $page++) {
            if (microtime(true) - $started > self::LIST_BUDGET_SECONDS) {
                throw new RuntimeException('Pobieranie listy '.ProceraB2bClient::HOST.' trwa ponad '.(self::LIST_BUDGET_SECONDS / 60).' min — przerwane bez zapisu');
            }
            $xpath = JspB2bClient::dom($this->client->listPage($page));
            $info = self::paginationInfo($xpath);
            $pageRows = self::parseRows($xpath);

            if ($info === null) {
                if ($page === 1) {
                    throw new RuntimeException('Lista produktów '.ProceraB2bClient::HOST.' bez licznika rekordów — pusta lista konta albo zmiana strony sklepu');
                }

                throw new RuntimeException('strona '.$page.' bez licznika rekordów', self::INCONSISTENT);
            }
            [$from, $to, $pageTotal] = $info;
            if ($page === 1) {
                $total = $pageTotal;
                $pageSize = $to - $from + 1;
                if ($total <= 0 || $from !== 1 || $pageSize <= 0) {
                    throw new RuntimeException('Lista produktów '.ProceraB2bClient::HOST.' ma nieoczekiwany licznik: od '.$from.' do '.$to.' z '.$total);
                }
                $pages = (int) ceil($total / $pageSize);
                $this->progress('Lista produktów Procera: '.$total.' modeli na '.$pages.' stronach');
            } elseif ($pageTotal !== $total) {
                throw new RuntimeException('liczba produktów zmieniła się z '.$total.' na '.$pageTotal.' (strona '.$page.')', self::INCONSISTENT);
            }

            $expectedFrom = ($page - 1) * $pageSize + 1;
            $expectedTo = min($page * $pageSize, $total);
            if ($from !== $expectedFrom || $to !== $expectedTo || count($pageRows) !== $to - $from + 1) {
                throw new RuntimeException(sprintf(
                    'strona %d: licznik od %d do %d, oczekiwano od %d do %d, wierszy %d',
                    $page, $from, $to, $expectedFrom, $expectedTo, count($pageRows),
                ), self::INCONSISTENT);
            }

            foreach ($pageRows as $row) {
                if ($row['price_text'] === '') {
                    throw new RuntimeException('Strona '.$page.' listy '.ProceraB2bClient::HOST.': pozycja '.$row['code'].' bez ceny konta — pobieranie przerwane (utracony widok cen konta?)');
                }
                if (isset($seen[$row['id']])) {
                    throw new RuntimeException('produkt id '.$row['id'].' ('.$row['code'].') na dwóch stronach', self::INCONSISTENT);
                }
                $seen[$row['id']] = true;
                $rows[] = $row;
            }

            if ($page > 1 && ($page % self::PROGRESS_EVERY_PAGES === 0 || $page === $pages)) {
                $this->progress('Lista produktów Procera: strona '.$page.'/'.$pages);
            }
        }

        if (count($rows) !== $total) {
            throw new RuntimeException('pobrano '.count($rows).' z '.$total.' pozycji', self::INCONSISTENT);
        }

        return $rows;
    }

    /**
     * Wiersze listy: id modelu z klasy odnośnika „urlViewProductCart_{id}”, każde pole z elementu z klasą z tym samym
     * numerem (jak w panelu UVEX — nie przez <tr>, bo libxml przestawia formularz koszyka w komórce).
     *
     * @return list<array{id: string, code: string, name: string, price_text: string, availability: string, unit: string, detail_url: string, image_url: string}>
     */
    private static function parseRows(DOMXPath $xpath): array
    {
        $rows = [];
        foreach ($xpath->query('//a[starts-with(@class, "urlViewProductCart_")]') ?: [] as $link) {
            if (! $link instanceof DOMElement || preg_match('/^urlViewProductCart_(\d+)$/', trim($link->getAttribute('class')), $m) !== 1) {
                continue;
            }
            $id = $m[1];
            if (isset($rows[$id])) {
                continue;
            }
            $byClass = static fn (string $class): string => '//*['.JspB2bClient::classPredicate($class.$id).']';
            $codeNode = $xpath->query($byClass('codeViewProductDane_'))->item(0);
            // nazwa: pierwszy <span> komórki z klasą urlViewProductCart_{id}, w której jest kod
            $nameNode = $codeNode !== null
                ? $xpath->query('ancestor::*['.JspB2bClient::classPredicate('urlViewProductCart_'.$id).'][1]/span[1]', $codeNode)->item(0)
                : null;
            $image = $xpath->query('//*[@id="B2B_fotorama-'.$id.'"]//a[@data-full]')->item(0);
            $href = html_entity_decode($link->getAttribute('href'), ENT_QUOTES | ENT_HTML5);

            $rows[$id] = [
                'id' => $id,
                'code' => self::text($codeNode),
                'name' => self::text($nameNode),
                'price_text' => self::text($xpath->query($byClass('twojaCenaNetto_'))->item(0)),
                'availability' => self::text($xpath->query($byClass('stanViewProductDane_'))->item(0)),
                'unit' => self::text($xpath->query($byClass('panelDodawaniaProduktuDoKoszyka_').'//*['.JspB2bClient::classPredicate('input-group-addon').']')->item(0)),
                'detail_url' => ProceraB2bClient::isShopUrl($href) ? $href : '',
                'image_url' => $image instanceof DOMElement ? self::imageUrl($image->getAttribute('data-full')) ?? '' : '',
            ];
        }

        return array_values($rows);
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null
     */
    private static function paginationInfo(DOMXPath $xpath): ?array
    {
        $text = self::text($xpath->query('//*['.JspB2bClient::classPredicate('pagination-info').']')->item(0));
        if (preg_match('/od\s+(\d+)\s+do\s+(\d+)\s+z\s+(\d+)/u', $text, $m) !== 1) {
            return null;
        }

        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }

    /**
     * Cennik XML konta: kod modelu → cena klienta, cena cennikowa hurtowa, EAN. Kod powtórzony = oba wiersze
     * odrzucone (nie wiadomo, który jest właściwy). Błąd pobrania = przebieg bez cen katalogowych, z powodem.
     */
    private function loadPriceList(): void
    {
        $this->priceList = [];
        try {
            $xml = $this->client->priceListXml();
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $this->summary[] = 'Cennik XML nie został pobrany ('.$e->getMessage().') — karty bez ceny katalogowej';

            return;
        }

        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, options: LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($doc === false) {
            $this->summary[] = 'Cennik XML nieczytelny — karty bez ceny katalogowej';

            return;
        }

        $rows = [];
        $repeated = [];
        foreach ($doc->product as $product) {
            $code = self::clean((string) $product->product_code);
            $client = self::decimal((string) $product->CENA_KLIENTA);
            $catalog = self::decimal((string) $product->CENA_CENNIKOWA_HURT);
            $currency = mb_strtoupper(self::clean((string) $product->currency));
            if ($code === '' || $client === null || $catalog === null || ($currency !== '' && $currency !== 'PLN')) {
                continue;
            }
            if (isset($rows[$code])) {
                $repeated[$code] = true;

                continue;
            }
            $rows[$code] = [
                'client' => $client,
                'catalog' => $catalog,
                'ean' => self::clean((string) ($product->barcodes->ean ?? '')),
            ];
        }
        foreach (array_keys($repeated) as $code) {
            unset($rows[$code]);
        }
        $this->priceList = $rows;
        $this->summary[] = 'Cennik XML: '.count($rows).' modeli'.($repeated !== [] ? ' (kody powtórzone, pominięte: '.implode(', ', array_keys($repeated)).')' : '');
    }

    /**
     * Karty jednego modelu (jedna na każdą cenę konta); model nieczytelny albo niezgodny z listą — jedna pozycja
     * pominięta z powodem.
     *
     * @param  array{id: string, code: string, name: string, price_text: string, availability: string, unit: string, detail_url: string, image_url: string}  $row
     * @return list<B2bRemoteProduct>
     */
    private function productsFor(array $row): array
    {
        if ($row['detail_url'] === '') {
            return [$this->skipped($row, 'wiersz listy bez adresu strony wyrobu')];
        }
        try {
            $page = self::parsePage($this->client->productPage($row['detail_url']));
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return [$this->skipped($row, 'strona wyrobu: '.$e->getMessage())];
        }

        $listed = null;
        foreach ($page['variants'] as $variant) {
            if ($variant['code'] === $row['code']) {
                $listed = $variant;
            }
        }
        if ($listed === null) {
            return [$this->skipped($row, 'strona wyrobu nie pokazuje kodu z listy ('.$row['code'].')')];
        }
        $listCents = self::priceCents($row['price_text']);
        if ($listCents === null || $listCents !== $listed['cents']) {
            return [$this->skipped($row, 'cena z listy („'.$row['price_text'].'”) inna niż cena rozmiaru '.$row['code'].' na stronie wyrobu')];
        }

        /** @var array<int, list<array{code: string, size: string, delivery: string, availability: string, cents: int|null}>> $groups */
        $groups = [];
        foreach ($page['variants'] as $variant) {
            if ($variant['cents'] !== null && $variant['cents'] > 0) {
                $groups[$variant['cents']][] = $variant;
            } else {
                $this->sizesWithoutPrice[] = $variant['code'];
            }
        }
        if ($groups === []) {
            return [$this->skipped($row, 'żaden rozmiar nie ma ceny konta')];
        }

        // kod modelu tylko potwierdzony cennikiem XML (Procera go używa) — wyliczony z kodów rozmiarów bywa kodem,
        // którego sklep nie zna („DESERT WORK SOCKS 43-46 OPAK” → „DESERT WORK SOCKS OPAK”, a w XML „DESERT WORK SOCKS”)
        $modelCode = $page['single'] ? $page['code'] : self::modelCode($page['variants']);
        if (! $page['single'] && $modelCode !== null && ! isset($this->priceList[$modelCode])) {
            $modelCode = null;
        }
        $split = count($groups) > 1;
        $this->splitModels += $split ? 1 : 0;

        $products = [];
        foreach ($groups as $cents => $group) {
            $products[] = $this->productFor($row, $page, $group, $cents, $modelCode, $split);
        }
        $this->cards += count($products);

        return $products;
    }

    /**
     * @param  array{id: string, code: string, name: string, price_text: string, availability: string, unit: string, detail_url: string, image_url: string}  $row
     * @param  array<string, mixed>  $page
     * @param  list<array{code: string, size: string, delivery: string, availability: string, cents: int|null}>  $group
     */
    private function productFor(array $row, array $page, array $group, int $cents, ?string $modelCode, bool $split): B2bRemoteProduct
    {
        $first = $group[0];
        $sku = ! $split && $modelCode !== null ? $modelCode : $first['code'];
        $net = $cents / 100;

        $base = null;
        $xml = $modelCode !== null ? ($this->priceList[$modelCode] ?? null) : null;
        if ($xml !== null && abs($xml['client'] - $net) < 0.005 && $xml['catalog'] > $net) {
            $base = $xml['catalog'];
        } else {
            $this->withoutBase[] = $sku;
        }

        $name = $row['name'] !== '' ? $row['name'] : $sku;
        $sizes = array_values(array_filter(array_column($group, 'size'), static fn (string $s): bool => $s !== ''));
        if ($split && $sizes !== []) {
            $name .= ' (rozm. '.implode(', ', $sizes).')';
        }

        $members = [];
        $summary = '';
        if (! $page['single']) {
            foreach ($group as $variant) {
                $members[] = [
                    'remote_id' => $variant['code'],
                    'sku' => $variant['code'],
                    'name' => trim(($row['name'] !== '' ? $row['name'] : $variant['code']).' '.$variant['size']),
                ];
            }
            $summary = 'Rozmiary: '.implode('; ', array_map(
                static fn (array $v): string => ($v['size'] !== '' ? $v['size'] : '—').' ('.$v['code'].')',
                $group,
            ));
        }

        $images = $page['images'];
        if ($images === [] && $row['image_url'] !== '') {
            $images = [$row['image_url']];
        }

        return new B2bRemoteProduct(
            remoteId: $first['code'],
            sku: $sku,
            name: $name,
            sourceUrl: $row['detail_url'],
            raw: [
                'status' => 'ok',
                'price' => $net,
                'base_price' => $base,
                'model_code' => $modelCode ?? '',
                'model_name' => $row['name'],
                'unit' => $row['unit'],
                'ean' => $page['ean'],
                'ean_code' => $page['code'],
                'description' => $page['description'],
                'specs' => $page['specs'],
                'icons' => $page['icons'],
                'documents' => $page['documents'],
                'image_urls' => $images,
            ],
            availability: self::groupAvailability($group),
            variantSummary: $summary,
            members: $members,
        );
    }

    /**
     * @param  array{id: string, code: string, name: string, price_text: string, availability: string, unit: string, detail_url: string, image_url: string}  $row
     */
    private function skipped(array $row, string $reason): B2bRemoteProduct
    {
        $this->skippedModels++;
        $code = $row['code'] !== '' ? $row['code'] : 'id '.$row['id'];

        return new B2bRemoteProduct(
            remoteId: $code,
            sku: $code,
            name: $row['name'] !== '' ? $row['name'] : $code,
            sourceUrl: $row['detail_url'] !== '' ? $row['detail_url'] : null,
            raw: ['status' => 'skipped', 'reason' => $reason, 'model_name' => $row['name']],
        );
    }

    /**
     * Jedna dostępność dla wszystkich rozmiarów — dosłownie; różne — „Dostępny: 39, 41; Czasowo niedostępny: 40”.
     * Termin dostawy z tabeli dopisany w nawiasie („Czasowo niedostępny (termin dostawy 20.04.2027)”).
     *
     * @param  list<array{code: string, size: string, delivery: string, availability: string, cents: int|null}>  $group
     */
    private static function groupAvailability(array $group): ?string
    {
        $statuses = [];
        foreach ($group as $variant) {
            $status = $variant['availability'] !== '' ? $variant['availability'] : 'brak informacji';
            if ($variant['delivery'] !== '') {
                $status .= ' (termin dostawy '.$variant['delivery'].')';
            }
            $statuses[$status][] = $variant['size'] !== '' ? $variant['size'] : $variant['code'];
        }
        if (count($statuses) === 1) {
            $only = (string) array_key_first($statuses);

            return $only === 'brak informacji' ? null : $only;
        }
        $parts = [];
        foreach ($statuses as $status => $sizes) {
            $parts[] = $status.': '.implode(', ', $sizes);
        }

        return implode('; ', $parts);
    }

    /**
     * Szablon „product-description-modern”: wstęp (akapity p bez tytułu h2) i zalety jako „- …”. Opis zwykły —
     * dosłownie, z liniami ze źródła (sklep dzieli je znakiem nowej linii w akapicie, nie <br>).
     */
    private static function descriptionOf(DOMXPath $xpath): string
    {
        $node = $xpath->query('//*[@id="description"]')->item(0);
        if ($node === null) {
            return '';
        }

        $modern = $xpath->query('.//*['.JspB2bClient::classPredicate('product-description-modern').']', $node)->item(0);
        if ($modern !== null) {
            $lines = [];
            foreach ($xpath->query('.//*['.JspB2bClient::classPredicate('product-intro').']//p', $modern) ?: [] as $p) {
                $text = self::text($p);
                if ($text !== '') {
                    $lines[] = $text;
                }
            }
            foreach ($xpath->query('.//*['.JspB2bClient::classPredicate('feature-box').']', $modern) ?: [] as $box) {
                $icon = self::text($xpath->query('.//*['.JspB2bClient::classPredicate('feature-icon').']', $box)->item(0));
                $text = self::text($box);
                if ($icon !== '' && str_starts_with($text, $icon)) {
                    $text = trim(mb_substr($text, mb_strlen($icon)));
                }
                if ($text !== '') {
                    $lines[] = '- '.$text;
                }
            }

            return mb_substr(implode("\n", $lines), 0, 10000);
        }

        return mb_substr(implode("\n", self::blockLines($node)), 0, 10000);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private static function specPairs(DOMXPath $xpath): array
    {
        $pairs = [];
        foreach ($xpath->query('//*[@id="description"]//*['.JspB2bClient::classPredicate('spec-row').']') ?: [] as $row) {
            $label = rtrim(self::text($xpath->query('.//*['.JspB2bClient::classPredicate('spec-label').']', $row)->item(0)), ':');
            $value = self::text($xpath->query('.//*['.JspB2bClient::classPredicate('spec-value').']', $row)->item(0));
            if ($label !== '' && $value !== '') {
                $pairs[] = [$label, $value];
            }
        }

        return $pairs;
    }

    /**
     * @return list<string>
     */
    private static function iconLabels(DOMXPath $xpath): array
    {
        $labels = [];
        foreach ($xpath->query('//*[@id="description"]//*['.JspB2bClient::classPredicate('icon-box').']') ?: [] as $box) {
            $text = self::text($box);
            if ($text !== '' && ! in_array($text, $labels, true)) {
                $labels[] = $text;
            }
        }

        return $labels;
    }

    /**
     * Pliki z zakładki „Pliki do pobrania” bez zdjęć (te same ujęcia są w galerii). Adres względny wobec <base>,
     * segmenty ścieżki kodowane zawsze tak samo — ten sam plik ma ten sam adres w każdym przebiegu.
     *
     * @return list<array{title: string, url: string, kind: string}>
     */
    private static function documentsOf(DOMXPath $xpath): array
    {
        $baseNode = $xpath->query('//base[@href]')->item(0);
        $base = $baseNode instanceof DOMElement && trim($baseNode->getAttribute('href')) !== ''
            ? trim($baseNode->getAttribute('href'))
            : ProceraB2bClient::BASE.'/';

        $documents = [];
        foreach ($xpath->query('//*[@id="p-files-table"]//tr') ?: [] as $tr) {
            $link = $xpath->query('.//a[@href]', $tr)->item(0);
            if (! $link instanceof DOMElement) {
                continue;
            }
            $url = self::fileUrl($base, $link->getAttribute('href'));
            if ($url === null || isset($documents[$url])) {
                continue;
            }
            $file = rawurldecode(basename((string) parse_url($url, PHP_URL_PATH)));
            $extension = mb_strtolower(pathinfo($file, PATHINFO_EXTENSION));
            if (in_array($extension, self::IMAGE_EXTENSIONS, true)) {
                continue;
            }
            $title = self::text($xpath->query('.//strong', $tr)->item(0));
            $documents[$url] = [
                'title' => mb_substr($title !== '' ? $title : $file, 0, 255),
                'url' => $url,
                'kind' => self::documentKind($file, $extension),
            ];
            if (count($documents) >= self::DOCUMENTS_LIMIT) {
                break;
            }
        }

        return array_values($documents);
    }

    private static function documentKind(string $file, string $extension): string
    {
        $name = mb_strtolower($file);
        if (str_contains($name, 'manual') || str_contains($name, 'instrukcja')) {
            return ProductDocument::KIND_MANUAL;
        }
        if (str_contains($name, 'deklaracja') || str_contains($name, 'declaration') || str_contains($name, 'certyfikat')) {
            return ProductDocument::KIND_CERTIFICATE;
        }

        return $extension === 'pdf' ? ProductDocument::KIND_DATASHEET : ProductDocument::KIND_OTHER;
    }

    /**
     * @return list<string>
     */
    private static function galleryUrls(DOMXPath $xpath): array
    {
        $urls = [];
        foreach ($xpath->query('//*[@id="B2B_fotorama_details"]//a[@data-full]') ?: [] as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }
            $url = self::imageUrl($link->getAttribute('data-full'));
            if ($url !== null) {
                $urls[$url] = true;
            }
            if (count($urls) >= self::IMAGES_LIMIT) {
                break;
            }
        }

        return array_keys($urls);
    }

    /** Zdjęcie sklepu (/get-preview/…); inny adres = null. */
    private static function imageUrl(string $url): ?string
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5));

        return ProceraB2bClient::isShopUrl($url) && str_starts_with((string) parse_url($url, PHP_URL_PATH), '/get-preview/')
            ? $url
            : null;
    }

    /**
     * Adres pliku z tabeli (względny wobec <base>) → pełny adres sklepu; null = adres spoza sklepu albo pusty.
     * Nazwy plików mają spacje i polskie znaki — segmenty ścieżki kodujemy raz (dekodowanie przed kodowaniem).
     */
    public static function fileUrl(string $base, string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));
        if ($href === '' || str_starts_with($href, '#')) {
            return null;
        }

        $url = preg_match('#^https?://#i', $href) === 1 ? $href : rtrim($base, '/').'/'.ltrim($href, '/');
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return null;
        }
        $path = implode('/', array_map(
            static fn (string $segment): string => rawurlencode(rawurldecode($segment)),
            explode('/', $parts['path']),
        ));
        $url = $parts['scheme'].'://'.$parts['host'].$path.(isset($parts['query']) ? '?'.$parts['query'] : '');

        return ProceraB2bClient::isShopUrl($url) ? $url : null;
    }

    /**
     * Element → linie tekstu: nowa linia przy <br>, elementach blokowych i znaku nowej linii w tekście; pozycja listy
     * jako „- …”. Białe znaki w linii zwinięte, puste linie pominięte.
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

    /** Kwota z XML („16.97”, „73.7500”); inny zapis = null. */
    private static function decimal(string $value): ?float
    {
        $value = trim($value);

        return preg_match('/^\d+(?:\.\d+)?$/', $value) === 1 ? (float) $value : null;
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
