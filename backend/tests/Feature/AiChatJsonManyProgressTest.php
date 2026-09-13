<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Pasek „Trwa dopasowanie AI” liczy odpowiedzi modelu po każdej podpaczce równoległych zapytań,
 * a nie dopiero po całej liście.
 */
final class AiChatJsonManyProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_callback_reports_answered_count_after_each_chunk(): void
    {
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'http://127.0.0.1:26872/v1',
            'api_key' => 'local-key-1234567890',
            'model' => 'qwen38-27b-fast',
            'timeout_seconds' => 90,
            'temperature' => 0.1,
        ]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{"ok":true}']]]])]);

        $calls = [];
        $messages = [
            [['role' => 'user', 'content' => 'a']],
            [['role' => 'user', 'content' => 'b']],
            [['role' => 'user', 'content' => 'c']],
        ];
        $out = app(OpenAiCompatibleClient::class)->chatJsonMany(
            $messages,
            null,
            AiTask::ProductSearch,
            2,
            static function (int $done, int $total) use (&$calls): void {
                $calls[] = [$done, $total];
            },
        );

        $this->assertSame([['ok' => true], ['ok' => true], ['ok' => true]], $out);
        $this->assertSame([[2, 3], [3, 3]], $calls);
    }
}
