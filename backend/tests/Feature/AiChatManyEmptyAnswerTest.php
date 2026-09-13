<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Log produkcji (przetarg 1): seria „API AI zwróciło pustą odpowiedź” z DeepSeek V4 Flash przy
 * równoległym rankingu. Model myślał mimo profilu „bez myślenia” i zjadał limit tokenów; zapytania
 * równoległe nie miały ponowienia z większym limitem, które ma pojedyncze zapytanie.
 */
final class AiChatManyEmptyAnswerTest extends TestCase
{
    use RefreshDatabase;

    private const MODEL = 'deepseek/deepseek-v4-flash-0731';

    private function seedSettings(string $reasoningEffort = 'auto'): void
    {
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://openrouter.ai/api/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => self::MODEL,
            'timeout_seconds' => 60,
            'temperature' => 0.1,
        ]);
        AiSetting::query()->first()?->forceFill(['reasoning_effort' => $reasoningEffort])->save();
    }

    public function test_pooled_empty_answer_cut_by_token_limit_is_retried_with_bigger_budget(): void
    {
        $this->seedSettings();
        $attempts = [];
        Http::fake(function (Request $request) use (&$attempts) {
            $prompt = (string) ($request['messages'][0]['content'] ?? '');
            $attempts[$prompt] = ($attempts[$prompt] ?? 0) + 1;
            if ($attempts[$prompt] === 1) {
                return Http::response([
                    'choices' => [['message' => ['content' => ''], 'finish_reason' => 'length']],
                    'usage' => ['completion_tokens' => 2500],
                ]);
            }

            return Http::response([
                'choices' => [['message' => ['content' => '{"matches":[{"id":'.strlen($prompt).',"score":80}]}'], 'finish_reason' => 'stop']],
            ]);
        });

        $out = app(OpenAiCompatibleClient::class)->chatJsonMany(
            [
                [['role' => 'user', 'content' => 'ranking A']],
                [['role' => 'user', 'content' => 'ranking BB']],
            ],
            2500,
            AiTask::TenderMatch,
            16,
        );

        $this->assertSame(9, $out[0]['matches'][0]['id'] ?? null, 'pierwsze zapytanie ma wynik po ponowieniu');
        $this->assertSame(10, $out[1]['matches'][0]['id'] ?? null, 'drugie zapytanie ma wynik po ponowieniu');
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => (int) ($request['max_tokens'] ?? 0) > 2500);
    }

    public function test_empty_answer_error_names_finish_reason_and_tokens(): void
    {
        $this->seedSettings();
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => ''], 'finish_reason' => 'stop']],
            'usage' => ['completion_tokens' => 0],
            'model' => self::MODEL,
        ])]);

        $rows = app(OpenAiCompatibleClient::class)->chatMany([
            [['role' => 'user', 'content' => 'a']],
            [['role' => 'user', 'content' => 'b']],
        ], true, null, AiTask::TenderMatch);

        $this->assertFalse($rows[0]['ok']);
        $this->assertStringContainsString('finish_reason=stop', (string) $rows[0]['error']);
        $this->assertStringContainsString('completion_tokens=0', (string) $rows[0]['error']);
        Http::assertSentCount(2);
    }

    public function test_none_effort_disables_thinking_for_openrouter_deepseek(): void
    {
        $this->seedSettings('none');
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => '{"ok":true}'], 'finish_reason' => 'stop']],
        ])]);

        app(OpenAiCompatibleClient::class)->chatJsonMany([
            [['role' => 'user', 'content' => 'a']],
            [['role' => 'user', 'content' => 'b']],
        ], 900, AiTask::TenderMatch, 16);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request['model'] === self::MODEL
                && ($data['reasoning']['enabled'] ?? null) === false
                && ! array_key_exists('reasoning_effort', $data);
        });
    }

    public function test_auto_effort_keeps_deepseek_request_without_reasoning_switch(): void
    {
        $this->seedSettings('auto');
        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['content' => '{"ok":true}'], 'finish_reason' => 'stop']],
        ])]);

        app(OpenAiCompatibleClient::class)->chatJson([['role' => 'user', 'content' => 'a']]);

        Http::assertSent(fn (Request $request): bool => ! array_key_exists('reasoning', $request->data()));
    }
}
