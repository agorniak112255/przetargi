<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * Tokeny i próby wywołań modelu w jednym kształcie — do liczenia kosztu pozycji wyszukiwania.
 *
 * `calls` to odpowiedzi HTTP 2xx z polem `usage` (także puste przez `length`, ucięte, ze złym JSON-em, naprawy JSON,
 * ponowienia i zejście na konfigurację główną), `failed_calls` — wywołania bez odpowiedzi modelu (429, 5xx, 4xx,
 * błąd połączenia, timeout): liczymy próbę, nie tokeny, bo dostawca ich nie podaje.
 *
 * @phpstan-type Usage array{prompt_tokens: int, completion_tokens: int, reasoning_tokens: int, cached_tokens: int, cost: ?float, calls: int, failed_calls: int}
 */
final class AiUsage
{
    private const COUNTS = ['prompt_tokens', 'completion_tokens', 'reasoning_tokens', 'cached_tokens', 'calls', 'failed_calls'];

    /** @return Usage */
    public static function empty(): array
    {
        return [
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'reasoning_tokens' => 0,
            'cached_tokens' => 0,
            'cost' => null,
            'calls' => 0,
            'failed_calls' => 0,
        ];
    }

    /**
     * Z payloadu odpowiedzi 2xx; null, gdy odpowiedź nie niesie `usage`. Chat Completions podaje prompt/completion_tokens,
     * API Responses (web search) — input/output_tokens; szczegóły myślenia i cache w *_details, koszt OpenRoutera w `cost`.
     *
     * @return Usage|null
     */
    public static function fromPayload(mixed $payload): ?array
    {
        $usage = is_array($payload) ? ($payload['usage'] ?? null) : null;
        if (! is_array($usage)) {
            return null;
        }

        return [
            'prompt_tokens' => self::int($usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null),
            'completion_tokens' => self::int($usage['completion_tokens'] ?? $usage['output_tokens'] ?? null),
            'reasoning_tokens' => self::int(
                data_get($usage, 'completion_tokens_details.reasoning_tokens')
                ?? data_get($usage, 'output_tokens_details.reasoning_tokens')
            ),
            'cached_tokens' => self::int(
                data_get($usage, 'prompt_tokens_details.cached_tokens')
                ?? data_get($usage, 'input_tokens_details.cached_tokens')
            ),
            'cost' => is_numeric($usage['cost'] ?? null) ? (float) $usage['cost'] : null,
            'calls' => 1,
            'failed_calls' => 0,
        ];
    }

    /** @return Usage */
    public static function failed(): array
    {
        $usage = self::empty();
        $usage['failed_calls'] = 1;

        return $usage;
    }

    /**
     * @param  Usage|null  $a
     * @param  Usage|null  $b
     * @return Usage
     */
    public static function add(?array $a, ?array $b): array
    {
        $a ??= self::empty();
        $b ??= self::empty();
        $out = self::empty();
        foreach (self::COUNTS as $key) {
            $out[$key] = self::int($a[$key] ?? 0) + self::int($b[$key] ?? 0);
        }
        $costA = self::cost($a);
        $costB = self::cost($b);
        $out['cost'] = $costA === null && $costB === null ? null : ($costA ?? 0.0) + ($costB ?? 0.0);

        return $out;
    }

    /**
     * $after − $before dla liczników narastających. Koszt null, gdy licznik po nie ma kosztu. Wyzerowanie licznika
     * między znacznikiem a odczytem (reset()) nie daje ujemnych liczb — wtedy zero.
     *
     * @param  Usage  $after
     * @param  Usage  $before
     * @return Usage
     */
    public static function diff(array $after, array $before): array
    {
        $out = self::empty();
        foreach (self::COUNTS as $key) {
            $out[$key] = max(0, self::int($after[$key] ?? 0) - self::int($before[$key] ?? 0));
        }
        $costAfter = self::cost($after);
        $costBefore = self::cost($before);
        $out['cost'] = $costAfter === null ? null : max(0.0, $costAfter - (float) ($costBefore ?? 0.0));

        return $out;
    }

    /** @param  Usage|null  $u */
    public static function isEmpty(?array $u): bool
    {
        return $u === null || self::int($u['calls'] ?? 0) + self::int($u['failed_calls'] ?? 0) === 0;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /** @param  array<string, mixed>  $u */
    private static function cost(array $u): ?float
    {
        return is_numeric($u['cost'] ?? null) ? (float) $u['cost'] : null;
    }
}
