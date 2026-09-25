<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Services\Ai\AiRateLimitedException;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 24.09.2026: przy serii HTTP 429 od przypiętego dostawcy pojedyncze zapytanie wyszukiwarki schodziło na konfigurację
 * główną (OpenRouter bez przypięcia), a zastępczy model źle dobierał karty. Pula zostawała przy profilu już wcześniej;
 * teraz także pojedyncze zapytanie, ostatnia jednoelementowa paczka fali i limit, po którym przyszło 5xx albo pusta
 * odpowiedź. Zejście przy awarii serwera modelu i limit w pozostałych zadaniach — bez zmian.
 */
final class AiRateLimitStaysOnProfileTest extends TestCase
{
    use RefreshDatabase;

    /** Adres IP, żeby test nie zależał od DNS (nierozwiązywalny host główny wyłącza zejście). */
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
            'tasks' => [AiTask::ProductSearch->value, AiTask::Enrichment->value],
        ]]])->save();
    }

    public function test_single_product_search_request_keeps_the_rate_limit_error(): void
    {
        $this->profileAnswers(fn (): PromiseInterface => self::rateLimited());

        try {
            $this->client()->chatJson([['role' => 'user', 'content' => 'a']], null, null, null, AiTask::ProductSearch);
            $this->fail('oczekiwano błędu limitu zapytań');
        } catch (AiRateLimitedException $e) {
            $this->assertStringContainsString('429', $e->getMessage());
        }
        Http::assertNotSent(fn (Request $r): bool => self::toMain($r));
    }

    /** Opisy produktów dalej biorą odpowiedź z konfiguracji głównej — handlowiec nie czeka na limit. */
    public function test_other_tasks_still_go_to_main_config_on_rate_limit(): void
    {
        $this->profileAnswers(fn (): PromiseInterface => self::rateLimited());

        $out = $this->client()->chatJson([['role' => 'user', 'content' => 'a']], null, null, null, AiTask::Enrichment);

        $this->assertSame(['prompt' => 'a'], $out);
        Http::assertSent(fn (Request $r): bool => self::toMain($r));
    }

    public function test_one_request_batch_stays_on_profile(): void
    {
        $this->profileAnswers(fn (): PromiseInterface => self::rateLimited());

        $out = $this->client()->chatJsonMany([[['role' => 'user', 'content' => 'a']]], null, AiTask::ProductSearch, 16);

        $this->assertSame([[]], $out);
        Http::assertNotSent(fn (Request $r): bool => self::toMain($r));
    }

    /**
     * Fala większa niż maxConcurrent (przesuwane okno w jednej puli; wcześniej paczki, z ostatnią jednoelementową
     * bez puli) — limit przy żadnym zapytaniu nie schodzi na konfigurację główną.
     */
    public function test_last_single_request_chunk_of_a_wave_stays_on_profile(): void
    {
        $this->profileAnswers(fn (): PromiseInterface => self::rateLimited());

        $out = $this->client()->chatJsonMany([
            [['role' => 'user', 'content' => 'a']],
            [['role' => 'user', 'content' => 'b']],
            [['role' => 'user', 'content' => 'c']],
        ], null, AiTask::ProductSearch, 2);

        $this->assertSame([[], [], []], $out);
        Http::assertNotSent(fn (Request $r): bool => self::toMain($r));
    }

    public function test_rate_limit_followed_by_overload_stays_on_profile(): void
    {
        $calls = 0;
        $this->profileAnswers(function () use (&$calls): PromiseInterface {
            return ++$calls === 1
                ? self::rateLimited()
                : Http::response(['error' => ['message' => 'server is busy']], 503);
        });

        try {
            $this->client()->chatJson([['role' => 'user', 'content' => 'a']], null, null, null, AiTask::ProductSearch);
            $this->fail('oczekiwano błędu limitu zapytań');
        } catch (AiRateLimitedException) {
        }
        $this->assertGreaterThan(1, $calls, 'fixture: po limicie przyszło przeciążenie');
        Http::assertNotSent(fn (Request $r): bool => self::toMain($r));
    }

    public function test_rate_limit_on_the_last_overload_retry_stays_on_profile(): void
    {
        $calls = 0;
        $this->profileAnswers(function () use (&$calls): PromiseInterface {
            return ++$calls <= 5
                ? Http::response(['error' => ['message' => 'server is busy']], 503)
                : self::rateLimited();
        });

        try {
            $this->client()->chatJson([['role' => 'user', 'content' => 'a']], null, null, null, AiTask::ProductSearch);
            $this->fail('oczekiwano błędu limitu zapytań');
        } catch (AiRateLimitedException) {
        }
        $this->assertSame(6, $calls, 'fixture: pięć przeciążeń, ostatnie ponowienie dostało 429');
        Http::assertNotSent(fn (Request $r): bool => self::toMain($r));
    }

    public function test_pooled_overload_then_rate_limit_then_server_error_stays_on_profile(): void
    {
        $calls = [];
        $this->profileAnswers(function (string $prompt) use (&$calls): PromiseInterface {
            $calls[$prompt] = ($calls[$prompt] ?? 0) + 1;

            return match ($calls[$prompt]) {
                1 => Http::response(['error' => ['message' => 'server is busy']], 503),
                2 => self::rateLimited(),
                default => Http::response(['error' => ['message' => 'upstream error']], 500),
            };
        });

        $out = $this->client()->chatJsonMany([
            [['role' => 'user', 'content' => 'a']],
            [['role' => 'user', 'content' => 'b']],
        ], null, AiTask::ProductSearch, 16);

        $this->assertSame([[], []], $out);
        $this->assertSame(['a' => 3, 'b' => 3], $calls, 'fixture: przeciążenie, limit w ponowieniu, potem 500');
        Http::assertNotSent(fn (Request $r): bool => self::toMain($r));
    }

    public function test_pooled_rate_limit_followed_by_server_error_stays_on_profile(): void
    {
        $calls = [];
        $this->profileAnswers(function (string $prompt) use (&$calls): PromiseInterface {
            $calls[$prompt] = ($calls[$prompt] ?? 0) + 1;

            return $calls[$prompt] === 1
                ? self::rateLimited()
                : Http::response(['error' => ['message' => 'upstream error']], 500);
        });

        $out = $this->client()->chatJsonMany([
            [['role' => 'user', 'content' => 'a']],
            [['role' => 'user', 'content' => 'b']],
        ], null, AiTask::ProductSearch, 16);

        $this->assertSame([[], []], $out);
        $this->assertSame(['a' => 2, 'b' => 2], $calls, 'fixture: po limicie ponowienie dostało 500');
        Http::assertNotSent(fn (Request $r): bool => self::toMain($r));
    }

    public function test_rate_limit_after_truncated_empty_answer_stays_on_profile(): void
    {
        $calls = 0;
        $this->profileAnswers(function () use (&$calls): PromiseInterface {
            return ++$calls === 1
                ? Http::response(['choices' => [['message' => ['content' => ''], 'finish_reason' => 'length']]])
                : self::rateLimited();
        });

        try {
            $this->client()->chatJson([['role' => 'user', 'content' => 'a']], null, 900, null, AiTask::ProductSearch);
            $this->fail('oczekiwano błędu limitu zapytań');
        } catch (AiRateLimitedException) {
        }
        $this->assertSame(2, $calls, 'fixture: pusta odpowiedź ucięta limitem tokenów, potem ponowienie z limitem zapytań');
        Http::assertNotSent(fn (Request $r): bool => self::toMain($r));
    }

    /** Krótszy prompt „zrozum” po limicie trafiłby w ten sam limit i znowu czekał na dwa ponowienia. */
    public function test_understanding_after_rate_limit_does_not_retry_with_short_prompt(): void
    {
        $this->profileAnswers(fn (): PromiseInterface => self::rateLimited());

        $intent = app(ProductAiSearchService::class)->understandRequirement(
            'Rękawice ochronne z lateksu naturalnego, flokowane, długość 300 mm, AQL 1,5, do kontaktu z żywnością'
        );

        $this->assertNotSame('', $intent['needed'], 'intencja lokalna po awarii modelu');
        $this->assertCount(3, Http::recorded(), 'jedno zapytanie „zrozum” z dwoma ponowieniami 429, bez krótszego promptu');
        Http::assertNotSent(fn (Request $r): bool => self::toMain($r));
    }

    /** Serwer modelu leży (HTTP 500) — to nie limit, wyszukiwarka dalej bierze odpowiedź z konfiguracji głównej. */
    public function test_server_error_on_single_request_still_goes_to_main_config(): void
    {
        $this->profileAnswers(fn (): PromiseInterface => Http::response(['error' => ['message' => 'Cannot connect to host']], 500));

        $out = $this->client()->chatJson([['role' => 'user', 'content' => 'a']], null, null, null, AiTask::ProductSearch);

        $this->assertSame(['prompt' => 'a'], $out);
        Http::assertSent(fn (Request $r): bool => self::toMain($r));
    }

    /** @param  callable(string): PromiseInterface  $profile  odpowiedź profilu na zapytanie o danej treści */
    private function profileAnswers(callable $profile): void
    {
        Http::fake(function (Request $request) use ($profile): PromiseInterface {
            $prompt = (string) ($request['messages'][0]['content'] ?? '');

            return self::toMain($request) ? self::answer($prompt) : $profile($prompt);
        });
    }

    private function client(): OpenAiCompatibleClient
    {
        return app(OpenAiCompatibleClient::class);
    }

    private static function toMain(Request $request): bool
    {
        return str_starts_with($request->url(), self::MAIN);
    }

    private static function rateLimited(): PromiseInterface
    {
        return Http::response(['error' => ['message' => 'Rate limit exceeded']], 429, ['Retry-After' => '0']);
    }

    private static function answer(string $prompt): PromiseInterface
    {
        return Http::response([
            'choices' => [['message' => ['content' => '{"prompt":"'.$prompt.'"}'], 'finish_reason' => 'stop']],
        ]);
    }
}
