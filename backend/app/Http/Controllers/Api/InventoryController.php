<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ErpItem;
use App\Models\ErpItemPurchase;
use App\Models\ErpRwPwPair;
use App\Services\Erp\ErpItemCards;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Zakładka „Zapasy”: towary ERP XL ze stanem (wszystkie magazyny), które nie sprzedały się od N miesięcy. Wartość =
 * ilość × cena zakupu (decyzja użytkownika 30.09.2026): najpierw wartość partii leżących na stanie (TwZ_KsiegowaNetto),
 * a bez niej stan × cena z ostatniej PZ. Sama ostatnia PZ bywa błędna — SNAU51000-04-S: PZ 1 szt. za 11 600,60 zł,
 * poprawione RW 1 szt. + PW 40 szt. po 290,02 zł; partie mają 290,02. Wiersz = towar XL (także bez karty katalogu —
 * to większość towarów). Dane z nocnej kopii XL (2:00); niczego nie zapisuje.
 */
class InventoryController extends Controller
{
    public const MONTHS = [1, 2, 3, 6, 9, 12, 18, 24];

    private const DEFAULT_MONTHS = 6;

    /** Grupy asortymentu XL po pierwszej literze kodu towaru (jak ekran Powiązania z ERP XL). */
    private const GROUPS = ['A', 'B', 'S', 'T', 'H'];

    /**
     * Cena jednostki podstawowej w PLN z ostatniej PZ towaru (ta sama kolejność co ErpItem::purchases). Wartość zapasu
     * w SQL, żeby sortowanie i suma szły po całej liście, nie po stronie.
     */
    private const LAST_PRICE_SQL = '(select p.unit_price_pln from erp_item_purchases p where p.erp_item_id = erp_items.id'
        .' and p.unit_price_pln is not null order by p.purchased_at desc, p.document_id desc limit 1)';

    private const FALLBACK_VALUE_SQL = '(erp_items.stock_total * '.self::LAST_PRICE_SQL.')';

    private const VALUE_SQL = '(coalesce(erp_items.stock_value, '.self::FALLBACK_VALUE_SQL.'))';

    private const SORTS = [
        'value' => 'value',
        'stock' => 'stock_total',
        'last_sale' => 'last_sale_at',
        'oldest_lot' => 'oldest_lot_at',
        'code' => 'code',
        'name' => 'name',
    ];

    /** Znacznik „RW/PW ×N” przy towarze: pary z 12 miesięcy, PW do 3 dni po RW — jak domyślne filtry podzakładki. */
    private const RW_PW_MONTHS = 12;

    private const RW_PW_GAP_DAYS = 3;

    public function __construct(private readonly ErpItemCards $cards) {}

    public function index(Request $request): JsonResponse
    {
        $v = $request->validate([
            'months' => ['nullable', 'integer', Rule::in(self::MONTHS)],
            'never_sold' => ['nullable', 'boolean'],
            'card' => ['nullable', 'string', Rule::in(['', 'with', 'without'])],
            'group' => ['nullable', 'string', Rule::in(['', ...self::GROUPS, 'other'])],
            'supplier' => ['nullable', 'string', 'max:100'],
            'search' => ['nullable', 'string', 'max:150'],
            'sort' => ['nullable', 'string', Rule::in(array_keys(self::SORTS))],
            'dir' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $cutoff = CarbonImmutable::today()->subMonthsNoOverflow((int) ($v['months'] ?? self::DEFAULT_MONTHS));
        $neverSold = ! array_key_exists('never_sold', $v) || $v['never_sold'] === null || (bool) $v['never_sold'];
        $query = $this->filtered($v, $cutoff, $neverSold);

        $summary = (clone $query)->toBase()
            ->selectRaw('count(*) as items, coalesce(sum('.self::VALUE_SQL.'), 0) as value,'
                .' sum(case when '.self::VALUE_SQL.' is null then 1 else 0 end) as value_unknown,'
                .' sum(case when last_sale_at is null then 1 else 0 end) as never_sold')
            ->first();
        $withoutCard = (clone $query)->whereDoesntHave('links', fn (Builder $q) => ErpItemCards::linked($q))->count();

        $dir = ($v['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $sort = self::SORTS[$v['sort'] ?? 'value'];
        $query->select('erp_items.*')->selectRaw(self::VALUE_SQL.' as purchase_value');
        if ($sort === 'value') {
            // towary bez ceny zakupu na końcu w obu kierunkach
            $query->orderByRaw(self::VALUE_SQL.' is null')->orderByRaw(self::VALUE_SQL.' '.$dir);
        } else {
            $query->orderBy($sort, $dir);
        }
        $page = $query
            ->orderBy('id')
            ->with([...ErpItemCards::eagerLinks(), 'purchases'])
            ->paginate((int) ($v['per_page'] ?? 50));

        $cards = $this->cards->forItems($page->getCollection());
        $rwPw = $this->rwPwCounts($page->getCollection()->pluck('id')->all());
        $syncedAt = ErpItem::query()->whereNull('removed_at')->max('synced_at');

        return response()->json([
            'data' => $page->getCollection()->map(fn (ErpItem $item): array => $this->present($item, $cards[(int) $item->id], $rwPw[(int) $item->id] ?? 0))->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'summary' => [
                'items' => (int) ($summary->items ?? 0),
                'value' => round((float) ($summary->value ?? 0), 2),
                'value_unknown' => (int) ($summary->value_unknown ?? 0),
                'without_card' => $withoutCard,
                'never_sold' => (int) ($summary->never_sold ?? 0),
            ],
            'cutoff' => $cutoff->toDateString(),
            'synced_at' => $syncedAt !== null ? Carbon::parse((string) $syncedAt)->toIso8601String() : null,
        ]);
    }

    /**
     * Towar ze stanem, którego ostatnia sprzedaż (FS, paragon, WZ) jest starsza niż próg. Nigdy niesprzedany liczy się
     * tylko wtedy, gdy jego najstarsza partia leży dłużej niż próg (albo jej data jest nieznana) — inaczej świeża
     * dostawa nowego towaru wyglądałaby jak zaleganie.
     *
     * @param  array<string, mixed>  $v
     * @return Builder<ErpItem>
     */
    private function filtered(array $v, CarbonImmutable $cutoff, bool $neverSold): Builder
    {
        $date = $cutoff->toDateString();
        $query = ErpItem::query()
            ->whereNull('removed_at')
            ->where('stock_total', '>', 0)
            ->where(function (Builder $q) use ($date, $neverSold): void {
                $q->where('last_sale_at', '<', $date);
                if ($neverSold) {
                    $q->orWhere(fn (Builder $n) => $n->whereNull('last_sale_at')
                        ->where(fn (Builder $l) => $l->whereNull('oldest_lot_at')->orWhere('oldest_lot_at', '<=', $date)));
                }
            });

        $card = (string) ($v['card'] ?? '');
        if ($card === 'with') {
            $query->whereHas('links', fn (Builder $q) => ErpItemCards::linked($q));
        } elseif ($card === 'without') {
            $query->whereDoesntHave('links', fn (Builder $q) => ErpItemCards::linked($q));
        }
        $group = (string) ($v['group'] ?? '');
        if ($group === 'other') {
            foreach (self::GROUPS as $letter) {
                $query->where('code', 'not like', $letter.'%');
            }
        } elseif ($group !== '') {
            $query->where('code', 'like', $group.'%');
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
                ->orWhereHas('links', fn (Builder $l) => ErpItemCards::linked($l)
                    ->whereHas('product', fn (Builder $p) => $p->where('sku', 'like', $like))));
        }

        return $query;
    }

    private function like(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /**
     * Liczba par RW → PW towarów ze strony — jedno zapytanie.
     *
     * @param  list<int>  $itemIds
     * @return array<int, int>
     */
    private function rwPwCounts(array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        return ErpRwPwPair::query()
            ->whereIn('erp_item_id', $itemIds)
            ->where('rw_date', '>=', CarbonImmutable::today()->subMonthsNoOverflow(self::RW_PW_MONTHS)->toDateString())
            ->where('gap_days', '<=', self::RW_PW_GAP_DAYS)
            ->groupBy('erp_item_id')
            ->selectRaw('erp_item_id, count(*) as c')
            ->pluck('c', 'erp_item_id')
            ->map(static fn ($c): int => (int) $c)
            ->all();
    }

    /**
     * @param  array{card: array<string, mixed>|null, cards_count: int}  $card
     * @return array<string, mixed>
     */
    private function present(ErpItem $item, array $card, int $rwPwPairs): array
    {
        /** @var ErpItemPurchase|null $purchase */
        $purchase = $item->purchases->first();

        return [
            'id' => $item->id,
            'xl_gid' => $item->xl_gid,
            'code' => $item->code,
            'name' => $item->name,
            'name1' => $item->name1,
            'unit' => $item->unit,
            'archived' => (bool) $item->archived,
            'stock_total' => (float) $item->stock_total,
            'stock_trade' => (float) $item->stock_trade,
            // ilość × cena zakupu: partie na stanie, a bez nich stan × ostatnia PZ; null = ani partii, ani PZ z ceną
            'stock_value' => $item->getAttribute('purchase_value') !== null ? round((float) $item->getAttribute('purchase_value'), 2) : null,
            'value_source' => match (true) {
                $item->stock_value !== null => 'lots',
                $item->getAttribute('purchase_value') !== null => 'last_purchase',
                default => null,
            },
            // średnia cena zakupu towaru na stanie = wartość partii ÷ ilość; ostatnia PZ bywa błędna (SNAU51000-04-S:
            // PZ 1 szt. za 11 600,60 zł, partie po korekcie RW/PW po 290,02 zł)
            'unit_cost' => $item->stock_value !== null && (float) $item->stock_total > 0
                ? round((float) $item->stock_value / (float) $item->stock_total, 4)
                : null,
            'warehouses' => array_values(array_map(static fn (array $w): array => [
                'code' => (string) ($w['code'] ?? ''),
                'name' => (string) ($w['name'] ?? ''),
                'quantity' => (float) ($w['quantity'] ?? 0),
                'value' => isset($w['value']) ? (float) $w['value'] : null,
            ], $item->stock_by_warehouse ?? [])),
            'last_sale_at' => $item->last_sale_at?->toDateString(),
            'oldest_lot_at' => $item->oldest_lot_at?->toDateString(),
            'last_purchase' => $purchase === null ? null : [
                'date' => $purchase->purchased_at?->toDateString(),
                'supplier' => $purchase->supplier,
                'unit_price_pln' => $purchase->unit_price_pln !== null ? (float) $purchase->unit_price_pln : null,
                'document_price' => $purchase->document_price !== null ? (float) $purchase->document_price : null,
                'currency' => $purchase->currency,
            ],
            'card' => $card['card'],
            'cards_count' => $card['cards_count'],
            'rw_pw_pairs' => $rwPwPairs,
        ];
    }
}
