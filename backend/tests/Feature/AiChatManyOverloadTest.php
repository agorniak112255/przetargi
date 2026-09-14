<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Services\Ai\AiServedProviderTally;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Log produkcji (przetarg 1, 15:13–15:14): „Limit zapytań modelu AI (HTTP 429)” w równoległym
 * rankingu — pojedyncze zapytanie ponawia 429 i luzuje przypiętego dostawcę, pula od razu się poddawała
 * i pozycje dostawały „Model nie odpowiedział”.
 */
final class AiChatManyOverloadTest extends TestCase
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
            'model' => 'deepseek/deepseek-v4-flash-0731',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
        ]);
        AiSetting::query()->first()?->forceFill(['model_profiles' => [[
            'id' => 'fast',
            'name' => 'Profil 2',
            'base_url' => 'https://openrouter.ai/api/v1',
            'model' => 'deepseek/deepseek-v4-flash-0731',
            'openrouter_provider' => 'makora',
            'api_key' => 'sk-or-profile-123',
            'tasks' => [AiTask::ProductSearch->value],
        ]]])->save();
    }

    /**
     * @param  array<string, int>  $rejectTimes  prompt → ile pierwszych prób dostaje 429
     * @param  array<string, list<mixed>>  $providers  prompt → pole provider z każdej próby
     */
    private function fakeOpenRouter(array $rejectTimes, array &$providers): void
    {
        $attempts = [];
        Http::fake(function (Request $request) use ($rejectTimes, &$attempts, &$providers) {
            $prompt = (string) ($request['messages'][0]['content'] ?? '');
            $attempts[$prompt] = ($attempts[$prompt] ?? 0) + 1;
            $providers[$prompt][] = $request->data()['provider'] ?? null;
            if ($attempts[$prompt] <= ($rejectTimes[$prompt] ?? 0)) {
                return Http::response(['error' => ['message' => 'Rate limit exceeded']], 429);
            }

            $pin = $request->data()['provider'] ?? null;

            return Http::response([
                // OpenRouter podaje, kto odpowiedział; po poluzowaniu przypięcia może to być inny dostawca modelu
                'provider' => is_array($pin) && ($pin['allow_fallbacks'] ?? false) === true ? 'DeepInfra' : 'Makora',
                'choices' => [['message' => ['content' => '{"prompt":"'.$prompt.'"}'], 'finish_reason' => 'stop']],
            ]);
        });
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

    public function test_pooled_429_is_retried_only_for_rejected_request(): void
    {
        $providers = [];
        $this->fakeOpenRouter(['a' => 1], $providers);

        $out = $this->rankTwo();

        $this->assertSame([['prompt' => 'a'], ['prompt' => 'b']], $out);
        $this->assertCount(2, $providers['a'], 'odrzucone zapytanie ponowione raz');
        $this->assertCount(1, $providers['b'], 'przyjęte zapytanie nie jest wysyłane drugi raz');
    }

    public function test_second_retry_relaxes_pinned_provider(): void
    {
        $providers = [];
        $this->fakeOpenRouter(['a' => 2], $providers);

        $out = $this->rankTwo();

        $this->assertSame(['prompt' => 'a'], $out[0]);
        $this->assertCount(3, $providers['a']);
        $this->assertSame(['only' => ['makora'], 'allow_fallbacks' => false], $providers['a'][0]);
        $this->assertSame(['only' => ['makora'], 'allow_fallbacks' => false], $providers['a'][1]);
        $this->assertSame(['order' => ['makora'], 'allow_fallbacks' => true], $providers['a'][2]);
    }

    /**
     * Pomiar przetargu 1 (raport 20260914_131814): jeden przebieg tym samym kodem dał 5 złych kart z oceną 95 — pomiar
     * musi widzieć, który dostawca modelu odpowiadał i czy przypięcie było luzowane.
     */
    public function test_tally_records_served_providers_and_relaxed_pin(): void
    {
        $providers = [];
        $this->fakeOpenRouter(['a' => 2], $providers);
        $tally = app(AiServedProviderTally::class);
        $tally->reset();

        $this->rankTwo();

        $snapshot = $tally->snapshot();
        $this->assertEquals(['Makora' => 1, 'DeepInfra' => 1], $snapshot['served'], 'b od przypiętego, a po poluzowaniu od innego');
        $this->assertSame(1, $snapshot['relaxed_pins']);
        $this->assertSame(['DeepInfra', 'Makora'], $tally->lastBatch(), 'dostawca każdej odpowiedzi w kolejności zapytań');

        app(OpenAiCompatibleClient::class)->chatJsonMany([[['role' => 'user', 'content' => 'c']]], null, AiTask::ProductSearch, 16);
        $this->assertSame(['Makora'], $tally->lastBatch(), 'pojedyncze zapytanie idzie bez puli, dostawca też zapisany');
    }

    public function test_exhausted_429_stops_after_rate_limit_retries(): void
    {
        $providers = [];
        $this->fakeOpenRouter(['a' => 99], $providers);

        $out = $this->rankTwo();

        $this->assertSame([], $out[0], 'po wyczerpaniu ponowień wynik jak dotąd: brak odpowiedzi');
        $this->assertSame(['prompt' => 'b'], $out[1]);
        $this->assertCount(3, $providers['a'], 'pierwsza próba + 2 ponowienia przy 429');
        $this->assertSame([null, 'Makora'], app(AiServedProviderTally::class)->lastBatch(), 'brak odpowiedzi = null na swojej pozycji');
    }
}
