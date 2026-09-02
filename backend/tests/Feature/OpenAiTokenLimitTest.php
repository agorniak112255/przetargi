<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Services\Ai\OpenAiCompatibleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class OpenAiTokenLimitTest extends TestCase
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

    public function test_local_model_is_not_asked_to_search_the_web(): void
    {
        AiSetting::query()->delete();
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'http://127.0.0.1:8000/v1',
            'api_key' => 'local-key',
            'model' => 'gemma4-12b',
            'timeout_seconds' => 30,
            'temperature' => 0.1,
        ]);

        Http::fake();

        try {
            app(OpenAiCompatibleClient::class)->chatWithProviderWebSearch('Znajdź kartę 420000600000');
            $this->fail('Lokalny model nie powinien dostać promptu web search.');
        } catch (\RuntimeException $e) {
            $this->assertMatchesRegularExpression('/pluginu web|lokalny model/i', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_web_search_uses_openrouter_profile_when_main_is_local(): void
    {
        AiSetting::query()->delete();
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'http://127.0.0.1:8000/v1',
            'api_key' => 'local-key',
            'model' => 'gemma4-12b',
            'timeout_seconds' => 30,
            'temperature' => 0.1,
            'model_profiles' => [[
                'id' => 'cloud',
                'name' => 'OpenRouter',
                'base_url' => 'https://openrouter.ai/api/v1',
                'model' => 'openai/gpt-4o',
                'api_key' => 'sk-or-web-123',
                'tasks' => ['enrichment'],
            ]],
        ]);

        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => "https://www.ironshop.it/product.html\n"],
                ]],
            ], 200),
        ]);

        $result = app(OpenAiCompatibleClient::class)->chatWithProviderWebSearch('Znajdź kartę CRACKDOWN');

        $this->assertNotSame('', $result['content']);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && ($request['plugins'][0]['id'] ?? '') === 'web');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '127.0.0.1'));
    }

    public function test_retries_truncated_json_with_compacted_prompt(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => ['content' => '{"description":"buty ochronne S3 z podnoskiem'],
                        'finish_reason' => 'length',
                    ]],
                ], 200)
                ->push([
                    'choices' => [[
                        'message' => ['content' => '{"description":"Pełny opis butów S3.","features":["podnosek"]}'],
                        'finish_reason' => 'stop',
                    ]],
                ], 200),
        ]);

        $longPages = str_repeat('Karta katalogowa butów S3 z podnoskiem kompozytowym. ', 80);
        $json = app(OpenAiCompatibleClient::class)->chatJson([
            ['role' => 'system', 'content' => 'Zwróć JSON opisu produktu.'],
            ['role' => 'user', 'content' => "SKU: S3-1\nStrony:\n".$longPages],
        ], 0.0, 4000);

        $this->assertSame('Pełny opis butów S3.', $json['description'] ?? null);
        Http::assertSentCount(2);
        Http::assertSent(function (Request $request): bool {
            $messages = $request->data()['messages'] ?? [];
            $blob = json_encode($messages, JSON_UNESCAPED_UNICODE) ?: '';

            return str_contains($blob, 'ucięta limitem tokenów');
        });
    }

    public function test_does_not_retry_truncated_rank_matches(): void
    {
        $raw = '{"matches": [ {"id": 23935, "sku": "MEDIBUT-PRIMA-CLOG",'
            .' "name": "PRIMA CLOG", "specs": ["Kod produktu: MEDIBUT-PRIMA-CLOG-SRC-ES"';
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => $raw],
                    'finish_reason' => 'length',
                ]],
            ], 200),
        ]);

        $json = app(OpenAiCompatibleClient::class)->chatJson([
            ['role' => 'user', 'content' => 'Ranking drewniakow'],
        ], 0.0, 800);

        $this->assertSame(23935, (int) ($json['matches'][0]['id'] ?? 0));
        Http::assertSentCount(1);
    }

    public function test_does_not_retry_complete_json_cut_at_token_cap(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"ok":true}'],
                    'finish_reason' => 'length',
                ]],
            ], 200),
        ]);

        $json = app(OpenAiCompatibleClient::class)->chatJson([
            ['role' => 'user', 'content' => 'ping'],
        ]);

        $this->assertTrue($json['ok'] ?? false);
        Http::assertSentCount(1);
    }

    public function test_retries_context_overflow_with_smaller_budget(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::sequence()
                ->push(['error' => ['message' => "This model's maximum context length is 16128 tokens"]], 400)
                ->push(['error' => ['message' => "This model's maximum context length is 16128 tokens"]], 400)
                ->push([
                    'choices' => [[
                        'message' => ['content' => '{"ok":true}'],
                        'finish_reason' => 'stop',
                    ]],
                ], 200),
        ]);

        $json = app(OpenAiCompatibleClient::class)->chatJson([
            ['role' => 'user', 'content' => 'ping'],
        ]);

        $this->assertTrue($json['ok'] ?? false);
        Http::assertSentCount(3);
        $payloads = Http::recorded()
            ->map(fn (array $pair): int => (int) ($pair[0]->data()['max_tokens'] ?? 0))
            ->all();
        $this->assertLessThan($payloads[0], $payloads[array_key_last($payloads)] ?? 0);
    }

    public function test_page_images_keep_output_token_budget(): void
    {
        Http::fake([
            'openrouter.ai/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => '{"products":[]}'],
                    'finish_reason' => 'stop',
                ]],
                'model' => 'openai/gpt-4o',
            ], 200),
        ]);

        app(OpenAiCompatibleClient::class)->chatWithPageImages(
            'Odczytaj cennik',
            [['bytes' => str_repeat("\xFF", 400_000), 'mime' => 'image/jpeg', 'label' => 'Strona 1']]
        );

        Http::assertSent(function (Request $request): bool {
            return (int) ($request->data()['max_tokens'] ?? 0) === 1500;
        });
    }
}
