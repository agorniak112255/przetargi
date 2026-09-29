<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ErpItem;
use App\Models\ErpRwPwPair;
use App\Services\Erp\ErpItemCards;
use App\Services\Erp\InventoryQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Raport zapasów dla zarządu (decyzje użytkownika 30.09.2026): jedna strona, gotowe progi, same fakty — bez zaleceń;
 * przy „odmładzaniu” towaru (pary RW → PW) także imiona i nazwiska. Reguły wartości i zalegania jak na liście
 * „Zalegające” (InventoryQuery). Niczego nie zapisuje.
 */
class InventoryBoardController extends Controller
{
    private const NO_SALE_MONTHS = [6, 12, 24];

    private const LOT_AGE_MONTHS = [12, 36, 60];

    /** Lista najdroższych pozycji bez sprzedaży ponad rok. */
    private const TOP_UNSOLD_MONTHS = 12;

    private const TOP_UNSOLD_LIMIT = 5;

    /** „Odmładzanie”: pary z 12 miesięcy, PW do 3 dni po RW — jak domyślne filtry zakładki RW → PW. */
    private const MOVES_MONTHS = 12;

    private const MOVES_GAP_DAYS = 3;

    private const PEOPLE_LIMIT = 5;

    public function __construct(private readonly ErpItemCards $cards) {}

    public function show(): JsonResponse
    {
        $today = CarbonImmutable::today();
        $stock = InventoryQuery::totals(InventoryQuery::inStock());

        $noSale = [];
        foreach (self::NO_SALE_MONTHS as $months) {
            $t = InventoryQuery::totals(InventoryQuery::unsoldSince(InventoryQuery::inStock(), $today->subMonthsNoOverflow($months)));
            $noSale[] = ['months' => $months, 'items' => $t['items'], 'value' => $t['value']];
        }
        $lotAge = [];
        foreach (self::LOT_AGE_MONTHS as $months) {
            $t = InventoryQuery::totals(InventoryQuery::lotOlderThan(InventoryQuery::inStock(), $today->subMonthsNoOverflow($months)));
            $lotAge[] = ['months' => $months, 'items' => $t['items'], 'value' => $t['value']];
        }
        // nigdy niesprzedany i leży dłużej niż pół roku (świeża dostawa nowego towaru to nie zaleganie)
        $halfYear = $today->subMonthsNoOverflow(6)->toDateString();
        $never = InventoryQuery::totals(InventoryQuery::inStock()->whereNull('last_sale_at')
            ->where(fn ($l) => $l->whereNull('oldest_lot_at')->orWhere('oldest_lot_at', '<=', $halfYear)));

        $syncedAt = ErpItem::query()->whereNull('removed_at')->max('synced_at');

        return response()->json([
            'as_of' => $syncedAt !== null ? Carbon::parse((string) $syncedAt)->toIso8601String() : null,
            'stock' => ['items' => $stock['items'], 'value' => $stock['value']],
            'no_sale' => $noSale,
            'never_sold' => ['items' => $never['items'], 'value' => $never['value']],
            'lot_age' => $lotAge,
            'top_unsold' => $this->topUnsold($today->subMonthsNoOverflow(self::TOP_UNSOLD_MONTHS)),
            'internal_moves' => $this->internalMoves($today->subMonthsNoOverflow(self::MOVES_MONTHS)),
            'value_unknown' => $stock['value_unknown'],
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function topUnsold(CarbonImmutable $cutoff): array
    {
        $items = InventoryQuery::unsoldSince(InventoryQuery::inStock(), $cutoff)
            ->select('erp_items.*')
            ->selectRaw(InventoryQuery::VALUE_SQL.' as purchase_value')
            ->whereRaw(InventoryQuery::VALUE_SQL.' is not null')
            ->orderByRaw(InventoryQuery::VALUE_SQL.' desc')
            ->orderBy('id')
            ->limit(self::TOP_UNSOLD_LIMIT)
            ->with(ErpItemCards::eagerLinks())
            ->get();
        $cards = $this->cards->forItems($items);

        return $items->map(fn (ErpItem $item): array => [
            'code' => $item->code,
            'name' => $item->name,
            'quantity' => (float) $item->stock_total,
            'unit' => $item->unit,
            'value' => round((float) $item->getAttribute('purchase_value'), 2),
            'last_sale_at' => $item->last_sale_at?->toDateString(),
            'card_name' => $cards[(int) $item->id]['card']['name'] ?? null,
        ])->values()->all();
    }

    /**
     * Pary RW → PW: wszystkie (najczęściej zamiana rozmiaru) i bez wyjaśnienia — ta sama cecha partii i puste uwagi RW.
     *
     * @return array<string, mixed>
     */
    private function internalMoves(CarbonImmutable $from): array
    {
        $pairs = fn () => ErpRwPwPair::query()
            ->where('rw_date', '>=', $from->toDateString())
            ->where('gap_days', '<=', self::MOVES_GAP_DAYS);
        $unexplained = fn () => $pairs()->where('same_feature', true)->whereNull('rw_note');

        $people = $unexplained()->toBase()
            ->selectRaw('rw_operator, max(rw_operator_name) as name, count(*) as c')
            ->groupBy('rw_operator')
            ->orderByDesc('c')
            ->orderBy('rw_operator')
            ->limit(self::PEOPLE_LIMIT)
            ->get()
            ->map(static fn ($row): array => [
                'name' => (string) ($row->name ?? $row->rw_operator ?? 'osoba nieznana'),
                'count' => (int) $row->c,
            ])
            ->values()
            ->all();

        return [
            'from' => $from->toDateString(),
            'total' => $pairs()->count(),
            'unexplained' => $unexplained()->count(),
            'unexplained_value' => round((float) $unexplained()->sum('rw_value'), 2),
            'people' => $people,
        ];
    }
}
