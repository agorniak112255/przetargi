<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Enrichment\DuckDuckGoHtmlSearch;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OpenAiCompatibleClient
{
    /** Model przeciążony / limit zapytań — warto poczekać i ponowić. */
    private const OVERLOAD_STATUSES = [429, 503, 529];

    private const OVERLOAD_RETRIES = 5;

    public function __construct(
        private readonly AiSettingsService $settings,
        private readonly JsonResponseParser $jsonParser,
        private readonly ChatCompletionContent $contentReader = new ChatCompletionContent,
        private readonly DuckDuckGoHtmlSearch $duckDuckGo = new DuckDuckGoHtmlSearch,
    ) {}

    /**
     * $task wskazuje profil modelu z Ustawień AI; bez niego działa konfiguracja główna.
     *
     * @param  list<array{role: string, content: mixed}>  $messages
     * @return array{content: string, model: string, usage: array<string, mixed>|null}
     */
    public function chat(
        array $messages,
        ?float $temperature = null,
        bool $jsonMode = true,
        ?array $extra = null,
        ?AiTask $task = null
    ): array {
        return $this->withProfileFallback(
            $task,
            fn (array $profile): array => $this->chatWithProfile($profile, $messages, $temperature, $jsonMode, $extra)
        );
    }

    /**
     * Cichy fallback: brak środków, limit 429 albo padnięty endpoint profilu nie może
     * zatrzymać funkcji, która przed wprowadzeniem profili działała na modelu głównym.
     *
     * @param  callable(array<string, mixed>): array<string, mixed>  $run
     * @return array<string, mixed>
     */
    private function withProfileFallback(?AiTask $task, callable $run): array
    {
        $profile = $this->settings->profileForTask($task);

        try {
            return $run($profile);
        } catch (RuntimeException $e) {
            if ($profile['is_default']) {
                throw $e;
            }

            Log::warning('Profil AI zawiódł — schodzę na konfigurację główną', [
                'task' => $task?->value,
                'profile' => $profile['label'],
                'error' => $e->getMessage(),
            ]);

            return $run($this->settings->profileForTask(null));
        }
    }

    /**
     * @param  array{label: string, base_url: string, api_key: ?string, model: string, timeout_seconds: int, temperature: float, is_default: bool}  $profile
     * @param  list<array{role: string, content: mixed}>  $messages
     * @return array{content: string, model: string, usage: array<string, mixed>|null}
     */
    private function chatWithProfile(
        array $profile,
        array $messages,
        ?float $temperature,
        bool $jsonMode,
        ?array $extra
    ): array {
        if (! $this->settings->resolve()['enabled']) {
            throw new RuntimeException('Integracja AI jest wyłączona. Włącz ją w Ustawieniach AI.');
        }
        if ($profile['api_key'] === null || $profile['api_key'] === '') {
            throw new RuntimeException($profile['is_default']
                ? 'Brak klucza API AI. Uzupełnij go w Ustawieniach AI.'
                : 'Brak klucza API dla profilu „'.$profile['label'].'”.');
        }

        $url = $profile['base_url'].'/chat/completions';
        $extra = is_array($extra) ? $extra : [];
        $model = is_string($extra['model'] ?? null) && trim((string) $extra['model']) !== ''
            ? trim((string) $extra['model'])
            : $profile['model'];
        $reasoning = $this->isReasoningModel($model);
        // Mniejszy budżet = mniejszy kontekst na slot, czyli więcej równoległych
        // slotów lokalnego modelu w tym samym VRAM. Przy urwaniu i tak ponawiamy z 16000.
        $maxTokens = (int) ($extra['max_tokens'] ?? 8000);
        if ($reasoning) {
            $maxTokens = max($maxTokens, 8000);
        }

        $basePayload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
        ];
        if (! $reasoning) {
            $basePayload['temperature'] = $temperature ?? $profile['temperature'];
        }
        unset($extra['max_tokens'], $extra['model']);
        $basePayload = array_merge($basePayload, $extra);
        $timeout = $profile['timeout_seconds'];

        try {
            $response = $this->postChatWithRetry($url, $profile['api_key'], $basePayload, $jsonMode, $reasoning, $timeout);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Nie można połączyć z API AI: '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            throw new RuntimeException($this->formatHttpError($response));
        }

        $payload = $response->json();
        $content = $this->contentReader->fromPayload($payload);

        // reasoning zjadł budżet tokenów — powtórz z większym limitem
        if ($content === '' && $this->contentReader->finishReason($payload) === 'length' && $maxTokens < 16000) {
            $basePayload['max_tokens'] = 16000;
            try {
                $response = $this->postChat($url, $profile['api_key'], $basePayload, $jsonMode, $reasoning, $timeout);
            } catch (ConnectionException $e) {
                throw new RuntimeException('Nie można połączyć z API AI: '.$e->getMessage(), 0, $e);
            }
            if ($response->successful()) {
                $payload = $response->json();
                $content = $this->contentReader->fromPayload($payload);
            }
        }

        if ($content === '') {
            Log::warning('AI empty content', ['body' => $payload, 'model' => $model]);
            throw new RuntimeException('API AI zwróciło pustą odpowiedź (model reasoning potrzebuje więcej tokenów na wynik JSON).');
        }

        return [
            'content' => $content,
            'model' => (string) data_get($payload, 'model', $profile['model']),
            'usage' => data_get($payload, 'usage'),
        ];
    }

    /**
     * Analiza PDF przez model multimodalny (OpenRouter/Gemini).
     *
     * @return array{content: string, model: string, usage: array<string, mixed>|null}
     */
    public function chatWithPdf(string $prompt, string $pdfPath, string $filename, ?AiTask $task = null): array
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
        ], $task);
    }

    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     * @return array<string, mixed>
     */
    public function chatJson(
        array $messages,
        ?float $temperature = null,
        ?int $maxTokens = null,
        ?string $model = null,
        ?AiTask $task = null
    ): array {
        $extra = [];
        if ($maxTokens !== null) {
            $extra['max_tokens'] = max(256, $maxTokens);
        }
        if ($model !== null && trim($model) !== '') {
            $extra['model'] = trim($model);
        }
        $result = $this->chat($messages, $temperature, true, $extra !== [] ? $extra : null, $task);

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
            ], 0.0, true, $extra !== [] ? $extra : null, $task);

            return $this->jsonParser->parse($repair['content']);
        }
    }

    /**
     * Opisy produktów / filtr chrome. Profil przypisany do zadania jest ważniejszy —
     * dopiero bez niego wraca stare pole „Model do opisów”.
     */
    public function chatJsonEnrichment(array $messages, ?float $temperature = null, ?int $maxTokens = null): array
    {
        $hasProfile = ! $this->settings->profileForTask(AiTask::Enrichment)['is_default'];

        return $this->chatJson(
            $messages,
            $temperature,
            $maxTokens,
            $hasProfile ? null : $this->settings->enrichmentModel(),
            AiTask::Enrichment
        );
    }

    /**
     * Analiza kilku obrazów w jednym wywołaniu modelu multimodalnego.
     *
     * @param  list<array{bytes: string, mime: string, label: string}>  $images
     * @return array<string, mixed>
     */
    public function chatJsonWithImages(string $prompt, array $images, ?AiTask $task = null): array
    {
        $content = [
            ['type' => 'text', 'text' => $prompt],
        ];
        foreach ($images as $index => $image) {
            $content[] = [
                'type' => 'text',
                'text' => sprintf('Kandydat %d: %s', $index, $image['label']),
            ];
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:'.$image['mime'].';base64,'.base64_encode($image['bytes']),
                    'detail' => 'low',
                ],
            ];
        }

        return $this->chatJson([
            [
                'role' => 'system',
                'content' => 'Weryfikujesz zdjęcia produktów BHP. Zwracasz wyłącznie JSON i nie zgadujesz.',
            ],
            [
                'role' => 'user',
                'content' => $content,
            ],
        ], 0.0, 1200, null, $task);
    }

    /**
     * OpenRouter chat/completions + plugin web (nie /responses — to wisi i timeoutuje).
     *
     * @return array{content: string, model: string, citations: list<array{url: string, title: string}>}
     */
    public function chatWithWebSearch(string $prompt, int $timeoutSeconds = 60, ?AiTask $task = null): array
    {
        if (! $this->settings->resolve()['enabled']) {
            throw new RuntimeException('Integracja AI jest wyłączona. Włącz ją w Ustawieniach AI.');
        }
        if ($this->settings->usesFreeWebSearch()) {
            return $this->phpDuckDuckGoCitations($prompt);
        }

        /** @var array{content: string, model: string, citations: list<array{url: string, title: string}>} $result */
        $result = $this->withProfileFallback(
            $task,
            fn (array $profile): array => $this->webSearchWithProfile($profile, $prompt, $timeoutSeconds)
        );

        return $result;
    }

    /**
     * @param  array{label: string, base_url: string, api_key: ?string, model: string, timeout_seconds: int, temperature: float, is_default: bool}  $profile
     * @return array{content: string, model: string, citations: list<array{url: string, title: string}>}
     */
    private function webSearchWithProfile(array $profile, string $prompt, int $timeoutSeconds): array
    {
        if ($profile['api_key'] === null || $profile['api_key'] === '') {
            throw new RuntimeException('Brak klucza API AI. Uzupełnij go w Ustawieniach AI.');
        }

        $url = $profile['base_url'].'/chat/completions';
        $payload = [
            'model' => $profile['model'],
            'temperature' => 0.0,
            'max_tokens' => 800,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Szukasz karty produktu BHP. Wypisz tylko prawdziwe URL-e (http), po jednym w linii. Nic nie zgaduj.',
                ],
                [
                    'role' => 'user',
                    'content' => $prompt,
                ],
            ],
            'plugins' => [
                [
                    'id' => 'web',
                    'max_results' => 3,
                ],
            ],
        ];

        try {
            $response = Http::withToken($profile['api_key'])
                ->acceptJson()
                ->withHeaders([
                    'HTTP-Referer' => config('app.url', 'http://localhost'),
                    'X-Title' => 'SUPON AI',
                ])
                ->timeout(max(30, $timeoutSeconds))
                ->connectTimeout(15)
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Nie można połączyć z API AI (web search): '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $body = $response->json();
            $detail = is_array($body)
                ? (string) data_get($body, 'error.message', $response->body())
                : $response->body();
            throw new RuntimeException('Web search AI HTTP '.$response->status().': '.$detail);
        }

        $data = $response->json();
        $content = $this->contentReader->fromPayload($data);
        $citations = $this->extractChatWebCitations($data);
        if ($content === '' && $citations === []) {
            throw new RuntimeException('Web search AI zwróciło pustą odpowiedź.');
        }

        return [
            'content' => $content,
            'model' => (string) data_get($data, 'model', $profile['model']),
            'citations' => $citations,
        ];
    }

    /**
     * OpenAI Responses API z narzędziem web_search (gdy provider wspiera).
     *
     * @return array{content: string, model: string, citations: list<array{url: string, title: string}>}
     */
    public function responsesWithWebSearch(string $prompt, int $timeoutSeconds = 25, ?AiTask $task = null): array
    {
        if (! $this->settings->resolve()['enabled']) {
            throw new RuntimeException('Integracja AI jest wyłączona. Włącz ją w Ustawieniach AI.');
        }

        /** @var array{content: string, model: string, citations: list<array{url: string, title: string}>} $result */
        $result = $this->withProfileFallback(
            $task,
            fn (array $profile): array => $this->responsesWithProfile($profile, $prompt, $timeoutSeconds)
        );

        return $result;
    }

    /**
     * @param  array{label: string, base_url: string, api_key: ?string, model: string, timeout_seconds: int, temperature: float, is_default: bool}  $profile
     * @return array{content: string, model: string, citations: list<array{url: string, title: string}>}
     */
    private function responsesWithProfile(array $profile, string $prompt, int $timeoutSeconds): array
    {
        if ($profile['api_key'] === null || $profile['api_key'] === '') {
            throw new RuntimeException('Brak klucza API AI. Uzupełnij go w Ustawieniach AI.');
        }

        $url = $profile['base_url'].'/responses';
        $payload = [
            'model' => $profile['model'],
            'tools' => [
                ['type' => 'web_search'],
            ],
            'input' => $prompt,
            // OpenRouter: domyślne 65k tokenów często kończy się HTTP 402 przy niskim saldzie
            'max_output_tokens' => 1500,
        ];

        try {
            $response = Http::withToken($profile['api_key'])
                ->acceptJson()
                ->withHeaders([
                    'HTTP-Referer' => config('app.url', 'http://localhost'),
                    'X-Title' => 'SUPON AI',
                ])
                ->timeout(max(10, $timeoutSeconds))
                ->connectTimeout(10)
                ->post($url, $payload);
        } catch (ConnectionException $e) {
            throw new RuntimeException('Nie można połączyć z API AI (web search): '.$e->getMessage(), 0, $e);
        }

        if (! $response->successful()) {
            $body = $response->json();
            $detail = is_array($body)
                ? (string) data_get($body, 'error.message', $response->body())
                : $response->body();
            throw new RuntimeException('Web search AI HTTP '.$response->status().': '.$detail);
        }

        $data = $response->json();
        $content = $this->extractResponsesContent($data);
        if ($content === '') {
            throw new RuntimeException('Web search AI zwróciło pustą odpowiedź.');
        }

        return [
            'content' => $content,
            'model' => (string) data_get($data, 'model', $profile['model']),
            'citations' => $this->extractResponsesCitations($data),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function extractResponsesContent(mixed $payload): string
    {
        $outputText = data_get($payload, 'output_text');
        if (is_string($outputText) && trim($outputText) !== '') {
            return trim($outputText);
        }

        $parts = [];
        $output = data_get($payload, 'output', []);
        if (! is_array($output)) {
            return '';
        }

        foreach ($output as $item) {
            if (! is_array($item)) {
                continue;
            }
            $content = $item['content'] ?? null;
            if (! is_array($content)) {
                continue;
            }
            foreach ($content as $block) {
                if (! is_array($block)) {
                    continue;
                }
                $text = $block['text'] ?? null;
                if (is_string($text) && $text !== '') {
                    $parts[] = $text;
                }
            }
        }

        return trim(implode("\n", $parts));
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return list<array{url: string, title: string}>
     */
    private function extractResponsesCitations(mixed $payload): array
    {
        $citations = [];
        $output = data_get($payload, 'output', []);
        if (! is_array($output)) {
            return [];
        }

        foreach ($output as $item) {
            if (! is_array($item)) {
                continue;
            }
            $content = $item['content'] ?? null;
            if (! is_array($content)) {
                continue;
            }
            foreach ($content as $block) {
                if (! is_array($block)) {
                    continue;
                }
                $annotations = $block['annotations'] ?? [];
                if (! is_array($annotations)) {
                    continue;
                }
                foreach ($annotations as $annotation) {
                    if (! is_array($annotation)) {
                        continue;
                    }
                    $url = (string) ($annotation['url'] ?? '');
                    if ($url === '') {
                        continue;
                    }
                    $citations[] = [
                        'url' => $url,
                        'title' => (string) ($annotation['title'] ?? $url),
                    ];
                }
            }
        }

        return $citations;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return list<array{url: string, title: string}>
     */
    private function extractChatWebCitations(mixed $payload): array
    {
        $citations = [];
        $annotations = data_get($payload, 'choices.0.message.annotations', []);
        if (! is_array($annotations)) {
            $annotations = [];
        }
        $extra = data_get($payload, 'choices.0.message.citations', []);
        if (is_array($extra)) {
            $annotations = array_merge($annotations, $extra);
        }

        foreach ($annotations as $annotation) {
            if (! is_array($annotation)) {
                continue;
            }
            $nested = $annotation['url_citation'] ?? null;
            if (is_array($nested)) {
                $url = (string) ($nested['url'] ?? '');
                $title = (string) ($nested['title'] ?? $url);
            } else {
                $url = (string) ($annotation['url'] ?? '');
                $title = (string) ($annotation['title'] ?? $url);
            }
            if ($url === '' || ! str_starts_with($url, 'http')) {
                continue;
            }
            $citations[] = [
                'url' => $url,
                'title' => $title !== '' ? $title : $url,
            ];
        }

        return $citations;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postChatWithRetry(
        string $url,
        string $apiKey,
        array $payload,
        bool $jsonMode,
        bool $reasoning,
        int $timeout
    ): Response {
        $response = $this->postChat($url, $apiKey, $payload, $jsonMode, $reasoning, $timeout);
        $attempt = 0;
        while (in_array($response->status(), self::OVERLOAD_STATUSES, true) && $attempt < self::OVERLOAD_RETRIES) {
            $wait = $this->retryAfterSeconds($response, $attempt);
            if ($wait > 0) {
                sleep($wait);
            }
            $response = $this->postChat($url, $apiKey, $payload, $jsonMode, $reasoning, $timeout);
            $attempt++;
        }

        return $response;
    }

    private function retryAfterSeconds(Response $response, int $attempt): int
    {
        if (app()->environment('testing')) {
            return 0;
        }
        $header = $response->header('Retry-After');
        if (is_numeric($header)) {
            return min(60, max(1, (int) $header));
        }

        // Jitter jest tu istotny: bez niego wszystkie równoległe żądania wracają
        // w tej samej chwili i znowu przepełniają kolejkę slotów modelu.
        $base = [3, 8, 15, 25, 40][$attempt] ?? 40;

        return $base + random_int(0, intdiv($base, 2));
    }

    private function formatHttpError(Response $response): string
    {
        $status = $response->status();
        $body = $response->json();
        $detail = is_array($body)
            ? (string) data_get($body, 'error.message', $response->body())
            : $response->body();
        if ($status === 429) {
            return 'Limit zapytań modelu AI (HTTP 429). To OpenRouter/dostawca modelu, nie Tavily. '
                .'Poczekaj ok. minutę i ponów opis produktu.';
        }

        return 'API AI zwróciło błąd HTTP '.$status.': '.$detail;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postChat(
        string $url,
        string $apiKey,
        array $payload,
        bool $jsonMode,
        bool $reasoning,
        int $timeout
    ): Response {
        $attempts = [];
        if ($jsonMode) {
            $attempts[] = $payload + ['response_format' => ['type' => 'json_object']];
        }
        $attempts[] = $payload;

        $response = null;
        foreach ($attempts as $body) {
            $response = $this->post($url, $apiKey, $body, $timeout);
            if (! in_array($response->status(), [400, 404, 422], true)) {
                return $response;
            }
        }

        // GPT-5 / o-series: max_tokens bywa odrzucane — spróbuj max_completion_tokens
        if ($reasoning && $response !== null && in_array($response->status(), [400, 422], true)) {
            $alt = $payload;
            $alt['max_completion_tokens'] = (int) ($payload['max_tokens'] ?? 8000);
            unset($alt['max_tokens']);
            $retry = $jsonMode ? ($alt + ['response_format' => ['type' => 'json_object']]) : $alt;
            $response = $this->post($url, $apiKey, $retry, $timeout);
            if ($response->successful() || ! $jsonMode) {
                return $response;
            }
            $response = $this->post($url, $apiKey, $alt, $timeout);
        }

        return $response ?? $this->post($url, $apiKey, $payload, $timeout);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function post(string $url, string $apiKey, array $payload, int $timeoutSeconds): Response
    {
        $timeout = max(120, $timeoutSeconds);
        if ($this->isReasoningModel((string) ($payload['model'] ?? ''))) {
            $timeout = max(180, $timeout);
        }

        return Http::withToken($apiKey)
            ->acceptJson()
            ->withHeaders([
                'HTTP-Referer' => config('app.url', 'http://localhost'),
                'X-Title' => 'SUPON AI',
            ])
            ->timeout($timeout)
            ->post($url, $payload);
    }

    /**
     * @return array{content: string, model: string, citations: list<array{url: string, title: string}>}
     */
    private function phpDuckDuckGoCitations(string $prompt): array
    {
        $query = $prompt;
        if (preg_match('/Zapytanie:\s*(.+)$/mu', $prompt, $m) === 1) {
            $query = trim($m[1]);
        }
        $hits = $this->duckDuckGo->search($query, 5);
        if ($hits === []) {
            throw new RuntimeException('DuckDuckGo nie zwróciło URL-i.');
        }

        $citations = [];
        $lines = [];
        foreach ($hits as $hit) {
            $citations[] = ['url' => $hit['url'], 'title' => $hit['title']];
            $lines[] = $hit['url'];
        }

        return [
            'content' => implode("\n", $lines),
            'model' => 'duckduckgo',
            'citations' => $citations,
        ];
    }

    private function isReasoningModel(string $model): bool
    {
        return preg_match('/gpt-5|o1|o3|o4|tera|reasoning|r1|think/i', $model) === 1;
    }
}
