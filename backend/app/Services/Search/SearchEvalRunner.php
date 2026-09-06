<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Product;
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
    public function __construct(
        private readonly ProductAiSearchService $search,
    ) {}

    /**
     * @return list<array{id: string, query: string, expected_skus: list<string>, forbidden_skus: list<string>, note: string}>
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
            $out[] = [
                'id' => trim((string) ($case['id'] ?? ('case-'.($i + 1)))),
                'query' => $query,
                'expected_skus' => $expected,
                'forbidden_skus' => $this->stringList($case['forbidden_skus'] ?? []),
                'note' => trim((string) ($case['note'] ?? '')),
            ];
        }

        if ($out === []) {
            throw new InvalidArgumentException("Golden set jest pusty: {$path}");
        }

        return $out;
    }

    /**
     * @param  array{id: string, query: string, expected_skus: list<string>, forbidden_skus: list<string>, note: string}  $case
     * @return array<string, mixed>
     */
    public function evaluate(array $case, int $k, int $limit): array
    {
        $started = hrtime(true);
        try {
            $result = $this->search->search($case['query'], $limit);
        } catch (Throwable $e) {
            return [
                'id' => $case['id'],
                'query' => $case['query'],
                'error' => $e->getMessage(),
                'retrieval_recall' => 0.0,
                'recall_at_k' => 0.0,
                'precision_at_k' => 0.0,
                'ndcg_at_k' => 0.0,
                'mrr' => 0.0,
                'violations' => [],
                'missing_skus' => SearchEvalMetrics::normalizeAll($case['expected_skus']),
                'unknown_skus' => [],
                'returned' => 0,
                'candidates' => 0,
                'duration_ms' => (int) round((hrtime(true) - $started) / 1e6),
            ];
        }

        $trace = $this->search->lastTrace();
        $rankedSkus = [];
        foreach ($result['products'] as $row) {
            $rankedSkus[] = (string) ($row['sku'] ?? '');
        }
        $candidateSkus = $this->skusFor($trace['candidate_ids'] ?? []);
        $expected = $case['expected_skus'];

        $unknown = array_values(array_diff(
            SearchEvalMetrics::normalizeAll($expected),
            SearchEvalMetrics::normalizeAll($this->existingSkus($expected)),
        ));
        $foundSet = array_flip(SearchEvalMetrics::normalizeAll($rankedSkus));
        $missing = [];
        foreach (SearchEvalMetrics::normalizeAll($expected) as $sku) {
            if (! isset($foundSet[$sku])) {
                $missing[] = $sku;
            }
        }

        return [
            'id' => $case['id'],
            'query' => $case['query'],
            'error' => null,
            // Etap 1: czy oczekiwany produkt w ogóle wszedł do puli kandydatów.
            'retrieval_recall' => SearchEvalMetrics::recall($expected, $candidateSkus),
            // Etap 2: co z tego zostało w wyniku oddanym użytkownikowi.
            'recall_at_k' => SearchEvalMetrics::recallAt($expected, $rankedSkus, $k),
            'precision_at_k' => SearchEvalMetrics::precisionAt($expected, $rankedSkus, $k),
            'ndcg_at_k' => SearchEvalMetrics::ndcgAt($expected, $rankedSkus, $k),
            'mrr' => SearchEvalMetrics::reciprocalRank($expected, $rankedSkus),
            'violations' => SearchEvalMetrics::violations($case['forbidden_skus'], $rankedSkus, $k),
            'missing_skus' => $missing,
            // Zły wpis w golden secie, nie zła wyszukiwarka — trzeba go poprawić.
            'unknown_skus' => $unknown,
            'returned' => count($rankedSkus),
            'candidates' => count($trace['candidate_ids'] ?? []),
            'duration_ms' => (int) round((hrtime(true) - $started) / 1e6),
        ];
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
        $errors = 0;
        $duration = 0;
        foreach ($rows as $row) {
            foreach ($sum as $key => $value) {
                $sum[$key] = $value + (float) ($row[$key] ?? 0.0);
            }
            $violations += count($row['violations'] ?? []);
            $errors += ($row['error'] ?? null) !== null ? 1 : 0;
            $duration += (int) ($row['duration_ms'] ?? 0);
        }

        $out = [];
        foreach ($sum as $key => $value) {
            $out[$key] = round($value / $count, 4);
        }
        $out['cases'] = $count;
        $out['violations'] = $violations;
        $out['errors'] = $errors;
        $out['avg_ms'] = (int) round($duration / $count);

        return $out;
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
