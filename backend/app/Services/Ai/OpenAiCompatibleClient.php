<?php

declare(strict_types=1);

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class OpenAiCompatibleClient
{
    public function __construct(
        private readonly AiSettingsService $settings,
        private readonly JsonResponseParser $jsonParser,
    ) {}

    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     * @return array{content: string, model: string, usage: array<string, mixed>|null}
     */
    public function chat(array $messages, ?float $temperature = null, bool $jsonMode = true, ?array $extra = null): array
    {
        $cfg = $this->settings->resolve();
        if (! $cfg['enabled']) {
            throw new RuntimeException('Integracja AI jest wyłączona. Włącz ją w Ustawieniach AI.');
        }
        if (! $cfg['has_api_key'] || $cfg['api_key'] === null) {
            throw new RuntimeException('Brak klucza API AI. Uzupełnij go w Ustawieniach AI.');
        }

        $url = $cfg['base_url'].'/chat/completions';
        $basePayload = [
            'model' => $cfg['model'],
            'temperature' => $temperature ?? $cfg['temperature'],
            'messages' => $messages,
            'max_tokens' => 16000,
        ];
        if (is_array($extra)) {
            $basePayload = array_merge($basePayload, $extra);
        }

        try {
            $response = null;
            if ($jsonMode) {
                $response = $this->post($url, $cfg['api_key'], $basePayload + [
                    'response_format' => ['type' => 'json_object'],
                ]);
            }
            if ($response === null || in_array($response->status(), [400, 404, 422], true)) {
                $response = $this->post($url, $cfg['api_key'], $basePayload);
            }
        } catch (ConnectionException $e) {
            throw new RuntimeException('Nie można połączyć z API AI: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $body = $response->json();
            $detail = is_array($body)
                ? (string) data_get($body, 'error.message', $response->body())
                : $response->body();
            throw new RuntimeException('API AI zwróciło błąd HTTP '.$response->status().': '.$detail);
        }

        $payload = $response->json();
        $content = $this->extractContent($payload);
        if ($content === '') {
            Log::warning('AI empty content', ['body' => $payload]);
            throw new RuntimeException('API AI zwróciło pustą odpowiedź.');
        }

        return [
            'content' => $content,
            'model' => (string) data_get($payload, 'model', $cfg['model']),
            'usage' => data_get($payload, 'usage'),
        ];
    }

    /**
     * Analiza PDF przez model multimodalny (OpenRouter/Gemini).
     *
     * @return array{content: string, model: string, usage: array<string, mixed>|null}
     */
    public function chatWithPdf(string $prompt, string $pdfPath, string $filename): array
    {
        $bytes = file_get_contents($pdfPath);
        if ($bytes === false) {
            throw new RuntimeException('Nie można odczytać pliku PDF.');
        }
        // Vision/base64: duże pliki (>4 MB) omijamy — analiza tekstowa w chunkach
        if (strlen($bytes) > 4_000_000) {
            throw new RuntimeException(
                'PDF za duży na vision ('.round(strlen($bytes) / 1_000_000, 1).' MB) — używam analizy tekstowej.'
            );
        }

        $b64 = base64_encode($bytes);
        $messages = [
            [
                'role' => 'system',
                'content' => 'Jesteś ekspertem od cenników BHP. Zwracasz WYŁĄCZNIE JSON (bez markdown).',
            ],
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    [
                        'type' => 'file',
                        'file' => [
                            'filename' => $filename,
                            'file_data' => 'data:application/pdf;base64,'.$b64,
                        ],
                    ],
                ],
            ],
        ];

        return $this->chat($messages, 0.1, true, [
            'plugins' => [
                [
                    'id' => 'file-parser',
                    'pdf' => ['engine' => 'native'],
                ],
            ],
        ]);
    }

    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     * @return array<string, mixed>
     */
    public function chatJson(array $messages, ?float $temperature = null): array
    {
        $result = $this->chat($messages, $temperature);

        try {
            return $this->jsonParser->parse($result['content']);
        } catch (RuntimeException) {
            $repair = $this->chat([
                [
                    'role' => 'system',
                    'content' => 'Napraw odpowiedź do poprawnego JSON obiektu. Zwróć TYLKO JSON, bez markdown i komentarzy.',
                ],
                [
                    'role' => 'user',
                    'content' => "Popraw poniższy tekst do walidnego JSON:\n\n".$result['content'],
                ],
            ], 0.0);

            return $this->jsonParser->parse($repair['content']);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(string $url, string $apiKey, array $payload): \Illuminate\Http\Client\Response
    {
        $timeout = max(120, (int) ($this->settings->resolve()['timeout_seconds'] ?? 90));

        return Http::withToken($apiKey)
            ->acceptJson()
            ->withHeaders([
                'HTTP-Referer' => config('app.url', 'http://localhost'),
                'X-Title' => 'SUPON AI',
            ])
            ->timeout($timeout)
            ->post($url, $payload);
    }

    private function extractContent(mixed $payload): string
    {
        $content = data_get($payload, 'choices.0.message.content');

        if (is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (is_string($part)) {
                    $parts[] = $part;
                } elseif (is_array($part)) {
                    $parts[] = (string) ($part['text'] ?? $part['content'] ?? '');
                }
            }
            $content = implode('', $parts);
        }

        if (! is_string($content)) {
            $content = (string) data_get($payload, 'choices.0.message.reasoning', '');
        }

        return trim((string) $content);
    }
}
