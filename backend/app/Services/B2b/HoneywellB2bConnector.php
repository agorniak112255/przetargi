<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use App\Models\ProductShopCard;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

/**
 * Sklep producenta Honeywell automation.honeywell.com (myAutomation) — ŚOI i przenośne detektory gazów. Sprawdzone na
 * koncie 30.09.2026 (PHT SUPON, jednostka sprzedaży FR50 – Honeywell Safety Products France, ceny w EUR).
 *
 * Lista: rodziny z publicznej wyszukiwarki katalogu (zakresy SCOPES, kraj „pl”, język „en” — polskiego sklep nie ma),
 * pobrane w całości przed pierwszym zapisem, z kontrolą licznika i unikalności jak u Mascot, w stałej kolejności
 * (numer rodziny). Dla każdej rodziny: pozycje konta z listy pozycji ({strona rodziny}.pdpsearchsearvlet — tylko
 * zalogowany dostaje kod produktu w sklepie), tabela pozycji ze strony produktu w sklepie (rozmiar, EAN, jednostka
 * ceny, opakowanie) i ceny z /pricecall (netprice = cena konta, listPrice = katalogowa; ceny specjalne konta już są
 * w netprice). Rodzina bez pozycji konta nie jest kartą. Kod pozycji w kilku rodzinach — tylko z pierwszej.
 *
 * „Produkt” Honeywell to RODZINA różnych wyrobów (A700: kolory soczewek, MAXIMUM: ze sznurkiem i bez, różne pudełka;
 * HL400: dozowniki i wkłady), więc karta = pozycja sklepu. Rozmiary łączymy w kartę z tabelą rozmiarów (B2bGroupsSizes,
 * ceny rozmiarów — B2bSizePriceSource) tylko wtedy, gdy tabela sklepu mówi, że to rozmiary (sizeGroups) — decyzja
 * zależy wyłącznie od kodu, rozmiaru i opisu pozycji, nie od cen, więc nie zmienia się między przebiegami. Skutek
 * znacznika B2bGroupsSizes: „Łączenie kart” nie proponuje scalania kart Honeywell jako rozmiarów — pozycje, których
 * łącznik nie uznał za rozmiary, zostają osobnymi kartami.
 *
 * Cena: sklep podaje ją za jednostkę sprzedaży („1 BOX”, „1 PR”, „1 EA”…) i zawartość opakowania w warunku zamawiania
 * („Min 1 box (12 pair)”). Decyzja użytkownika 30.09.2026: cena za parę/sztukę, gdy zawartość da się odczytać, oraz
 * informacja o opakowaniu. Honeywell jest pierwszym łącznikiem, który DZIELI cenę (3M podaje cenę za sztukę wprost):
 * cena konta i katalogowa / zawartość, tylko gdy zaokrąglenie do centa zmienia cenę opakowania najwyżej o 0,5%
 * (kolumny cen mają 2 miejsca — 33,20 € za 400 par dałoby 0,08 €/parę, −3,6%); inaczej cena za opakowanie. Przy obu
 * przypis ceny z ceną sklepu dosłownie i warunek zamawiania pełnymi opakowaniami. Opakowanie bez podanej zawartości —
 * pozycja pominięta (cena za nieznaną ilość wygrałaby jako cena producenta z ceną za parę dystrybutora).
 *
 * Błąd chwilowy (strona sklepu, ceny, sesja) — rodzina idzie jako pozycja pominięta z listą swoich pozycji, więc
 * synchronizacja niczego nie oznacza jako wycofanego; seria takich rodzin przerywa przebieg.
 *
 * Teksty po angielsku (B2bForeignLanguageSource): opis i nazwa nowej karty są tłumaczone po zapisie; łącznik podaje je
 * dosłownie. Norm i certyfikatów łącznik nie dopisuje — są tylko w opisie i plikach producenta.
 */
final class HoneywellB2bConnector implements B2bConnector, B2bDocumentSource, B2bForeignLanguageSource, B2bGroupsSizes, B2bImageGallery, B2bListProgressAware, B2bManufacturerSite, B2bRunSummaryAware, B2bShopFieldSource, B2bSizePriceSource
{
    public const BRAND = 'Honeywell';

    /**
     * Zakresy katalogu (decyzja użytkownika 30.09.2026: ŚOI i przenośne detektory gazów) — filtry wyszukiwarki.
     *
     * @var array<string, list<array<string, string>>>
     */
    public const SCOPES = [
        'ŚOI' => [['sbu' => 'Personal Protective Equipment']],
        'detektory przenośne' => [['line_of_business' => 'Gas & Flame Detection'], ['product_family' => 'Portables']],
    ];

    private const PAGE_SIZE = 100;

    /** Pozycje w jednym zapytaniu o ceny (strona sklepu pyta po kilka). */
    private const PRICE_BATCH = 20;

    private const LIST_BUDGET_SECONDS = 25 * 60;

    private const INCONSISTENT = 7301;

    /** Komunikat postępu co tyle rodzin (przebieg bez sygnału życia b2b:sync-due uznałby za przerwany). */
    private const PROGRESS_EVERY_FAMILIES = 25;

    /**
     * Tyle rodzin z pozycjami konta z rzędu z błędem chwilowym (brak tabeli w sklepie, brak cen, błąd strony) = sklep nie
     * pokazuje danych konta (np. prosi o wybór jednostki sprzedaży) — przebieg przerwany.
     */
    private const MAX_FAILED_FAMILIES_IN_A_ROW = 10;

    /** Dopuszczalna zmiana ceny opakowania przez przeliczenie na parę/sztukę i zaokrąglenie do centa. */
    private const ROUNDING_TOLERANCE = 0.005;

    /** Pamięć plików bieżącej rodziny (galeria i PDF-y wspólne dla jej kart) — limit bajtów. */
    private const FILE_CACHE_BYTES = 16_000_000;

    private const SHOP_SECTION = 'Informacje ze sklepu Honeywell';

    /** Rozmiar „jeden” w kolumnie Size — nie rozmiar. */
    private const ONE_SIZE = 'one size';

    /**
     * Jednostka ceny („1 BOX”) → słowo opakowania w tekście „Min 1 box (12 pair)”.
     *
     * @var array<string, string>
     */
    private const PRICE_UNITS = ['PR' => 'pair', 'EA' => 'each', 'BOX' => 'box', 'CAS' => 'case', 'CS' => 'case', 'BAG' => 'bag', 'PAC' => 'pack', 'PK' => 'pack'];

    /** Jednostki pojedyncze (cena za parę/sztukę). */
    private const SINGLE_UNITS = ['pair', 'each'];

    /** Jednostka warunku zamawiania po polsku (widok dopisuje ją do ilości: „po 12 par”); dosłowna jest w tabelce karty. */
    private const ORDER_UNITS = ['pair' => 'par', 'each' => 'szt.', 'box' => 'op.', 'pack' => 'op.', 'case' => 'karton', 'bag' => 'worek'];

    /** Rozmiary literowe (zasada B — segment kodu). */
    private const LETTER_SIZES = ['XXS', 'XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL', '2XL', '3XL', '4XL', '5XL'];

    /** Rodzaj pliku z kategorii zasobu; Firmware i reszta nie-PDF pomijane. */
    private const DOCUMENT_KINDS = [
        'data sheet' => ProductDocument::KIND_DATASHEET,
        'manuals and guides' => ProductDocument::KIND_MANUAL,
        'certificate' => ProductDocument::KIND_CERTIFICATE,
    ];

    private const SKIPPED_DOCUMENT_CATEGORIES = ['firmware'];

    private int $total = 0;

    /** @var (callable(string): void)|null */
    private $listProgress = null;

    /** @var list<string> */
    private array $summary = [];

    private int $families = 0;

    private int $familiesOutside = 0;

    private int $familiesWithAccount = 0;

    private int $cards = 0;

    private int $sizeCards = 0;

    private int $perUnitPrices = 0;

    private int $perPackPrices = 0;

    /** @var list<string> kody pozycji bez ceny konta (poza kartami) */
    private array $withoutPrice = [];

    /** @var list<string> kody pozycji z ceną za opakowanie bez podanej zawartości (poza kartami) */
    private array $withoutContents = [];

    /** @var list<string> kody pozycji konta bez wiersza w tabeli sklepu */
    private array $withoutRow = [];

    /** @var list<string> kody pozycji w kilku rodzinach (karta tylko z pierwszej) */
    private array $duplicateCodes = [];

    /** @var list<string> rodziny z błędem chwilowym (pominięte w tym przebiegu) */
    private array $failedFamilies = [];

    /** @var list<string> karty rozmiarów pominięte, bo rozmiary mają różne jednostki ceny */
    private array $mixedUnits = [];

    /** @var array<string, array{bytes: string, mime: string}> */
    private array $fileCache = [];

    private int $fileCacheBytes = 0;

    public function __construct(
        private readonly HoneywellB2bClient $client,
        private readonly int $pageSize = self::PAGE_SIZE,
    ) {}

    public static function key(): string
    {
        return 'honeywell';
    }

    public static function label(): string
    {
        return 'Honeywell';
    }

    public static function host(): string
    {
        return HoneywellB2bClient::HOST;
    }

    public static function ownBrand(): string
    {
        return self::BRAND;
    }

    public static function forAccount(B2bAccount $account, int $delayMs): self
    {
        return new self(new HoneywellB2bClient((string) $account->username, (string) $account->password, $delayMs));
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
        $this->families = 0;
        $this->familiesOutside = 0;
        $this->familiesWithAccount = 0;
        $this->cards = 0;
        $this->sizeCards = 0;
        $this->perUnitPrices = 0;
        $this->perPackPrices = 0;
        $this->withoutPrice = [];
        $this->withoutContents = [];
        $this->withoutRow = [];
        $this->duplicateCodes = [];
        $this->failedFamilies = [];
        $this->mixedUnits = [];

        if (! $this->client->isLoggedIn()) {
            $this->client->login();
        }

        $families = $this->listFamilies();
        // stała kolejność: kod pozycji z kilku rodzin trafia zawsze do tej samej
        usort($families, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        $this->families = count($families);
        // na start karta na rodzinę; rodzina poza kontem odejmuje swoją, rodzina z kilkoma kartami dokłada
        $this->total = count($families);

        $seenCodes = [];
        $failedInARow = 0;
        foreach ($families as $index => $family) {
            if ($index > 0 && $index % self::PROGRESS_EVERY_FAMILIES === 0) {
                $this->progress('Rodziny Honeywell: '.$index.'/'.count($families));
            }

            try {
                $accountSkus = $this->client->accountSkus($family['path'], $family['id']);
            } catch (B2bFatalException $e) {
                throw $e;
            } catch (RuntimeException $e) {
                // pozycji rodziny nie znamy — nic o nich nie mówimy (karty zostają bez zmian), rodzina liczy się do serii błędów
                $this->failedFamilies[] = $family['name'].' (lista pozycji: '.$e->getMessage().')';
                $this->total--;
                if (++$failedInARow >= self::MAX_FAILED_FAMILIES_IN_A_ROW) {
                    throw new B2bFatalException(self::MAX_FAILED_FAMILIES_IN_A_ROW.' rodzin z rzędu bez listy pozycji konta w sklepie '.HoneywellB2bClient::HOST.' (ostatnia: '.$e->getMessage().') — przebieg przerwany', 0, $e);
                }

                continue;
            }
            $skus = [];
            $outside = true;
            foreach ($accountSkus as $sku) {
                $outside = false;
                if (isset($seenCodes[$sku['code']])) {
                    $this->duplicateCodes[] = $sku['code'];

                    continue;
                }
                $seenCodes[$sku['code']] = true;
                $skus[] = $sku;
            }
            if ($skus === []) {
                $this->familiesOutside += $outside ? 1 : 0;
                $this->total--;

                continue;
            }
            $this->familiesWithAccount++;

            ['products' => $products, 'failed' => $failed] = $this->familyProducts($family, $skus);
            $failedInARow = $failed ? $failedInARow + 1 : 0;
            if ($failedInARow >= self::MAX_FAILED_FAMILIES_IN_A_ROW) {
                throw new B2bFatalException(self::MAX_FAILED_FAMILIES_IN_A_ROW.' rodzin z rzędu z pozycjami konta, ale bez tabeli pozycji albo cen w sklepie '.HoneywellB2bClient::HOST.' (ostatnia: '.end($this->failedFamilies).') — przebieg przerwany');
            }

            $this->total += count($products) - 1;
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
            'Rodziny: %d w zakresie, %d z pozycjami konta, %d poza kontem (sklep nie podaje pozycji konta); karty: %d (%d z tabelą rozmiarów)',
            $this->families,
            $this->familiesWithAccount,
            $this->familiesOutside,
            $this->cards,
            $this->sizeCards,
        );
        $lines[] = sprintf(
            'Ceny: %d pozycji za parę/sztukę (przeliczone z opakowania albo podane tak w sklepie), %d za opakowanie (przeliczenie zaokrągleniem zmieniłoby cenę o ponad 0,5%%)',
            $this->perUnitPrices,
            $this->perPackPrices,
        );
        foreach ([
            'Pozycje bez ceny konta (poza kartami)' => $this->withoutPrice,
            'Pozycje z ceną za opakowanie bez podanej zawartości (poza kartami)' => $this->withoutContents,
            'Pozycje konta bez wiersza w tabeli sklepu (poza kartami)' => $this->withoutRow,
            'Pozycje w kilku rodzinach (karta z pierwszej)' => $this->duplicateCodes,
            'Rozmiary w różnych jednostkach ceny (karta pominięta)' => $this->mixedUnits,
            'Rodziny z błędem odczytu (bez zmian w tym przebiegu)' => $this->failedFamilies,
        ] as $label => $items) {
            if ($items !== []) {
                $lines[] = $label.': '.self::listing($items);
            }
        }
        $lines[] = 'Rodziny z listy wyszukiwarki dla kraju „pl”; sklep podaje teksty po angielsku (tłumaczenie po zapisie) i nie podaje stanów magazynowych; minimum logistyczne zamówienia jest warunkiem konta, nie karty';

        return $lines;
    }

    public function manufacturer(B2bRemoteProduct $product): string
    {
        return self::BRAND;
    }

    public function price(B2bRemoteProduct $product): ?B2bRemotePrice
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            throw new RuntimeException((string) ($product->raw['reason'] ?? 'pozycja nieodczytana'));
        }
        $price = $product->raw['price'] ?? null;
        if (! $price instanceof B2bRemotePrice) {
            throw new RuntimeException('karta bez ceny pozycji');
        }

        return $price;
    }

    /** Opis rodziny dosłownie; pozycja pominięta — wyjątek (synchronizacja zostawia opis karty bez zmian). */
    public function description(B2bRemoteProduct $product): string
    {
        self::assertRead($product);

        return (string) ($product->raw['description'] ?? '');
    }

    /** Pozycja pominięta nie ma danych sklepu — opis, pliki i tabelka karty zostają bez zmian (nie są „puste”). */
    private static function assertRead(B2bRemoteProduct $product): void
    {
        if (($product->raw['status'] ?? null) !== 'ok') {
            throw new RuntimeException((string) ($product->raw['reason'] ?? 'pozycja nieodczytana'));
        }
    }

    /**
     * Tabelka karty ze sklepu: rodzina, kody, opisy pozycji, rozmiary, EAN-y, jednostka ceny i warunek zamawiania
     * dosłownie (po angielsku), kraj pochodzenia, wysyłka, jednostka sprzedaży Honeywell.
     *
     * @return list<B2bRemoteShopField>
     */
    public function shopFields(B2bRemoteProduct $product): array
    {
        self::assertRead($product);
        $raw = $product->raw;
        /** @var list<array<string, string>> $rows */
        $rows = $raw['rows'];
        $many = count($rows) > 1;
        $perRow = static function (string $key) use ($rows, $many): string {
            if (! $many) {
                return $rows[0][$key];
            }
            $values = [];
            foreach ($rows as $row) {
                if ($row[$key] !== '') {
                    $values[] = $row['code'].': '.$row[$key];
                }
            }

            return self::limited($values);
        };
        $distinct = static function (string $key) use ($rows): string {
            return implode('; ', array_values(array_unique(array_filter(array_column($rows, $key), static fn (string $v): bool => $v !== ''))));
        };

        $fields = [];
        foreach ([
            'Rodzina wyrobów' => $raw['family_name'],
            'Kod rodziny' => $raw['family_code'],
            'Grupa' => $raw['group'],
            'Kody pozycji' => self::limited(array_column($rows, 'code')),
            'Opis pozycji' => $perRow('description'),
            'Rozmiar' => $perRow('size'),
            // zasada B: rozmiar odczytany z ostatniego segmentu kodu, kolumna Size sklepu mówi „One Size”
            'Rozmiar (z kodu pozycji)' => ($raw['size_from_code'] ?? false) === true ? self::limited(array_map(
                static fn (array $row): string => $row['code'].': '.self::lastSegment($row['code']),
                $rows,
            )) : '',
            'EAN' => $perRow('ean'),
            'Jednostka ceny w sklepie' => $distinct('price_unit'),
            'Opakowanie / minimum zamówienia' => $distinct('min_text'),
            'Kraj pochodzenia' => $distinct('origin'),
            'Wysyłka z' => $distinct('ships_from'),
            'Jednostka sprzedaży Honeywell' => $distinct('entity'),
        ] as $name => $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $fields[] = new B2bRemoteShopField(self::SHOP_SECTION, $name, $value);
            }
        }

        return $fields;
    }

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage
    {
        $url = $this->imageUrls($product)[0] ?? null;

        return $url === null ? null : $this->imageAt($url);
    }

    /**
     * @return list<string>
     */
    public function imageUrls(B2bRemoteProduct $product): array
    {
        return is_array($product->raw['images'] ?? null) ? $product->raw['images'] : [];
    }

    public function imageAt(string $url): ?B2bRemoteImage
    {
        $file = $this->file($url);
        if ($file['bytes'] === '' || ! str_starts_with($file['mime'], 'image/')) {
            return null;
        }

        return new B2bRemoteImage(bytes: $file['bytes'], mime: $file['mime'], sourceUrl: $url);
    }

    /**
     * @return list<B2bRemoteDocument>
     */
    public function documents(B2bRemoteProduct $product): array
    {
        self::assertRead($product);
        $documents = [];
        foreach (is_array($product->raw['documents'] ?? null) ? $product->raw['documents'] : [] as $doc) {
            $documents[] = new B2bRemoteDocument(title: $doc['title'], sourceUrl: $doc['url'], kind: $doc['kind']);
        }

        return $documents;
    }

    public function documentBytes(B2bRemoteDocument $document): array
    {
        $file = $this->file($document->sourceUrl);
        if ($file['mime'] !== 'application/pdf' && ! str_starts_with($file['bytes'], '%PDF')) {
            throw new RuntimeException('plik nie jest PDF-em ('.$file['mime'].'): '.$document->sourceUrl);
        }

        return ['bytes' => $file['bytes'], 'mime' => 'application/pdf'];
    }

    /**
     * Wiersze tabeli pozycji ze strony produktu w sklepie, dosłownie. Kod z odnośnika pozycji, ref ceny z pola „code”
     * („PRD010~T4700-07S”), EAN z komórki kodu, rozmiar z kolumny Size, cena katalogowa i jej jednostka („1 BOX”),
     * tekst warunku zamawiania („Min 1 box (12 pair)”) i dane wysyłki. Wiersz bez kodu albo ref — pominięty.
     *
     * @return array<string, array{code: string, ref: string, ean: string, description: string, size: string, list_price: string, price_unit: string, min_text: string, min_qty: string, multiple: string, origin: string, ships_from: string, entity: string}>
     */
    public static function shopRows(string $html): array
    {
        if (! str_contains($html, 'responsive-table-item')) {
            return [];
        }
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);

        $rows = [];
        $trs = $xpath->query('//table[contains(concat(" ", normalize-space(@class), " "), " custom-table ")]//tbody/tr[contains(concat(" ", normalize-space(@class), " "), " responsive-table-item ")]');
        foreach ($trs === false ? [] : $trs as $tr) {
            if (! $tr instanceof DOMElement) {
                continue;
            }
            $cells = self::labelledCells($tr);
            $code = self::clean(self::firstText($xpath, './/a[contains(@class, "variant-part-specification-redirect")]', $tr));
            if ($code === '') {
                $code = self::clean(self::firstValue($xpath, './/input[@name="variantname"]', $tr));
            }
            $ref = self::clean(self::firstValue($xpath, './/input[@name="code"]', $tr));
            if ($code === '' || $ref === '') {
                continue;
            }
            $partCell = $cells['part #'] ?? null;
            $ean = '';
            if ($partCell !== null && preg_match('/EAN\s*UPC\s*(\d{8,14})/u', self::clean($partCell->textContent), $m) === 1) {
                $ean = $m[1];
            }
            $priceCell = $cells['list price'] ?? null;
            $component = $xpath->query('.//div[contains(@class, "addtocart-component")]', $tr);
            $component = $component !== false ? $component->item(0) : null;
            $minText = '';
            if ($component instanceof DOMElement) {
                $minNode = $xpath->query('./div[contains(@class, "text-center")]', $component);
                $minText = $minNode !== false && $minNode->item(0) !== null ? self::clean($minNode->item(0)->textContent) : '';
            }
            $entity = '';
            if (preg_match('/Business Entity\s*:\s*(.+?)\s*$/u', self::clean(self::firstText($xpath, './/p[contains(., "Business Entity")]', $tr)), $m) === 1) {
                $entity = $m[1];
            }

            $rows[$code] = [
                'code' => $code,
                'ref' => $ref,
                'ean' => $ean,
                'description' => self::clean(self::firstText($xpath, './/p[contains(@class, "item__name")]', $tr)),
                'size' => isset($cells['size']) ? self::clean($cells['size']->textContent) : '',
                'list_price' => self::clean(self::firstValue($xpath, './/input[contains(@class, "js-list-price")]', $tr)),
                'price_unit' => $priceCell !== null ? self::clean(self::firstText($xpath, './/div[contains(@class, "text-uppercase")]', $priceCell)) : '',
                'min_text' => $minText,
                'min_qty' => $component instanceof DOMElement ? trim($component->getAttribute('data-min-order-qty')) : '',
                'multiple' => $component instanceof DOMElement ? trim($component->getAttribute('data-variant-multiple')) : '',
                'origin' => self::clean(self::firstValue($xpath, './/input[@name="origin"]', $tr)),
                'ships_from' => self::clean(self::firstValue($xpath, './/input[@name="shipFrom"]', $tr)),
                'entity' => $entity,
            ];
        }

        return $rows;
    }

    /**
     * Jednostka ceny i warunek zamawiania: słowo jednostki ceny („1 BOX” → box), opakowanie, liczba opakowań minimum,
     * zawartość opakowania i jej jednostka z „Min 1 box (12 pair)”, krok z data-variant-multiple.
     *
     * @return array{word: string|null, pack: string, min_packs: float|null, contents: float|null, inner: string|null, step: float|null}
     */
    public static function packaging(string $priceUnit, string $minText, string $minQty = '', string $multiple = ''): array
    {
        $word = null;
        if (preg_match('/^1\s+([A-Z]+)$/u', mb_strtoupper(self::clean($priceUnit)), $m) === 1) {
            $word = self::PRICE_UNITS[$m[1]] ?? null;
        }
        $minPacks = null;
        $pack = '';
        $contents = null;
        $inner = null;
        if (preg_match('/^Min\s*(\d+(?:[.,]\d+)?)?\s*([A-Za-z]+)?\s*(?:\(\s*(\d+(?:[.,]\d+)?)\s*([A-Za-z]+)\s*\))?$/u', self::clean($minText), $m) === 1) {
            $minPacks = ($m[1] ?? '') !== '' ? (float) str_replace(',', '.', $m[1]) : null;
            $pack = mb_strtolower($m[2] ?? '');
            $contents = ($m[3] ?? '') !== '' ? (float) str_replace(',', '.', $m[3]) : null;
            $inner = ($m[4] ?? '') !== '' ? mb_strtolower($m[4]) : null;
        }

        return [
            'word' => $word,
            'pack' => $pack,
            'min_packs' => $minPacks ?? B2bOrderQuantity::attribute($minQty),
            'contents' => $contents,
            'inner' => $inner,
            'step' => B2bOrderQuantity::attribute($multiple),
        ];
    }

    /**
     * Cena pozycji w EUR z /pricecall i wiersza tabeli. Tryby:
     * - „unit” — cena za parę/sztukę: jednostka ceny to para/sztuka („1 PR”) albo opakowanie z zawartością w parach/
     *   sztukach i przeliczenie mieści się w ROUNDING_TOLERANCE; warunek zamawiania pełnymi opakowaniami w parach/
     *   sztukach, przypis z ceną sklepu i ilością w opakowaniu;
     * - „pack” — cena za opakowanie o znanej zawartości, gdy przeliczenie zniekształciłoby cenę;
     * - null — opakowanie bez podanej zawartości albo nieznana jednostka ceny: pozycja nie trafia na kartę.
     *
     * @param  array{net: float, list: float|null, discount: float}  $shopPrice
     * @param  array<string, string>  $row
     * @return array{mode: string, price: B2bRemotePrice}|null
     */
    public static function offer(array $shopPrice, array $row): ?array
    {
        $p = self::packaging($row['price_unit'], $row['min_text'], $row['min_qty'] ?? '', $row['multiple'] ?? '');
        $net = $shopPrice['net'];
        $list = $shopPrice['list'];
        $shopText = number_format($net, 2, ',', '').' EUR za '.self::clean($row['price_unit']).($row['min_text'] !== '' ? ' ('.self::clean($row['min_text']).')' : '');
        $price = static fn (float $n, ?float $b, B2bOrderQuantity $order, B2bPriceCondition $condition): B2bRemotePrice => new B2bRemotePrice(
            net: $n,
            base: $b,
            discountPercent: $shopPrice['discount'],
            currency: 'EUR',
            order: $order,
            condition: $condition,
        );

        if ($p['word'] !== null && in_array($p['word'], self::SINGLE_UNITS, true)) {
            $unit = self::ORDER_UNITS[$p['word']];
            // „1 EA” i „Min 1 box (10 each)” — cena za sztukę, zamawia się pełne pudełka
            if ($p['contents'] !== null && $p['contents'] > 1 && $p['inner'] === $p['word']) {
                return ['mode' => 'unit|'.$p['word'], 'price' => $price(
                    $net,
                    $list,
                    new B2bOrderQuantity(($p['min_packs'] ?? 1.0) * $p['contents'], $p['contents'], $unit),
                    new B2bPriceCondition('Sklep Honeywell: '.$shopText, $p['contents']),
                )];
            }

            return ['mode' => 'unit|'.$p['word'], 'price' => $price($net, $list, new B2bOrderQuantity($p['min_packs'], $p['step'], $unit), new B2bPriceCondition(null))];
        }

        if ($p['word'] === null || $p['pack'] !== $p['word'] || $p['contents'] === null || $p['contents'] <= 1
            || $p['inner'] === null || ! in_array($p['inner'], self::SINGLE_UNITS, true)) {
            return null;
        }

        $contents = $p['contents'];
        $perNet = round($net / $contents, 2);
        $perList = $list !== null ? round($list / $contents, 2) : null;
        $fits = abs($perNet * $contents - $net) <= self::ROUNDING_TOLERANCE * $net
            && ($list === null || abs((float) $perList * $contents - $list) <= self::ROUNDING_TOLERANCE * $list);
        if ($fits && $perNet > 0) {
            return ['mode' => 'unit|'.$p['inner'], 'price' => $price(
                $perNet,
                $perList,
                new B2bOrderQuantity(($p['min_packs'] ?? 1.0) * $contents, $contents, self::ORDER_UNITS[$p['inner']]),
                new B2bPriceCondition('Sklep Honeywell: '.$shopText.'; cena przeliczona na 1 '.$p['inner'], $contents),
            )];
        }

        return ['mode' => 'pack|'.$p['word'], 'price' => $price(
            $net,
            $list,
            new B2bOrderQuantity($p['min_packs'], $p['step'], self::ORDER_UNITS[$p['word']] ?? $p['word']),
            new B2bPriceCondition('Sklep Honeywell: '.$shopText.'; cena za opakowanie — przeliczenie na 1 '.$p['inner'].' zmieniłoby cenę przez zaokrąglenie do centa'),
        )];
    }

    /**
     * Pozycje jednej rodziny w kartach — wyłącznie z kodu, rozmiaru i opisu pozycji (nie z cen), w kolejności strony:
     * A) rozmiar z kolumny Size (nie „One Size”) jest segmentem kodu (między „-”, „/” albo końcem; zero wiodące w kodzie
     *    dopuszczalne) — karta = pozycje o tym samym szablonie kodu z rozmiarem zastąpionym znacznikiem (T4700/6XS…10XL,
     *    6243850-37/7…47/7), rozmiar z kolumny Size;
     * B) „One Size”, ostatni segment kodu po „-”/„/” to rozmiar rękawic/obuwia (5–13, 35–48 z zerem wiodącym albo XS…5XL)
     *    — karta = pozycje o tej samej części kodu przed segmentem i tym samym opisie (CS21-7518B-6…11), rozmiar = segment;
     * reszta — karta na pozycję. Pozycja A/B zawsze idzie jako karta z pozycjami, także pojedyncza.
     *
     * @param  list<array<string, string>>  $rows
     * @return list<array{kind: 'A'|'B'|'single', rows: list<array<string, string>>}>
     */
    public static function sizeGroups(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = self::templateA($row);
            $kind = 'A';
            if ($key === null) {
                $key = self::templateB($row);
                $kind = 'B';
            }
            if ($key === null) {
                $groups['#'.$row['code']] = ['kind' => 'single', 'rows' => [$row]];

                continue;
            }
            $groups[$kind.'|'.$key] ??= ['kind' => $kind, 'rows' => []];
            $groups[$kind.'|'.$key]['rows'][] = $row;
        }

        $out = [];
        foreach ($groups as $group) {
            $sizes = array_map(static fn (array $r): string => mb_strtolower(self::sizeLabel($group['kind'], $r)), $group['rows']);
            if ($group['kind'] !== 'single' && count(array_unique($sizes)) !== count($sizes)) {
                // ten sam rozmiar dwa razy — to nie tabela rozmiarów jednego wyrobu
                foreach ($group['rows'] as $row) {
                    $out[] = ['kind' => 'single', 'rows' => [$row]];
                }

                continue;
            }
            $out[] = $group;
        }

        return $out;
    }

    /**
     * Rozmiar pozycji na karcie: A — kolumna Size, B — ostatni segment kodu.
     *
     * @param  array<string, string>  $row
     */
    private static function sizeLabel(string $kind, array $row): string
    {
        return match ($kind) {
            'A' => trim($row['size']),
            'B' => self::lastSegment($row['code']),
            default => $row['code'],
        };
    }

    /**
     * Klucz zasady A (szablon kodu z rozmiarem zastąpionym znacznikiem); null = pozycja jej nie spełnia.
     *
     * @param  array<string, string>  $row
     */
    private static function templateA(array $row): ?string
    {
        $size = trim($row['size']);
        if ($size === '' || mb_strtolower($size) === self::ONE_SIZE || preg_match('/^[A-Za-z0-9]+$/', $size) !== 1) {
            return null;
        }
        $bare = ltrim($size, '0') !== '' ? ltrim($size, '0') : $size;
        if (preg_match('~^(.+?[-/])0*'.preg_quote($bare, '~').'(?=$|[-/])(.*)$~i', $row['code'], $m) !== 1) {
            return null;
        }

        return mb_strtolower($m[1].'{S}'.$m[2]);
    }

    /**
     * Klucz zasady B (część kodu przed rozmiarem i opis); null = pozycja jej nie spełnia.
     *
     * @param  array<string, string>  $row
     */
    private static function templateB(array $row): ?string
    {
        $size = mb_strtolower(trim($row['size']));
        if ($size !== '' && $size !== self::ONE_SIZE) {
            return null;
        }
        if (preg_match('~^(.+?)[-/]([A-Za-z0-9]{1,4})$~', $row['code'], $m) !== 1 || ! self::isSizeSegment($m[2])) {
            return null;
        }
        $description = mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($row['description'])));

        return mb_strtolower($m[1]).'|'.$description;
    }

    private static function isSizeSegment(string $segment): bool
    {
        if (preg_match('/^\d{1,3}$/', $segment) === 1) {
            $n = (int) $segment;

            return ($n >= 5 && $n <= 13) || ($n >= 35 && $n <= 48);
        }

        return in_array(mb_strtoupper($segment), self::LETTER_SIZES, true);
    }

    private static function lastSegment(string $code): string
    {
        $parts = preg_split('~[-/]~', $code) ?: [$code];

        return (string) end($parts);
    }

    /**
     * Pełna lista rodzin (wszystkie zakresy); niespójna — jedno ponowne pobranie.
     *
     * @return list<array<string, mixed>>
     */
    private function listFamilies(): array
    {
        try {
            return $this->scanFamilies();
        } catch (RuntimeException $e) {
            if ($e->getCode() !== self::INCONSISTENT) {
                throw $e;
            }
            $this->progress('Lista rodzin zmieniła się w trakcie pobierania ('.$e->getMessage().') — pobieram od nowa');
        }

        try {
            return $this->scanFamilies();
        } catch (RuntimeException $e) {
            if ($e->getCode() !== self::INCONSISTENT) {
                throw $e;
            }

            throw new RuntimeException('Lista rodzin '.HoneywellB2bClient::HOST.' niespójna także po ponownym pobraniu: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scanFamilies(): array
    {
        $started = microtime(true);
        $families = [];
        $summary = [];
        foreach (self::SCOPES as $scope => $filters) {
            $total = null;
            $seen = [];
            for ($page = 1; $total === null || ($page - 1) * $this->pageSize < $total; $page++) {
                if (microtime(true) - $started > self::LIST_BUDGET_SECONDS) {
                    throw new RuntimeException('Pobieranie listy '.HoneywellB2bClient::HOST.' trwa ponad '.(self::LIST_BUDGET_SECONDS / 60).' min — przerwane bez zapisu');
                }
                $json = $this->client->searchProducts($filters, $page, $this->pageSize);
                $pageTotal = $json['meta']['page']['total_results'];
                if ($total === null) {
                    $total = $pageTotal;
                    if ($total <= 0) {
                        throw new RuntimeException('Lista rodzin '.HoneywellB2bClient::HOST.' („'.$scope.'”) pusta — zmiana wyszukiwarki albo filtrów');
                    }
                    $this->progress('Lista Honeywell („'.$scope.'”): '.$total.' rodzin');
                } elseif ($pageTotal !== $total) {
                    throw new RuntimeException('liczba rodzin („'.$scope.'”) zmieniła się z '.$total.' na '.$pageTotal, self::INCONSISTENT);
                }
                $expected = min($this->pageSize, $total - ($page - 1) * $this->pageSize);
                if (count($json['results']) !== $expected) {
                    throw new RuntimeException(sprintf('„%s” strona %d: %d rodzin, oczekiwano %d', $scope, $page, count($json['results']), $expected), self::INCONSISTENT);
                }
                foreach ($json['results'] as $doc) {
                    $family = is_array($doc) ? self::family($doc) : null;
                    if ($family === null) {
                        throw new RuntimeException('Rodzina bez numeru albo ścieżki na liście '.HoneywellB2bClient::HOST.' („'.$scope.'”, strona '.$page.') — zmiana wyszukiwarki?');
                    }
                    if (isset($seen[$family['id']])) {
                        throw new RuntimeException('rodzina '.$family['id'].' na dwóch stronach', self::INCONSISTENT);
                    }
                    $seen[$family['id']] = true;
                    // rodzina w dwóch zakresach — raz
                    $families[$family['id']] ??= $family;
                }
            }
            $summary[] = 'Lista Honeywell („'.$scope.'”): '.$total.' rodzin';
        }
        $this->summary = [...$this->summary, ...$summary];

        return array_values($families);
    }

    /**
     * Rodzina z dokumentu wyszukiwarki — tylko pola potrzebne kartom (lista trzymana w pamięci przez cały przebieg).
     *
     * @param  array<string, mixed>  $doc
     * @return array<string, mixed>|null
     */
    private static function family(array $doc): ?array
    {
        // pole wielowartościowe (tablica) — wartości złączone; inne typy niż tekst/liczba — puste
        $raw = static function (string $key) use ($doc): string {
            $value = is_array($doc[$key] ?? null) ? ($doc[$key]['raw'] ?? null) : null;
            if (is_array($value)) {
                $value = implode(', ', array_filter($value, static fn (mixed $v): bool => is_string($v) && trim($v) !== ''));
            }

            return is_string($value) || is_int($value) || is_float($value) ? (string) $value : '';
        };
        $id = $raw('id');
        $path = trim($raw('url'));
        if (preg_match('/\|(\d+)-[a-z]{2}$/', $id, $m) !== 1 || $path === '' || $path[0] !== '/') {
            return null;
        }
        $name = self::clean($raw('product_name') !== '' ? $raw('product_name') : $raw('title'));
        $short = self::htmlText($raw('short_description'));
        $long = self::htmlText($raw('long_description'));
        $description = $long;
        if ($short !== '' && ! str_contains(mb_strtolower($long), mb_strtolower($short))) {
            $description = $long !== '' ? $short."\n\n".$long : $short;
        }
        $group = implode(' › ', array_values(array_filter([self::clean($raw('line_of_business')), self::clean($raw('product_family'))])));

        return [
            'id' => $m[1],
            'path' => $path,
            'name' => $name,
            'code' => self::clean($raw('product_id')),
            'short' => $short,
            'description' => mb_substr($description, 0, 10000),
            'group' => $group,
            'images' => self::images($raw('assets')),
            'documents' => self::documentList($raw('resources')),
        ];
    }

    /**
     * Karty jednej rodziny; failed = błąd chwilowy (rodzina pominięta w tym przebiegu).
     *
     * @param  array<string, mixed>  $family
     * @param  list<array{code: string, shop_code: string, description: string}>  $skus
     * @return array{products: list<B2bRemoteProduct>, failed: bool}
     */
    private function familyProducts(array $family, array $skus): array
    {
        // pliki poprzedniej rodziny nie będą już potrzebne
        $this->fileCache = [];
        $this->fileCacheBytes = 0;

        $codes = array_column($skus, 'code');
        $byShopCode = [];
        foreach ($skus as $sku) {
            $byShopCode[$sku['shop_code']][] = $sku['code'];
        }

        try {
            $rows = [];
            $shopOf = [];
            foreach ($byShopCode as $shopCode => $shopCodes) {
                $page = self::shopRows($this->client->shopPage((string) $shopCode));
                foreach ($shopCodes as $code) {
                    if (isset($page[$code])) {
                        $rows[$code] = $page[$code];
                        $shopOf[$code] = (string) $shopCode;
                    }
                }
            }
            if ($rows === []) {
                return $this->familyFailed($family, $codes, 'sklep nie pokazał pozycji konta w tabeli strony produktu');
            }
            $prices = [];
            $grouped = [];
            foreach ($rows as $code => $row) {
                $grouped[$shopOf[$code]][] = $code;
            }
            foreach ($grouped as $shopCode => $shopCodes) {
                foreach (array_chunk($shopCodes, self::PRICE_BATCH) as $chunk) {
                    $prices += $this->client->prices((string) $shopCode, $chunk);
                }
            }
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            return $this->familyFailed($family, $codes, $e->getMessage(), $e->getCode() !== HoneywellB2bClient::NO_TABLE);
        }

        $answered = array_filter($rows, static fn (array $row): bool => isset($prices[$row['ref']]));
        if ($answered === []) {
            return $this->familyFailed($family, $codes, 'sklep nie podał cen pozycji konta');
        }
        foreach ($codes as $code) {
            if (! isset($rows[$code])) {
                $this->withoutRow[] = $code;
            }
        }

        $products = [];
        foreach (self::sizeGroups(array_values($rows)) as $group) {
            $products = [...$products, ...$this->cardFor($family, $group, $prices)];
        }

        return ['products' => $products, 'failed' => false];
    }

    /**
     * Rodzina z błędem: każda jej pozycja jako osobna pozycja pominięta — synchronizacja uzna je za nieudane i nie ruszy
     * ich kart. $streak = błąd chwilowy albo nieznany (liczy się do serii przerywającej przebieg); strona konta bez
     * tabeli to stan trwały i do serii się nie liczy.
     *
     * @param  array<string, mixed>  $family
     * @param  list<string>  $codes
     * @return array{products: list<B2bRemoteProduct>, failed: bool}
     */
    private function familyFailed(array $family, array $codes, string $reason, bool $streak = true): array
    {
        $this->failedFamilies[] = $family['name'].' ('.$reason.')';

        return ['products' => $this->skippedProducts($family, $codes, 'rodzina '.$family['name'].': '.$reason.' — pozycja bez zmian w tym przebiegu'), 'failed' => $streak];
    }

    /**
     * Pozycje pominięte — osobno, bez łączenia w grupę: różne wyroby rodziny (soczewki A700) nie mogą trafić na jedną
     * kartę nawet wtedy, gdy synchronizacja nie pyta o cenę (wyłączona cena producenta).
     *
     * @param  array<string, mixed>  $family
     * @param  list<string>  $codes
     * @return list<B2bRemoteProduct>
     */
    private function skippedProducts(array $family, array $codes, string $reason): array
    {
        usort($codes, 'strnatcasecmp');

        return array_map(static fn (string $code): B2bRemoteProduct => new B2bRemoteProduct(
            remoteId: $code,
            sku: $code,
            name: self::clean($family['name'].' '.$code),
            sourceUrl: HoneywellB2bClient::productPageUrl($family['path']),
            raw: ['status' => 'skipped', 'reason' => $reason],
        ), $codes);
    }

    /**
     * Karta z grupy pozycji ([karta]); [] = żadna pozycja nie ma ceny do zapisu (listy w podsumowaniu); rozmiary
     * w różnych jednostkach ceny — ich pozycje pominięte, każda osobno.
     *
     * @param  array<string, mixed>  $family
     * @param  array{kind: 'A'|'B'|'single', rows: list<array<string, string>>}  $group
     * @param  array<string, array<string, mixed>>  $prices
     * @return list<B2bRemoteProduct>
     */
    private function cardFor(array $family, array $group, array $prices): array
    {
        $valid = [];
        foreach ($group['rows'] as $row) {
            $shopPrice = self::readPrice($prices[$row['ref']] ?? null);
            if ($shopPrice === null) {
                $this->withoutPrice[] = $row['code'];

                continue;
            }
            $offer = self::offer($shopPrice, $row);
            if ($offer === null) {
                $this->withoutContents[] = $row['code'];

                continue;
            }
            $valid[] = ['row' => $row, 'offer' => $offer];
        }
        if ($valid === []) {
            return [];
        }
        usort($valid, static fn (array $a, array $b): int => strnatcasecmp($a['row']['code'], $b['row']['code']));

        $sized = $group['kind'] !== 'single';
        if ($sized && count(array_unique(array_map(static fn (array $v): string => $v['offer']['mode'], $valid))) > 1) {
            $codes = array_map(static fn (array $v): string => $v['row']['code'], $valid);
            $this->mixedUnits[] = implode(', ', $codes);

            return $this->skippedProducts($family, $codes, 'rozmiary w różnych jednostkach ceny ('.implode(', ', array_unique(array_map(static fn (array $v): string => $v['row']['price_unit'].' / '.$v['row']['min_text'], $valid))).') — karta pominięta');
        }

        $rows = array_map(static fn (array $v): array => $v['row'], $valid);
        $lead = $valid[0];
        $name = self::cardName($family, $rows, $sized);
        $members = [];
        $identifiers = [];
        foreach ($valid as $index => ['row' => $row, 'offer' => $offer]) {
            $label = $sized ? self::sizeLabel($group['kind'], $row) : null;
            if ($sized) {
                $members[] = [
                    'remote_id' => $row['code'],
                    'sku' => $row['code'],
                    // pozycja wiodąca nosi nazwę karty — tylko wtedy tłumaczenie rozpozna nazwę ze źródła
                    'name' => $index === 0 ? $name : $name.' '.$label,
                    'size' => $label,
                    'price' => $offer['price'],
                ];
            }
            $identifiers[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_MANUFACTURER_CODE, value: $row['code'], remoteId: $row['code'], label: $label, field: 'Part #');
            if ($row['ean'] !== '') {
                $identifiers[] = new B2bRemoteIdentifier(type: ProductIdentifier::TYPE_EAN, value: $row['ean'], remoteId: $row['code'], label: $label, field: 'EAN UPC');
            }
            if (str_starts_with($offer['mode'], 'unit|')) {
                $this->perUnitPrices++;
            } else {
                $this->perPackPrices++;
            }
        }
        $this->cards++;
        $this->sizeCards += $sized ? 1 : 0;

        return [new B2bRemoteProduct(
            remoteId: $lead['row']['code'],
            sku: $lead['row']['code'],
            name: $name,
            category: $family['group'] !== '' ? $family['group'] : null,
            sourceUrl: HoneywellB2bClient::productPageUrl($family['path']),
            raw: [
                'status' => 'ok',
                'price' => self::groupPrice(array_map(static fn (array $v): B2bRemotePrice => $v['offer']['price'], $valid)),
                'description' => $family['description'],
                'family_name' => $family['name'],
                'family_code' => $family['code'],
                'group' => $family['group'],
                'images' => $family['images'],
                'documents' => $family['documents'],
                'rows' => $rows,
                'size_from_code' => $group['kind'] === 'B',
            ],
            variantSummary: $sized
                ? 'Rozmiary: '.implode('; ', array_map(
                    static fn (array $r): string => self::sizeLabel($group['kind'], $r).' ('.$r['code'].($r['ean'] !== '' ? ', EAN '.$r['ean'] : '').')',
                    $rows,
                ))
                : 'Kod: '.$rows[0]['code'].($rows[0]['ean'] !== '' ? '; EAN: '.$rows[0]['ean'] : ''),
            members: $members,
            identifiers: $identifiers,
        )];
    }

    /**
     * Cena karty (price()): najtańsza pozycja; warunek zamawiania wspólny albo „zależy od rozmiaru”, przypis ceny
     * najtańszej pozycji z ilością w opakowaniu tylko wtedy, gdy wszystkie pozycje mają tę samą.
     *
     * @param  non-empty-list<B2bRemotePrice>  $prices
     */
    private static function groupPrice(array $prices): B2bRemotePrice
    {
        $cheapest = $prices[0];
        foreach ($prices as $price) {
            if ($price->net < $cheapest->net - 0.0049) {
                $cheapest = $price;
            }
        }
        if (count($prices) === 1) {
            return $cheapest;
        }
        $orders = array_unique(array_map(static fn (B2bRemotePrice $p): string => json_encode($p->order?->slotValues(), JSON_THROW_ON_ERROR), $prices));
        $order = count($orders) === 1 ? $cheapest->order : new B2bOrderQuantity(null, null, $cheapest->order?->unit, varies: true);
        $cartons = array_unique(array_map(static fn (B2bRemotePrice $p): string => (string) $p->condition?->cartonQty, $prices));
        $condition = $cheapest->condition !== null && count($cartons) > 1
            ? new B2bPriceCondition($cheapest->condition->note, null)
            : $cheapest->condition;

        return new B2bRemotePrice(
            net: $cheapest->net,
            base: $cheapest->base,
            discountPercent: $cheapest->discountPercent,
            currency: $cheapest->currency,
            order: $order,
            condition: $condition,
        );
    }

    /**
     * Nazwa karty (dosłowna, tłumaczona po zapisie — człon przed „ – ” zostaje): pozycja — „{rodzina} {kod} – {opis
     * pozycji}”, karta rozmiarów — „{rodzina} – {krótki opis rodziny}”. Opis pisany samymi wielkimi literami (skróty
     * SAP: „HL400 DSPNSR ANTIMICR 400PR”) do nazwy nie idzie — jest w tabelce karty.
     *
     * @param  array<string, mixed>  $family
     * @param  list<array<string, string>>  $rows
     */
    private static function cardName(array $family, array $rows, bool $sized): string
    {
        $base = (string) $family['name'];
        if ($base === '') {
            $base = $rows[0]['description'] !== '' ? $rows[0]['description'] : $rows[0]['code'];
        }
        $detail = $sized ? (string) $family['short'] : $rows[0]['description'];
        if ($sized && ! self::isSentence($detail)) {
            $detail = $rows[0]['description'];
        }
        $detail = self::isSentence($detail) && mb_strtolower(self::clean($detail)) !== mb_strtolower($base) ? self::clean($detail) : '';
        $head = $sized ? $base : $base.' '.$rows[0]['code'];

        return mb_substr($detail !== '' ? $head.' – '.$detail : $head, 0, 500);
    }

    /** Tekst z małymi literami (zdanie, nie skrót SAP pisany wersalikami). */
    private static function isSentence(string $text): bool
    {
        return preg_match('/\p{Ll}/u', $text) === 1 && preg_match('/\n/', $text) !== 1;
    }

    /**
     * @param  mixed  $variant  {listPrice, netprice, discount, error} z /pricecall
     * @return array{net: float, list: float|null, discount: float}|null
     */
    private static function readPrice(mixed $variant): ?array
    {
        if (! is_array($variant) || ($variant['error'] ?? null) !== null) {
            return null;
        }
        $net = self::number($variant['netprice'] ?? null);
        if ($net === null || $net <= 0) {
            return null;
        }
        $list = self::number($variant['listPrice'] ?? null);

        return [
            'net' => $net,
            'list' => $list !== null && $list >= $net ? $list : null,
            'discount' => self::number($variant['discount'] ?? null) ?? 0.0,
        ];
    }

    private static function number(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);
        // „1,234.56” — przecinek tylko jako separator tysięcy (sklep podaje kropkę dziesiętną)
        if (preg_match('/^\d{1,3}(?:,\d{3})+(?:\.\d+)?$/', $value) === 1) {
            $value = str_replace(',', '', $value);
        }

        return preg_match('/^\d+(?:\.\d+)?$/', $value) === 1 ? (float) $value : null;
    }

    /**
     * Plik rodziny z pamięci bieżącej rodziny — jej karty mają wspólną galerię i PDF-y (A700: kilkanaście kart).
     *
     * @return array{bytes: string, mime: string}
     */
    private function file(string $url): array
    {
        if (isset($this->fileCache[$url])) {
            return $this->fileCache[$url];
        }
        $file = $this->client->fileBytes($url);
        $size = strlen($file['bytes']);
        if ($this->fileCacheBytes + $size <= self::FILE_CACHE_BYTES) {
            $this->fileCache[$url] = $file;
            $this->fileCacheBytes += $size;
        }

        return $file;
    }

    /**
     * Zdjęcia rodziny z pola assets: rynek „gb” (zdjęcie główne, potem dodatkowe), bez filmów; brak gb — „us”, dalej
     * pierwsze zdjęcie główne dowolnego rynku.
     *
     * @return list<string>
     */
    private static function images(string $json): array
    {
        $assets = json_decode($json, true);
        if (! is_array($assets)) {
            return [];
        }
        $pick = static function (string $market) use ($assets): array {
            $primary = [];
            $more = [];
            foreach ($assets as $asset) {
                if (! is_array($asset) || ! in_array($market, (array) ($asset['targetMarket'] ?? []), true)) {
                    continue;
                }
                $url = self::fileUrl((string) ($asset['url'] ?? ''));
                $type = mb_strtolower((string) ($asset['type'] ?? ''));
                if ($url === '' || ! HoneywellB2bClient::isFileUrl($url) || str_contains($type, 'video') || ($asset['hasVideo'] ?? false) === true) {
                    continue;
                }
                if ($type === 'product-image-primary') {
                    $primary[] = $url;
                } elseif (str_contains($type, 'image')) {
                    $more[] = $url;
                }
            }

            return array_values(array_unique([...$primary, ...$more]));
        };
        foreach (['gb', 'us'] as $market) {
            $urls = $pick($market);
            if ($urls !== []) {
                return $urls;
            }
        }
        foreach ($assets as $asset) {
            $url = is_array($asset) ? self::fileUrl((string) ($asset['url'] ?? '')) : '';
            if ($url !== '' && ($asset['type'] ?? '') === 'product-image-primary' && HoneywellB2bClient::isFileUrl($url)) {
                return [$url];
            }
        }

        return [];
    }

    /**
     * Pliki PDF rodziny z pola resources: rynek „gb”, a gdy go brak — „us”; bez oprogramowania (Firmware).
     *
     * @return list<array{title: string, url: string, kind: string}>
     */
    private static function documentList(string $json): array
    {
        $resources = json_decode($json, true);
        if (! is_array($resources)) {
            return [];
        }
        foreach (['gb', 'us'] as $market) {
            $documents = [];
            foreach ($resources as $resource) {
                if (! is_array($resource) || ! in_array($market, (array) ($resource['targetMarket'] ?? []), true)) {
                    continue;
                }
                $url = self::fileUrl((string) ($resource['url'] ?? ''));
                $category = mb_strtolower(trim((string) ($resource['category'] ?? '')));
                $format = mb_strtolower(trim((string) ($resource['format'] ?? '')));
                if ($url === '' || ! HoneywellB2bClient::isFileUrl($url) || in_array($category, self::SKIPPED_DOCUMENT_CATEGORIES, true)
                    || ($format !== 'application/pdf' && ! str_ends_with(mb_strtolower((string) parse_url($url, PHP_URL_PATH)), '.pdf'))) {
                    continue;
                }
                $title = self::clean((string) ($resource['name'] ?? ''));
                $documents[$url] = [
                    'title' => $title !== '' ? $title : basename((string) parse_url($url, PHP_URL_PATH)),
                    'url' => $url,
                    // tabela rozmiarów leży w sklepie w „Manuals and Guides” — poznajemy ją po nazwie pliku
                    'kind' => preg_match('/\bsize\s*chart\b/i', $title) === 1
                        ? ProductDocument::KIND_SIZE_CHART
                        : (self::DOCUMENT_KINDS[$category] ?? ProductDocument::KIND_OTHER),
                ];
            }
            if ($documents !== []) {
                return array_values($documents);
            }
        }

        return [];
    }

    /** Adres zdjęcia/pliku z wyszukiwarki; spacje w ścieżce („732_B KC0268 732 CamaVel A6EF3C”) jako %20. */
    private static function fileUrl(string $url): string
    {
        return str_replace(' ', '%20', trim($url));
    }

    /** HTML opisu → tekst z liniami (<br>, akapity, punkty i „<CRLF>” wyszukiwarki = nowa linia). */
    private static function htmlText(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $text = str_ireplace(['<CRLF>', '&lt;CRLF&gt;'], "\n", $html);
        $text = (string) preg_replace('#<\s*br\s*/?\s*>|</\s*(p|div|li|h[1-6]|tr)\s*>#i', "\n", $text);
        $text = (string) preg_replace('#<\s*li\b[^>]*>#i', '- ', $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $lines = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = self::clean($line);
            if ($line !== '' && $line !== '.') {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Komórki wiersza wg etykiet: komórka z klasą sm-label („Size”) opisuje następną.
     *
     * @return array<string, DOMElement>
     */
    private static function labelledCells(DOMElement $tr): array
    {
        $cells = [];
        $label = null;
        foreach ($tr->childNodes as $node) {
            if (! $node instanceof DOMElement || strtolower($node->nodeName) !== 'td') {
                continue;
            }
            if (str_contains(' '.$node->getAttribute('class').' ', ' sm-label ')) {
                $label = mb_strtolower(self::clean($node->textContent));

                continue;
            }
            if ($label !== null && $label !== '' && ! isset($cells[$label])) {
                $cells[$label] = $node;
            }
            $label = null;
        }

        return $cells;
    }

    private static function firstText(DOMXPath $xpath, string $query, DOMElement $context): string
    {
        $nodes = $xpath->query($query, $context);

        return $nodes !== false && $nodes->item(0) !== null ? $nodes->item(0)->textContent : '';
    }

    private static function firstValue(DOMXPath $xpath, string $query, DOMElement $context): string
    {
        $nodes = $xpath->query($query, $context);
        $node = $nodes !== false ? $nodes->item(0) : null;

        return $node instanceof DOMElement ? $node->getAttribute('value') : '';
    }

    /**
     * Lista do jednego pola tabelki; dłuższa niż pole kończy się jawną informacją, nie ucięciem w pół.
     *
     * @param  list<string>  $items
     */
    private static function limited(array $items): string
    {
        $all = implode('; ', $items);
        if (mb_strlen($all) <= ProductShopCard::MAX_VALUE_CHARS) {
            return $all;
        }
        $more = '; … (pełna lista w tabeli pozycji)';
        $out = '';
        foreach ($items as $item) {
            $next = ($out === '' ? '' : $out.'; ').$item;
            if (mb_strlen($next.$more) > ProductShopCard::MAX_VALUE_CHARS) {
                break;
            }
            $out = $next;
        }

        return $out.$more;
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
}
