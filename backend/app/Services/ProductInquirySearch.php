<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Ai\AiTask;

class ProductInquirySearch
{
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
}
