<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use App\Support\ImageReencoder;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use RuntimeException;

/**
 * pl.b2b.vmfootwear.cz — sklep B2B producenta obuwia VM Footwear (AB Solutions na ERP Cézar). Sprawdzone na
 * zalogowanym koncie 01.10.2026 (VmFootwearB2bClient opisuje logowanie i sesję).
 *
 * Lista: działy z górnego menu (Obuwie, Rękawice, Akcesoria — 305 modeli 01.10.2026; „Materiały marketingowe” są
 * tylko w stopce i nie są wyrobami), strony „/obuv/”, „/obuv/2”… (ok. 30 modeli na stronę). Z listy bierzemy adres
 * strony wyrobu; resztę czytamy ze strony wyrobu (jedno zapytanie na model).
 *
 * Karta = model (strona wyrobu) ze wszystkimi rozmiarami. Rozmiar to w sklepie „podkarta” z tym samym kodem produktu
 * i tą samą ceną — lista i strona modelu pokazują jedną cenę dla wszystkich rozmiarów (sprawdzone 01.10.2026 na
 * stronach rozmiarów 14 modeli, najmniejszy i największy rozmiar, oraz na zamówieniu z historii konta: rozmiar 48 po
 * cenie modelu). Rozmiary trafiają do opisu wariantów karty, stany rozmiarów do dostępności.
 *
 * Cena: „Cena” strony wyrobu = cena konta netto za parę/sztukę (na zamówieniu konta „Cena bez VAT” równa tej cenie).
 * Ceny katalogowej sklep nie pokazuje. Promocja („GORE-TEX special price - 254,21 PLN”) to ta sama cena konta —
 * dopisek trafia do tabelki sklepu.
 *
 * remote_id i SKU = kod produktu („6655-O6”, „E1/15LI”) — w sklepie unikalny, a adresy stron bywają przypadkowe
 * („/tooking-polbuty-ochronne/” to GOTEBORG). Producent = VM Footwear (sklep własny producenta; rękawice i akcesoria
 * sprzedawane pod tą samą marką — strona nie podaje innego producenta).
 *
 * Co skąd: opis = tabela „Opis produktu” (cholewka, podeszwa, normy, wersja…) wiersz po wierszu „nazwa: wartość”,
 * dosłownie; właściwości z ikoną ✓ (SR, ESD, BOA…), „Normą”, „Kategoria obuwia” i „Parametry biznesowe” — do
 * tabelki sklepu (B2bShopFieldSource), normy z wiersza „Normą” (B2bShopFieldNormSource). Właściwości z ikoną ✗ do
 * tabelki nie trafiają: tabelka zasila wyszukiwanie tekstowe, a wiersz „ESD … nie” dałby trafienie na „ESD” wyrobowi
 * bez ESD (pełna lista Tak/Nie zostaje w karcie technicznej PDF). Pliki: „Karta techniczna” (PDF generowany przez
 * sklep z danych strony, bez ceny), instrukcja i deklaracja zgodności; zdjęcie — jedno na model, w pełnym rozmiarze.
 *
 * Zdjęcia bez tła (od 08.10.2026): link „Do pobrania” ze strony głównej prowadzi do folderu producenta na SharePoincie
 * (VmFootwearSharePoint) z PNG bez tła. Ujęcia PNG kodu karty idą w galerii przed zdjęciem ze sklepu; gdy kodu karty
 * nie ma, a są PNG innej wersji tego modelu (karta 6655-O6, zdjęcie 6655-O2) — też je bierzemy (decyzja właściciela
 * 08.10.2026), z listą takich kart w podsumowaniu przebiegu. Zdjęcie ze sklepu zostaje na karcie, na końcu galerii.
 *
 * Cena katalogowa (od 08.10.2026, B2bCatalogFromPurchaseSite): sklep jej nie podaje — liczymy ją z reguł rabatu konta
 * („Rabaty” przy cenniku): katalogowa = cena konta ÷ (1 − rabat), np. 289,80 zł przy 43% → 508,42 zł. Reguły jak
 * u innych kont: kod produktu, kategoria (okruszki „Obuwie > Obuwie robocze > Półbuty”) albo nazwa, pierwsza pasująca
 * wygrywa. Karta bez reguły — katalogowa = cena konta, jak dotąd; liczba takich kart w podsumowaniu.
 */
final class VmFootwearB2bConnector implements B2bCatalogFromPurchaseSite, B2bConnector, B2bDocumentSource, B2bImageGallery, B2bListProgressAware, B2bManufacturerSite, B2bRunSummaryAware, B2bShopFieldNormSource, B2bShopFieldSource
{
    public const BRAND = 'VM Footwear';

    private const SECTION = 'Informacje ze sklepu VM Footwear';

    private const PROPERTIES_SECTION = 'Właściwości';

    private const BUSINESS_SECTION = 'Parametry biznesowe';

    /** Wiersz właściwości z normą („EN ISO 20345:2022”) — nazwa dosłownie jak w sklepie. */
    private const NORM_PROPERTY = 'Normą';

    /**
     * Tyle modeli z odczytaną stroną, a żaden z ceną konta, zanim pojawi się pierwsza cena = sesja bez cen (albo
     * zmiana strony) — przebieg przerwany.
     */
    private const MAX_FIRST_WITHOUT_PRICE = 20;

    private const LIST_BUDGET_SECONDS = 20 * 60;

    private const MAX_LIST_PAGES = 100;

    private const PROGRESS_EVERY = 50;

    /** Dłuższy bok PNG z SharePointu na karcie — kolejne próby, gdy plik po zmniejszeniu nadal za duży. */
    private const SHAREPOINT_IMAGE_SIDES = [2000, 1400];

    /** Większe pliki zmniejszamy nawet przy tej szerokości (zapis zdjęć karty przyjmuje do 5 MB). */
    private const SHAREPOINT_IMAGE_BYTES = 4_500_000;

    /** Dłuższy bok, którego już nie dekodujemy (pamięć GD). */
    private const SHAREPOINT_SOURCE_SIDE = 10_000;

    private const DOCUMENT_ORDER = [
        ProductDocument::KIND_DATASHEET => 0,
        ProductDocument::KIND_CERTIFICATE => 1,
        ProductDocument::KIND_MANUAL => 2,
        ProductDocument::KIND_OTHER => 3,
    ];

    private int $total = 0;

    private int $cards = 0;

    private int $withPrice = 0;

    /** @var list<string> */
    private array $summary = [];

    /** @var list<string> */
    private array $withoutPrice = [];

    /** @var list<string> */
    private array $withoutDescription = [];

    /** @var list<string> */
    private array $codeMismatch = [];

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    /** Link „Do pobrania” ze strony głównej sklepu (folder producenta na SharePoincie). */
    private ?string $shareLink = null;

    /** @var list<array{folder: string, name: string, path: string, size: int}>|null null = PNG niedostępne w tym przebiegu */
    private ?array $pngIndex = null;

    /** @var list<string> */
    private array $pngOtherVersion = [];

    private int $pngExact = 0;

    private int $pngMissing = 0;

    /**
     * SharePoint z linkiem „Do pobrania”, ale chwilowo niedostępny: przebieg nie podaje żadnych zdjęć. Z samym
     * zdjęciem sklepu synchronizacja nadałaby mu miejsce 0 w galerii (stampGalleryImage) i jako starszy wiersz
     * wyprzedziłby PNG z poprzednich przebiegów — zdjęcie główne karty skakałoby przy każdej awarii.
     */
    private bool $galleryFrozen = false;

    /** @var list<string> karty bez pasującej reguły rabatu (katalogowa = cena konta) */
    private array $withoutDiscountRule = [];

    public function __construct(
        private readonly VmFootwearB2bClient $client,
        private readonly ?VmFootwearSharePoint $sharePoint = null,
        private readonly ?B2bDiscountRuleResolver $discounts = null,
    ) {}

    public static function key(): string
    {
        return 'vmfootwear';
    }

    public static function label(): string
    {
        return self::BRAND;
    }

    public static function host(): string
    {
        return VmFootwearB2bClient::HOST;
    }

    public static function ownBrand(): string
    {
        return self::BRAND;
    }

    public static function normShopFieldNames(): array
    {
        return [self::NORM_PROPERTY];
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(
            new VmFootwearB2bClient((string) $account->username, (string) $account->password, $delayMs),
            new VmFootwearSharePoint($delayMs),
            new B2bDiscountRuleResolver((int) $account->id),
        );
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
        $this->codeMismatch = [];
        $this->cards = 0;
        $this->withPrice = 0;
        $this->shareLink = null;
        $this->pngIndex = null;
        $this->pngOtherVersion = [];
        $this->pngExact = 0;
        $this->pngMissing = 0;
        $this->galleryFrozen = false;
        $this->withoutDiscountRule = [];

        if (! $this->client->isLoggedIn()) {
            $this->client->login();
        }

        $rows = $this->listRows();
        $this->total = count($rows);
        $this->summary[] = 'Lista VM Footwear: '.count($rows).' modeli';
        $this->pngIndex = $this->loadPngIndex();

        $done = 0;
        $seenCodes = [];
        foreach ($rows as $path => $row) {
            $product = $this->productFor($path, $row, $seenCodes);
            if ($this->withPrice === 0 && count($this->withoutPrice) >= self::MAX_FIRST_WITHOUT_PRICE) {
                throw new B2bFatalException(count($this->withoutPrice).' pierwszych modeli '.VmFootwearB2bClient::HOST.' bez ceny konta — sesja konta nie pokazuje cen albo sklep zmienił stronę wyrobu; przebieg przerwany');
            }
            $done++;
            if ($done % self::PROGRESS_EVERY === 0) {
                $this->progress('Strony wyrobów VM Footwear: '.$done.'/'.count($rows));
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
        $lines[] = 'Karty: '.$this->cards;
        if ($this->withoutPrice !== []) {
            $lines[] = 'Bez ceny konta (pominięte): '.self::listing($this->withoutPrice);
        }
        if ($this->withoutDescription !== []) {
            $lines[] = 'Bez opisu w sklepie: '.self::listing($this->withoutDescription);
        }
        if ($this->codeMismatch !== []) {
            $lines[] = 'Kod na liście inny niż na stronie wyrobu (wzięty ze strony wyrobu): '.self::listing($this->codeMismatch);
        }
        if ($this->discounts !== null) {
            $this->discounts->flushCounters();
            if ($this->discounts->hasRules()) {
                $lines[] = 'Cena katalogowa z rabatu konta: '.$this->discounts->matchedCount().' kart';
                if ($this->withoutDiscountRule !== []) {
                    $lines[] = 'Bez pasującej reguły rabatu (katalogowa = cena zakupu): '.self::listing($this->withoutDiscountRule);
                }
            } else {
                $lines[] = 'Konto bez reguł rabatu — cena katalogowa = cena zakupu. Rabat od katalogowej ustawisz przyciskiem „Rabaty” przy cenniku.';
            }
        }
        if ($this->pngIndex !== null) {
            $lines[] = 'Zdjęcia bez tła (SharePoint VM): z kodem karty '.$this->pngExact.', bez PNG '.$this->pngMissing;
            if ($this->pngOtherVersion !== []) {
                $lines[] = 'Zdjęcia bez tła innej wersji modelu: '.self::listing($this->pngOtherVersion);
            }
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
        $add(self::SECTION, 'Kod produktu', (string) $raw['code']);
        $add(self::SECTION, 'Rozmiary', implode(', ', $raw['sizes']));
        $add(self::SECTION, 'Oznaczenia', implode(', ', $raw['badges']));
        $add(self::SECTION, 'Promocja', (string) $raw['action']);
        foreach ($raw['properties'] as $property) {
            $add(self::PROPERTIES_SECTION, $property['name'], $property['value']);
        }
        foreach ($raw['business'] as $parameter) {
            $add(self::BUSINESS_SECTION, $parameter['name'], $parameter['value']);
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
        if ($this->sharePoint !== null && $this->sharePoint->isFileUrl($url)) {
            return $this->sharePointImage($url);
        }
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
     * Działy z górnego menu strony („/obuv/”, „/rukavice/”, „/prislusenstvi/”) w kolejności menu.
     *
     * @return list<string> ścieżki działów
     */
    public static function parseMenu(string $html): array
    {
        $xpath = self::xpath($html);
        $out = [];
        foreach ($xpath->query('//ul[contains(concat(" ", normalize-space(@class), " "), " navbar-nav ")]//a[contains(concat(" ", normalize-space(@class), " "), " nav-link ")]') ?: [] as $a) {
            $path = self::shopPath($a instanceof DOMElement ? $a->getAttribute('href') : '');
            if ($path !== null && $path !== '/' && ! in_array($path, $out, true)) {
                $out[] = $path;
            }
        }

        return $out;
    }

    /**
     * Strona listy działu: wyroby (adres strony, kod, nazwa) i numer ostatniej strony z paginacji.
     *
     * @return array{items: list<array{path: string, code: string, name: string}>, last_page: int}
     */
    public static function parseListPage(string $html): array
    {
        $xpath = self::xpath($html);
        $items = [];
        foreach ($xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " product-card ")]') ?: [] as $card) {
            $title = $xpath->query('.//h3[contains(@class, "product-title")]//a', $card)?->item(0);
            $path = self::shopPath($title instanceof DOMElement ? $title->getAttribute('href') : '');
            if ($path === null) {
                continue;
            }
            $meta = $xpath->query('.//a[contains(concat(" ", normalize-space(@class), " "), " product-meta ")]', $card)?->item(0);
            $items[] = [
                'path' => $path,
                'code' => self::clean((string) $meta?->textContent),
                'name' => self::clean((string) $title?->textContent),
            ];
        }
        $last = 1;
        foreach ($xpath->query('//ul[contains(@class, "pagination")]//a[contains(@class, "page-link")]') ?: [] as $a) {
            $number = self::clean($a->textContent);
            if (ctype_digit($number)) {
                $last = max($last, (int) $number);
            }
        }

        return ['items' => $items, 'last_page' => $last];
    }

    /**
     * Strona wyrobu.
     *
     * @return array{name: string, code: string, price: array{net: float, currency: string}|null, action: string, badges: list<string>, availability: string, sizes: list<array{label: string, stock: string, delivery: string}>, categories: list<string>, description: string, documents: list<array{title: string, url: string, kind: string}>, images: list<string>, properties: list<array{name: string, value: string}>, business: list<array{name: string, value: string}>}
     */
    public static function parseProductPage(string $html): array
    {
        $xpath = self::xpath($html);
        $details = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " product-details ")]')?->item(0);

        $price = null;
        $action = '';
        $badges = [];
        $availability = '';
        if ($details !== null) {
            foreach ($xpath->query('.//div[contains(concat(" ", normalize-space(@class), " "), " mb-3 ")]/span', $details) ?: [] as $span) {
                $price ??= self::parsePrice($span->textContent);
            }
            $action = self::clean((string) $xpath->query('.//*[contains(@class, "product-action-price")]', $details)?->item(0)?->textContent);
            foreach ($xpath->query('.//*[contains(@class, "badge-list")]//span[contains(@class, "badge")]', $details) ?: [] as $badge) {
                $text = self::clean($badge->textContent);
                if ($text !== '' && ! in_array($text, $badges, true)) {
                    $badges[] = $text;
                }
            }
            $stock = $xpath->query('.//div[contains(@class, "item-availible")]/span[2]', $details)?->item(0);
            $availability = self::clean((string) $stock?->textContent);
        }

        $categories = [];
        foreach ($xpath->query('//ol[contains(@class, "breadcrumb")]//a') ?: [] as $a) {
            $path = self::shopPath($a instanceof DOMElement ? $a->getAttribute('href') : '');
            $text = self::clean($a->textContent);
            if ($path !== null && $path !== '/' && $text !== '') {
                $categories[] = $text;
            }
        }

        return [
            'name' => self::clean((string) $xpath->query('//h1')?->item(0)?->textContent),
            'code' => self::clean((string) $xpath->query('//*[@id="colorOption"]')?->item(0)?->textContent),
            'price' => $price,
            'action' => $action,
            'badges' => $badges,
            'availability' => $availability,
            'sizes' => self::sizes($xpath),
            'categories' => $categories,
            'description' => self::descriptionText($xpath),
            'documents' => self::documentLinks($xpath),
            'images' => self::imageLinks($xpath),
            'properties' => self::parameterRows($xpath, 'param_1', true),
            'business' => self::parameterRows($xpath, 'param_3', false),
        ];
    }

    /**
     * Cena ze strony („307,92 PLN”, „1 234,56 PLN”, odstęp tysięcy także twardą spacją); null = to nie cena.
     *
     * @return array{net: float, currency: string}|null
     */
    public static function parsePrice(string $text): ?array
    {
        $text = self::clean($text);
        if (preg_match('/^(\d{1,3}(?:[ .]\d{3})*|\d+),(\d{2})\s*([A-Z]{3})$/u', $text, $m) !== 1) {
            return null;
        }
        $net = (float) (str_replace([' ', '.'], '', $m[1]).'.'.$m[2]);

        return $net > 0 ? ['net' => $net, 'currency' => $m[3]] : null;
    }

    /**
     * Rodzaj pliku z nazwy przycisku i nazwy pliku: deklaracja zgodności (także plik „EU_6655-O6_FO_CI_SR.pdf”,
     * „EU prohlášení o shodě”), instrukcja; „Karta techniczna” i reszta — karta produktu.
     */
    public static function documentKind(string $title, string $url): string
    {
        $file = basename(rawurldecode((string) parse_url($url, PHP_URL_PATH)));
        $text = $title.' '.$file;

        return match (true) {
            preg_match('/declaration|deklarac|conformit|konformit|prohl|vyhl|zgodno/iu', $text) === 1,
            preg_match('/^EU[_ ]/u', $file) === 1 => ProductDocument::KIND_CERTIFICATE,
            preg_match('/manual|instrukc|návod|navod/iu', $text) === 1 => ProductDocument::KIND_MANUAL,
            default => ProductDocument::KIND_DATASHEET,
        };
    }

    /**
     * Cała lista ze wszystkich działów menu: ścieżka strony wyrobu → kod i nazwa z listy. Wyrób w dwóch działach —
     * raz.
     *
     * @return array<string, array{code: string, name: string}>
     */
    private function listRows(): array
    {
        $started = microtime(true);
        $home = $this->client->homePage();
        $this->shareLink = VmFootwearSharePoint::shareLinkFrom($home);
        $sections = self::parseMenu($home);
        if ($sections === []) {
            throw new RuntimeException('Strona główna '.VmFootwearB2bClient::HOST.' bez działów w menu — zmiana sklepu?');
        }
        $this->progress('Działy VM Footwear: '.implode(', ', $sections));

        $rows = [];
        foreach ($sections as $section) {
            $last = 1;
            for ($page = 1; $page <= $last; $page++) {
                if (microtime(true) - $started > self::LIST_BUDGET_SECONDS) {
                    throw new RuntimeException('Pobieranie listy '.VmFootwearB2bClient::HOST.' trwa ponad '.(self::LIST_BUDGET_SECONDS / 60).' min — przerwane bez zapisu');
                }
                $parsed = self::parseListPage($this->client->page($page === 1 ? $section : $section.$page));
                if ($page === 1) {
                    $last = min($parsed['last_page'], self::MAX_LIST_PAGES);
                } elseif ($parsed['items'] === []) {
                    throw new RuntimeException('Strona '.$page.' działu '.$section.' bez wyrobów — lista zmieniła się w trakcie pobierania');
                }
                foreach ($parsed['items'] as $item) {
                    $rows[$item['path']] ??= ['code' => $item['code'], 'name' => $item['name']];
                }
            }
        }
        if ($rows === []) {
            throw new RuntimeException('Lista '.VmFootwearB2bClient::HOST.' pusta — zmiana sklepu?');
        }

        return $rows;
    }

    /**
     * @param  array{code: string, name: string}  $row
     * @param  array<string, true>  $seenCodes
     */
    private function productFor(string $path, array $row, array &$seenCodes): B2bRemoteProduct
    {
        $listCode = $row['code'] !== '' ? $row['code'] : $path;
        try {
            $page = self::parseProductPage($this->client->page($path));
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return $this->skipped($listCode, $row['name'], 'strona wyrobu: '.$e->getMessage());
        }

        $code = $page['code'] !== '' ? $page['code'] : $row['code'];
        if ($code === '') {
            return $this->skipped($path, $row['name'], 'strona wyrobu bez kodu produktu');
        }
        if ($row['code'] !== '' && $row['code'] !== $code) {
            $this->codeMismatch[] = $row['code'].' → '.$code;
        }
        $name = $page['name'] !== '' ? $page['name'] : $row['name'];
        if (isset($seenCodes[$code])) {
            return $this->skipped($code.' '.$path, $name, 'ten sam kod produktu ma już inna strona wyrobu');
        }
        $seenCodes[$code] = true;

        if ($page['price'] === null) {
            // pominięty z powodem, nie przemilczany — karta z poprzedniego przebiegu zostaje nietknięta
            $this->withoutPrice[] = $code;

            return $this->skipped($code, $name, 'strona wyrobu bez ceny konta');
        }
        $this->withPrice++;
        if ($page['description'] === '') {
            $this->withoutDescription[] = $code;
        }
        $this->cards++;

        $sizes = array_values(array_filter(array_column($page['sizes'], 'label'), static fn (string $label): bool => $label !== ''));
        $images = $this->galleryFrozen ? [] : [...$this->pngUrls($code), ...$page['images']];
        $category = $page['categories'] !== [] ? implode(' > ', $page['categories']) : null;

        return new B2bRemoteProduct(
            remoteId: $code,
            sku: $code,
            name: $name,
            category: $category,
            sourceUrl: VmFootwearB2bClient::BASE.$path,
            raw: [
                'status' => 'ok',
                'price' => $this->accountPrice($page['price']['net'], $page['price']['currency'], $code, $category, $name),
                'code' => $code,
                'sizes' => $sizes,
                'badges' => $page['badges'],
                'action' => $page['action'],
                'description' => $page['description'],
                'properties' => $page['properties'],
                'business' => $page['business'],
                'documents' => $page['documents'],
                'images' => $images,
            ],
            availability: self::availabilityText($page['sizes'], $page['availability']),
            variantSummary: $sizes !== [] ? 'Rozmiary: '.implode(', ', $sizes) : null,
            identifiers: [new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_MANUFACTURER_CODE, value: $code, field: 'Kod produktu')],
        );
    }

    /**
     * Cena konta i katalogowa z reguły rabatu konta: katalogowa = cena konta ÷ (1 − rabat), zaokrąglona do grosza.
     * Bez reguły (albo rabat 0%) — bez ceny katalogowej, synchronizacja przyjmuje cenę konta, jak przed regułami.
     */
    private function accountPrice(float $net, string $currency, string $code, ?string $category, string $name): B2bRemotePrice
    {
        $match = $this->discounts?->hasRules() ? $this->discounts->resolve($code, $category, $name) : null;
        if ($match === null) {
            if ($this->discounts?->hasRules()) {
                $this->withoutDiscountRule[] = $code;
            }

            return new B2bRemotePrice(net: $net, currency: $currency);
        }
        $discount = $match->discountPercent;
        // 100% nie przejdzie zapisu reguł; ujemnego i 0% nie liczymy — katalogowa = cena konta
        if ($discount <= 0 || $discount >= 100) {
            return new B2bRemotePrice(net: $net, currency: $currency);
        }

        return new B2bRemotePrice(
            net: $net,
            base: round($net / (1 - $discount / 100), 2),
            discountPercent: $discount,
            currency: $currency,
        );
    }

    /**
     * Spis PNG z folderu „Do pobrania” (SharePoint producenta). Błąd = przebieg bez zdjęć bez tła, z powodem
     * w podsumowaniu — zdjęcie ze sklepu zostaje, a karta nie traci nic z tego, co ma.
     *
     * @return list<array{folder: string, name: string, path: string, size: int}>|null
     */
    private function loadPngIndex(): ?array
    {
        if ($this->sharePoint === null) {
            return null;
        }
        if ($this->shareLink === null) {
            $this->summary[] = 'Zdjęcia bez tła (SharePoint VM): na stronie głównej sklepu brak linku „Do pobrania”';

            return null;
        }
        $this->progress('Zdjęcia bez tła VM Footwear: spis folderów SharePointu');
        try {
            $this->sharePoint->open($this->shareLink);
            $index = $this->sharePoint->pngIndex();
        } catch (RuntimeException $e) {
            $this->galleryFrozen = true;
            $this->summary[] = 'Zdjęcia bez tła (SharePoint VM) niedostępne: '.$e->getMessage().' — galerie kart bez zmian w tym przebiegu';

            return null;
        }
        $this->summary[] = 'Zdjęcia bez tła (SharePoint VM): '.count($index).' plików PNG';

        return $index;
    }

    /**
     * Adresy PNG bez tła dla kodu karty, w kolejności galerii (przed zdjęciem ze sklepu).
     *
     * @return list<string>
     */
    private function pngUrls(string $code): array
    {
        if ($this->pngIndex === null || $this->sharePoint === null) {
            return [];
        }
        $match = VmFootwearSharePoint::imagesFor($code, $this->pngIndex);
        if ($match['paths'] === []) {
            $this->pngMissing++;

            return [];
        }
        if ($match['exact']) {
            $this->pngExact++;
        } else {
            $this->pngOtherVersion[] = $code.' ← '.basename($match['paths'][0]);
        }

        return array_map(fn (string $path): string => $this->sharePoint->fileUrl($path), $match['paths']);
    }

    /**
     * PNG z SharePointu. Pliki producenta mają do 6000×4000 px i 11 MB (zapis zdjęć karty przyjmuje do 5 MB) —
     * większe niż SHAREPOINT_IMAGE_SIDES[0] (dłuższy bok) albo SHAREPOINT_IMAGE_BYTES zmniejszamy z zachowaniem
     * przezroczystości.
     */
    private function sharePointImage(string $url): ?B2bRemoteImage
    {
        $file = $this->sharePoint?->fileBytes($url);
        if ($file === null) {
            return null;
        }
        $bytes = $file['bytes'];
        $size = @getimagesizefromstring($bytes);
        if (! is_array($size)) {
            return null;
        }
        [$width, $height] = [(int) $size[0], (int) $size[1]];
        if (max($width, $height) <= self::SHAREPOINT_IMAGE_SIDES[0] && strlen($bytes) <= self::SHAREPOINT_IMAGE_BYTES) {
            return new B2bRemoteImage(bytes: $bytes, mime: $file['mime'], sourceUrl: $url);
        }
        // dłuższy bok do 2000 px; gdy plik nadal za duży (szum, pion) — mniej
        foreach (self::SHAREPOINT_IMAGE_SIDES as $side) {
            $targetWidth = max(1, (int) floor($width * min(1, $side / max($width, $height))));
            $smaller = ImageReencoder::fromBytes($bytes, $targetWidth, self::SHAREPOINT_SOURCE_SIDE);
            if ($smaller === null) {
                throw new RuntimeException('PNG '.$width.'×'.$height.' nie dał się zmniejszyć (za duży na pamięć albo uszkodzony): '.$url);
            }
            if (strlen($smaller['bytes']) <= self::SHAREPOINT_IMAGE_BYTES) {
                return new B2bRemoteImage(bytes: $smaller['bytes'], mime: $smaller['mime'], sourceUrl: $url);
            }
        }

        throw new RuntimeException('PNG '.$width.'×'.$height.' po zmniejszeniu do '.self::SHAREPOINT_IMAGE_SIDES[array_key_last(self::SHAREPOINT_IMAGE_SIDES)].' px nadal ponad '.(self::SHAREPOINT_IMAGE_BYTES / 1_000_000).' MB: '.$url);
    }

    /**
     * Rozmiary ze stanem z bloku „Dostępne rozmiary” (przycisk rozmiaru: „roz.39”, stan „24” albo „>50”, ikona
     * ciężarówki z przewidywaną datą dostawy); bez tego bloku — z listy wyboru rozmiaru (bez stanu).
     *
     * @return list<array{label: string, stock: string, delivery: string}>
     */
    private static function sizes(DOMXPath $xpath): array
    {
        $out = [];
        foreach ($xpath->query('//div[contains(@class, "cart-load-variants")]/div') ?: [] as $button) {
            $span = $xpath->query('.//span[contains(@class, "mx-1")]', $button)?->item(0);
            if ($span === null) {
                continue;
            }
            $label = '';
            foreach ($span->childNodes as $child) {
                if ($child->nodeType === XML_TEXT_NODE) {
                    $label .= $child->textContent;
                }
            }
            $label = self::clean((string) preg_replace('/^roz\.\s*/u', '', self::clean($label)));
            $stock = self::clean((string) $xpath->query('.//small//div', $span)?->item(0)?->textContent);
            $delivery = '';
            $icon = $xpath->query('.//small//i[contains(@class, "fa-truck")]', $span)?->item(0);
            if ($icon instanceof DOMElement && preg_match('/(\d{1,2}\.\d{1,2}\.\d{4})/', $icon->getAttribute('title'), $m) === 1) {
                $delivery = $m[1];
            }
            if ($label !== '') {
                $out[] = ['label' => $label, 'stock' => $stock, 'delivery' => $delivery];
            }
        }
        if ($out !== []) {
            return $out;
        }
        foreach ($xpath->query('//select[@id="product-size"]/option') ?: [] as $option) {
            $label = $option instanceof DOMElement ? self::clean($option->getAttribute('value')) : '';
            if ($label !== '') {
                $out[] = ['label' => $label, 'stock' => '', 'delivery' => ''];
            }
        }

        return $out;
    }

    /**
     * Opis z tabeli „Opis produktu”: wiersz tabeli = „nazwa: wartość” (kilka komórek wartości — po średniku, puste
     * pominięte), akapit poza tabelą = osobna linia. Treść dosłownie, tylko białe znaki zwinięte.
     */
    private static function descriptionText(DOMXPath $xpath): string
    {
        $body = $xpath->query('//*[@id="productInfo"]//div[contains(@class, "accordion-body")]')?->item(0);
        if ($body === null) {
            return '';
        }
        $lines = [];
        $walk = static function (DOMNode $node) use (&$walk, &$lines, $xpath): void {
            foreach ($node->childNodes as $child) {
                if (! $child instanceof DOMElement) {
                    $text = self::clean($child->textContent);
                    if ($text !== '') {
                        $lines[] = $text;
                    }

                    continue;
                }
                $tag = strtolower($child->tagName);
                if ($tag === 'tr') {
                    $cells = [];
                    foreach ($xpath->query('./td|./th', $child) ?: [] as $cell) {
                        $cells[] = self::clean($cell->textContent);
                    }
                    $label = array_shift($cells) ?? '';
                    $values = array_values(array_filter($cells, static fn (string $c): bool => $c !== ''));
                    $line = $label !== '' && $values !== [] ? $label.': '.implode('; ', $values) : trim($label.' '.implode('; ', $values));
                    if ($line !== '') {
                        $lines[] = $line;
                    }
                } elseif (in_array($tag, ['table', 'tbody', 'thead', 'div', 'ul', 'ol'], true)) {
                    $walk($child);
                } else {
                    $text = self::clean($child->textContent);
                    if ($text !== '') {
                        $lines[] = $text;
                    }
                }
            }
        };
        $walk($body);

        return mb_substr(implode("\n", $lines), 0, 10000);
    }

    /**
     * Pliki z „Pliki do pobrania”: „Karta techniczna” (/{wyrób}/?d=pdf) i pliki „/file/{hash}/{nazwa}”. Tytuł =
     * napis przycisku bez rozmiaru pliku. Karty produktu pierwsze.
     *
     * @return list<array{title: string, url: string, kind: string}>
     */
    private static function documentLinks(DOMXPath $xpath): array
    {
        $files = [];
        foreach ($xpath->query('//*[@id="fileDownload"]//a[@href]') ?: [] as $a) {
            if (! $a instanceof DOMElement) {
                continue;
            }
            $path = self::shopPath($a->getAttribute('href'), keepQuery: true);
            if ($path === null) {
                continue;
            }
            $title = '';
            foreach ($a->childNodes as $child) {
                if ($child->nodeType === XML_TEXT_NODE) {
                    $title .= $child->textContent;
                }
            }
            $title = self::clean($title);
            if ($title === '') {
                $title = basename((string) parse_url($path, PHP_URL_PATH));
            }
            $url = VmFootwearB2bClient::BASE.$path;
            $files[$url] = ['title' => $title, 'url' => $url, 'kind' => self::documentKind($title, $url)];
        }
        $files = array_values($files);
        usort($files, static fn (array $a, array $b): int => self::DOCUMENT_ORDER[$a['kind']] <=> self::DOCUMENT_ORDER[$b['kind']]);

        return $files;
    }

    /**
     * Zdjęcia galerii w pełnym rozmiarze (data-zoom „/image/{hash}”; bez niego — podgląd „/image/detail/{hash}”).
     *
     * @return list<string>
     */
    private static function imageLinks(DOMXPath $xpath): array
    {
        $out = [];
        foreach ($xpath->query('//div[contains(@class, "product-gallery-preview")]//img') ?: [] as $img) {
            if (! $img instanceof DOMElement) {
                continue;
            }
            $path = self::shopPath($img->getAttribute('data-zoom')) ?? self::shopPath($img->getAttribute('src'));
            if ($path === null) {
                continue;
            }
            $url = VmFootwearB2bClient::BASE.$path;
            if (! in_array($url, $out, true)) {
                $out[] = $url;
            }
        }

        return $out;
    }

    /**
     * Wiersze zakładki parametrów (#param_1 „Właściwości”, #param_3 „Parametry biznesowe”). Wiersz z wartością —
     * „nazwa → wartość” dosłownie; wiersz z ikoną (tylko właściwości) — tylko zaznaczony ✓, jako „SR — Podeszwa
     * antypoślizg. (posadzka ceramiczna) → tak”.
     *
     * @return list<array{name: string, value: string}>
     */
    private static function parameterRows(DOMXPath $xpath, string $tabId, bool $withIcons): array
    {
        $rows = [];
        foreach ($xpath->query('//*[@id="'.$tabId.'"]//li') ?: [] as $li) {
            $label = $xpath->query('.//span[contains(@class, "text-accent")]/div', $li)?->item(0);
            if ($label === null) {
                continue;
            }
            $code = '';
            foreach ($label->childNodes as $child) {
                if ($child->nodeType === XML_TEXT_NODE) {
                    $code .= $child->textContent;
                }
            }
            $code = self::clean($code);
            $small = $xpath->query('.//small', $label)?->item(0);
            $title = $small instanceof DOMElement ? self::clean($small->getAttribute('title')) : '';
            if ($code === '') {
                continue;
            }

            $value = $xpath->query('.//div[contains(@class, "text-white")]', $li)?->item(0);
            if ($value !== null) {
                $text = self::clean($value->textContent);
                if ($text !== '') {
                    $rows[] = ['name' => $code, 'value' => $text];
                }

                continue;
            }
            if (! $withIcons) {
                continue;
            }
            $checked = $xpath->query('.//i[contains(@class, "fa-check-circle")]', $li)?->length ?? 0;
            if ($checked > 0) {
                $rows[] = ['name' => $title !== '' ? $code.' — '.$title : $code, 'value' => 'tak'];
            }
        }

        return $rows;
    }

    /**
     * Stan rozmiarów dosłownie: „Stan: 39: 24; 40: 25; 42: >50 (dostawa 9.10.2026)”; wyrób bez rozmiarów — stan
     * z nagłówka strony („Stan: >50”); bez stanu — null.
     *
     * @param  list<array{label: string, stock: string, delivery: string}>  $sizes
     */
    private static function availabilityText(array $sizes, string $headline): ?string
    {
        $parts = [];
        foreach ($sizes as $size) {
            if ($size['stock'] === '' && $size['delivery'] === '') {
                continue;
            }
            $parts[] = $size['label'].': '.($size['stock'] !== '' ? $size['stock'] : '—').($size['delivery'] !== '' ? ' (dostawa '.$size['delivery'].')' : '');
        }
        if ($parts !== []) {
            return mb_substr('Stan: '.implode('; ', $parts), 0, 1000);
        }

        return $headline !== '' ? 'Stan: '.$headline : null;
    }

    /**
     * Wyrób, którego nie da się zapisać w tym przebiegu — pozycja bez ceny, widoczna jako pominięta.
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

    /**
     * Ścieżka w sklepie z adresu ze strony („/obuv/”, „https://pl.b2b.vmfootwear.cz/x/”); null = adres spoza sklepu,
     * kotwica, javascript albo pusty. Zapytanie zostaje tylko na życzenie (karta techniczna „?d=pdf”).
     */
    private static function shopPath(string $href, bool $keepQuery = false): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '' || str_starts_with($href, '#') || preg_match('/^(?:javascript|mailto|tel|data):/i', $href) === 1) {
            return null;
        }
        if (preg_match('#^https?://#i', $href) === 1) {
            $host = strtolower((string) parse_url($href, PHP_URL_HOST));
            if ($host !== VmFootwearB2bClient::HOST) {
                return null;
            }
            $query = (string) parse_url($href, PHP_URL_QUERY);
            $href = (string) parse_url($href, PHP_URL_PATH).($query !== '' ? '?'.$query : '');
        } elseif (! str_starts_with($href, '/') || str_starts_with($href, '//')) {
            return null;
        }
        $href = (string) preg_replace('/#.*$/s', '', $href);
        if (! $keepQuery) {
            $href = (string) preg_replace('/\?.*$/s', '', $href);
        }
        if ($href === '' || str_contains($href, '..')) {
            return null;
        }

        return $href;
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
