<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use RuntimeException;

/**
 * web.rawpol.com — hurtownia RAW-POL: własne marki (REIS, L.HOLLMAN, OGRIFOX…) i wyroby innych producentów
 * (3M, Honeywell, ANRO, UVEX…). Lista wyrobów i ich wersje z publicznego pliku danych katalogu, ceny konta z API,
 * treść (opis, normy, pliki) z pliku treści wyrobu (RawpolB2bClient).
 *
 * Wyrób ma wersje (kolor × rozmiar), każda z własnym symbolem, EAN i ceną. Na liście są też formy handlowe tej samej
 * wersji: „…_Promocyjna” to czasowa oferta promocyjna (pomijamy — cena promocji nie jest ceną zakupu), „OLD” to
 * wyprzedawana stara wersja pod tym samym symbolem (bierzemy tylko, gdy symbol nie ma formy podstawowej).
 * Wersje z ujemnym numerem to wyroby tylko prezentowane, bez sprzedaży (21.09.2026: 1698 pozycji Honeywell) — pomijamy.
 *
 * Karta = wyrób w jednej cenie konta (decyzja użytkownika 15.09.2026: rozmiar w innej cenie = osobna karta, jak
 * u Ardona). SKU karty: kod wyrobu RAW-POL wielkimi literami („3M-MAS-6000” — tak serwis go pokazuje); przy kilku
 * cenach kod dostaje największa grupa (remis: pierwsza na liście), pozostałe — symbol swojej pierwszej wersji.
 * Pozycja karty (remote_id) = symbol wersji („RNITRIOS”) — stały i widoczny w serwisie.
 *
 * Cena konta = „cenaCennikowa” (cena w grupie cenowej konta — ta, którą serwis pokazuje jako cenę netto),
 * cena katalogowa = „cenaBazowa” (cennik bazowy A), gdy serwis ją podaje. Sprawdzone na koncie 21.09.2026:
 * 3M-MAS-6000 — cena netto 68,06 (cenaCennikowa), katalogowa 97,16, promocja 61,25 do 30.09 (pomijana).
 * Cena dotyczy jednostki sprzedaży wersji („szt.”, „para”, „opak.”) — trafia do tabelki sklepu na karcie.
 *
 * Producent: marka z danych wyrobu (słownik marek serwisu); pisownię marek, które katalog już ma, bierzemy
 * z katalogu (BRANDS). RAW-POL nie jest producentem tych marek, więc łącznik nie jest B2bManufacturerSite.
 */
final class RawpolB2bConnector implements B2bConnector, B2bDocumentSource, B2bRunSummaryAware, B2bShopFieldSource
{
    private const SHOP_SECTION = 'Dane wyrobu';

    private const TRADE_SECTION = 'Informacje handlowe';

    /**
     * Klucz marki w serwisie → pisownia marki na istniejących kartach katalogu (21.09.2026). B2bCatalogSync porównuje
     * producenta bez wielkości liter, ale nowa karta dostałaby „HONEYWELL” obok „Honeywell”. Reszta — nazwa ze słownika
     * marek serwisu, dosłownie.
     *
     * @var array<string, string>
     */
    private const BRANDS = [
        '3m' => '3M',
        'anro' => 'Anro',
        'honeywell' => 'Honeywell',
        'uvex' => 'UVEX',
        'jsp' => 'JSP',
        'ansell' => 'Ansell',
        'coba' => 'Coba',
        'secura' => 'SECURA',
        'showa' => 'SHOWA',
        'weldas' => 'Weldas',
        'fagum-stomil' => 'Fagum-Stomil',
        'reis' => 'Reis',
    ];

    /** Pozycje pliku core_list. */
    private const CORE_ENCODED_ID = 0;

    private const CORE_ID = 1;

    private const CORE_BRAND = 2;

    private const CORE_LINE = 3;

    private const CORE_NAME = 4;

    private const CORE_IMAGE = 6;

    private const CORE_COLORS = 8;

    /** Pozycje grupy koloru i wersji w core_list. */
    private const COLOR_CODE = 0;

    private const COLOR_VERSIONS = 5;

    private const VERSION_REF = 0;

    private const VERSION_FORM = 1;

    private const VERSION_SIZE = 3;

    private const VERSION_EAN = 4;

    private const VERSION_SYMBOL = 7;

    /** Pozycje pliku treści wyrobu (info_pl). */
    private const INFO_DESCRIPTION = 1;

    private const INFO_CATEGORY = 2;

    private const INFO_BRANCHES = 24;

    private const INFO_NORMS = 25;

    private const INFO_GROUP = 28;

    private const INFO_COLORS = 33;

    /** Pozycje wersji w pliku treści wyrobu. */
    private const INFO_VERSION_REF = 0;

    private const INFO_VERSION_DOCUMENTS = 32;

    private const INFO_VERSION_CLASSIFICATION = 44;

    private const INFO_VERSION_CE = 55;

    private int $total = 0;

    /** @var list<string> */
    private array $summary = [];

    /** @var array<string, array<mixed>> słowniki serwisu wg nazwy pliku */
    private array $dictionaries = [];

    /** @var array<string, string> */
    private array $messages = [];

    /** @var list<string> wyroby bez żadnej wersji z ceną konta */
    private array $withoutPrice = [];

    /** @var list<string> wyroby, których treści nie udało się pobrać */
    private array $withoutInfo = [];

    public function __construct(private readonly RawpolB2bClient $client) {}

    public static function key(): string
    {
        return 'rawpol';
    }

    public static function label(): string
    {
        return 'Raw-Pol';
    }

    public static function host(): string
    {
        return RawpolB2bClient::HOST;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new RawpolB2bClient($account->username, (string) $account->password, $delayMs));
    }

    public function login(): void
    {
        $this->client->login();
    }

    public function products(): iterable
    {
        $this->withoutPrice = [];
        $this->withoutInfo = [];
        $this->dictionaries = [];
        foreach (['def_marki_pl', 'def_linie_pl', 'def_kolory_pl', 'def_kategorie_ce_pl', 'def_normybazowe_pl'] as $name) {
            $this->dictionaries[$name] = $this->client->dataFile($name);
        }
        $this->messages = $this->client->serverMessages();

        $listed = self::listedProducts($this->client->dataFile('def_produkty_core_list_pl'));
        $products = $listed['products'];
        $this->total = count($products);
        $this->summary = ['Katalog Raw-Pol: '.$listed['all'].' wyrobów, w sprzedaży '.count($products)
            .' (wersji: '.array_sum(array_map(static fn (array $p): int => count($p['versions']), $products)).')'];
        $cards = 0;
        $split = 0;

        foreach (self::batches($products) as $batch) {
            $dynamic = $this->client->dynamicData(array_column($batch, 'id'));
            foreach ($batch as $product) {
                $groups = self::priceGroups($product, is_array($dynamic[$product['id']] ?? null) ? $dynamic[$product['id']] : []);
                if ($groups === []) {
                    $this->withoutPrice[] = mb_strtoupper($product['id']);

                    continue;
                }
                $info = $this->info($product);
                $cards += count($groups);
                $split += count($groups) > 1 ? 1 : 0;
                // wyrób w kilku cenach to kilka kart — licznik postępu rośnie, zanim je wydamy
                $this->total += count($groups) - 1;
                foreach ($groups as $group) {
                    yield $this->productFor($product, $group, $info);
                }
            }
        }
        $this->summary[] = 'Karty: '.$cards.' ('.$split.' wyrobów w kilku cenach — rozmiar w innej cenie to osobna karta)';
    }

    /**
     * Liczba kart: na początku liczba wyrobów w sprzedaży, powiększana o każdą dodatkową kartę wyrobu w kilku cenach
     * (ceny znamy dopiero porcjami). Po pełnym przebiegu = liczba wydanych kart.
     */
    public function totalProducts(): int
    {
        return $this->total;
    }

    public function runSummary(): array
    {
        $lines = $this->summary;
        if ($this->withoutPrice !== []) {
            $lines[] = 'Bez ceny konta: '.count($this->withoutPrice).' wyrobów (pominięte), np. '
                .implode(', ', array_slice($this->withoutPrice, 0, 10));
        }
        if ($this->withoutInfo !== []) {
            $lines[] = 'Bez treści wyrobu (opis, normy, pliki): '.count($this->withoutInfo).' kart — zapisane z ceną, np. '
                .implode(', ', array_slice($this->withoutInfo, 0, 10));
        }

        return $lines;
    }

    /**
     * Marka z danych wyrobu. Wyrób bez marki jest pomijany z powodem: synchronizacja wpisuje producenta na kartę,
     * a „Raw-Pol” w zastępstwie przypisałby hurtowni cudzy wyrób.
     */
    public function manufacturer(B2bRemoteProduct $product): string
    {
        $brand = self::text($product->raw['brand'] ?? null);
        if ($brand === '') {
            throw new RuntimeException('serwis nie podaje marki wyrobu — pozycja pominięta');
        }

        return $brand;
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        $net = $product->raw['price'] ?? null;
        if (! is_float($net) || $net <= 0) {
            return null;
        }
        $base = $product->raw['base_price'] ?? null;
        $base = is_float($base) && $base > 0 ? round($base, 2) : null;

        return new B2bRemotePrice(
            net: round($net, 2),
            base: $base,
            discountPercent: $base !== null && $base > $net ? round((1 - $net / $base) * 100, 2) : 0.0,
        );
    }

    /** Opis wyrobu z pliku treści, dosłownie (serwis dzieli linie znakiem CR). */
    public function description(B2bRemoteProduct $product): string
    {
        return mb_substr(self::lines(self::text($product->raw['description'] ?? null)), 0, 10000);
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        $path = self::text($product->raw['image'] ?? null);
        if ($path === '') {
            return null;
        }
        $url = RawpolB2bClient::mediaUrl($path);
        $file = $this->client->fileBytes($url);
        if (! str_starts_with($file['mime'], 'image/') || $file['bytes'] === '') {
            return null;
        }

        return new B2bRemoteImage(bytes: $file['bytes'], mime: $file['mime'], sourceUrl: $url);
    }

    /**
     * Pliki wersji karty (deklaracje zgodności, karty charakterystyki, broszury informacyjne). Każdy rozmiar ma
     * własny egzemplarz deklaracji pod tą samą nazwą — bierzemy pierwszy plik danej nazwy. Plik angielski tylko
     * wtedy, gdy nie ma polskiego tego rodzaju; rumuńskich nie bierzemy.
     */
    public function documents(B2bRemoteProduct $product): array
    {
        $files = [];
        foreach ((array) ($product->raw['documents'] ?? []) as [$title, $path]) {
            if (! isset($files[$title])) {
                $files[$title] = ['title' => $title, 'url' => RawpolB2bClient::mediaUrl($path), ...self::documentType($title)];
            }
        }
        $polish = [];
        foreach ($files as $file) {
            if ($file['language'] === 'pl') {
                $polish[$file['kind']] = true;
            }
        }

        $documents = [];
        foreach ($files as $file) {
            if ($file['language'] === 'pl' || ($file['language'] === 'en' && ! isset($polish[$file['kind']]))) {
                $documents[] = new B2bRemoteDocument($file['title'], $file['url'], $file['kind']);
            }
        }

        return $documents;
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
     * Dane wyrobu ze słowników serwisu (marka, linia, kategoria, normy, kategoria ochrony, branże) i dane handlowe
     * wersji (symbol, EAN, jednostka, której dotyczy cena). Wszystko dosłownie ze źródła; dostępność idzie do slotu
     * ceny konta (B2bRemoteProduct::$availability), nie do tabelki.
     *
     * @return list<B2bRemoteShopField>
     */
    public function shopFields(B2bRemoteProduct $product): array
    {
        $fields = [];
        $seen = [];
        $add = static function (string $section, string $name, string $value) use (&$fields, &$seen): void {
            $key = $section.'|'.mb_strtolower($name).'|'.$value;
            if ($name === '' || $value === '' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $fields[] = new B2bRemoteShopField($section, $name, $value);
        };

        $raw = $product->raw;
        $add(self::SHOP_SECTION, 'Marka', self::text($raw['brand_label'] ?? null));
        $add(self::SHOP_SECTION, 'Linia', self::text($raw['line'] ?? null));
        $add(self::SHOP_SECTION, 'Grupa', self::text($raw['group'] ?? null));
        $add(self::SHOP_SECTION, 'Kategoria', self::text($raw['category'] ?? null));
        foreach ((array) ($raw['norms'] ?? []) as $norm) {
            $add(self::SHOP_SECTION, 'Norma', (string) $norm);
        }
        foreach ((array) ($raw['ce'] ?? []) as $ce) {
            $add(self::SHOP_SECTION, 'Kategoria ochrony', (string) $ce);
        }
        $add(self::SHOP_SECTION, 'Branże', implode('; ', (array) ($raw['branches'] ?? [])));
        foreach ((array) ($raw['versions'] ?? []) as $version) {
            $code = $version['symbol'].($version['ean'] !== '' ? ' (EAN '.$version['ean'].')' : '');
            $add(self::TRADE_SECTION, 'Kod towaru', $code);
        }
        foreach ((array) ($raw['versions'] ?? []) as $version) {
            $add(self::TRADE_SECTION, 'Cena za', $version['unit']);
        }

        return $fields;
    }

    /**
     * Wyroby z wersjami w sprzedaży: bez wersji prezentowanych (numer ≤ 0) i bez form promocyjnych; jeden wpis na
     * symbol — forma podstawowa przed innymi (np. „OLD” pod tym samym symbolem).
     *
     * @param  array<mixed>  $core
     * @return array{all: int, products: list<array{id: string, encoded: string, brand: string, line: string, name: string, image: string, versions: list<array{ref: int, symbol: string, size: string, color: string, ean: string}>}>}
     */
    public static function listedProducts(array $core): array
    {
        $products = [];
        foreach ($core as $row) {
            if (! is_array($row) || ! is_string($row[self::CORE_ID] ?? null)) {
                continue;
            }
            $versions = [];
            foreach (is_array($row[self::CORE_COLORS] ?? null) ? $row[self::CORE_COLORS] : [] as $color) {
                foreach (is_array($color[self::COLOR_VERSIONS] ?? null) ? $color[self::COLOR_VERSIONS] : [] as $version) {
                    $ref = $version[self::VERSION_REF] ?? null;
                    $symbol = self::text($version[self::VERSION_SYMBOL] ?? null);
                    $form = self::text($version[self::VERSION_FORM] ?? null);
                    if (! is_int($ref) || $ref <= 0 || $symbol === '' || str_contains(mb_strtolower($form), 'promocyjna')) {
                        continue;
                    }
                    $basic = $form === '' || $form === 'Podstawowa';
                    if (isset($versions[$symbol]) && ($versions[$symbol]['basic'] || ! $basic)) {
                        continue;
                    }
                    $versions[$symbol] = [
                        'ref' => $ref,
                        'symbol' => $symbol,
                        'size' => self::text($version[self::VERSION_SIZE] ?? null),
                        'color' => self::text($color[self::COLOR_CODE] ?? null),
                        'ean' => self::text($version[self::VERSION_EAN] ?? null),
                        'basic' => $basic,
                    ];
                }
            }
            if ($versions === []) {
                continue;
            }
            $products[] = [
                'id' => $row[self::CORE_ID],
                'encoded' => self::text($row[self::CORE_ENCODED_ID] ?? null),
                'brand' => self::text($row[self::CORE_BRAND] ?? null),
                'line' => self::text($row[self::CORE_LINE] ?? null),
                'name' => self::text($row[self::CORE_NAME] ?? null),
                'image' => self::text($row[self::CORE_IMAGE] ?? null),
                'versions' => array_map(static function (array $v): array {
                    unset($v['basic']);

                    return $v;
                }, array_values($versions)),
            ];
        }

        return ['all' => count($core), 'products' => $products];
    }

    /**
     * Wersje wyrobu z ceną konta w karty: jedna karta na cenę. SKU — patrz opis klasy.
     *
     * @param  array{id: string, versions: list<array{ref: int, symbol: string, size: string, color: string, ean: string}>}  $product
     * @param  array<string, mixed>  $dynamic  dane zmienne wyrobu z API (wersje wg numeru)
     * @return list<array{sku: string, versions: list<array<string, mixed>>}>
     */
    public static function priceGroups(array $product, array $dynamic): array
    {
        $remote = is_array($dynamic['wersje'] ?? null) ? $dynamic['wersje'] : [];
        $groups = [];
        foreach ($product['versions'] as $version) {
            $data = $remote[(string) $version['ref']] ?? null;
            $net = is_array($data) ? self::number($data['cenaCennikowa'] ?? null) : null;
            if ($net === null || $net <= 0) {
                continue;
            }
            $groups[sprintf('%.4F', $net)][] = [
                ...$version,
                'price' => $net,
                'base_price' => self::number($data['cenaBazowa'] ?? null),
                'unit' => self::text($data['jednostka'] ?? null),
                'availability_key' => self::text($data['dostepnoscInfo'] ?? null),
                'availability_params' => array_values(array_filter((array) ($data['dostepnoscInfoParams'] ?? []), 'is_scalar')),
            ];
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

        $cards = [];
        foreach ($groups as $key => $versions) {
            $cards[] = [
                'sku' => $key === $main ? mb_strtoupper($product['id']) : $versions[0]['symbol'],
                'versions' => $versions,
            ];
        }

        return $cards;
    }

    /**
     * Wyroby w porcjach do zapytania o ceny — do RawpolB2bClient::PRICE_BATCH_VERSIONS wersji, ale zawsze co najmniej
     * jeden wyrób (wyrób z większą liczbą wersji idzie sam).
     *
     * @param  list<array{id: string, versions: list<mixed>}>  $products
     * @return iterable<list<array<string, mixed>>>
     */
    private static function batches(array $products): iterable
    {
        $batch = [];
        $count = 0;
        foreach ($products as $product) {
            $versions = count($product['versions']);
            if ($batch !== [] && $count + $versions > RawpolB2bClient::PRICE_BATCH_VERSIONS) {
                yield $batch;
                $batch = [];
                $count = 0;
            }
            $batch[] = $product;
            $count += $versions;
        }
        if ($batch !== []) {
            yield $batch;
        }
    }

    /**
     * Wyciąg z pliku treści wyrobu. Błąd pobrania nie przerywa przebiegu: karta dostaje cenę bez opisu, a kod trafia
     * do podsumowania.
     *
     * @param  array{id: string, encoded: string}  $product
     * @return array{description: string, category: string, group: string, branches: list<string>, norms: list<string>, versions: array<int, array{documents: list<array{0: string, 1: string}>, norms: list<string>, ce: string}>}|null
     */
    private function info(array $product): ?array
    {
        try {
            $info = $product['encoded'] !== '' ? $this->client->productInfo($product['encoded']) : null;
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException) {
            $info = null;
        }
        if ($info === null) {
            $this->withoutInfo[] = mb_strtoupper($product['id']);

            return null;
        }

        return $this->parseInfo($info);
    }

    /**
     * @param  array<mixed>  $info
     * @return array{description: string, category: string, group: string, branches: list<string>, norms: list<string>, versions: array<int, array{documents: list<array{0: string, 1: string}>, norms: list<string>, ce: string}>}
     */
    private function parseInfo(array $info): array
    {
        $versions = [];
        foreach (is_array($info[self::INFO_COLORS] ?? null) ? $info[self::INFO_COLORS] : [] as $color) {
            foreach (is_array($color[0] ?? null) ? $color[0] : [] as $version) {
                $ref = $version[self::INFO_VERSION_REF] ?? null;
                if (! is_int($ref)) {
                    continue;
                }
                $documents = [];
                foreach (is_array($version[self::INFO_VERSION_DOCUMENTS] ?? null) ? $version[self::INFO_VERSION_DOCUMENTS] : [] as $document) {
                    $path = self::text($document[1] ?? null);
                    $title = self::inline(self::text($document[3] ?? null));
                    if ($path !== '' && $title !== '') {
                        $documents[] = [$title, $path];
                    }
                }
                $versions[$ref] = [
                    'documents' => $documents,
                    'norms' => self::versionNorms(is_array($version[self::INFO_VERSION_CLASSIFICATION] ?? null) ? $version[self::INFO_VERSION_CLASSIFICATION] : []),
                    'ce' => $this->ceLabel(self::text($version[self::INFO_VERSION_CE] ?? null)),
                ];
            }
        }

        return [
            'description' => self::text($info[self::INFO_DESCRIPTION] ?? null),
            'category' => self::inline(self::text($info[self::INFO_CATEGORY] ?? null)),
            'group' => self::inline(self::text($info[self::INFO_GROUP] ?? null)),
            'branches' => array_values(array_filter(array_map(
                static fn (mixed $b): string => self::inline(self::text($b)),
                is_array($info[self::INFO_BRANCHES] ?? null) ? $info[self::INFO_BRANCHES] : [],
            ))),
            'norms' => $this->productNorms(is_array($info[self::INFO_NORMS] ?? null) ? $info[self::INFO_NORMS] : []),
            'versions' => $versions,
        ];
    }

    /**
     * Normy wersji z jej klasyfikacji w serwisie: „NORMA1”, „NORMA2”… z oznaczeniem dosłownie („EN ISO 20471*”),
     * poziomy z „NORMAn_PARAMETRY” („Typ 6”) i dopisek serwisu („dotyczy materiału lub pasów”). To te same wiersze,
     * które serwis pokazuje w sekcji „Normy” strony wyrobu.
     *
     * @param  array<mixed>  $classification
     * @return list<string>
     */
    private static function versionNorms(array $classification): array
    {
        $norms = [];
        foreach ($classification as $key => $entry) {
            if (! is_string($key) || preg_match('/^NORMA(\d+)$/', $key, $m) !== 1 || ! is_array($entry)) {
                continue;
            }
            $label = self::inline(self::text($entry[1] ?? null));
            if ($label === '') {
                continue;
            }
            $parameters = self::inline(self::text($classification[$key.'_PARAMETRY'][1] ?? null));
            $note = self::inline(self::text($entry[2] ?? null));
            $norms[(int) $m[1]] = $label.($parameters !== '' ? ' '.$parameters : '').($note !== '' ? ' — '.$note : '');
        }
        ksort($norms);

        return array_values($norms);
    }

    /**
     * Normy z listy wyrobu — tylko na wypadek, gdy żadna wersja karty nie ma normy w klasyfikacji. Oznaczenie ze
     * słownika norm bazowych serwisu, dosłownie (tam bez spacji: „EN388”, „ENISO21420”), z poziomami („parametry”).
     *
     * @param  list<mixed>  $norms
     * @return list<string>
     */
    private function productNorms(array $norms): array
    {
        $labels = [];
        foreach ($norms as $norm) {
            if (! is_array($norm)) {
                continue;
            }
            $label = self::text($this->dictionaries['def_normybazowe_pl'][self::text($norm['normaBazowaIdRef'] ?? null)][1] ?? null);
            if ($label === '') {
                continue;
            }
            $parameters = self::inline(self::text($norm['parametry'] ?? null));
            $labels[] = $label.($parameters !== '' ? ' '.$parameters : '');
        }

        return array_values(array_unique($labels));
    }

    /** Kategoria ŚOI ze słownika serwisu („Kategoria I”); zamiast „-” — objaśnienie („Produkt nie jest środkiem…”). */
    private function ceLabel(string $key): string
    {
        $entry = $key !== '' ? ($this->dictionaries['def_kategorie_ce_pl'][$key] ?? null) : null;
        $label = self::text($entry[1] ?? null);

        return $label === '-' ? self::text($entry[2] ?? null) : $label;
    }

    /**
     * @param  array{id: string, brand: string, line: string, name: string, image: string}  $product
     * @param  array{sku: string, versions: list<array<string, mixed>>}  $group
     * @param  array{description: string, category: string, group: string, branches: list<string>, norms: list<string>, versions: array<int, array{documents: list<array{0: string, 1: string}>, norms: list<string>, ce: string}>}|null  $info
     */
    private function productFor(array $product, array $group, ?array $info): B2bRemoteProduct
    {
        $versions = $group['versions'];
        $first = $versions[0];
        $brandKey = trim(explode('|', $product['brand'])[0]);
        $brandLabel = $brandKey !== '' ? self::text($this->dictionaries['def_marki_pl'][$brandKey][1] ?? null) : '';
        $brand = $brandKey === '' ? '' : (self::BRANDS[$brandKey] ?? ($brandLabel !== '' ? $brandLabel : $brandKey));

        $documents = [];
        $norms = [];
        $ce = [];
        $items = [];
        foreach ($versions as $version) {
            foreach ($info['versions'][$version['ref']]['documents'] ?? [] as $document) {
                $documents[] = $document;
            }
            foreach ($info['versions'][$version['ref']]['norms'] ?? [] as $norm) {
                $norms[$norm] = $norm;
            }
            $ceLabel = $info['versions'][$version['ref']]['ce'] ?? '';
            if ($ceLabel !== '') {
                $ce[$ceLabel] = $ceLabel;
            }
            $items[] = [
                'symbol' => $version['symbol'],
                'ean' => $version['ean'],
                'unit' => $version['unit'],
                'label' => $this->versionLabel($version),
                'availability' => $this->availability($version),
            ];
        }

        $labelled = array_values(array_filter($items, static fn (array $item): bool => $item['label'] !== ''));

        return new B2bRemoteProduct(
            remoteId: $first['symbol'],
            sku: $group['sku'],
            name: $product['name'],
            category: ($info['category'] ?? '') !== '' ? $info['category'] : null,
            sourceUrl: RawpolB2bClient::productUrl($product['id']),
            raw: [
                'product_id' => $product['id'],
                'brand' => $brand,
                'brand_label' => $brandLabel,
                'line' => $this->lineLabel($product['line']),
                'price' => $first['price'],
                'base_price' => $first['base_price'],
                'image' => $product['image'],
                'description' => $info['description'] ?? '',
                'category' => $info['category'] ?? '',
                'group' => $info['group'] ?? '',
                'branches' => $info['branches'] ?? [],
                'norms' => $norms !== [] ? array_values($norms) : ($info['norms'] ?? []),
                'ce' => array_values($ce),
                'documents' => $documents,
                'versions' => $items,
            ],
            availability: self::availabilityText($items),
            variantSummary: $labelled !== []
                ? 'Wersje: '.implode('; ', array_map(static fn (array $item): string => $item['label'].' ('.$item['symbol'].')', $labelled))
                : null,
            members: count($versions) > 1
                ? array_map(static fn (array $item): array => [
                    'remote_id' => $item['symbol'],
                    'sku' => $item['symbol'],
                    'name' => trim($product['name'].' '.$item['label']),
                ], $items)
                : [],
            identifiers: self::identifiers($product['id'], $items),
        );
    }

    /**
     * Kod wyrobu RAW-POL (kod rodziny wersji, bez koloru i rozmiaru) dosłownie z listy wyrobów („3m-mas-6000” —
     * SKU karty to nasz zapis wielkimi literami) — na każdej karcie wyrobu, także na karcie wersji w innej cenie.
     * Symbol i EAN każdej wersji; pozycja = symbol (remote_id powiązania). RAW-POL jest hurtownią, więc symbol to
     * jej własny kod, nie kod producenta.
     *
     * @param  list<array{symbol: string, ean: string, label: string}>  $items
     * @return list<B2bRemoteIdentifier>
     */
    private static function identifiers(string $productId, array $items): array
    {
        $out = [];
        $productId = self::text($productId);
        if ($productId !== '') {
            $out[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_MODEL_CODE, value: $productId, field: 'Kod wyrobu');
        }
        foreach ($items as $item) {
            $label = $item['label'] !== '' ? $item['label'] : null;
            $out[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_SOURCE_CODE, value: $item['symbol'], remoteId: $item['symbol'], label: $label, field: 'Symbol');
            if ($item['ean'] !== '') {
                $out[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_EAN, value: $item['ean'], remoteId: $item['symbol'], label: $label, field: 'EAN');
            }
        }

        return $out;
    }

    /** Nazwa linii wyrobów ze słownika serwisu („TACTICAL GUARD”); klucz dosłownie, gdy słownik go nie zna. */
    private function lineLabel(string $key): string
    {
        if ($key === '') {
            return '';
        }
        $label = self::text($this->dictionaries['def_linie_pl'][$key][1] ?? null);

        return $label !== '' ? $label : $key;
    }

    /** Kolor (nazwa ze słownika serwisu) i rozmiar wersji, dosłownie; '' gdy wersja nie ma żadnego. */
    private function versionLabel(array $version): string
    {
        // lista wyrobów podaje kod koloru wielkimi literami („BS”), słownik ma klucze małymi („bs” → „czarno-szary”)
        $color = $version['color'] !== '' ? self::text($this->dictionaries['def_kolory_pl'][mb_strtolower($version['color'])][1] ?? null) : '';

        return trim(($color !== '' ? $color : '').' '.$version['size']);
    }

    /** Komunikat dostępności serwisu po polsku, z parametrami („czas realizacji zamówienia <$0$> dni”). */
    private function availability(array $version): string
    {
        $text = $this->messages[$version['availability_key']] ?? '';
        foreach ($version['availability_params'] as $index => $value) {
            $text = str_replace('<$'.$index.'$>', (string) $value, $text);
        }

        return self::inline(strip_tags($text));
    }

    /**
     * Dostępność karty: jeden komunikat, gdy wszystkie wersje mają ten sam; inaczej komunikat każdej wersji
     * z symbolem. null = serwis nie podał żadnego.
     *
     * @param  list<array{symbol: string, availability: string}>  $items
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
            static fn (array $item): string => $item['symbol'].': '.($item['availability'] !== '' ? $item['availability'] : 'brak informacji'),
            $items,
        )), 0, 1000);
    }

    /**
     * Rodzaj i język pliku z jego nazwy w serwisie („Deklaracja zgodności - UE - PL”, „Deklaracja zgodności - EN”,
     * „Deklaracja RO”, „Karta charakterystyki/ostrzeżenia”, „Broszura informacyjna/ostrzeżenia - PL”, „Certyfikat”).
     * Bez oznaczenia języka — plik polski (serwis w wersji polskiej).
     *
     * @return array{language: string, kind: string}
     */
    private static function documentType(string $title): array
    {
        $name = mb_strtolower($title);
        $language = match (true) {
            preg_match('/\bro\b/u', $name) === 1 => 'ro',
            preg_match('/(^|[\s\-])en$/u', $name) === 1 => 'en',
            default => 'pl',
        };
        $kind = match (true) {
            str_contains($name, 'deklaracj') || str_contains($name, 'certyfikat') => ProductDocument::KIND_CERTIFICATE,
            str_contains($name, 'broszura') || str_contains($name, 'instrukcj') => ProductDocument::KIND_MANUAL,
            str_contains($name, 'karta techniczna') => ProductDocument::KIND_DATASHEET,
            default => ProductDocument::KIND_OTHER,
        };

        return ['language' => $language, 'kind' => $kind];
    }

    private static function number(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    /** Linie opisu: CR i CRLF serwisu na LF, bez białych znaków na końcach linii. */
    private static function lines(string $text): string
    {
        $lines = array_map('rtrim', preg_split('/\r\n|\r|\n/', $text) ?: []);

        return trim(implode("\n", $lines));
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
