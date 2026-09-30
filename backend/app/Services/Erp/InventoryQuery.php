<?php

declare(strict_types=1);

namespace App\Services\Erp;

use App\Models\ErpItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Wspólne reguły Zapasów — lista „Zalegające” i raport dla zarządu liczą tak samo.
 *
 * Wartość = ilość × cena zakupu (decyzja użytkownika 30.09.2026): najpierw wartość partii leżących na stanie
 * (TwZ_KsiegowaNetto), a bez niej stan × cena z ostatniej PZ. Sama ostatnia PZ bywa błędna — SNAU51000-04-S: PZ 1 szt.
 * za 11 600,60 zł, poprawione RW 1 szt. + PW 40 szt. po 290,02 zł; partie mają 290,02.
 *
 * Zakres magazynów (raport dla zarządu): 'all' — wszystkie, 'service' — usługowe ze słownika erp_warehouses,
 * 'trade' — handlowe (całość − usługowe). Ostatnia sprzedaż jest wspólna dla towaru.
 *
 * Oddział (WarehouseLocations, np. '01' Rzeszów) zawęża zakres do magazynów oddziału: ilość, wartość i wiek partii
 * z wierszy erp_item_warehouse_stocks; słownik usługowych czytany na bieżąco. Wartość oddziału = suma wartości partii jego
 * magazynów, a gdy któryś magazyn jej nie ma — ilość × cena ostatniej PZ (jak dla całego towaru).
 */
final class InventoryQuery
{
    public const SCOPES = ['trade', 'service', 'all'];

    /**
     * Cena jednostki podstawowej w PLN z ostatniej PZ towaru (ta sama kolejność co ErpItem::purchases). Wartość w SQL,
     * żeby sortowanie i suma szły po całej liście, nie po stronie.
     */
    public const LAST_PRICE_SQL = '(select p.unit_price_pln from erp_item_purchases p where p.erp_item_id = erp_items.id'
        .' and p.unit_price_pln is not null order by p.purchased_at desc, p.document_id desc limit 1)';

    public const VALUE_SQL = '(coalesce(erp_items.stock_value, (erp_items.stock_total * '.self::LAST_PRICE_SQL.')))';

    public static function quantitySql(string $scope = 'all', ?string $location = null): string
    {
        if ($location !== null) {
            return '(select coalesce(sum(s.quantity), 0) '.self::locationRows($scope, $location).')';
        }

        return match (self::scope($scope)) {
            'trade' => '(erp_items.stock_total - erp_items.stock_service)',
            'service' => 'erp_items.stock_service',
            default => 'erp_items.stock_total',
        };
    }

    public static function valueSql(string $scope = 'all', ?string $location = null): string
    {
        if ($location !== null) {
            return '(coalesce((select case when count(*) = count(s.value) then sum(s.value) end '.self::locationRows($scope, $location).'), ('
                .self::quantitySql($scope, $location).' * '.self::LAST_PRICE_SQL.')))';
        }

        return match (self::scope($scope)) {
            'trade' => '(coalesce(erp_items.stock_value - erp_items.stock_service_value, ('.self::quantitySql('trade').' * '.self::LAST_PRICE_SQL.')))',
            'service' => '(coalesce(erp_items.stock_service_value, (erp_items.stock_service * '.self::LAST_PRICE_SQL.')))',
            default => self::VALUE_SQL,
        };
    }

    /**
     * Dzień przyjęcia najstarszej partii w wybranych magazynach. Gdy data zakresu jest nieznana (stare rozbicie bez dat
     * albo przed pierwszym przeliczeniem), data całego towaru — najwyżej starsza, nigdy nie zrobi świeżej dostawy starą.
     * Tak samo w oddziale: bez daty w jego magazynach — data zakresu.
     */
    public static function oldestLotSql(string $scope = 'all', ?string $location = null): string
    {
        if ($location !== null) {
            return '(coalesce((select min(s.oldest_lot_at) '.self::locationRows($scope, $location).' and s.quantity > 0), '
                .self::oldestLotSql($scope).'))';
        }

        return match (self::scope($scope)) {
            'trade' => '(coalesce(erp_items.oldest_lot_trade_at, erp_items.oldest_lot_at))',
            'service' => '(coalesce(erp_items.oldest_lot_service_at, erp_items.oldest_lot_at))',
            default => 'erp_items.oldest_lot_at',
        };
    }

    /** @return Builder<ErpItem> towar z XL (bez usuniętych) ze stanem w wybranych magazynach */
    public static function inStock(string $scope = 'all', ?string $location = null): Builder
    {
        $query = ErpItem::query()->whereNull('removed_at');
        if ($location !== null) {
            // najpierw towar z jakimkolwiek stanem w oddziale (indeks), dopiero potem suma zakresu
            $query->whereIn('erp_items.id', fn ($q) => $q->select('erp_item_id')->from(WarehouseLocations::TABLE)
                ->where('location', WarehouseLocations::assertValid($location))->where('quantity', '>', 0));
        }

        return $query->whereRaw(self::quantitySql($scope, $location).' > 0');
    }

    /**
     * Ostatnia sprzedaż (FS, paragon, WZ) starsza niż próg. Nigdy niesprzedany liczy się tylko wtedy, gdy jego najstarsza
     * partia leży dłużej niż próg (albo jej data jest nieznana) — inaczej świeża dostawa wyglądałaby jak zaleganie.
     *
     * @param  Builder<ErpItem>  $query
     * @return Builder<ErpItem>
     */
    public static function unsoldSince(Builder $query, CarbonImmutable $cutoff, bool $neverSold = true, string $scope = 'all', ?string $location = null): Builder
    {
        $date = $cutoff->toDateString();
        $lot = self::oldestLotSql($scope, $location);

        return $query->where(function (Builder $q) use ($date, $neverSold, $lot): void {
            $q->where('last_sale_at', '<', $date);
            if ($neverSold) {
                $q->orWhere(fn (Builder $n) => $n->whereNull('last_sale_at')
                    ->where(fn (Builder $l) => $l->whereRaw($lot.' is null')->orWhereRaw($lot.' <= ?', [$date])));
            }
        });
    }

    /**
     * Najstarsza partia w wybranych magazynach przyjęta najpóźniej w dniu progu. Uwaga: PW z pary RW → PW zakłada nową
     * partię i „odmładza” tę datę.
     *
     * @param  Builder<ErpItem>  $query
     * @return Builder<ErpItem>
     */
    public static function lotOlderThan(Builder $query, CarbonImmutable $cutoff, string $scope = 'all', ?string $location = null): Builder
    {
        $lot = self::oldestLotSql($scope, $location);

        return $query->whereRaw($lot.' is not null')->whereRaw($lot.' <= ?', [$cutoff->toDateString()]);
    }

    /**
     * Liczba pozycji, wartość i ile bez wartości — jedno zapytanie.
     *
     * @param  Builder<ErpItem>  $query
     * @return array{items: int, value: float, value_unknown: int}
     */
    public static function totals(Builder $query, string $scope = 'all', ?string $location = null): array
    {
        $value = self::valueSql($scope, $location);
        $row = (clone $query)->toBase()
            ->selectRaw('count(*) as items, coalesce(sum('.$value.'), 0) as value,'
                .' sum(case when '.$value.' is null then 1 else 0 end) as value_unknown')
            ->first();

        return [
            'items' => (int) ($row->items ?? 0),
            'value' => round((float) ($row->value ?? 0), 2),
            'value_unknown' => (int) ($row->value_unknown ?? 0),
        ];
    }

    /**
     * FROM i WHERE wierszy stanu towaru w magazynach oddziału w wybranym zakresie (alias s). Oddział to same cyfry
     * (assertValid) — dlatego wolno go wkleić do SQL.
     */
    private static function locationRows(string $scope, string $location): string
    {
        $sql = 'from '.WarehouseLocations::TABLE.' s where s.erp_item_id = erp_items.id'
            ." and s.location = '".WarehouseLocations::assertValid($location)."'";
        $service = 'select w.code from erp_warehouses w where w.is_service = 1';

        return match (self::scope($scope)) {
            'trade' => $sql.' and s.warehouse_code not in ('.$service.')',
            'service' => $sql.' and s.warehouse_code in ('.$service.')',
            default => $sql,
        };
    }

    private static function scope(string $scope): string
    {
        if (! in_array($scope, self::SCOPES, true)) {
            throw new InvalidArgumentException('Nieznany zakres magazynów: '.$scope);
        }

        return $scope;
    }
}
