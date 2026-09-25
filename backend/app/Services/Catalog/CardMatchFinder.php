<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\CardMatchCandidate;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductSourcePrice;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\Pricing\SourcePriceComparison;
use App\Services\ProductSizeMergeService;
use App\Support\CanonicalBrand;
use App\Support\ProductIdentifierCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Propozycje połączenia karty dystrybutora z kartą producenta (plan łączenia kart, etap B) — tylko pewny klucz, nigdy
 * nazwa. Dystrybutor wielu marek (P4S, Raw-Pol, Ardon, Procera) zakłada własną kartę wyrobu, który ma już kartę
 * producenta: P4S „ZPPV99C” (producent „ANRO”, kod producenta „IF/016/F/PS”) obok karty Anro „IF/016/F/PS”.
 *
 * Karta-źródło (duplikat): ma powiązania B2B wyłącznie od kont, które nie są jej właścicielem (CardOwnership), sama nie
 * jest chroniona i nie ma aktywnych wersji. Jej klucze: aktywne identyfikatory — EAN sztuki i kod producenta.
 * Karta-cel: inna karta chroniona; liczą się wyłącznie klucze jej źródeł-właścicieli (identyfikatory konta producenta
 * albo cennika producenta z pliku oraz remote_sku powiązań konta producenta — u producenta SKU w B2B to kod producenta).
 * Dystrybutor nie łączy się z dystrybutorem: wspólny kod dwóch kart P4S i Raw-Pol niczego nie proponuje.
 *
 * Wynik: pending (wszystkie trafione pozycje wskazują jedną kartę, łączenie bezpieczne) albo conflict (kilka kart,
 * inna marka przy EAN albo dane, których scalenie nie przenosi — te same odmowy co products:merge-duplicate), zawsze
 * z powodem. Decyzję podejmuje człowiek na ekranie „Łączenie kart” (CardMatchMerger ponownie woła evaluate()).
 *
 * Klucze w kilku kartach producenta (krok 5): plan „pozycja → karta” — każda pozycja karty dystrybutora (powiązanie
 * B2B albo pozycja identyfikatorów z pliku) ma trafić dokładnie jedną kartę producenta. Rodzaj: size_merge (producent
 * ma osobną kartę na każdy rozmiar w tej samej cenie — pozycje różnią się rozmiarem wg etykiety dystrybutora i nazw
 * kart, ten sam właściciel, te same ceny) albo split (każdy inny przypadek: kolory, rozmiary w różnych cenach, plan
 * niepoprawny). Rozmiar/kolor bez zgadywania (CardMatchSignals); niepewne — conflict z powodem.
 */
final class CardMatchFinder
{
    /** Kod krótszy niż 5 znaków (po normalizacji) bywa numerem rozmiaru albo koloru — nie jest kluczem. */
    private const CODE_MIN_LENGTH = 5;

    private const CHUNK = 1000;

    /** Kod po stronie karty-celu: kod producenta, kod pozycji u producenta (to jego kod) albo drugi kod producenta. */
    private const TARGET_CODE_TYPES = [
        ProductIdentifier::TYPE_MANUFACTURER_CODE,
        ProductIdentifier::TYPE_SOURCE_CODE,
        ProductIdentifier::TYPE_ALT_CODE,
    ];

    /**
     * Prefiksy EAN-13 bez znaczenia poza jednym sklepem albo spoza wyrobów (GS1): 02, 04 i 20–29 — numery do obiegu
     * wewnętrznego (waga, towar sklepu), 05 — kupony, 977–979 — czasopisma i książki (ISSN, ISBN, ISMN), 98–99 — bony
     * i kupony. Taki kod w dwóch firmach może oznaczać dwa różne wyroby.
     */
    private const EAN_EXCLUDED_PREFIXES = '/^(0[245]|2\d|97[789]|9[89])/';

    /** Pola wyniku evaluate() sprzed kroku 5 — ponowna weryfikacja połączenia (CardMatchMerger) i jej atrapy. */
    private const LEGACY_KEYS = [
        'status', 'target_product_id', 'matched_by', 'matched_value', 'matched_source_key', 'brand', 'hits', 'positions',
        'reason', 'conflict_product_ids',
    ];

    private const PLAN_VERSION = 1;

    private const REASON_MAX = 500;

    /** @var array<int, Product> karty z bieżącego przebiegu */
    private array $cards = [];

    /** @var array<int, list<array{account: int, remote_id: string, remote_sku: string|null}>> */
    private array $links = [];

    /** @var array<int, int> karta => liczba aktywnych wersji */
    private array $activeVariants = [];

    /** @var array<int, int> karta => liczba wszystkich wersji (także wycofanych — kaskada skasowałaby i je) */
    private array $allVariants = [];

    /** @var array<int, int|null> karta => price_list_id slotu ceny z pliku (klucz jest, gdy slot jest) */
    private array $fileSlots = [];

    /** @var Collection<int, B2bAccount>|null */
    private ?Collection $accounts = null;

    /** @var array<int, list<string>> pamięć przebiegu: CardOwnership::ownerSourceKeys */
    private array $ownerKeys = [];

    /** @var array<string, string> pamięć przebiegu: CanonicalBrand::key */
    private array $brandKeys = [];

    /** @var array<int, Product> karty docelowe planów (kilka kart) — z liczbą zdjęć i opisem, do wyboru karty, która zostaje */
    private array $keeperCards = [];

    /** @var array<int, list<array{source_key: string, price_list_id: int|null, purchase_price: string|null, catalog_price_net: string|null, currency: string}>> */
    private array $slots = [];

    /** @var array<string, array<int, int>> licznik => karta => liczba wierszy (przetargi, Presta, akcesoria…) */
    private array $planCounts = [];

    /** @var array<int, list<array{source_key: string, position_key: string}>> karta dystrybutora => pozycje z pliku */
    private array $filePositions = [];

    /** @var array<string, string> "źródło\npozycja" => etykieta pozycji (variant_label) */
    private array $positionLabels = [];

    /** @var array<int, string> cennik => producent */
    private array $priceListNames = [];

    public function __construct(
        private readonly CardOwnership $ownership,
        private readonly SourcePriceComparison $labels,
        private readonly CardMatchSignals $signals,
        private readonly B2bConnectorRegistry $connectors,
        private readonly ProductSizeMergeService $sizeMerge,
    ) {}

    /**
     * Przelicza propozycje: upsert pending/conflict, usuwa pending/conflict, które już nie pasują; rejected i merged
     * nietknięte (odrzucona para nie wraca — dla tego samego zestawu kart, niezależnie od rodzaju).
     *
     * @return array{pending: int, conflict: int, removed: int, refreshed_at: string, by_kind: array<string, array{pending: int, conflict: int}>, signals: array<string, int>}
     */
    public function refresh(): array
    {
        $this->reset();

        $eanValues = $this->sharedEanValues();
        $codeValues = $this->sharedCodeValues();
        $linkHits = $this->linksWithManufacturerCodeSku($this->manufacturerCodeValues(), null);
        // klucze tablic PHP zamieniają kody z samych cyfr na liczby — do zapytania zawsze tekst (varchar = liczba
        // porównywałby liczbowo: „012345” = „12345”)
        $codeValues = array_values(array_unique([...$codeValues, ...array_map('strval', array_keys($linkHits))]));

        $results = $this->analyze($eanValues, $codeValues, $linkHits, null);

        return $this->store($results);
    }

    /**
     * Dopasowanie jednej karty-kandydata na duplikat, bez zapisu — do testów i ponownej weryfikacji przed połączeniem.
     * Ten sam wynik co refresh() dla tej karty. Bez $withPlan — pola sprzed kroku 5 (ponowna weryfikacja połączenia
     * z jedną kartą); z $withPlan — także kind, plan, plan_hash i targets_key, jak w zapisie propozycji.
     *
     * @return array<string, mixed>|null
     */
    public function evaluate(Product $source, bool $withPlan = false): ?array
    {
        $result = $this->evaluateFull($source);
        if ($result === null || $withPlan) {
            return $result;
        }

        return array_intersect_key($result, array_flip(self::LEGACY_KEYS));
    }

    /** @return array<string, mixed>|null */
    private function evaluateFull(Product $source): ?array
    {
        $this->reset();
        // producent z bazy, nie z przekazanego modelu — ponowna weryfikacja przed połączeniem ma widzieć stan bieżący
        $current = $source->exists ? Product::query()->toBase()->where('id', $source->id)->first(['manufacturer']) : null;
        if ($current === null) {
            return null;
        }

        $eanValues = [];
        $codeValues = [];
        foreach ($this->sourceIdentifiers([(int) $source->id])[(int) $source->id] ?? [] as $row) {
            if ($row['type'] === ProductIdentifier::TYPE_EAN) {
                $eanValues[$row['normalized']] = true;
            } else {
                $codeValues[$row['normalized']] = true;
            }
        }
        if ($eanValues === [] && $codeValues === []) {
            return null;
        }
        $eanValues = array_keys($eanValues);
        $codeValues = array_map('strval', array_keys($codeValues));
        // kod trafia tylko w tej samej marce, więc powiązania kart innych marek nic nie zmienią
        $linkHits = $codeValues !== []
            ? $this->linksWithManufacturerCodeSku(array_fill_keys($codeValues, 0), $this->brandKey($current->manufacturer))
            : [];

        return $this->analyze(array_map('strval', $eanValues), $codeValues, $linkHits, (int) $source->id)[(int) $source->id] ?? null;
    }

    private function reset(): void
    {
        $this->cards = [];
        $this->links = [];
        $this->activeVariants = [];
        $this->allVariants = [];
        $this->fileSlots = [];
        $this->accounts = null;
        $this->ownerKeys = [];
        $this->brandKeys = [];
        $this->keeperCards = [];
        $this->slots = [];
        $this->planCounts = [];
        $this->filePositions = [];
        $this->positionLabels = [];
        $this->priceListNames = [];
    }

    /**
     * EAN-y sztuki (typ ean, poprawna suma, bez obiegu wewnętrznego i ISBN) obecne na więcej niż jednej karcie.
     *
     * @return list<string>
     */
    private function sharedEanValues(): array
    {
        $values = ProductIdentifier::query()->toBase()
            ->where('type', ProductIdentifier::TYPE_EAN)
            ->whereNotNull('normalized')
            ->whereNull('removed_at')
            ->groupBy('normalized')
            ->havingRaw('COUNT(DISTINCT product_id) > 1')
            ->pluck('normalized');

        return $values->map(static fn ($v): string => (string) $v)
            ->filter(static fn (string $v): bool => self::eligibleEan($v))
            ->values()->all();
    }

    /**
     * Kody (≥ 5 znaków) obecne na więcej niż jednej karcie, z których co najmniej jeden to kod producenta — tylko
     * on jest kluczem po stronie karty-źródła.
     *
     * @return list<string>
     */
    private function sharedCodeValues(): array
    {
        return ProductIdentifier::query()->toBase()
            ->whereIn('type', self::TARGET_CODE_TYPES)
            ->whereNotNull('normalized')
            ->whereNull('removed_at')
            ->whereRaw('LENGTH(normalized) >= ?', [self::CODE_MIN_LENGTH])
            ->groupBy('normalized')
            ->havingRaw('COUNT(DISTINCT product_id) > 1')
            ->havingRaw('SUM(CASE WHEN type = ? THEN 1 ELSE 0 END) > 0', [ProductIdentifier::TYPE_MANUFACTURER_CODE])
            ->pluck('normalized')
            ->map(static fn ($v): string => (string) $v)
            ->all();
    }

    /**
     * Wszystkie aktywne kody producenta (≥ 5 znaków) — do porównania z remote_sku powiązań kont producentów — z kartą,
     * na której kod jest jedyny (0 — kod jest na kilku kartach). Powiązanie tej jedynej karty pary nie da (karta-cel
     * to inna karta niż źródło z tym kodem), a to zwykły przypadek: u producenta remote_sku to jego własny kod
     * producenta. Bez tego sita przebieg ładował niemal każdą kartę producenta (produkcja 25.09.2026: 25 tys. kodów
     * z powiązań, 10,8 tys. kart, ponad 128 MB), choć kod na innej karcie miało nieco ponad 400 z nich.
     *
     * @return array<string, int>
     */
    private function manufacturerCodeValues(): array
    {
        $out = [];
        foreach (ProductIdentifier::query()->toBase()
            ->selectRaw('normalized, MIN(product_id) AS product_id, COUNT(DISTINCT product_id) AS cards')
            ->where('type', ProductIdentifier::TYPE_MANUFACTURER_CODE)
            ->whereNotNull('normalized')
            ->whereNull('removed_at')
            ->whereRaw('LENGTH(normalized) >= ?', [self::CODE_MIN_LENGTH])
            ->groupBy('normalized')
            ->cursor() as $row) {
            $out[(string) $row->normalized] = (int) $row->cards > 1 ? 0 : (int) $row->product_id;
        }

        return $out;
    }

    /**
     * Powiązania B2B, których remote_sku po normalizacji jest jednym z podanych kodów (Anro: remote_sku „IF/016/F/PS”
     * to kod producenta). Kolumna remote_sku nie ma postaci znormalizowanej, więc odczyt strumieniem po id.
     * Czy konto jest właścicielem karty, rozstrzyga później CardOwnership — tu tylko wstępne sito.
     *
     * @param  array<string, int>  $codes  kod => jedyna karta z tym kodem producenta, której własne powiązania nic nie
     *                                     dadzą (0 — bez pomijania)
     * @param  string|null  $brand  tylko karty tej marki kanonicznej (evaluate jednej karty)
     * @return array<string, list<array{product: int, account: int}>>
     */
    private function linksWithManufacturerCodeSku(array $codes, ?string $brand): array
    {
        if ($codes === [] || $brand === '') {
            return [];
        }
        $query = B2bProductLink::query()->toBase()
            ->select(['b2b_product_links.id', 'b2b_product_links.product_id', 'b2b_product_links.b2b_account_id', 'b2b_product_links.remote_sku'])
            ->whereNotNull('b2b_product_links.remote_sku');
        if ($brand !== null) {
            $manufacturers = $this->manufacturersOfBrand($brand);
            if ($manufacturers === []) {
                return [];
            }
            $query->join('products', 'products.id', '=', 'b2b_product_links.product_id')
                ->whereIn('products.manufacturer', $manufacturers);
        }

        $out = [];
        foreach ($query->lazyById(5000, 'b2b_product_links.id', 'id') as $link) {
            $code = ProductIdentifierCode::code((string) $link->remote_sku);
            if ($code === null || strlen($code) < self::CODE_MIN_LENGTH || ! isset($codes[$code])) {
                continue;
            }
            // karta trafia własnym kodem sama w siebie — nie ma drugiej karty z tym kodem producenta
            if ($codes[$code] === (int) $link->product_id) {
                continue;
            }
            $out[$code][] = ['product' => (int) $link->product_id, 'account' => (int) $link->b2b_account_id];
        }

        return $out;
    }

    /**
     * Nazwy producentów z katalogu, które sprowadzają się do tej marki (słownik marek: „PELTOR” → 3M).
     *
     * @return list<string>
     */
    private function manufacturersOfBrand(string $brand): array
    {
        $out = [];
        foreach (Product::query()->toBase()->whereNotNull('manufacturer')->distinct()->pluck('manufacturer') as $name) {
            if ($this->brandKey((string) $name) === $brand) {
                $out[] = (string) $name;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $eanValues
     * @param  list<string>  $codeValues
     * @param  array<string, list<array{product: int, account: int}>>  $linkHits
     * @return array<int, array<string, mixed>> karta-źródło => wynik
     */
    private function analyze(array $eanValues, array $codeValues, array $linkHits, ?int $onlySource): array
    {
        $eanRows = $this->identifierRows([ProductIdentifier::TYPE_EAN], $eanValues);
        $codeRows = $this->identifierRows(self::TARGET_CODE_TYPES, $codeValues);

        $ids = $onlySource !== null ? [$onlySource => true] : [];
        foreach ([$eanRows, $codeRows] as $rows) {
            foreach ($rows as $list) {
                foreach ($list as $row) {
                    $ids[$row['product']] = true;
                }
            }
        }
        foreach ($linkHits as $list) {
            foreach ($list as $hit) {
                $ids[$hit['product']] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $this->loadCards(array_keys($ids));

        $candidates = $onlySource !== null ? [$onlySource] : array_keys($this->cards);
        $sources = array_values(array_filter($candidates, fn (int $id): bool => $this->isSource($id)));
        if ($sources === []) {
            return [];
        }
        $identifiers = $this->sourceIdentifiers($sources);
        $specialPrices = $this->specialPriceCounts($sources);

        $hits = [];
        $planSources = [];
        $planTargets = [];
        $planKeyPositions = [];
        foreach ($sources as $sourceId) {
            $hit = $this->collectHits($sourceId, $identifiers[$sourceId] ?? [], $eanRows, $codeRows, $linkHits);
            if ($hit === null) {
                continue;
            }
            $hits[$sourceId] = $hit;
            if (count($hit['targets']) > 1) {
                $planSources[] = $sourceId;
                $planTargets += $hit['targets'];
                $planKeyPositions += $hit['positions'];
            }
        }
        // dane planów hurtem — liczba zapytań nie zależy od liczby propozycji
        if ($planSources !== []) {
            $this->loadPlanData($planSources, array_map('intval', array_keys($planTargets)), array_map('strval', array_keys($planKeyPositions)));
        }

        $out = [];
        foreach ($hits as $sourceId => $hit) {
            $out[$sourceId] = count($hit['targets']) > 1
                ? $this->evaluatePlan($sourceId, $hit)
                : $this->evaluateSource($sourceId, $hit, $specialPrices[$sourceId] ?? 0);
        }

        return $out;
    }

    /**
     * @param  list<string>  $types
     * @param  list<string>  $values
     * @return array<string, list<array{product: int, source_key: string, type: string}>>
     */
    private function identifierRows(array $types, array $values): array
    {
        $out = [];
        foreach (array_chunk($values, self::CHUNK) as $chunk) {
            $rows = ProductIdentifier::query()->toBase()
                ->whereIn('type', $types)
                ->whereIn('normalized', $chunk)
                ->whereNull('removed_at')
                ->get(['product_id', 'source_key', 'type', 'normalized']);
            foreach ($rows as $row) {
                $out[(string) $row->normalized][] = [
                    'product' => (int) $row->product_id,
                    'source_key' => (string) $row->source_key,
                    'type' => (string) $row->type,
                ];
            }
        }

        return $out;
    }

    /**
     * Klucze kart-źródeł: aktywne EAN-y sztuki i kody producenta, z pozycją (źródło + pozycja) — pozycja to rozmiar
     * albo wersja u dostawcy, liczona raz niezależnie od liczby jej kodów.
     *
     * @param  list<int>  $productIds
     * @return array<int, list<array{position: string, type: string, normalized: string, source_key: string}>>
     */
    private function sourceIdentifiers(array $productIds): array
    {
        $out = [];
        foreach (array_chunk($productIds, self::CHUNK) as $chunk) {
            $rows = ProductIdentifier::query()->toBase()
                ->whereIn('product_id', $chunk)
                ->whereIn('type', [ProductIdentifier::TYPE_EAN, ProductIdentifier::TYPE_MANUFACTURER_CODE])
                ->whereNotNull('normalized')
                ->whereNull('removed_at')
                ->orderBy('id')
                ->get(['product_id', 'source_key', 'position_key', 'type', 'normalized']);
            foreach ($rows as $row) {
                $type = (string) $row->type;
                $normalized = (string) $row->normalized;
                $usable = $type === ProductIdentifier::TYPE_EAN
                    ? self::eligibleEan($normalized)
                    : strlen($normalized) >= self::CODE_MIN_LENGTH;
                if (! $usable) {
                    continue;
                }
                $out[(int) $row->product_id][] = [
                    'position' => $row->source_key."\n".$row->position_key,
                    'type' => $type,
                    'normalized' => $normalized,
                    'source_key' => (string) $row->source_key,
                ];
            }
        }

        return $out;
    }

    /**
     * Karty, powiązania, wersje i sloty z pliku dla wszystkich kart przebiegu — hurtem, po 1000.
     *
     * @param  list<int>  $ids
     */
    private function loadCards(array $ids): void
    {
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach (Product::query()->whereIn('id', $chunk)->get(['id', 'sku', 'name', 'manufacturer']) as $card) {
                $this->cards[(int) $card->id] = $card;
            }
            foreach (B2bProductLink::query()->toBase()->whereIn('product_id', $chunk)->orderBy('id')
                ->get(['product_id', 'b2b_account_id', 'remote_id', 'remote_sku']) as $link) {
                $this->links[(int) $link->product_id][] = [
                    'account' => (int) $link->b2b_account_id,
                    'remote_id' => (string) $link->remote_id,
                    'remote_sku' => $link->remote_sku !== null ? (string) $link->remote_sku : null,
                ];
            }
            foreach (DB::table('product_variants')->whereIn('product_id', $chunk)
                ->selectRaw('product_id, COUNT(*) AS total, SUM(CASE WHEN removed_at IS NULL THEN 1 ELSE 0 END) AS active')
                ->groupBy('product_id')->get() as $row) {
                $this->allVariants[(int) $row->product_id] = (int) $row->total;
                $this->activeVariants[(int) $row->product_id] = (int) $row->active;
            }
            foreach (ProductSourcePrice::query()->toBase()->whereIn('product_id', $chunk)
                ->where('source_key', ProductSourcePrice::SOURCE_FILE)
                ->get(['product_id', 'price_list_id']) as $slot) {
                $this->fileSlots[(int) $slot->product_id] = $slot->price_list_id !== null ? (int) $slot->price_list_id : null;
            }
        }
        $this->accounts = B2bAccount::query()->get()->keyBy('id');
    }

    /**
     * Ceny specjalne kart-źródeł — scalenie ich nie przenosi (products:merge-duplicate odmawia). Akcesoria scalenie
     * przenosi (ProductSizeMergeService::moveOwnAccessories) — nie są już powodem niepewnej propozycji.
     *
     * @param  list<int>  $ids
     * @return array<int, int>
     */
    private function specialPriceCounts(array $ids): array
    {
        $counts = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            foreach (DB::table('product_special_prices')->whereIn('product_id', $chunk)
                ->selectRaw('product_id, COUNT(*) AS n')->groupBy('product_id')->get() as $row) {
                $counts[(int) $row->product_id] = (int) $row->n;
            }
        }

        return $counts;
    }

    /**
     * Dane planów (karty dystrybutora z kluczami w kilku kartach producenta) — hurtem, po 1000: karty docelowe z liczbą
     * zdjęć i opisem, ich pełne sloty cen, liczniki blokad obu stron, pozycje z pliku i etykiety pozycji, nazwy cenników.
     *
     * @param  list<int>  $sourceIds
     * @param  list<int>  $targetIds
     * @param  list<string>  $keyPositions  pozycje z kluczami („źródło\npozycja”; identyfikator bywa bez powiązania na karcie)
     */
    private function loadPlanData(array $sourceIds, array $targetIds, array $keyPositions): void
    {
        foreach (array_chunk($targetIds, self::CHUNK) as $chunk) {
            foreach (Product::query()->withCount('images')->whereIn('id', $chunk)
                ->get(['id', 'sku', 'name', 'manufacturer', 'description', 'enrichment_status']) as $card) {
                $this->keeperCards[(int) $card->id] = $card;
            }
            foreach (ProductSourcePrice::query()->toBase()->whereIn('product_id', $chunk)->orderBy('id')
                ->get(['product_id', 'source_key', 'price_list_id', 'purchase_price', 'catalog_price_net', 'currency']) as $slot) {
                $this->slots[(int) $slot->product_id][] = [
                    'source_key' => (string) $slot->source_key,
                    'price_list_id' => $slot->price_list_id !== null ? (int) $slot->price_list_id : null,
                    'purchase_price' => self::money($slot->purchase_price),
                    'catalog_price_net' => self::money($slot->catalog_price_net),
                    'currency' => strtoupper(trim((string) $slot->currency)),
                ];
            }
        }

        $counters = [
            'special' => ['product_special_prices', 'product_id'],
            'accessory' => ['product_accessories', 'product_id'],
            'accessory_related' => ['product_accessories', 'related_product_id'],
            'tender_main' => ['tender_items', 'main_product_id'],
            'tender_companion' => ['tender_items', 'companion_product_id'],
            'presta' => ['presta_product_matches', 'product_id'],
            'substitute_main' => ['product_substitutes', 'main_product_id'],
            'substitute_of' => ['product_substitutes', 'substitute_product_id'],
        ];
        $ids = array_values(array_unique([...$sourceIds, ...$targetIds]));
        foreach ($counters as $name => [$table, $column]) {
            $this->planCounts[$name] = [];
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                foreach (DB::table($table)->whereIn($column, $chunk)
                    ->selectRaw($column.' AS product_id, COUNT(*) AS n')->groupBy($column)->get() as $row) {
                    $this->planCounts[$name][(int) $row->product_id] = (int) $row->n;
                }
            }
        }

        // pozycje z pliku (aktywne identyfikatory) i etykiety pozycji: pierwsza niepusta wg id, dowolny typ
        $listIds = [];
        $b2bKeys = [];
        $b2bPositions = [];
        foreach ($sourceIds as $sourceId) {
            foreach ($this->links[$sourceId] ?? [] as $link) {
                $b2bKeys[ProductSourcePrice::b2bKey($link['account'])] = true;
                $b2bPositions[$link['remote_id']] = true;
            }
        }
        foreach ($keyPositions as $key) {
            [$sourceKey, $positionKey] = explode("\n", $key, 2) + [1 => ''];
            if (str_starts_with($sourceKey, 'b2b:')) {
                $b2bKeys[$sourceKey] = true;
                $b2bPositions[$positionKey] = true;
            }
        }
        foreach (array_chunk($sourceIds, self::CHUNK) as $chunk) {
            $seen = [];
            foreach (ProductIdentifier::query()->toBase()->whereIn('product_id', $chunk)
                ->where('source_key', 'like', 'file:%')->orderBy('id')
                ->get(['product_id', 'source_key', 'position_key', 'variant_label', 'removed_at']) as $row) {
                $key = $row->source_key."\n".$row->position_key;
                if ($row->removed_at === null && ! isset($seen[(int) $row->product_id][$key])) {
                    $seen[(int) $row->product_id][$key] = true;
                    $this->filePositions[(int) $row->product_id][] = ['source_key' => (string) $row->source_key, 'position_key' => (string) $row->position_key];
                    $listIds[(int) substr((string) $row->source_key, 5)] = true;
                }
                $this->rememberLabel($key, $row->variant_label);
            }
        }
        if ($b2bKeys !== []) {
            foreach (array_chunk(array_map('strval', array_keys($b2bPositions)), self::CHUNK) as $chunk) {
                foreach (ProductIdentifier::query()->toBase()
                    ->whereIn('source_key', array_keys($b2bKeys))
                    ->whereIn('position_key', $chunk)
                    ->whereNotNull('variant_label')
                    ->orderBy('id')
                    ->get(['source_key', 'position_key', 'variant_label']) as $row) {
                    $this->rememberLabel($row->source_key."\n".$row->position_key, $row->variant_label);
                }
            }
        }

        foreach ($this->slots as $slots) {
            foreach ($slots as $slot) {
                if ($slot['price_list_id'] !== null) {
                    $listIds[$slot['price_list_id']] = true;
                }
            }
        }
        foreach (array_chunk(array_keys($listIds), self::CHUNK) as $chunk) {
            foreach (DB::table('price_lists')->whereIn('id', $chunk)->get(['id', 'manufacturer']) as $list) {
                $this->priceListNames[(int) $list->id] = (string) $list->manufacturer;
            }
        }
    }

    private function rememberLabel(string $key, mixed $label): void
    {
        $label = trim((string) $label);
        if ($label !== '' && ! isset($this->positionLabels[$key])) {
            $this->positionLabels[$key] = $label;
        }
    }

    private static function money(mixed $value): ?string
    {
        return $value !== null && $value !== '' ? number_format(round((float) $value, 2), 2, '.', '') : null;
    }

    /**
     * Karta dystrybutora: powiązania B2B tylko od kont nie-właścicieli, bez właściciela i bez aktywnych wersji.
     * Karta bez powiązań B2B (założona z pliku) jest poza zakresem planu.
     */
    private function isSource(int $id): bool
    {
        $card = $this->cards[$id] ?? null;
        $links = $this->links[$id] ?? [];
        if ($card === null || $links === [] || ($this->activeVariants[$id] ?? 0) > 0) {
            return false;
        }
        foreach ($links as $link) {
            $account = $this->accounts?->get($link['account']);
            if ($account === null || $this->ownership->isOwnerAccount($card, $account)) {
                return false;
            }
        }

        return $this->ownerKeys($id) === [];
    }

    /** @return list<string> */
    private function ownerKeys(int $id): array
    {
        if (! isset($this->ownerKeys[$id])) {
            $card = $this->cards[$id] ?? null;
            $this->ownerKeys[$id] = $card !== null ? $this->ownership->ownerSourceKeys($card) : [];
        }

        return $this->ownerKeys[$id];
    }

    /**
     * Identyfikator karty-celu pochodzi od jej właściciela: konto producenta albo cennik producenta, którego slot
     * jest na karcie (identyfikatory pliku mają source_key „file:{cennik}”, właściciel — „file”).
     */
    private function isOwnerIdentifier(int $targetId, string $sourceKey): bool
    {
        $keys = $this->ownerKeys($targetId);
        if (in_array($sourceKey, $keys, true)) {
            return true;
        }
        $listId = $this->fileSlots[$targetId] ?? null;

        return $listId !== null
            && in_array(ProductSourcePrice::SOURCE_FILE, $keys, true)
            && $sourceKey === ProductIdentifierStore::fileKey($listId);
    }

    /**
     * Trafienia kluczy karty-źródła w karty producenta: na kartę (pozycje, EAN-y, kody) i na pozycję (karta => klucze).
     *
     * @param  list<array{position: string, type: string, normalized: string, source_key: string}>  $identifiers
     * @param  array<string, list<array{product: int, source_key: string, type: string}>>  $eanRows
     * @param  array<string, list<array{product: int, source_key: string, type: string}>>  $codeRows
     * @param  array<string, list<array{product: int, account: int}>>  $linkHits
     * @return array{targets: array<int, array{positions: array<string, true>, ean: array<string, string>, code: array<string, string>}>, positions: array<string, true>, by_position: array<string, array<int, array{ean: array<string, string>, code: array<string, string>}>>}|null
     */
    private function collectHits(int $sourceId, array $identifiers, array $eanRows, array $codeRows, array $linkHits): ?array
    {
        $sourceBrand = $this->brandKey($this->cards[$sourceId]->manufacturer);

        $positions = [];
        $targets = [];
        $byPosition = [];
        $hit = function (int $targetId, string $position, string $by, string $value, string $sourceKey) use (&$targets, &$byPosition): void {
            $targets[$targetId] ??= ['positions' => [], 'ean' => [], 'code' => []];
            $targets[$targetId]['positions'][$position] = true;
            $targets[$targetId][$by][$value] ??= $sourceKey;
            $byPosition[$position][$targetId] ??= ['ean' => [], 'code' => []];
            $byPosition[$position][$targetId][$by][$value] ??= $sourceKey;
        };

        foreach ($identifiers as $row) {
            $positions[$row['position']] = true;
            $value = $row['normalized'];
            if ($row['type'] === ProductIdentifier::TYPE_EAN) {
                foreach ($eanRows[$value] ?? [] as $other) {
                    if ($this->isTarget($other['product'], $sourceId) && $this->isOwnerIdentifier($other['product'], $other['source_key'])) {
                        $hit($other['product'], $row['position'], 'ean', $value, $other['source_key']);
                    }
                }

                continue;
            }
            // kod producenta — tylko w tej samej marce kanonicznej
            foreach ($codeRows[$value] ?? [] as $other) {
                if ($this->isTarget($other['product'], $sourceId)
                    && $this->sameBrand($sourceBrand, $other['product'])
                    && $this->isOwnerIdentifier($other['product'], $other['source_key'])) {
                    $hit($other['product'], $row['position'], 'code', $value, $other['source_key']);
                }
            }
            foreach ($linkHits[$value] ?? [] as $link) {
                $key = ProductSourcePrice::b2bKey($link['account']);
                if ($this->isTarget($link['product'], $sourceId)
                    && $this->sameBrand($sourceBrand, $link['product'])
                    && in_array($key, $this->ownerKeys($link['product']), true)) {
                    $hit($link['product'], $row['position'], 'code', $value, $key);
                }
            }
        }

        if ($targets === []) {
            return null;
        }
        ksort($targets);

        return ['targets' => $targets, 'positions' => $positions, 'by_position' => $byPosition];
    }

    /**
     * Klucze wskazują jedną kartę producenta — połączenie dwóch kart (kind=merge, reguły sprzed kroku 5).
     *
     * @param  array{targets: array<int, array{positions: array<string, true>, ean: array<string, string>, code: array<string, string>}>, positions: array<string, true>, by_position: array<string, mixed>}  $hits
     * @return array<string, mixed>
     */
    private function evaluateSource(int $sourceId, array $hits, int $specialPrices): array
    {
        $targetId = (int) array_key_first($hits['targets']);
        $target = $hits['targets'][$targetId];
        $reasons = $this->conflictReasons($sourceId, $targetId, $target['ean'] !== [], $specialPrices);

        return [
            'status' => $reasons === [] ? CardMatchCandidate::STATUS_PENDING : CardMatchCandidate::STATUS_CONFLICT,
            'target_product_id' => $targetId,
            ...self::matchedKey($target['ean'], $target['code']),
            'brand' => $this->brandKey($this->cards[$targetId]->manufacturer),
            'hits' => count($target['positions']),
            'positions' => count($hits['positions']),
            'reason' => $reasons === [] ? null : mb_substr(implode('; ', $reasons), 0, self::REASON_MAX),
            'conflict_product_ids' => null,
            'kind' => CardMatchCandidate::KIND_MERGE,
            'plan' => null,
            'plan_hash' => null,
            'targets_key' => (string) $targetId,
        ];
    }

    /**
     * Klucze wskazują kilka kart producenta — plan „pozycja → karta”: łączenie rozmiarów (size_merge) albo
     * rozdzielanie (split). Pending tylko przy poprawnym planie, bez blokad i z pewnym sygnałem; inaczej conflict
     * z powodami (plan.blockers, reason).
     *
     * @param  array{targets: array<int, array{positions: array<string, true>, ean: array<string, string>, code: array<string, string>}>, positions: array<string, true>, by_position: array<string, array<int, array{ean: array<string, string>, code: array<string, string>}>>}  $hits
     * @return array<string, mixed>
     */
    private function evaluatePlan(int $sourceId, array $hits): array
    {
        $source = $this->cards[$sourceId];
        $sourceBrand = $this->brandKey($source->manufacturer);
        $ids = array_map('intval', array_keys($hits['targets']));
        sort($ids);
        $producer = trim((string) $this->cards[$ids[0]]->manufacturer);

        $nameSignals = [];
        foreach ($this->signals->nameSignals(array_map(fn (int $id): string => (string) $this->cards[$id]->name, $ids), $producer) as $i => $signal) {
            $nameSignals[$ids[$i]] = $signal;
        }

        $blockers = [];
        $positions = [];
        $keepsSeparate = [];
        foreach ($this->planPositions($sourceId, $hits) as $position) {
            $key = $position['source_key']."\n".$position['position_key'];
            $byTarget = $hits['by_position'][$key] ?? [];
            ksort($byTarget);
            $targetIds = array_map('intval', array_keys($byTarget));
            $code = $position['remote_sku'] ?? $position['position_key'];
            $targetId = count($targetIds) === 1 ? $targetIds[0] : null;
            $ean = [];
            $codes = [];
            foreach ($byTarget as $keys) {
                $ean += $keys['ean'];
                $codes += $keys['code'];
            }
            $matched = $byTarget !== [] ? self::matchedKey($ean, $codes) : null;
            if ($targetIds === []) {
                $blockers[] = isset($hits['positions'][$key])
                    ? ['code' => 'position_missing', 'text' => 'pozycja '.$code.' nie trafia w żadną kartę producenta']
                    : ['code' => 'position_no_key', 'text' => 'pozycja '.$code.' nie ma EAN-u ani kodu producenta'];
            } elseif ($targetId === null) {
                $blockers[] = ['code' => 'position_ambiguous', 'text' => 'pozycja '.$code.' wskazuje kilka kart producenta ('
                    .implode(', ', array_map(static fn (int $id): string => '#'.$id, $targetIds)).')'];
            }

            $sourceLabel = $this->sourceLabel($position['source_key']);
            $label = $this->positionLabels[$key] ?? null;
            $labelSignal = $this->signals->labelSignal($label, $sourceLabel);
            $nameSignal = $targetId !== null ? $nameSignals[$targetId] : null;
            $separate = $targetId !== null ? $this->producerKeepingSizesSeparate($targetId) : null;
            if ($separate !== null) {
                $keepsSeparate[$separate] = true;
            }
            $signal = CardMatchCandidate::SIGNAL_UNKNOWN;
            if ($labelSignal['signal'] === CardMatchCandidate::SIGNAL_COLOR || ($nameSignal['signal'] ?? null) === CardMatchCandidate::SIGNAL_COLOR) {
                $signal = CardMatchCandidate::SIGNAL_COLOR;
            } elseif ($labelSignal['signal'] === CardMatchCandidate::SIGNAL_SIZE
                && ($nameSignal['signal'] ?? null) === CardMatchCandidate::SIGNAL_SIZE
                && $separate === null) {
                $signal = CardMatchCandidate::SIGNAL_SIZE;
            }
            $why = [$labelSignal['why']];
            if ($nameSignal !== null) {
                $why[] = $nameSignal['why'];
            }
            if ($separate !== null) {
                $why[] = 'producent ('.$separate.') sam łączy rozmiary, a te kody trzyma osobno';
            }

            $positions[] = [
                'source_key' => $position['source_key'],
                'source_label' => $sourceLabel,
                'position_key' => $position['position_key'],
                'remote_sku' => $position['remote_sku'],
                'label' => $label,
                'size_label' => $this->signals->sizeLabel($label),
                'target_product_id' => $targetId,
                'target_ids' => count($targetIds) > 1 ? $targetIds : null,
                'matched_by' => $matched['matched_by'] ?? null,
                'matched_value' => $matched['matched_value'] ?? null,
                'signal' => $signal,
                'signal_why' => implode('; ', $why),
            ];
        }

        $single = array_values(array_unique(array_filter(array_column($positions, 'target_product_id'), static fn ($id): bool => $id !== null)));
        $valid = $positions !== [] && count($single) >= 2
            && count(array_filter($positions, static fn (array $p): bool => $p['target_product_id'] === null)) === 0;
        $signalValues = array_column($positions, 'signal');
        $signal = match (true) {
            in_array(CardMatchCandidate::SIGNAL_COLOR, $signalValues, true) => CardMatchCandidate::SIGNAL_COLOR,
            $signalValues !== [] && array_unique($signalValues) === [CardMatchCandidate::SIGNAL_SIZE] => CardMatchCandidate::SIGNAL_SIZE,
            default => CardMatchCandidate::SIGNAL_UNKNOWN,
        };
        $sameOwner = $this->sameOwner($ids);
        $priceDifferences = $this->priceDifferences($ids);
        $equalPrices = $priceDifferences === [];
        $kind = $valid && $signal === CardMatchCandidate::SIGNAL_SIZE && $sameOwner && $equalPrices
            ? CardMatchCandidate::KIND_SIZE_MERGE
            : CardMatchCandidate::KIND_SPLIT;

        array_push($blockers, ...$this->planBlockers($sourceId, $ids, $kind));
        if ($kind === CardMatchCandidate::KIND_SPLIT) {
            // pozycję z pliku import po rozdzieleniu zapisałby do jedynego slotu „file” karty producenta — nadpisałby
            // cenę z pliku producenta (PriceListImportService czyta mapę file:{cennik})
            foreach ($positions as $position) {
                if (! str_starts_with($position['source_key'], 'b2b:')) {
                    $blockers[] = ['code' => 'split_file_position', 'text' => 'pozycja '.($position['remote_sku'] ?? $position['position_key'])
                        .' pochodzi z pliku ('.$position['source_label'].') — rozdzielenie przenosi tylko pozycje kont B2B'];
                }
            }
        }
        if ($kind === CardMatchCandidate::KIND_SPLIT && $signal === CardMatchCandidate::SIGNAL_UNKNOWN && $equalPrices) {
            $text = 'nie wiadomo, czy pozycje różnią się rozmiarem czy kolorem';
            foreach (array_keys($keepsSeparate) as $account) {
                $text .= '; producent ('.$account.') sam łączy rozmiary, a te kody trzyma osobno — to nie są rozmiary';
            }
            $blockers[] = ['code' => 'unknown_signal', 'text' => $text];
        }
        $decided = $kind === CardMatchCandidate::KIND_SIZE_MERGE
            || $signal === CardMatchCandidate::SIGNAL_COLOR
            || ! $equalPrices
            || ($signal === CardMatchCandidate::SIGNAL_SIZE && ! $sameOwner);
        $status = $valid && $blockers === [] && $decided ? CardMatchCandidate::STATUS_PENDING : CardMatchCandidate::STATUS_CONFLICT;

        $ean = [];
        $code = [];
        $hitPositions = [];
        foreach ($hits['targets'] as $target) {
            $ean += $target['ean'];
            $code += $target['code'];
            $hitPositions += $target['positions'];
        }
        $planKeys = array_map(static fn (array $p): array => [$p['source_key'], $p['position_key'], $p['target_product_id']], $positions);
        usort($planKeys, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return [
            'status' => $status,
            'target_product_id' => null,
            ...self::matchedKey($ean, $code),
            'brand' => $sourceBrand,
            'hits' => count($hitPositions),
            'positions' => count($positions),
            'reason' => $blockers === [] ? null : mb_substr(implode('; ', array_column($blockers, 'text')), 0, self::REASON_MAX),
            'conflict_product_ids' => $ids,
            'kind' => $kind,
            'plan' => [
                'version' => self::PLAN_VERSION,
                'signal' => $signal,
                'source_label' => $this->distributorLabel($sourceId, $positions),
                'same_owner' => $sameOwner,
                'equal_prices' => $equalPrices,
                'price_differences' => $priceDifferences,
                'blockers' => $blockers,
                'positions' => $positions,
                'suggested' => $kind === CardMatchCandidate::KIND_SIZE_MERGE ? $this->suggested($positions) : null,
            ],
            'plan_hash' => sha1((string) json_encode([$kind, $planKeys])),
            'targets_key' => CardMatchCandidate::targetsKeyFor($ids),
        ];
    }

    /**
     * Pozycje karty dystrybutora: wszystkie powiązania B2B (każde konto), pozycje jej identyfikatorów z pliku i pozycje
     * z kluczami (identyfikator bez powiązania na tej karcie — też musi trafić). Kolejność naturalna wg kodu pozycji
     * u dystrybutora, potem źródło i pozycja.
     *
     * @param  array{positions: array<string, true>}  $hits
     * @return list<array{source_key: string, position_key: string, remote_sku: string|null}>
     */
    private function planPositions(int $sourceId, array $hits): array
    {
        $out = [];
        foreach ($this->links[$sourceId] ?? [] as $link) {
            $sourceKey = ProductSourcePrice::b2bKey($link['account']);
            $out[$sourceKey."\n".$link['remote_id']] ??= [
                'source_key' => $sourceKey, 'position_key' => $link['remote_id'], 'remote_sku' => $link['remote_sku'],
            ];
        }
        foreach ($this->filePositions[$sourceId] ?? [] as $position) {
            $out[$position['source_key']."\n".$position['position_key']] ??= [...$position, 'remote_sku' => null];
        }
        foreach (array_keys($hits['positions']) as $key) {
            [$sourceKey, $positionKey] = explode("\n", (string) $key, 2);
            $out[$key] ??= ['source_key' => $sourceKey, 'position_key' => $positionKey, 'remote_sku' => null];
        }
        $out = array_values($out);
        usort($out, static fn (array $a, array $b): int => strnatcasecmp($a['remote_sku'] ?? $a['position_key'], $b['remote_sku'] ?? $b['position_key'])
            ?: strcmp($a['source_key'], $b['source_key'])
            ?: strcmp($a['position_key'], $b['position_key']));

        return $out;
    }

    /**
     * Blokady planu po stronie kart (A6): karty producenta (marka, wersje, pozycja tego konta), karta dystrybutora
     * (ceny specjalne, wycofane wersje, slot pliku; akcesoria tylko przy rozdzielaniu) i zależne od rodzaju.
     *
     * @param  list<int>  $ids
     * @return list<array{code: string, text: string}>
     */
    private function planBlockers(int $sourceId, array $ids, string $kind): array
    {
        $source = $this->cards[$sourceId];
        $sourceBrand = $this->brandKey($source->manufacturer);
        $out = [];
        foreach ($ids as $id) {
            $target = $this->cards[$id];
            if (! $this->sameBrand($sourceBrand, $id)) {
                $out[] = ['code' => 'target_brand', 'text' => 'karta producenta #'.$id.' jest innej marki ('
                    .trim((string) $source->manufacturer).' / '.trim((string) $target->manufacturer).')'];
            }
            if (($this->activeVariants[$id] ?? 0) > 0) {
                $out[] = ['code' => 'target_variants', 'text' => 'karta producenta #'.$id.' ma wersje ('.$this->activeVariants[$id]
                    .') — łączenie kart z wersjami wyłączone'];
            }
            $targetPositions = [];
            foreach ($this->links[$id] ?? [] as $link) {
                $targetPositions[$link['account']][$link['remote_id']] = true;
            }
            foreach ($this->links[$sourceId] ?? [] as $link) {
                $other = array_diff_key($targetPositions[$link['account']] ?? [], [$link['remote_id'] => true]);
                if ($other !== []) {
                    $out[] = ['code' => 'target_account_position', 'text' => 'karta producenta #'.$id.' ma już pozycję tego konta ('
                        .$this->labels->accountLabel($this->accounts?->get($link['account'])).': '.implode(', ', array_keys($other)).')'];
                    break;
                }
            }
        }

        $count = fn (string $counter, int $id): int => $this->planCounts[$counter][$id] ?? 0;
        if (($n = $count('special', $sourceId)) > 0) {
            $out[] = ['code' => 'source_special_prices', 'text' => 'karta dystrybutora ma ceny specjalne ('.$n.') — plan ich nie przenosi'];
        }
        // łączenie rozmiarów dołącza kartę dystrybutora przez mergeDuplicate, które akcesoria przenosi; rozdzielanie nie
        // ma dla nich jednej karty docelowej (CardMatchSplitter odmawia)
        if ($kind === CardMatchCandidate::KIND_SPLIT
            && ($n = $count('accessory', $sourceId) + $count('accessory_related', $sourceId)) > 0) {
            $out[] = ['code' => 'source_accessories', 'text' => 'karta dystrybutora ma akcesoria albo jest akcesorium innej karty ('.$n.') — plan ich nie przenosi'];
        }
        if (($this->allVariants[$sourceId] ?? 0) > 0) {
            $out[] = ['code' => 'source_retired_variants', 'text' => 'karta dystrybutora ma wycofane wersje ('.$this->allVariants[$sourceId].') — plan ich nie przenosi'];
        }
        if (array_key_exists($sourceId, $this->fileSlots)) {
            $out[] = ['code' => 'source_file_slot', 'text' => 'karta dystrybutora ma cenę z pliku — plan jej nie przenosi'];
        }

        if ($kind === CardMatchCandidate::KIND_SIZE_MERGE) {
            foreach ($ids as $id) {
                if (($n = $count('tender_main', $id) + $count('tender_companion', $id)) > 0) {
                    $out[] = ['code' => 'target_tender', 'text' => 'karta producenta #'.$id.' jest w pozycjach przetargów ('.$n.')'];
                }
                if (($n = $count('presta', $id)) > 0) {
                    $out[] = ['code' => 'target_presta', 'text' => 'karta producenta #'.$id.' jest powiązana z Prestą ('.$n.')'];
                }
                if (($n = $count('special', $id)) > 0) {
                    $out[] = ['code' => 'target_special_prices', 'text' => 'karta producenta #'.$id.' ma ceny specjalne ('.$n.')'];
                }
                if (($n = $count('accessory', $id) + $count('accessory_related', $id)) > 0) {
                    $out[] = ['code' => 'target_accessories', 'text' => 'karta producenta #'.$id.' ma akcesoria albo jest akcesorium innej karty ('.$n.')'];
                }
            }
        } else {
            if (($n = $count('tender_main', $sourceId) + $count('tender_companion', $sourceId)) > 0) {
                $out[] = ['code' => 'source_tender', 'text' => 'karta dystrybutora jest w pozycjach przetargów ('.$n.')'];
            }
            if (($n = $count('substitute_main', $sourceId) + $count('substitute_of', $sourceId)) > 0) {
                $out[] = ['code' => 'source_substitutes', 'text' => 'karta dystrybutora ma zamienniki albo jest zamiennikiem ('.$n.')'];
            }
            if (($n = $count('presta', $sourceId)) > 0) {
                $out[] = ['code' => 'source_presta', 'text' => 'karta dystrybutora jest powiązana z Prestą ('.$n.')'];
            }
        }

        return $out;
    }

    /**
     * Ci sami właściciele na wszystkich kartach producenta (CardOwnership) — dla cennika z pliku także ten sam cennik.
     *
     * @param  list<int>  $ids
     */
    private function sameOwner(array $ids): bool
    {
        $first = null;
        foreach ($ids as $id) {
            $keys = $this->ownerKeys($id);
            sort($keys);
            if ($keys === []) {
                return false;
            }
            $signature = [$keys, in_array(ProductSourcePrice::SOURCE_FILE, $keys, true) ? ($this->fileSlots[$id] ?? null) : null];
            $first ??= $signature;
            if ($signature !== $first) {
                return false;
            }
        }

        return true;
    }

    /**
     * Źródła ceny obecne na co najmniej dwóch kartach producenta z różną ceną (zakup, katalogowa po zaokrągleniu do
     * grosza, waluta). [] = te same ceny.
     *
     * @param  list<int>  $ids
     * @return list<array{source_key: string, label: string, values: list<array{product_id: int, purchase_price: string|null, currency: string|null}>}>
     */
    private function priceDifferences(array $ids): array
    {
        $bySource = [];
        foreach ($ids as $id) {
            foreach ($this->slots[$id] ?? [] as $slot) {
                $bySource[$slot['source_key']][$id] ??= $slot;
            }
        }
        ksort($bySource, SORT_STRING);
        $out = [];
        foreach ($bySource as $sourceKey => $slots) {
            if (count($slots) < 2) {
                continue;
            }
            $signatures = array_map(static fn (array $slot): string => $slot['purchase_price'].'|'.$slot['catalog_price_net'].'|'.$slot['currency'], $slots);
            if (count(array_unique($signatures)) === 1) {
                continue;
            }
            $first = reset($slots);
            $out[] = [
                'source_key' => (string) $sourceKey,
                'label' => $sourceKey === ProductSourcePrice::SOURCE_FILE
                    ? $this->priceListLabel($first['price_list_id'])
                    : $this->sourceLabel((string) $sourceKey),
                'values' => array_values(array_map(static fn (int $id, array $slot): array => [
                    'product_id' => $id,
                    'purchase_price' => $slot['purchase_price'],
                    'currency' => $slot['currency'] !== '' ? $slot['currency'] : null,
                ], array_keys($slots), $slots)),
            ];
        }

        return $out;
    }

    /**
     * Warunek 3 sygnału: właściciel karty producenta to konto, którego łącznik sam łączy rozmiary tej samej ceny
     * (B2bGroupsSizes) — skoro trzyma te kody osobno, to nie są rozmiary. Etykieta konta albo null.
     */
    private function producerKeepingSizesSeparate(int $targetId): ?string
    {
        foreach ($this->ownerKeys($targetId) as $key) {
            if (! str_starts_with($key, 'b2b:')) {
                continue;
            }
            $account = $this->accounts?->get((int) substr($key, 4));
            if ($account !== null && $this->connectors->groupsSizes($this->connectors->keyForAccount($account))) {
                return $this->labels->accountLabel($account);
            }
        }

        return null;
    }

    /**
     * Podpowiedź łączenia rozmiarów (do odczytu — decyzja w kolejnym kroku): karta, która zostaje (reguła łączenia
     * rozmiarów, karty w kolejności pozycji planu), wspólny początek nazw i kody rozmiarów.
     *
     * @param  list<array<string, mixed>>  $positions
     * @return array{keep_product_id: int, common_name: string|null, sizes: list<array{product_id: int, label: string|null, code: string}>}
     */
    private function suggested(array $positions): array
    {
        $sizes = [];
        foreach ($positions as $position) {
            $id = (int) $position['target_product_id'];
            $sizes[$id] ??= ['product_id' => $id, 'label' => $position['size_label'], 'code' => (string) $position['matched_value']];
        }
        $cards = array_values(array_filter(array_map(fn (int $id): ?Product => $this->keeperCards[$id] ?? null, array_keys($sizes))));
        $keep = $cards !== [] ? (int) $this->sizeMerge->preferredKeeper($cards)->id : (int) array_key_first($sizes);

        return [
            'keep_product_id' => $keep,
            'common_name' => $this->signals->commonName(array_map(fn (int $id): string => (string) $this->cards[$id]->name, array_keys($sizes))),
            'sizes' => array_values($sizes),
        ];
    }

    /** Nazwa źródła pozycji: konto B2B jak w porównaniu cen, cennik z pliku — „cennik {producent}”. */
    private function sourceLabel(string $sourceKey): string
    {
        if (str_starts_with($sourceKey, 'b2b:')) {
            return $this->labels->accountLabel($this->accounts?->get((int) substr($sourceKey, 4)));
        }
        if (str_starts_with($sourceKey, 'file:')) {
            return $this->priceListLabel((int) substr($sourceKey, 5));
        }

        return $sourceKey;
    }

    private function priceListLabel(?int $priceListId): string
    {
        $name = $priceListId !== null ? trim($this->priceListNames[$priceListId] ?? '') : '';

        return $name !== '' ? 'cennik '.$name : 'cennik #'.($priceListId ?? '—');
    }

    /**
     * Dystrybutor w zdaniach ekranu: konto pierwszego powiązania karty (wg id), bez powiązań — źródło pierwszej pozycji.
     *
     * @param  list<array<string, mixed>>  $positions
     */
    private function distributorLabel(int $sourceId, array $positions): string
    {
        $link = $this->links[$sourceId][0] ?? null;
        if ($link !== null) {
            return $this->sourceLabel(ProductSourcePrice::b2bKey($link['account']));
        }

        return (string) ($positions[0]['source_label'] ?? '');
    }

    /**
     * Para wskazuje jedną kartę, ale scalenie byłoby niebezpieczne — wtedy tylko do wglądu, z powodem.
     *
     * @return list<string>
     */
    private function conflictReasons(int $sourceId, int $targetId, bool $byEan, int $specialPrices): array
    {
        $source = $this->cards[$sourceId];
        $target = $this->cards[$targetId];
        $reasons = [];
        // kod trafia tylko w tej samej marce; EAN — w każdej, a inna marka to sygnał błędu danych, nie ten sam wyrób
        if ($byEan && ! $this->sameBrand($this->brandKey($source->manufacturer), $targetId)) {
            $reasons[] = 'EAN wskazuje kartę innej marki ('.trim((string) $source->manufacturer).' / '.trim((string) $target->manufacturer).')';
        }
        if (($this->activeVariants[$targetId] ?? 0) > 0) {
            $reasons[] = 'karta producenta ma wersje ('.$this->activeVariants[$targetId].') — łączenie kart z wersjami wyłączone';
        }
        $targetPositions = [];
        foreach ($this->links[$targetId] ?? [] as $link) {
            $targetPositions[$link['account']][$link['remote_id']] = true;
        }
        foreach ($this->links[$sourceId] ?? [] as $link) {
            $other = array_diff_key($targetPositions[$link['account']] ?? [], [$link['remote_id'] => true]);
            if ($other !== []) {
                $reasons[] = 'karta producenta ma już pozycję tego konta ('
                    .$this->labels->accountLabel($this->accounts?->get($link['account'])).': '.implode(', ', array_keys($other))
                    .') — sztuka czy karton?';
                break;
            }
        }
        if (array_key_exists($sourceId, $this->fileSlots) && array_key_exists($targetId, $this->fileSlots)) {
            $reasons[] = 'obie karty mają cenę z pliku — starsza by zginęła';
        }
        if ($specialPrices > 0) {
            $reasons[] = 'karta dystrybutora ma ceny specjalne ('.$specialPrices.') — scalenie ich nie przenosi';
        }
        // aktywne wersje wykluczają kartę ze źródeł; wycofane skasowałaby kaskada razem z kartą
        if (($this->allVariants[$sourceId] ?? 0) > 0) {
            $reasons[] = 'karta dystrybutora ma wycofane wersje ('.$this->allVariants[$sourceId].') — scalenie ich nie przenosi';
        }

        return $reasons;
    }

    /**
     * EAN ma pierwszeństwo przed kodem; z kilku wartości najmniejsza — ten sam wynik przy każdym przebiegu.
     *
     * @param  array<string, string>  $ean  wartość => źródło-właściciel
     * @param  array<string, string>  $code
     * @return array{matched_by: string, matched_value: string, matched_source_key: string}
     */
    private static function matchedKey(array $ean, array $code): array
    {
        if ($ean !== []) {
            ksort($ean, SORT_STRING);
            $value = (string) array_key_first($ean);

            // EAN-13 do pokazania (klucz porównania ma 14 cyfr z zerem z przodu)
            return ['matched_by' => CardMatchCandidate::BY_EAN, 'matched_value' => substr($value, 1), 'matched_source_key' => $ean[$value]];
        }
        ksort($code, SORT_STRING);
        $value = (string) array_key_first($code);

        return ['matched_by' => CardMatchCandidate::BY_MANUFACTURER_CODE, 'matched_value' => $value, 'matched_source_key' => $code[$value]];
    }

    /** Karta-cel: inna niż źródło i chroniona (ma właściciela). */
    private function isTarget(int $id, int $sourceId): bool
    {
        return $id !== $sourceId && isset($this->cards[$id]) && $this->ownerKeys($id) !== [];
    }

    private function sameBrand(string $sourceBrand, int $targetId): bool
    {
        return $sourceBrand !== '' && $sourceBrand === $this->brandKey($this->cards[$targetId]->manufacturer);
    }

    private function brandKey(?string $manufacturer): string
    {
        $name = (string) $manufacturer;

        return $this->brandKeys[$name] ??= CanonicalBrand::key($name);
    }

    /**
     * EAN sztuki nadający się na klucz: 14 cyfr z zerem z przodu (GTIN-14 ze wskaźnikiem 1–9 to karton albo waga
     * zmienna) i prefiks EAN-13 spoza obiegu wewnętrznego, kuponów i wydawnictw.
     */
    private static function eligibleEan(string $normalized): bool
    {
        return strlen($normalized) === 14
            && $normalized[0] === '0'
            && preg_match(self::EAN_EXCLUDED_PREFIXES, substr($normalized, 1)) !== 1;
    }

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array{pending: int, conflict: int, removed: int, refreshed_at: string, by_kind: array<string, array{pending: int, conflict: int}>, signals: array<string, int>}
     */
    private function store(array $results): array
    {
        $now = now();

        return DB::transaction(function () use ($results, $now): array {
            $blocked = [];
            $active = [];
            foreach (CardMatchCandidate::query()->get() as $row) {
                $targetsKey = self::rowTargetsKey($row);
                $key = self::pairKey((int) $row->source_product_id, $targetsKey);
                if (in_array($row->status, [CardMatchCandidate::STATUS_REJECTED, CardMatchCandidate::STATUS_MERGED], true)) {
                    // odrzucona para, której karta-cel zniknęła (klucz obcy wyzerował target), nie blokuje konfliktu
                    // „kilka kart producenta” tej karty — to już inna para; odrzucenie obowiązuje zestaw kart
                    // niezależnie od rodzaju propozycji
                    if (! str_starts_with($targetsKey, 'gone:')) {
                        $blocked[$key] = true;
                    }
                } else {
                    $active[$key] = $row;
                }
            }

            $counts = [CardMatchCandidate::STATUS_PENDING => 0, CardMatchCandidate::STATUS_CONFLICT => 0];
            $byKind = array_fill_keys(CardMatchCandidate::KINDS, $counts);
            $signals = array_fill_keys(CardMatchCandidate::SIGNALS, 0);
            $touched = [];
            foreach ($results as $sourceId => $result) {
                $key = self::pairKey($sourceId, (string) $result['targets_key']);
                // odrzucona (albo już połączona) para nie wraca
                if (isset($blocked[$key])) {
                    continue;
                }
                $card = $this->cards[$sourceId];
                $attributes = [
                    'source_product_id' => $sourceId,
                    ...$result,
                    'source_snapshot' => [
                        'sku' => (string) $card->sku,
                        'name' => (string) $card->name,
                        'manufacturer' => (string) $card->manufacturer,
                    ],
                ];
                $row = $active[$key] ?? new CardMatchCandidate;
                $row->fill($attributes);
                if (! $row->exists || $row->isDirty()) {
                    $row->last_seen_at = $now;
                    $row->save();
                }
                $touched[(int) $row->id] = true;
                $counts[$result['status']]++;
                $byKind[$result['kind']][$result['status']]++;
                if ($result['kind'] !== CardMatchCandidate::KIND_MERGE) {
                    $signals[$result['plan']['signal'] ?? CardMatchCandidate::SIGNAL_UNKNOWN]++;
                }
            }
            foreach (array_chunk(array_keys($touched), self::CHUNK) as $chunk) {
                CardMatchCandidate::query()->toBase()->whereIn('id', $chunk)->update(['last_seen_at' => $now]);
            }

            $gone = [];
            foreach ($active as $row) {
                if (! isset($touched[(int) $row->id])) {
                    $gone[] = (int) $row->id;
                }
            }
            foreach (array_chunk($gone, self::CHUNK) as $chunk) {
                CardMatchCandidate::query()->whereIn('id', $chunk)->delete();
            }

            return [
                'pending' => $counts[CardMatchCandidate::STATUS_PENDING],
                'conflict' => $counts[CardMatchCandidate::STATUS_CONFLICT],
                'removed' => count($gone),
                'refreshed_at' => $now->toIso8601String(),
                'by_kind' => $byKind,
                'signals' => $signals,
            ];
        });
    }

    private static function pairKey(int $sourceId, string $targetsKey): string
    {
        return $sourceId.'|'.$targetsKey;
    }

    /**
     * targets_key wiersza; pusty (wiersz zapisany z pominięciem modelu) — ta sama reguła co migracja i model.
     */
    private static function rowTargetsKey(CardMatchCandidate $row): string
    {
        $key = (string) $row->targets_key;
        if ($key !== '') {
            return $key;
        }
        if ($row->target_product_id !== null) {
            return (string) $row->target_product_id;
        }
        $ids = is_array($row->conflict_product_ids) ? $row->conflict_product_ids : [];

        return $ids !== [] ? CardMatchCandidate::targetsKeyFor($ids) : 'gone:'.$row->id;
    }
}
