<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Ai\AiTask;

class ProductInquirySearch
{
    private const MAX_PARALLEL = 10;

    public function __construct(
        private readonly ProductAiSearchService $search,
    ) {}

    /**
     * @return array{products: list<array<string, mixed>>}
     */
    public function find(string $query, int $limit): array
    {
        $result = $this->search->search($query, $limit, false, AiTask::ProductSearch);

        return [
            'products' => is_array($result['products'] ?? null) ? $result['products'] : [],
        ];
    }

    /**
     * @param  list<string>  $queries
     * @return list<array{query: string, products: list<array<string, mixed>>}>
     */
    public function findMany(array $queries, int $limit): array
    {
        $results = $this->search->searchMany(
            $queries,
            $limit,
            false,
            AiTask::ProductSearch,
            self::MAX_PARALLEL,
        );
        $out = [];
        foreach ($results as $i => $result) {
            $out[] = [
                'query' => $queries[$i] ?? (string) ($result['query'] ?? ''),
                'products' => is_array($result['products'] ?? null) ? $result['products'] : [],
            ];
        }

        return $out;
    }
}
