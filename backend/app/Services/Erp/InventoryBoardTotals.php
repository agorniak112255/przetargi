<?php

declare(strict_types=1);

namespace App\Services\Erp;

use App\Models\ErpItem;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Koszyki raportu zapasów dla zarządu (kafelki, paski, „jak długo leżą dostawy”) liczone na dzień `$today` — wspólne
 * dla raportu (InventoryBoardController, dziś) i codziennego zapisu stanu (InventorySnapshots, dzień odczytu z XL), żeby
 * historia na wykresie liczyła się tak samo jak kafelki. Reguły zapytań w InventoryQuery.
 */
final class InventoryBoardTotals
{
    /** [rodzaj, miesiące, tytuł okna] — kafelki i okna liczą z tych samych definicji. */
    public const BUCKETS = [
        'stock' => ['stock', 0, 'Cały towar w magazynach'],
        'no_sale_6' => ['no_sale', 6, 'Towar, który nie sprzedaje się od pół roku'],
        'no_sale_12' => ['no_sale', 12, 'Towar, który nie sprzedaje się ponad rok'],
        'no_sale_24' => ['no_sale', 24, 'Towar, który nie sprzedaje się ponad 2 lata'],
        'never_sold' => ['never', 6, 'Towar, który nie sprzedał się ani razu'],
        'stale_36' => ['stale', 36, 'Towar bez sprzedaży ponad rok, który leży w magazynie ponad 3 lata'],
        'stale_60' => ['stale', 60, 'Towar bez sprzedaży ponad rok, który leży w magazynie ponad 5 lat'],
    ];

    /**
     * „Jak długo leży” (decyzja właściciela 01.10.2026): każda dostawa (partia) w przedziale swojego wieku — ilość
     * i wartość samych dostaw z okresu, więc przedziały sumują się do zapasu. [od miesięcy, do miesięcy, tytuł okna];
     * od null = partie bez daty przyjęcia.
     */
    public const LOT_AGES = [
        'lot_age_0_6' => [0, 6, 'Dostawy, które leżą w magazynie do pół roku'],
        'lot_age_6_12' => [6, 12, 'Dostawy, które leżą w magazynie od pół roku do roku'],
        'lot_age_12_24' => [12, 24, 'Dostawy, które leżą w magazynie od roku do 2 lat'],
        'lot_age_24_36' => [24, 36, 'Dostawy, które leżą w magazynie od 2 do 3 lat'],
        'lot_age_36_48' => [36, 48, 'Dostawy, które leżą w magazynie od 3 do 4 lat'],
        'lot_age_48_60' => [48, 60, 'Dostawy, które leżą w magazynie od 4 do 5 lat'],
        'lot_age_60' => [60, null, 'Dostawy, które leżą w magazynie ponad 5 lat'],
        'lot_age_unknown' => [null, null, 'Dostawy bez daty przyjęcia w programie magazynowym'],
    ];

    public function __construct(private readonly CarbonImmutable $today) {}

    public static function today(): self
    {
        return new self(CarbonImmutable::today());
    }

    public function ago(int $months): CarbonImmutable
    {
        return $this->today->startOfDay()->subMonthsNoOverflow($months);
    }

    /**
     * Towar koszyka w wybranych magazynach. Nigdy niesprzedany liczy się dopiero, gdy leży dłużej niż pół roku — świeża
     * dostawa nowego towaru to nie zaleganie. Przedział „jak długo leży” — towar z dostawami z tego okresu.
     *
     * @return Builder<ErpItem>
     */
    public function bucketQuery(string $key, string $scope, ?string $location = null): Builder
    {
        if (isset(self::LOT_AGES[$key])) {
            return InventoryQuery::withLots($this->lotRange($key), $scope, $location);
        }
        [$kind, $months] = self::BUCKETS[$key];
        $query = InventoryQuery::inStock($scope, $location);
        $lot = InventoryQuery::oldestLotSql($scope, $location);
        match ($kind) {
            'no_sale' => InventoryQuery::unsoldSince($query, $this->ago($months), true, $scope, $location),
            'stale' => InventoryQuery::lotOlderThan(InventoryQuery::unsoldSince($query, $this->ago(12), true, $scope, $location), $this->ago($months), $scope, $location),
            'never' => $query->whereRaw(InventoryQuery::lastSaleSql($location).' is null')
                ->where(fn (Builder $l) => $l->whereRaw($lot.' is null')->orWhereRaw($lot.' <= ?', [$this->ago($months)->toDateString()])),
            default => $query,
        };

        return $query;
    }

    /**
     * Okres przedziału „jak długo leży”: przyjęte po `after` i najpóźniej `until`; null = partie bez daty.
     *
     * @return array{after: ?string, until: ?string}|null
     */
    public function lotRange(string $key): ?array
    {
        [$from, $to] = self::LOT_AGES[$key];
        if ($from === null) {
            return null;
        }

        return [
            'after' => $to !== null ? $this->ago($to)->toDateString() : null,
            'until' => $from > 0 ? $this->ago($from)->toDateString() : null,
        ];
    }

    /**
     * Liczby kafelków i pasków bez list: każdy koszyk z BUCKETS (pozycje, wartość, ile bez wartości).
     *
     * @return array<string, array{items: int, value: float, value_unknown: int}>
     */
    public function buckets(string $scope, ?string $location = null): array
    {
        $out = [];
        foreach (array_keys(self::BUCKETS) as $key) {
            $out[$key] = InventoryQuery::totals($this->bucketQuery($key, $scope, $location), $scope, $location);
        }

        return $out;
    }

    /**
     * Przedziały „jak długo leży”: wartość samych dostaw z okresu i liczba towarów z takimi dostawami (towar z dostawami
     * z kilku okresów liczy się w każdym z nich). null = partii jeszcze nie odczytano z XL (przed pierwszym odczytem
     * stanów po wdrożeniu).
     *
     * @return array{buckets: list<array{key: string, from_months: int|null, to_months: int|null, items: int, value: float}>, items: int, value: float, value_unknown_items: int}|null
     */
    public function lotAgeSummary(string $scope, ?string $location): ?array
    {
        if (! DB::table(StockLots::TABLE)->exists()) {
            return null;
        }
        $buckets = [];
        foreach (self::LOT_AGES as $key => [$from, $to]) {
            $range = $this->lotRange($key);
            $totals = InventoryQuery::totals(InventoryQuery::withLots($range, $scope, $location), $scope, $location, InventoryQuery::lotValueSql($range, $scope, $location));
            $buckets[] = ['key' => $key, 'from_months' => $from, 'to_months' => $to, 'items' => $totals['items'], 'value' => $totals['value'], 'value_unknown' => $totals['value_unknown']];
        }
        $any = ['after' => null, 'until' => null];
        $all = InventoryQuery::totals(InventoryQuery::withLots($any, $scope, $location), $scope, $location, InventoryQuery::lotValueSql($any, $scope, $location));

        return [
            'buckets' => array_map(static fn (array $b): array => array_diff_key($b, ['value_unknown' => true]), $buckets),
            'items' => $all['items'],
            // suma przedziałów: towar bez ceny jednej dostawy traci tylko przedział tej dostawy
            'value' => round(array_sum(array_column($buckets, 'value')), 2),
            'value_unknown_items' => $all['value_unknown'],
        ];
    }
}
