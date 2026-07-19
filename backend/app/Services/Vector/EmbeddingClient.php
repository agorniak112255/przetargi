<?php

declare(strict_types=1);

namespace App\Services\Vector;

use App\Services\Ai\AiSettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class EmbeddingClient
{
    public function __construct(
        private readonly AiSettingsService $settings,
    ) {}

    /**
     * @return list<float>
     */
    public function embed(string $text): array
    {
        $cfg = $this->settings->resolve();
        $base = rtrim((string) ($cfg['embedding_base_url'] ?: $cfg['base_url']), '/');
        $key = $cfg['embedding_api_key'] ?: $cfg['api_key'];
        $model = trim((string) ($cfg['embedding_model'] ?: 'text-embedding-3-small'));

        if ($base === '' || $key === null || $key === '') {
            throw new RuntimeException('Brak konfiguracji embeddings (base_url / api_key).');
        }
        if ($model === '') {
            throw new RuntimeException('Brak modelu embeddings.');
        }

        $text = trim($text);
        if ($text === '') {
            throw new RuntimeException('Pusty tekst do embeddingu.');
        }
        if (mb_strlen($text) > 8000) {
            $text = mb_substr($text, 0, 8000);
        }

        $url = $base.'/embeddings';

        try {
            $response = Http::withToken($key)
                ->timeout(max(15, (int) $cfg['timeout_seconds']))
                ->acceptJson()
                ->post($url, [
                    'model' => $model,
                    'input' => $text,
                ]);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Nie można połączyć z API embeddings: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $body = $response->json();
            $detail = is_array($body)
                ? (string) data_get($body, 'error.message', $response->body())
                : $response->body();
            throw new RuntimeException('Embeddings HTTP '.$response->status().': '.$detail);
        }

        $vector = data_get($response->json(), 'data.0.embedding');
        if (! is_array($vector) || $vector === []) {
            throw new RuntimeException('API embeddings nie zwróciło wektora.');
        }

        return array_map(static fn ($v): float => (float) $v, array_values($vector));
    }
}
