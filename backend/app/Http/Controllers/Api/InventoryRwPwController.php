<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ErpItem;
use App\Models\ErpRwPwPair;
use App\Services\Erp\ErpItemCards;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Zapasy → RW → PW: pary RW i PW tego samego towaru w tej samej ilości (ErpRwPwSync, co noc). Trzy widoki: pary,
 * po towarze, po osobie (kto wystawił RW). Wartość = wartość księgowa RW (wydanej partii). Lista „do wyjaśnienia” —
 * część par to uczciwe korekty; niczego nie zapisuje.
 */
class InventoryRwPwController extends Controller
{
    private const MONTHS = [3, 6, 12];

    private const GAPS = [0, 3, 7, 30];

    private const PAIR_SORTS = ['date' => 'rw_date', 'value' => 'rw_value', 'gap' => 'gap_days', 'code' => 'code'];

    private const ITEM_SORTS = ['pairs' => 'pairs', 'value' => 'value', 'last_date' => 'last_date', 'code' => 'code'];

    public function __construct(private readonly ErpItemCards $cards) {}

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'months' => ['nullable', 'integer', Rule::in(self::MONTHS)],
            'gap' => ['nullable', 'integer', Rule::in(self::GAPS)],
            'same_value' => ['nullable', 'boolean'],
            'operator' => ['nullable', 'string', 'max:20'],
            'search' => ['nullable', 'string', 'max:150'],
            'view' => ['nullable', 'string', Rule::in(['pairs', 'items', 'operators'])],
            'sort' => ['nullable', 'string'],
            'dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);
        $from = CarbonImmutable::today()->subMonthsNoOverflow((int) ($v['months'] ?? 12));
        $gap = (int) ($v['gap'] ?? 3);
        $view = (string) ($v['view'] ?? 'pairs');
        $dir = ($v['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $period = fn (): Builder => ErpRwPwPair::query()
            ->where('rw_date', '>=', $from->toDateString())
            ->where('gap_days', '<=', $gap);
        $query = $period();
        if (! empty($v['same_value'])) {
            $query->where('same_value', true);
        }
        $operator = trim((string) ($v['operator'] ?? ''));
        if ($operator !== '') {
            $query->where(fn (Builder $q) => $q->where('rw_operator', $operator)->orWhere('rw_approver', $operator)
                ->orWhere('pw_operator', $operator)->orWhere('pw_approver', $operator));
        }
        $search = trim((string) ($v['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn (Builder $q) => $q->where('rw_number', 'like', $like)->orWhere('pw_number', 'like', $like)
                ->orWhereHas('item', fn (Builder $i) => $i->where('code', 'like', $like)->orWhere('name', 'like', $like)));
        }

        $summary = (clone $query)->toBase()
            ->selectRaw('count(*) as pairs, count(distinct xl_gid) as items, coalesce(sum(rw_value), 0) as value,'
                .' sum(case when same_value then 1 else 0 end) as same_value, count(distinct rw_operator) as operators')
            ->first();
        $operators = $this->operatorOptions($period());
        $syncedAt = ErpRwPwPair::query()->max('synced_at');

        [$data, $meta] = match ($view) {
            'items' => $this->items($query, (string) ($v['sort'] ?? 'value'), $dir, (int) ($v['per_page'] ?? 50)),
            'operators' => [$this->operators($query), null],
            default => $this->pairs($query, (string) ($v['sort'] ?? 'date'), $dir, (int) ($v['per_page'] ?? 50)),
        };

        return response()->json([
            'view' => $view,
            'data' => $data,
            'meta' => $meta,
            'summary' => [
                'pairs' => (int) ($summary->pairs ?? 0),
                'items' => (int) ($summary->items ?? 0),
                'value' => round((float) ($summary->value ?? 0), 2),
                'same_value' => (int) ($summary->same_value ?? 0),
                'operators' => (int) ($summary->operators ?? 0),
            ],
            'operators' => $operators,
            'from' => $from->toDateString(),
            'synced_at' => $syncedAt !== null ? Carbon::parse((string) $syncedAt)->toIso8601String() : null,
        ]);
    }

    /**
     * @param  Builder<ErpRwPwPair>  $query
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
     */
    private function pairs(Builder $query, string $sort, string $dir, int $perPage): array
    {
        $column = self::PAIR_SORTS[$sort] ?? 'rw_date';
        if ($column === 'code') {
            $query->orderBy(ErpItem::query()->select('code')->whereColumn('erp_items.id', 'erp_rw_pw_pairs.erp_item_id'), $dir);
        } else {
            $query->orderBy($column, $dir);
        }
        $page = $query->orderBy('id', $dir)->paginate($perPage);
        $refs = $this->itemRefs($page->getCollection()->pluck('erp_item_id')->filter()->all());

        $data = $page->getCollection()->map(fn (ErpRwPwPair $p): array => [
            'id' => $p->id,
            'item' => $this->ref($p->erp_item_id, $p->xl_gid, $refs),
            'rw' => $this->doc($p, 'rw'),
            'pw' => $this->doc($p, 'pw'),
            'gap_days' => $p->gap_days,
            'same_value' => $p->same_value,
            'same_warehouse' => $p->same_warehouse,
        ])->values()->all();

        return [$data, $this->meta($page->currentPage(), $page->lastPage(), $page->perPage(), $page->total())];
    }

    /**
     * @param  Builder<ErpRwPwPair>  $query
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>}
     */
    private function items(Builder $query, string $sort, string $dir, int $perPage): array
    {
        $grouped = (clone $query)->toBase()
            ->groupBy('xl_gid', 'erp_item_id')
            ->selectRaw('xl_gid, erp_item_id, count(*) as pairs, sum(rw_quantity) as quantity, sum(rw_value) as value,'
                .' sum(case when same_value then 1 else 0 end) as same_value, min(rw_date) as first_date, max(rw_date) as last_date');
        $column = self::ITEM_SORTS[$sort] ?? 'value';
        if ($column === 'code') {
            $grouped->orderBy(ErpItem::query()->select('code')->whereColumn('erp_items.id', 'erp_rw_pw_pairs.erp_item_id')->toBase(), $dir);
        } else {
            $grouped->orderBy($column, $dir);
        }
        $page = $grouped->orderBy('xl_gid')->paginate($perPage);

        $gids = collect($page->items())->pluck('xl_gid')->map(fn ($g): int => (int) $g)->all();
        $people = [];
        foreach ((clone $query)->whereIn('xl_gid', $gids)->get(['xl_gid', 'rw_operator', 'pw_operator']) as $p) {
            foreach ([$p->rw_operator, $p->pw_operator] as $who) {
                if ($who !== null && $who !== '') {
                    $people[(int) $p->xl_gid][$who] = true;
                }
            }
        }
        $refs = $this->itemRefs(collect($page->items())->pluck('erp_item_id')->filter()->all());

        $data = collect($page->items())->map(function ($row) use ($refs, $people): array {
            $names = array_keys($people[(int) $row->xl_gid] ?? []);
            sort($names);

            return [
                'item' => $this->ref($row->erp_item_id !== null ? (int) $row->erp_item_id : null, (int) $row->xl_gid, $refs),
                'pairs' => (int) $row->pairs,
                'quantity' => (float) $row->quantity,
                'value' => round((float) $row->value, 2),
                'same_value' => (int) $row->same_value,
                'operators' => $names,
                'first_date' => Carbon::parse((string) $row->first_date)->toDateString(),
                'last_date' => Carbon::parse((string) $row->last_date)->toDateString(),
            ];
        })->values()->all();

        return [$data, $this->meta($page->currentPage(), $page->lastPage(), $page->perPage(), $page->total())];
    }

    /**
     * Po osobie, która wystawiła RW; pw_by_other — ile PW wystawił ktoś inny.
     *
     * @param  Builder<ErpRwPwPair>  $query
     * @return list<array<string, mixed>>
     */
    private function operators(Builder $query): array
    {
        return (clone $query)->toBase()
            ->groupBy('rw_operator')
            ->selectRaw('rw_operator, count(*) as pairs, count(distinct xl_gid) as items, sum(rw_value) as value,'
                .' sum(case when same_value then 1 else 0 end) as same_value, max(rw_date) as last_date,'
                .' sum(case when pw_operator is null or rw_operator is null or pw_operator <> rw_operator then 1 else 0 end) as pw_by_other')
            ->orderByDesc('value')
            ->get()
            ->map(fn ($row): array => [
                'operator' => $row->rw_operator ?? '(brak)',
                'pairs' => (int) $row->pairs,
                'items' => (int) $row->items,
                'value' => round((float) $row->value, 2),
                'same_value' => (int) $row->same_value,
                'last_date' => Carbon::parse((string) $row->last_date)->toDateString(),
                'pw_by_other' => (int) $row->pw_by_other,
            ])
            ->values()
            ->all();
    }

    /**
     * Akronimy do listy wyboru: wszyscy, którzy w okresie wystawili albo zatwierdzili RW lub PW z pary.
     *
     * @param  Builder<ErpRwPwPair>  $period
     * @return list<string>
     */
    private function operatorOptions(Builder $period): array
    {
        $names = [];
        foreach ($period->get(['rw_operator', 'rw_approver', 'pw_operator', 'pw_approver']) as $p) {
            foreach ([$p->rw_operator, $p->rw_approver, $p->pw_operator, $p->pw_approver] as $who) {
                if ($who !== null && $who !== '') {
                    $names[$who] = true;
                }
            }
        }
        $names = array_keys($names);
        sort($names);

        return $names;
    }

    /**
     * @param  list<int|string>  $itemIds
     * @return array<int, array<string, mixed>>
     */
    private function itemRefs(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }
        $items = ErpItem::query()->whereIn('id', array_unique(array_map('intval', $itemIds)))
            ->with(ErpItemCards::eagerLinks())
            ->get(['id', 'code', 'name', 'unit', 'stock_total']);
        $cards = $this->cards->forItems($items);
        $out = [];
        foreach ($items as $item) {
            $card = $cards[(int) $item->id]['card'];
            $out[(int) $item->id] = [
                'erp_item_id' => (int) $item->id,
                'code' => (string) $item->code,
                'name' => (string) $item->name,
                'unit' => $item->unit,
                'stock_total' => (float) $item->stock_total,
                'card' => $card === null ? null : array_diff_key($card, ['link_status' => true]),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $refs
     * @return array<string, mixed>
     */
    private function ref(?int $itemId, int $gid, array $refs): array
    {
        return ($itemId !== null ? ($refs[$itemId] ?? null) : null) ?? [
            // towaru nie ma jeszcze w kopii erp_items (dojdzie z nocną synchronizacją)
            'erp_item_id' => null, 'code' => 'XL #'.$gid, 'name' => '', 'unit' => null, 'stock_total' => null, 'card' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function doc(ErpRwPwPair $p, string $prefix): array
    {
        return [
            'number' => $p->{$prefix.'_number'},
            'date' => $p->{$prefix.'_date'}?->toDateString(),
            'warehouse' => $p->{$prefix.'_warehouse'},
            'quantity' => (float) $p->{$prefix.'_quantity'},
            'value' => (float) $p->{$prefix.'_value'},
            'operator' => $p->{$prefix.'_operator'},
            'approver' => $p->{$prefix.'_approver'},
        ];
    }

    /** @return array<string, int> */
    private function meta(int $current, int $last, int $perPage, int $total): array
    {
        return ['current_page' => $current, 'last_page' => $last, 'per_page' => $perPage, 'total' => $total];
    }
}
