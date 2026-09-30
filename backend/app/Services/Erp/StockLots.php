<?php

declare(strict_types=1);

namespace App\Services\Erp;

use App\Support\XlTimestamp;
use Illuminate\Support\Facades\DB;

/**
 * Partie towaru XL na stanie (erp_item_stock_lots) — do rozbicia „jak długo leży” w raporcie dla zarządu (decyzja
 * właściciela 01.10.2026: wartość samych dostaw z danego okresu, nie całego towaru według najstarszej dostawy).
 *
 * Wiersz = towar × magazyn × dzień przyjęcia (TwZ_DataP, XlTimestamp): ilość i wartość księgowa netto partii; data null =
 * XL nie podał. Zapis przy odczycie stanów (ErpItemSync) — zastępuje całe partie towaru.
 */
final class StockLots
{
    public const TABLE = 'erp_item_stock_lots';

    /**
     * @param  list<array{warehouse_code: string, received_at: int|null, quantity: float, value: float|null}>  $lots
     */
    public static function replace(int $itemId, array $lots): void
    {
        DB::table(self::TABLE)->where('erp_item_id', $itemId)->delete();
        $rows = self::rows($itemId, $lots);
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table(self::TABLE)->insert($chunk);
        }
    }

    /**
     * Partie tego samego dnia na tym samym magazynie sumowane; wartość null, gdy któraś z nich jej nie ma (suma bez niej
     * zaniżałaby zapas — jak stan na magazyn). Bez ilości — pominięte.
     *
     * @param  list<array{warehouse_code: string, received_at: int|null, quantity: float, value: float|null}>  $lots
     * @return list<array{erp_item_id: int, warehouse_code: string, location: string|null, received_at: string|null, quantity: float, value: float|null}>
     */
    public static function rows(int $itemId, array $lots): array
    {
        $out = [];
        foreach ($lots as $lot) {
            $code = mb_substr(trim((string) $lot['warehouse_code']), 0, 20);
            $quantity = (float) $lot['quantity'];
            if ($code === '' || $quantity <= 0) {
                continue;
            }
            $day = XlTimestamp::toDate($lot['received_at'])?->toDateString();
            $value = $lot['value'] !== null ? (float) $lot['value'] : null;
            $key = $code."\0".($day ?? '');
            if (isset($out[$key])) {
                $prev = $out[$key];
                $out[$key]['quantity'] = round($prev['quantity'] + $quantity, 4);
                $out[$key]['value'] = $prev['value'] === null || $value === null ? null : round($prev['value'] + $value, 2);

                continue;
            }
            $out[$key] = [
                'erp_item_id' => $itemId,
                'warehouse_code' => $code,
                'location' => WarehouseLocations::of($code),
                'received_at' => $day,
                'quantity' => round($quantity, 4),
                'value' => $value !== null ? round($value, 2) : null,
            ];
        }

        return array_values($out);
    }
}
