<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ErpItem;
use App\Models\ErpItemLink;
use App\Models\Product;
use App\Services\Erp\ErpLinkDecisions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Ekran „Powiązania z ERP XL”: towary XL (aktywne, bez usuniętych z XL) z wynikiem łączenia i powiązanymi kartami,
 * liczniki braków i decyzje człowieka. Niczego nie zapisuje w XL.
 */
class ErpItemController extends Controller
{
    /** Filtr statusu → wyniki łączenia (erp_items.match_outcome). */
    private const STATUS_OUTCOMES = [
        'linked' => ['auto', 'confirmed'],
        'auto' => ['auto'],
        'confirmed' => ['confirmed'],
        'review' => ['suggested', 'ambiguous', 'name_suggested', 'search_suggested'],
        'no_card' => ['no_match', 'family_conflict'],
        'no_code' => ['no_code'],
        'rejected' => ['rejected'],
    ];

    /** Grupy asortymentu XL po pierwszej literze kodu towaru. */
    private const GROUPS = ['A', 'B', 'S', 'T', 'H'];

    private const SORTS = [
        'stock' => 'stock_trade',
        'last_sale' => 'last_sale_at',
        'last_purchase' => 'last_purchase_at',
        'code' => 'code',
        'name' => 'name',
    ];

    private const OUTCOMES = ['auto', 'confirmed', 'suggested', 'ambiguous', 'name_suggested', 'search_suggested', 'no_match', 'family_conflict', 'no_code', 'rejected'];

    private const LINK_ORDER = [
        ErpItemLink::STATUS_CONFIRMED => 0,
        ErpItemLink::STATUS_AUTO => 1,
        ErpItemLink::STATUS_SUGGESTED => 2,
        ErpItemLink::STATUS_REJECTED => 3,
    ];

    public function __construct(private readonly ErpLinkDecisions $decisions) {}

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'status' => ['nullable', 'string', Rule::in([...array_keys(self::STATUS_OUTCOMES), 'unlinked'])],
            'group' => ['nullable', 'string', Rule::in([...self::GROUPS, 'other'])],
            'in_stock' => ['nullable', 'boolean'],
            'sold_months' => ['nullable', 'integer', Rule::in([3, 6, 12])],
            'supplier' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:150'],
            'sort' => ['nullable', 'string', Rule::in(array_keys(self::SORTS))],
            'dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = $this->active();
        $status = (string) ($v['status'] ?? '');
        if ($status === 'unlinked') {
            $query->where(fn (Builder $q) => $q->whereNull('match_outcome')->orWhereNotIn('match_outcome', ['auto', 'confirmed']));
        } elseif ($status !== '') {
            $query->whereIn('match_outcome', self::STATUS_OUTCOMES[$status]);
        }
        $group = (string) ($v['group'] ?? '');
        if ($group === 'other') {
            foreach (self::GROUPS as $letter) {
                $query->where('code', 'not like', $letter.'%');
            }
        } elseif ($group !== '') {
            $query->where('code', 'like', $group.'%');
        }
        if (! empty($v['in_stock'])) {
            $query->where('stock_trade', '>', 0);
        }
        if (! empty($v['sold_months'])) {
            $query->where('last_sale_at', '>=', now()->subMonths((int) $v['sold_months'])->toDateString());
        }
        $supplier = trim((string) ($v['supplier'] ?? ''));
        if ($supplier !== '') {
            $query->where('last_supplier', 'like', '%'.$this->like($supplier).'%');
        }
        $search = trim((string) ($v['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$this->like($search).'%';
            $query->where(fn (Builder $q) => $q->where('code', 'like', $like)
                ->orWhere('name', 'like', $like)
                ->orWhere('name1', 'like', $like)
                ->orWhereHas('links.product', fn (Builder $p) => $p->where('sku', 'like', $like)));
        }

        $column = self::SORTS[$v['sort'] ?? 'stock'];
        $page = $query
            ->orderBy($column, ($v['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc')
            ->orderBy('id')
            ->with(['links.product:id,sku,name,manufacturer', 'links.decider:id,name'])
            ->paginate((int) ($v['per_page'] ?? 50));

        return response()->json([
            'data' => $page->getCollection()->map(fn (ErpItem $item): array => $this->present($item))->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function summary(): JsonResponse
    {
        $byOutcome = array_fill_keys(self::OUTCOMES, 0);
        foreach ($this->active()->selectRaw('match_outcome, count(*) as c')->groupBy('match_outcome')->get() as $row) {
            if ($row->match_outcome !== null && isset($byOutcome[$row->match_outcome])) {
                $byOutcome[$row->match_outcome] = (int) $row->c;
            }
        }
        $unlinked = fn (): Builder => $this->active()
            ->where(fn (Builder $q) => $q->whereNull('match_outcome')->orWhereNotIn('match_outcome', ['auto', 'confirmed']));
        $groups = [];
        foreach ($this->active()
            ->selectRaw("substr(code, 1, 1) as letter, count(*) as total, sum(case when match_outcome in ('auto', 'confirmed') then 1 else 0 end) as linked")
            ->groupBy(DB::raw('substr(code, 1, 1)'))
            ->get() as $row) {
            $letter = in_array(strtoupper((string) $row->letter), self::GROUPS, true) ? strtoupper((string) $row->letter) : 'other';
            $groups[$letter] ??= ['group' => $letter, 'total' => 0, 'linked' => 0];
            $groups[$letter]['total'] += (int) $row->total;
            $groups[$letter]['linked'] += (int) $row->linked;
        }
        $order = array_flip([...self::GROUPS, 'other']);
        uksort($groups, static fn (string $a, string $b): int => $order[$a] <=> $order[$b]);
        $syncedAt = $this->active()->max('synced_at');

        return response()->json([
            'total' => $this->active()->count(),
            'synced_at' => $syncedAt !== null ? Carbon::parse((string) $syncedAt)->toIso8601String() : null,
            'by_outcome' => $byOutcome,
            'unlinked_sold_12m' => $unlinked()->where('last_sale_at', '>=', now()->subMonths(12)->toDateString())->count(),
            'unlinked_in_stock' => $unlinked()->where('stock_trade', '>', 0)->count(),
            'groups' => array_values($groups),
        ]);
    }

    public function confirm(Request $request, ErpItemLink $link): JsonResponse
    {
        try {
            $item = $this->decisions->confirm($link, $request->user());
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['item' => $this->presentFresh($item)]);
    }

    public function reject(Request $request, ErpItemLink $link): JsonResponse
    {
        return response()->json(['item' => $this->presentFresh($this->decisions->reject($link, $request->user()))]);
    }

    public function link(Request $request, ErpItem $item): JsonResponse
    {
        $v = $request->validate(['product_id' => ['required', 'integer', 'exists:products,id']]);
        $product = Product::query()->findOrFail((int) $v['product_id']);

        return response()->json(['item' => $this->presentFresh($this->decisions->link($item, $product, $request->user()))]);
    }

    public function bulkConfirm(Request $request): JsonResponse
    {
        $v = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);

        return response()->json(['confirmed' => $this->decisions->bulkConfirm(array_map('intval', $v['ids']), $request->user())]);
    }

    /** @return Builder<ErpItem> */
    private function active(): Builder
    {
        return ErpItem::query()->whereNull('removed_at')->where('archived', false);
    }

    private function like(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /** @return array<string, mixed> */
    private function presentFresh(ErpItem $item): array
    {
        return $this->present($item->fresh(['links.product:id,sku,name,manufacturer', 'links.decider:id,name']) ?? $item);
    }

    /** @return array<string, mixed> */
    private function present(ErpItem $item): array
    {
        $links = $item->links
            ->sortBy(fn (ErpItemLink $l): string => (self::LINK_ORDER[$l->status] ?? 9).'-'.str_pad((string) $l->id, 10, '0', STR_PAD_LEFT))
            ->map(static fn (ErpItemLink $l): array => [
                'id' => $l->id,
                'status' => $l->status,
                'method' => $l->method,
                'matched_value' => $l->matched_value,
                'evidence' => $l->evidence,
                'decided_at' => $l->decided_at?->toIso8601String(),
                'decided_by' => $l->decider?->name,
                'product' => $l->product === null ? null : [
                    'id' => $l->product->id,
                    'sku' => (string) $l->product->sku,
                    'name' => (string) $l->product->name,
                    'manufacturer' => $l->product->manufacturer !== '' ? $l->product->manufacturer : null,
                ],
            ])
            ->values()
            ->all();

        return [
            'id' => $item->id,
            'xl_gid' => $item->xl_gid,
            'code' => $item->code,
            'name' => $item->name,
            'name1' => $item->name1,
            'unit' => $item->unit,
            'archived' => (bool) $item->archived,
            'stock_trade' => (float) $item->stock_trade,
            'stock_total' => (float) $item->stock_total,
            'last_sale_at' => $item->last_sale_at?->toDateString(),
            'last_purchase_at' => $item->last_purchase_at?->toDateString(),
            'last_supplier' => $item->last_supplier,
            'outcome' => $item->match_outcome,
            'match_value' => $item->match_value,
            'links' => $links,
        ];
    }
}
