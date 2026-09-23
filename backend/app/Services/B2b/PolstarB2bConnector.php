<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use SimpleXMLElement;

/**
 * polstar.com.pl — sklep producenta odzieży, obuwia i rękawic (kolekcje BRIXTON, COVENT, BEARFIELD, …).
 * Lista, opis i dane wyrobu z pliku XML konta (PolstarB2bClient::productsXml), cena konta z kafelków stron kategorii,
 * pliki PDF i kategoria ochrony ze strony produktu.
 *
 * Karta = jeden produkt sklepu (id z XML). Kolory i rozmiary są wariantami produktu bez osobnych cen (XML nie podaje
 * ceny wariantu, a strona produktu ma jedną cenę), więc trafiają do listy rozmiarów karty, nie na osobne karty.
 * Kod produktu u Polstaru nie jest unikalny (ABOG to trzy produkty w różnych cenach: zwykłe, z odblaskiem,
 * czerwone), a products.sku jest — dlatego SKU karty to „kod-id” (np. „RCCS-64”); sam kod jest w tabelce sklepu.
 *
 * Cena konta: kafelek „TWOJA CENA”, a bez rabatu kafelek „DETAL” — wtedy strona produktu podaje tę samą kwotę jako
 * „Cena po rabacie” (sprawdzone 21.09.2026 na MYNTS). Produkt, którego nie ma w żadnej kategorii menu (ochronniki
 * słuchu CHAPLIN 21.09.2026), szukamy wyszukiwarką sklepu po nazwie; jej kafelki nie mają id, więc kafelek o tym
 * samym kodzie potwierdzamy id ze strony produktu.
 *
 * Polstar jest producentem swoich kolekcji (B2bManufacturerSite): opis stąd pochodzi od autora wyrobu. Pojedyncze
 * wyroby innych producentów (np. SUMIRUBBER) mają ich nazwę jako producenta, więc reguła marki ich nie obejmuje.
 *
 * Opis w pliku XML to krótkie hasła, a zastosowanie, kategoria ochrony i zakres rozmiarów są w instrukcji PDF —
 * dlatego B2bDescribesFromDatasheet: opis karty pisze model wyłącznie z opisu sklepu i instrukcji zapisanej przy
 * karcie, jak u Tegro (decyzja użytkownika 21.09.2026).
 */
final class PolstarB2bConnector implements B2bConnector, B2bDescribesFromDatasheet, B2bDocumentSource, B2bManufacturerSite, B2bRunSummaryAware, B2bShopFieldSource
{
    private const SHOP_SECTION = 'Parametry produktu';

    private const TRADE_SECTION = 'Informacje handlowe';

    /** Więcej produktów bez kafelka niż tyle = lista kategorii się nie pobrała; nie odpytujemy wyszukiwarki setki razy. */
    private const MAX_SEARCHES = 30;

    private int $total = 0;

    /** @var list<string> linie podsumowania przebiegu (B2bRunSummaryAware) */
    private array $summary = [];

    /** @var array{url: string, xpath: DOMXPath}|null ostatnio pobrana strona produktu (pliki i kategoria ochrony) */
    private ?array $lastPage = null;

    public function __construct(private readonly PolstarB2bClient $client) {}

    public static function key(): string
    {
        return 'polstar';
    }

    public static function label(): string
    {
        return 'Polstar';
    }

    public static function host(): string
    {
        return PolstarB2bClient::HOST;
    }

    public static function ownBrand(): string
    {
        return 'Polstar';
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new PolstarB2bClient($account->username, (string) $account->password, $delayMs));
    }

    public function login(): void
    {
        $this->client->login();
    }

    public function products(): iterable
    {
        $items = self::parseProductsXml($this->client->productsXml());
        if ($items === []) {
            throw new RuntimeException('Plik XML produktów '.PolstarB2bClient::HOST.' jest pusty albo nieczytelny');
        }
        $this->total = count($items);
        $this->summary = ['Lista Polstar (plik XML konta): '.count($items).' produktów'];

        $tiles = $this->tiles($items);

        foreach ($items as $item) {
            yield $this->productFor($item, $tiles[$item['id']] ?? null);
        }
    }

    public function totalProducts(): int
    {
        return $this->total;
    }

    public function runSummary(): array
    {
        return $this->summary;
    }

    /** Polstar dla jego kolekcji, a dla wyrobów innych producentów ich nazwa z pliku XML, dosłownie. */
    public function manufacturer(B2bRemoteProduct $product): string
    {
        $producer = self::text($product->raw['producer'] ?? null);

        return self::isOwnProducer($producer) ? self::label() : $producer;
    }

    /** Pusty producent albo „Polstar …” w pliku XML = wyrób kolekcji Polstaru. */
    private static function isOwnProducer(string $producer): bool
    {
        return $producer === '' || str_contains(mb_strtolower($producer), 'polstar');
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        $net = $product->raw['price'] ?? null;
        if (! is_float($net) || $net <= 0) {
            return null;
        }
        $base = $product->raw['retail_price'] ?? null;

        return new B2bRemotePrice(
            net: $net,
            base: is_float($base) && $base > 0 ? $base : null,
            // sklep nie podaje rabatu wprost, więc go nie liczymy
            discountPercent: 0.0,
            currency: 'PLN',
        );
    }

    /**
     * Opis szczegółowy z pliku XML (na stronie produktu sekcja „Opis”): „Nazwa: wartości” w wierszach, w kolejności
     * ze źródła. Znaczniki poziomu przed nazwą („..Rodzaj rękawicy:”) pomijamy — to wcięcie, nie treść.
     */
    public function description(B2bRemoteProduct $product): string
    {
        $lines = [];
        foreach ((array) ($product->raw['details'] ?? []) as [$name, $values]) {
            $name = ltrim($name, '. ');
            $value = implode(' ', $values);
            if ($name === '' && $value === '') {
                continue;
            }
            if ($name === '') {
                $lines[] = $value;

                continue;
            }
            $name = rtrim($name, ': ');
            $lines[] = $value !== '' ? $name.': '.$value : $name;
        }

        return mb_substr(implode("\n", $lines), 0, 10000);
    }

    /**
     * Dane wyrobu z pliku XML (kod, kolekcja, cechy, normy, opakowanie, producent) i kategoria ochrony ze strony
     * produktu — jedyne pole, którego XML nie podaje. Wszystko dosłownie ze sklepu.
     *
     * @return list<B2bRemoteShopField>
     */
    public function shopFields(B2bRemoteProduct $product): array
    {
        $fields = [];
        $add = static function (string $section, string $name, string $value) use (&$fields): void {
            if ($name !== '' && $value !== '') {
                $fields[] = new B2bRemoteShopField($section, $name, $value);
            }
        };

        $raw = $product->raw;
        $add(self::SHOP_SECTION, 'Kod produktu', self::text($raw['code'] ?? null));
        $add(self::SHOP_SECTION, 'Kolekcja', self::text($raw['collection'] ?? null));
        foreach ((array) ($raw['features'] ?? []) as [$name, $value, $unit]) {
            $add(self::SHOP_SECTION, rtrim($name, ': '), $unit !== '' ? $value.' '.$unit : $value);
        }
        foreach ((array) ($raw['norms'] ?? []) as $norm) {
            $add(self::SHOP_SECTION, 'Norma', $norm);
        }
        $xpath = $this->pageXPath($product);
        if ($xpath !== null) {
            $add(self::SHOP_SECTION, 'Kategoria ochrony', self::pageInfo($xpath, 'Kategoria ochrony'));
        }
        $add(self::SHOP_SECTION, 'Ilość w kartonie', self::text($raw['package'] ?? null));
        $add(self::SHOP_SECTION, 'Producent', self::text($raw['producer'] ?? null));

        $add(self::TRADE_SECTION, 'Kategoria', self::text($raw['category_path'] ?? null));
        $add(self::TRADE_SECTION, 'Kolory', implode('; ', self::colors($raw['variants'] ?? [])));
        $add(self::TRADE_SECTION, 'Rozmiary', implode('; ', self::sizes($raw['variants'] ?? [])));

        return $fields;
    }

    /**
     * Pliki z pola „Pliki” strony produktu. Deklaracje PPWR dotyczą opakowania (folia, karton), nie wyrobu —
     * pomijamy je. Kolejność: karta, instrukcja, deklaracja zgodności, reszta — synchronizacja czyta tekst pierwszej
     * karty technicznej albo instrukcji (B2bCatalogSync::DESCRIPTION_SOURCE_KINDS).
     */
    public function documents(B2bRemoteProduct $product): array
    {
        $xpath = $this->pageXPath($product);
        if ($xpath === null) {
            return [];
        }

        $documents = [];
        foreach ($xpath->query('//a['.self::classPredicate('product-file').'][@href]') ?: [] as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }
            $url = self::absoluteUrl($link->getAttribute('href'));
            $title = self::inline($link->textContent);
            if ($url === null || $title === '' || isset($documents[$url]) || str_contains(mb_strtoupper($title), 'PPWR')) {
                continue;
            }
            [$rank, $kind] = self::documentKind(mb_strtolower($title));
            $documents[$url] = ['rank' => $rank, 'document' => new B2bRemoteDocument($title, $url, $kind)];
        }
        // sortowanie stabilne: w obrębie rodzaju zostaje kolejność ze strony
        $list = array_values($documents);
        usort($list, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        return array_map(static fn (array $row): B2bRemoteDocument => $row['document'], $list);
    }

    public function documentBytes(B2bRemoteDocument $document): array
    {
        if (! PolstarB2bClient::isOwnUrl($document->sourceUrl)) {
            throw new RuntimeException('plik spoza '.PolstarB2bClient::HOST.': '.$document->sourceUrl);
        }

        return $this->client->fileBytes($document->sourceUrl);
    }

    /** Pierwsze zdjęcie z pliku XML — w sklepie zdjęcie ogólne produktu, przed zdjęciami kolorów. */
    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        $url = self::text(((array) ($product->raw['photos'] ?? []))[0] ?? null);
        if ($url === '' || ! PolstarB2bClient::isOwnUrl($url)) {
            return null;
        }
        $file = $this->client->fileBytes($url);
        if (! str_starts_with($file['mime'], 'image/') || $file['bytes'] === '') {
            return null;
        }

        return new B2bRemoteImage(bytes: $file['bytes'], mime: $file['mime'], sourceUrl: $url);
    }

    /**
     * Produkty z pliku XML. Wartości dosłownie (bez skracania białych znaków wewnątrz), puste pola jako ''.
     *
     * @return list<array{id: string, name: string, code: string, collection: string, category_path: string, producer: string,
     *     retail_price: float|null, package: string, norms: list<string>, features: list<array{0: string, 1: string, 2: string}>,
     *     details: list<array{0: string, 1: list<string>}>, photos: list<string>, variants: list<array{color: string, size: string, ean: string, code: string}>}>
     */
    public static function parseProductsXml(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, options: LIBXML_NOCDATA | LIBXML_NONET | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($document === false) {
            return [];
        }

        $items = [];
        foreach ($document->produkt as $product) {
            $id = self::text((string) $product->id);
            if ($id === '') {
                continue;
            }
            $retail = self::text((string) $product->cena_detaliczna);
            $items[] = [
                'id' => $id,
                'name' => self::text((string) $product->nazwa),
                'code' => self::text((string) $product->kod_produktu),
                'collection' => self::text((string) $product->kolekcja),
                'category_path' => self::text((string) $product->kategorie),
                'producer' => self::text((string) $product->producent),
                'retail_price' => is_numeric($retail) ? round((float) $retail, 2) : null,
                'package' => self::text((string) $product->opakowanie_zbiorcze),
                'norms' => self::texts($product->normy->norma ?? null),
                'features' => array_values(array_filter(array_map(
                    static fn (SimpleXMLElement $f): array => [self::text((string) $f->nazwa), self::text((string) $f->wartosc), self::text((string) $f->jednostka)],
                    self::children($product->cechy->cecha ?? null),
                ), static fn (array $f): bool => $f[0] !== '' && $f[1] !== '')),
                'details' => array_map(
                    static fn (SimpleXMLElement $e): array => [self::text((string) $e->nazwa), self::texts($e->wartosci->wartosc ?? null)],
                    self::children($product->dane_szczegolowe->element ?? null),
                ),
                'photos' => array_values(array_filter(array_map(
                    static fn (SimpleXMLElement $photo): string => self::text((string) $photo->url),
                    self::children($product->zdjecia->zdjecie ?? null),
                ), static fn (string $url): bool => $url !== '')),
                'variants' => array_map(
                    static fn (SimpleXMLElement $v): array => [
                        'color' => self::text((string) $v->kolor),
                        'size' => self::text((string) $v->rozmiar),
                        'ean' => self::text((string) $v->ean13),
                        'code' => self::text((string) $v->kod_produktu),
                    ],
                    self::children($product->warianty->wariant ?? null),
                ),
            ];
        }

        return $items;
    }

    /**
     * Kafelki produktów ze strony kategorii: id produktu → adres strony i cena konta.
     *
     * @return array<string, array{url: string, price: float|null}>
     */
    public static function parseTiles(string $html): array
    {
        $tiles = [];
        foreach (self::parseTileList($html) as $tile) {
            if ($tile['id'] !== null) {
                $tiles[$tile['id']] ??= ['url' => $tile['url'], 'price' => $tile['price']];
            }
        }

        return $tiles;
    }

    /**
     * Kafelki w kolejności ze strony. Id produktu jest w przycisku porównania, którego kafelki wyszukiwarki
     * nie mają (21.09.2026) — tam id = null, a kafelek rozpoznajemy po kodzie produktu.
     *
     * @return list<array{id: string|null, code: string, url: string, price: float|null}>
     */
    public static function parseTileList(string $html): array
    {
        $tiles = [];
        $parts = preg_split('/<div class="product-card">/', $html) ?: [];
        array_shift($parts);
        foreach ($parts as $part) {
            // kafelek kończy się przed następnym; ogon strony za ostatnim nie ma już pól kafelka
            if (preg_match('/data-slug="([^"]+)"/', $part, $slug) !== 1) {
                continue;
            }
            $id = preg_match('/data-id="(\d+)"/', $part, $idMatch) === 1 ? $idMatch[1] : null;
            $code = preg_match('#class="product-card-symbol">([^<]*)<#', $part, $codeMatch) === 1
                ? self::inline(html_entity_decode($codeMatch[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))
                : '';
            $price = null;
            if (preg_match('#class="product-card-price">(.*?)</div>#s', $part, $block) === 1) {
                $text = self::inline(html_entity_decode(strip_tags($block[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                // „1,59 zł TWOJA CENA”, a bez rabatu „152,84 zł DETAL”; inna etykieta = nie wiemy, co to za cena
                if (preg_match('/^([\d ]+,\d{2}) zł (TWOJA CENA|DETAL)$/u', $text, $m) === 1) {
                    $price = round((float) str_replace([' ', ','], ['', '.'], $m[1]), 2);
                }
            }
            $tiles[] = [
                'id' => $id,
                'code' => $code,
                'url' => PolstarB2bClient::BASE.'/produkt/'.rawurlencode(html_entity_decode($slug[1])),
                'price' => $price,
            ];
        }

        return $tiles;
    }

    /** Id produktu ze strony produktu (skrypt strony: var product = { id: '64', … }); null gdy go nie ma. */
    public static function productIdOfPage(string $html): ?string
    {
        return preg_match("/var product = \\{\\s*id: '(\\d+)'/", $html, $m) === 1 ? $m[1] : null;
    }

    /**
     * Kafelki wszystkich produktów z listy: najpierw strony kategorii z menu, potem wyszukiwarka dla brakujących.
     * Błąd pojedynczej strony nie przerywa przebiegu — produkty bez kafelka zostają bez ceny (pominięte przez
     * synchronizację z powodem), a podsumowanie mówi, ile ich jest.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, array{url: string, price: float|null}>
     */
    private function tiles(array $items): array
    {
        $tiles = [];
        $failed = 0;
        foreach ($this->client->categoryUrls() as $url) {
            try {
                $tiles += self::parseTiles($this->client->listingPage($url));
            } catch (B2bFatalException $e) {
                throw $e;
            } catch (RuntimeException) {
                $failed++;
            }
        }
        // bez żadnego kafelka nie ma cennika — to awaria listy kategorii, nie 599 produktów bez ceny
        if ($tiles === []) {
            throw new RuntimeException('Strony kategorii '.PolstarB2bClient::HOST.' nie podały żadnego produktu z ceną konta');
        }
        if ($failed > 0) {
            $this->summary[] = 'Strony kategorii niepobrane: '.$failed;
        }

        $missing = array_values(array_filter($items, static fn (array $item): bool => ! isset($tiles[$item['id']])));
        $searched = 0;
        foreach (array_slice($missing, 0, self::MAX_SEARCHES) as $item) {
            if ($item['name'] === '') {
                continue;
            }
            try {
                $searched++;
                $tile = $this->searchTile($item);
            } catch (B2bFatalException $e) {
                throw $e;
            } catch (RuntimeException) {
                continue;
            }
            if ($tile !== null) {
                $tiles[$item['id']] = $tile;
            }
        }
        if ($missing !== []) {
            $this->summary[] = 'Produkty spoza kategorii menu: '.count($missing).', wyszukiwarka sklepu: '.$searched;
        }

        $withoutPrice = count(array_filter($items, static fn (array $item): bool => ($tiles[$item['id']]['price'] ?? null) === null));
        if ($withoutPrice > 0) {
            $this->summary[] = 'Produkty bez ceny konta na stronach sklepu: '.$withoutPrice.' — pominięte';
        }

        return $tiles;
    }

    /**
     * Kafelek produktu z wyszukiwarki sklepu (szukamy po nazwie). Kafelki wyszukiwarki nie mają id, a kod produktu
     * bywa wspólny kilku produktom (ABOG) — kandydata o tym samym kodzie potwierdzamy id ze strony produktu.
     *
     * @param  array<string, mixed>  $item
     * @return array{url: string, price: float|null}|null
     */
    private function searchTile(array $item): ?array
    {
        $candidates = array_filter(
            self::parseTileList($this->client->searchPage($item['name'])),
            static fn (array $tile): bool => $tile['id'] === $item['id'] || ($tile['id'] === null && $tile['code'] === $item['code']),
        );
        foreach (array_slice($candidates, 0, 3) as $tile) {
            if ($tile['id'] === $item['id'] || self::productIdOfPage($this->client->page($tile['url'])) === $item['id']) {
                return ['url' => $tile['url'], 'price' => $tile['price']];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array{url: string, price: float|null}|null  $tile
     */
    private function productFor(array $item, ?array $tile): B2bRemoteProduct
    {
        $variants = $item['variants'];
        $colors = self::colors($variants);
        $sizes = self::sizes($variants);
        $summary = array_values(array_filter([
            $colors !== [] ? 'Kolory: '.implode('; ', $colors) : '',
            $sizes !== [] ? 'Rozmiary: '.implode('; ', $sizes) : '',
        ]));
        $segments = array_values(array_filter(array_map('trim', explode('->', $item['category_path']))));

        return new B2bRemoteProduct(
            remoteId: $item['id'],
            sku: $item['code'] !== '' ? $item['code'].'-'.$item['id'] : '',
            name: self::cardName($item['collection'], $item['name']),
            category: $segments !== [] ? end($segments) : null,
            sourceUrl: $tile['url'] ?? null,
            raw: [
                ...$item,
                'price' => $tile['price'] ?? null,
                'page_url' => $tile['url'] ?? null,
            ],
            variantSummary: $summary !== [] ? implode(' | ', $summary) : null,
            identifiers: self::identifiers($item),
        );
    }

    /**
     * Kod produktu i kody oraz EAN-y wariantów z pliku XML, dosłownie. Karta nie ma pozycji (members), więc wszystkie
     * należą do karty (remoteId null), a wariant opisuje etykieta: kolor i rozmiar bez znaczników braku („__”,
     * „_______a”). Kod wyrobu kolekcji Polstaru to kod producenta; kod wyrobu innego producenta (SUMIRUBBER) to kod
     * sklepu Polstar. SKU karty („kod-id”) składamy sami — nie jest identyfikatorem; kod_koloru i kod_rozmiaru to
     * tylko przyrostki wariantu, nie kody wyrobu.
     *
     * @param  array{code: string, producer: string, variants: list<array{color: string, size: string, ean: string, code: string}>}  $item
     * @return list<B2bRemoteIdentifier>
     */
    private static function identifiers(array $item): array
    {
        $codeType = self::isOwnProducer($item['producer']) ? ProductIdentifier::TYPE_MANUFACTURER_CODE : ProductIdentifier::TYPE_SOURCE_CODE;
        $out = [];
        if ($item['code'] !== '') {
            $out[] = new B2bRemoteIdentifier(type: $codeType, value: $item['code'], field: 'kod_produktu');
        }
        foreach ($item['variants'] as $variant) {
            $parts = [
                $variant['color'] !== '__' ? $variant['color'] : '',
                preg_match('/^_+[a-z]?$/', $variant['size']) !== 1 ? $variant['size'] : '',
            ];
            $label = trim(implode(' ', $parts));
            $label = $label !== '' ? $label : null;
            if ($variant['code'] !== '') {
                $out[] = new B2bRemoteIdentifier(type: $codeType, value: $variant['code'], label: $label, field: 'wariant/kod_produktu');
            }
            if ($variant['ean'] !== '') {
                $out[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_EAN, value: $variant['ean'], label: $label, field: 'wariant/ean13');
            }
        }

        return $out;
    }

    /**
     * Nazwa karty jak w sklepie: kolekcja przed nazwą produktu („COVENT” + „S kat. II”; nagłówek strony produktu
     * i plik CSV konta składają ją tak samo). Nazwy, która już zawiera nazwę kolekcji („TRZEWIK BEARFIELD X TRAIL”),
     * nie dublujemy.
     */
    public static function cardName(string $collection, string $name): string
    {
        $collection = rtrim($collection, '. ');
        if ($collection === '' || preg_match('/(?<![\p{L}\p{N}])'.preg_quote($collection, '/').'(?![\p{L}\p{N}])/iu', $name) === 1) {
            return $name;
        }

        return $name === '' ? '' : $collection.' '.$name;
    }

    /**
     * @param  list<array{color: string, size: string, ean: string, code: string}>  $variants
     * @return list<string> kolory w kolejności ze źródła; „__” to w sklepie brak koloru
     */
    private static function colors(array $variants): array
    {
        $colors = [];
        foreach ($variants as $variant) {
            if ($variant['color'] !== '' && $variant['color'] !== '__') {
                $colors[$variant['color']] = true;
            }
        }

        return array_keys($colors);
    }

    /**
     * @param  list<array{color: string, size: string, ean: string, code: string}>  $variants
     * @return list<string> rozmiary rosnąco; „_______a” to w sklepie wyrób bez rozmiaru (np. ochronniki słuchu)
     */
    private static function sizes(array $variants): array
    {
        $sizes = [];
        foreach ($variants as $variant) {
            if ($variant['size'] !== '' && preg_match('/^_+[a-z]?$/', $variant['size']) !== 1) {
                $sizes[$variant['size']] = true;
            }
        }
        $sizes = array_map('strval', array_keys($sizes));
        usort($sizes, UvexSizeGroups::compareSizes(...));

        return $sizes;
    }

    /** Strona produktu tej karty; pliki i kategoria ochrony czytają tę samą stronę — pobieramy ją raz na kartę. */
    private function pageXPath(B2bRemoteProduct $product): ?DOMXPath
    {
        $url = self::text($product->raw['page_url'] ?? null);
        if ($url === '' || ! PolstarB2bClient::isOwnUrl($url)) {
            return null;
        }
        if ($this->lastPage !== null && $this->lastPage['url'] === $url) {
            return $this->lastPage['xpath'];
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$this->client->page($url), LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        $this->lastPage = ['url' => $url, 'xpath' => $xpath];

        return $xpath;
    }

    /** Wartość pola z listy danych produktu na stronie („Kategoria ochrony:” → „II”). */
    private static function pageInfo(DOMXPath $xpath, string $label): string
    {
        foreach ($xpath->query('//dl['.self::classPredicate('product-info').']/dt') ?: [] as $dt) {
            if (rtrim(self::inline($dt->textContent), ': ') !== $label) {
                continue;
            }
            $dd = $dt->nextSibling;
            while ($dd !== null && ! ($dd instanceof DOMElement)) {
                $dd = $dd->nextSibling;
            }

            return $dd instanceof DOMElement && $dd->tagName === 'dd' ? self::inline($dd->textContent) : '';
        }

        return '';
    }

    /**
     * Rodzaj pliku po nazwie ze strony (sprawdzone 21.09.2026: „… deklaracja 2023.pdf”, „… instrukcja 2023.pdf”,
     * „CE user instruction - …”, „Deklaracja zgodnosci …”, literówka „deklaacja”, nazwa kolekcji bez opisu).
     *
     * @return array{0: int, 1: string} pozycja w kolejności i ProductDocument::KIND_*
     */
    private static function documentKind(string $title): array
    {
        return match (true) {
            str_contains($title, 'karta'), str_contains($title, 'datasheet') => [0, ProductDocument::KIND_DATASHEET],
            str_contains($title, 'instrukcj'), str_contains($title, 'instruction') => [1, ProductDocument::KIND_MANUAL],
            str_contains($title, 'deklarac'), str_contains($title, 'deklaacj'), str_contains($title, 'declaration') => [2, ProductDocument::KIND_CERTIFICATE],
            default => [3, ProductDocument::KIND_OTHER],
        };
    }

    private static function absoluteUrl(string $href): ?string
    {
        $href = trim($href);
        if ($href === '') {
            return null;
        }
        $url = str_starts_with($href, '/') && ! str_starts_with($href, '//') ? PolstarB2bClient::BASE.$href : $href;

        return PolstarB2bClient::isOwnUrl($url) ? $url : null;
    }

    /**
     * @return list<SimpleXMLElement>
     */
    private static function children(?SimpleXMLElement $nodes): array
    {
        $list = [];
        foreach ($nodes ?? [] as $node) {
            $list[] = $node;
        }

        return $list;
    }

    /**
     * Niepuste teksty węzłów, w kolejności ze źródła.
     *
     * @return list<string>
     */
    private static function texts(?SimpleXMLElement $nodes): array
    {
        $texts = array_map(static fn (SimpleXMLElement $node): string => self::text((string) $node), self::children($nodes));

        return array_values(array_filter($texts, static fn (string $t): bool => $t !== ''));
    }

    private static function classPredicate(string $class): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
    }

    private static function inline(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $text)) ?? $text);
    }

    /** Wartość dosłownie ze źródła — same białe znaki liczą się jak brak. */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
