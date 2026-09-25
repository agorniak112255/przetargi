<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Atrapa Qdrant pod prawdziwym ProductEmbeddingIndexer (klasa final): wyszukiwanie wektorowe włączone w konfiguracji
 * (test bez wiersza ustawień AI) na adresie qdrant.test, odpowiedzi z Http::fake — test nie wychodzi do sieci.
 */
final class FakeQdrant
{
    /** Włącza wektory i odpowiada na każde żądanie do Qdrant kodem $status (500 — Qdrant z błędem). */
    public static function enable(int $status = 200): void
    {
        config(['ai.vector_enabled' => true, 'ai.qdrant_url' => 'http://qdrant.test:6333']);
        Http::fake(['qdrant.test:6333/*' => Http::response(['result' => ['status' => 'completed']], $status)]);
    }

    /**
     * Włącza wektory i udaje kolekcję z punktami (numer => payload): przegląd (POST …/points/scroll) oddaje je rosnąco
     * po `limit`, od numeru `offset` włącznie, z next_page_offset = numer pierwszego punktu następnej strony (null na
     * końcu) — jak Qdrant 25.09.2026 na produkcji. Kasowanie odpowiada $deleteStatus i niczego z kolekcji nie zabiera.
     *
     * @param  array<int, array<string, mixed>>  $points
     */
    public static function withPoints(array $points, int $deleteStatus = 200): void
    {
        ksort($points);
        config(['ai.vector_enabled' => true, 'ai.qdrant_url' => 'http://qdrant.test:6333']);
        Http::fake(['qdrant.test:6333/*' => static function (Request $request) use ($points, $deleteStatus) {
            if (! str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), '/points/scroll')) {
                return Http::response(['result' => ['status' => 'completed'], 'status' => 'ok'], $deleteStatus);
            }

            $offset = $request->data()['offset'] ?? null;
            $ids = array_values(array_filter(array_keys($points), static fn (int $id): bool => $offset === null || $id >= $offset));
            $limit = (int) ($request->data()['limit'] ?? 10);
            $withPayload = (bool) ($request->data()['with_payload'] ?? false);

            return Http::response(['result' => [
                'points' => array_map(static fn (int $id): array => [
                    'id' => $id,
                    'payload' => $withPayload ? $points[$id] : null,
                    'vector' => null,
                ], array_slice($ids, 0, $limit)),
                'next_page_offset' => $ids[$limit] ?? null,
            ], 'status' => 'ok']);
        }]);
    }

    /**
     * Karty, których wektory skasowano (POST …/points/delete), rosnąco — karta kasowana dwa razy jest na liście dwa razy.
     *
     * @return list<int>
     */
    public static function deletedIds(): array
    {
        $ids = [];
        foreach (Http::recorded(static fn (Request $request): bool => str_contains($request->url(), '/points/delete')) as [$request]) {
            foreach ((array) ($request->data()['points'] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        }
        sort($ids);

        return $ids;
    }
}
