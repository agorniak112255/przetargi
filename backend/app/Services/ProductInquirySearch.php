<?php

declare(strict_types=1);

namespace App\Services;

use App\Services\Ai\AiTask;
use App\Services\Search\AiProductSearch;

class ProductInquirySearch
{
    private const MAX_PARALLEL = 10;

    public function __construct(
        private readonly AiProductSearch $search,
    ) {}

    /**
     * @return array{products: list<array<string, mixed>>}
     */
    public function find(string $query, int $limit): array
    {
        $result = $this->search->find($query, $limit, AiTask::ProductSearch);

        return [
            'products' => is_array($result['products'] ?? null) ? $result['products'] : [],
        ];
    }

    /**
     * @param  list<string>  $queries
     * @return list<array{query: string, products: list<array<string, mixed>>, model_state: string|null, requested_brand_absent: string|null, timings_ms: array<string, int>}>
     */
    public function findMany(array $queries, int $limit): array
    {
        $results = $this->search->findMany(
            $queries,
            $limit,
            AiTask::ProductSearch,
            self::MAX_PARALLEL,
        );
        $out = [];
        foreach ($results as $i => $result) {
            $out[] = [
                'query' => $queries[$i] ?? (string) ($result['query'] ?? ''),
                'products' => is_array($result['products'] ?? null) ? $result['products'] : [],
                // „unavailable” = model nie odpowiedział; pusta lista nie znaczy wtedy „brak w katalogu”
                'model_state' => is_string($result['model_state'] ?? null) ? $result['model_state'] : null,
                // Marka z zapytania, której nie ma w katalogu — wtedy wyniki to zamienniki innej marki.
                'requested_brand_absent' => $this->absentBrand($result),
                // Czasy etapów całej fali (zrozumienie, katalog, ocena modelu) — te same w każdym wierszu.
                'timings_ms' => is_array($result['trace']['timings_ms'] ?? null) ? $result['trace']['timings_ms'] : [],
                // Koszt pozycji, ślad (pula, karty oceny) i to, co zrozumiał model — do zdarzenia wyszukiwania
                // (ekran „Statystyki AI”: lista awarii i pustych ocen pokazuje uwagę i szukany produkt).
                'ai_usage' => is_array($result['ai_usage'] ?? null) ? $result['ai_usage'] : null,
                'trace' => is_array($result['trace'] ?? null) ? $result['trace'] : [],
                'needed' => is_string($result['needed'] ?? null) ? $result['needed'] : null,
                'search_phrases' => is_array($result['search_phrases'] ?? null) ? $result['search_phrases'] : [],
                'ai_note' => is_string($result['ai_note'] ?? null) ? $result['ai_note'] : null,
            ];
        }

        return $out;
    }

    /** @param  array<string, mixed>  $result */
    private function absentBrand(array $result): ?string
    {
        $intent = is_array($result['parsed_intent'] ?? null) ? $result['parsed_intent'] : [];
        if (($intent['manufacturer_absent_in_catalog'] ?? false) !== true) {
            return null;
        }
        $name = trim((string) ($intent['manufacturer_requested'] ?? ''));

        return $name !== '' ? $name : null;
    }
}
