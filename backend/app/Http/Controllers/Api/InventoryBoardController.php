<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ErpItem;
use App\Models\ErpItemLink;
use App\Models\ErpRwPwPair;
use App\Models\ErpWarehouse;
use App\Services\Erp\ErpItemCards;
use App\Services\Erp\InventoryBoardTotals;
use App\Services\Erp\InventoryQuery;
use App\Services\Erp\InventorySnapshots;
use App\Services\Erp\WarehouseLocations;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Raport zapasów dla zarządu (decyzje użytkownika 30.09.2026): jedna strona, gotowe progi, same fakty — bez zaleceń;
 * przy „odmładzaniu” towaru (pary RW → PW) imiona i nazwiska z kontekstem. Każda liczba prowadzi do listy w oknie
 * (items, moves). Magazyny handlowe / usługowe / wszystkie (słownik erp_warehouses). Wiek towaru tylko dla towaru bez
 * sprzedaży ponad rok (recenzja: kwoty wieku całego zapasu wprowadzały w błąd). Reguły jak lista „Zalegające”
 * (InventoryQuery). Niczego nie zapisuje.
 *
 * Oddział (parametr location, np. '01' Rzeszów — WarehouseLocations) zawęża każdą liczbę, okno i dokumenty RW → PW do
 * magazynów oddziału (razem z wyborem handlowe / usługowe / wszystkie), także ostatnią sprzedaż (dokumenty z magazynów
 * oddziału).
 */
class InventoryBoardController extends Controller
{
    /** Koszyki kafelków i przedziały „jak długo leży” — definicje w InventoryBoardTotals (wspólne z zapisem historii). */
    private const BUCKETS = InventoryBoardTotals::BUCKETS;

    private const LOT_AGES = InventoryBoardTotals::LOT_AGES;

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

    /** Historia zapasów: domyślny okres i najwięcej punktów dziennych (dłużej — punkt na tydzień). */
    private const HISTORY_DEFAULT_DAYS = 30;

    private const HISTORY_MAX_POINTS = 400;

    /** Koszyki pokazywane w historii. */
    private const HISTORY_BUCKETS = ['stock', 'no_sale_6', 'no_sale_12', 'no_sale_24', 'never_sold', 'stale_36', 'stale_60'];

    /** Sortowanie okien kliknięciem w nagłówek kolumny; bez parametru — od największej wartości / od najnowszego. */
    private const ITEM_SORTS = ['name', 'quantity', 'value', 'last_sale', 'oldest_lot'];

    private const MOVE_SORTS = ['date', 'name', 'operator', 'value', 'lot_age', 'note'];

    /** Pole wyszukiwania: każde słowo musi pasować do którejś kolumny; najwyżej tyle słów. */
    private const SEARCH_WORDS = 6;

    /**
     * Daty w oknach są pisane słownie („sierpień 2025”, „24 września 2026”), więc nazwa miesiąca (początek, bez
     * ogonków, od 3 liter) też szuka po dacie.
     */
    private const MONTH_NAMES = [
        '01' => ['styczen', 'stycznia'], '02' => ['luty', 'lutego'], '03' => ['marzec', 'marca'],
        '04' => ['kwiecien', 'kwietnia'], '05' => ['maj', 'maja'], '06' => ['czerwiec', 'czerwca'],
        '07' => ['lipiec', 'lipca'], '08' => ['sierpien', 'sierpnia'], '09' => ['wrzesien', 'wrzesnia'],
        '10' => ['pazdziernik', 'pazdziernika'], '11' => ['listopad', 'listopada'], '12' => ['grudzien', 'grudnia'],
    ];

    public function __construct(private readonly ErpItemCards $cards) {}

    public function show(Request $request): JsonResponse
    {
        $scope = $this->scope($request);
        $location = $this->location($request);
        $totals = fn (string $bucket, ?string $group = null): array => InventoryQuery::totals($this->bucketQuery($bucket, $scope, $group, $location), $scope, $location);
        $stock = $totals('stock');
        $never = $totals('never_sold');
        $trade = InventoryQuery::totals(InventoryQuery::inStock('trade', $location), 'trade', $location);
        $service = InventoryQuery::totals(InventoryQuery::inStock('service', $location), 'service', $location);
        $lot12 = InventoryQuery::totals(InventoryQuery::lotOlderThan(InventoryQuery::inStock($scope, $location), $this->ago(12), $scope, $location), $scope, $location);
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
            'location' => $location,
            'location_name' => $location !== null ? WarehouseLocations::name($location) : null,
            'locations' => WarehouseLocations::available(),
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
                $this->itemRows($this->bucketQuery('no_sale_12', $scope, null, $location)->limit(self::TOP_UNSOLD_LIMIT), $scope, valuedOnly: true, location: $location),
            ),
            'internal_moves' => $this->movesSummary($scope, $location),
            'lot_age' => InventoryBoardTotals::today()->lotAgeSummary($scope, $location),
            'value_unknown' => $stock['value_unknown'],
        ]);
    }

    /**
     * Okno z listą towarów koszyka: 10/20/50/100 na stronę, od największej wartości albo wg klikniętej kolumny, zawężane
     * polem wyszukiwania. `totals` — cały koszyk (jak kafelek), `found` — po wyszukiwaniu.
     */
    public function items(Request $request): JsonResponse
    {
        $v = $request->validate([
            'bucket' => ['required', 'string', Rule::in([...array_keys(self::BUCKETS), ...array_keys(self::LOT_AGES)])],
            'group' => ['nullable', 'string', Rule::in([...array_keys(self::GROUPS), 'other'])],
            'per_page' => ['nullable', 'integer', Rule::in(self::PER_PAGE)],
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:150'],
            'sort' => ['nullable', 'string', Rule::in(self::ITEM_SORTS)],
            'dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ]);
        $scope = $this->scope($request);
        $location = $this->location($request);
        $key = (string) $v['bucket'];
        $group = isset($v['group']) && $v['group'] !== '' ? (string) $v['group'] : null;
        $measures = $this->measures($scope, $location, $key);
        $query = $this->bucketQuery($key, $scope, $group, $location);
        $totals = InventoryQuery::totals($query, $scope, $location, $measures['value']);
        $words = $this->words($v['search'] ?? null);
        if ($words !== []) {
            $this->searchItems($query, $words, $measures);
        }
        $found = $words === [] ? $totals : InventoryQuery::totals($query, $scope, $location, $measures['value']);
        $sort = isset($v['sort']) ? (string) $v['sort'] : null;
        $page = $this->ordered($query, $measures, $sort, ($v['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc')
            ->paginate((int) ($v['per_page'] ?? 10));

        $title = (self::BUCKETS[$key] ?? self::LOT_AGES[$key])[2];
        if ($group !== null) {
            $title .= ' — '.mb_strtolower(self::GROUPS[$group] ?? 'Pozostałe');
        }

        return response()->json([
            'bucket' => $key,
            'group' => $group,
            'warehouses' => $scope,
            'location' => $location,
            'title' => $title.' ('.$this->scopeLabel($scope, $location).')',
            'data' => $this->itemRows(null, $scope, $page->getCollection(), location: $location),
            'meta' => $this->meta($page->currentPage(), $page->lastPage(), $page->perPage(), $page->total()),
            'totals' => ['items' => $totals['items'], 'value' => $totals['value']],
            'found' => ['items' => $found['items'], 'value' => $found['value']],
        ]);
    }

    /**
     * Okno z dokumentami „odmładzania”: wszystkie albo bez wyjaśnienia, opcjonalnie jednej osoby; od najnowszego albo
     * wg klikniętej kolumny, zawężane polem wyszukiwania. `totals` — cała lista, `found` — po wyszukiwaniu.
     */
    public function moves(Request $request): JsonResponse
    {
        $v = $request->validate([
            'scope' => ['nullable', 'string', Rule::in(['all', 'unexplained'])],
            'operator' => ['nullable', 'string', 'max:20'],
            'per_page' => ['nullable', 'integer', Rule::in(self::PER_PAGE)],
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:150'],
            'sort' => ['nullable', 'string', Rule::in(self::MOVE_SORTS)],
            'dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
        ]);
        $warehouses = $this->scope($request);
        $location = $this->location($request);
        $kind = (string) ($v['scope'] ?? 'unexplained');
        $operator = trim((string) ($v['operator'] ?? ''));
        $query = $kind === 'all' ? $this->pairs($warehouses, $location) : $this->unexplained($warehouses, $location);
        $operatorName = null;
        if ($operator !== '') {
            $query->where('rw_operator', $operator);
            $operatorName = ErpRwPwPair::query()->where('rw_operator', $operator)->whereNotNull('rw_operator_name')->value('rw_operator_name');
        }
        $totalPairs = (clone $query)->count();
        $totalValue = round((float) (clone $query)->sum('rw_value'), 2);
        $words = $this->words($v['search'] ?? null);
        if ($words !== []) {
            $this->searchMoves($query, $words);
        }
        $foundPairs = $words === [] ? $totalPairs : (clone $query)->count();
        $foundValue = $words === [] ? $totalValue : round((float) (clone $query)->sum('rw_value'), 2);

        $sort = isset($v['sort']) ? (string) $v['sort'] : null;
        $page = $this->orderedMoves($query, $sort, ($v['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc')
            ->paginate((int) ($v['per_page'] ?? 10));
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
            'location' => $location,
            'operator' => $operator !== '' ? $operator : null,
            'operator_name' => $operatorName,
            'min_lot_age_months' => self::MOVES_MIN_LOT_AGE_MONTHS,
            'title' => $title.' ('.$this->scopeLabel($warehouses, $location).')',
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
            'found' => ['pairs' => $foundPairs, 'value' => $foundValue],
        ]);
    }

    /**
     * Historia zapasów (zapis co noc — InventorySnapshots): punkty wykresu dla wybranych magazynów i oddziału w okresie
     * oraz porównanie początku i końca okresu na wszystkich oddziałach i magazynach XL. Porównanie bierze dla wszystkich
     * te same dwa dni: pierwszy i ostatni zapisany dzień okresu. Domyślnie ostatnie 30 dni. Ponad MAX_POINTS dni —
     * jeden punkt na tydzień (ostatni zapisany dzień tygodnia).
     */
    public function history(Request $request): JsonResponse
    {
        $v = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $scope = $this->scope($request);
        $location = $this->location($request);
        $table = InventorySnapshots::TABLE;
        $first = DB::table($table)->min('taken_on');
        $last = DB::table($table)->max('taken_on');
        $to = isset($v['to']) ? (string) $v['to'] : ($last !== null ? substr((string) $last, 0, 10) : CarbonImmutable::today()->toDateString());
        $from = isset($v['from']) ? (string) $v['from'] : CarbonImmutable::parse($to)->subDays(self::HISTORY_DEFAULT_DAYS)->toDateString();
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $rows = DB::table($table)
            ->where('location', $location ?? '')
            ->where('scope', $scope)
            ->whereBetween('taken_on', [$from, $to])
            ->orderBy('taken_on')
            ->get(['taken_on', 'source', 'totals']);
        $points = $rows->map(fn ($r): array => ['date' => substr((string) $r->taken_on, 0, 10), 'source' => (string) $r->source, ...$this->historyBuckets((string) $r->totals)])->all();
        $weekly = count($points) > self::HISTORY_MAX_POINTS;
        if ($weekly) {
            // ostatni zapisany dzień każdego tygodnia, a na początku pierwszy dzień okresu (zmiana „od …” liczona od niego)
            $byWeek = [];
            foreach ($points as $p) {
                $byWeek[CarbonImmutable::parse($p['date'])->format('o-W')] = $p;
            }
            $firstPoint = $points[0];
            $points = array_values($byWeek);
            if ($points[0]['date'] !== $firstPoint['date']) {
                array_unshift($points, $firstPoint);
            }
        }

        $days = DB::table($table)->whereBetween('taken_on', [$from, $to]);
        $startDay = (clone $days)->min('taken_on');
        $endDay = (clone $days)->max('taken_on');

        return response()->json([
            'warehouses' => $scope,
            'location' => $location,
            'from' => $from,
            'to' => $to,
            'first_date' => $first !== null ? substr((string) $first, 0, 10) : null,
            'last_date' => $last !== null ? substr((string) $last, 0, 10) : null,
            'weekly' => $weekly,
            'points' => $points,
            'compare' => $startDay !== null && $endDay !== null
                ? $this->historyCompare(substr((string) $startDay, 0, 10), substr((string) $endDay, 0, 10), $scope, $location)
                : null,
        ]);
    }

    /**
     * Koszyki z zapisu dnia: pozycje i wartość; lot_age_6/12/24 — „leży ponad pół roku / rok / 2 lata” (items null w pierwszym
     * zapisie nocnym).
     *
     * @return array<string, array{items: int|null, value: float}|null>
     */
    private function historyBuckets(string $json): array
    {
        $totals = json_decode($json, true);
        $totals = is_array($totals) ? $totals : [];
        $buckets = is_array($totals['buckets'] ?? null) ? $totals['buckets'] : [];
        $out = [];
        foreach (self::HISTORY_BUCKETS as $key) {
            $b = $buckets[$key] ?? null;
            $out[$key] = is_array($b) ? ['items' => (int) ($b['items'] ?? 0), 'value' => round((float) ($b['value'] ?? 0), 2)] : null;
        }
        // „leży ponad…” — sztuki z dostaw starszych niż próg (pierwszy zapis nocny: bez liczby towarów)
        $ages = InventorySnapshots::lotAgeThresholds($totals);
        foreach ([6, 12, 24] as $m) {
            $out['lot_age_'.$m] = $ages[$m] ?? null;
        }

        return $out;
    }

    /**
     * Początek i koniec okresu: każdy oddział (i wszystkie razem) w wybranych magazynach, a w wybranym oddziale (albo
     * wszystkich) każdy magazyn XL — wartość z warstwy na magazyn; podział handlowe/usługowe wg dnia zapisu.
     *
     * @return array<string, mixed>
     */
    private function historyCompare(string $start, string $end, string $scope, ?string $location): array
    {
        $rows = DB::table(InventorySnapshots::TABLE)
            ->whereIn('taken_on', [$start, $end])
            ->where('scope', $scope)
            ->get(['taken_on', 'location', 'totals']);
        $byLocation = [];
        foreach ($rows as $r) {
            $side = substr((string) $r->taken_on, 0, 10) === $end ? 'end' : 'start';
            if ($start === $end) {
                $byLocation[(string) $r->location]['start'] = $this->historyBuckets((string) $r->totals);
            }
            $byLocation[(string) $r->location][$side] = $this->historyBuckets((string) $r->totals);
        }
        $order = ['', ...array_map('strval', array_keys(WarehouseLocations::NAMES))];
        // klucze '10', '11'… PHP zamienia na liczby
        uksort($byLocation, static function ($a, $b) use ($order): int {
            [$a, $b] = [(string) $a, (string) $b];
            $ia = array_search($a, $order, true);
            $ib = array_search($b, $order, true);

            return [$ia === false ? PHP_INT_MAX : $ia, $a] <=> [$ib === false ? PHP_INT_MAX : $ib, $b];
        });

        $warehouses = DB::table(InventorySnapshots::WAREHOUSE_TABLE)->whereIn('taken_on', [$start, $end]);
        if ($location !== null) {
            $warehouses->where('location', $location);
        }
        if ($scope !== 'all') {
            $warehouses->where('is_service', $scope === 'service');
        }
        $byCode = [];
        foreach ($warehouses->orderBy('warehouse_code')->get(['taken_on', 'warehouse_code', 'location', 'value', 'items']) as $w) {
            $code = (string) $w->warehouse_code;
            $byCode[$code] ??= ['code' => $code, 'location' => $w->location, 'start' => null, 'end' => null];
            $point = ['items' => (int) $w->items, 'value' => round((float) $w->value, 2)];
            if (substr((string) $w->taken_on, 0, 10) === $start) {
                $byCode[$code]['start'] = $point;
            }
            if (substr((string) $w->taken_on, 0, 10) === $end) {
                $byCode[$code]['end'] = $point;
            }
        }

        return [
            'start_date' => $start,
            'end_date' => $end,
            'locations' => array_values(array_map(
                static fn (string $key, array $sides): array => [
                    'key' => $key,
                    'name' => $key === '' ? 'Wszystkie oddziały' : WarehouseLocations::name($key),
                    'start' => $sides['start'] ?? null,
                    'end' => $sides['end'] ?? null,
                ],
                array_map('strval', array_keys($byLocation)),
                $byLocation,
            )),
            'warehouses' => array_values($byCode),
        ];
    }

    private function scope(Request $request): string
    {
        $v = $request->validate(['warehouses' => ['nullable', 'string', Rule::in(InventoryQuery::SCOPES)]]);

        return (string) ($v['warehouses'] ?? 'trade');
    }

    /** Oddział: cyfry z początku kodu magazynu (01 = Rzeszów); brak = wszystkie oddziały. */
    private function location(Request $request): ?string
    {
        $v = $request->validate(['location' => ['nullable', 'string', 'regex:/^\d{1,10}$/']]);

        return isset($v['location']) && $v['location'] !== '' ? (string) $v['location'] : null;
    }

    /** Dopisek tytułu okna: „magazyny handlowe”, z oddziałem „Rzeszów, magazyny handlowe”. */
    private function scopeLabel(string $scope, ?string $location): string
    {
        return ($location !== null ? WarehouseLocations::name($location).', ' : '').self::SCOPE_LABELS[$scope];
    }

    private function ago(int $months): CarbonImmutable
    {
        return InventoryBoardTotals::today()->ago($months);
    }

    /**
     * Towar koszyka w wybranych magazynach (InventoryBoardTotals::bucketQuery), zawężony do rodzaju.
     *
     * @return Builder<ErpItem>
     */
    private function bucketQuery(string $key, string $scope, ?string $group = null, ?string $location = null): Builder
    {
        $query = InventoryBoardTotals::today()->bucketQuery($key, $scope, $location);
        $this->whereGroup($query, $group);

        return $query;
    }

    /** @param  Builder<ErpItem>  $query */
    private function whereGroup(Builder $query, ?string $group): void
    {
        if ($group === 'other') {
            foreach (array_keys(self::GROUPS) as $letter) {
                $query->where('code', 'not like', $letter.'%');
            }
        } elseif ($group !== null) {
            $query->where('code', 'like', $group.'%');
        }
    }

    /**
     * Okres przedziału „jak długo leży” (InventoryBoardTotals::lotRange).
     *
     * @return array{after: ?string, until: ?string}|null
     */
    private function lotRange(string $key): ?array
    {
        return InventoryBoardTotals::today()->lotRange($key);
    }

    /**
     * SQL kolumn okna towarów: ilość, wartość, najstarsza dostawa i ostatnia sprzedaż w wybranych magazynach; w przedziale
     * „jak długo leży” ilość, wartość i najstarsza dostawa — tylko z dostaw tego okresu.
     *
     * @return array{quantity: string, value: string, lot: string, sale: string}
     */
    private function measures(string $scope, ?string $location, ?string $key = null): array
    {
        if ($key !== null && isset(self::LOT_AGES[$key])) {
            $range = $this->lotRange($key);

            return [
                'quantity' => InventoryQuery::lotQuantitySql($range, $scope, $location),
                'value' => InventoryQuery::lotValueSql($range, $scope, $location),
                'lot' => InventoryQuery::lotOldestSql($range, $scope, $location),
                'sale' => InventoryQuery::lastSaleSql($location),
            ];
        }

        return [
            'quantity' => InventoryQuery::quantitySql($scope, $location),
            'value' => InventoryQuery::valueSql($scope, $location),
            'lot' => InventoryQuery::oldestLotSql($scope, $location),
            'sale' => InventoryQuery::lastSaleSql($location),
        ];
    }

    /**
     * Kolejność okna towarów. Bez kolumny — od największej wartości. Towar bez wartości i bez daty dostawy zawsze na
     * końcu; „ani razu nie sprzedany” przy ostatniej sprzedaży rosnąco na początku (sprzedaż najdawniej), malejąco na końcu.
     *
     * @param  Builder<ErpItem>  $query
     * @param  array{quantity: string, value: string, lot: string, sale: string}  $measures
     * @param  'asc'|'desc'  $dir
     * @return Builder<ErpItem>
     */
    private function ordered(Builder $query, array $measures, ?string $sort = null, string $dir = 'desc'): Builder
    {
        ['value' => $value, 'quantity' => $quantity, 'lot' => $lot, 'sale' => $sale] = $measures;
        $query->select('erp_items.*')
            ->selectRaw($value.' as purchase_value')
            ->selectRaw($quantity.' as scope_quantity')
            ->selectRaw($lot.' as scope_oldest_lot')
            ->selectRaw($sale.' as scope_last_sale')
            ->with(ErpItemCards::eagerLinks());

        match ($sort) {
            'name' => $query->orderByRaw('coalesce('.self::cardNameSql('erp_items.id').', erp_items.name) '.$dir),
            'quantity' => $query->orderByRaw($quantity.' '.$dir),
            'last_sale' => $query->orderByRaw($sale.' '.$dir),
            'oldest_lot' => $query->orderByRaw($lot.' is null')->orderByRaw($lot.' '.$dir),
            'value' => $query->orderByRaw($value.' is null')->orderByRaw($value.' '.$dir),
            default => $query->orderByRaw($value.' is null')->orderByRaw($value.' desc'),
        };

        return $query->orderBy('code')->orderBy('erp_items.id');
    }

    /**
     * Kolejność okna dokumentów. Bez kolumny — od najnowszego; dokumenty bez opisu na końcu przy sortowaniu po opisie.
     *
     * @param  Builder<ErpRwPwPair>  $query
     * @param  'asc'|'desc'  $dir
     * @return Builder<ErpRwPwPair>
     */
    private function orderedMoves(Builder $query, ?string $sort, string $dir): Builder
    {
        $item = 'erp_rw_pw_pairs.erp_item_id';
        match ($sort) {
            'name' => $query->orderByRaw('coalesce('.self::cardNameSql($item).', (select i.name from erp_items i where i.id = '.$item.'), \'\') '.$dir),
            'operator' => $query->orderByRaw('coalesce(rw_operator_name, rw_operator, \'\') '.$dir),
            'value' => $query->orderBy('rw_value', $dir),
            'lot_age' => $query->orderBy('rw_lot_age_months', $dir),
            'note' => $query->orderByRaw('rw_note is null')->orderBy('rw_note', $dir),
            default => null,
        };
        $dateDir = $sort === 'date' ? $dir : 'desc';

        return $query->orderBy('rw_date', $dateDir)->orderBy('id', $dateDir);
    }

    /**
     * Nazwa karty katalogu przy towarze XL — ta sama co w wierszu (ErpItemCards: pewne i potwierdzone powiązanie,
     * potwierdzone przed automatycznym, potem najstarsze).
     */
    private static function cardNameSql(string $itemId): string
    {
        $linked = implode(', ', array_map(static fn (string $s): string => "'".$s."'", ErpItemCards::LINKED));

        return '(select p.name from erp_item_links l join products p on p.id = l.product_id'
            .' where l.erp_item_id = '.$itemId.' and l.status in ('.$linked.')'
            ." order by case when l.status = '".ErpItemLink::STATUS_CONFIRMED."' then 0 else 1 end, l.id limit 1)";
    }

    /** @return list<string> słowa z pola wyszukiwania (bez powtórzeń, najwyżej SEARCH_WORDS) */
    private function words(mixed $search): array
    {
        $words = preg_split('/\s+/u', trim((string) $search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_slice(array_values(array_unique($words)), 0, self::SEARCH_WORDS);
    }

    /**
     * Każde słowo musi pasować do którejś kolumny okna: kod, nazwa XL, karta (nazwa, SKU), dostawca, rodzaj, daty
     * (rok, nazwa miesiąca), ilość albo wartość w pełnych złotych.
     *
     * @param  Builder<ErpItem>  $query
     * @param  list<string>  $words
     * @param  array{quantity: string, value: string, lot: string, sale: string}  $measures
     */
    private function searchItems(Builder $query, array $words, array $measures): void
    {
        ['value' => $value, 'quantity' => $quantity, 'lot' => $lot, 'sale' => $sale] = $measures;
        foreach ($words as $word) {
            $like = '%'.addcslashes($word, '%_\\').'%';
            $query->where(function (Builder $q) use ($word, $like, $quantity, $value, $lot, $sale): void {
                $q->where('code', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('name1', 'like', $like)
                    ->orWhere('last_supplier', 'like', $like)
                    ->orWhereHas('links', fn (Builder $l) => ErpItemCards::linked($l)
                        ->whereHas('product', fn (Builder $p) => $p->where('name', 'like', $like)->orWhere('sku', 'like', $like)));
                foreach ($this->groupsFor($word) as $group) {
                    if ($group === 'other') {
                        $q->orWhere(function (Builder $o): void {
                            foreach (array_keys(self::GROUPS) as $letter) {
                                $o->where('code', 'not like', $letter.'%');
                            }
                        });
                    } else {
                        $q->orWhere('code', 'like', $group.'%');
                    }
                }
                foreach ($this->datePatterns($word) as $pattern) {
                    $q->orWhereRaw($sale.' like ?', [$pattern])->orWhereRaw($lot.' like ?', [$pattern]);
                }
                if (ctype_digit($word)) {
                    $q->orWhereRaw($quantity.' = ?', [(int) $word])->orWhereRaw('round('.$value.') = ?', [(int) $word]);
                }
            });
        }
    }

    /**
     * Każde słowo musi pasować do którejś kolumny okna: numery dokumentów, towar (kod, nazwa XL, karta), osoby, opisy,
     * rozmiar/kolor, data (rok, nazwa miesiąca), ilość, miesiące leżenia albo wartość w pełnych złotych.
     *
     * @param  Builder<ErpRwPwPair>  $query
     * @param  list<string>  $words
     */
    private function searchMoves(Builder $query, array $words): void
    {
        foreach ($words as $word) {
            $like = '%'.addcslashes($word, '%_\\').'%';
            $query->where(function (Builder $q) use ($word, $like): void {
                foreach (['rw_number', 'pw_number', 'rw_note', 'pw_note', 'rw_operator_name', 'rw_operator', 'rw_approver_name', 'rw_approver', 'rw_features', 'pw_features'] as $column) {
                    $q->orWhere($column, 'like', $like);
                }
                $q->orWhereHas('item', fn (Builder $i) => $i->where('code', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhereHas('links', fn (Builder $l) => ErpItemCards::linked($l)
                        ->whereHas('product', fn (Builder $p) => $p->where('name', 'like', $like)->orWhere('sku', 'like', $like))));
                foreach ($this->datePatterns($word) as $pattern) {
                    $q->orWhere('rw_date', 'like', $pattern);
                }
                if (ctype_digit($word)) {
                    $q->orWhere('rw_quantity', (int) $word)
                        ->orWhere('rw_lot_age_months', (int) $word)
                        ->orWhereRaw('round(rw_value) = ?', [(int) $word]);
                }
            });
        }
    }

    /** @return list<string> rodzaje (litera kodu albo 'other'), których nazwa zaczyna się od słowa (od 3 liter) */
    private function groupsFor(string $word): array
    {
        $word = self::plain($word);
        if (mb_strlen($word) < 3) {
            return [];
        }
        $out = [];
        foreach ([...self::GROUPS, 'other' => 'Pozostałe'] as $group => $label) {
            if (str_starts_with(self::plain($label), $word)) {
                $out[] = (string) $group;
            }
        }

        return $out;
    }

    /** @return list<string> wzorce LIKE po dacie 'YYYY-MM-DD': rok/cyfry dosłownie, nazwa miesiąca → '-MM-' */
    private function datePatterns(string $word): array
    {
        if (preg_match('/^\d{2,4}(-\d{1,2})?$/', $word) === 1) {
            return ['%'.$word.'%'];
        }
        $word = self::plain($word);
        if (mb_strlen($word) < 3) {
            return [];
        }
        $out = [];
        foreach (self::MONTH_NAMES as $month => $names) {
            foreach ($names as $name) {
                if (str_starts_with($name, $word)) {
                    $out[] = '%-'.$month.'-%';
                    break;
                }
            }
        }

        return $out;
    }

    /** Małe litery bez polskich znaków — do porównania z nazwami miesięcy i rodzajów. */
    private static function plain(string $text): string
    {
        return strtr(mb_strtolower($text), ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z']);
    }

    /**
     * @param  Builder<ErpItem>|null  $query  zapytanie do wykonania (z limitem) albo null, gdy podane są gotowe towary
     * @param  iterable<ErpItem>|null  $items
     * @return list<array<string, mixed>>
     */
    private function itemRows(?Builder $query, string $scope, ?iterable $items = null, bool $valuedOnly = false, ?string $location = null): array
    {
        if ($query !== null) {
            $query = $this->ordered($query, $this->measures($scope, $location));
            if ($valuedOnly) {
                $query->whereRaw(InventoryQuery::valueSql($scope, $location).' is not null');
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
                // w oddziale — ostatnia sprzedaż z jego magazynów
                'last_sale_at' => $item->getAttribute('scope_last_sale') !== null ? substr((string) $item->getAttribute('scope_last_sale'), 0, 10) : null,
                'oldest_lot_at' => $item->getAttribute('scope_oldest_lot') !== null ? substr((string) $item->getAttribute('scope_oldest_lot'), 0, 10) : null,
                'last_supplier' => $item->last_supplier,
            ];
        })->values()->all();
    }

    /** @return Builder<ErpRwPwPair> pary z 12 mies., PW do 3 dni po RW, partia leżała 3+ mies., w wybranych magazynach i oddziale (magazyn RW) */
    private function pairs(string $scope, ?string $location = null): Builder
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
        if ($location !== null) {
            // magazyny RW tego oddziału — z kodów, które występują w parach (kilkanaście), ta sama reguła co stan
            $codes = ErpRwPwPair::query()->whereNotNull('rw_warehouse')->distinct()->pluck('rw_warehouse')
                ->map(static fn ($c): string => (string) $c)
                ->filter(static fn (string $c): bool => WarehouseLocations::of($c) === $location)
                ->values()->all();
            $query->whereIn('rw_warehouse', $codes);
        }

        return $query;
    }

    /** @return Builder<ErpRwPwPair> bez wyjaśnienia: ta sama cecha (rozmiar, kolor) i puste uwagi RW */
    private function unexplained(string $scope, ?string $location = null): Builder
    {
        return $this->pairs($scope, $location)->where('same_feature', true)->whereNull('rw_note');
    }

    /** @return array<string, mixed> */
    private function movesSummary(string $scope, ?string $location = null): array
    {
        $people = $this->unexplained($scope, $location)->toBase()
            ->selectRaw('rw_operator, max(rw_operator_name) as name, count(*) as c')
            ->groupBy('rw_operator')
            ->orderByDesc('c')
            ->orderBy('rw_operator')
            ->limit(self::PEOPLE_LIMIT)
            ->get();
        // kontekst: ile wszystkich takich wydań i przyjęć ta osoba wystawiła (większość to zamiany rozmiaru)
        $all = $people->isEmpty() ? collect() : $this->pairs($scope, $location)->toBase()
            ->whereIn('rw_operator', $people->pluck('rw_operator')->filter()->all())
            ->selectRaw('rw_operator, count(*) as c')
            ->groupBy('rw_operator')
            ->pluck('c', 'rw_operator');

        return [
            'from' => $this->ago(self::MOVES_MONTHS)->toDateString(),
            'min_lot_age_months' => self::MOVES_MIN_LOT_AGE_MONTHS,
            'total' => $this->pairs($scope, $location)->count(),
            'unexplained' => $this->unexplained($scope, $location)->count(),
            'unexplained_value' => round((float) $this->unexplained($scope, $location)->sum('rw_value'), 2),
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
