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

/**
 * b2b.tegro.pl (SolEx B2B) — dystrybutor rękawic marek RS Arbeitsschutz, G-REX, TK Gloves i Safeticult.
 * Lista i ceny konta z API sklepu (TegroB2bClient::products), tabelka parametrów i pliki PDF ze strony produktu.
 *
 * Rozmiary jednego wyrobu są u Tegro osobnymi pozycjami („RĘKAWICE G-REX F09 PLUS 6” … „11”), a sklep sam
 * podaje, które należą do jednego wyrobu — pole Model („RĘKAWICE G-REX F09 PLUS”). Karta = jeden model w jednej
 * walucie i jednostce, ze wszystkimi rozmiarami z ceną (decyzja użytkownika 28.09.2026: rozmiary w różnych cenach to
 * jedna karta; u Tegro 21.09.2026 dotyczyło to HEAVY, ULTRA TEC i BASS). Rozmiar w innej jednostce albo walucie to
 * nadal osobna karta — sprzedaż na sztuki i na opakowania to nie rozmiary jednej karty. Do 28.09.2026 (decyzja
 * 15.09.2026) rozmiar w innej cenie był osobną kartą (kod i nazwa pozycji) — takie karty zostają, dopóki nie scali ich
 * osobne polecenie (synchronizacja daje każdej jej rozmiary, B2bCatalogSync::syncMembersByCard). Scalamy tylko wtedy,
 * gdy nazwa pozycji to dokładnie Model + spacja + rozmiar — inaczej pozycja zostaje osobną kartą, bez zgadywania.
 * Pozycje (members) = rozmiary z ceną konta (PriceAfterDiscountNet) i ceną katalogową (RetailPriceNet w tej samej
 * walucie) tego rozmiaru; cena karty = najniższa cena rozmiaru (raw['price'], price()), pozostałe — wiersze rozmiarów
 * karty.
 *
 * Kod i nazwa karty (decyzja właściciela 28.09.2026): kod karty = kod modelu bez rozmiaru. Sklep nie ma pola kodu
 * modelu, ale kod pozycji to u 108 ze 113 kart „kod modelu + spacja + rozmiar” („CITRIN 7” … „CITRIN 11” → „CITRIN”).
 * Kod bez rozmiaru bierzemy tylko wtedy, gdy KAŻDA pozycja karty ma kod X + spacja + jej rozmiar z tym samym X —
 * inaczej, jak do 28.09.2026, kod najmniejszego rozmiaru. Nazwa = Model także przy jednym rozmiarze z modelem, a lista
 * „Rozmiary: …” także przy jednym rozmiarze. Pozycja z pustym Modelem (18 z 455, np. „COMFORT PREMIUM 10”) ma rozmiar
 * tylko w wierszu „Rozmiar” tabelki strony produktu — pojedyncza wartość (bez spacji, nie zakres „7-11”), na którą
 * kończy się nazwa, schodzi z nazwy i z kodu (kod bez tej końcówki zostaje bez zmian — „POLAR I” przy nazwie
 * „… POLAR I 10”); samej nazwy nie tniemy na zgadywany rozmiar. Strona takiej pozycji jest pobierana w products()
 * i trzymana do shopFields()/documents() tej karty (bez drugiego pobrania). Dwie karty przebiegu z tym samym kodem
 * albo nazwą po zmianie — wszystkie zostają z kodem i nazwą sprzed zmiany (bez zgadywania), wpis w podsumowaniu.
 * Karta z jedną pozycją: nazwa bez rozmiaru idzie jako cardName (nazwa nowej karty), a name zostaje dosłowną nazwą
 * pozycji (b2b_product_links.remote_name); kod powiązania takiej karty synchronizacja bierze z kodu karty, więc kod
 * pozycji dosłownie zostaje w identyfikatorze source_code (etykieta = rozmiar).
 * Synchronizacja nie zmienia kodu ani nazwy zastanej karty (tylko nowej) — zastane karty poprawia
 * products:repair-tegro-codes. Nowa karta, której kod modelu jest kodem karty innego producenta („ALASKA”, „BASIC”),
 * zostaje przez synchronizację pominięta („kod należy do karty producenta …”), a przy tym samym producencie —
 * dopasowana do tej karty po kodzie (dotychczasowa reguła kodu w B2bCatalogSync).
 *
 * Tegro nie jest producentem tych marek, więc łącznik nie jest B2bManufacturerSite: opis ze sklepu nie nadpisuje
 * opisu z witryny producenta, a normy trafiają do tabelki sklepu (B2bShopFieldSource), nie do norm producenta.
 *
 * Opis w sklepie ma 1–2 zdania, a treść wyrobu (poziomy norm, powłoka, wkładka, branże) jest w karcie katalogowej
 * PDF — dlatego B2bDescribesFromDatasheet: opis karty pisze model z tych dwóch źródeł (decyzja użytkownika 21.09.2026).
 */
final class TegroB2bConnector implements B2bConnector, B2bDescribesFromDatasheet, B2bDocumentSource, B2bGroupsSizes, B2bRunSummaryAware, B2bShopFieldSource, B2bSizePriceSource
{
    private const SHOP_SECTION = 'Parametry produktu';

    private const TRADE_SECTION = 'Informacje handlowe';

    private int $total = 0;

    /** @var list<string> linie podsumowania przebiegu (B2bRunSummaryAware) */
    private array $summary = [];

    /** @var array{url: string, xpath: DOMXPath}|null ostatnio pobrana strona produktu (tabelka i pliki są na tej samej) */
    private ?array $lastPage = null;

    /** @var array<string, string> strony pobrane w products() dla pozycji bez modelu (adres → HTML), do pierwszego odczytu karty */
    private array $prefetchedPages = [];

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
        $groups = array_filter($cards, static fn (array $group): bool => count($group) > 1);
        $multiPrice = count(array_filter($groups, static fn (array $group): bool => count(array_unique(array_map(
            static fn (array $m): string => number_format((float) self::sizePrice($m['item'])?->net, 2, '.', ''),
            $group,
        ))) > 1));
        $this->summary = ['Lista Tegro: '.count($items).' pozycji → '.count($cards).' kart ('.count($groups).' grup rozmiarów, w tym '
            .$multiPrice.' z rozmiarami w różnych cenach — jedna karta, cena karty = najniższa cena rozmiaru, ceny rozmiarów w tabeli rozmiarów karty)'];

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

        // pozycja bez modelu: rozmiar tylko z wiersza „Rozmiar” strony produktu — strona pobrana raz, na kartę
        $this->prefetchedPages = [];
        $pageSizes = [];
        $checked = 0;
        $failed = [];
        foreach ($cards as $index => $group) {
            $item = $group[0]['item'];
            $url = $urls[self::text($item['Id'] ?? null)] ?? null;
            if (count($group) !== 1 || $group[0]['size'] !== null || self::text($item['Model'] ?? null) !== ''
                || self::sizePrice($item) === null || $url === null || ! TegroB2bClient::isOwnUrl($url)) {
                continue;
            }
            $checked++;
            try {
                $html = $this->client->page($url);
            } catch (B2bFatalException $e) {
                throw $e;
            } catch (RuntimeException $e) {
                $failed[] = self::text($item['Sku'] ?? null).' ('.$e->getMessage().')';

                continue;
            }
            $this->prefetchedPages[$url] = $html;
            $size = self::pageSizeOf(self::parsePage($html), self::text($item['Name'] ?? null));
            if ($size !== null) {
                $pageSizes[$index] = $size;
            }
        }
        if ($checked > 0) {
            $this->summary[] = 'Pozycje bez modelu ze stroną produktu: '.$checked.', rozmiar z wiersza „Rozmiar” strony: '.count($pageSizes)
                .' — ich kod i nazwa karty bez rozmiaru';
        }
        if ($failed !== []) {
            $this->summary[] = 'Strona produktu pozycji bez modelu nie została pobrana ('.count($failed).'): '.implode('; ', $failed)
                .' — kod i nazwa tych kart zostają z rozmiarem';
        }

        $plans = [];
        foreach ($cards as $index => $group) {
            $plans[$index] = self::naming($group, $pageSizes[$index] ?? null);
        }
        $this->keepOriginalOnCollision($plans);

        foreach ($cards as $index => $group) {
            yield $this->productFor($group, $urls, $plans[$index]);
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
        return self::sizePrice(['PriceAfterDiscountNet' => $product->raw['price'] ?? null, 'RetailPriceNet' => $product->raw['retail_price'] ?? null]);
    }

    /**
     * Cena pozycji listy: cena konta (PriceAfterDiscountNet) i katalogowa (RetailPriceNet) tej samej pozycji; null =
     * brak ceny konta.
     *
     * @param  array<string, mixed>  $item
     */
    private static function sizePrice(array $item): ?B2bRemotePrice
    {
        $net = self::money($item['PriceAfterDiscountNet'] ?? null);
        if ($net === null || $net['value'] <= 0 || $net['currency'] === '') {
            return null;
        }
        $base = self::money($item['RetailPriceNet'] ?? null);

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
     * Pozycje listy w karty: model + waluta ceny konta + jednostka (cena rozmiaru nie dzieli karty, decyzja użytkownika
     * 28.09.2026). Pozycja bez rozmiaru w nazwie albo bez ceny konta — osobna karta. Karta w kolejności pierwszej pozycji
     * na liście, pozycje karty wg rozmiaru.
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
            $key = mb_strtolower($model).'|'.$price['currency'].'|'.mb_strtolower(self::text($item['Unit'] ?? null));
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
     * Kod i nazwa karty (decyzja właściciela 28.09.2026, opis klasy): karta z rozmiarami z Modelu — nazwa = Model, kod
     * modelu bez rozmiaru (modelCode), bez niego kod najmniejszego rozmiaru; pozycja bez modelu z rozmiarem ze strony
     * ($pageSize) — nazwa i kod bez końcowego „ rozmiar”. „original_*” = kod i nazwa sprzed 28.09.2026 (powrót przy
     * kolizji, keepOriginalOnCollision).
     *
     * @param  list<array{item: array<string, mixed>, size: string|null}>  $group
     * @return array{sku: string, name: string, original_sku: string, original_name: string, sizes: list<string|null>}
     */
    private static function naming(array $group, ?string $pageSize): array
    {
        $first = $group[0]['item'];
        $firstSku = self::text($first['Sku'] ?? null);
        $firstName = self::text($first['Name'] ?? null);
        $model = self::text($first['Model'] ?? null);
        $plan = [
            'sku' => $firstSku,
            'name' => count($group) > 1 ? $model : $firstName,
            'original_sku' => $firstSku,
            'original_name' => count($group) > 1 ? $model : $firstName,
            'sizes' => array_map(static fn (array $m): ?string => $m['size'], $group),
        ];
        if ($group[0]['size'] !== null) {
            // rozmiar z Modelu (group()): nazwa = Model także przy jednym rozmiarze
            $plan['name'] = $model;
            $plan['sku'] = self::modelCode($group) ?? $firstSku;
        } elseif ($pageSize !== null && count($group) === 1) {
            // pageSizeOf sprawdził, że nazwa kończy się na „ rozmiar” i coś przed nim zostaje
            $plan['name'] = trim(substr($firstName, 0, -strlen(' '.$pageSize)));
            $code = str_ends_with($firstSku, ' '.$pageSize) ? trim(substr($firstSku, 0, -strlen(' '.$pageSize))) : '';
            $plan['sku'] = $code !== '' ? $code : $firstSku;
            $plan['sizes'] = [$pageSize];
        }

        return $plan;
    }

    /**
     * Kod modelu: każda pozycja karty ma kod „X rozmiar” z tym samym niepustym X; inaczej null (bez zgadywania).
     *
     * @param  list<array{item: array<string, mixed>, size: string|null}>  $group
     */
    private static function modelCode(array $group): ?string
    {
        $code = null;
        foreach ($group as $member) {
            $sku = self::text($member['item']['Sku'] ?? null);
            $suffix = ' '.$member['size'];
            if ($member['size'] === null || ! str_ends_with($sku, $suffix)) {
                return null;
            }
            $prefix = trim(substr($sku, 0, -strlen($suffix)));
            if ($prefix === '' || ($code !== null && $prefix !== $code)) {
                return null;
            }
            $code = $prefix;
        }

        return $code;
    }

    /**
     * Rozmiar pozycji bez modelu z wiersza „Rozmiar” tabelki strony: jedna wartość (jeden wiersz albo te same), bez
     * białych znaków, nie zakres ani lista („7-11”, „7–11”, „8,9”), a nazwa pozycji kończy się na „ wartość” i przed
     * nią coś zostaje. Inaczej null — nazwa i kod zostają jak w sklepie.
     */
    private static function pageSizeOf(DOMXPath $xpath, string $name): ?string
    {
        $values = [];
        foreach (self::pageParameters($xpath) as [$label, $value]) {
            if (mb_strtolower($label) === 'rozmiar') {
                $values[$value] = true;
            }
        }
        if (count($values) !== 1) {
            return null;
        }
        $size = (string) array_key_first($values);
        if (preg_match('/[\s\-\x{2013}\x{2014},;\/]/u', $size) === 1 || ! str_ends_with($name, ' '.$size)) {
            return null;
        }

        return trim(substr($name, 0, -strlen(' '.$size))) !== '' ? $size : null;
    }

    /**
     * Dwie karty przebiegu z tym samym kodem albo tą samą nazwą (bez rozróżniania wielkości liter), z których choć
     * jedna zmieniła kod lub nazwę według reguły z 28.09.2026 — wszystkie z tej grupy wracają do kodu i nazwy sprzed
     * zmiany (bez zgadywania, która jest „prawdziwa”), wpis w podsumowaniu przebiegu. Rozmiar karty (lista rozmiarów,
     * etykiety kodów) zostaje — to wartość ze sklepu, nie z przycinania.
     *
     * @param  array<int|string, array{sku: string, name: string, original_sku: string, original_name: string, sizes: list<string|null>}>  $plans
     */
    private function keepOriginalOnCollision(array &$plans): void
    {
        $notes = [];
        do {
            $reverted = false;
            foreach (['sku', 'name'] as $field) {
                $byValue = [];
                foreach ($plans as $index => $plan) {
                    $byValue[mb_strtolower($plan[$field])][] = $index;
                }
                foreach ($byValue as $value => $indexes) {
                    if ($value === '' || count($indexes) < 2) {
                        continue;
                    }
                    $changed = array_filter($indexes, static fn (int|string $i): bool => $plans[$i]['sku'] !== $plans[$i]['original_sku']
                        || $plans[$i]['name'] !== $plans[$i]['original_name']);
                    if ($changed === []) {
                        continue;
                    }
                    $notes[] = ($field === 'sku' ? 'kod' : 'nazwa').' „'.$plans[$indexes[0]][$field].'”: '
                        .implode(', ', array_map(static fn (int|string $i): string => $plans[$i]['original_sku'], $indexes));
                    foreach ($indexes as $i) {
                        $plans[$i]['sku'] = $plans[$i]['original_sku'];
                        $plans[$i]['name'] = $plans[$i]['original_name'];
                    }
                    $reverted = true;
                }
            }
        } while ($reverted);

        if ($notes !== []) {
            $this->summary[] = 'Kod albo nazwa bez rozmiaru wspólne dla kilku kart — te karty zostają z kodem i nazwą pozycji: '.implode('; ', $notes);
        }
    }

    /**
     * @param  list<array{item: array<string, mixed>, size: string|null}>  $group
     * @param  array<string, string>  $urls  id produktu → adres strony
     * @param  array{sku: string, name: string, original_sku: string, original_name: string, sizes: list<string|null>}  $plan
     */
    private function productFor(array $group, array $urls, array $plan): B2bRemoteProduct
    {
        $first = $group[0]['item'];
        $id = self::text($first['Id'] ?? null);
        $grouped = count($group) > 1;

        $items = array_map(static fn (array $m, ?string $size): array => [
            'id' => self::text($m['item']['Id'] ?? null),
            'sku' => self::text($m['item']['Sku'] ?? null),
            'name' => self::text($m['item']['Name'] ?? null),
            'ean' => self::text($m['item']['Ean'] ?? null),
            'size' => $size,
        ], $group, $plan['sizes']);
        // pozycje grupy mają cenę konta w jednej walucie (group()) — cena rozmiaru przy każdej
        $members = [];
        if ($grouped) {
            foreach ($group as $index => $member) {
                $members[] = array_filter([
                    'remote_id' => $items[$index]['id'],
                    'sku' => $items[$index]['sku'],
                    'name' => $items[$index]['name'],
                    'size' => (string) $items[$index]['size'],
                    'price' => self::sizePrice($member['item']),
                ], static fn (mixed $value): bool => $value !== null);
            }
        }
        // cena karty = najtańszy rozmiar; remis ceny konta — rozmiar ze znaną ceną katalogową, z dwóch znanych niższa,
        // dalej pierwszy (ta sama reguła co B2bCatalogSync::winsSizePriceTie)
        $cheapest = $group[0]['item'];
        foreach ($group as $member) {
            $price = self::sizePrice($member['item']);
            $best = self::sizePrice($cheapest);
            if ($price !== null && $best !== null && ($price->net < $best->net - 0.0049
                || (abs($price->net - $best->net) < 0.005 && B2bCatalogSync::winsSizePriceTie($price->base, $best->base)))) {
                $cheapest = $member['item'];
            }
        }

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
            sku: $plan['sku'],
            // pojedyncza pozycja: nazwa pozycji dosłownie (b2b_product_links.remote_name), nazwa bez rozmiaru tylko na nową
            // kartę (cardName, jak u Protektu); karta z rozmiarami — nazwa modelu, jak dotąd
            name: $grouped ? $plan['name'] : $items[0]['name'],
            category: $categories[0] ?? null,
            sourceUrl: $url,
            raw: [
                'brand' => self::text($first['Brand'] ?? null),
                // pierwszy niepusty opis rozmiarów modelu — 21.09.2026 „F09 PLUS 6” jako jedyna z 455 pozycji nie miała
                // opisu, a pozostałe rozmiary tego modelu go mają; karta zostawała bez opisu
                'description' => (string) (collect($group)
                    ->map(static fn (array $m): string => self::text($m['item']['Description'] ?? null))
                    ->first(static fn (string $d): bool => $d !== '') ?? ''),
                'unit' => self::text($first['Unit'] ?? null),
                'price' => $cheapest['PriceAfterDiscountNet'] ?? null,
                'retail_price' => $cheapest['RetailPriceNet'] ?? null,
                'photo' => $photo,
                'categories' => $categories,
                'attributes' => $attributes,
                'items' => $items,
                'page_url' => $url,
            ],
            // lista rozmiarów także przy jednym rozmiarze (z Modelu albo z wiersza „Rozmiar” strony), decyzja 28.09.2026
            variantSummary: $items[0]['size'] !== null
                ? 'Rozmiary: '.implode('; ', array_map(static fn (array $i): string => $i['size'].' ('.$i['sku'].')', $items))
                : null,
            members: $members,
            identifiers: self::identifiers($items),
            cardName: ! $grouped && $plan['name'] !== $items[0]['name'] ? $plan['name'] : null,
        );
    }

    /**
     * EAN i kod Tegro każdego rozmiaru; pozycja = Id z API (remote_id powiązania). Tegro jest dystrybutorem, więc
     * Sku to jego własny kod, nie kod producenta.
     *
     * @param  list<array{id: string, sku: string, name: string, ean: string, size: string|null}>  $items
     * @return list<B2bRemoteIdentifier>
     */
    private static function identifiers(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if ($item['id'] === '') {
                continue;
            }
            if ($item['ean'] !== '') {
                $out[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_EAN, value: $item['ean'], remoteId: $item['id'], label: $item['size'], field: 'Ean');
            }
            if ($item['sku'] !== '') {
                $out[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_SOURCE_CODE, value: $item['sku'], remoteId: $item['id'], label: $item['size'], field: 'Sku');
            }
        }

        return $out;
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

        // strona pozycji bez modelu jest już pobrana w products() — oddajemy ją raz, bez drugiego zapytania
        $html = $this->prefetchedPages[$url] ?? $this->client->page($url);
        unset($this->prefetchedPages[$url]);
        $xpath = self::parsePage($html);
        $this->lastPage = ['url' => $url, 'xpath' => $xpath];

        return $xpath;
    }

    private static function parsePage(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
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
