<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ErpItem;
use App\Models\ErpRwPwPair;
use App\Models\ErpWarehouse;
use App\Services\Erp\ErpItemCards;
use App\Services\Erp\InventoryQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Raport zapasów dla zarządu (decyzje użytkownika 30.09.2026): jedna strona, gotowe progi, same fakty — bez zaleceń;
 * przy „odmładzaniu” towaru (pary RW → PW) imiona i nazwiska z kontekstem. Każda liczba prowadzi do listy w oknie
 * (items, moves). Magazyny handlowe / usługowe / wszystkie (słownik erp_warehouses). Wiek towaru tylko dla towaru bez
 * sprzedaży ponad rok (recenzja: kwoty wieku całego zapasu wprowadzały w błąd). Reguły jak lista „Zalegające”
 * (InventoryQuery). Niczego nie zapisuje.
 */
class InventoryBoardController extends Controller
{
    /** [rodzaj, miesiące, tytuł okna] — kafelki i okna liczą z tych samych definicji. */
    private const BUCKETS = [
        'stock' => ['stock', 0, 'Cały towar w magazynach'],
        'no_sale_6' => ['no_sale', 6, 'Towar, który nie sprzedaje się od pół roku'],
        'no_sale_12' => ['no_sale', 12, 'Towar, który nie sprzedaje się ponad rok'],
        'no_sale_24' => ['no_sale', 24, 'Towar, który nie sprzedaje się ponad 2 lata'],
        'never_sold' => ['never', 6, 'Towar, który nie sprzedał się ani razu'],
        'stale_36' => ['stale', 36, 'Towar bez sprzedaży ponad rok, który leży w magazynie ponad 3 lata'],
        'stale_60' => ['stale', 60, 'Towar bez sprzedaży ponad rok, który leży w magazynie ponad 5 lat'],
    ];

    /** Rodzaje asortymentu po pierwszej literze kodu XL (jak ekran Powiązania z ERP XL). */
    private const GROUPS = ['A' => 'Odzież', 'B' => 'Obuwie', 'S' => 'Sprzęt ochronny', 'T' => 'Techniczne', 'H' => 'Higiena'];

    private const SCOPE_LABELS = ['trade' => 'magazyny handlowe', 'service' => 'magazyny usługowe', 'all' => 'wszystkie magazyny'];

    private const PER_PAGE = [10, 20, 50, 100];

    private const TOP_UNSOLD_LIMIT = 5;

    /** „Odmładzanie”: pary z 12 miesięcy, PW do 3 dni po RW — jak domyślne filtry zakładki RW → PW. */
    private const MOVES_MONTHS = 12;

    private const MOVES_GAP_DAYS = 3;

    /**
     * Tylko towar, który przed wydaniem RW leżał co najmniej tyle miesięcy (decyzja właściciela 30.09.2026): szukamy
     * „rozmydlania” zalegających zapasów nową dostawą z PW; świeży towar nie ma czego ukrywać. Para bez znanego wieku
     * partii się nie liczy.
     */
    private const MOVES_MIN_LOT_AGE_MONTHS = 3;

    private const PEOPLE_LIMIT = 5;

    public function __construct(private readonly ErpItemCards $cards) {}

    public function show(Request $request): JsonResponse
    {
        $scope = $this->scope($request);
        $totals = fn (string $bucket, ?string $group = null): array => InventoryQuery::totals($this->bucketQuery($bucket, $scope, $group), $scope);
        $stock = $totals('stock');
        $never = $totals('never_sold');
        $trade = InventoryQuery::totals(InventoryQuery::inStock('trade'), 'trade');
        $service = InventoryQuery::totals(InventoryQuery::inStock('service'), 'service');
        $lot12 = InventoryQuery::totals(InventoryQuery::lotOlderThan(InventoryQuery::inStock($scope), $this->ago(12), $scope), $scope);
        $syncedAt = ErpItem::query()->whereNull('removed_at')->max('synced_at');

        $groups = [];
        foreach ([...array_keys(self::GROUPS), 'other'] as $group) {
            $unsold = $totals('no_sale_12', $group);
            $groups[] = [
                'group' => $group,
                'label' => self::GROUPS[$group] ?? 'Pozostałe',
                'unsold_items' => $unsold['items'],
                'unsold_value' => $unsold['value'],
                'stock_value' => $totals('stock', $group)['value'],
            ];
        }
        usort($groups, static fn (array $a, array $b): int => $b['unsold_value'] <=> $a['unsold_value']);

        return response()->json([
            'as_of' => $syncedAt !== null ? Carbon::parse((string) $syncedAt)->toIso8601String() : null,
            'warehouses' => $scope,
            'split' => [
                'trade' => ['items' => $trade['items'], 'value' => $trade['value']],
                'service' => ['items' => $service['items'], 'value' => $service['value']],
            ],
            'stock' => ['items' => $stock['items'], 'value' => $stock['value']],
            'no_sale' => array_map(function (int $months) use ($totals): array {
                $t = $totals('no_sale_'.$months);

                return ['months' => $months, 'items' => $t['items'], 'value' => $t['value']];
            }, [6, 12, 24]),
            'never_sold' => ['items' => $never['items'], 'value' => $never['value']],
            'stale_lot' => array_map(function (int $months) use ($totals): array {
                $t = $totals('stale_'.$months);

                return ['months' => $months, 'items' => $t['items'], 'value' => $t['value']];
            }, [36, 60]),
            'lot_12_total' => ['items' => $lot12['items'], 'value' => $lot12['value']],
            'groups' => $groups,
            'top_unsold' => array_map(
                static fn (array $r): array => array_intersect_key($r, array_flip(['code', 'name', 'quantity', 'unit', 'value', 'last_sale_at', 'card_name'])),
                $this->itemRows($this->bucketQuery('no_sale_12', $scope)->limit(self::TOP_UNSOLD_LIMIT), $scope, valuedOnly: true),
            ),
            'internal_moves' => $this->movesSummary($scope),
            'value_unknown' => $stock['value_unknown'],
        ]);
    }

    /** Okno z listą towarów koszyka: od największej wartości, 10/20/50/100 na stronę. */
    public function items(Request $request): JsonResponse
    {
        $v = $request->validate([
            'bucket' => ['required', 'string', Rule::in(array_keys(self::BUCKETS))],
            'group' => ['nullable', 'string', Rule::in([...array_keys(self::GROUPS), 'other'])],
            'per_page' => ['nullable', 'integer', Rule::in(self::PER_PAGE)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $scope = $this->scope($request);
        $key = (string) $v['bucket'];
        $group = isset($v['group']) && $v['group'] !== '' ? (string) $v['group'] : null;
        $query = $this->bucketQuery($key, $scope, $group);
        $totals = InventoryQuery::totals($query, $scope);
        $page = $this->ordered($query, $scope)->paginate((int) ($v['per_page'] ?? 10));

        $title = self::BUCKETS[$key][2];
        if ($group !== null) {
            $title .= ' — '.mb_strtolower(self::GROUPS[$group] ?? 'Pozostałe');
        }

        return response()->json([
            'bucket' => $key,
            'group' => $group,
            'warehouses' => $scope,
            'title' => $title.' ('.self::SCOPE_LABELS[$scope].')',
            'data' => $this->itemRows(null, $scope, $page->getCollection()),
            'meta' => $this->meta($page->currentPage(), $page->lastPage(), $page->perPage(), $page->total()),
            'totals' => ['items' => $totals['items'], 'value' => $totals['value']],
        ]);
    }

    /** Okno z dokumentami „odmładzania”: wszystkie albo bez wyjaśnienia, opcjonalnie jednej osoby; od najnowszego. */
    public function moves(Request $request): JsonResponse
    {
        $v = $request->validate([
            'scope' => ['nullable', 'string', Rule::in(['all', 'unexplained'])],
            'operator' => ['nullable', 'string', 'max:20'],
            'per_page' => ['nullable', 'integer', Rule::in(self::PER_PAGE)],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $warehouses = $this->scope($request);
        $kind = (string) ($v['scope'] ?? 'unexplained');
        $operator = trim((string) ($v['operator'] ?? ''));
        $query = $kind === 'all' ? $this->pairs($warehouses) : $this->unexplained($warehouses);
        $operatorName = null;
        if ($operator !== '') {
            $query->where('rw_operator', $operator);
            $operatorName = ErpRwPwPair::query()->where('rw_operator', $operator)->whereNotNull('rw_operator_name')->value('rw_operator_name');
        }
        $totalPairs = (clone $query)->count();
        $totalValue = round((float) (clone $query)->sum('rw_value'), 2);

        $page = $query->orderByDesc('rw_date')->orderByDesc('id')->paginate((int) ($v['per_page'] ?? 10));
        $items = ErpItem::query()->whereIn('id', $page->getCollection()->pluck('erp_item_id')->filter()->unique()->all())
            ->with(ErpItemCards::eagerLinks())
            ->get(['id', 'code', 'name', 'unit']);
        $cards = $this->cards->forItems($items);
        $items = $items->keyBy('id');

        $title = $kind === 'all' ? 'Towar wydany i od razu przyjęty z powrotem' : 'Towar wydany i od razu przyjęty z powrotem bez opisu';
        if ($operator !== '') {
            $title = 'Dokumenty wystawione przez: '.($operatorName ?? $operator);
        }

        return response()->json([
            'scope' => $kind,
            'warehouses' => $warehouses,
            'operator' => $operator !== '' ? $operator : null,
            'operator_name' => $operatorName,
            'min_lot_age_months' => self::MOVES_MIN_LOT_AGE_MONTHS,
            'title' => $title.' ('.self::SCOPE_LABELS[$warehouses].')',
            'data' => $page->getCollection()->map(function (ErpRwPwPair $p) use ($items, $cards): array {
                $item = $p->erp_item_id !== null ? $items->get($p->erp_item_id) : null;

                return [
                    'rw_number' => $p->rw_number,
                    'rw_date' => $p->rw_date?->toDateString(),
                    'pw_number' => $p->pw_number,
                    'pw_date' => $p->pw_date?->toDateString(),
                    'item_code' => $item !== null ? (string) $item->code : 'XL #'.$p->xl_gid,
                    'item_name' => $item !== null ? (string) $item->name : '',
                    'card_name' => $item !== null ? ($cards[(int) $item->id]['card']['name'] ?? null) : null,
                    'quantity' => (float) $p->rw_quantity,
                    'unit' => $item?->unit,
                    'value' => (float) $p->rw_value,
                    'lot_age_months' => $p->rw_lot_age_months,
                    'lot_received_at' => $p->rw_lot_at?->toDateString(),
                    'rw_features' => $p->rw_features,
                    'pw_features' => $p->pw_features,
                    'same_feature' => $p->same_feature,
                    'rw_note' => $p->rw_note,
                    'pw_note' => $p->pw_note,
                    'operator_name' => $p->rw_operator_name ?? $p->rw_operator,
                    'approver_name' => $p->rw_approver_name ?? $p->rw_approver,
                ];
            })->values()->all(),
            'meta' => $this->meta($page->currentPage(), $page->lastPage(), $page->perPage(), $page->total()),
            'totals' => ['pairs' => $totalPairs, 'value' => $totalValue],
        ]);
    }

    private function scope(Request $request): string
    {
        $v = $request->validate(['warehouses' => ['nullable', 'string', Rule::in(InventoryQuery::SCOPES)]]);

        return (string) ($v['warehouses'] ?? 'trade');
    }

    private function ago(int $months): CarbonImmutable
    {
        return CarbonImmutable::today()->subMonthsNoOverflow($months);
    }

    /**
     * Towar koszyka w wybranych magazynach. Nigdy niesprzedany liczy się dopiero, gdy leży dłużej niż pół roku — świeża
     * dostawa nowego towaru to nie zaleganie.
     *
     * @return Builder<ErpItem>
     */
    private function bucketQuery(string $key, string $scope, ?string $group = null): Builder
    {
        [$kind, $months] = self::BUCKETS[$key];
        $query = InventoryQuery::inStock($scope);
        $lot = InventoryQuery::oldestLotSql($scope);
        match ($kind) {
            'no_sale' => InventoryQuery::unsoldSince($query, $this->ago($months), true, $scope),
            'stale' => InventoryQuery::lotOlderThan(InventoryQuery::unsoldSince($query, $this->ago(12), true, $scope), $this->ago($months), $scope),
            'never' => $query->whereNull('last_sale_at')
                ->where(fn (Builder $l) => $l->whereRaw($lot.' is null')->orWhereRaw($lot.' <= ?', [$this->ago($months)->toDateString()])),
            default => $query,
        };
        if ($group === 'other') {
            foreach (array_keys(self::GROUPS) as $letter) {
                $query->where('code', 'not like', $letter.'%');
            }
        } elseif ($group !== null) {
            $query->where('code', 'like', $group.'%');
        }

        return $query;
    }

    /**
     * @param  Builder<ErpItem>  $query
     * @return Builder<ErpItem> od największej wartości; bez wartości na końcu
     */
    private function ordered(Builder $query, string $scope): Builder
    {
        $value = InventoryQuery::valueSql($scope);

        return $query->select('erp_items.*')
            ->selectRaw($value.' as purchase_value')
            ->selectRaw(InventoryQuery::quantitySql($scope).' as scope_quantity')
            ->selectRaw(InventoryQuery::oldestLotSql($scope).' as scope_oldest_lot')
            ->orderByRaw($value.' is null')
            ->orderByRaw($value.' desc')
            ->orderBy('code')
            ->with(ErpItemCards::eagerLinks());
    }

    /**
     * @param  Builder<ErpItem>|null  $query  zapytanie do wykonania (z limitem) albo null, gdy podane są gotowe towary
     * @param  iterable<ErpItem>|null  $items
     * @return list<array<string, mixed>>
     */
    private function itemRows(?Builder $query, string $scope, ?iterable $items = null, bool $valuedOnly = false): array
    {
        if ($query !== null) {
            $query = $this->ordered($query, $scope);
            if ($valuedOnly) {
                $query->whereRaw(InventoryQuery::valueSql($scope).' is not null');
            }
            $items = $query->get();
        }
        $items = collect($items ?? []);
        $cards = $this->cards->forItems($items);

        return $items->map(function (ErpItem $item) use ($cards): array {
            $value = $item->getAttribute('purchase_value');
            $quantity = (float) $item->getAttribute('scope_quantity');

            return [
                'code' => $item->code,
                'name' => $item->name,
                // rodzaj asortymentu po pierwszej literze kodu XL
                'group_label' => self::GROUPS[mb_substr((string) $item->code, 0, 1)] ?? 'Pozostałe',
                'card_name' => $cards[(int) $item->id]['card']['name'] ?? null,
                'quantity' => round($quantity, 4),
                'unit' => $item->unit,
                'value' => $value !== null ? round((float) $value, 2) : null,
                'unit_cost' => $value !== null && $quantity > 0 ? round((float) $value / $quantity, 2) : null,
                'last_sale_at' => $item->last_sale_at?->toDateString(),
                'oldest_lot_at' => $item->getAttribute('scope_oldest_lot') !== null ? substr((string) $item->getAttribute('scope_oldest_lot'), 0, 10) : null,
                'last_supplier' => $item->last_supplier,
            ];
        })->values()->all();
    }

    /** @return Builder<ErpRwPwPair> pary z 12 mies., PW do 3 dni po RW, partia leżała 3+ mies., w wybranych magazynach (magazyn RW) */
    private function pairs(string $scope): Builder
    {
        $query = ErpRwPwPair::query()
            ->where('rw_date', '>=', $this->ago(self::MOVES_MONTHS)->toDateString())
            ->where('gap_days', '<=', self::MOVES_GAP_DAYS)
            ->where('rw_lot_age_months', '>=', self::MOVES_MIN_LOT_AGE_MONTHS);
        $service = ErpWarehouse::serviceCodes();
        if ($scope === 'service') {
            $query->whereIn('rw_warehouse', $service);
        } elseif ($scope === 'trade') {
            $query->where(fn (Builder $q) => $q->whereNull('rw_warehouse')->orWhereNotIn('rw_warehouse', $service));
        }

        return $query;
    }

    /** @return Builder<ErpRwPwPair> bez wyjaśnienia: ta sama cecha (rozmiar, kolor) i puste uwagi RW */
    private function unexplained(string $scope): Builder
    {
        return $this->pairs($scope)->where('same_feature', true)->whereNull('rw_note');
    }

    /** @return array<string, mixed> */
    private function movesSummary(string $scope): array
    {
        $people = $this->unexplained($scope)->toBase()
            ->selectRaw('rw_operator, max(rw_operator_name) as name, count(*) as c')
            ->groupBy('rw_operator')
            ->orderByDesc('c')
            ->orderBy('rw_operator')
            ->limit(self::PEOPLE_LIMIT)
            ->get();
        // kontekst: ile wszystkich takich wydań i przyjęć ta osoba wystawiła (większość to zamiany rozmiaru)
        $all = $people->isEmpty() ? collect() : $this->pairs($scope)->toBase()
            ->whereIn('rw_operator', $people->pluck('rw_operator')->filter()->all())
            ->selectRaw('rw_operator, count(*) as c')
            ->groupBy('rw_operator')
            ->pluck('c', 'rw_operator');

        return [
            'from' => $this->ago(self::MOVES_MONTHS)->toDateString(),
            'min_lot_age_months' => self::MOVES_MIN_LOT_AGE_MONTHS,
            'total' => $this->pairs($scope)->count(),
            'unexplained' => $this->unexplained($scope)->count(),
            'unexplained_value' => round((float) $this->unexplained($scope)->sum('rw_value'), 2),
            'people' => $people->map(static fn ($row): array => [
                'operator' => (string) ($row->rw_operator ?? ''),
                'name' => (string) ($row->name ?? $row->rw_operator ?? 'osoba nieznana'),
                'count' => (int) $row->c,
                'total' => (int) ($all[$row->rw_operator] ?? $row->c),
            ])->values()->all(),
        ];
    }

    /** @return array<string, int> */
    private function meta(int $current, int $last, int $perPage, int $total): array
    {
        return ['current_page' => $current, 'last_page' => $last, 'per_page' => $perPage, 'total' => $total];
    }
}
