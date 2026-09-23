<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\CardMatchCandidate;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductSourcePrice;
use App\Services\Pricing\SourcePriceComparison;
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

    public function __construct(
        private readonly CardOwnership $ownership,
        private readonly SourcePriceComparison $labels,
    ) {}

    /**
     * Przelicza propozycje: upsert pending/conflict, usuwa pending/conflict, które już nie pasują; rejected i merged
     * nietknięte (odrzucona para nie wraca).
     *
     * @return array{pending: int, conflict: int, removed: int, refreshed_at: string}
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
     * Ten sam wynik co refresh() dla tej karty.
     *
     * @return array{status: string, target_product_id: int|null, matched_by: string, matched_value: string, matched_source_key: string, brand: string, hits: int, positions: int, reason: string|null, conflict_product_ids: list<int>|null}|null
     */
    public function evaluate(Product $source): ?array
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
            ? $this->linksWithManufacturerCodeSku(array_fill_keys($codeValues, true), $this->brandKey($current->manufacturer))
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
     * Wszystkie aktywne kody producenta (≥ 5 znaków) — do porównania z remote_sku powiązań kont producentów.
     *
     * @return array<string, true>
     */
    private function manufacturerCodeValues(): array
    {
        $out = [];
        foreach (ProductIdentifier::query()->toBase()
            ->where('type', ProductIdentifier::TYPE_MANUFACTURER_CODE)
            ->whereNotNull('normalized')
            ->whereNull('removed_at')
            ->whereRaw('LENGTH(normalized) >= ?', [self::CODE_MIN_LENGTH])
            ->distinct()
            ->pluck('normalized') as $value) {
            $out[(string) $value] = true;
        }

        return $out;
    }

    /**
     * Powiązania B2B, których remote_sku po normalizacji jest jednym z podanych kodów (Anro: remote_sku „IF/016/F/PS”
     * to kod producenta). Kolumna remote_sku nie ma postaci znormalizowanej, więc odczyt strumieniem po id.
     * Czy konto jest właścicielem karty, rozstrzyga później CardOwnership — tu tylko wstępne sito.
     *
     * @param  array<string, true>  $codes
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
        [$specialPrices, $accessories] = $this->blockingCounts($sources);

        $out = [];
        foreach ($sources as $sourceId) {
            $result = $this->evaluateSource(
                $sourceId,
                $identifiers[$sourceId] ?? [],
                $eanRows,
                $codeRows,
                $linkHits,
                $specialPrices[$sourceId] ?? 0,
                $accessories[$sourceId] ?? 0,
            );
            if ($result !== null) {
                $out[$sourceId] = $result;
            }
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
     * Ceny specjalne i akcesoria kart-źródeł — scalenie ich nie przenosi (products:merge-duplicate odmawia).
     *
     * @param  list<int>  $ids
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function blockingCounts(array $ids): array
    {
        $counts = [[], []];
        foreach (['product_special_prices', 'product_accessories'] as $i => $table) {
            foreach (array_chunk($ids, self::CHUNK) as $chunk) {
                foreach (DB::table($table)->whereIn('product_id', $chunk)
                    ->selectRaw('product_id, COUNT(*) AS n')->groupBy('product_id')->get() as $row) {
                    $counts[$i][(int) $row->product_id] = (int) $row->n;
                }
            }
        }

        return $counts;
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
     * @param  list<array{position: string, type: string, normalized: string, source_key: string}>  $identifiers
     * @param  array<string, list<array{product: int, source_key: string, type: string}>>  $eanRows
     * @param  array<string, list<array{product: int, source_key: string, type: string}>>  $codeRows
     * @param  array<string, list<array{product: int, account: int}>>  $linkHits
     * @return array<string, mixed>|null
     */
    private function evaluateSource(
        int $sourceId,
        array $identifiers,
        array $eanRows,
        array $codeRows,
        array $linkHits,
        int $specialPrices,
        int $accessories,
    ): ?array {
        $source = $this->cards[$sourceId];
        $sourceBrand = $this->brandKey($source->manufacturer);

        $positions = [];
        /** @var array<int, array{positions: array<string, true>, ean: array<string, string>, code: array<string, string>}> $targets */
        $targets = [];
        $hit = function (int $targetId, string $position, string $by, string $value, string $sourceKey) use (&$targets): void {
            $targets[$targetId] ??= ['positions' => [], 'ean' => [], 'code' => []];
            $targets[$targetId]['positions'][$position] = true;
            $targets[$targetId][$by][$value] ??= $sourceKey;
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

        if (count($targets) > 1) {
            $ean = [];
            $code = [];
            $hitPositions = [];
            foreach ($targets as $target) {
                $ean += $target['ean'];
                $code += $target['code'];
                $hitPositions += $target['positions'];
            }
            $ids = array_keys($targets);

            return [
                'status' => CardMatchCandidate::STATUS_CONFLICT,
                'target_product_id' => null,
                ...self::matchedKey($ean, $code),
                'brand' => $sourceBrand,
                'hits' => count($hitPositions),
                'positions' => count($positions),
                'reason' => 'Klucze wskazują kilka kart producenta ('
                    .implode(', ', array_map(static fn (int $id): string => '#'.$id, $ids)).') — nie wiadomo, która zostaje.',
                'conflict_product_ids' => $ids,
            ];
        }

        $targetId = (int) array_key_first($targets);
        $target = $targets[$targetId];
        $reasons = $this->conflictReasons($sourceId, $targetId, $target['ean'] !== [], $specialPrices, $accessories);

        return [
            'status' => $reasons === [] ? CardMatchCandidate::STATUS_PENDING : CardMatchCandidate::STATUS_CONFLICT,
            'target_product_id' => $targetId,
            ...self::matchedKey($target['ean'], $target['code']),
            'brand' => $this->brandKey($this->cards[$targetId]->manufacturer),
            'hits' => count($target['positions']),
            'positions' => count($positions),
            'reason' => $reasons === [] ? null : mb_substr(implode('; ', $reasons), 0, 500),
            'conflict_product_ids' => null,
        ];
    }

    /**
     * Para wskazuje jedną kartę, ale scalenie byłoby niebezpieczne — wtedy tylko do wglądu, z powodem.
     *
     * @return list<string>
     */
    private function conflictReasons(int $sourceId, int $targetId, bool $byEan, int $specialPrices, int $accessories): array
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
        if ($accessories > 0) {
            $reasons[] = 'karta dystrybutora ma akcesoria ('.$accessories.') — scalenie ich nie przenosi';
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
     * @return array{pending: int, conflict: int, removed: int, refreshed_at: string}
     */
    private function store(array $results): array
    {
        $now = now();

        return DB::transaction(function () use ($results, $now): array {
            $blocked = [];
            $active = [];
            foreach (CardMatchCandidate::query()->get() as $row) {
                $key = self::pairKey((int) $row->source_product_id, $row->target_product_id);
                if (in_array($row->status, [CardMatchCandidate::STATUS_REJECTED, CardMatchCandidate::STATUS_MERGED], true)) {
                    // odrzucona para, której karta-cel zniknęła (klucz obcy wyzerował target), nie blokuje konfliktu
                    // „kilka kart producenta” tej karty — to już inna para
                    if ($row->target_product_id !== null || $row->conflict_product_ids !== null) {
                        $blocked[$key] = true;
                    }
                } else {
                    $active[$key] = $row;
                }
            }

            $counts = [CardMatchCandidate::STATUS_PENDING => 0, CardMatchCandidate::STATUS_CONFLICT => 0];
            $touched = [];
            foreach ($results as $sourceId => $result) {
                $key = self::pairKey($sourceId, $result['target_product_id']);
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
            ];
        });
    }

    private static function pairKey(int $sourceId, mixed $targetId): string
    {
        return $sourceId.'|'.($targetId !== null ? (int) $targetId : '');
    }
}
