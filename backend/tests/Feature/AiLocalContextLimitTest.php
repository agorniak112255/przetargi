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
 * 21.09.2026 lokalny Qwen na Sparku miał --max-model-len 65536, a klient zakładał 16128 (dawny vLLM) i przycinał
 * prompt rankingu przetargu (13,7 tys. znaków) do 947 znaków — bez reguł oceny i formatu odpowiedzi. Model
 * odpowiadał wtedy `{"id":109,"score":95}` i 13 z 15 pozycji zostało bez oceny modelu. Zapytanie, które nie mieści
 * się w założonym limicie, sprawdza faktyczny limit serwera (/v1/models → max_model_len).
 */
final class AiLocalContextLimitTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'http://192.168.1.59:8000/v1';

    private const MODEL = 'qwen36-35b-a3b';

    private function seedSettings(string $baseUrl = self::BASE): void
    {
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => $baseUrl,
            'api_key' => 'sk-test-key-1234567890',
            'model' => self::MODEL,
            'timeout_seconds' => 60,
            'temperature' => 0.1,
        ]);
    }

    /** Prompt rankingu jak z przetargu: reguły i format w wiadomości systemowej, karty w długiej wiadomości użytkownika. */
    private static function rankingMessages(): array
    {
        return [
            ['role' => 'system', 'content' => str_repeat('Reguła oceny karty. ', 400).'JSON: {"matches":[{"id":1,"score":0-100,"reason":"uzasadnienie","missing_key":[]}]}.'],
            ['role' => 'user', 'content' => "Wymaganie: rękawice antyprzecięciowe\n\nKarty katalogu:\n".str_repeat('{"id":1,"name":"Rękawice","description":"opis karty"} ', 450)],
        ];
    }

    private function fakeServer(?int $maxModelLen): void
    {
        Http::fake(function (Request $request) use ($maxModelLen) {
            if (str_ends_with($request->url(), '/models')) {
                return $maxModelLen === null
                    ? Http::response(['error' => 'not found'], 404)
                    : Http::response(['object' => 'list', 'data' => [['id' => self::MODEL, 'object' => 'model', 'max_model_len' => $maxModelLen]]]);
            }

            return Http::response(['choices' => [['message' => ['content' => '{"matches":[]}'], 'finish_reason' => 'stop']]]);
        });
    }

    /** @return list<Request> */
    private static function chatRequests(): array
    {
        return Http::recorded(fn (Request $r): bool => str_ends_with($r->url(), '/chat/completions'))->map(fn (array $pair) => $pair[0])->values()->all();
    }

    public function test_prompt_over_the_assumed_limit_is_sent_whole_when_the_server_serves_a_bigger_context(): void
    {
        $this->seedSettings();
        $this->fakeServer(65536);
        $messages = self::rankingMessages();

        app(OpenAiCompatibleClient::class)->chatJsonMany([$messages, $messages], 2500, AiTask::ProductSearch, 4);

        $requests = self::chatRequests();
        $this->assertCount(2, $requests);
        foreach ($requests as $request) {
            $this->assertSame($messages[0]['content'], $request['messages'][0]['content']);
            $this->assertSame($messages[1]['content'], $request['messages'][1]['content']);
            $this->assertSame(2500, $request['max_tokens']);
        }
        // limit serwera zapamiętany — jedno pytanie o /models na całą paczkę
        $this->assertCount(1, Http::recorded(fn (Request $r): bool => str_ends_with($r->url(), '/models')));
    }

    public function test_without_the_served_limit_the_prompt_is_trimmed_as_before(): void
    {
        $this->seedSettings();
        $this->fakeServer(null);
        $messages = self::rankingMessages();

        app(OpenAiCompatibleClient::class)->chatJsonMany([$messages, $messages], 2500, AiTask::ProductSearch, 4);

        $request = self::chatRequests()[0];
        $sent = (string) $request['messages'][0]['content'].(string) $request['messages'][1]['content'];
        $this->assertLessThan(mb_strlen($messages[0]['content'].$messages[1]['content']), mb_strlen($sent));
    }

    public function test_small_prompt_does_not_ask_the_server_for_its_limit(): void
    {
        $this->seedSettings();
        $this->fakeServer(65536);

        app(OpenAiCompatibleClient::class)->chatJsonMany(
            [[['role' => 'user', 'content' => 'a']], [['role' => 'user', 'content' => 'b']]],
            900,
            AiTask::ProductSearch,
            4,
        );

        Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/models'));
    }

    public function test_cloud_endpoint_never_asks_for_the_limit(): void
    {
        $this->seedSettings('https://openrouter.ai/api/v1');
        $this->fakeServer(65536);
        $messages = self::rankingMessages();

        app(OpenAiCompatibleClient::class)->chatJsonMany([$messages, $messages], 2500, AiTask::ProductSearch, 4);

        Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/models'));
        $this->assertSame($messages[1]['content'], self::chatRequests()[0]['messages'][1]['content']);
    }
}
