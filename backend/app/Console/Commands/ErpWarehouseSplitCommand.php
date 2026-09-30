<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ErpItem;
use App\Models\ErpWarehouse;
use App\Services\Erp\WarehouseLocations;
use App\Services\Erp\WarehouseSplit;
use Illuminate\Console\Command;

class ErpWarehouseSplitCommand extends Command
{
    protected $signature = 'erp:warehouse-split';

    protected $description = 'Przelicza część towaru w magazynach usługowych i stan na magazyn (oddziały) z zapisanego rozbicia stanów (po zmianie słownika magazynów; bez odczytu z ERP XL)';

    public function handle(): int
    {
        $serviceCodes = ErpWarehouse::serviceCodes();
        $changed = 0;
        $seen = 0;
        ErpItem::query()
            ->select(['id', 'stock_by_warehouse', 'oldest_lot_at', 'stock_service', 'stock_service_value', 'oldest_lot_trade_at', 'oldest_lot_service_at'])
            ->chunkById(500, function ($items) use ($serviceCodes, &$changed, &$seen): void {
                foreach ($items as $item) {
                    $seen++;
                    // kopia rozbicia na wiersze (filtr oddziału) — zawsze od nowa, bez porównywania
                    WarehouseLocations::replace((int) $item->id, $item->stock_by_warehouse ?? []);
                    $split = WarehouseSplit::compute($item->stock_by_warehouse ?? [], $serviceCodes, $item->oldest_lot_at?->toDateString());
                    $same = abs((float) $item->stock_service - $split['stock_service']) < 0.00005
                        && ($item->stock_service_value === null) === ($split['stock_service_value'] === null)
                        && abs((float) $item->stock_service_value - (float) $split['stock_service_value']) < 0.005
                        && $item->oldest_lot_trade_at?->toDateString() === $split['oldest_lot_trade_at']
                        && $item->oldest_lot_service_at?->toDateString() === $split['oldest_lot_service_at'];
                    if (! $same) {
                        ErpItem::query()->whereKey($item->id)->update($split);
                        $changed++;
                    }
                }
            });
        $this->info(sprintf('Magazyny usługowe: %s. Towarów: %d, przeliczonych: %d.', implode(', ', $serviceCodes) ?: 'brak', $seen, $changed));

        return self::SUCCESS;
    }
}
