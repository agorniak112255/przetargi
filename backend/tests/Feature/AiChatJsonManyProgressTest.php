<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

/**
 * Licznik „Trwa dopasowanie AI” rośnie z każdą odpowiedzią modelu z puli równoległych zapytań,
 * a nie dopiero po całej paczce — okno stało na „0 / 15” do samego końca.
 */
final class AiChatJsonManyProgressTest extends TestCase
{
    use RefreshDatabase;

    public function test_callback_reports_each_answer_not_only_whole_chunk(): void
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
        // każda odpowiedź z puli podbija licznik o jeden
        $this->assertSame([[1, 3], [2, 3], [3, 3]], $calls);
        Http::assertSentCount(3);
    }

    /**
     * Limit „Ile zapytań AI naraz” to przesuwane okno: po pierwszej odpowiedzi od razu rusza trzecie zapytanie,
     * zanim wróci drugie. Paczki po 2 czekały na całą paczkę (a, b), dopiero potem wysyłały c.
     */
    public function test_next_request_starts_as_soon_as_any_answer_returns(): void
    {
        $this->aiSettings();
        $events = [];
        // Http::fake czeka na obietnicę od razu przy wysłaniu, więc odpowiedź „w locie” podstawia warstwa pośrednia:
        // obietnica rozwiązuje się dopiero, gdy pula na nią czeka — jak prawdziwe zapytanie w curl_multi.
        Http::globalMiddleware(static function (callable $handler) use (&$events): callable {
            return static function (RequestInterface $request) use (&$events): PromiseInterface {
                $content = (string) data_get(json_decode((string) $request->getBody(), true), 'messages.0.content');
                $events[] = 'start '.$content;
                $promise = new Promise(static function () use (&$promise, &$events, $content): void {
                    $events[] = 'answer '.$content;
                    $promise->resolve(new Psr7Response(200, [], (string) json_encode([
                        'choices' => [['message' => ['content' => '{"q":"'.$content.'"}']]],
                    ])));
                });

                return $promise;
            };
        });

        $out = app(OpenAiCompatibleClient::class)->chatJsonMany([
            [['role' => 'user', 'content' => 'a']],
            [['role' => 'user', 'content' => 'b']],
            [['role' => 'user', 'content' => 'c']],
        ], null, AiTask::ProductSearch, 2);

        $this->assertSame([['q' => 'a'], ['q' => 'b'], ['q' => 'c']], $out);
        $this->assertSame(['start a', 'start b', 'answer a', 'start c', 'answer b', 'answer c'], $events);
    }

    private function aiSettings(): void
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
    }
}
