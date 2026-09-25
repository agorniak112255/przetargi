<?php

declare(strict_types=1);

namespace App\Services\Vector;

use App\Services\Ai\AiSettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class QdrantClient
{
    public function __construct(
        private readonly AiSettingsService $settings,
    ) {}

    public function isConfigured(): bool
    {
        $cfg = $this->settings->resolve();

        return ! (bool) config('ai.vector_eval_disabled', false)
            && (bool) $cfg['vector_enabled']
            && is_string($cfg['qdrant_url'] ?? null)
            && trim((string) $cfg['qdrant_url']) !== '';
    }

    public function collection(): string
    {
        return $this->settings->embeddingCollection();
    }

    /**
     * @return array{ok: bool, message: string, collections?: list<string>}
     */
    public function ping(): array
    {
        try {
            $response = $this->http()->get($this->base().'/collections');
        } catch (ConnectionException $e) {
            return ['ok' => false, 'message' => 'Qdrant niedostępny: '.$e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => 'Qdrant HTTP '.$response->status()];
        }

        $names = [];
        foreach (data_get($response->json(), 'result.collections', []) as $row) {
            if (is_array($row) && isset($row['name'])) {
                $names[] = (string) $row['name'];
            }
        }

        return [
            'ok' => true,
            'message' => 'Połączenie z Qdrant OK (kolekcji: '.count($names).').',
            'collections' => $names,
        ];
    }

    public function ensureCollection(int $vectorSize): void
    {
        $name = $this->collection();
        $exists = $this->http()->get($this->base().'/collections/'.$name);
        if ($exists->successful()) {
            $current = (int) data_get($exists->json(), 'result.config.params.vectors.size', 0);
            if ($current > 0 && $current !== $vectorSize) {
                throw new RuntimeException(
                    'Kolekcja Qdrant "'.$name.'" ma wymiar '.$current.', a model embeddings zwraca '
                    .$vectorSize.'. Usuń kolekcję i uruchom products:reindex-embeddings --force.'
                );
            }

            return;
        }

        $create = $this->http()->put($this->base().'/collections/'.$name, [
            'vectors' => [
                'size' => $vectorSize,
                'distance' => 'Cosine',
            ],
        ]);

        if (! $create->successful() && $create->status() !== 409) {
            throw new RuntimeException(
                'Nie udało się utworzyć kolekcji Qdrant: HTTP '.$create->status().' '.$create->body()
            );
        }
    }

    /** Kasuje kolekcję aktywnego profilu — konieczne przy zmianie wymiaru wektora. */
    public function dropCollection(): bool
    {
        $response = $this->http()->delete($this->base().'/collections/'.$this->collection());

        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException('Qdrant delete collection HTTP '.$response->status().': '.$response->body());
        }

        return $response->successful();
    }

    /**
     * @param  list<float>  $vector
     * @param  array<string, mixed>  $payload
     */
    public function upsert(int $pointId, array $vector, array $payload = []): void
    {
        $this->ensureCollection(count($vector));
        $response = $this->http()->put(
            $this->base().'/collections/'.$this->collection().'/points?wait=true',
            [
                'points' => [[
                    'id' => $pointId,
                    'vector' => $vector,
                    'payload' => $payload,
                ]],
            ]
        );

        if (! $response->successful()) {
            throw new RuntimeException('Qdrant upsert HTTP '.$response->status().': '.$response->body());
        }
    }

    public function delete(int $pointId): void
    {
        $this->deleteMany([$pointId]);
    }

    /**
     * Kasuje punkty jednym żądaniem. Brak kolekcji (404) — nie ma czego kasować.
     *
     * @param  list<int>  $pointIds
     */
    public function deleteMany(array $pointIds): void
    {
        if ($pointIds === []) {
            return;
        }

        $response = $this->http()->post(
            $this->base().'/collections/'.$this->collection().'/points/delete?wait=true',
            [
                'points' => array_values($pointIds),
            ]
        );

        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException('Qdrant delete HTTP '.$response->status().': '.$response->body());
        }
    }

    /**
     * Jedna strona punktów kolekcji, rosnąco po numerze, bez wektorów. Kolekcji nie zakłada (bez ensureCollection) —
     * przegląd ma tylko czytać. Następną stronę daje next_offset (numer pierwszego punktu następnej strony), null
     * to koniec kolekcji. Punkt karty ma numer karty; punkt z numerem UUID nie jest kartą i zostaje pominięty.
     *
     * @return array{points: list<array{id: int, payload: array<string, mixed>}>, next_offset: int|string|null}
     */
    public function scroll(int|string|null $offset = null, int $limit = 1000, bool $withPayload = false): array
    {
        $body = [
            'limit' => max(1, $limit),
            'with_payload' => $withPayload,
            'with_vector' => false,
        ];
        if ($offset !== null) {
            $body['offset'] = $offset;
        }

        $response = $this->http()->post($this->base().'/collections/'.$this->collection().'/points/scroll', $body);

        if (! $response->successful()) {
            throw new RuntimeException('Qdrant scroll HTTP '.$response->status().': '.$response->body());
        }

        $points = [];
        foreach ((array) data_get($response->json(), 'result.points', []) as $point) {
            if (! is_array($point) || ! is_int($point['id'] ?? null)) {
                continue;
            }
            $points[] = [
                'id' => $point['id'],
                'payload' => is_array($point['payload'] ?? null) ? $point['payload'] : [],
            ];
        }
        $next = data_get($response->json(), 'result.next_page_offset');

        return [
            'points' => $points,
            'next_offset' => is_int($next) || (is_string($next) && $next !== '') ? $next : null,
        ];
    }

    /**
     * @param  list<float>  $vector
     * @return list<array{id: int, score: float}>
     */
    public function search(array $vector, int $limit = 40): array
    {
        $this->ensureCollection(count($vector));
        $response = $this->http()->post(
            $this->base().'/collections/'.$this->collection().'/points/search',
            [
                'vector' => $vector,
                'limit' => max(1, min(200, $limit)),
                'with_payload' => false,
            ]
        );

        if (! $response->successful()) {
            throw new RuntimeException('Qdrant search HTTP '.$response->status().': '.$response->body());
        }

        $out = [];
        foreach (data_get($response->json(), 'result', []) as $hit) {
            if (! is_array($hit)) {
                continue;
            }
            $id = (int) ($hit['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'score' => (float) ($hit['score'] ?? 0),
            ];
        }

        return $out;
    }

    private function base(): string
    {
        $cfg = $this->settings->resolve();
        $url = rtrim((string) ($cfg['qdrant_url'] ?? ''), '/');
        if ($url === '') {
            throw new RuntimeException('Brak qdrant_url w ustawieniach AI.');
        }

        return $url;
    }

    private function http(): PendingRequest
    {
        $cfg = $this->settings->resolve();
        $req = Http::timeout(30)->acceptJson();
        $key = $cfg['qdrant_api_key'] ?? null;
        if (is_string($key) && $key !== '') {
            $req = $req->withHeaders(['api-key' => $key]);
        }

        return $req;
    }
}
