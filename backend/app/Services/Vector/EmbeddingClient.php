<?php

declare(strict_types=1);

namespace App\Services\Vector;

use App\Services\Ai\AiSettingsService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

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
        $profile = $this->settings->embeddingProfile();
        $base = $profile['base_url'];
        $key = $profile['api_key'];
        $model = trim($profile['model']);

        if ($base === '' || $key === null || $key === '') {
            throw new RuntimeException($profile['provider'] === AiSettingsService::EMBEDDING_OPENAI
                ? 'Brak klucza API OpenAI dla embeddingów.'
                : 'Brak konfiguracji embeddings (base_url / api_key).');
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

    /**
     * Równoległe embeddingi — klucz to przycięty tekst.
     *
     * @param  list<string>  $texts
     * @return array<string, list<float>>
     */
    public function embedMany(array $texts): array
    {
        $unique = [];
        foreach ($texts as $text) {
            $text = trim((string) $text);
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text) > 8000) {
                $text = mb_substr($text, 0, 8000);
            }
            $unique[$text] = true;
        }
        $list = array_keys($unique);
        if ($list === []) {
            return [];
        }
        if (count($list) === 1) {
            return [$list[0] => $this->embed($list[0])];
        }

        $cfg = $this->settings->resolve();
        $profile = $this->settings->embeddingProfile();
        $base = $profile['base_url'];
        $key = $profile['api_key'];
        $model = trim($profile['model']);
        if ($base === '' || $key === null || $key === '' || $model === '') {
            throw new RuntimeException('Brak konfiguracji embeddings (base_url / api_key / model).');
        }

        $url = $base.'/embeddings';
        $timeout = max(15, (int) $cfg['timeout_seconds']);
        $responses = Http::pool(function (Pool $pool) use ($list, $url, $key, $timeout, $model) {
            foreach ($list as $i => $text) {
                $req = $pool->as((string) $i)
                    ->acceptJson()
                    ->timeout($timeout)
                    ->connectTimeout(15);
                if ($key !== '') {
                    $req = $req->withToken($key);
                }
                $req->post($url, [
                    'model' => $model,
                    'input' => $text,
                ]);
            }
        });

        $out = [];
        foreach ($list as $i => $text) {
            $response = $responses[(string) $i] ?? $responses[$i] ?? null;
            if ($response instanceof Throwable || ! $response instanceof Response || ! $response->successful()) {
                continue;
            }
            $vector = data_get($response->json(), 'data.0.embedding');
            if (! is_array($vector) || $vector === []) {
                continue;
            }
            $out[$text] = array_map(static fn ($v): float => (float) $v, array_values($vector));
        }

        return $out;
    }
}
