<?php

declare(strict_types=1);

namespace App\Services\Erp;

use App\Models\ErpItem;
use App\Models\ErpWarehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Codzienny zapis stanu zapasów (historia raportu dla zarządu, decyzja właściciela 01.10.2026). Dzień zapisu = dzień
 * odczytu stanów z XL (czas polski), koszyki liczone na ten dzień — jak kafelki raportu.
 *
 * Zapis tylko po pełnym odczycie z dziś: najwcześniejszy stock_synced_at towarów (bez usuniętych z XL) musi być z dziś —
 * przerwana synchronizacja zostawia część towarów ze starym stanem i wtedy dnia nie zapisujemy (luka na wykresie zamiast
 * pomieszanych stanów). Raz zapisanego dnia nie nadpisuje (chyba że force), więc ręczny odczyt w dzień nie zastąpi
 * nocnego obrazu.
 */
final class InventorySnapshots
{
    public const TABLE = 'erp_inventory_snapshots';

    public const WAREHOUSE_TABLE = 'erp_inventory_warehouse_snapshots';

    public const TIMEZONE = 'Europe/Warsaw';

    /** Wersja reguł koszyków zapisana przy każdym wierszu — zmiana reguł = nowa wersja (historia nieporównywalna wprost). */
    public const RULES_VERSION = 2; // 2: „jak długo leży” narastająco (lot_age_6…60) zamiast przedziałów lot_age_0_6…

    /** Dzień ostatniego pełnego odczytu stanów (czas polski) albo null, gdy nic jeszcze nie odczytano. */
    public function readingDate(): ?CarbonImmutable
    {
        $oldest = ErpItem::query()->whereNull('removed_at')->whereNotNull('stock_synced_at')->min('stock_synced_at');
        if ($oldest === null) {
            return null;
        }

        return CarbonImmutable::parse((string) $oldest, 'UTC')->setTimezone(self::TIMEZONE)->startOfDay();
    }

    /**
     * @return array{status: 'saved'|'stale'|'exists'|'empty', date: ?string, rows: int}
     */
    public function take(bool $force = false): array
    {
        $date = $this->readingDate();
        if ($date === null) {
            return ['status' => 'empty', 'date' => null, 'rows' => 0];
        }
        $day = $date->toDateString();
        if ($day !== CarbonImmutable::now(self::TIMEZONE)->toDateString()) {
            return ['status' => 'stale', 'date' => $day, 'rows' => 0];
        }
        if (! $force && DB::table(self::TABLE)->where('taken_on', $day)->exists()) {
            return ['status' => 'exists', 'date' => $day, 'rows' => 0];
        }

        $readAt = Carbon::parse((string) ErpItem::query()->whereNull('removed_at')->max('stock_synced_at'));
        $totals = new InventoryBoardTotals(CarbonImmutable::parse($day));
        $service = ErpWarehouse::serviceCodes();
        $now = now();
        $rows = [];
        foreach ($this->locations() as $location) {
            foreach (InventoryQuery::SCOPES as $scope) {
                $loc = $location !== '' ? $location : null;
                $rows[] = [
                    'taken_on' => $day,
                    'location' => $location,
                    'scope' => $scope,
                    'source' => 'live',
                    'totals' => json_encode([
                        'version' => self::RULES_VERSION,
                        'buckets' => $totals->buckets($scope, $loc),
                        'lot_age' => $totals->lotAgeSummary($scope, $loc),
                        'service_codes' => $service,
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'read_at' => $readAt,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        $warehouses = $this->warehouseRows($day, $service, $now);

        DB::transaction(function () use ($day, $rows, $warehouses): void {
            DB::table(self::TABLE)->where('taken_on', $day)->delete();
            DB::table(self::WAREHOUSE_TABLE)->where('taken_on', $day)->delete();
            DB::table(self::TABLE)->insert($rows);
            foreach (array_chunk($warehouses, 500) as $chunk) {
                DB::table(self::WAREHOUSE_TABLE)->insert($chunk);
            }
        });

        return ['status' => 'saved', 'date' => $day, 'rows' => count($rows)];
    }

    /**
     * Oddziały do zapisu: '' (wszystkie), znane z nazwy, te ze stanem i te, które już są w historii — oddział, który
     * wyprzedał towar, dostaje zera zamiast zniknąć z wykresu.
     *
     * @return list<string>
     */
    private function locations(): array
    {
        $keys = [
            '',
            ...array_map('strval', array_keys(WarehouseLocations::NAMES)),
            ...array_column(WarehouseLocations::available(), 'key'),
            ...DB::table(self::TABLE)->where('location', '!=', '')->distinct()->pluck('location')->map(static fn ($l): string => (string) $l)->all(),
        ];

        return array_values(array_unique($keys));
    }

    /**
     * Ilość i wartość na każdy magazyn XL (towar bez usuniętych, ze stanem): wartość partii, a bez niej ilość × cena
     * ostatniej PZ — jak wartość towaru w raporcie.
     *
     * @param  list<string>  $service
     * @return list<array<string, mixed>>
     */
    private function warehouseRows(string $day, array $service, Carbon $now): array
    {
        // wartość wiersza w podzapytaniu, grupowanie dopiero na zewnątrz — MySQL z ONLY_FULL_GROUP_BY nie przyjmuje
        // skorelowanego podzapytania po erp_items.id wewnątrz sum() przy grupowaniu po magazynie
        $rows = DB::table(WarehouseLocations::TABLE.' as s')
            ->join('erp_items', 'erp_items.id', '=', 's.erp_item_id')
            ->whereNull('erp_items.removed_at')
            ->where('s.quantity', '>', 0)
            ->selectRaw('s.warehouse_code, s.quantity, coalesce(s.value, s.quantity * '.InventoryQuery::LAST_PRICE_SQL.') as row_value');

        return DB::query()->fromSub($rows, 'x')
            ->groupBy('x.warehouse_code')
            ->orderBy('x.warehouse_code')
            ->selectRaw('x.warehouse_code, count(*) as items, sum(x.quantity) as quantity, coalesce(sum(x.row_value), 0) as value,'
                .' sum(case when x.row_value is null then 1 else 0 end) as value_unknown')
            ->get()
            ->map(static fn ($r): array => [
                'taken_on' => $day,
                'warehouse_code' => (string) $r->warehouse_code,
                'location' => WarehouseLocations::of((string) $r->warehouse_code),
                'is_service' => in_array((string) $r->warehouse_code, $service, true),
                'source' => 'live',
                'items' => (int) $r->items,
                'quantity' => round((float) $r->quantity, 4),
                'value' => round((float) $r->value, 2),
                'value_unknown' => (int) $r->value_unknown,
                'created_at' => $now,
                'updated_at' => $now,
            ])->values()->all();
    }
}
