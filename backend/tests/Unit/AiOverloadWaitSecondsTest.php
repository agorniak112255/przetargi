<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\OpenAiCompatibleClient;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Przetarg 1 na produkcji (20.09.2026, 21:08): dostawca modelu odsyłał przy HTTP 429 nagłówek
 * „Retry-After: 1”. Program brał go dosłownie, więc obie powtórki poszły w 2 sekundy i trzy pozycje
 * zostały z komunikatem „Model nie odpowiedział”.
 */
final class AiOverloadWaitSecondsTest extends TestCase
{
    /** @param  array<string, string>  $headers */
    private function wait(int $status, array $headers, int $attempt): int
    {
        $method = new ReflectionMethod(OpenAiCompatibleClient::class, 'overloadWaitSeconds');

        return (int) $method->invoke(
            app(OpenAiCompatibleClient::class),
            new Response(new PsrResponse($status, $headers)),
            $attempt,
        );
    }

    public function test_short_retry_after_does_not_shorten_growing_pause(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $first = $this->wait(429, ['Retry-After' => '1'], 0);
            $second = $this->wait(429, ['Retry-After' => '1'], 1);
            $this->assertGreaterThanOrEqual(3, $first);
            $this->assertLessThanOrEqual(4, $first);
            $this->assertGreaterThanOrEqual(8, $second);
            $this->assertLessThanOrEqual(12, $second);
        }
    }

    public function test_longer_retry_after_is_respected_up_to_a_minute(): void
    {
        $this->assertSame(30, $this->wait(429, ['Retry-After' => '30'], 0));
        $this->assertSame(60, $this->wait(503, ['Retry-After' => '600'], 0));
    }

    public function test_without_header_pause_grows_with_attempts(): void
    {
        $this->assertGreaterThanOrEqual(3, $this->wait(503, [], 0));
        $this->assertGreaterThanOrEqual(15, $this->wait(503, [], 2));
    }

    public function test_interrupted_continue_waits_one_second(): void
    {
        $this->assertSame(1, $this->wait(100, [], 3));
    }
}
