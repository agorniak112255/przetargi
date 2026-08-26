<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Services\Ai\OpenAiCompatibleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OpenAiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://openrouter.ai/api/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'openai/gpt-4o',
            'timeout_seconds' => 30,
            'temperature' => 0.1,
        ]);
    }

    public function test_retries_429_then_succeeds(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::sequence()
                ->push(['error' => ['message' => 'Provider returned error']], 429)
                ->push([
                    'choices' => [[
                        'message' => ['content' => '{"ok":true}'],
                    ]],
                ], 200),
        ]);

        $json = app(OpenAiCompatibleClient::class)->chatJson([
            ['role' => 'user', 'content' => 'ping'],
        ]);

        $this->assertTrue($json['ok'] ?? false);
        Http::assertSentCount(2);
    }

    public function test_retries_busy_local_model_until_slot_is_free(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::sequence()
                ->push(['error' => ['message' => 'server is busy']], 503)
                ->push(['error' => ['message' => 'no slot available']], 429)
                ->push(['error' => ['message' => 'overloaded']], 529)
                ->push([
                    'choices' => [[
                        'message' => ['content' => '{"ok":true}'],
                    ]],
                ], 200),
        ]);

        $json = app(OpenAiCompatibleClient::class)->chatJson([
            ['role' => 'user', 'content' => 'ping'],
        ]);

        $this->assertTrue($json['ok'] ?? false);
        Http::assertSentCount(4);
    }

    public function test_gives_up_after_five_overload_retries(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response(
                ['error' => ['message' => 'no slot available']],
                429
            ),
        ]);

        try {
            app(OpenAiCompatibleClient::class)->chatJson([
                ['role' => 'user', 'content' => 'ping'],
            ]);
            $this->fail('Oczekiwano wyjątku 429');
        } catch (\RuntimeException) {
            Http::assertSentCount(6);
        }
    }

    public function test_sends_smaller_token_budget_by_default(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"ok":true}'],
                ]],
            ], 200),
        ]);

        app(OpenAiCompatibleClient::class)->chatJson([
            ['role' => 'user', 'content' => 'ping'],
        ]);

        Http::assertSent(fn ($request) => ($request->data()['max_tokens'] ?? null) === 6000);
    }

    public function test_exhausted_429_explains_openrouter_not_tavily(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response(
                ['error' => ['message' => 'Provider returned error']],
                429
            ),
        ]);

        try {
            app(OpenAiCompatibleClient::class)->chatJson([
                ['role' => 'user', 'content' => 'ping'],
            ]);
            $this->fail('Oczekiwano wyjątku 429');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('429', $e->getMessage());
            $this->assertStringContainsString('nie Tavily', $e->getMessage());
        }
    }
}
