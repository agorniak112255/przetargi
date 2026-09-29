<?php

declare(strict_types=1);

namespace App\Services\Erp;

use App\Models\ErpItem;
use App\Models\ErpItemPurchase;
use App\Support\ClarionDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Kopia towarów ERP XL: paczkami po numerze towaru (Twr_GIDNumer) — w pamięci jest tylko jedna paczka (CLI na serwerze
 * ma 128 MB). Na towar: stany z rozbiciem na magazyny, dostawcy z karty towaru, ostatnie pozycje PZ, data ostatniej
 * sprzedaży. Pełny przebieg oznacza removed_at towarom, których XL już nie zwrócił.
 */
final class ErpItemSync
{
    public function __construct(private readonly ErpXlGateway $gateway) {}

    /**
     * @param  (callable(int): void)|null  $progress  liczba zapisanych towarów po każdej paczce
     * @return array{items: int, with_trade_stock: int, purchases: int, removed: int}
     */
    public function run(?int $limit = null, ?callable $progress = null): array
    {
        if (! $this->gateway->configured()) {
            throw new RuntimeException('Połączenie z ERP XL jest wyłączone albo nieuzupełnione (ERPXL_*).');
        }

        $startedAt = CarbonImmutable::now();
        $batch = max(1, (int) config('erpxl.batch', 500));
        $perItem = max(1, (int) config('erpxl.purchases_per_item', 3));
        $after = 0;
        $stats = ['items' => 0, 'with_trade_stock' => 0, 'purchases' => 0, 'removed' => 0];

        while (true) {
            $take = $limit === null ? $batch : min($batch, $limit - $stats['items']);
            if ($take <= 0) {
                break;
            }
            $items = $this->gateway->items($after, $take);
            if ($items === []) {
                break;
            }
            $after = max(array_column($items, 'gid'));
            $counts = $this->saveBatch($items, $perItem, $startedAt);
            $stats['items'] += count($items);
            $stats['with_trade_stock'] += $counts['with_trade_stock'];
            $stats['purchases'] += $counts['purchases'];
            if ($progress !== null) {
                $progress($stats['items']);
            }
            if (count($items) < $take) {
                break;
            }
        }

        // tylko pełny przebieg wie, czego w XL już nie ma
        if ($limit === null) {
            $stats['removed'] = ErpItem::query()
                ->whereNull('removed_at')
                ->where(fn ($q) => $q->whereNull('synced_at')->orWhere('synced_at', '<', $startedAt))
                ->update(['removed_at' => $startedAt]);
        }

        return $stats;
    }

    /**
     * @param  list<array{gid: int, code: string, name: string, name1: string, ean: string, unit: string, archived: bool}>  $items
     * @return array{with_trade_stock: int, purchases: int}
     */
    private function saveBatch(array $items, int $perItem, CarbonImmutable $now): array
    {
        $gids = array_column($items, 'gid');
        $stock = $this->group($this->gateway->stock($gids));
        $suppliers = $this->group($this->gateway->suppliers($gids));
        $purchases = $this->group($this->gateway->purchases($gids, $perItem));
        $lastSales = $this->gateway->lastSales($gids);
        $tradePrefix = mb_strtolower((string) config('erpxl.trade_warehouse_prefix', 'Magazyn HANDEL'));

        $withTrade = 0;
        $savedPurchases = 0;
        DB::transaction(function () use ($items, $stock, $suppliers, $purchases, $lastSales, $tradePrefix, $now, &$withTrade, &$savedPurchases): void {
            foreach ($items as $item) {
                $gid = $item['gid'];
                $stockFields = $this->stockFields($stock[$gid] ?? [], $tradePrefix);
                if ($stockFields['stock_trade'] > 0) {
                    $withTrade++;
                }

                $supplierRows = array_map(static fn (array $s): array => [
                    'supplier_id' => $s['supplier_id'],
                    'supplier' => $s['supplier'],
                    'price' => $s['price'],
                    'currency' => $s['currency'],
                    'updated_at' => ClarionDate::toDate($s['updated'])?->toDateString(),
                ], $suppliers[$gid] ?? []);

                $itemPurchases = $purchases[$gid] ?? [];
                $lastPurchase = null;
                $lastSupplier = null;
                foreach ($itemPurchases as $p) {
                    $date = ClarionDate::toDate($p['date']);
                    if ($date !== null && ($lastPurchase === null || $date->greaterThan($lastPurchase))) {
                        $lastPurchase = $date;
                        $lastSupplier = $p['supplier'] !== '' ? mb_substr($p['supplier'], 0, 100) : null;
                    }
                }

                $model = ErpItem::query()->updateOrCreate(['xl_gid' => $gid], [
                    'code' => mb_substr($item['code'], 0, 100),
                    'name' => mb_substr($item['name'], 0, 500),
                    'name1' => $item['name1'] !== '' ? mb_substr($item['name1'], 0, 500) : null,
                    'ean' => $item['ean'] !== '' ? mb_substr($item['ean'], 0, 64) : null,
                    'unit' => $item['unit'] !== '' ? mb_substr($item['unit'], 0, 20) : null,
                    'archived' => $item['archived'],
                    ...$stockFields,
                    'stock_synced_at' => $now,
                    'suppliers' => $supplierRows,
                    'last_purchase_at' => $lastPurchase?->toDateString(),
                    'last_supplier' => $lastSupplier,
                    'last_sale_at' => ClarionDate::toDate($lastSales[$gid] ?? null)?->toDateString(),
                    'synced_at' => $now,
                    'removed_at' => null,
                ]);

                ErpItemPurchase::query()->where('erp_item_id', $model->id)->delete();
                foreach ($itemPurchases as $p) {
                    ErpItemPurchase::query()->create([
                        'erp_item_id' => $model->id,
                        'document_type' => $p['document_type'],
                        'document_id' => $p['document_id'],
                        'document_line' => $p['document_line'],
                        'document_state' => $p['document_state'],
                        'purchased_at' => ClarionDate::toDate($p['date'])?->toDateString(),
                        'supplier_xl_id' => $p['supplier_id'],
                        'supplier' => $p['supplier'] !== '' ? mb_substr($p['supplier'], 0, 100) : null,
                        'quantity' => $p['quantity'],
                        'document_unit' => $p['document_unit'] !== '' ? $p['document_unit'] : null,
                        'net_value_pln' => $p['net_value_pln'],
                        'unit_price_pln' => $p['quantity'] > 0 ? round($p['net_value_pln'] / $p['quantity'], 4) : null,
                        'document_price' => $p['document_price'],
                        'currency' => $p['currency'] !== '' ? $p['currency'] : null,
                    ]);
                    $savedPurchases++;
                }
            }
        });

        return ['with_trade_stock' => $withTrade, 'purchases' => $savedPurchases];
    }

    /**
     * Same stany towarów już skopiowanych (bez nazw, dostawców i zakupów) — odświeżanie w ciągu dnia. Zapis tylko
     * zmienionych wierszy; stock_synced_at dostają wszystkie przeczytane towary (karta pokazuje czas odczytu stanu).
     *
     * @param  (callable(int): void)|null  $progress
     * @return array{items: int, changed: int}
     */
    public function refreshStock(?callable $progress = null): array
    {
        if (! $this->gateway->configured()) {
            throw new RuntimeException('Połączenie z ERP XL jest wyłączone albo nieuzupełnione (ERPXL_*).');
        }
        $now = CarbonImmutable::now();
        $batch = max(1, (int) config('erpxl.batch', 500));
        $tradePrefix = mb_strtolower((string) config('erpxl.trade_warehouse_prefix', 'Magazyn HANDEL'));
        $stats = ['items' => 0, 'changed' => 0];

        ErpItem::query()
            ->whereNull('removed_at')
            ->select(['id', 'xl_gid', 'stock_trade', 'stock_total', 'stock_by_warehouse'])
            ->chunkById($batch, function ($items) use ($tradePrefix, $now, &$stats, $progress): void {
                $stock = $this->group($this->gateway->stock($items->pluck('xl_gid')->map(fn ($g) => (int) $g)->all()));
                DB::transaction(function () use ($items, $stock, $tradePrefix, $now, &$stats): void {
                    $unchanged = [];
                    foreach ($items as $item) {
                        $fields = $this->stockFields($stock[(int) $item->xl_gid] ?? [], $tradePrefix);
                        $same = abs((float) $item->stock_trade - $fields['stock_trade']) < 0.00005
                            && abs((float) $item->stock_total - $fields['stock_total']) < 0.00005
                            && ($item->stock_by_warehouse ?? []) == $fields['stock_by_warehouse'];
                        if ($same) {
                            $unchanged[] = $item->id;

                            continue;
                        }
                        ErpItem::query()->whereKey($item->id)->update([
                            'stock_trade' => $fields['stock_trade'],
                            'stock_total' => $fields['stock_total'],
                            'stock_by_warehouse' => json_encode($fields['stock_by_warehouse']),
                            'stock_synced_at' => $now,
                        ]);
                        $stats['changed']++;
                    }
                    if ($unchanged !== []) {
                        ErpItem::query()->whereIn('id', $unchanged)->update(['stock_synced_at' => $now]);
                    }
                });
                $stats['items'] += $items->count();
                if ($progress !== null) {
                    $progress($stats['items']);
                }
            });

        return $stats;
    }

    /**
     * Stan towaru z wierszy XL (suma zasobów na magazyn): HANDEL = magazyny o nazwie z erpxl.trade_warehouse_prefix,
     * rozbicie od największego stanu.
     *
     * @param  list<array{gid: int, warehouse_code: string, warehouse_name: string, quantity: float}>  $rows
     * @return array{stock_trade: float, stock_total: float, stock_by_warehouse: list<array{code: string, name: string, quantity: float}>}
     */
    private function stockFields(array $rows, string $tradePrefix): array
    {
        $warehouses = [];
        $trade = 0.0;
        $total = 0.0;
        foreach ($rows as $row) {
            $warehouses[] = ['code' => $row['warehouse_code'], 'name' => $row['warehouse_name'], 'quantity' => $row['quantity']];
            $total += $row['quantity'];
            if ($tradePrefix !== '' && str_starts_with(mb_strtolower($row['warehouse_name']), $tradePrefix)) {
                $trade += $row['quantity'];
            }
        }
        usort($warehouses, static fn (array $a, array $b): int => $b['quantity'] <=> $a['quantity'] ?: strcmp($a['code'], $b['code']));

        return ['stock_trade' => $trade, 'stock_total' => $total, 'stock_by_warehouse' => $warehouses];
    }

    /**
     * @template T of array{gid: int}
     *
     * @param  list<T>  $rows
     * @return array<int, list<T>>
     */
    private function group(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[$row['gid']][] = $row;
        }

        return $out;
    }
}
