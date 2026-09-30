<?php

declare(strict_types=1);

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Oddziały magazynów XL (filtr Zapasów i raportu dla zarządu): magazyny łączone po cyfrach na początku kodu — 01H, 01MTU,
 * 01JS → 01 Rzeszów (decyzja właściciela 01.10.2026); 14U (Kraków – usługi) należy do Krakowa (15).
 *
 * Nazwy i przypisania w kodzie, nie w config: migracje przeliczają oddziały przed config:cache wdrożenia.
 *
 * Stan na magazyn w tabeli erp_item_warehouse_stocks = kopia rozbicia erp_items.stock_by_warehouse (źródłem jest rozbicie):
 * zapis przy odczycie stanów (ErpItemSync) i przebudowa w erp:warehouse-split. Ostatnia sprzedaż na magazyn dokumentu
 * w erp_item_warehouse_sales — z nocnej kopii (ErpItemSync).
 */
final class WarehouseLocations
{
    public const TABLE = 'erp_item_warehouse_stocks';

    public const SALES_TABLE = 'erp_item_warehouse_sales';

    /** Oddziały w kolejności listy wyboru; spoza listy — „Magazyny NN”. */
    public const NAMES = [
        '01' => 'Rzeszów',
        '11' => 'Tarnów',
        '15' => 'Kraków',
        '13' => 'Stalowa Wola',
        '20' => 'Sanok',
        '10' => 'Łódź',
    ];

    /** Cyfry kodu magazynu, które należą do innego oddziału: 14U Kraków – usługi → Kraków (decyzja 01.10.2026). */
    public const ALIASES = ['14' => '15'];

    /** Oddział magazynu: cyfry z początku kodu (z ALIASES); kod bez cyfr na początku — bez oddziału. */
    public static function of(string $warehouseCode): ?string
    {
        if (preg_match('/^\d{1,10}/', trim($warehouseCode), $m) !== 1) {
            return null;
        }

        return self::ALIASES[$m[0]] ?? $m[0];
    }

    /** Oddział wolno wkleić do SQL tylko po tej kontroli (same cyfry). */
    public static function assertValid(string $location): string
    {
        if (preg_match('/^\d{1,10}$/', $location) !== 1) {
            throw new InvalidArgumentException('Nieznany oddział magazynów: '.$location);
        }

        return $location;
    }

    public static function name(string $location): string
    {
        return self::NAMES[$location] ?? 'Magazyny '.$location;
    }

    /**
     * Oddziały, w których jakiś towar (nieusunięty z XL) ma stan — kolejność jak w NAMES, reszta po kodzie.
     *
     * @return list<array{key: string, name: string}>
     */
    public static function available(): array
    {
        $keys = DB::table(self::TABLE.' as s')
            ->join('erp_items as i', 'i.id', '=', 's.erp_item_id')
            ->whereNull('i.removed_at')
            ->whereNotNull('s.location')
            ->where('s.quantity', '>', 0)
            ->distinct()
            ->pluck('s.location')
            ->map(static fn ($l): string => (string) $l)
            ->all();
        $order = array_map('strval', array_keys(self::NAMES));
        usort($keys, static function (string $a, string $b) use ($order): int {
            $ia = array_search($a, $order, true);
            $ib = array_search($b, $order, true);

            return [$ia === false ? PHP_INT_MAX : $ia, $a] <=> [$ib === false ? PHP_INT_MAX : $ib, $b];
        });

        return array_map(static fn (string $key): array => ['key' => $key, 'name' => self::name($key)], $keys);
    }

    /**
     * Zastępuje stan towaru na magazynach rozbiciem z odczytu (ten sam magazyn dwa razy — sumowany).
     *
     * @param  list<array{code?: string, quantity?: float|int, value?: float|null, oldest_lot?: string|null}>  $warehouses
     */
    public static function replace(int $itemId, array $warehouses): void
    {
        DB::table(self::TABLE)->where('erp_item_id', $itemId)->delete();
        $rows = self::rows($itemId, $warehouses);
        if ($rows !== []) {
            DB::table(self::TABLE)->insert($rows);
        }
    }

    /**
     * Zastępuje ostatnią sprzedaż towaru na magazynach. Dokument bez magazynu — kod '' bez oddziału (towar ma wtedy
     * wiersz, więc wiadomo, że sprzedaż na magazyn jest już odczytana).
     *
     * @param  list<array{warehouse_code: string|null, last_sale_at: string|null}>  $sales
     */
    public static function replaceSales(int $itemId, array $sales): void
    {
        DB::table(self::SALES_TABLE)->where('erp_item_id', $itemId)->delete();
        $rows = [];
        foreach ($sales as $sale) {
            if ($sale['last_sale_at'] === null) {
                continue;
            }
            $code = mb_substr(trim((string) $sale['warehouse_code']), 0, 20);
            if (! isset($rows[$code]) || $sale['last_sale_at'] > $rows[$code]['last_sale_at']) {
                $rows[$code] = [
                    'erp_item_id' => $itemId,
                    'warehouse_code' => $code,
                    'location' => $code !== '' ? self::of($code) : null,
                    'last_sale_at' => $sale['last_sale_at'],
                ];
            }
        }
        if ($rows !== []) {
            DB::table(self::SALES_TABLE)->insert(array_values($rows));
        }
    }

    /**
     * @param  list<array{code?: string, quantity?: float|int, value?: float|null, oldest_lot?: string|null}>  $warehouses
     * @return list<array{erp_item_id: int, warehouse_code: string, location: string|null, quantity: float, value: float|null, oldest_lot_at: string|null}>
     */
    public static function rows(int $itemId, array $warehouses): array
    {
        $out = [];
        foreach ($warehouses as $w) {
            $code = mb_substr(trim((string) ($w['code'] ?? '')), 0, 20);
            if ($code === '') {
                continue;
            }
            $value = isset($w['value']) ? round((float) $w['value'], 2) : null;
            $lot = isset($w['oldest_lot']) && $w['oldest_lot'] !== '' ? substr((string) $w['oldest_lot'], 0, 10) : null;
            $quantity = (float) ($w['quantity'] ?? 0);
            if (isset($out[$code])) {
                $prev = $out[$code];
                $out[$code]['quantity'] = round($prev['quantity'] + $quantity, 4);
                $out[$code]['value'] = $prev['value'] === null || $value === null ? null : round($prev['value'] + $value, 2);
                $out[$code]['oldest_lot_at'] = $prev['oldest_lot_at'] === null ? $lot : ($lot === null ? $prev['oldest_lot_at'] : min($prev['oldest_lot_at'], $lot));

                continue;
            }
            $out[$code] = [
                'erp_item_id' => $itemId,
                'warehouse_code' => $code,
                'location' => self::of($code),
                'quantity' => round($quantity, 4),
                'value' => $value,
                'oldest_lot_at' => $lot,
            ];
        }

        return array_values($out);
    }
}
