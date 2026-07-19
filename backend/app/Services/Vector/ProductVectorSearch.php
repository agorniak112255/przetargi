<?php

declare(strict_types=1);

namespace App\Services\Vector;

use Illuminate\Support\Facades\Log;
use Throwable;

final class ProductVectorSearch
{
    public function __construct(
        private readonly EmbeddingClient $embeddings,
        private readonly QdrantClient $qdrant,
    ) {}

    public function enabled(): bool
    {
        return $this->qdrant->isConfigured();
    }

    /**
     * @return list<array{id: int, score: float}>
     */
    public function similar(string $query, int $limit = 40): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $query = trim($query);
        if ($query === '') {
            return [];
        }

        try {
            $vector = $this->embeddings->embed($query);

            return $this->qdrant->search($vector, $limit);
        } catch (Throwable $e) {
            Log::warning('Product vector search failed', ['error' => $e->getMessage()]);

            return [];
        }
    }
}
