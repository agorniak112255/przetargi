<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reciprocal Rank Fusion — scala listy z różnych silników (pełny tekst, wektory,
 * kod modelu) po samej pozycji, bez porównywania nieporównywalnych wyników
 * punktowych. Karta wysoka w dwóch listach bije kartę pierwszą w jednej, więc
 * wektory przestają być doklejane na końcu i tracone na obcięciu puli.
 */
final class RrfFusion
{
    /** Tłumi wpływ czubka listy; 60 to wartość z oryginalnej pracy o RRF. */
    private const K = 60;

    /**
     * @param  array<string, list<int>>  $rankings  nazwa źródła => id w kolejności trafności
     * @param  array<string, float>  $weights  waga źródła, domyślnie 1.0
     * @return list<int>
     */
    public function fuse(array $rankings, array $weights = [], int $limit = 100): array
    {
        $scores = [];
        foreach ($rankings as $source => $ids) {
            $weight = $weights[$source] ?? 1.0;
            if ($weight <= 0.0) {
                continue;
            }
            $rank = 0;
            foreach ($ids as $id) {
                $id = (int) $id;
                if ($id <= 0) {
                    continue;
                }
                $rank++;
                $scores[$id] = ($scores[$id] ?? 0.0) + $weight / (self::K + $rank);
            }
        }

        if ($scores === []) {
            return [];
        }

        arsort($scores);

        return array_slice(array_map('intval', array_keys($scores)), 0, max(1, $limit));
    }
}
