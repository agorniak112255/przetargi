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
 * magazynów, a gdy któryś magazyn jej nie ma — ilość × cena ostatniej PZ (jak dla całego towaru). Ostatnia sprzedaż
 * w oddziale — z dokumentów jego magazynów (erp_item_warehouse_sales), bez względu na handlowe / usługowe.
 *
 * Wiek zapasu (raport dla zarządu, „jak długo leżą dostawy”): z partii StockLots — ilość i wartość samych dostaw
 * przyjętych w okresie (lot*Sql), ten sam zakres magazynów i oddział.
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
     * Dzień ostatniej sprzedaży (FS, paragon, WZ). W oddziale — z dokumentów jego magazynów; towar bez żadnego wiersza
     * sprzedaży na magazyn (przed pierwszą nocną kopią) — ostatnia sprzedaż z dowolnego magazynu.
     */
    public static function lastSaleSql(?string $location = null): string
    {
        if ($location === null) {
            return 'erp_items.last_sale_at';
        }
        $sales = WarehouseLocations::SALES_TABLE;

        return '(case when exists (select 1 from '.$sales.' x where x.erp_item_id = erp_items.id)'
            .' then (select max(x.last_sale_at) from '.$sales.' x where x.erp_item_id = erp_items.id'
            ." and x.location = '".WarehouseLocations::assertValid($location)."')"
            .' else erp_items.last_sale_at end)';
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
        $sale = self::lastSaleSql($location);

        return $query->where(function (Builder $q) use ($date, $neverSold, $lot, $sale): void {
            $q->whereRaw($sale.' < ?', [$date]);
            if ($neverSold) {
                $q->orWhere(fn (Builder $n) => $n->whereRaw($sale.' is null')
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
     * Liczba pozycji, wartość i ile bez wartości — jedno zapytanie. `$valueSql` zastępuje wartość towaru (np. wartość
     * samych partii z okresu — lotValueSql).
     *
     * @param  Builder<ErpItem>  $query
     * @return array{items: int, value: float, value_unknown: int}
     */
    public static function totals(Builder $query, string $scope = 'all', ?string $location = null, ?string $valueSql = null): array
    {
        $value = $valueSql ?? self::valueSql($scope, $location);
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
     * Ilość partii towaru z okresu w wybranych magazynach (StockLots).
     *
     * @param  array{after: ?string, until: ?string}|null  $range  zob. lotRows
     */
    public static function lotQuantitySql(?array $range, string $scope = 'all', ?string $location = null): string
    {
        return '(select coalesce(sum(l.quantity), 0) '.self::lotRows($range, $scope, $location).')';
    }

    /**
     * Wartość partii towaru z okresu: wartość księgowa partii, a bez niej ilość × cena ostatniej PZ (jak wartość
     * towaru); null, gdy którejś partii nie da się wycenić.
     *
     * @param  array{after: ?string, until: ?string}|null  $range
     */
    public static function lotValueSql(?array $range, string $scope = 'all', ?string $location = null): string
    {
        $lot = 'coalesce(l.value, l.quantity * '.self::LAST_PRICE_SQL.')';

        return '(select case when count(*) = count('.$lot.') then sum('.$lot.') end '.self::lotRows($range, $scope, $location).')';
    }

    /**
     * Lista Zalegające z filtrem „partia leży od” (decyzja właściciela 01.10.2026: SPŁAR322 — najstarsza partia z 2020 r.,
     * a lista pokazywała cały stan 33 404 szt. za 185 tys. zł): ilość tylko z partii przyjętych najpóźniej w dniu progu.
     * Towar bez żadnej zapisanej partii (przed pierwszym odczytem partii) — cały stan zakresu, jak dotąd.
     */
    public static function lotsUntilQuantitySql(CarbonImmutable $until, string $scope = 'all', ?string $location = null): string
    {
        return '(case when '.self::HAS_LOTS_SQL.' then '.self::lotQuantitySql(['after' => null, 'until' => $until->toDateString()], $scope, $location)
            .' else '.self::quantitySql($scope, $location).' end)';
    }

    /** Wartość do lotsUntilQuantitySql: partie przyjęte najpóźniej w dniu progu; bez zapisanych partii — wartość zakresu. */
    public static function lotsUntilValueSql(CarbonImmutable $until, string $scope = 'all', ?string $location = null): string
    {
        return '(case when '.self::HAS_LOTS_SQL.' then '.self::lotValueSql(['after' => null, 'until' => $until->toDateString()], $scope, $location)
            .' else '.self::valueSql($scope, $location).' end)';
    }

    private const HAS_LOTS_SQL = 'exists (select 1 from '.StockLots::TABLE.' x where x.erp_item_id = erp_items.id)';

    /**
     * Najwcześniejsze przyjęcie wśród partii towaru z okresu.
     *
     * @param  array{after: ?string, until: ?string}|null  $range
     */
    public static function lotOldestSql(?array $range, string $scope = 'all', ?string $location = null): string
    {
        return '(select min(l.received_at) '.self::lotRows($range, $scope, $location).')';
    }

    /**
     * Towar (bez usuniętych z XL), który ma w wybranych magazynach partie przyjęte w okresie.
     *
     * @param  array{after: ?string, until: ?string}|null  $range
     * @return Builder<ErpItem>
     */
    public static function withLots(?array $range, string $scope = 'all', ?string $location = null): Builder
    {
        return ErpItem::query()->whereNull('removed_at')->whereRaw('exists (select 1 '.self::lotRows($range, $scope, $location).')');
    }

    /**
     * FROM i WHERE partii towaru (alias l, tabela StockLots) w wybranych magazynach i oddziale, przyjętych w okresie:
     * `after` < dzień przyjęcia <= `until` (granica jak lotOlderThan: partia z dnia progu jest już starsza), brak granicy
     * = bez ograniczenia (bez obu — wszystkie partie, także bez daty); `$range` null = tylko partie bez daty przyjęcia.
     * Daty sprawdzone (RRRR-MM-DD), oddział = cyfry — dlatego wolno je wkleić do SQL.
     *
     * @param  array{after: ?string, until: ?string}|null  $range
     */
    private static function lotRows(?array $range, string $scope, ?string $location): string
    {
        $sql = 'from '.StockLots::TABLE.' l where l.erp_item_id = erp_items.id';
        if ($location !== null) {
            $sql .= " and l.location = '".WarehouseLocations::assertValid($location)."'";
        }
        $service = 'select w.code from erp_warehouses w where w.is_service = 1';
        $sql .= match (self::scope($scope)) {
            'trade' => ' and l.warehouse_code not in ('.$service.')',
            'service' => ' and l.warehouse_code in ('.$service.')',
            default => '',
        };
        if ($range === null) {
            return $sql.' and l.received_at is null';
        }
        if ($range['after'] !== null) {
            $sql .= " and l.received_at > '".self::date($range['after'])."'";
        }
        if ($range['until'] !== null) {
            $sql .= " and l.received_at <= '".self::date($range['until'])."'";
        }

        return $sql;
    }

    private static function date(string $date): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw new InvalidArgumentException('Nieprawidłowa data: '.$date);
        }

        return $date;
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
