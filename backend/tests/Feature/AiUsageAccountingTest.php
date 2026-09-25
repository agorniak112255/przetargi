<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Services\Ai\AiServedProviderTally;
use App\Services\Ai\AiTask;
use App\Services\Ai\AiUsage;
use App\Services\Ai\OpenAiCompatibleClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Koszt pozycji wyszukiwania to tokeny WSZYSTKICH odpowiedzi modelu, nie tylko tej, z której wzięto treść: pusta
 * odpowiedź przez limit tokenów (model myślał), ucięty albo zły JSON, naprawa JSON, ponowienia i zejście na konfigurację
 * główną. Dotąd usage było tylko w wyniku chat() i tylko z ostatniej odpowiedzi.
 */
final class AiUsageAccountingTest extends TestCase
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

    public function test_from_payload_reads_reasoning_cached_tokens_and_cost(): void
    {
        $usage = AiUsage::fromPayload([
            'usage' => [
                'prompt_tokens' => 1200,
                'completion_tokens' => 300,
                'completion_tokens_details' => ['reasoning_tokens' => 250],
                'prompt_tokens_details' => ['cached_tokens' => 1024],
                'cost' => 0.00042,
            ],
        ]);

        $this->assertSame([
            'prompt_tokens' => 1200,
            'completion_tokens' => 300,
            'reasoning_tokens' => 250,
            'cached_tokens' => 1024,
            'cost' => 0.00042,
            'calls' => 1,
            'failed_calls' => 0,
        ], $usage);
        $this->assertNull(AiUsage::fromPayload(['choices' => []]), 'odpowiedź bez usage');
        $this->assertNull(AiUsage::fromPayload(null), 'treść spoza JSON');
        $this->assertSame(0.0, AiUsage::fromPayload(['usage' => ['prompt_tokens' => 5, 'cost' => 0]])['cost']);
        $this->assertNull(AiUsage::fromPayload(['usage' => ['prompt_tokens' => 5]])['cost'], 'brak kosztu to nie zero');
    }

    public function test_usage_arithmetic(): void
    {
        $a = AiUsage::fromPayload(['usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10, 'cost' => 0.5]]);
        $b = AiUsage::fromPayload(['usage' => ['prompt_tokens' => 50, 'completion_tokens' => 5]]);

        $sum = AiUsage::add(AiUsage::add($a, $b), AiUsage::failed());
        $this->assertSame(150, $sum['prompt_tokens']);
        $this->assertSame(15, $sum['completion_tokens']);
        $this->assertSame(0.5, $sum['cost']);
        $this->assertSame(2, $sum['calls']);
        $this->assertSame(1, $sum['failed_calls']);
        $this->assertNull(AiUsage::add(null, $b)['cost'], 'koszt null, gdy obie strony bez kosztu');
        $this->assertSame(AiUsage::empty(), AiUsage::add(null, null));

        $diff = AiUsage::diff($sum, $a);
        $this->assertSame(['prompt_tokens' => 50, 'completion_tokens' => 5, 'calls' => 1, 'failed_calls' => 1], array_intersect_key($diff, array_flip(['prompt_tokens', 'completion_tokens', 'calls', 'failed_calls'])));
        $this->assertSame(0.0, $diff['cost']);
        $this->assertSame(0, AiUsage::diff(AiUsage::empty(), $a)['prompt_tokens'], 'licznik wyzerowany po znaczniku — bez ujemnych liczb');

        $this->assertTrue(AiUsage::isEmpty(null));
        $this->assertTrue(AiUsage::isEmpty(AiUsage::empty()));
        $this->assertFalse(AiUsage::isEmpty(AiUsage::failed()));
        $this->assertSame([
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'reasoning_tokens' => 0,
            'cached_tokens' => 0,
            'cost' => null,
            'calls' => 0,
            'failed_calls' => 1,
        ], AiUsage::failed(), 'ten sam kształt i kolejność pól co empty()');
    }

    public function test_tally_counts_marks_and_resets(): void
    {
        $tally = app(AiServedProviderTally::class);
        $tally->addUsage(AiUsage::fromPayload(['usage' => ['prompt_tokens' => 10]]));
        $mark = $tally->usageMark();
        $tally->addUsage(null);
        $tally->addUsage(AiUsage::failed());

        $this->assertSame(10, $tally->usageTotal()['prompt_tokens']);
        $this->assertSame(['calls' => 0, 'failed_calls' => 1], array_intersect_key($tally->usageSince($mark), array_flip(['calls', 'failed_calls'])));

        $tally->recordBatch(['x'], [null], [AiUsage::failed()]);
        $this->assertSame([AiUsage::failed()], $tally->lastBatchUsage());
        $tally->recordBatch(['x']);
        $this->assertSame([], $tally->lastBatchUsage(), 'stare wywołanie z dwoma parametrami');
        $tally->recordBatch(['x'], [null], [null]);
        $tally->forgetBatch();
        $this->assertSame([], $tally->lastBatchUsage());
        $tally->recordBatch(['x'], [null], [null]);
        $tally->reset();
        $this->assertSame([], $tally->lastBatchUsage());
        $this->assertSame(AiUsage::empty(), $tally->usageTotal());
    }

    /** Model myślał i zjadł limit — pusta odpowiedź przez `length` kosztowała tokeny, choć treść wzięto z ponowienia. */
    public function test_single_request_counts_empty_length_answer_and_its_retry(): void
    {
        $this->fakeSequence([
            self::reply('', 'length', ['prompt_tokens' => 1000, 'completion_tokens' => 900, 'completion_tokens_details' => ['reasoning_tokens' => 900]]),
            self::reply('{"ok":true}', 'stop', ['prompt_tokens' => 1000, 'completion_tokens' => 50]),
        ]);

        $raw = $this->client()->chat([['role' => 'user', 'content' => 'a']], null, true, ['max_tokens' => 900], AiTask::ProductSearch);

        $this->assertSame(['prompt_tokens' => 1000, 'completion_tokens' => 50], $raw['usage'], 'wynik chat() bez zmian — usage ostatniej odpowiedzi');
        $this->assertUsage(['prompt_tokens' => 2000, 'completion_tokens' => 950, 'reasoning_tokens' => 900, 'calls' => 2, 'failed_calls' => 0], $this->tally()->usageTotal());
        Http::assertSentCount(2);
    }

    /** Bramka bez pola `usage`: wywołanie się odbyło — liczy się jako wywołanie z zerowymi tokenami (przegląd 25.09). */
    public function test_answer_without_usage_counts_as_call_with_zero_tokens(): void
    {
        $this->fakeSequence([
            Http::response(['choices' => [['message' => ['content' => '{"ok":true}'], 'finish_reason' => 'stop']]]),
        ]);

        $this->client()->chat([['role' => 'user', 'content' => 'a']], null, true, null, AiTask::ProductSearch);

        $this->assertUsage(['prompt_tokens' => 0, 'completion_tokens' => 0, 'calls' => 1, 'failed_calls' => 0], $this->tally()->usageTotal());
    }

    public function test_empty_answer_that_throws_still_counts_its_tokens(): void
    {
        $this->fakeSequence([
            self::reply('', 'length', ['prompt_tokens' => 700, 'completion_tokens' => 6000]),
        ]);

        try {
            $this->client()->chat([['role' => 'user', 'content' => 'a']]);
            $this->fail('oczekiwano błędu pustej odpowiedzi');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('pustą odpowiedź', $e->getMessage());
        }
        $this->assertUsage(['prompt_tokens' => 700, 'completion_tokens' => 6000, 'calls' => 1, 'failed_calls' => 0], $this->tally()->usageTotal());
    }

    /** Pula: ponowienia (pusta przez `length`, przeciążenie 503) sumują się na pozycji zapytania, nie na nowym indeksie. */
    public function test_pool_sums_every_attempt_per_original_index(): void
    {
        $calls = [];
        Http::fake(function (Request $request) use (&$calls): PromiseInterface {
            $prompt = (string) ($request['messages'][0]['content'] ?? '');
            $n = $calls[$prompt] = ($calls[$prompt] ?? 0) + 1;

            return match ([$prompt, $n]) {
                ['a', 1] => self::reply('{"id":"a"}', 'stop', ['prompt_tokens' => 100, 'completion_tokens' => 10]),
                ['b', 1] => self::reply('', 'length', ['prompt_tokens' => 200, 'completion_tokens' => 2500, 'completion_tokens_details' => ['reasoning_tokens' => 2500]]),
                ['b', 2] => self::reply('{"id":"b"}', 'stop', ['prompt_tokens' => 210, 'completion_tokens' => 40]),
                ['c', 1] => Http::response(['error' => ['message' => 'server is busy']], 503),
                ['c', 2] => self::reply('{"id":"c"}', 'stop', ['prompt_tokens' => 300, 'completion_tokens' => 20]),
                default => Http::response(['error' => ['message' => 'nieoczekiwane zapytanie']], 500),
            };
        });

        $out = $this->client()->chatJsonMany([
            [['role' => 'user', 'content' => 'a']],
            [['role' => 'user', 'content' => 'b']],
            [['role' => 'user', 'content' => 'c']],
        ], 2500, AiTask::ProductSearch, 16);

        $this->assertSame([['id' => 'a'], ['id' => 'b'], ['id' => 'c']], $out);
        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 2], $calls);
        $batch = $this->tally()->lastBatchUsage();
        $this->assertCount(3, $batch);
        $this->assertUsage(['prompt_tokens' => 100, 'completion_tokens' => 10, 'calls' => 1, 'failed_calls' => 0], $batch[0]);
        $this->assertUsage(['prompt_tokens' => 410, 'completion_tokens' => 2540, 'reasoning_tokens' => 2500, 'calls' => 2, 'failed_calls' => 0], $batch[1]);
        $this->assertUsage(['prompt_tokens' => 300, 'completion_tokens' => 20, 'calls' => 1, 'failed_calls' => 1], $batch[2]);
        $this->assertUsage(['prompt_tokens' => 810, 'completion_tokens' => 2570, 'reasoning_tokens' => 2500, 'calls' => 4, 'failed_calls' => 1], $this->tally()->usageTotal());
    }

    /** Wiersz bez treści (ok=false) też niesie koszt — pusta odpowiedź i odrzucenie 422 z ponowieniem. */
    public function test_pool_rows_without_answer_carry_usage(): void
    {
        Http::fake(function (Request $request): PromiseInterface {
            return ($request['messages'][0]['content'] ?? '') === 'a'
                ? self::reply('', 'stop', ['prompt_tokens' => 90, 'completion_tokens' => 0])
                : Http::response(['error' => ['message' => 'bad request']], 422);
        });

        $rows = $this->client()->chatMany([
            [['role' => 'user', 'content' => 'a']],
            [['role' => 'user', 'content' => 'b']],
        ]);

        $this->assertFalse($rows[0]['ok']);
        $this->assertFalse($rows[1]['ok']);
        $this->assertUsage(['prompt_tokens' => 90, 'calls' => 1, 'failed_calls' => 0], $rows[0]['usage']);
        $this->assertUsage(['prompt_tokens' => 0, 'calls' => 0, 'failed_calls' => 2], $rows[1]['usage']);
    }

    /** Serwer modelu na profilu leży (5xx) — koszt zapytania to nieudana próba na profilu i odpowiedź konfiguracji głównej. */
    public function test_pool_server_error_on_profile_sums_profile_and_main_attempts(): void
    {
        Http::fake(function (Request $request): PromiseInterface {
            $prompt = (string) ($request['messages'][0]['content'] ?? '');
            if (str_starts_with($request->url(), self::MAIN)) {
                return self::reply('{"main":"'.$prompt.'"}', 'stop', ['prompt_tokens' => 400, 'completion_tokens' => 40, 'cost' => 0.002]);
            }

            return $prompt === 'a'
                ? Http::response(['error' => ['message' => 'upstream error']], 500)
                : self::reply('{"local":"'.$prompt.'"}', 'stop', ['prompt_tokens' => 50, 'completion_tokens' => 5]);
        });

        $out = $this->client()->chatJsonMany([
            [['role' => 'user', 'content' => 'a']],
            [['role' => 'user', 'content' => 'b']],
        ], null, AiTask::ProductSearch, 16);

        $this->assertSame([['main' => 'a'], ['local' => 'b']], $out);
        $batch = $this->tally()->lastBatchUsage();
        $this->assertUsage(['prompt_tokens' => 400, 'completion_tokens' => 40, 'cost' => 0.002, 'calls' => 1, 'failed_calls' => 1], $batch[0]);
        $this->assertUsage(['prompt_tokens' => 50, 'completion_tokens' => 5, 'cost' => null, 'calls' => 1, 'failed_calls' => 0], $batch[1]);
        $this->assertSame(1, $this->tally()->profileFallbacks());
    }

    public function test_single_server_error_on_profile_counts_failed_attempt_and_main_answer(): void
    {
        Http::fake(function (Request $request): PromiseInterface {
            return str_starts_with($request->url(), self::MAIN)
                ? self::reply('{"ok":true}', 'stop', ['prompt_tokens' => 400, 'completion_tokens' => 40])
                : Http::response(['error' => ['message' => 'upstream error']], 500);
        });

        $out = $this->client()->chatJson([['role' => 'user', 'content' => 'a']], null, null, null, AiTask::ProductSearch);

        $this->assertSame(['ok' => true], $out);
        $this->assertUsage(['prompt_tokens' => 400, 'completion_tokens' => 40, 'calls' => 1, 'failed_calls' => 1], $this->tally()->usageTotal());
    }

    /** Limit zapytań: dostawca nie podaje tokenów — liczą się tylko próby (pierwsza i dwa ponowienia). */
    public function test_rate_limit_counts_attempts_without_tokens(): void
    {
        Http::fake(fn (): PromiseInterface => Http::response(['error' => ['message' => 'Rate limit exceeded']], 429, ['Retry-After' => '0']));

        $out = $this->client()->chatJsonMany([[['role' => 'user', 'content' => 'a']]], null, AiTask::ProductSearch, 16);

        $this->assertSame([[]], $out);
        Http::assertSentCount(3);
        $expected = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'calls' => 0, 'failed_calls' => 3];
        $this->assertUsage($expected, $this->tally()->usageTotal());
        $this->assertUsage($expected, $this->tally()->lastBatchUsage()[0], 'jednoelementowa paczka idzie przez chat() — koszt z licznika wokół wywołania');
    }

    public function test_json_repair_counts_both_answers(): void
    {
        $this->fakeSequence([
            self::reply('Oto wynik bez JSON', 'stop', ['prompt_tokens' => 100, 'completion_tokens' => 20]),
            self::reply('{"ok":true}', 'stop', ['prompt_tokens' => 60, 'completion_tokens' => 8]),
        ]);

        $out = $this->client()->chatJson([['role' => 'user', 'content' => 'a']]);

        $this->assertSame(['ok' => true], $out);
        $this->assertUsage(['prompt_tokens' => 160, 'completion_tokens' => 28, 'calls' => 2, 'failed_calls' => 0], $this->tally()->usageTotal());
    }

    /** Ucięty JSON, ponowienie i nieudana naprawa kończą się wyjątkiem — tokeny wszystkich trzech odpowiedzi zostają. */
    public function test_truncated_retry_and_failed_repair_count_all_answers_before_exception(): void
    {
        $this->fakeSequence([
            self::reply('{"note": "abc', 'length', ['prompt_tokens' => 100, 'completion_tokens' => 900]),
            self::reply('dalej bez JSON', 'stop', ['prompt_tokens' => 80, 'completion_tokens' => 10]),
            self::reply('też nie JSON', 'stop', ['prompt_tokens' => 50, 'completion_tokens' => 5]),
        ]);

        try {
            $this->client()->chatJson([['role' => 'user', 'content' => 'a']], null, 900);
            $this->fail('oczekiwano błędu JSON');
        } catch (RuntimeException) {
        }
        Http::assertSentCount(3);
        $this->assertUsage(['prompt_tokens' => 230, 'completion_tokens' => 915, 'calls' => 3, 'failed_calls' => 0], $this->tally()->usageTotal());
    }

    /**
     * @param  array<string, mixed>  $expected
     * @param  array<string, mixed>|null  $actual
     */
    private function assertUsage(array $expected, ?array $actual, string $message = ''): void
    {
        $this->assertNotNull($actual, $message);
        $this->assertSame($expected, array_intersect_key($actual, $expected), $message);
    }

    /** @param  list<PromiseInterface>  $responses  odpowiedzi po kolei, niezależnie od adresu */
    private function fakeSequence(array $responses): void
    {
        $n = 0;
        Http::fake(function () use ($responses, &$n): PromiseInterface {
            return $responses[$n++] ?? Http::response(['error' => ['message' => 'nieoczekiwane zapytanie']], 500);
        });
    }

    /** @param  array<string, mixed>  $usage */
    private static function reply(string $content, string $finish, array $usage): PromiseInterface
    {
        return Http::response([
            'choices' => [['message' => ['content' => $content], 'finish_reason' => $finish]],
            'usage' => $usage,
        ]);
    }

    private function client(): OpenAiCompatibleClient
    {
        return app(OpenAiCompatibleClient::class);
    }

    private function tally(): AiServedProviderTally
    {
        return app(AiServedProviderTally::class);
    }
}
