<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Enrichment\DuckDuckGoHtmlSearch;
use App\Services\Enrichment\EnrichmentLiveProgress;
use App\Support\QueueWorkerIdentity;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class OpenAiCompatibleClient
{
    /** Model przeciążony / limit zapytań albo urwane 100 Continue — warto poczekać i ponowić. */
    private const OVERLOAD_STATUSES = [100, 429, 503, 529];

    private const OVERLOAD_RETRIES = 5;

    /**
     * Limit zapytań u dostawcy nie mija od czekania tak jak chwilowe przeciążenie —
     * pięć podejść z narastającą przerwą blokowało wzbogacanie na ponad dwie minuty.
     */
    private const RATE_LIMIT_RETRIES = 2;

    /** Domyślny budżet odpowiedzi. vLLM odrzuca prompt + max_tokens > --max-model-len. */
    private const DEFAULT_MAX_TOKENS = 6000;

    /** Lokalny vLLM (Qwen) — --max-model-len. */
    private const LOCAL_MAX_MODEL_LEN = 16128;

    /** Sufit dla OpenRouter / OpenAI, gdy brak AI_MAX_MODEL_LEN. */
    private const CLOUD_MAX_MODEL_LEN = 128000;

    private const MIN_OUTPUT_TOKENS = 512;

    private const SLOT_RESERVE = 768;

    /** Zostaw zapas na chat template Qwen — inaczej pierwszy request dostaje 400. */
    private const LOCAL_FIT_RATIO = 0.82;

    /** Polski + JSON + szablon: Qwen zjada ~1.8 znaku/token, nie 4. */
    private const PROMPT_CHARS_PER_TOKEN = 1.8;

    private const TOKEN_ESTIMATE_PADDING = 256;

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
     * @return array{content: string, model: string, usage: array<string, mixed>|null, finish_reason: string, provider: ?string}
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
            fn (array $profile): array => $this->chatWithProfile($this->withProviderPinPolicy($profile, $task), $messages, $temperature, $jsonMode, $extra)
        );
    }

    /**
     * Ping konfiguracji głównej i każdego profilu — bez fallbacku między nimi.
     *
     * @return list<array{id: string, label: string, ok: bool, message: string}>
     */
    public function testConnections(): array
    {
        $out = [];
        foreach ($this->settings->resolvedProfiles() as $index => $profile) {
            $label = $profile['is_default'] ? 'Model główny' : (string) $profile['label'];
            $id = $profile['is_default'] ? 'main' : 'profile-'.$index;
            try {
                $raw = $this->chatWithProfile(
                    $profile,
                    [
                        [
                            'role' => 'system',
                            'content' => 'Odpowiedz wyłącznie JSON: {"ok":true,"message":"pong"}',
                        ],
                        [
                            'role' => 'user',
                            'content' => 'Ping test połączenia z API modelu AI.',
                        ],
                    ],
                    0.0,
                    true,
                    ['max_tokens' => 256]
                );
                $model = trim((string) ($raw['model'] ?? $profile['model']));
                $out[] = [
                    'id' => $id,
                    'label' => $label,
                    'ok' => true,
                    'message' => $model !== '' ? 'połączono ('.$model.')' : 'połączono',
                ];
            } catch (Throwable $e) {
                $out[] = [
                    'id' => $id,
                    'label' => $label,
                    'ok' => false,
                    'message' => $this->summarizePingError($e->getMessage()),
                ];
            }
        }

        return $out;
    }

    private function summarizePingError(string $msg): string
    {
        if (str_contains($msg, 'Could not resolve host') || str_contains($msg, 'cURL error 6')) {
            return 'brak połączenia (host nieosiągalny)';
        }
        if (str_contains($msg, 'cURL error 7')
            || str_contains($msg, 'Connection refused')
            || str_contains($msg, 'Failed to connect')) {
            return 'brak połączenia (odmowa połączenia)';
        }
        if (str_contains($msg, 'cURL error 28') || str_contains($msg, 'timed out')) {
            return 'brak połączenia (timeout)';
        }

        return mb_strlen($msg) > 180 ? mb_substr($msg, 0, 177).'…' : $msg;
    }

    /**
     * Równoległe chat/completions (curl_multi) — tyle requestów, ile caller trzyma slotów.
     *
     * @param  list<list<array{role: string, content: mixed}>>  $messageSets
     * @return list<array{ok: bool, content?: string, model?: string, error?: string}>
     */
    public function chatMany(
        array $messageSets,
        bool $jsonMode = true,
        ?array $extra = null,
        ?AiTask $task = null,
        ?callable $onAnswered = null,
    ): array {
        if ($messageSets === []) {
            return [];
        }
        if (count($messageSets) === 1) {
            try {
                $raw = $this->chat($messageSets[0], null, $jsonMode, $extra, $task);

                return [['ok' => true, 'content' => $raw['content'], 'model' => $raw['model'], 'provider' => $raw['provider'] ?? null, 'finish_reason' => $raw['finish_reason'] ?? null]];
            } catch (RuntimeException $e) {
                return [['ok' => false, 'error' => $e->getMessage()]];
            }
        }

        $profile = $this->withProviderPinPolicy($this->settings->profileForTask($task), $task);
        try {
            return $this->chatManyWithProfile($profile, $messageSets, $jsonMode, $extra, $onAnswered);
        } catch (RuntimeException $e) {
            if ($profile['is_default']) {
                $failed = [];
                foreach ($messageSets as $_) {
                    $failed[] = ['ok' => false, 'error' => $e->getMessage()];
                }

                return $failed;
            }

            return $this->runOnMainOrRethrow(
                $e,
                fn (array $main): array => $this->chatManyWithProfile($this->withProviderPinPolicy($main, $task), $messageSets, $jsonMode, $extra, $onAnswered),
                $task,
                $profile['label']
            );
        }
    }

    /**
     * Równoległe chat/completions w paczkach — max $maxConcurrent połączeń naraz.
     *
     * @param  list<list<array{role: string, content: mixed}>>  $messageSets
     * @return list<array<string, mixed>>
     */
    public function chatJsonMany(
        array $messageSets,
        ?int $maxTokens = null,
        ?AiTask $task = null,
        int $maxConcurrent = 10,
        ?callable $onProgress = null,
    ): array {
        if ($messageSets === []) {
            return [];
        }
        $extra = [];
        if ($maxTokens !== null) {
            $extra['max_tokens'] = max(256, $maxTokens);
        }
        $maxConcurrent = max(1, min(AiSettingsService::CONCURRENCY_MAX, $maxConcurrent));
        $parsed = [];
        $providers = [];
        // $onProgress(odpowiedzi, wszystkie) — okno „Trwa dopasowanie” liczy każdą odpowiedź modelu,
        // nie całą paczkę naraz. Tylko w górę: ponowienie na profilu głównym nie cofa licznika.
        $total = count($messageSets);
        $reported = 0;
        $report = static function (int $count) use ($onProgress, $total, &$reported): void {
            $count = min($count, $total);
            if ($onProgress !== null && $count > $reported) {
                $reported = $count;
                $onProgress($count, $total);
            }
        };
        foreach (array_chunk($messageSets, $maxConcurrent) as $chunk) {
            $chunkStart = count($parsed);
            $chunkSize = count($chunk);
            $inChunk = 0;
            $onAnswered = $onProgress === null ? null : static function () use (&$inChunk, $chunkStart, $chunkSize, $report): void {
                $inChunk++;
                $report($chunkStart + min($inChunk, $chunkSize));
            };
            foreach ($this->chatMany($chunk, true, $extra !== [] ? $extra : null, $task, $onAnswered) as $row) {
                if (! ($row['ok'] ?? false)) {
                    Log::warning('AI chatJsonMany failed', [
                        'task' => $task?->value,
                        'error' => $row['error'] ?? 'unknown',
                    ]);
                    $parsed[] = [];
                    $providers[] = null;

                    continue;
                }
                $content = (string) ($row['content'] ?? '');
                $json = $this->tryParseJson($content);
                if ($json === null) {
                    // Pusta tablica niżej znaczy dla wołającego „model nie odpowiedział” — bez tego wpisu przetarg
                    // pokazywał „model nie odpowiedział” przy czystym logu (21.09.2026, lokalny Qwen na profilu).
                    Log::warning('AI chatJsonMany: odpowiedź bez poprawnego JSON', [
                        'task' => $task?->value,
                        'model' => $row['model'] ?? null,
                        'finish_reason' => $row['finish_reason'] ?? null,
                        'length' => mb_strlen($content),
                        'head' => mb_substr($content, 0, 300),
                        'tail' => mb_strlen($content) > 300 ? mb_substr($content, -200) : null,
                    ]);
                }
                $parsed[] = $json ?? [];
                $providers[] = is_string($row['provider'] ?? null) ? $row['provider'] : null;
            }
            // pojedyncze zapytanie idzie bez puli, a ponowienia mogą nie wywołać licznika — wyrównanie
            $report(count($parsed));
        }
        app(AiServedProviderTally::class)->recordBatch($providers);

        return $parsed;
    }

    /**
     * Fallback na model główny przy 402/429 — ale nie gdy lokalny endpoint nie odpowiada,
     * bo wtedy użytkownik widzi cURL do trycloudflare zamiast błędu profilu.
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

            return $this->runOnMainOrRethrow($e, $run, $task, $profile['label']);
        }
    }

    /**
     * @template T
     *
     * @param  callable(array<string, mixed>): T  $run
     * @return T
     */
    private function runOnMainOrRethrow(
        RuntimeException $profileError,
        callable $run,
        ?AiTask $task,
        string $profileLabel
    ): mixed {
        $main = $this->settings->profileForTask(null);
        if ($this->isUnreachableBaseUrl($main['base_url'])) {
            Log::warning('Profil AI zawiódł — konfiguracja główna nieosiągalna, zostawiam błąd profilu', [
                'task' => $task?->value,
                'profile' => $profileLabel,
                'error' => $profileError->getMessage(),
                'main_url' => $main['base_url'],
            ]);

            throw $profileError;
        }

        Log::warning('Profil AI zawiódł — schodzę na konfigurację główną', [
            'task' => $task?->value,
            'profile' => $profileLabel,
            'error' => $profileError->getMessage(),
        ]);

        try {
            return $run($main);
        } catch (RuntimeException $fallbackError) {
            if ($this->isUnreachableEndpointError($fallbackError)) {
                Log::warning('Profil AI zawiódł — konfiguracja główna też nie odpowiada, zostawiam błąd profilu', [
                    'task' => $task?->value,
                    'profile' => $profileLabel,
                    'profile_error' => $profileError->getMessage(),
                    'main_error' => $fallbackError->getMessage(),
                ]);

                throw $profileError;
            }

            throw $fallbackError;
        }
    }

    private function isUnreachableBaseUrl(string $baseUrl): bool
    {
        $host = parse_url($baseUrl, PHP_URL_HOST);

        return ! is_string($host)
            || $host === ''
            || (! filter_var($host, FILTER_VALIDATE_IP) && gethostbyname($host) === $host);
    }

    private function isUnreachableEndpointError(Throwable $e): bool
    {
        if ($e->getPrevious() instanceof ConnectionException) {
            return true;
        }
        $msg = $e->getMessage();

        return str_contains($msg, 'Nie można połączyć z API AI')
            || str_contains($msg, 'Could not resolve host')
            || str_contains($msg, 'cURL error 6')
            || str_contains($msg, 'cURL error 7')
            || str_contains($msg, 'cURL error 28')
            || str_contains($msg, 'Connection refused')
            || str_contains($msg, 'Failed to connect')
            || str_contains($msg, 'Operation timed out');
    }

    /**
     * @param  array{label: string, base_url: string, api_key: ?string, model: string, timeout_seconds: int, temperature: float, reasoning_effort: string, is_default: bool}  $profile
     * @param  list<list<array{role: string, content: mixed}>>  $messageSets
     * @return list<array{ok: bool, content?: string, model?: string, error?: string}>
     */
    private function chatManyWithProfile(
        array $profile,
        array $messageSets,
        bool $jsonMode,
        ?array $extra,
        ?callable $onAnswered = null,
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
        $apiKey = $profile['api_key'];
        // Krok pomocniczy (filtr stron) dostawał sztywno co najmniej 4 minuty, także gdy
        // profil mówił mniej — jeden zawieszony request wstrzymywał cały produkt.
        $timeout = max(60, $profile['timeout_seconds']);
        $bodies = [];
        foreach ($messageSets as $messages) {
            $bodies[] = $this->buildChatPayload($profile, $messages, null, $extra, $jsonMode);
        }

        $responses = $this->postChatPool($url, $apiKey, $timeout, $bodies, $onAnswered);
        $responses = $this->retryChatManyOverloaded($url, $apiKey, $timeout, $profile, $bodies, $responses);
        $responses = $this->retryChatManyRejected($url, $apiKey, $timeout, $profile, $bodies, $responses);
        $responses = $this->retryChatManyEmptyTruncated($url, $apiKey, $timeout, $profile, $bodies, $responses);

        $out = [];
        foreach ($messageSets as $i => $_) {
            $out[] = $this->chatManyItemFromResponse($responses[$i] ?? null, $profile);
        }

        return $out;
    }

    /**
     * @param  array<int, array<string, mixed>>  $bodies
     * @return array<int, mixed>
     */
    private function postChatPool(string $url, string $apiKey, int $timeout, array $bodies, ?callable $onAnswered = null): array
    {
        $responses = Http::pool(function (Pool $pool) use ($bodies, $url, $apiKey, $timeout, $onAnswered) {
            foreach ($bodies as $i => $body) {
                $req = $pool->as((string) $i)
                    ->acceptJson()
                    ->withHeaders([
                        'HTTP-Referer' => config('app.url', 'http://localhost'),
                        'X-Title' => 'SUPON AI',
                        'User-Agent' => QueueWorkerIdentity::userAgent('SUPON-AI/1.0'),
                        'Expect' => '',
                    ])
                    ->withOptions(['expect' => false])
                    ->timeout($timeout)
                    ->connectTimeout(15);
                if ($apiKey !== '') {
                    $req = $req->withToken($apiKey);
                }
                $promise = $req->post($url, $body);
                if ($onAnswered !== null) {
                    // Guzzle woła to w pętli curl, gdy wróci TA odpowiedź (także błąd) — nie po całej puli.
                    // Osobny łańcuch: wynik czekany przez Http::pool zostaje nietknięty.
                    $promise->then(
                        static function (mixed $response) use ($onAnswered): mixed {
                            $onAnswered();

                            return $response;
                        },
                        static function (mixed $reason) use ($onAnswered): null {
                            $onAnswered();

                            return null;
                        },
                    );
                }
            }
        });

        $out = [];
        foreach ($bodies as $i => $_) {
            $out[$i] = $responses[(string) $i] ?? $responses[$i] ?? null;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<int, array<string, mixed>>  $bodies
     * @param  array<int, mixed>  $responses
     * @return array<int, mixed>
     */
    private function retryChatManyRejected(
        string $url,
        string $apiKey,
        int $timeout,
        array $profile,
        array $bodies,
        array $responses
    ): array {
        $retryBodies = [];
        foreach ($bodies as $i => $body) {
            $response = $responses[$i] ?? null;
            if (! $response instanceof Response || ! in_array($response->status(), [400, 413, 422], true)) {
                continue;
            }
            $overflow = $this->isContextOverflow($response);
            $retry = $body;
            if ($overflow) {
                $messages = is_array($body['messages'] ?? null) ? $body['messages'] : [];
                $compacted = $this->shrinkMessagesToFit(
                    $this->compactMessages($messages, 0.65),
                    self::MIN_OUTPUT_TOKENS,
                    $profile
                );
                $retry['messages'] = $compacted;
                $retry['max_tokens'] = $this->fitMaxTokens(
                    max(self::MIN_OUTPUT_TOKENS, (int) floor(((int) ($body['max_tokens'] ?? self::MIN_OUTPUT_TOKENS)) * 0.7)),
                    $compacted,
                    $profile
                );
            }
            unset($retry['response_format'], $retry['chat_template_kwargs'], $retry['reasoning_effort'], $retry['reasoning']);
            $retryBodies[$i] = $retry;
            Log::warning('AI chatMany HTTP '.$response->status().' — ponawiam', [
                'overflow' => $overflow,
                'error' => (string) (data_get($response->json(), 'error.message') ?? $response->body()),
                'profile' => $profile['label'] ?? '',
            ]);
        }
        if ($retryBodies === []) {
            return $responses;
        }

        foreach ($this->postChatPool($url, $apiKey, $timeout, $retryBodies) as $i => $response) {
            $responses[$i] = $response;
        }

        return $responses;
    }

    /**
     * HTTP 429/503 w puli (log produkcji 15:13–15:14: „Limit zapytań modelu AI (HTTP 429)” przy
     * poz. 1, 8 i 13 przetargu 1) — jak postChatWithRetry dla pojedynczego zapytania: odczekaj
     * i ponów tylko odrzucone zapytania, 429 najwyżej RATE_LIMIT_RETRIES razy; od drugiej powtórki
     * przypięty dostawca OpenRoutera dopuszcza innych dostawców modelu. Pula nie zwalnia.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<int, array<string, mixed>>  $bodies
     * @param  array<int, mixed>  $responses
     * @return array<int, mixed>
     */
    private function retryChatManyOverloaded(
        string $url,
        string $apiKey,
        int $timeout,
        array $profile,
        array $bodies,
        array $responses
    ): array {
        $rateLimited = 0;
        for ($attempt = 0; $attempt < self::OVERLOAD_RETRIES; $attempt++) {
            $retryBodies = [];
            $wait = 0;
            $saw429 = false;
            foreach ($bodies as $i => $body) {
                $response = $responses[$i] ?? null;
                if (! $response instanceof Response || ! in_array($response->status(), self::OVERLOAD_STATUSES, true)) {
                    continue;
                }
                $saw429 = $saw429 || $response->status() === 429;
                $wait = max($wait, $this->retryAfterSeconds($response, $attempt));
                $retryBodies[$i] = $attempt >= 1 && ! ($profile['keep_provider_pin'] ?? false)
                    ? $this->relaxOverloadedProviderPin($body)
                    : $body;
            }
            if ($retryBodies === []) {
                return $responses;
            }
            if ($saw429 && ++$rateLimited > self::RATE_LIMIT_RETRIES) {
                return $responses;
            }
            Log::info('AI chatMany: limit/przeciążenie dostawcy — ponawiam odrzucone zapytania', [
                'count' => count($retryBodies),
                'attempt' => $attempt + 1,
                'wait_seconds' => $wait,
                'profile' => $profile['label'] ?? '',
                'keep_provider_pin' => (bool) ($profile['keep_provider_pin'] ?? false),
            ]);
            if ($wait > 0) {
                sleep($wait);
            }
            foreach ($this->postChatPool($url, $apiKey, $timeout, $retryBodies) as $i => $response) {
                $responses[$i] = $response;
                $bodies[$i] = $retryBodies[$i];
            }
        }

        return $responses;
    }

    /**
     * Model z trybem myślenia zjadł limit tokenów i oddał pustą treść (finish_reason=length) — jak
     * pojedyncze zapytanie w chat(): jedno ponowienie z limitem DEFAULT_MAX_TOKENS, o ile kontekst
     * ma miejsce. Wcześniej zapytania równoległe od razu zgłaszały „pustą odpowiedź”.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<int, array<string, mixed>>  $bodies
     * @param  array<int, mixed>  $responses
     * @return array<int, mixed>
     */
    private function retryChatManyEmptyTruncated(
        string $url,
        string $apiKey,
        int $timeout,
        array $profile,
        array $bodies,
        array $responses
    ): array {
        $retryBodies = [];
        foreach ($bodies as $i => $body) {
            $response = $responses[$i] ?? null;
            if (! $response instanceof Response || ! $response->successful()) {
                continue;
            }
            $payload = $response->json();
            if ($this->contentReader->fromPayload(is_array($payload) ? $payload : []) !== ''
                || $this->contentReader->finishReason($payload) !== 'length') {
                continue;
            }
            $messages = is_array($body['messages'] ?? null) ? $body['messages'] : [];
            $bumped = $this->fitMaxTokens(self::DEFAULT_MAX_TOKENS, $messages, $profile);
            if ($bumped <= (int) ($body['max_tokens'] ?? 0)) {
                continue;
            }
            $body['max_tokens'] = $bumped;
            $retryBodies[$i] = $body;
        }
        if ($retryBodies === []) {
            return $responses;
        }

        Log::info('AI chatMany: pusta odpowiedź ucięta limitem tokenów — ponawiam z większym limitem', [
            'count' => count($retryBodies),
            'max_tokens' => max(array_map(static fn (array $b): int => (int) ($b['max_tokens'] ?? 0), $retryBodies)),
            'profile' => $profile['label'] ?? '',
        ]);
        foreach ($this->postChatPool($url, $apiKey, $timeout, $retryBodies) as $i => $response) {
            $responses[$i] = $response;
        }

        return $responses;
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array{ok: bool, content?: string, model?: string, provider?: string|null, finish_reason?: string, error?: string}
     */
    private function chatManyItemFromResponse(mixed $response, array $profile): array
    {
        if ($response instanceof Throwable) {
            return ['ok' => false, 'error' => $response->getMessage()];
        }
        if (! $response instanceof Response || ! $response->successful()) {
            return ['ok' => false, 'error' => $response instanceof Response
                ? $this->formatHttpError($response, $profile)
                : 'Brak odpowiedzi AI'];
        }
        $payload = $response->json();
        $content = $this->contentReader->fromPayload(is_array($payload) ? $payload : []);
        if ($content === '') {
            // log produkcji mówił tylko „pustą odpowiedź” — bez powodu nie dało się odróżnić
            // ucięcia limitem (myślenie modelu) od odmowy czy błędu dostawcy
            $finish = $this->contentReader->finishReason($payload);
            $completion = data_get($payload, 'usage.completion_tokens');

            return ['ok' => false, 'error' => sprintf(
                'API AI zwróciło pustą odpowiedź (finish_reason=%s, completion_tokens=%s, model=%s).',
                $finish !== '' ? $finish : 'brak',
                is_numeric($completion) ? (string) $completion : 'brak',
                (string) data_get($payload, 'model', $profile['model'] ?? ''),
            )];
        }

        app(AiServedProviderTally::class)->served($payload);

        return [
            'ok' => true,
            'content' => $content,
            'model' => (string) data_get($payload, 'model', $profile['model'] ?? ''),
            'provider' => is_string(data_get($payload, 'provider')) ? (string) data_get($payload, 'provider') : null,
            'finish_reason' => $this->contentReader->finishReason($payload),
        ];
    }

    /**
     * @param  array{label: string, base_url: string, api_key: ?string, model: string, timeout_seconds: int, temperature: float, reasoning_effort: string, is_default: bool}  $profile
     * @param  list<array{role: string, content: mixed}>  $messages
     * @return array<string, mixed>
     */
    private function buildChatPayload(
        array $profile,
        array $messages,
        ?float $temperature,
        ?array $extra,
        bool $jsonMode
    ): array {
        $extra = is_array($extra) ? $extra : [];
        $model = is_string($extra['model'] ?? null) && trim((string) $extra['model']) !== ''
            ? trim((string) $extra['model'])
            : $profile['model'];
        $reasoning = $this->isReasoningModel($model);
        $maxTokens = (int) ($extra['max_tokens'] ?? self::DEFAULT_MAX_TOKENS);
        if ($reasoning) {
            $maxTokens = max($maxTokens, self::DEFAULT_MAX_TOKENS);
        }
        [$messages, $maxTokens] = $this->fitChatRequest($messages, $maxTokens, $profile);
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
        ];
        if (! $reasoning) {
            $payload['temperature'] = $temperature ?? $profile['temperature'];
        }
        unset($extra['max_tokens'], $extra['model']);
        $payload = array_merge($payload, $extra);
        $payload = $this->applyReasoningEffort(
            $payload,
            (string) ($profile['reasoning_effort'] ?? ReasoningEffort::AUTO)
        );
        if ($jsonMode) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        return $this->applyOpenRouterProvider($payload, $profile);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{base_url?: string, openrouter_provider?: ?string}  $profile
     * @return array<string, mixed>
     */
    private function applyOpenRouterProvider(array $payload, array $profile): array
    {
        $slug = trim((string) ($profile['openrouter_provider'] ?? ''));
        if ($slug === '') {
            return $payload;
        }
        $base = mb_strtolower((string) ($profile['base_url'] ?? ''));
        if (! str_contains($base, 'openrouter.ai')) {
            return $payload;
        }

        $payload['provider'] = [
            'only' => [$slug],
            'allow_fallbacks' => false,
        ];

        return $payload;
    }

    /**
     * @param  array{label: string, base_url: string, api_key: ?string, model: string, timeout_seconds: int, temperature: float, reasoning_effort: string, is_default: bool}  $profile
     * @param  list<array{role: string, content: mixed}>  $messages
     * @return array{content: string, model: string, usage: array<string, mixed>|null, finish_reason: string, provider: ?string}
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
        // slotów lokalnego modelu w tym samym VRAM. Przy urwaniu ponawiamy do DEFAULT_MAX_TOKENS.
        $maxTokens = (int) ($extra['max_tokens'] ?? self::DEFAULT_MAX_TOKENS);
        if ($reasoning) {
            $maxTokens = max($maxTokens, self::DEFAULT_MAX_TOKENS);
        }
        [$messages, $maxTokens] = $this->fitChatRequest($messages, $maxTokens, $profile);

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
        $basePayload = $this->applyReasoningEffort(
            $basePayload,
            (string) ($profile['reasoning_effort'] ?? ReasoningEffort::AUTO)
        );
        $basePayload = $this->applyOpenRouterProvider($basePayload, $profile);
        $timeout = $profile['timeout_seconds'];

        $started = microtime(true);
        $usage = null;
        $resultModel = $model;
        $this->reportLiveWaiting($profile, $model);
        try {
            try {
                $response = $this->postChatWithRetry($url, $profile['api_key'], $basePayload, $jsonMode, $reasoning, $timeout, ! ($profile['keep_provider_pin'] ?? false));
            } catch (ConnectionException $e) {
                throw new RuntimeException('Nie można połączyć z API AI: '.$e->getMessage(), 0, $e);
            }

            if (! $response->successful() && $this->isContextOverflow($response)) {
                $compacted = $this->shrinkMessagesToFit(
                    $this->compactMessages($messages, 0.65),
                    self::MIN_OUTPUT_TOKENS,
                    $profile
                );
                $maxTokens = $this->fitMaxTokens(
                    max(self::MIN_OUTPUT_TOKENS, (int) floor($maxTokens * 0.7)),
                    $compacted,
                    $profile
                );
                $basePayload['messages'] = $compacted;
                $basePayload['max_tokens'] = $maxTokens;
                Log::info('AI przepełniony kontekst slota — ponawiam ze skróconymi źródłami', [
                    'model' => $model,
                    'max_tokens' => $maxTokens,
                ]);
                try {
                    $response = $this->postChatWithRetry($url, $profile['api_key'], $basePayload, $jsonMode, $reasoning, $timeout, ! ($profile['keep_provider_pin'] ?? false));
                } catch (ConnectionException $e) {
                    throw new RuntimeException('Nie można połączyć z API AI: '.$e->getMessage(), 0, $e);
                }
            }

            if (! $response->successful()) {
                throw new RuntimeException($this->formatHttpError($response, $profile));
            }

            $payload = $response->json();
            $content = $this->contentReader->fromPayload($payload);

            // reasoning zjadł budżet tokenów — powtórz z większym limitem, o ile slot jeszcze ma miejsce
            if ($content === '' && $this->contentReader->finishReason($payload) === 'length' && $maxTokens < self::DEFAULT_MAX_TOKENS) {
                $bumped = $this->fitMaxTokens(
                    self::DEFAULT_MAX_TOKENS,
                    $basePayload['messages'] ?? $messages,
                    $profile
                );
                if ($bumped > $maxTokens) {
                    $basePayload['max_tokens'] = $bumped;
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
            }

            if ($content === '') {
                Log::warning('AI empty content', ['body' => $payload, 'model' => $model]);
                throw new RuntimeException('API AI zwróciło pustą odpowiedź (model reasoning potrzebuje więcej tokenów na wynik JSON).');
            }

            $resultModel = (string) data_get($payload, 'model', $profile['model']);
            $usage = data_get($payload, 'usage');
            app(AiServedProviderTally::class)->served($payload);

            return [
                'content' => $content,
                'model' => $resultModel,
                'usage' => $usage,
                'finish_reason' => $this->contentReader->finishReason($payload),
                'provider' => is_string(data_get($payload, 'provider')) ? (string) data_get($payload, 'provider') : null,
            ];
        } finally {
            $this->reportLiveDone($profile, $resultModel, $started, $usage);
        }
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
     * Skan PDF jako strony-obrazy (gdy native parser nie widzi katalogu / warstwy tekstowej).
     *
     * @param  list<array{bytes: string, mime: string, label?: string}>  $images
     * @return array{content: string, model: string, usage: array<string, mixed>|null}
     */
    public function chatWithPageImages(string $prompt, array $images, ?AiTask $task = null): array
    {
        if ($images === []) {
            throw new RuntimeException('Brak stron-obrazów do analizy PDF.');
        }

        $content = [
            ['type' => 'text', 'text' => $prompt],
        ];
        foreach ($images as $image) {
            $label = trim((string) ($image['label'] ?? ''));
            if ($label !== '') {
                $content[] = ['type' => 'text', 'text' => $label];
            }
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:'.$image['mime'].';base64,'.base64_encode($image['bytes']),
                    'detail' => 'low',
                ],
            ];
        }

        return $this->chat([
            [
                'role' => 'system',
                'content' => 'Jesteś ekspertem od cenników BHP. Odczytujesz tabele ze skanów. Zwracasz WYŁĄCZNIE JSON (bez markdown).',
            ],
            [
                'role' => 'user',
                'content' => $content,
            ],
        ], 0.1, true, ['max_tokens' => 1500], $task);
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
        $parsed = $this->tryParseJson($result['content']);
        if ($this->shouldRetryTruncated($result, $parsed)) {
            Log::info('AI JSON ucięty (finish_reason=length) — ponawiam ze skróconymi źródłami', [
                'content_len' => mb_strlen($result['content']),
            ]);
            $retryExtra = $extra;
            if (isset($retryExtra['max_tokens'])) {
                $retryExtra['max_tokens'] = max(512, (int) floor(((int) $retryExtra['max_tokens']) * 0.85));
            }
            $result = $this->chat(
                $this->messagesForLengthRetry($messages),
                $temperature,
                true,
                $retryExtra !== [] ? $retryExtra : null,
                $task
            );
            $parsed = $this->tryParseJson($result['content']);
        }

        if ($parsed !== null) {
            return $parsed;
        }

        $repair = $this->chat([
            [
                'role' => 'system',
                'content' => 'Napraw odpowiedź do poprawnego JSON obiektu. Zwróć TYLKO JSON, bez markdown, komentarzy i pól thought/reasoning/thinking.',
            ],
            [
                'role' => 'user',
                'content' => "Popraw poniższy tekst do walidnego JSON:\n\n".$result['content'],
            ],
        ], 0.0, true, $extra !== [] ? $extra : null, $task);

        try {
            return $this->jsonParser->parse($repair['content']);
        } catch (RuntimeException $e) {
            $recovered = $this->tryParseJson((string) ($result['content'] ?? ''));
            if ($recovered !== null) {
                return $recovered;
            }

            throw $e;
        }
    }

    /**
     * Opisy produktów / filtr chrome. Profil przypisany do zadania jest ważniejszy —
     * dopiero bez niego wraca stare pole „Model do opisów”.
     */
    public function chatJsonEnrichment(array $messages, ?float $temperature = null, ?int $maxTokens = null): array
    {
        if ($this->settings->enrichmentUsesLargeModel()) {
            return $this->chatJson($messages, $temperature, $maxTokens, null, null);
        }

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
        $detail = $task === AiTask::ImageVerification ? 'high' : 'low';
        foreach ($images as $index => $image) {
            $content[] = [
                'type' => 'text',
                'text' => sprintf('Kandydat %d: %s', $index, $image['label']),
            ];
            $content[] = [
                'type' => 'image_url',
                'image_url' => [
                    'url' => 'data:'.$image['mime'].';base64,'.base64_encode($image['bytes']),
                    'detail' => $detail,
                ],
            ];
        }

        return $this->chatJson([
            [
                'role' => 'system',
                'content' => 'Weryfikujesz zdjęcia produktów BHP. '
                    .'Najpierw nazwij rodzaj przedmiotu na zdjęciu (czapka, spodnie, ogrodniczki, kurtka, bluza, rękawice, buty). '
                    .'Jeśli rodzaj nie zgadza się z produktem z zapytania — is_relevant_product=false. '
                    .'Ta sama linia odzieży nie wystarczy. Zwracasz wyłącznie JSON i nie zgadujesz.',
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

        return $this->chatWithProviderWebSearch($prompt, $timeoutSeconds, $task);
    }

    /**
     * Web search u dostawcy (OpenRouter plugin), nawet gdy w ustawieniach jest SearXNG/DDG.
     * Używane gdy darmowe silniki zwracają 429 / captcha.
     *
     * @return array{content: string, model: string, citations: list<array{url: string, title: string}>}
     */
    public function chatWithProviderWebSearch(string $prompt, int $timeoutSeconds = 60, ?AiTask $task = null): array
    {
        if (! $this->settings->resolve()['enabled']) {
            throw new RuntimeException('Integracja AI jest wyłączona. Włącz ją w Ustawieniach AI.');
        }

        $errors = [];
        $tried = [];
        foreach ($this->webSearchProfileQueue($task) as $profile) {
            $sig = $profile['base_url'].'|'.$profile['model'];
            if (isset($tried[$sig])) {
                continue;
            }
            $tried[$sig] = true;
            if (! $this->baseUrlSupportsProviderWebSearch((string) $profile['base_url'])) {
                $errors[] = $profile['label'].': brak pluginu web';

                continue;
            }

            try {
                return $this->webSearchWithProfile($profile, $prompt, $timeoutSeconds);
            } catch (RuntimeException $e) {
                $errors[] = $profile['label'].': '.$e->getMessage();
                Log::warning('Web search profil zawiódł — próbuję kolejny z pluginem web', [
                    'profile' => $profile['label'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        throw new RuntimeException(
            $errors !== []
                ? 'AI web_search: '.implode(' | ', array_slice($errors, 0, 3))
                : 'Brak modelu z wyszukiwaniem internetowym (OpenRouter / OpenAI).'
        );
    }

    /**
     * Najpierw zadanie WebSearch, potem każdy profil z pluginem web — lokalne Gemma pomijamy.
     *
     * @return list<array{label: string, base_url: string, api_key: ?string, model: string, timeout_seconds: int, temperature: float, reasoning_effort: string, is_default: bool}>
     */
    private function webSearchProfileQueue(?AiTask $task): array
    {
        $queue = [$this->settings->profileForTask($task ?? AiTask::WebSearch)];
        foreach ($this->settings->resolvedProfiles() as $profile) {
            $queue[] = $profile;
        }

        return $queue;
    }

    /**
     * @param  array{label: string, base_url: string, api_key: ?string, model: string, timeout_seconds: int, temperature: float, reasoning_effort: string, is_default: bool}  $profile
     * @return array{content: string, model: string, citations: list<array{url: string, title: string}>}
     */
    private function webSearchWithProfile(array $profile, string $prompt, int $timeoutSeconds): array
    {
        if ($profile['api_key'] === null || $profile['api_key'] === '') {
            throw new RuntimeException('Brak klucza API AI. Uzupełnij go w Ustawieniach AI.');
        }
        if (! $this->baseUrlSupportsProviderWebSearch((string) $profile['base_url'])) {
            throw new RuntimeException(
                'Ten endpoint AI nie ma wyszukiwania internetowego (plugin web) — lokalny model nie szuka w sieci.'
            );
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
        $payload = $this->applyReasoningEffort(
            $payload,
            (string) ($profile['reasoning_effort'] ?? ReasoningEffort::AUTO)
        );
        $payload = $this->applyOpenRouterProvider($payload, $profile);

        $started = microtime(true);
        $usage = null;
        $resultModel = $profile['model'];
        $this->reportLiveWaiting($profile, $profile['model']);
        try {
            try {
                $response = $this->aiHttp($profile['api_key'], max(30, $timeoutSeconds))
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
                throw new RuntimeException($this->webSearchHttpError($response->status(), $detail));
            }

            $data = $response->json();
            $content = $this->contentReader->fromPayload($data);
            $citations = $this->extractChatWebCitations($data);
            if ($content === '' && $citations === []) {
                throw new RuntimeException('Web search AI zwróciło pustą odpowiedź.');
            }

            $resultModel = (string) data_get($data, 'model', $profile['model']);
            $usage = data_get($data, 'usage');

            return [
                'content' => $content,
                'model' => $resultModel,
                'citations' => $citations,
            ];
        } finally {
            $this->reportLiveDone($profile, $resultModel, $started, $usage);
        }
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
        $payload = $this->applyOpenRouterProvider($payload, $profile);

        try {
            $response = $this->aiHttp($profile['api_key'], max(10, $timeoutSeconds))
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
            throw new RuntimeException($this->webSearchHttpError($response->status(), $detail));
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
        int $timeout,
        bool $relaxPin = true,
    ): Response {
        $response = $this->postChat($url, $apiKey, $payload, $jsonMode, $reasoning, $timeout);
        $attempt = 0;
        $rateLimited = 0;
        while (in_array($response->status(), self::OVERLOAD_STATUSES, true) && $attempt < self::OVERLOAD_RETRIES) {
            if ($response->status() === 429) {
                $rateLimited++;
                if ($rateLimited > self::RATE_LIMIT_RETRIES) {
                    break;
                }
            }
            $wait = $this->retryAfterSeconds($response, $attempt);
            if ($wait > 0) {
                sleep($wait);
            }
            // Przypięty dostawca dalej przeciążony — po jednej powtórce wolno OpenRouterowi
            // wziąć innego dostawcę tego modelu, zamiast czekać minutami na jednego.
            if ($attempt >= 1 && $relaxPin) {
                $payload = $this->relaxOverloadedProviderPin($payload);
            }
            $response = $this->postChat($url, $apiKey, $payload, $jsonMode, $reasoning, $timeout);
            $attempt++;
        }

        return $response;
    }

    /**
     * Ocena kart i zrozumienie wymagań (wyszukiwarka, dopasowanie przetargu) nie przechodzą po limicie zapytań na zastępczych
     * dostawców modelu. Pomiar 20260914_154500: przypięty Makora ocenił 40 pozycji z 1 złą kartą, zastępczy OpenInference
     * 5 pozycji z 3 złymi kartami z oceną 95 (wcześniej StreamLake i DeepInfra w przebiegach z seriami złych kart).
     * Brak odpowiedzi daje pustą pozycję, którą widać; zła karta z oceną 95 wyglądała jak pewny wybór.
     * Opisy produktów i pozostałe zadania dalej mogą wziąć zastępcę, żeby nie czekać minutami.
     *
     * @param  array<string, mixed>  $profile
     * @return array<string, mixed>
     */
    private function withProviderPinPolicy(array $profile, ?AiTask $task): array
    {
        $profile['keep_provider_pin'] = $task === AiTask::ProductSearch;

        return $profile;
    }

    /**
     * provider.only + allow_fallbacks=false → najpierw ten sam dostawca, ale z przejściem na innych.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function relaxOverloadedProviderPin(array $payload): array
    {
        $only = $payload['provider']['only'] ?? null;
        if (! is_array($only) || $only === [] || ($payload['provider']['allow_fallbacks'] ?? true) !== false) {
            return $payload;
        }
        Log::info('Dostawca OpenRouter przeciążony — dopuszczam innych dostawców modelu', [
            'provider' => $only,
            'model' => $payload['model'] ?? null,
        ]);
        app(AiServedProviderTally::class)->relaxedPin();
        $payload['provider'] = ['order' => array_values($only), 'allow_fallbacks' => true];

        return $payload;
    }

    private function retryAfterSeconds(Response $response, int $attempt): int
    {
        if (app()->environment('testing')) {
            return 0;
        }

        return $this->overloadWaitSeconds($response, $attempt);
    }

    /**
     * Przerwa przed powtórką. Nagłówek Retry-After to dolna granica, nie cała przerwa: przetarg 1 na
     * produkcji (20.09.2026) — dostawca odsyłał „Retry-After: 1”, obie powtórki 429 poszły w 2 sekundy
     * i trzy pozycje zostały bez oceny modelu. Jak długo trwa limit u dostawcy — nie wiemy; liczbę
     * powtórek 429 dalej ogranicza RATE_LIMIT_RETRIES.
     */
    private function overloadWaitSeconds(Response $response, int $attempt): int
    {
        if ($response->status() === 100) {
            return 1;
        }

        // Jitter jest tu istotny: bez niego wszystkie równoległe żądania wracają
        // w tej samej chwili i znowu przepełniają kolejkę slotów modelu.
        $base = [3, 8, 15, 25, 40][$attempt] ?? 40;
        $wait = $base + random_int(0, intdiv($base, 2));
        $header = $response->header('Retry-After');

        return is_numeric($header) ? min(60, max($wait, (int) $header)) : $wait;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function tryParseJson(string $content): ?array
    {
        try {
            return $this->jsonParser->parse($content);
        } catch (RuntimeException) {
            return null;
        }
    }

    /**
     * @param  array{content?: string, finish_reason?: string}  $result
     * @param  array<string, mixed>|null  $parsed
     */
    private function shouldRetryTruncated(array $result, ?array $parsed): bool
    {
        if (($result['finish_reason'] ?? '') !== 'length') {
            return false;
        }
        $matches = is_array($parsed['matches'] ?? null) ? $parsed['matches'] : [];
        if ($matches !== []) {
            return false;
        }
        if ($parsed === null || ($parsed['_partial'] ?? false) === true) {
            return true;
        }

        return ! $this->jsonParser->looksComplete((string) ($result['content'] ?? ''));
    }

    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     * @return list<array{role: string, content: mixed}>
     */
    private function messagesForLengthRetry(array $messages): array
    {
        $out = [[
            'role' => 'system',
            'content' => 'Poprzednia odpowiedź została ucięta limitem tokenów. '
                .'Zwróć kompletny, domknięty JSON bez pól thought/reasoning/thinking. '
                .'Opis produktu zostaw pełny (wszystkie fakty i normy); '
                .'skróć tylko powtórzenia i cytaty ze stron.',
        ]];
        foreach ($this->compactMessages($messages, 0.74) as $message) {
            $out[] = $message;
        }

        return $out;
    }

    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     * @return list<array{role: string, content: mixed}>
     */
    private function compactMessages(array $messages, float $keep): array
    {
        $out = [];
        foreach ($messages as $message) {
            $copy = $message;
            $content = $copy['content'] ?? null;
            if (is_string($content) && mb_strlen($content) > 400) {
                $copy['content'] = $this->shrinkPromptText($content, $keep);
            }
            $out[] = $copy;
        }

        return $out;
    }

    private function shrinkPromptText(string $text, float $keep): string
    {
        $limit = max(400, (int) floor(mb_strlen($text) * $keep));
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        return mb_substr($text, 0, $limit)."\n[… skrócono źródła — zachowaj pełny opis produktu …]";
    }

    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     * @param  array<string, mixed>  $profile
     * @return array{0: list<array{role: string, content: mixed}>, 1: int}
     */
    private function fitChatRequest(array $messages, int $requestedMaxTokens, array $profile): array
    {
        $limit = $this->hardContextLimit($profile);
        // Założony limit lokalnego serwera to --max-model-len dawnego vLLM. Zapytanie, które by się w nim nie
        // zmieściło, sprawdza faktyczny limit serwera — 21.09.2026 Qwen na Sparku miał 65536, a klient ciął prompt
        // rankingu (13,7 tys. znaków) do 947 znaków bez reguł i formatu odpowiedzi: 13 z 15 pozycji przetargu bez
        // oceny modelu. Małe zapytania i chmura idą bez dodatkowego pytania.
        if ($this->usesAssumedLocalLimit($profile)
            && $this->estimatePromptTokens($messages) + max(self::MIN_OUTPUT_TOKENS, $requestedMaxTokens) + self::SLOT_RESERVE > $limit) {
            $served = $this->servedMaxModelLen($profile);
            if ($served !== null) {
                $limit = max($limit, (int) floor($served * self::LOCAL_FIT_RATIO));
            }
        }
        $messages = $this->shrinkMessagesToFit($messages, self::MIN_OUTPUT_TOKENS, $profile, $limit);

        return [$messages, $this->fitMaxTokens($requestedMaxTokens, $messages, $profile, $limit)];
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function usesAssumedLocalLimit(array $profile): bool
    {
        $base = (string) ($profile['base_url'] ?? '');

        return (int) config('ai.max_model_len', 0) < 1024 && $base !== '' && ! $this->isCloudEndpoint($base);
    }

    /**
     * Limit kontekstu podany przez serwer modelu (vLLM: /v1/models → max_model_len) dla modelu profilu.
     * Zapamiętany na godzinę; brak odpowiedzi — na 5 minut null, czyli dotychczasowy założony limit.
     *
     * @param  array<string, mixed>  $profile
     * @param  bool  $fetch  false = tylko zapamiętana wartość, bez zapytania do serwera
     */
    private function servedMaxModelLen(array $profile, bool $fetch = true): ?int
    {
        $base = rtrim((string) ($profile['base_url'] ?? ''), '/');
        $model = (string) ($profile['model'] ?? '');
        if ($base === '') {
            return null;
        }
        $key = 'ai:served-max-model-len:'.sha1($base.'|'.$model);
        $cached = Cache::get($key);
        if (is_int($cached)) {
            return $cached > 0 ? $cached : null;
        }
        if (! $fetch) {
            return null;
        }

        $found = 0;
        try {
            $response = Http::timeout(5)
                ->withToken((string) ($profile['api_key'] ?? ''))
                ->acceptJson()
                ->get($base.'/models');
            $models = $response->successful() && is_array($response->json('data')) ? $response->json('data') : [];
            $entry = collect($models)->first(static fn (mixed $m): bool => is_array($m) && ($m['id'] ?? null) === $model)
                ?? (count($models) === 1 ? $models[0] : null);
            $len = is_array($entry) ? ($entry['max_model_len'] ?? null) : null;
            $found = is_int($len) && $len >= 1024 ? $len : 0;
        } catch (Throwable) {
            $found = 0;
        }
        Cache::put($key, $found, $found > 0 ? 3600 : 300);

        return $found > 0 ? $found : null;
    }

    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     * @param  array<string, mixed>  $profile
     * @return list<array{role: string, content: mixed}>
     */
    private function shrinkMessagesToFit(array $messages, int $minOutputTokens, array $profile, ?int $limit = null): array
    {
        $limit ??= $this->hardContextLimit($profile);
        $keep = 0.82;
        for ($i = 0; $i < 8; $i++) {
            if ($this->estimatePromptTokens($messages) + $minOutputTokens + self::SLOT_RESERVE <= $limit) {
                return $messages;
            }
            $messages = $this->compactMessages($messages, $keep);
            $keep = max(0.40, $keep - 0.10);
        }

        return $this->forceTrimMessages(
            $messages,
            max(400, $limit - $minOutputTokens - self::SLOT_RESERVE)
        );
    }

    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     * @return list<array{role: string, content: mixed}>
     */
    private function forceTrimMessages(array $messages, int $maxPromptTokens): array
    {
        $budgetChars = max(400, (int) floor($maxPromptTokens * self::PROMPT_CHARS_PER_TOKEN));
        $used = 0;
        foreach ($messages as $message) {
            $used += $this->estimateContentChars($message['content'] ?? '');
        }
        if ($used <= $budgetChars) {
            return $messages;
        }

        $longestIdx = null;
        $longestLen = 0;
        foreach ($messages as $i => $message) {
            $content = $message['content'] ?? null;
            if (! is_string($content)) {
                continue;
            }
            $len = mb_strlen($content);
            if ($len > $longestLen) {
                $longestIdx = $i;
                $longestLen = $len;
            }
        }
        if ($longestIdx === null) {
            return $messages;
        }

        $allow = max(400, $budgetChars - ($used - $longestLen));
        $messages[$longestIdx]['content'] = mb_substr((string) $messages[$longestIdx]['content'], 0, $allow)
            ."\n[… skrócono źródła — zachowaj pełny opis produktu …]";

        return $messages;
    }

    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     * @param  array<string, mixed>  $profile
     */
    private function fitMaxTokens(int $requested, array $messages, array $profile = [], ?int $limit = null): int
    {
        $room = ($limit ?? $this->hardContextLimit($profile)) - $this->estimatePromptTokens($messages) - self::SLOT_RESERVE;

        return max(self::MIN_OUTPUT_TOKENS, min($requested, $room));
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function hardContextLimit(array $profile = []): int
    {
        $configured = (int) config('ai.max_model_len', 0);
        if ($configured >= 1024) {
            return $configured;
        }
        $base = (string) ($profile['base_url'] ?? '');
        if ($base !== '' && $this->isCloudEndpoint($base)) {
            return self::CLOUD_MAX_MODEL_LEN;
        }

        // limit podany przez serwer, jeśli już go znamy (servedMaxModelLen) — bez pytania serwera tutaj
        $served = $this->servedMaxModelLen($profile, fetch: false);

        return (int) floor(max(self::LOCAL_MAX_MODEL_LEN, $served ?? 0) * self::LOCAL_FIT_RATIO);
    }

    private function isCloudEndpoint(string $baseUrl): bool
    {
        $base = mb_strtolower($baseUrl);

        return str_contains($base, 'openrouter.ai')
            || str_contains($base, 'api.openai.com')
            || str_contains($base, 'googleapis.com')
            || str_contains($base, 'anthropic.com');
    }

    /**
     * @param  list<array{role: string, content: mixed}>  $messages
     */
    private function estimatePromptTokens(array $messages): int
    {
        $chars = 0;
        foreach ($messages as $message) {
            $chars += $this->estimateContentChars($message['content'] ?? '');
        }

        return max(1, (int) ceil($chars / self::PROMPT_CHARS_PER_TOKEN) + self::TOKEN_ESTIMATE_PADDING);
    }

    private function estimateContentChars(mixed $content): int
    {
        if (is_string($content)) {
            return mb_strlen($content);
        }
        if (! is_array($content) || $content === [] || ! array_is_list($content)) {
            return is_array($content)
                ? mb_strlen((string) json_encode($content, JSON_UNESCAPED_UNICODE))
                : 0;
        }

        $chars = 0;
        foreach ($content as $part) {
            if (! is_array($part)) {
                $chars += mb_strlen((string) $part);

                continue;
            }
            $type = (string) ($part['type'] ?? '');
            if ($type === 'text') {
                $chars += mb_strlen((string) ($part['text'] ?? ''));

                continue;
            }
            // base64 obrazu/PDF nie jest tekstem slota vLLM — stały budżet enkodera vision
            if ($type === 'image_url' || $type === 'file') {
                $chars += 2500;

                continue;
            }
            $chars += 40;
        }

        return $chars;
    }

    private function isContextOverflow(Response $response): bool
    {
        if (! in_array($response->status(), [400, 413], true)) {
            return false;
        }
        $detail = strtolower((string) (data_get($response->json(), 'error.message') ?? $response->body()));

        return preg_match(
            '/context|max_model_len|n_ctx|too many tokens|maximum context|requested .+ tokens|decoder prompt/i',
            $detail
        ) === 1;
    }

    /**
     * @param  array{label?: string, is_default?: bool}|null  $profile
     */
    private function formatHttpError(Response $response, ?array $profile = null): string
    {
        $status = $response->status();
        $body = $response->json();
        $detail = is_array($body)
            ? (string) data_get($body, 'error.message', $response->body())
            : $response->body();
        $who = ! empty($profile['is_default']) || ($profile['label'] ?? '') === ''
            ? 'API AI'
            : 'API AI (profil „'.$profile['label'].'”)';
        if ($status === 401) {
            return $who.' odrzuciło klucz (HTTP 401: '.$detail.'). '
                .'Wklej nowy klucz sk-or-v1-… w tym profilu i zapisz ustawienia — nie zmieniaj klucza w konfiguracji głównej.';
        }
        if ($status === 402) {
            return $who.' odmówiło (HTTP 402: '.$detail.'). '.$this->outOfCreditsHint();
        }
        if ($status === 429) {
            return 'Limit zapytań modelu AI (HTTP 429). To OpenRouter/dostawca modelu, nie Tavily. '
                .'Poczekaj ok. minutę i ponów próbę.';
        }

        return $who.' zwróciło błąd HTTP '.$status.': '.$detail;
    }

    private function webSearchHttpError(int $status, string $detail): string
    {
        $message = 'Web search AI HTTP '.$status.': '.$detail;

        return $status === 402 ? $message.' '.$this->outOfCreditsHint() : $message;
    }

    /**
     * OpenRouter przy pustym saldzie odpowiada 402 „Insufficient credits” — samo
     * to nie mówi, gdzie problem naprawić, a błąd widzi handlowiec przy opisie
     * produktu, nie administrator.
     */
    private function outOfCreditsHint(): string
    {
        return 'Konto modelu AI nie ma środków. Doładuj konto OpenRouter na '
            .'https://openrouter.ai/settings/credits albo wpisz w Ustawieniach AI klucz innego dostawcy.';
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

        // Gemini 3: reasoning nie da się wyłączyć — ponów bez enabled:false.
        if ($response !== null
            && in_array($response->status(), [400, 422], true)
            && $this->isMandatoryReasoningError($response)
            && $this->payloadDisablesReasoning($payload)
        ) {
            $alt = $this->stripDisabledReasoning($payload);
            $retry = $jsonMode ? ($alt + ['response_format' => ['type' => 'json_object']]) : $alt;
            $response = $this->post($url, $apiKey, $retry, $timeout);
            if ($response->successful() || ! $jsonMode) {
                return $response;
            }
            $response = $this->post($url, $apiKey, $alt, $timeout);
            if ($response->successful() || ! in_array($response->status(), [400, 422], true)) {
                return $response;
            }
        }

        // GPT-5 / o-series: max_tokens bywa odrzucane — spróbuj max_completion_tokens
        if ($reasoning && $response !== null && in_array($response->status(), [400, 422], true)) {
            $alt = $payload;
            $alt['max_completion_tokens'] = (int) ($payload['max_tokens'] ?? self::DEFAULT_MAX_TOKENS);
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
        $timeout = max(240, $timeoutSeconds);
        if ($this->isReasoningModel((string) ($payload['model'] ?? ''))) {
            $timeout = max(240, $timeout);
        }

        return $this->aiHttp($apiKey, $timeout)->post($url, $payload);
    }

    /**
     * Guzzle przy ciele >1 MB dodaje Expect: 100-continue — llama-swap/vLLM
     * zapisuje wtedy status 100 i 0 tokenów, a klient urywa połączenie (~2 s).
     */
    private function aiHttp(?string $apiKey, int $timeoutSeconds): PendingRequest
    {
        $request = Http::acceptJson()
            ->withHeaders([
                'HTTP-Referer' => config('app.url', 'http://localhost'),
                'X-Title' => 'SUPON AI',
                'User-Agent' => QueueWorkerIdentity::userAgent('SUPON-AI/1.0'),
                'Expect' => '',
            ])
            // Zatrzymany dostawca potrafi trzymać połączenie bez danych dłużej niż timeout
            // (zdarzały się godziny) — wtedy worker stoi. Brak ruchu przez minutę kończy próbę.
            ->withOptions(['expect' => false, 'curl' => [
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME => 60,
            ]])
            ->timeout($timeoutSeconds)
            ->connectTimeout(15);

        if (is_string($apiKey) && $apiKey !== '') {
            $request = $request->withToken($apiKey);
        }

        return $request;
    }

    /**
     * @return array{content: string, model: string, citations: list<array{url: string, title: string}>}
     */
    private function baseUrlSupportsProviderWebSearch(string $baseUrl): bool
    {
        $base = mb_strtolower($baseUrl);

        return str_contains($base, 'openrouter.ai') || str_contains($base, 'api.openai.com');
    }

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
        return preg_match('/gpt-5|o1|o3|o4|tera|reasoning|r1|think|gemini-3/i', $model) === 1;
    }

    private function requiresReasoning(string $model): bool
    {
        return preg_match('/gemini-3/i', $model) === 1;
    }

    private function isQwen38Model(string $model): bool
    {
        // Tylko lokalny vLLM/llama-swap (qwen38-27b-fast). OpenRouter qwen/qwen3.8-flash
        // nie może łapać się tu — AUTO+low włącza myślenie i pustoszy JSON.
        return preg_match('/qwen38[-_]|qwen3\.8-\d/i', $model) === 1;
    }

    private function shouldDisableOpenRouterThinking(string $model, string $effort): bool
    {
        if ($this->requiresReasoning($model)
            || ! str_contains($model, '/')
            || ! in_array($effort, [ReasoningEffort::OFF, ReasoningEffort::AUTO, ReasoningEffort::NONE], true)) {
            return false;
        }

        if (preg_match('/qwen3|gemini/i', $model) === 1) {
            return true;
        }

        // Jawne „bez myślenia” w profilu: DeepSeek V4 Flash (i inne hybrydy) ignorują samo
        // chat_template_kwargs.enable_thinking na OpenRouterze, myślą dalej i zjadają limit tokenów —
        // log produkcji: seria „API AI zwróciło pustą odpowiedź” przy dopasowaniu przetargu.
        // Endpoint, który wymaga myślenia, odrzuci to 400 i ponowienie zdejmie pole.
        return $effort === ReasoningEffort::NONE && ! $this->isReasoningModel($model);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function disableOpenRouterThinking(array $payload): array
    {
        $kwargs = is_array($payload['chat_template_kwargs'] ?? null)
            ? $payload['chat_template_kwargs']
            : [];
        $kwargs['enable_thinking'] = false;
        $payload['chat_template_kwargs'] = $kwargs;
        $payload['reasoning'] = ['enabled' => false, 'exclude' => true];
        unset($payload['reasoning_effort']);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadDisablesReasoning(array $payload): bool
    {
        return ($payload['reasoning']['enabled'] ?? null) === false
            || ($payload['chat_template_kwargs']['enable_thinking'] ?? null) === false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function stripDisabledReasoning(array $payload): array
    {
        unset($payload['reasoning']);
        $kwargs = $payload['chat_template_kwargs'] ?? null;
        if (is_array($kwargs)) {
            unset($kwargs['enable_thinking']);
            if ($kwargs === []) {
                unset($payload['chat_template_kwargs']);
            } else {
                $payload['chat_template_kwargs'] = $kwargs;
            }
        }

        return $payload;
    }

    private function isMandatoryReasoningError(Response $response): bool
    {
        $detail = strtolower((string) (data_get($response->json(), 'error.message') ?? $response->body()));

        return str_contains($detail, 'reasoning is mandatory')
            || (str_contains($detail, 'reasoning') && str_contains($detail, 'cannot be disabled'));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function applyReasoningEffort(array $payload, string $effort): array
    {
        $effort = ReasoningEffort::normalize($effort);
        $model = (string) ($payload['model'] ?? '');
        if ($this->shouldDisableOpenRouterThinking($model, $effort)) {
            return $this->disableOpenRouterThinking($payload);
        }
        if ($this->requiresReasoning($model)
            && in_array($effort, [ReasoningEffort::AUTO, ReasoningEffort::LOW], true)) {
            $payload['reasoning'] = ['effort' => 'low'];

            return $payload;
        }
        if ($effort === ReasoningEffort::OFF) {
            return $payload;
        }
        if ($effort === ReasoningEffort::AUTO) {
            if (! $this->isQwen38Model($model)) {
                return $payload;
            }
            $effort = ReasoningEffort::LOW;
        }

        $kwargs = is_array($payload['chat_template_kwargs'] ?? null)
            ? $payload['chat_template_kwargs']
            : [];

        if ($effort === ReasoningEffort::NONE) {
            $kwargs['enable_thinking'] = false;
            $payload['chat_template_kwargs'] = $kwargs;

            return $payload;
        }

        $payload['reasoning_effort'] = $effort;
        $kwargs['reasoning_effort'] = $effort;
        $payload['chat_template_kwargs'] = $kwargs;

        return $payload;
    }

    /**
     * @param  array{label?: string, base_url?: string}  $profile
     */
    private function reportLiveWaiting(array $profile, string $model): void
    {
        $live = app(EnrichmentLiveProgress::class);
        if ($live->isBound()) {
            $live->waiting($model, $this->providerLabel($profile));
        }
    }

    /**
     * @param  array{label?: string, base_url?: string}  $profile
     */
    private function reportLiveDone(array $profile, string $model, float $started, mixed $usage): void
    {
        $live = app(EnrichmentLiveProgress::class);
        if (! $live->isBound()) {
            return;
        }
        $prompt = is_array($usage) ? $this->usageInt($usage, 'prompt_tokens') : null;
        $completion = is_array($usage) ? $this->usageInt($usage, 'completion_tokens') : null;
        $live->done($model, microtime(true) - $started, $prompt, $completion);
    }

    /** @param  array<string, mixed>  $usage */
    private function usageInt(array $usage, string $key): ?int
    {
        $value = $usage[$key] ?? null;

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array{label?: string, base_url?: string}  $profile
     */
    private function providerLabel(array $profile): string
    {
        $base = mb_strtolower((string) ($profile['base_url'] ?? ''));
        if (str_contains($base, 'openrouter.ai')) {
            return 'OpenRouter';
        }
        if (str_contains($base, 'api.openai.com')) {
            return 'OpenAI';
        }
        if (str_contains($base, '127.0.0.1') || str_contains($base, 'localhost')) {
            return 'lokalny';
        }
        $label = trim((string) ($profile['label'] ?? ''));

        return $label !== '' ? $label : 'AI';
    }
}
