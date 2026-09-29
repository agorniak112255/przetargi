<?php

declare(strict_types=1);

namespace App\Services\Erp;

use App\Models\ErpItem;
use App\Models\ErpItemLink;
use Carbon\CarbonImmutable;

/**
 * Blok „Stan w ERP XL” na karcie: towary XL powiązane z kartą (auto i potwierdzone), ich stany i ostatnie PZ.
 * Suma stanów tylko przy jednej jednostce — „szt” i „par” albo „opk” to różne ilości, więc przy mieszanych jednostkach
 * suma = null i widać stan każdego towaru osobno. Propozycje (suggested) tylko jako liczba do sprawdzenia.
 */
final class ErpCardStock
{
    /** Kopia starsza niż doba z zapasem (przebieg nocny) — karta ostrzega, że stan może być nieaktualny. */
    private const STALE_HOURS = 36;

    /**
     * @return array<string, mixed>|null null, gdy karta nie ma żadnego powiązania z XL
     */
    public function forProduct(int $productId): ?array
    {
        $links = ErpItemLink::query()
            ->where('product_id', $productId)
            ->whereIn('status', [ErpItemLink::STATUS_AUTO, ErpItemLink::STATUS_CONFIRMED, ErpItemLink::STATUS_SUGGESTED])
            ->whereHas('item', fn ($q) => $q->whereNull('removed_at'))
            ->with(['item.purchases'])
            ->get();
        if ($links->isEmpty()) {
            return null;
        }
        $suggested = $links->where('status', ErpItemLink::STATUS_SUGGESTED)->count();
        $linked = $links->where('status', '!=', ErpItemLink::STATUS_SUGGESTED)
            ->sortByDesc(fn (ErpItemLink $l): float => (float) $l->item->stock_trade)
            ->values();

        $items = [];
        $units = [];
        $warehouses = [];
        $trade = 0.0;
        $syncedAt = null;
        $lastPurchase = null;
        foreach ($linked as $link) {
            /** @var ErpItem $item */
            $item = $link->item;
            $unit = trim((string) $item->unit);
            $units[mb_strtolower(rtrim($unit, '.'))] = true;
            $trade += (float) $item->stock_trade;
            foreach ($item->stock_by_warehouse ?? [] as $w) {
                $code = (string) ($w['code'] ?? '');
                $warehouses[$code] ??= ['code' => $code, 'name' => (string) ($w['name'] ?? ''), 'quantity' => 0.0];
                $warehouses[$code]['quantity'] += (float) ($w['quantity'] ?? 0);
            }
            // czas odczytu stanu: odświeżanie w ciągu dnia (erp:stock) albo nocna kopia
            $readAt = $item->stock_synced_at ?? $item->synced_at;
            if ($readAt !== null && ($syncedAt === null || $readAt->lessThan($syncedAt))) {
                $syncedAt = CarbonImmutable::parse($readAt);
            }
            $purchases = $item->purchases->take(3)->map(static fn ($p): array => [
                'date' => $p->purchased_at?->toDateString(),
                'supplier' => $p->supplier,
                'quantity' => (float) $p->quantity,
                'unit' => $unit !== '' ? $unit : null,
                'unit_price_pln' => $p->unit_price_pln !== null ? (float) $p->unit_price_pln : null,
                'document_price' => $p->document_price !== null ? (float) $p->document_price : null,
                'currency' => $p->currency,
                'document_id' => (int) $p->document_id,
            ])->values()->all();
            $newest = $purchases[0] ?? null;
            if ($newest !== null && ($lastPurchase === null || (string) $newest['date'] > (string) $lastPurchase['date'])) {
                $lastPurchase = $newest + ['xl_code' => $item->code];
            }
            $items[] = [
                'xl_gid' => $item->xl_gid,
                'code' => $item->code,
                'name' => $item->name,
                'name1' => $item->name1,
                'unit' => $unit !== '' ? $unit : null,
                'archived' => (bool) $item->archived,
                'status' => $link->status,
                'method' => $link->method,
                'matched_value' => $link->matched_value,
                'stock_trade' => (float) $item->stock_trade,
                'stock_total' => (float) $item->stock_total,
                'warehouses' => array_values($item->stock_by_warehouse ?? []),
                'last_sale_at' => $item->last_sale_at?->toDateString(),
                'purchases' => $purchases,
            ];
        }

        $singleUnit = count($units) <= 1;
        usort($warehouses, static fn (array $a, array $b): int => $b['quantity'] <=> $a['quantity'] ?: strcmp($a['code'], $b['code']));

        return [
            'items' => $items,
            'unit' => $singleUnit && $items !== [] ? $items[0]['unit'] : null,
            'stock_trade' => $singleUnit ? $trade : null,
            'warehouses' => $singleUnit ? array_values($warehouses) : null,
            'last_purchase' => $lastPurchase,
            'suggested' => $suggested,
            'synced_at' => $syncedAt?->toIso8601String(),
            'stale' => $syncedAt !== null && $syncedAt->lessThan(CarbonImmutable::now()->subHours(self::STALE_HOURS)),
        ];
    }
}
