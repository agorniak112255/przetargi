<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use App\Support\ProductIdentifierCode;
use RuntimeException;

/**
 * Platforma B2B P4S b2b.p4s.pl — dystrybutor ŚOI (PORTWEST, ANSELL, LEBON, PLANAM, UVEX, 3M, Tyvek…). Sprawdzone na
 * koncie 23.09.2026: oferta ogólna 3370 wyrobów, „Moja oferta” 2311 (ceny te same; 4 wyroby są tylko w „Mojej
 * ofercie”), więc lista to suma obu ofert bez powtórzeń.
 *
 * Wyrób jest pojedynczy (typ T) albo „matką” (M) z rozmiarami (R — rozmiar, czasem kolor i rozmiar: „kolor biały,
 * rozmiar L”). Lista z mothers=0 podaje pojedyncze i wszystkie rozmiary z ceną konta i parentProductId (13 836 pozycji
 * po 1000 na stronę, kilka sekund), lista z mothers=1 — matki i pojedyncze (nazwa, kod). Pełne listy idą przed
 * pierwszym zapisem, z kontrolami spójności (recordsTotal stały na każdej stronie, liczba pozycji, unikalne id;
 * zmiana w trakcie = jedno ponowne pobranie). Potem karta wyrobu (getProduct) — raz na wyrób.
 *
 * Cena: platforma podaje jedną cenę netto (netPrice, „1 133,05 PLN”) — to cena konta; ceny katalogowej nie ma.
 * Karta = rozmiary jednej matki w jednej cenie (decyzja użytkownika 15.09.2026: rozmiar w innej cenie to osobna
 * karta; 34 z 2136 matek ma rozmiary w kilku cenach). SKU: kod P4S matki („00.196”), gdy matka jest jedną kartą;
 * przy podziale — kod pierwszego rozmiaru karty („00.196/10,0”), żeby kod matki nie przechodził między kartami przy
 * zmianie podziału. Pozycje (members) = rozmiary, remote_id = id rozmiaru w platformie. Kodu producenta (PS18,
 * 2055BOA) nie używamy jako SKU — mógłby trafić w kartę innego dostawcy; idzie do tabelki sklepu i do identyfikatorów
 * karty (identifiers()).
 *
 * Opis: pole characteristic dosłownie (podział linii z <br />). Normy (europeanNorms), kategoria ŚOI, oznaczenia
 * („EN 388:2016” → „4 X 4 3 D”) i opis techniczny idą do tabelki sklepu (B2bShopFieldSource). P4S jest dystrybutorem,
 * więc łącznik nie jest B2bManufacturerSite ani B2bNormFactSource — nie nadpisuje opisów i norm z innych źródeł.
 * Producent: pole brand dosłownie (bywa „SHOWA BEST (b)”, „TOP SAFETY Szelki bezpieczeństwa”); puste — dostawca P4S,
 * liczone w podsumowaniu przebiegu.
 *
 * Pliki: karta producenta, deklaracja zgodności, instrukcja, tabela rozmiarów. „Karta techniczna P4S” (technicalCard)
 * jest pomijana — to PDF złożony przez platformę z tych samych pól karty (sprawdzone 23.09.2026 na 11.935), a ma
 * 200–360 KB na każdym z 3370 wyrobów.
 */
final class P4sB2bConnector implements B2bConnector, B2bDocumentSource, B2bGroupsSizes, B2bImageGallery, B2bListProgressAware, B2bRunSummaryAware, B2bShopFieldSource
{
    public const SUPPLIER = 'P4S';

    private const OFFER_GENERAL = 'generalOffer';

    private const OFFER_OWN = 'productGroups';

    private const PAGE_SIZE = 1000;

    /** Nieprzerwane pobieranie listy dłużej = błąd (przebieg bez postępu uznałby b2b:sync-due za przerwany). */
    private const LIST_BUDGET_SECONDS = 25 * 60;

    private const INCONSISTENT = 7301;

    private const TYPE_SINGLE = 'T';

    private const TYPE_MOTHER = 'M';

    private const TYPE_SIZE = 'R';

    private const DOCUMENTS_LIMIT = 8;

    private const IMAGES_LIMIT = 8;

    /** Opis pliku, którego platforma nie tłumaczy (klucz tłumaczenia widoczny na stronie). */
    private const SIZES_TABLE_KEY = 'Product.sizesTable';

    private const SHOP_SECTION_TRADE = 'Informacje handlowe';

    private const SHOP_SECTION_CERTIFICATION = 'Certyfikacja';

    private const SHOP_SECTION_MARKS = 'Oznaczenia';

    private const SHOP_SECTION_TECHNICAL = 'Opis techniczny';

    /** Tyle wariantów wypisanych w nazwie karty z podziału cenowego; więcej = skrót z liczbą. */
    private const VARIANTS_IN_NAME = 8;

    private int $total = 0;

    /** @var list<string> */
    private array $summary = [];

    /** @var list<string> kody matek z rozmiarami bez ceny (te rozmiary poza kartami) */
    private array $sizesWithoutPrice = [];

    /** @var list<string> */
    private array $withoutDescription = [];

    /** @var array<string, true> karty z producentem przyjętym jako dostawca (remoteId) */
    private array $supplierAsManufacturer = [];

    private int $cards = 0;

    private int $splitProducts = 0;

    private int $skippedProducts = 0;

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    /**
     * @param  int  $pageSize  pozycji na stronę listy (testy stronicują drobniej)
     */
    public function __construct(
        private readonly P4sB2bClient $client,
        private readonly int $pageSize = self::PAGE_SIZE,
    ) {}

    public static function key(): string
    {
        return 'p4s';
    }

    public static function label(): string
    {
        return self::SUPPLIER;
    }

    public static function host(): string
    {
        return P4sB2bClient::HOST;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new P4sB2bClient(
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
        $this->sizesWithoutPrice = [];
        $this->withoutDescription = [];
        $this->supplierAsManufacturer = [];
        $this->cards = 0;
        $this->splitProducts = 0;
        $this->skippedProducts = 0;

        if (! $this->client->isLoggedIn()) {
            $this->client->login();
        }
        $currency = $this->client->currency();
        if ($currency !== 'PLN') {
            throw new RuntimeException('Konto '.P4sB2bClient::HOST.' ma ceny w walucie „'.$currency.'”, a katalog przyjmuje ceny w PLN — przebieg przerwany bez zapisu');
        }

        ['products' => $rows, 'sizes' => $sizes] = $this->catalog();
        $this->total = count($rows);

        foreach ($rows as $row) {
            $products = $this->productsFor($row, $sizes[$row['id']] ?? []);
            // wyrób w kilku cenach to kilka kart — licznik postępu rośnie, zanim je wydamy
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
            'Karty: %d (%d wyrobów w kilku cenach — rozmiary w innej cenie to osobna karta; %d wyrobów pominiętych)',
            $this->cards,
            $this->splitProducts,
            $this->skippedProducts,
        );
        $lines[] = 'Platforma podaje jedną cenę (cena konta), bez ceny katalogowej; „Karta techniczna P4S” pomijana — to zestawienie pól karty';
        if ($this->sizesWithoutPrice !== []) {
            $lines[] = 'Wyroby z rozmiarami bez ceny (te rozmiary poza kartami): '.self::listing($this->sizesWithoutPrice);
        }
        if ($this->withoutDescription !== []) {
            $lines[] = 'Bez opisu w platformie: '.self::listing($this->withoutDescription);
        }
        if ($this->supplierAsManufacturer !== []) {
            $lines[] = 'Bez marki — producent przyjęty jako P4S: '.self::listing(array_keys($this->supplierAsManufacturer));
        }

        return $lines;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        $brand = (string) ($product->raw['brand'] ?? '');
        if ($brand !== '') {
            return $brand;
        }
        $this->supplierAsManufacturer[$product->remoteId] = true;

        return self::SUPPLIER;
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            throw new RuntimeException((string) ($product->raw['reason'] ?? 'karta wyrobu nieodczytana'));
        }

        return new B2bRemotePrice(net: round((int) $product->raw['cents'] / 100, 2));
    }

    public function description(B2bRemoteProduct $product): string
    {
        return (string) ($product->raw['description'] ?? '');
    }

    /**
     * Pola karty dosłownie z platformy: kody, marka, jednostka, opakowanie, normy, kategoria ŚOI, oznaczenia
     * i opis techniczny. Wszystko z raz pobranej karty — metoda niczego nie pobiera.
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

        $add(self::SHOP_SECTION_TRADE, 'Kod P4S', (string) $raw['code']);
        $add(self::SHOP_SECTION_TRADE, 'Kod producenta', (string) $raw['manufacturer_code']);
        $add(self::SHOP_SECTION_TRADE, 'Marka', (string) $raw['brand']);
        $add(self::SHOP_SECTION_TRADE, 'Grupa', (string) $raw['group']);
        $add(self::SHOP_SECTION_TRADE, 'Jednostka sprzedaży', (string) $raw['unit']);
        if ((int) $raw['minimum_quantity'] > 1) {
            $add(self::SHOP_SECTION_TRADE, 'Minimalna ilość zamówienia', $raw['minimum_quantity'].' '.$raw['unit']);
        }
        $add(self::SHOP_SECTION_TRADE, 'Opakowanie zbiorcze', implode('; ', $raw['packings']));
        $add(self::SHOP_SECTION_TRADE, 'Stawka VAT', (string) $raw['tax_rate']);
        $add(self::SHOP_SECTION_TRADE, 'Cena ważna do', (string) $raw['expiration_date']);
        $add(self::SHOP_SECTION_CERTIFICATION, 'Normy', (string) $raw['norms']);
        $add(self::SHOP_SECTION_CERTIFICATION, 'Kategoria UE', (string) $raw['category_ce']);
        $add(self::SHOP_SECTION_CERTIFICATION, 'Zastosowanie', (string) $raw['application']);
        foreach ($raw['marks'] as [$label, $value]) {
            $add(self::SHOP_SECTION_MARKS, $label, $value);
        }
        foreach ($raw['technical'] as [$label, $value]) {
            $add(self::SHOP_SECTION_TECHNICAL, $label, $value);
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

    /**
     * @return array{bytes: string, mime: string}
     */
    public function documentBytes(B2bRemoteDocument $document): array
    {
        $file = $this->client->documentBytes($document->sourceUrl);
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

    /** „1 133,05 PLN” → 113305; inny zapis (bez waluty, inna waluta) = null — nie zgadujemy. */
    public static function priceCents(string $text): ?int
    {
        if (preg_match('/^(\d{1,3}(?:[ \x{00A0}]\d{3})*|\d+),(\d{2})\s*PLN$/u', trim($text), $m) !== 1) {
            return null;
        }

        return (int) preg_replace('/\D/', '', $m[1]) * 100 + (int) $m[2];
    }

    /** Opis z platformy: <br /> = nowa linia, bez znaczników i encji, puste linie pominięte. */
    public static function descriptionText(string $html): string
    {
        $text = (string) preg_replace('~<br\s*/?>~i', "\n", $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = self::clean($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return mb_substr(implode("\n", $lines), 0, 10000);
    }

    /**
     * Karta wyrobu → pola karty. Nieczytelna budowa = wyjątek z powodem (wyrób pominięty, reszta przebiegu idzie dalej).
     *
     * @param  array<string, mixed>  $product
     * @return array{code: string, name: string, brand: string, group: string, manufacturer_code: string, unit: string, minimum_quantity: int, packings: list<string>, tax_rate: string, expiration_date: string, norms: string, category_ce: string, application: string, marks: list<array{0: string, 1: string}>, technical: list<array{0: string, 1: string}>, description: string, documents: list<array{title: string, url: string, kind: string}>, image_urls: list<string>}
     */
    public static function parseProduct(array $product): array
    {
        $id = $product['id'] ?? null;
        $code = self::field($product['code'] ?? '');
        if (! is_int($id) || $code === '') {
            throw new RuntimeException('karta wyrobu bez id albo kodu');
        }

        $packings = [];
        foreach (self::listOf($product['packings'] ?? null) as $packing) {
            $unit = self::field($packing['measureUnit'] ?? '');
            $base = self::field($packing['baseMeasureUnit'] ?? '');
            $ratio = $packing['ratio'] ?? null;
            if ($unit !== '' && $base !== '' && (is_int($ratio) || is_float($ratio))) {
                $packings[] = $unit.' = '.$ratio.' '.$base;
            }
        }

        $documents = [];
        foreach (self::listOf($product['documents'] ?? null) as $document) {
            // karta techniczna P4S (bez numeru pliku) to zestawienie pól tej samej karty — pomijana
            $ordinal = $document['ordinalNumber'] ?? null;
            if (($document['type'] ?? '') !== 'document' || ! is_int($ordinal) || ($document['productId'] ?? null) !== $id) {
                continue;
            }
            $title = self::field($document['description'] ?? '');
            $title = $title === self::SIZES_TABLE_KEY ? 'Tabela rozmiarów' : $title;
            $url = P4sB2bClient::documentUrl($id, $ordinal);
            if ($title === '' || isset($documents[$url])) {
                continue;
            }
            $documents[$url] = ['title' => $title, 'url' => $url, 'kind' => self::documentKind($title)];
        }

        $images = [];
        foreach (is_array($product['images'] ?? null) ? $product['images'] : [] as $path) {
            $url = is_string($path) ? P4sB2bClient::imageUrl($path) : null;
            if ($url !== null && ! in_array($url, $images, true)) {
                $images[] = $url;
            }
        }

        return [
            'code' => $code,
            'name' => self::field($product['name'] ?? ''),
            'brand' => self::field($product['brand'] ?? ''),
            'group' => self::field($product['group'] ?? ''),
            'manufacturer_code' => self::field($product['manufacturerCode'] ?? ''),
            'unit' => self::field($product['measureUnit'] ?? ''),
            'minimum_quantity' => is_int($product['minimumQuantity'] ?? null) ? $product['minimumQuantity'] : 1,
            'packings' => $packings,
            'tax_rate' => self::field($product['taxRate'] ?? ''),
            'expiration_date' => self::field($product['expirationDate'] ?? ''),
            'norms' => self::field($product['europeanNorms'] ?? ''),
            'category_ce' => self::field($product['categoryCE'] ?? ''),
            'application' => self::field($product['application'] ?? ''),
            'marks' => self::pairs($product['marks'] ?? null),
            'technical' => self::pairs($product['technicalDescriptions'] ?? null),
            'description' => self::descriptionText(is_string($product['characteristic'] ?? null) ? $product['characteristic'] : ''),
            'documents' => array_slice(array_values($documents), 0, self::DOCUMENTS_LIMIT),
            'image_urls' => array_slice($images, 0, self::IMAGES_LIMIT),
        ];
    }

    /**
     * Wyroby (matki i pojedyncze) i rozmiary wg id matki — z obu ofert, bez powtórzeń (najpierw oferta ogólna).
     *
     * @return array{products: list<array<string, mixed>>, sizes: array<int, list<array<string, mixed>>>}
     */
    private function catalog(): array
    {
        $products = [];
        $leaves = [];
        foreach ([self::OFFER_GENERAL => 'oferta ogólna', self::OFFER_OWN => 'Moja oferta'] as $offer => $label) {
            $offerProducts = $this->listRows($offer, true, $label);
            $offerLeaves = $this->listRows($offer, false, $label);
            $this->summary[] = sprintf('Lista P4S (%s): %d wyrobów, %d pozycji do zamówienia (pojedyncze i rozmiary)', $label, count($offerProducts), count($offerLeaves));
            $products += $offerProducts;
            $leaves += $offerLeaves;
        }

        $sizes = [];
        $orphans = 0;
        foreach ($leaves as $leaf) {
            if (($leaf['type'] ?? null) !== self::TYPE_SIZE) {
                continue;
            }
            $parent = $leaf['parentProductId'] ?? null;
            if (! is_int($parent) || ! isset($products[$parent])) {
                $orphans++;

                continue;
            }
            $sizes[$parent][] = $leaf;
        }

        $rows = [];
        $otherTypes = [];
        foreach ($products as $row) {
            $type = (string) ($row['type'] ?? '');
            if ($type === self::TYPE_SINGLE || $type === self::TYPE_MOTHER) {
                $rows[] = $row;
            } else {
                $otherTypes[] = self::field($row['code'] ?? '').' (typ '.$type.')';
            }
        }

        $this->summary[] = 'Lista P4S razem: '.count($rows).' wyrobów bez powtórzeń';
        if ($otherTypes !== []) {
            $this->summary[] = 'Pozycje, które nie są wyrobem (usługi) — pominięte: '.self::listing($otherTypes);
        }
        if ($orphans > 0) {
            $this->summary[] = 'Rozmiary bez swojego wyrobu na liście — pominięte: '.$orphans;
        }

        return ['products' => $rows, 'sizes' => $sizes];
    }

    /**
     * Cała lista jednej oferty; niespójna — jedno ponowne pobranie od początku.
     *
     * @return array<int, array<string, mixed>> id → pozycja
     */
    private function listRows(string $offer, bool $mothers, string $label): array
    {
        try {
            return $this->scanList($offer, $mothers, $label);
        } catch (RuntimeException $e) {
            if ($e->getCode() !== self::INCONSISTENT) {
                throw $e;
            }
            $this->progress('Lista zmieniła się w trakcie pobierania ('.$e->getMessage().') — pobieram od nowa');
        }

        try {
            return $this->scanList($offer, $mothers, $label);
        } catch (RuntimeException $e) {
            if ($e->getCode() !== self::INCONSISTENT) {
                throw $e;
            }

            throw new RuntimeException('Lista produktów '.P4sB2bClient::HOST.' ('.$label.') niespójna także po ponownym pobraniu: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function scanList(string $offer, bool $mothers, string $label): array
    {
        $started = microtime(true);
        $what = $mothers ? 'wyroby' : 'rozmiary';
        $rows = [];
        $total = 0;
        $pages = 1;

        for ($page = 1; $page <= $pages; $page++) {
            if (microtime(true) - $started > self::LIST_BUDGET_SECONDS) {
                throw new RuntimeException('Pobieranie listy '.P4sB2bClient::HOST.' trwa ponad '.(self::LIST_BUDGET_SECONDS / 60).' min — przerwane bez zapisu');
            }
            $json = $this->client->listPage($offer, $mothers, ($page - 1) * $this->pageSize, $this->pageSize);
            $pageTotal = $json['recordsTotal'];
            if ($page === 1) {
                $total = $pageTotal;
                if ($total <= 0 && $offer === self::OFFER_GENERAL) {
                    throw new RuntimeException('Lista produktów '.P4sB2bClient::HOST.' ('.$label.') pusta — pusta oferta konta albo zmiana platformy');
                }
                $pages = max(1, (int) ceil($total / $this->pageSize));
                $this->progress(sprintf('Lista P4S (%s, %s): %d pozycji na %d stronach', $label, $what, $total, $pages));
            } elseif ($pageTotal !== $total) {
                throw new RuntimeException('liczba pozycji zmieniła się z '.$total.' na '.$pageTotal.' (strona '.$page.')', self::INCONSISTENT);
            }

            $expected = max(0, min($this->pageSize, $total - ($page - 1) * $this->pageSize));
            if (count($json['products']) !== $expected) {
                throw new RuntimeException(sprintf('strona %d: %d pozycji, oczekiwano %d', $page, count($json['products']), $expected), self::INCONSISTENT);
            }

            foreach ($json['products'] as $item) {
                $id = is_array($item) ? ($item['id'] ?? null) : null;
                if (! is_int($id) || self::field($item['code'] ?? '') === '') {
                    throw new RuntimeException('Strona '.$page.' listy '.P4sB2bClient::HOST.': pozycja bez id albo kodu — zmiana platformy?');
                }
                if (isset($rows[$id])) {
                    throw new RuntimeException('pozycja '.$id.' na dwóch stronach', self::INCONSISTENT);
                }
                $rows[$id] = $item;
            }
        }

        if (count($rows) !== $total) {
            throw new RuntimeException('pobrano '.count($rows).' z '.$total.' pozycji', self::INCONSISTENT);
        }

        return $rows;
    }

    /**
     * Karty jednego wyrobu (jedna na każdą cenę); karta nieczytelna albo cudza — jedna pozycja pominięta z powodem.
     *
     * @param  array<string, mixed>  $row  pozycja listy (matka albo wyrób pojedynczy)
     * @param  list<array<string, mixed>>  $sizes  rozmiary matki z listy
     * @return list<B2bRemoteProduct>
     */
    private function productsFor(array $row, array $sizes): array
    {
        $id = (int) $row['id'];
        $code = self::field($row['code']);
        $name = self::field($row['name'] ?? '');

        /** @var list<array{id: string, code: string, name: string, label: string, size_name: string, manufacturer_code: string, cents: int, available: bool}> $variants */
        $variants = [];
        if ($row['type'] === self::TYPE_SINGLE) {
            $cents = self::priceCents((string) ($row['netPrice'] ?? ''));
            if ($cents === null || $cents <= 0) {
                return [$this->skipped($row, 'cena „'.self::field($row['netPrice'] ?? '').'” nieczytelna')];
            }
            $variants[] = [
                'id' => (string) $id,
                'code' => $code,
                'name' => $name,
                'label' => '',
                'size_name' => '',
                'manufacturer_code' => self::field($row['manufacturerCode'] ?? ''),
                'cents' => $cents,
                'available' => ($row['available'] ?? '') === '1',
            ];
        } else {
            $missing = false;
            foreach ($sizes as $size) {
                $cents = self::priceCents((string) ($size['netPrice'] ?? ''));
                if ($cents === null || $cents <= 0) {
                    $missing = true;

                    continue;
                }
                $sizeName = self::field($size['name'] ?? '');
                $variants[] = [
                    'id' => (string) $size['id'],
                    'code' => self::field($size['code']),
                    'name' => $sizeName,
                    'label' => self::sizeLabel($size, $name),
                    'size_name' => self::field($size['sizeName'] ?? ''),
                    'manufacturer_code' => self::field($size['manufacturerCode'] ?? ''),
                    'cents' => $cents,
                    'available' => ($size['available'] ?? '') === '1',
                ];
            }
            if ($missing) {
                $this->sizesWithoutPrice[] = $code;
            }
            if ($variants === []) {
                return [$this->skipped($row, $sizes === [] ? 'wyrób bez rozmiarów w ofercie konta' : 'żaden rozmiar nie ma ceny')];
            }
        }

        try {
            $json = $this->client->product($id);
            if (($json['productNotInOffer'] ?? false) === true || ! is_array($json['product'] ?? null)) {
                return [$this->skipped($row, 'platforma: wyrobu nie ma w ofercie konta')];
            }
            $detail = self::parseProduct($json['product']);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return [$this->skipped($row, 'karta wyrobu: '.$e->getMessage())];
        }
        if ($detail['code'] !== $code) {
            return [$this->skipped($row, 'karta dotyczy innego kodu ('.$detail['code'].')')];
        }
        if ($detail['description'] === '') {
            $this->withoutDescription[] = $code;
        }

        /** @var array<int, list<array{id: string, code: string, name: string, label: string, size_name: string, manufacturer_code: string, cents: int, available: bool}>> $groups */
        $groups = [];
        foreach ($variants as $variant) {
            $groups[$variant['cents']][] = $variant;
        }
        $split = count($groups) > 1;
        $this->splitProducts += $split ? 1 : 0;

        $products = [];
        foreach ($groups as $cents => $group) {
            $products[] = $this->productFor($row, $detail, $group, $cents, $split, $variants);
        }
        $this->cards += count($products);

        return $products;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $detail  wynik parseProduct()
     * @param  list<array{id: string, code: string, name: string, label: string, size_name: string, manufacturer_code: string, cents: int, available: bool}>  $group
     * @param  list<array{manufacturer_code: string}>  $allVariants  wszystkie rozmiary wyrobu (wszystkich grup cenowych)
     */
    private function productFor(array $row, array $detail, array $group, int $cents, bool $split, array $allVariants = []): B2bRemoteProduct
    {
        $single = $row['type'] === self::TYPE_SINGLE;
        $base = $detail['name'] !== '' ? $detail['name'] : self::field($row['name'] ?? '');
        $labels = array_column($group, 'label');

        return new B2bRemoteProduct(
            remoteId: $group[0]['id'],
            sku: $split ? $group[0]['code'] : $detail['code'],
            name: $split ? $base.' ('.self::variantsLabel($labels).')' : $base,
            category: $detail['group'] !== '' ? $detail['group'] : null,
            sourceUrl: P4sB2bClient::PRODUCT_PAGE.$row['id'],
            raw: ['status' => 'ok', 'cents' => $cents, ...$detail],
            availability: self::groupAvailability($group, $single),
            variantSummary: $single ? '' : 'Warianty: '.implode('; ', array_map(
                static fn (array $v): string => $v['label'].' ('.$v['code'].')',
                $group,
            )),
            // dostępność rozmiaru tekstem platformy — karta rozdzielona na rozmiary (mapa połączeń) dostaje
            // dostępność swojego rozmiaru, nie zbiorczą listę grupy
            members: $single ? [] : array_map(static fn (array $v): array => [
                'remote_id' => $v['id'],
                'sku' => $v['code'],
                'name' => $v['name'] !== '' ? $v['name'] : $base.', '.$v['label'],
                'availability' => self::groupAvailability([$v], true),
            ], $group),
            identifiers: self::identifiers($detail, $group, $single, $allVariants !== [] ? $allVariants : $group),
        );
    }

    /**
     * Kod producenta z karty wyrobu (manufacturerCode, „PS18”) na każdej karcie wyrobu — także na każdej karcie
     * z podziału cenowego (po nim łączymy wyroby JALAS/TEGERA z kartami Ejendals). Kod P4S wyrobu z rozmiarami
     * („11.999”) to kod rodziny (model_code); kod P4S rozmiaru („11.999/F07,0”) i wyrobu pojedynczego („000999”)
     * to własny kod dystrybutora (source_code) pozycji, a kod producenta z wiersza listy (manufacturerCode
     * pozycji) — kod producenta tej pozycji. Pozycja = id rozmiaru / wyrobu w platformie (remote_id powiązania);
     * etykieta = sizeName dosłownie (pusty — bez etykiety, nie składamy jej z nazwy).
     *
     * @param  array<string, mixed>  $detail  wynik parseProduct()
     * @param  list<array{id: string, code: string, name: string, label: string, size_name: string, manufacturer_code: string, cents: int, available: bool}>  $group
     * @param  list<array{manufacturer_code: string}>  $allVariants  wszystkie rozmiary wyrobu — kod karty równy kodowi
     *                                                               rozmiaru z innej grupy cenowej też jest kodem rozmiaru
     * @return list<B2bRemoteIdentifier>
     */
    private static function identifiers(array $detail, array $group, bool $single, array $allVariants = []): array
    {
        $out = [];
        $cardCode = self::cardManufacturerCodeType($detail['manufacturer_code'], $group, $single, $allVariants !== [] ? $allVariants : $group);
        if ($cardCode !== null) {
            $out[] = new B2bRemoteIdentifier(type: $cardCode, value: $detail['manufacturer_code'], field: 'manufacturerCode');
        }
        if (! $single && $detail['code'] !== '') {
            $out[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_MODEL_CODE, value: $detail['code'], field: 'code');
        }
        foreach ($group as $variant) {
            $label = $variant['size_name'] !== '' ? $variant['size_name'] : null;
            if ($variant['code'] !== '') {
                $out[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_SOURCE_CODE, value: $variant['code'], remoteId: $variant['id'], label: $label, field: 'code');
            }
            if ($variant['manufacturer_code'] !== '') {
                $out[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_MANUFACTURER_CODE, value: $variant['manufacturer_code'], remoteId: $variant['id'], label: $label, field: 'manufacturerCode');
            }
        }

        return $out;
    }

    /**
     * Typ kodu producenta z karty wyrobu (manufacturerCode karty); null = pomijamy. Kod bez pozycji trafia pod pozycję
     * karty, czyli pierwszego rozmiaru grupy (ProductIdentifierStore) — przy wyrobie z rozmiarami, które mają własne
     * kody, to przypisywało rozmiarowi cudzy kod (24.09.2026: P4S „6X00 Półmaska 3M 6000” ma kod karty 7000146847 = kod
     * rozmiaru M, zapisany pod rozmiarem S — S wskazywał dwie karty 3M). Dlatego:
     * - wyrób pojedynczy albo rozmiary bez własnych kodów → kod producenta jak dotąd (JALAS/TEGERA „PS18” — po nim
     *   łączymy z kartami Ejendals);
     * - kod równy własnemu kodowi któregoś rozmiaru wyrobu (także z innej grupy cenowej) → pomijamy (to ten rozmiar,
     *   jest pod swoją pozycją);
     * - rozmiary tej grupy z własnymi kodami, kod karty inny → kod modelu (FR360 obok FR360-08-58): zostaje w danych,
     *   ale nie udaje kodu producenta pierwszego rozmiaru (dopasowanie kart go nie używa).
     *
     * @param  list<array{manufacturer_code: string}>  $group
     * @param  list<array{manufacturer_code: string}>  $allVariants
     */
    private static function cardManufacturerCodeType(string $code, array $group, bool $single, array $allVariants): ?string
    {
        if ($code === '') {
            return null;
        }
        if ($single) {
            return ProductIdentifier::TYPE_MANUFACTURER_CODE;
        }
        $own = static function (array $variants): array {
            $codes = [];
            foreach ($variants as $variant) {
                $normalized = $variant['manufacturer_code'] !== '' ? ProductIdentifierCode::code($variant['manufacturer_code']) : null;
                if ($normalized !== null) {
                    $codes[$normalized] = true;
                }
            }

            return $codes;
        };
        $normalized = ProductIdentifierCode::code($code);
        if ($normalized !== null && isset($own($allVariants)[$normalized])) {
            return null;
        }

        return $own($group) === [] ? ProductIdentifier::TYPE_MANUFACTURER_CODE : ProductIdentifier::TYPE_MODEL_CODE;
    }

    /**
     * Oznaczenie rozmiaru dosłownie z platformy („kolor biały, rozmiar L”); puste — reszta nazwy rozmiaru po nazwie
     * wyrobu, a gdy i tej brak — kod rozmiaru.
     *
     * @param  array<string, mixed>  $size
     */
    private static function sizeLabel(array $size, string $motherName): string
    {
        $label = self::field($size['sizeName'] ?? '');
        if ($label !== '') {
            return $label;
        }
        $name = self::field($size['name'] ?? '');
        if ($motherName !== '' && str_starts_with($name, $motherName)) {
            $rest = trim(mb_substr($name, mb_strlen($motherName)), ' ,');
            if ($rest !== '') {
                return $rest;
            }
        }

        return self::field($size['code']);
    }

    /**
     * „rozmiar 7; rozmiar 8”; długa lista skrócona do pierwszych i ostatniego z liczbą — pełna lista jest
     * w podsumowaniu wariantów karty.
     *
     * @param  list<string>  $labels
     */
    private static function variantsLabel(array $labels): string
    {
        if (count($labels) <= self::VARIANTS_IN_NAME) {
            return implode('; ', $labels);
        }

        return count($labels).' wariantów: '.implode('; ', array_slice($labels, 0, self::VARIANTS_IN_NAME - 1)).' … '.$labels[count($labels) - 1];
    }

    /**
     * Jedna dostępność dla całej karty — tekstem platformy; różne — „Produkt dostępny: rozmiar 8, rozmiar 9;
     * Produkt niedostępny: rozmiar 11”.
     *
     * @param  list<array{id: string, code: string, name: string, label: string, size_name: string, manufacturer_code: string, cents: int, available: bool}>  $group
     */
    private static function groupAvailability(array $group, bool $single): string
    {
        $statuses = [];
        foreach ($group as $variant) {
            $statuses[$variant['available'] ? 'Produkt dostępny' : 'Produkt niedostępny'][] = $variant['label'];
        }
        if (count($statuses) === 1 || $single) {
            return (string) array_key_first($statuses);
        }
        $parts = [];
        foreach ($statuses as $status => $labels) {
            $parts[] = $status.': '.implode(', ', $labels);
        }

        return implode('; ', $parts);
    }

    private static function documentKind(string $title): string
    {
        $title = mb_strtolower($title);

        return match (true) {
            str_contains($title, 'deklaracj') || str_contains($title, 'certyfikat') => ProductDocument::KIND_CERTIFICATE,
            str_contains($title, 'instrukcj') => ProductDocument::KIND_MANUAL,
            str_contains($title, 'tabela rozmiar') => ProductDocument::KIND_SIZE_CHART,
            str_contains($title, 'karta techniczna') || str_contains($title, 'karta katalogowa') => ProductDocument::KIND_DATASHEET,
            default => ProductDocument::KIND_OTHER,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function skipped(array $row, string $reason): B2bRemoteProduct
    {
        $this->skippedProducts++;
        $code = self::field($row['code']);
        $name = self::field($row['name'] ?? '');

        return new B2bRemoteProduct(
            remoteId: (string) $row['id'],
            sku: $code,
            name: $name !== '' ? $name : $code,
            sourceUrl: P4sB2bClient::PRODUCT_PAGE.$row['id'],
            raw: ['status' => 'skipped', 'reason' => $reason],
        );
    }

    /**
     * Pary „etykieta → wartość” (oznaczenia, opis techniczny) dosłownie; niepełne pominięte.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function pairs(mixed $items): array
    {
        $pairs = [];
        foreach (self::listOf($items) as $item) {
            $label = self::field($item['discriminant'] ?? '');
            $value = self::field($item['value'] ?? '');
            if ($label !== '' && $value !== '') {
                $pairs[] = [$label, $value];
            }
        }

        return $pairs;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function listOf(mixed $items): array
    {
        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
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

    private static function field(mixed $value): string
    {
        return is_string($value) ? self::clean(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    }

    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $value));
    }
}
