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
     * $idealCount: z ilu kart liczyć idealny DCG, gdy $expected to wzorcowe ∪ równoważniki — z samych wzorcowych.
     * Dopisanie równoważnika do golden setu nie może obniżyć nDCG przy tym samym wyniku (recenzja 25.09.2026:
     * 3 wzorcowe na miejscach 1–3 i 16 równoważników poza top‑10 dawały 0,469 zamiast 1,0); równoważnik w wyniku
     * zastępuje brakującą wzorcową, a trafień liczy się najwyżej tyle, ile miejsc ma idealny ranking.
     *
     * @param  list<string>  $expected
     * @param  list<string>  $ranked
     */
    public static function ndcgAt(array $expected, array $ranked, int $k, ?int $idealCount = null): float
    {
        $k = max(1, $k);
        $expected = self::normalizeAll($expected);
        if ($expected === []) {
            return 0.0;
        }
        $set = array_flip($expected);

        $slots = min($k, max(1, $idealCount ?? count($expected)));
        $dcg = 0.0;
        $hits = 0;
        foreach (array_slice(self::normalizeAll($ranked), 0, $k) as $i => $sku) {
            // Liczy się tyle pierwszych trafień, ile miejsc ma idealny ranking: równoważnik zajmuje miejsce brakującej
            // wzorcowej, a nadmiarowe trafienia niczego nie dodają — inaczej karta obca na 1. miejscu i dość równoważników
            // niżej dawały 1,0 (recenzja 25.09.2026).
            if (isset($set[$sku]) && $hits < $slots) {
                $dcg += 1.0 / log(($i + 2), 2);
                $hits++;
            }
        }

        $ideal = 0.0;
        foreach (range(0, $slots - 1) as $i) {
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
        return self::hitsAt($forbidden, $ranked, $k);
    }

    /**
     * SKU z listy, które stoją w top‑k wyniku (w kolejności listy, po normalizacji). Dla zakazanych
     * to `violations`, dla równoważników (`acceptable_skus`) — trafienia innego producenta/modelu.
     *
     * @param  list<string>  $skus
     * @param  list<string>  $ranked
     * @return list<string>
     */
    public static function hitsAt(array $skus, array $ranked, int $k): array
    {
        $set = array_flip(self::normalizeAll(array_slice($ranked, 0, max(1, $k))));
        $out = [];
        foreach (self::normalizeAll($skus) as $sku) {
            if (isset($set[$sku])) {
                $out[] = $sku;
            }
        }

        return $out;
    }

    /**
     * Zakazane SKU z top‑k, które są tylko propozycją pod właściwą kartą: ocena poniżej progu zapisu,
     * a wyżej w wyniku stoi karta wzorcowa albo akceptowalna z oceną ≥ progu. Decyzja z 25.09.2026:
     * ARMEN 6660 (inny kolor) z 60% pod 1010 z 99% to ostrzeżenie, nie błąd blokujący. Wiersz bez oceny,
     * zakazany z oceną ≥ progu albo bez właściwej karty nad sobą zostaje blokujący — taki automat przetargu
     * mógłby wybrać. SKU powtórzone w top‑k jest pokazane tylko wtedy, gdy każde wystąpienie spełnia warunek.
     *
     * @param  list<string>  $forbidden
     * @param  list<string>  $relevant  wzorcowe ∪ akceptowalne
     * @param  list<array{sku: string, percent: int|null, source?: string|null}>  $rows  wiersze wyniku w kolejności wyniku
     * @return list<string> podzbiór `violations()` w jego kolejności
     */
    public static function forbiddenShown(array $forbidden, array $relevant, array $rows, int $k, int $minScore): array
    {
        $forbidden = self::normalizeAll($forbidden);
        $forbiddenSet = array_flip($forbidden);
        $relevantSet = array_flip(self::normalizeAll($relevant));
        $covered = false;
        $shown = [];
        $blocking = [];
        foreach (array_slice($rows, 0, max(1, $k)) as $row) {
            $sku = self::normalize((string) ($row['sku'] ?? ''));
            $percent = $row['percent'] ?? null;
            // Zakaz wygrywa, jak w violations(): zakazana karta nie osłania innej zakazanej.
            if (isset($forbiddenSet[$sku])) {
                if ($covered && is_int($percent) && $percent < $minScore) {
                    $shown[$sku] = true;
                } else {
                    $blocking[$sku] = true;
                }

                continue;
            }
            // Wiersz listy zapasowej (catalog) albo skrótu reguły (rule) nie osłania: jego procent to kolejność z puli,
            // nie ocena karty, i automat przetargu nie zapisze go bez zgody na wiersze katalogowe.
            $unrated = in_array($row['source'] ?? null, ['catalog', 'rule'], true);
            if (isset($relevantSet[$sku]) && is_int($percent) && $percent >= $minScore && ! $unrated) {
                $covered = true;
            }
        }

        $out = [];
        foreach ($forbidden as $sku) {
            if (isset($shown[$sku]) && ! isset($blocking[$sku])) {
                $out[] = $sku;
            }
        }

        return $out;
    }
}
