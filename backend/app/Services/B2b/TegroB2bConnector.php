<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * b2b.tegro.pl (SolEx B2B) — dystrybutor rękawic marek RS Arbeitsschutz, G-REX, TK Gloves i Safeticult.
 * Lista i ceny konta z API sklepu (TegroB2bClient::products), tabelka parametrów i pliki PDF ze strony produktu.
 *
 * Rozmiary jednego wyrobu są u Tegro osobnymi pozycjami („RĘKAWICE G-REX F09 PLUS 6” … „11”), a sklep sam
 * podaje, które należą do jednego wyrobu — pole Model („RĘKAWICE G-REX F09 PLUS”). Karta = jeden model w jednej
 * cenie i jednostce (decyzja użytkownika 15.09.2026: rozmiar z inną ceną = osobna karta; u Tegro 21.09.2026
 * dotyczy to HEAVY, ULTRA TEC i BASS). Scalamy tylko wtedy, gdy nazwa pozycji to dokładnie Model + spacja +
 * rozmiar — inaczej pozycja zostaje osobną kartą, bez zgadywania.
 *
 * Tegro nie jest producentem tych marek, więc łącznik nie jest B2bManufacturerSite: opis ze sklepu nie nadpisuje
 * opisu z witryny producenta, a normy trafiają do tabelki sklepu (B2bShopFieldSource), nie do norm producenta.
 */
final class TegroB2bConnector implements B2bConnector, B2bDocumentSource, B2bRunSummaryAware, B2bShopFieldSource
{
    private const SHOP_SECTION = 'Parametry produktu';

    private const TRADE_SECTION = 'Informacje handlowe';

    private int $total = 0;

    /** @var list<string> linie podsumowania przebiegu (B2bRunSummaryAware) */
    private array $summary = [];

    /** @var array{url: string, xpath: DOMXPath}|null ostatnio pobrana strona produktu (tabelka i pliki są na tej samej) */
    private ?array $lastPage = null;

    public function __construct(private readonly TegroB2bClient $client) {}

    public static function key(): string
    {
        return 'tegro';
    }

    public static function label(): string
    {
        return 'Tegro';
    }

    public static function host(): string
    {
        return TegroB2bClient::HOST;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new TegroB2bClient($account->username, (string) $account->password, $delayMs));
    }

    public function login(): void
    {
        $this->client->login();
    }

    public function products(): iterable
    {
        $items = $this->client->products();
        $cards = self::group($items);
        $this->total = count($cards);
        $groups = count(array_filter($cards, static fn (array $group): bool => count($group) > 1));
        $this->summary = ['Lista Tegro: '.count($items).' pozycji → '.count($cards).' kart ('.$groups.' grup rozmiarów o tej samej cenie)'];

        // bez adresu strony cennik i opis z API wchodzą, a tabelka parametrów i pliki PDF czekają na kolejny przebieg
        try {
            $urls = $this->client->productUrls();
            $missing = count(array_filter($items, static fn (array $item): bool => ! isset($urls[self::text($item['Id'] ?? null)])));
            if ($missing > 0) {
                $this->summary[] = 'Pozycje bez adresu strony w pliku oferty XML: '.$missing.' — ich karty bez tabelki parametrów i plików PDF';
            }
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            $urls = [];
            $this->summary[] = 'Plik oferty XML nie został pobrany ('.$e->getMessage().') — karty bez tabelki parametrów i plików PDF';
        }

        foreach ($cards as $group) {
            yield $this->productFor($group, $urls);
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

    /** Marka ze sklepu, dosłownie („RS”, „G-REX”, „TK GLOVES”, „SAFETICULT”); bez marki — dostawca. */
    public function manufacturer(B2bRemoteProduct $product): string
    {
        $brand = self::text($product->raw['brand'] ?? null);

        return $brand !== '' ? $brand : self::label();
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        $net = self::money($product->raw['price'] ?? null);
        if ($net === null || $net['value'] <= 0 || $net['currency'] === '') {
            return null;
        }
        $base = self::money($product->raw['retail_price'] ?? null);

        return new B2bRemotePrice(
            net: $net['value'],
            // cena katalogowa tylko w tej samej walucie; sklep nie podaje rabatu wprost, więc go nie liczymy
            base: $base !== null && $base['value'] > 0 && $base['currency'] === $net['currency'] ? $base['value'] : null,
            discountPercent: 0.0,
            currency: $net['currency'],
        );
    }

    /** Opis z API, dosłownie — zwykły tekst, ten sam co w sekcji „Opis” strony produktu. */
    public function description(B2bRemoteProduct $product): string
    {
        $lines = [];
        foreach (preg_split('/\R/u', self::text($product->raw['description'] ?? null)) ?: [] as $line) {
            $line = trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $line) ?? $line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return mb_substr(implode("\n", $lines), 0, 10000);
    }

    /**
     * Tabelka „Parametry produktu” ze strony produktu (nazwa modelu, pakowanie, zakres rozmiarów, kod CN, normy)
     * i dane handlowe z listy API (marka, kategoria, jednostka, kod i EAN każdej pozycji karty). Wszystko
     * dosłownie ze sklepu. Normy z API (atrybut „Normy”) mają to samo brzmienie co wiersz strony — dopisujemy je
     * tylko, gdy strona ich nie podała.
     *
     * @return list<B2bRemoteShopField>
     */
    public function shopFields(B2bRemoteProduct $product): array
    {
        $fields = [];
        $seen = [];
        $add = static function (string $section, string $name, string $value) use (&$fields, &$seen): void {
            $key = mb_strtolower($name).'|'.$value;
            if ($name === '' || $value === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $fields[] = new B2bRemoteShopField($section, $name, $value);
        };

        $xpath = $this->pageXPath($product);
        if ($xpath !== null) {
            foreach (self::pageParameters($xpath) as [$name, $value]) {
                $add(self::SHOP_SECTION, $name, $value);
            }
        }
        foreach ((array) ($product->raw['attributes'] ?? []) as [$name, $value]) {
            $add(self::SHOP_SECTION, $name, $value);
        }

        $add(self::TRADE_SECTION, 'Marka', self::text($product->raw['brand'] ?? null));
        $add(self::TRADE_SECTION, 'Kategoria', implode(', ', (array) ($product->raw['categories'] ?? [])));
        $add(self::TRADE_SECTION, 'Jednostka', self::text($product->raw['unit'] ?? null));
        foreach ((array) ($product->raw['items'] ?? []) as $item) {
            $add(self::TRADE_SECTION, 'Kod towaru', $item['sku']);
            if ($item['ean'] !== '') {
                $add(self::TRADE_SECTION, 'EAN', $item['ean'].' ('.$item['sku'].')');
            }
        }

        return $fields;
    }

    /**
     * Pliki z sekcji „Załączniki” strony produktu. Kolejność: najpierw polska karta katalogowa, potem instrukcja,
     * angielska karta produktu, deklaracja, reszta — synchronizacja czyta tekst pierwszej karty technicznej
     * albo instrukcji (B2bCatalogSync::DESCRIPTION_SOURCE_KINDS), a polska karta mówi o wyrobie najpełniej.
     */
    public function documents(B2bRemoteProduct $product): array
    {
        $xpath = $this->pageXPath($product);
        if ($xpath === null) {
            return [];
        }

        $documents = [];
        foreach ($xpath->query('//*['.self::classPredicate('pliki-do-produktu').']//a[@href]') ?: [] as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }
            $url = self::absoluteUrl($link->getAttribute('href'));
            $title = self::inline($link->textContent);
            if ($url === null || isset($documents[$url])) {
                continue;
            }
            $file = mb_strtolower(basename((string) parse_url($url, PHP_URL_PATH)));
            [$rank, $kind] = self::documentKind($file);
            $documents[$url] = [
                'rank' => $rank,
                'document' => new B2bRemoteDocument($title !== '' ? $title : $file, $url, $kind),
            ];
        }
        // sortowanie stabilne: w obrębie rodzaju zostaje kolejność ze strony
        $list = array_values($documents);
        usort($list, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        return array_map(static fn (array $row): B2bRemoteDocument => $row['document'], $list);
    }

    public function documentBytes(B2bRemoteDocument $document): array
    {
        if (! TegroB2bClient::isOwnUrl($document->sourceUrl)) {
            throw new RuntimeException('plik spoza '.TegroB2bClient::HOST.': '.$document->sourceUrl);
        }

        return $this->client->fileBytes($document->sourceUrl);
    }

    /** Zdjęcie z listy API w pełnym rozmiarze — adres bez parametru miniatury (?preset=…). */
    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        $url = self::text($product->raw['photo'] ?? null);
        if ($url === '') {
            return null;
        }
        $file = $this->client->fileBytes($url);
        if (! str_starts_with($file['mime'], 'image/') || $file['bytes'] === '') {
            return null;
        }

        return new B2bRemoteImage(bytes: $file['bytes'], mime: $file['mime'], sourceUrl: $url);
    }

    /**
     * Pozycje listy w karty: model + cena konta + jednostka. Karta w kolejności pierwszej pozycji na liście,
     * pozycje karty wg rozmiaru.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<list<array{item: array<string, mixed>, size: string|null}>>
     */
    public static function group(array $items): array
    {
        $groups = [];
        foreach ($items as $index => $item) {
            $model = self::text($item['Model'] ?? null);
            $size = self::sizeOf(self::text($item['Name'] ?? null), $model);
            $price = self::money($item['PriceAfterDiscountNet'] ?? null);
            if ($size === null || $price === null || $price['value'] <= 0) {
                $groups['#'.$index] = [['item' => $item, 'size' => null]];

                continue;
            }
            $key = mb_strtolower($model).'|'.number_format($price['value'], 2, '.', '').'|'.$price['currency']
                .'|'.mb_strtolower(self::text($item['Unit'] ?? null));
            $groups[$key][] = ['item' => $item, 'size' => $size];
        }

        $out = [];
        foreach ($groups as $group) {
            usort($group, static fn (array $a, array $b): int => UvexSizeGroups::compareSizes((string) $a['size'], (string) $b['size']));
            $out[] = $group;
        }

        return $out;
    }

    /** Rozmiar = reszta nazwy po modelu, gdy nazwa to dokładnie „Model rozmiar”; null = pozycja bez modelu. */
    private static function sizeOf(string $name, string $model): ?string
    {
        if ($model === '' || ! str_starts_with($name, $model.' ')) {
            return null;
        }
        $size = trim(mb_substr($name, mb_strlen($model)));

        return $size !== '' && ! str_contains($size, ' ') ? $size : null;
    }

    /**
     * @param  list<array{item: array<string, mixed>, size: string|null}>  $group
     * @param  array<string, string>  $urls  id produktu → adres strony
     */
    private function productFor(array $group, array $urls): B2bRemoteProduct
    {
        $first = $group[0]['item'];
        $id = self::text($first['Id'] ?? null);
        $grouped = count($group) > 1;

        $items = array_map(static fn (array $m): array => [
            'id' => self::text($m['item']['Id'] ?? null),
            'sku' => self::text($m['item']['Sku'] ?? null),
            'name' => self::text($m['item']['Name'] ?? null),
            'ean' => self::text($m['item']['Ean'] ?? null),
            'size' => $m['size'],
        ], $group);

        $attributes = [];
        foreach ((array) ($first['Attributes'] ?? []) as $attribute) {
            $name = self::text(is_array($attribute) ? ($attribute['Name'] ?? null) : null);
            $values = array_values(array_filter(array_map(
                static fn (mixed $feature): string => self::text(is_array($feature) ? ($feature['Name'] ?? null) : null),
                is_array($attribute) ? (array) ($attribute['Features'] ?? []) : [],
            )));
            if ($name !== '' && $values !== []) {
                $attributes[] = [$name, implode(', ', $values)];
            }
        }
        $categories = array_values(array_filter(array_map(
            static fn (mixed $category): string => self::text(is_array($category) ? ($category['Name'] ?? null) : null),
            (array) ($first['Categories'] ?? []),
        )));
        $photo = self::text($first['Photo'] ?? null);
        $photo = $photo !== '' ? self::absoluteUrl((string) strtok($photo, '?')) : null;
        $url = $urls[$id] ?? null;

        return new B2bRemoteProduct(
            remoteId: $id,
            sku: $items[0]['sku'],
            name: $grouped ? self::text($first['Model'] ?? null) : $items[0]['name'],
            category: $categories[0] ?? null,
            sourceUrl: $url,
            raw: [
                'brand' => self::text($first['Brand'] ?? null),
                'description' => self::text($first['Description'] ?? null),
                'unit' => self::text($first['Unit'] ?? null),
                'price' => $first['PriceAfterDiscountNet'] ?? null,
                'retail_price' => $first['RetailPriceNet'] ?? null,
                'photo' => $photo,
                'categories' => $categories,
                'attributes' => $attributes,
                'items' => $items,
                'page_url' => $url,
            ],
            variantSummary: $grouped
                ? 'Rozmiary: '.implode('; ', array_map(static fn (array $i): string => $i['size'].' ('.$i['sku'].')', $items))
                : null,
            members: $grouped
                ? array_map(static fn (array $i): array => ['remote_id' => $i['id'], 'sku' => $i['sku'], 'name' => $i['name']], $items)
                : [],
        );
    }

    /** Strona produktu tej karty; tabelka i pliki czytają tę samą stronę — pobieramy ją raz na kartę. */
    private function pageXPath(B2bRemoteProduct $product): ?DOMXPath
    {
        $url = self::text($product->raw['page_url'] ?? null);
        if ($url === '' || ! TegroB2bClient::isOwnUrl($url)) {
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

    /**
     * Wiersze pól produktu ze strony: „etykieta | wartość” w dwóch kolumnach albo jedna wartość z etykietą
     * w treści („Normy:EN ISO 21420:2020;EN 388:2016+A1:2018”). Sekcja „Opis” to opis — nie wiersz tabelki.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function pageParameters(DOMXPath $xpath): array
    {
        $rows = [];
        foreach ($xpath->query('//*['.self::classPredicate('kontrolka-PoleProduktu').']') ?: [] as $control) {
            $heading = self::inline((string) $xpath->query('.//h4', $control)?->item(0)?->textContent);
            if (mb_strtolower($heading) === 'opis') {
                continue;
            }
            $pairs = $xpath->query('.//*['.self::classPredicate('row').']', $control) ?: [];
            foreach ($pairs as $row) {
                $cells = $xpath->query('./*['.self::classPredicate('col-6').']', $row);
                if ($cells === false || $cells->length !== 2) {
                    continue;
                }
                $rows[] = [self::inline((string) $cells->item(0)?->textContent), self::inline((string) $cells->item(1)?->textContent)];
            }
            if ($pairs->length > 0) {
                continue;
            }
            // pole z samą wartością: etykieta przed pierwszym dwukropkiem, reszta dosłownie (bez obrazków marki)
            $value = self::inline((string) $xpath->query('.//*['.self::classPredicate('wartosc').']', $control)?->item(0)?->textContent);
            if (preg_match('/^([^:]{1,40}):\s*(.+)$/u', $value, $m) === 1) {
                $rows[] = [trim($m[1]), trim($m[2])];
            }
        }

        return array_values(array_filter($rows, static fn (array $r): bool => $r[0] !== '' && $r[1] !== ''));
    }

    /**
     * Rodzaj pliku po nazwie ze strony (sprawdzone na wszystkich modelach 21.09.2026: karta katalogowa PL,
     * „product card” EN, deklaracja, instrukcja; pojedynczo „karta produktu” i plik bez opisu w nazwie).
     *
     * @return array{0: int, 1: string} pozycja w kolejności i ProductDocument::KIND_*
     */
    private static function documentKind(string $file): array
    {
        return match (true) {
            str_contains($file, 'karta-katalogowa'), str_contains($file, 'karta-produktu') => [0, ProductDocument::KIND_DATASHEET],
            str_contains($file, 'instrukcj') => [1, ProductDocument::KIND_MANUAL],
            str_contains($file, 'product-card') => [2, ProductDocument::KIND_DATASHEET],
            str_contains($file, 'deklaracj') => [3, ProductDocument::KIND_CERTIFICATE],
            default => [4, ProductDocument::KIND_OTHER],
        };
    }

    private static function absoluteUrl(string $href): ?string
    {
        $href = trim($href);
        if ($href === '') {
            return null;
        }
        $url = str_starts_with($href, '/') && ! str_starts_with($href, '//') ? TegroB2bClient::BASE.$href : $href;

        return TegroB2bClient::isOwnUrl($url) ? $url : null;
    }

    /**
     * @return array{value: float, currency: string}|null
     */
    private static function money(mixed $value): ?array
    {
        if (! is_array($value) || ! is_numeric($value['Value'] ?? null)) {
            return null;
        }

        return ['value' => round((float) $value['Value'], 2), 'currency' => strtoupper(self::text($value['Currency'] ?? null))];
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
