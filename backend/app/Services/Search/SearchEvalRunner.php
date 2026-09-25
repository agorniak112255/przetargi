<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Product;
use App\Services\Ai\AiSettingsService;
use App\Services\ProductAiSearchService;
use App\Support\SearchEvalMetrics;
use InvalidArgumentException;
use Throwable;

/**
 * Ewaluacja wyszukiwania AI na golden secie: jedno prawdziwe wywołanie pipeline'u
 * na przypadek, a metryki liczone osobno dla retrievalu i dla rankingu.
 *
 * Rozdzielenie jest tu całym sensem: gdy `retrieval_recall` jest niskie, żadna
 * zmiana promptu nie pomoże (dobrej karty model nigdy nie zobaczył); gdy jest
 * wysokie, a `ndcg` niskie — problem siedzi w rankingu.
 */
final class SearchEvalRunner
{
    private ?int $matchMinScore = null;

    public function __construct(
        private readonly AiProductSearch $search,
        private readonly AiSettingsService $settings,
    ) {}

    /**
     * `tender_expect_empty`: dla pomiaru decyzji przetargu poprawny jest brak propozycji (poz. 9: jedyne gogle z zaciemnieniem 5.0
     * mają FT zamiast wymaganych 120 m/s); `expected_skus` zostaje najlepszą kartą katalogu dla pomiaru wyszukiwania.
     *
     * `acceptable_skus` (decyzja z 25.09.2026): karty innego producenta/modelu spełniające wszystkie warunki wymagania.
     * Liczą się jako trafienie w precision@k, MRR i nDCG, ale nie w recall — recall dalej mierzy wskazane wyroby.
     * `acceptable_note` mówi, skąd wiadomo, że spełniają warunki. Brak pól = [] i '' (stare pliki bez zmian).
     *
     * @return list<array{id: string, query: string, expected_skus: list<string>, acceptable_skus: list<string>, acceptable_note: string, forbidden_skus: list<string>, note: string, expect_empty: bool}>
     */
    public function loadCases(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException("Nie ma pliku golden setu: {$path}");
        }
        $raw = json_decode((string) file_get_contents($path), true);
        if (! is_array($raw)) {
            throw new InvalidArgumentException("Golden set nie jest poprawnym JSON-em: {$path}");
        }
        $cases = is_array($raw['cases'] ?? null) ? $raw['cases'] : $raw;

        $out = [];
        foreach ($cases as $i => $case) {
            if (! is_array($case)) {
                continue;
            }
            $query = trim((string) ($case['query'] ?? ''));
            $expected = $this->stringList($case['expected_skus'] ?? []);
            if ($query === '') {
                throw new InvalidArgumentException("Przypadek #{$i}: puste `query`.");
            }
            if ($expected === []) {
                throw new InvalidArgumentException("Przypadek #{$i} ({$query}): puste `expected_skus`.");
            }
            $forbidden = $this->stringList($case['forbidden_skus'] ?? []);
            $acceptable = $this->stringList($case['acceptable_skus'] ?? []);
            // Równoważnik jednocześnie wzorcowy albo zakazany to błąd wpisu: tag i forbidden_shown byłyby niejednoznaczne.
            $clash = array_values(array_intersect(
                SearchEvalMetrics::normalizeAll($acceptable),
                SearchEvalMetrics::normalizeAll([...$expected, ...$forbidden]),
            ));
            if ($clash !== []) {
                throw new InvalidArgumentException("Przypadek #{$i} ({$query}): SKU z `acceptable_skus` jest też wśród wzorcowych albo zakazanych: ".implode(', ', $clash).'.');
            }
            $out[] = [
                'id' => trim((string) ($case['id'] ?? ('case-'.($i + 1)))),
                'query' => $query,
                'expected_skus' => $expected,
                'acceptable_skus' => $acceptable,
                'acceptable_note' => trim((string) ($case['acceptable_note'] ?? '')),
                'forbidden_skus' => $forbidden,
                'note' => trim((string) ($case['note'] ?? '')),
                'expect_empty' => ($case['tender_expect_empty'] ?? false) === true,
            ];
        }

        if ($out === []) {
            throw new InvalidArgumentException("Golden set jest pusty: {$path}");
        }

        return $out;
    }

    /**
     * @param  array{id: string, query: string, expected_skus: list<string>, acceptable_skus?: list<string>, forbidden_skus: list<string>, note: string}  $case
     * @return array<string, mixed>
     */
    public function evaluate(array $case, int $k, int $limit): array
    {
        $started = hrtime(true);
        try {
            $result = $this->search->find($case['query'], $limit);
        } catch (Throwable $e) {
            return $this->errorRow($case, $e->getMessage(), (int) round((hrtime(true) - $started) / 1e6));
        }

        $row = $this->scoreResult($case, $result, $this->search->lastTrace(), $k);
        $row['duration_ms'] = (int) round((hrtime(true) - $started) / 1e6);

        return $row;
    }

    /**
     * Wiersz przypadku, który się wywrócił (wyjątek wyszukiwania albo proces równoległy bez wyniku) — liczy się jako
     * błąd w podsumowaniu, a nie jako zero trafień.
     *
     * @param  array{id: string, query: string, expected_skus: list<string>}  $case
     * @return array<string, mixed>
     */
    public function errorRow(array $case, string $message, int $durationMs = 0): array
    {
        return [
            'id' => $case['id'],
            'query' => $case['query'],
            'error' => $message,
            'retrieval_recall' => 0.0,
            'recall_at_k' => 0.0,
            'precision_at_k' => 0.0,
            'ndcg_at_k' => 0.0,
            'mrr' => 0.0,
            'violations' => [],
            'violations_blocking' => [],
            'forbidden_shown' => [],
            'acceptable_hits' => [],
            'missing_skus' => SearchEvalMetrics::normalizeAll($case['expected_skus']),
            'unknown_skus' => [],
            'unknown_forbidden_skus' => [],
            'unknown_acceptable_skus' => [],
            'returned' => 0,
            'unrated_rows' => 0,
            'returned_top' => [],
            'candidates' => 0,
            'duration_ms' => $durationMs,
            'model_state' => null,
            'rank_passes' => 0,
            'rewrite' => false,
        ];
    }

    /**
     * Metryki jednego przypadku z gotowego wyniku wyszukiwania (`products` jak z find(), ślad z lastTrace()),
     * bez wywołania modelu — ten sam kod liczy przebieg na żywo i wynik zapisany w raporcie z produkcji.
     * `duration_ms` ustawia evaluate().
     *
     * @param  array{id: string, query: string, expected_skus: list<string>, acceptable_skus?: list<string>, forbidden_skus: list<string>, note?: string}  $case
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $trace
     * @return array<string, mixed>
     */
    public function scoreResult(array $case, array $result, array $trace, int $k): array
    {
        $k = max(1, $k);
        $products = is_array($result['products'] ?? null) ? array_values($result['products']) : [];
        $rankedSkus = [];
        foreach ($products as $row) {
            $rankedSkus[] = (string) ($row['sku'] ?? '');
        }
        $candidateSkus = $this->skusFor($trace['candidate_ids'] ?? []);
        $expected = $case['expected_skus'];
        $acceptable = $case['acceptable_skus'] ?? [];
        $forbidden = $case['forbidden_skus'];
        // Trafne w rankingu: wzorcowe i równoważniki innego producenta/modelu (decyzja z 25.09.2026).
        // Bez acceptable_skus to dokładnie lista wzorcowych, więc metryki są te same co przed zmianą.
        $relevant = [...$expected, ...$acceptable];

        $existing = SearchEvalMetrics::normalizeAll($this->existingSkus([...$expected, ...$acceptable, ...$forbidden]));
        $foundSet = array_flip(SearchEvalMetrics::normalizeAll($rankedSkus));
        $missing = [];
        foreach (SearchEvalMetrics::normalizeAll($expected) as $sku) {
            if (! isset($foundSet[$sku])) {
                $missing[] = $sku;
            }
        }

        // Surowa ocena modelu i jego brakujący kluczowy warunek (ślad llm_matches, ostatni przebieg wygrywa) — do
        // diagnozy remisów procentu, które rozstrzyga cena (25.09.2026).
        $llm = [];
        foreach (is_array($trace['llm_matches'] ?? null) ? $trace['llm_matches'] : [] as $match) {
            if (is_array($match) && (int) ($match['id'] ?? 0) > 0) {
                $llm[(int) $match['id']] = $match;
            }
        }
        $top = [];
        foreach (array_slice($products, 0, $k) as $row) {
            $match = $llm[(int) ($row['id'] ?? 0)] ?? null;
            $top[] = [
                'sku' => (string) ($row['sku'] ?? ''),
                'percent' => is_numeric($row['ai_match_percent'] ?? null) ? (int) $row['ai_match_percent'] : null,
                'source' => is_string($row['ai_match_source'] ?? null) ? $row['ai_match_source'] : null,
                'model_score' => is_array($match) && is_numeric($match['score'] ?? null) ? (int) $match['score'] : null,
                'missing_key' => is_array($match) && is_array($match['missing_key'] ?? null) ? array_values($match['missing_key']) : [],
                'purchase_price_pln' => is_numeric($row['purchase_price_pln'] ?? null) ? (float) $row['purchase_price_pln'] : null,
                'purchase_price' => is_numeric($row['purchase_price'] ?? null) ? (float) $row['purchase_price'] : null,
                'currency' => is_string($row['currency'] ?? null) ? $row['currency'] : null,
            ];
        }
        $violations = SearchEvalMetrics::violations($forbidden, $rankedSkus, $k);
        $shown = SearchEvalMetrics::forbiddenShown($forbidden, $relevant, $top, $k, $this->matchMinScore());

        return [
            'id' => $case['id'],
            'query' => $case['query'],
            'error' => null,
            // Etap 1: czy oczekiwany produkt w ogóle wszedł do puli kandydatów.
            'retrieval_recall' => SearchEvalMetrics::recall($expected, $candidateSkus),
            // Etap 2: co z tego zostało w wyniku oddanym użytkownikowi.
            'recall_at_k' => SearchEvalMetrics::recallAt($expected, $rankedSkus, $k),
            'precision_at_k' => SearchEvalMetrics::precisionAt($relevant, $rankedSkus, $k),
            // Idealny DCG z samych wzorcowych — dopisany równoważnik nie obniża nDCG przy tym samym wyniku.
            'ndcg_at_k' => SearchEvalMetrics::ndcgAt($relevant, $rankedSkus, $k, count(array_unique(SearchEvalMetrics::normalizeAll($expected)))),
            'mrr' => SearchEvalMetrics::reciprocalRank($relevant, $rankedSkus),
            // Ścisłe: każde zakazane SKU w top-k. Bramką porównań jest violations_blocking (decyzja z 25.09.2026):
            // zakazana propozycja poniżej progu zapisu pod właściwą kartą (inny kolor ARMEN) to ostrzeżenie.
            'violations' => $violations,
            'violations_blocking' => array_values(array_diff($violations, $shown)),
            'forbidden_shown' => $shown,
            'acceptable_hits' => SearchEvalMetrics::hitsAt($acceptable, $rankedSkus, $k),
            'missing_skus' => $missing,
            // Zły wpis w golden secie, nie zła wyszukiwarka — trzeba go poprawić. Zakazane spoza katalogu
            // (np. karta dystrybutora po łączeniu kart) niczego już nie pilnują.
            'unknown_skus' => array_values(array_diff(SearchEvalMetrics::normalizeAll($expected), $existing)),
            'unknown_forbidden_skus' => array_values(array_diff(SearchEvalMetrics::normalizeAll($forbidden), $existing)),
            'unknown_acceptable_skus' => array_values(array_diff(SearchEvalMetrics::normalizeAll($acceptable), $existing)),
            'returned' => count($rankedSkus),
            // Wiersze z listy zapasowej (catalog) i skrótów reguł (rule) — procent to kolejność, nie ocena modelu.
            'unrated_rows' => count(array_filter($products, static fn (array $row): bool => in_array(
                $row['ai_match_source'] ?? null,
                [ProductAiSearchService::MATCH_SOURCE_CATALOG, ProductAiSearchService::MATCH_SOURCE_RULE],
                true,
            ))),
            // Lista wyniku do przeliczenia zmian golden bez nowego przebiegu modelu (K11, 25.09.2026).
            'returned_top' => array_map(fn (array $row): array => $row + ['tag' => $this->tag($row['sku'], $case)], $top),
            'candidates' => count($trace['candidate_ids'] ?? []),
            'duration_ms' => 0,
            // Droga przez model: bez niej raport „przed/po” zmiany w ścieżce po pustej ocenie pokazywał same
            // metryki, a nie które przypadki poszły drugą oceną albo przepisaniem zapytania (24.09.2026).
            'model_state' => is_string($result['model_state'] ?? null) ? $result['model_state'] : null,
            'rank_passes' => (int) ($trace['passes'] ?? 0),
            'rewrite' => array_key_exists('rewrite_llm', is_array($trace['timings_ms'] ?? null) ? $trace['timings_ms'] : []),
        ];
    }

    /**
     * Próg zapisu pozycji przetargu z Ustawień AI (match_min_score) — od niego zależy, czy zakazana
     * propozycja jest ostrzeżeniem, czy naruszeniem blokującym. Czytany raz na przebieg, żeby wszystkie
     * przypadki i nagłówek raportu mówiły o tym samym progu.
     */
    public function matchMinScore(): int
    {
        return $this->matchMinScore ??= $this->settings->matchMinScore();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, float|int>
     */
    public function summarize(array $rows): array
    {
        $count = count($rows);
        if ($count === 0) {
            return [];
        }

        $sum = [
            'retrieval_recall' => 0.0,
            'recall_at_k' => 0.0,
            'precision_at_k' => 0.0,
            'ndcg_at_k' => 0.0,
            'mrr' => 0.0,
        ];
        $violations = 0;
        $blocking = 0;
        $shown = 0;
        $acceptableHits = 0;
        $errors = 0;
        $duration = 0;
        foreach ($rows as $row) {
            foreach ($sum as $key => $value) {
                $sum[$key] = $value + (float) ($row[$key] ?? 0.0);
            }
            $violations += count($row['violations'] ?? []);
            // Wiersz sprzed 25.09.2026 nie rozróżnia pokazanych — każde naruszenie liczy się jak dotąd, jako blokujące.
            $blocking += count($row['violations_blocking'] ?? $row['violations'] ?? []);
            $shown += count($row['forbidden_shown'] ?? []);
            $acceptableHits += count($row['acceptable_hits'] ?? []);
            $errors += ($row['error'] ?? null) !== null ? 1 : 0;
            $duration += (int) ($row['duration_ms'] ?? 0);
        }

        $out = [];
        foreach ($sum as $key => $value) {
            $out[$key] = round($value / $count, 4);
        }
        $out['cases'] = $count;
        $out['violations'] = $violations;
        $out['violations_blocking'] = $blocking;
        $out['forbidden_shown'] = $shown;
        $out['acceptable_hits'] = $acceptableHits;
        $out['errors'] = $errors;
        $out['avg_ms'] = (int) round($duration / $count);

        return $out;
    }

    /**
     * Rola karty z wyniku w golden secie. Zakaz wygrywa, jak w violations(); równoważnik nie pokrywa się
     * z wzorcowymi ani zakazanymi (pilnuje tego loadCases()).
     *
     * @param  array{expected_skus: list<string>, acceptable_skus?: list<string>, forbidden_skus: list<string>}  $case
     */
    private function tag(string $sku, array $case): string
    {
        $sku = SearchEvalMetrics::normalize($sku);

        return match (true) {
            in_array($sku, SearchEvalMetrics::normalizeAll($case['forbidden_skus']), true) => 'forbidden',
            in_array($sku, SearchEvalMetrics::normalizeAll($case['expected_skus']), true) => 'expected',
            in_array($sku, SearchEvalMetrics::normalizeAll($case['acceptable_skus'] ?? []), true) => 'acceptable',
            default => 'other',
        };
    }

    /**
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function skusFor(array $ids): array
    {
        $ids = array_values(array_filter(array_map(intval(...), $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return [];
        }

        return Product::query()
            ->whereIn('id', $ids)
            ->pluck('sku')
            ->map(static fn ($sku): string => (string) $sku)
            ->all();
    }

    /**
     * @param  list<string>  $skus
     * @return list<string>
     */
    private function existingSkus(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        return Product::query()
            ->whereIn('sku', $skus)
            ->pluck('sku')
            ->map(static fn ($sku): string => (string) $sku)
            ->all();
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $value) {
            if (is_string($value) && trim($value) !== '') {
                $out[] = trim($value);
            }
        }

        return array_values(array_unique($out));
    }
}
