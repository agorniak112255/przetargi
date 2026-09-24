<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Services\Ai\AiServedProviderTally;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Log produkcji 24.09.2026: lokalny vLLM za bramką nie odpowiadał („Cannot connect to host …:8001”,
 * HTTP 500 z bramki). Pojedyncze zapytanie schodziło na konfigurację główną, ale pula nie — ocena kart
 * padała w całej fali i zapytanie klienta pokazywało „brak w katalogu” przy wyrobach, które są w bazie.
 */
final class AiChatManyProfileFallbackTest extends TestCase
{
    use RefreshDatabase;

    /** Adres IP, żeby test nie zależał od DNS (nierozwiązywalny host główny wyłącza fallback). */
    private const MAIN = 'http://10.0.0.5/api/v1';

    private const LOCAL = 'http://192.168.1.59:4000/v1';

    protected function setUp(): void
    {
        parent::setUp();
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => self::MAIN,
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'deepseek/deepseek-v4-flash-0731',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
        ]);
        AiSetting::query()->first()?->forceFill(['model_profiles' => [[
            'id' => 'local',
            'name' => 'Profil 1',
            'base_url' => self::LOCAL,
            'model' => 'qwen36-35b-a3b',
            'api_key' => 'sk-local-123',
            'tasks' => [AiTask::ProductSearch->value],
        ]]])->save();
    }

    /** @return list<array<string, mixed>> */
    private function rankTwo(): array
    {
        return app(OpenAiCompatibleClient::class)->chatJsonMany(
            [
                [['role' => 'user', 'content' => 'a']],
                [['role' => 'user', 'content' => 'b']],
            ],
            null,
            AiTask::ProductSearch,
            16,
        );
    }

    private static function answer(string $prompt): PromiseInterface
    {
        return Http::response([
            'choices' => [['message' => ['content' => '{"prompt":"'.$prompt.'"}'], 'finish_reason' => 'stop']],
        ]);
    }

    public function test_pooled_requests_go_to_main_config_when_local_model_server_is_down(): void
    {
        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), self::LOCAL)) {
                return Http::response(['error' => ['message' => 'litellm.InternalServerError: Hosted_vllmException - Cannot connect to host']], 500);
            }

            return self::answer((string) ($request['messages'][0]['content'] ?? ''));
        });
        $tally = app(AiServedProviderTally::class);
        $tally->reset();

        $out = $this->rankTwo();

        $this->assertSame([['prompt' => 'a'], ['prompt' => 'b']], $out);
        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), self::MAIN) && $r['model'] === 'deepseek/deepseek-v4-flash-0731');
        $this->assertSame(1, $tally->snapshot()['profile_fallbacks']);
    }

    public function test_only_unanswered_requests_are_repeated_on_main_config(): void
    {
        Http::fake(function (Request $request) {
            $prompt = (string) ($request['messages'][0]['content'] ?? '');
            if (str_starts_with($request->url(), self::LOCAL)) {
                if ($prompt === 'a') {
                    throw new ConnectionException('cURL error 7: Failed to connect');
                }

                return Http::response([
                    'choices' => [['message' => ['content' => '{"local":"'.$prompt.'"}'], 'finish_reason' => 'stop']],
                ]);
            }

            return self::answer($prompt);
        });

        $out = $this->rankTwo();

        $this->assertSame(['prompt' => 'a'], $out[0], 'brak połączenia — odpowiedź z konfiguracji głównej');
        $this->assertSame(['local' => 'b'], $out[1], 'odpowiedź lokalnego modelu zostaje');
        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), self::MAIN) && $r['messages'][0]['content'] === 'a');
        Http::assertNotSent(fn (Request $r): bool => str_starts_with($r->url(), self::MAIN) && $r['messages'][0]['content'] === 'b');
    }

    /** Limit zapytań to nie awaria serwera — ocena kart zostaje przy profilu, jak dotąd. */
    public function test_rate_limit_on_profile_does_not_switch_to_main_config(): void
    {
        Http::fake(function (Request $request) {
            if (str_starts_with($request->url(), self::LOCAL)) {
                return Http::response(['error' => ['message' => 'Rate limit exceeded']], 429, ['Retry-After' => '0']);
            }

            return self::answer((string) ($request['messages'][0]['content'] ?? ''));
        });

        $out = $this->rankTwo();

        $this->assertSame([[], []], $out);
        Http::assertNotSent(fn (Request $r): bool => str_starts_with($r->url(), self::MAIN));
    }

    public function test_when_main_config_also_fails_profile_error_stays(): void
    {
        Http::fake(fn () => Http::response(['error' => ['message' => 'upstream down']], 500));

        $rows = app(OpenAiCompatibleClient::class)->chatMany([
            [['role' => 'user', 'content' => 'a']],
            [['role' => 'user', 'content' => 'b']],
        ], true, null, AiTask::ProductSearch);

        $this->assertFalse($rows[0]['ok']);
        $this->assertStringContainsString('Profil 1', (string) $rows[0]['error']);
        Http::assertSent(fn (Request $r): bool => str_starts_with($r->url(), self::MAIN));
    }
}
