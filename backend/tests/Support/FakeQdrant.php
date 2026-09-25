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
