<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use Illuminate\Support\Facades\DB;

/**
 * Wynik kampanii: ile zapasu pozycji zeszło po wysyłce. Sztuk różnych towarów się nie sumuje — spadek to średnia
 * procentów pozycji ważona wartością zapasu przy wysyłce (stan × koszt jednostkowy), a bez kosztu u którejkolwiek
 * pozycji — zwykła średnia. Sumy stanów tylko przy jednej jednostce wszystkich pozycji. Pozycje bez towaru XL
 * (karta bez powiązania) nie mają stanu — pomijane.
 */
final class CampaignResult
{
    /**
     * Jednym zapytaniem dla wielu kampanii.
     *
     * @param  list<int>  $campaignIds
     * @return array<int, array{stock_at_send: float|null, stock_after_7d: float|null, stock_after_30d: float|null, drop_percent: float|null}|null>
     */
    public static function forCampaigns(array $campaignIds): array
    {
        $out = array_fill_keys($campaignIds, null);
        if ($campaignIds === []) {
            return $out;
        }
        $rows = DB::table('campaign_items as ci')
            ->join('erp_items as e', 'e.id', '=', 'ci.erp_item_id')
            ->whereIn('ci.campaign_id', $campaignIds)
            ->whereNotNull('ci.snap_stock')
            ->orderBy('ci.id')
            ->get(['ci.campaign_id', 'ci.snap_stock', 'ci.snap_unit', 'ci.stock_after_7d', 'ci.stock_after_30d', 'e.stock_value', 'e.stock_total']);

        $items = [];
        foreach ($rows as $row) {
            $items[(int) $row->campaign_id][] = [
                'snap' => (float) $row->snap_stock,
                'unit' => $row->snap_unit !== null ? (string) $row->snap_unit : null,
                'stock_after_7d' => $row->stock_after_7d !== null ? (float) $row->stock_after_7d : null,
                'stock_after_30d' => $row->stock_after_30d !== null ? (float) $row->stock_after_30d : null,
                // koszt bieżący (jak w pozycji kampanii): wartość partii / stan całkowity
                'unit_cost' => $row->stock_value !== null && (float) $row->stock_total > 0 ? (float) $row->stock_value / (float) $row->stock_total : null,
            ];
        }
        foreach ($items as $campaignId => $list) {
            $out[$campaignId] = self::compute($list);
        }

        return $out;
    }

    /**
     * @param  list<array{snap: float, unit: string|null, stock_after_7d: float|null, stock_after_30d: float|null, unit_cost: float|null}>  $items
     * @return array{stock_at_send: float|null, stock_after_7d: float|null, stock_after_30d: float|null, drop_percent: float|null}|null
     */
    public static function compute(array $items): ?array
    {
        if ($items === []) {
            return null;
        }
        $oneUnit = count(array_unique(array_map(static fn (array $i): string => $i['unit'] ?? "\0", $items))) === 1;
        // „po N dniach” tylko, gdy znane dla wszystkich pozycji ze stanem przy wysyłce
        $sum = static function (string $column) use ($items): ?float {
            $values = array_column($items, $column);

            return in_array(null, $values, true) ? null : (float) array_sum($values);
        };

        return [
            'stock_at_send' => $oneUnit ? (float) array_sum(array_column($items, 'snap')) : null,
            'stock_after_7d' => $oneUnit ? $sum('stock_after_7d') : null,
            'stock_after_30d' => $oneUnit ? $sum('stock_after_30d') : null,
            'drop_percent' => self::dropPercent($items),
        ];
    }

    /** @param  list<array{snap: float, unit: string|null, stock_after_7d: float|null, stock_after_30d: float|null, unit_cost: float|null}>  $items */
    private static function dropPercent(array $items): ?float
    {
        $withStock = array_values(array_filter($items, static fn (array $i): bool => $i['snap'] > 0));
        // stan po 30 dniach, a gdy nie ma go u żadnej pozycji — po 7 dniach
        $column = null;
        foreach (['stock_after_30d', 'stock_after_7d'] as $candidate) {
            if (array_filter($withStock, static fn (array $i): bool => $i[$candidate] !== null) !== []) {
                $column = $candidate;
                break;
            }
        }
        if ($column === null) {
            return null;
        }

        $percents = $weights = [];
        $weighted = true;
        foreach ($withStock as $item) {
            if ($item[$column] === null) {
                continue;
            }
            $percents[] = min(100.0, max(0.0, $item['snap'] - $item[$column]) / $item['snap'] * 100);
            if ($item['unit_cost'] === null || $item['unit_cost'] <= 0) {
                $weighted = false;
            }
            $weights[] = $item['snap'] * (float) $item['unit_cost'];
        }
        $totalWeight = array_sum($weights);
        if ($weighted && $totalWeight > 0) {
            $drop = 0.0;
            foreach ($percents as $k => $p) {
                $drop += $p * $weights[$k];
            }
            $drop /= $totalWeight;
        } else {
            $drop = array_sum($percents) / count($percents);
        }

        return round($drop, 1);
    }
}
