<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Miary jakości wyszukiwania na relewancji binarnej (SKU trafione / nietrafione).
 * Czyste funkcje — liczone tak samo w komendzie `search:eval` i w testach.
 */
final class SearchEvalMetrics
{
    /** SKU bywa zapisany „8145-S1PL ESD” i „8145-s1pl  esd” — to ten sam produkt. */
    public static function normalize(string $sku): string
    {
        return trim(preg_replace('/\s+/u', ' ', mb_strtoupper(trim($sku))) ?? '');
    }

    /**
     * @param  list<string>  $skus
     * @return list<string>
     */
    public static function normalizeAll(array $skus): array
    {
        $out = [];
        foreach ($skus as $sku) {
            $n = self::normalize((string) $sku);
            if ($n !== '') {
                $out[] = $n;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Ile oczekiwanych SKU w ogóle znalazło się w zbiorze (kolejność bez znaczenia).
     * Na puli z retrievalu to recall etapu 1 — sufit dla całej reszty pipeline'u.
     *
     * @param  list<string>  $expected
     * @param  list<string>  $found
     */
    public static function recall(array $expected, array $found): float
    {
        $expected = self::normalizeAll($expected);
        if ($expected === []) {
            return 0.0;
        }
        $set = array_flip(self::normalizeAll($found));
        $hits = 0;
        foreach ($expected as $sku) {
            if (isset($set[$sku])) {
                $hits++;
            }
        }

        return $hits / count($expected);
    }

    /**
     * @param  list<string>  $expected
     * @param  list<string>  $ranked
     */
    public static function recallAt(array $expected, array $ranked, int $k): float
    {
        return self::recall($expected, array_slice($ranked, 0, max(1, $k)));
    }

    /**
     * Klasyczne P@k — dzielone przez k, więc przy jednym oczekiwanym SKU nie
     * przekroczy 1/k. Do porównań między wersjami, nie do czytania bezwzględnie.
     *
     * @param  list<string>  $expected
     * @param  list<string>  $ranked
     */
    public static function precisionAt(array $expected, array $ranked, int $k): float
    {
        $k = max(1, $k);
        $set = array_flip(self::normalizeAll($expected));
        $hits = 0;
        foreach (array_slice(self::normalizeAll($ranked), 0, $k) as $sku) {
            if (isset($set[$sku])) {
                $hits++;
            }
        }

        return $hits / $k;
    }

    /**
     * nDCG@k przy relewancji 0/1 — kara za to, że trafienie leży nisko.
     *
     * @param  list<string>  $expected
     * @param  list<string>  $ranked
     */
    public static function ndcgAt(array $expected, array $ranked, int $k): float
    {
        $k = max(1, $k);
        $expected = self::normalizeAll($expected);
        if ($expected === []) {
            return 0.0;
        }
        $set = array_flip($expected);

        $dcg = 0.0;
        foreach (array_slice(self::normalizeAll($ranked), 0, $k) as $i => $sku) {
            if (isset($set[$sku])) {
                $dcg += 1.0 / log(($i + 2), 2);
            }
        }

        $ideal = 0.0;
        foreach (range(0, min($k, count($expected)) - 1) as $i) {
            $ideal += 1.0 / log(($i + 2), 2);
        }

        return $ideal > 0.0 ? $dcg / $ideal : 0.0;
    }

    /**
     * Odwrotność pozycji pierwszego trafienia — „jak wysoko user widzi coś sensownego”.
     *
     * @param  list<string>  $expected
     * @param  list<string>  $ranked
     */
    public static function reciprocalRank(array $expected, array $ranked): float
    {
        $set = array_flip(self::normalizeAll($expected));
        foreach (self::normalizeAll($ranked) as $i => $sku) {
            if (isset($set[$sku])) {
                return 1.0 / ($i + 1);
            }
        }

        return 0.0;
    }

    /**
     * SKU, które w tym wymaganiu są błędem (np. trzewik ESD przy kaloszach).
     * W przetargu fałszywy pozytyw kosztuje więcej niż brak trafienia.
     *
     * @param  list<string>  $forbidden
     * @param  list<string>  $ranked
     * @return list<string>
     */
    public static function violations(array $forbidden, array $ranked, int $k): array
    {
        $set = array_flip(self::normalizeAll(array_slice($ranked, 0, max(1, $k))));
        $out = [];
        foreach (self::normalizeAll($forbidden) as $sku) {
            if (isset($set[$sku])) {
                $out[] = $sku;
            }
        }

        return $out;
    }
}
