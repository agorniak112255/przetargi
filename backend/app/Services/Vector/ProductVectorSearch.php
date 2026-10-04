<?php

declare(strict_types=1);

namespace App\Services\Vector;

use Illuminate\Support\Facades\Log;
use Throwable;

final class ProductVectorSearch
{
    private const PREFETCH_LIMIT = 150;

    /**
     * Najwięcej zapytań w pamięci trafień. Pamięć służy jednemu wyszukiwaniu (prefetch fali → similar() tych samych
     * tekstów); bez limitu długi przebieg CLI rośnie bez końca — erp:suggest (4.10.2026): 130 zapytań = 8 MB, przebieg
     * 2000 towarów nie mieścił się w 128 MB.
     */
    private const HIT_CACHE_LIMIT = 256;

    /** @var array<string, list<array{id: int, score: float}>> */
    private array $hitCache = [];

    public function __construct(
        private readonly EmbeddingClient $embeddings,
        private readonly QdrantClient $qdrant,
    ) {}

    public function enabled(): bool
    {
        return $this->qdrant->isConfigured();
    }

    /**
     * Embeddingi wielu zapytań naraz (Http::pool), potem Qdrant.
     *
     * @param  list<string>  $queries
     */
    public function prefetch(array $queries): void
    {
        if (! $this->enabled()) {
            return;
        }

        $pending = [];
        foreach ($queries as $query) {
            $query = trim((string) $query);
            if ($query !== '' && ! isset($this->hitCache[$query])) {
                $pending[$query] = true;
            }
        }
        $texts = array_keys($pending);
        if ($texts === []) {
            return;
        }
        // miejsce na całą falę z góry — jej wpisy nie wypadają, zanim similar() je przeczyta
        $this->makeRoom(count($texts));

        try {
            $vectors = $this->embeddings->embedMany($texts);
        } catch (Throwable $e) {
            Log::warning('Product vector prefetch failed', ['error' => $e->getMessage()]);

            return;
        }

        foreach ($texts as $text) {
            $vector = $vectors[$text] ?? [];
            if ($vector === []) {
                $this->hitCache[$text] = [];

                continue;
            }
            try {
                $this->hitCache[$text] = $this->qdrant->search($vector, self::PREFETCH_LIMIT);
            } catch (Throwable $e) {
                Log::warning('Product vector prefetch search failed', ['error' => $e->getMessage()]);
                $this->hitCache[$text] = [];
            }
        }
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
        if (isset($this->hitCache[$query])) {
            return array_slice($this->hitCache[$query], 0, max(1, $limit));
        }

        try {
            $vector = $this->embeddings->embed($query);
            $hits = $this->qdrant->search($vector, $limit);
            $this->makeRoom(1);
            $this->hitCache[$query] = $hits;

            return $hits;
        } catch (Throwable $e) {
            Log::warning('Product vector search failed', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /** Usuwa najstarsze wpisy, żeby po dodaniu $incoming nowych pamięć nie przekroczyła limitu. */
    private function makeRoom(int $incoming): void
    {
        $excess = count($this->hitCache) + $incoming - self::HIT_CACHE_LIMIT;
        if ($excess <= 0) {
            return;
        }
        $this->hitCache = $excess >= count($this->hitCache) ? [] : array_slice($this->hitCache, $excess, null, true);
    }
}
