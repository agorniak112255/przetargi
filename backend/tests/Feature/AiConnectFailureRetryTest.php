<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Log produkcji 22–24.09.2026: 24 z 43 zejść wyszukiwarki na konfigurację główną to HTTP 500 z bramki LiteLLM
 * „Cannot connect to host …:8000 [Connect call failed]” — jeden z dwóch węzłów vLLM leżał, a bramka kieruje
 * zapytania na zmianę do obu. Takie zapytanie nie dotarło do modelu, więc jedna powtórka na tym samym profilu nic nie
 * dubluje i zwykle trafia w zdrowy węzeł, zamiast oddać ocenę zastępczemu modelowi. Timeout (model mógł liczyć)
 * i 504 bez powtórki, jak dotąd. Tylko wyszukiwarka — pozostałe zadania bez zmian.
 */
final class AiConnectFailureRetryTest extends TestCase
{
    use RefreshDatabase;

    /** Adres IP, żeby test nie zależał od DNS (nierozwiązywalny host główny wyłącza zejście). */
    private const MAIN = 'http://10.0.0.5/api/v1';

    private const LOCAL = 'http://192.168.1.59:4000/v1';

    private const GATEWAY_DOWN = "litellm.InternalServerError: InternalServerError: Hosted_vllmException - Cannot connect to host 192.168.1.61:8000 ssl:default [Connect call failed ('192.168.1.61', 8000)]. Received Model Group=qwen36-35b-a3b";

    /** @var array<string, int> */
    private array $profileCalls = [];

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
            'timeout_seconds' => 60,
            'tasks' => [AiTask::ProductSearch->value, AiTask::Enrichment->value],
        ]]])->save();
    }

    public function test_single_request_repeats_once_when_gateway_cannot_reach_the_model(): void
    {
        $this->profileFailsFirst(fn (): PromiseInterface => self::gatewayDown());

        $out = $this->single(AiTask::ProductSearch);

        $this->assertSame(['local' => 'a'], $out);
        $this->assertSame(['a' => 2], $this->profileCalls);
        Http::assertNotSent(fn (Request $r): bool => self::toMain($r));
    }

    public function test_single_request_repeats_once_when_connection_is_refused(): void
    {
        $this->profileFailsFirst(fn (Request $r): PromiseInterface => (Http::failedConnection('cURL error 7: Failed to connect to 192.168.1.59 port 4000: Connection refused'))($r));

        $out = $this->single(AiTask::ProductSearch);

        $this->assertSame(['local' => 'a'], $out);
        Http::assertNotSent(fn (Request $r): bool => self::toMain($r));
    }

    public function test_single_request_repeats_once_on_bad_gateway(): void
    {
        $this->profileFailsFirst(fn (): PromiseInterface => Http::response('<html>502 Bad Gateway</html>', 502));

        $this->assertSame(['local' => 'a'], $this->single(AiTask::ProductSearch));
    }

    public function test_single_request_goes_to_main_config_when_repeat_also_fails(): void
    {
        $this->profileFailsFirst(fn (): PromiseInterface => self::gatewayDown(), failures: 2);

        $out = $this->single(AiTask::ProductSearch);

        $this->assertSame(['main' => 'a'], $out, 'po jednej powtórce jak dotąd — konfiguracja główna');
        $this->assertSame(['a' => 2], $this->profileCalls, 'dokładnie jedna powtórka');
    }

    /** @return iterable<string, array{string}> */
    public static function notRepeatedFailures(): iterable
    {
        yield 'HTTP 504' => ['504'];
        yield 'timeout zapytania' => ['timeout'];
        yield '500 bez błędu połączenia' => ['500'];
    }

    /** Model mógł liczyć — powtórka podwoiłaby obciążenie (i ewentualne powtórki bramki). */
    #[DataProvider('notRepeatedFailures')]
    public function test_timeouts_and_other_server_errors_are_not_repeated(string $kind): void
    {
        $this->profileFailsFirst(fn (Request $r): PromiseInterface => match ($kind) {
            '504' => Http::response(['error' => ['message' => 'Gateway Timeout']], 504),
            'timeout' => (Http::failedConnection('cURL error 28: Operation timed out after 240001 milliseconds with 0 bytes received'))($r),
            default => Http::response(['error' => ['message' => 'litellm.InternalServerError: CUDA out of memory']], 500),
        });

        $this->assertSame(['main' => 'a'], $this->single(AiTask::ProductSearch));
        $this->assertSame(['a' => 1], $this->profileCalls, 'bez powtórki');
    }

    public function test_other_tasks_are_not_repeated(): void
    {
        $this->profileFailsFirst(fn (): PromiseInterface => self::gatewayDown());

        $this->assertSame(['main' => 'a'], $this->single(AiTask::Enrichment));
        $this->assertSame(['a' => 1], $this->profileCalls);
    }

    public function test_pooled_requests_repeat_once_only_those_that_did_not_reach_the_model(): void
    {
        $this->profileFailsFirst(function (Request $r): PromiseInterface {
            return self::prompt($r) === 'a'
                ? self::gatewayDown()
                : (Http::failedConnection('cURL error 7: Failed to connect to 192.168.1.59 port 4000: Connection refused'))($r);
        }, only: ['a', 'b']);

        $out = $this->pool(AiTask::ProductSearch, ['a', 'b', 'c']);

        $this->assertSame([['local' => 'a'], ['local' => 'b'], ['local' => 'c']], $out);
        $this->assertSame(['a' => 2, 'b' => 2, 'c' => 1], $this->profileCalls);
        Http::assertNotSent(fn (Request $r): bool => self::toMain($r));
    }

    /** Przeciążenie po powtórce ma zwykłą obsługę (odczekać, ponowić) — jak w pojedynczym zapytaniu. */
    public function test_pooled_overload_after_the_repeat_is_retried_as_usual(): void
    {
        $this->profileFailsFirst(fn (Request $r): PromiseInterface => $this->profileCalls[self::prompt($r)] === 1
            ? self::gatewayDown()
            : Http::response(['error' => ['message' => 'server is busy']], 503), failures: 2, only: ['a']);

        $out = $this->pool(AiTask::ProductSearch, ['a', 'b']);

        $this->assertSame([['local' => 'a'], ['local' => 'b']], $out);
        $this->assertSame(['a' => 3, 'b' => 1], $this->profileCalls);
        Http::assertNotSent(fn (Request $r): bool => self::toMain($r));
    }

    public function test_pooled_timeout_is_not_repeated_and_goes_to_main_config(): void
    {
        $this->profileFailsFirst(fn (): PromiseInterface => Http::response(['error' => ['message' => 'Gateway Timeout']], 504), only: ['a']);

        $out = $this->pool(AiTask::ProductSearch, ['a', 'b']);

        $this->assertSame([['main' => 'a'], ['local' => 'b']], $out);
        $this->assertSame(['a' => 1, 'b' => 1], $this->profileCalls);
    }

    public function test_pooled_requests_of_other_tasks_are_not_repeated(): void
    {
        $this->profileFailsFirst(fn (): PromiseInterface => self::gatewayDown(), only: ['a']);

        $out = $this->pool(AiTask::Enrichment, ['a', 'b']);

        $this->assertSame([['main' => 'a'], ['local' => 'b']], $out);
        $this->assertSame(['a' => 1, 'b' => 1], $this->profileCalls);
    }

    /**
     * Pula wyszukiwarki czeka na model tyle co pojedyncze zapytanie (co najmniej 240 s). Gęsty Qwen liczy ranking
     * 55–65 s, a pula przy 60 s zrywała działające zapytania i oddawała je konfiguracji głównej.
     */
    public function test_search_pool_waits_as_long_as_a_single_request(): void
    {
        $timeouts = [];
        Http::fake(function (Request $request, array $options) use (&$timeouts): PromiseInterface {
            $timeouts[] = $options['timeout'] ?? null;

            return self::answer('local', self::prompt($request));
        });

        $this->pool(AiTask::ProductSearch, ['a', 'b']);
        $search = $timeouts;
        $timeouts = [];
        $this->pool(AiTask::Enrichment, ['a', 'b']);

        $this->assertSame([240, 240], $search);
        $this->assertSame([60, 60], $timeouts, 'pozostałe zadania bez zmian');
    }

    /**
     * Profil odpowiada $failure na pierwsze $failures zapytań o każdej treści z $only (null = wszystkie), potem
     * odpowiedzią; konfiguracja główna zawsze odpowiada.
     *
     * @param  callable(Request): PromiseInterface  $failure
     * @param  list<string>|null  $only
     */
    private function profileFailsFirst(callable $failure, int $failures = 1, ?array $only = null): void
    {
        Http::fake(function (Request $request) use ($failure, $failures, $only): PromiseInterface {
            $prompt = self::prompt($request);
            if (self::toMain($request)) {
                return self::answer('main', $prompt);
            }
            $this->profileCalls[$prompt] = ($this->profileCalls[$prompt] ?? 0) + 1;
            if (($only === null || in_array($prompt, $only, true)) && $this->profileCalls[$prompt] <= $failures) {
                return $failure($request);
            }

            return self::answer('local', $prompt);
        });
    }

    /** @return array<string, mixed> */
    private function single(AiTask $task): array
    {
        return app(OpenAiCompatibleClient::class)->chatJson([['role' => 'user', 'content' => 'a']], null, null, null, $task);
    }

    /**
     * @param  list<string>  $prompts
     * @return list<array<string, mixed>>
     */
    private function pool(AiTask $task, array $prompts): array
    {
        return app(OpenAiCompatibleClient::class)->chatJsonMany(
            array_map(static fn (string $p): array => [['role' => 'user', 'content' => $p]], $prompts),
            null,
            $task,
            16,
        );
    }

    private static function prompt(Request $request): string
    {
        return (string) ($request['messages'][0]['content'] ?? '');
    }

    private static function toMain(Request $request): bool
    {
        return str_starts_with($request->url(), self::MAIN);
    }

    private static function gatewayDown(): PromiseInterface
    {
        return Http::response(['error' => ['message' => self::GATEWAY_DOWN]], 500);
    }

    private static function answer(string $who, string $prompt): PromiseInterface
    {
        return Http::response([
            'choices' => [['message' => ['content' => '{"'.$who.'":"'.$prompt.'"}'], 'finish_reason' => 'stop']],
        ]);
    }
}
