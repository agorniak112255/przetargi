<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\RequirementUnderstanding;
use App\Services\Ai\AiTask;
use App\Services\ProductAiSearchService;
use App\Services\Search\RequirementUnderstandingStore;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\FakeSearchLlm;
use Tests\TestCase;

/**
 * Zrozumienie wymagania zapisujemy raz i czytamy przy każdym kolejnym przebiegu, więc odpowiedź zastępczego modelu
 * zostawała na stałe. Na produkcji 22–24.09.2026 część zrozumień przyszła z konfiguracji głównej po awarii profilu
 * i nie dało się ich odróżnić od pozostałych. Zapis niesie model, dostawcę i profil, a odpowiedź z zejścia na
 * konfigurację główną służy tylko bieżącemu wyszukiwaniu — niezależnie od przyczyny zejścia.
 */
final class RequirementUnderstandingOriginTest extends TestCase
{
    use RefreshDatabase;

    /** Adres IP, żeby test nie zależał od DNS (nierozwiązywalny host główny wyłącza zejście). */
    private const MAIN = 'http://10.0.0.5/api/v1';

    private const LOCAL = 'http://192.168.1.59:4000/v1';

    private const GLOVES = 'Rękawice ochronne z lateksu naturalnego, flokowane, długość 300 mm, grubość 0,45 mm, AQL 1,5, do kontaktu z żywnością';

    private const MASK = 'Półmaska filtrująca FFP3 z zaworem wydechowym, zgodna z EN 149:2001+A1:2009, pakowana pojedynczo';

    public function test_understanding_from_the_profile_is_stored_with_model_provider_and_profile(): void
    {
        $this->settings();
        $this->modelAnswers(fn (Request $r): PromiseInterface => self::understood('rękawice lateksowe flokowane', 'qwen36-35b-a3b', 'Makora'));

        $intent = app(ProductAiSearchService::class)->understandRequirement(self::GLOVES);

        $this->assertSame('rękawice lateksowe flokowane', $intent['needed']);
        $row = RequirementUnderstanding::query()->sole();
        $this->assertSame('qwen36-35b-a3b', $row->model);
        $this->assertSame('Makora', $row->provider);
        $this->assertSame('Profil 1', $row->profile);
    }

    /** Wyszukiwarka bez własnego profilu legalnie działa na konfiguracji głównej — to nie jest zejście. */
    public function test_understanding_from_main_config_without_a_task_profile_is_stored(): void
    {
        $this->settings(withProfile: false);
        $this->modelAnswers(fn (Request $r): PromiseInterface => self::understood('rękawice lateksowe flokowane', 'deepseek/deepseek-v4-flash-0731', 'DeepInfra'));

        app(ProductAiSearchService::class)->understandRequirement(self::GLOVES);

        $row = RequirementUnderstanding::query()->sole();
        $this->assertSame('deepseek/deepseek-v4-flash-0731', $row->model);
        $this->assertSame('DeepInfra', $row->provider);
        $this->assertSame('konfiguracja główna', $row->profile);
    }

    public function test_understanding_after_profile_failure_serves_this_search_but_is_not_stored(): void
    {
        $this->settings();
        $this->modelAnswers(fn (Request $r): PromiseInterface => self::toMain($r)
            ? self::understood('rękawice gospodarcze', 'deepseek/deepseek-v4-flash-0731', 'DeepInfra')
            : Http::response(['error' => ['message' => 'Cannot connect to host']], 500));

        $intent = app(ProductAiSearchService::class)->understandRequirement(self::GLOVES);

        $this->assertSame('rękawice gospodarcze', $intent['needed'], 'bieżące wyszukiwanie korzysta z odpowiedzi konfiguracji głównej');
        $this->assertSame(0, RequirementUnderstanding::query()->count(), 'zrozumienie z zejścia na konfigurację główną zostało zapisane na stałe');
    }

    /** Treść przyszła z profilu, ale naprawę JSON zrobiła konfiguracja główna — to już nie jest odpowiedź profilu. */
    public function test_json_repair_on_main_config_is_not_stored(): void
    {
        $this->settings();
        $this->modelAnswers(function (Request $r): PromiseInterface {
            $repair = str_contains((string) ($r['messages'][0]['content'] ?? ''), 'Napraw odpowiedź');
            if (self::toMain($r)) {
                return self::understood('rękawice lateksowe flokowane', 'deepseek/deepseek-v4-flash-0731', 'DeepInfra');
            }

            return $repair
                ? Http::response(['error' => ['message' => 'Cannot connect to host']], 500)
                : self::reply('rękawice lateksowe, flokowane — bez JSON-a', 'qwen36-35b-a3b', 'Makora');
        });

        $intent = app(ProductAiSearchService::class)->understandRequirement(self::GLOVES);

        $this->assertSame('rękawice lateksowe flokowane', $intent['needed'], 'fixture: odpowiedź po naprawie JSON');
        Http::assertSent(fn (Request $r): bool => self::toMain($r));
        $this->assertSame(0, RequirementUnderstanding::query()->count());
    }

    public function test_wave_stores_only_understandings_answered_by_the_profile(): void
    {
        $this->settings();
        $this->modelAnswers(function (Request $r): PromiseInterface {
            $user = (string) ($r['messages'][1]['content'] ?? '');
            if (self::toMain($r)) {
                return self::understood('półmaska FFP3 z zaworem', 'deepseek/deepseek-v4-flash-0731', 'DeepInfra');
            }
            if (str_contains($user, self::MASK)) {
                throw new ConnectionException('cURL error 7: Failed to connect');
            }

            return self::understood('rękawice lateksowe flokowane', 'qwen36-35b-a3b', 'Makora');
        });

        app(ProductAiSearchService::class)->searchMany([self::GLOVES, self::MASK], 5, false, AiTask::ProductSearch);

        Http::assertSent(fn (Request $r): bool => self::toMain($r));
        $store = app(RequirementUnderstandingStore::class);
        $this->assertNull($store->get(self::MASK, ProductAiSearchService::UNDERSTAND_PROMPT_VERSION), 'zrozumienie z konfiguracji głównej zapisane na stałe');
        $this->assertSame('rękawice lateksowe flokowane', $store->get(self::GLOVES, ProductAiSearchService::UNDERSTAND_PROMPT_VERSION)['needed'] ?? null);
        $row = RequirementUnderstanding::query()->sole();
        $this->assertSame(['qwen36-35b-a3b', 'Makora', 'Profil 1'], [$row->model, $row->provider, $row->profile]);
    }

    /** Fala z jednym wymaganiem idzie bez puli, przez pojedyncze zapytanie. */
    public function test_single_requirement_wave_after_profile_failure_is_not_stored(): void
    {
        $this->settings();
        $this->modelAnswers(fn (Request $r): PromiseInterface => self::toMain($r)
            ? self::understood('półmaska FFP3 z zaworem', 'deepseek/deepseek-v4-flash-0731', 'DeepInfra')
            : Http::response(['error' => ['message' => 'Cannot connect to host']], 500));

        app(ProductAiSearchService::class)->searchMany([self::MASK], 5, false, AiTask::ProductSearch);

        Http::assertSent(fn (Request $r): bool => self::toMain($r));
        $this->assertSame(0, RequirementUnderstanding::query()->count());
    }

    /** Ucięta odpowiedź profilu, ponowienie z konfiguracji głównej — treść jest już z zastępczego modelu. */
    public function test_truncated_answer_retried_on_main_config_is_not_stored(): void
    {
        $this->settings();
        $profileCalls = 0;
        $this->modelAnswers(function (Request $r) use (&$profileCalls): PromiseInterface {
            if (self::toMain($r)) {
                return self::understood('rękawice lateksowe flokowane', 'deepseek/deepseek-v4-flash-0731', 'DeepInfra');
            }

            return ++$profileCalls === 1
                ? Http::response(['model' => 'qwen36-35b-a3b', 'choices' => [['message' => ['content' => '{"needed":"rękawice lat'], 'finish_reason' => 'length']]])
                : Http::response(['error' => ['message' => 'Cannot connect to host']], 500);
        });

        $intent = app(ProductAiSearchService::class)->understandRequirement(self::GLOVES);

        $this->assertSame('rękawice lateksowe flokowane', $intent['needed'], 'fixture: odpowiedź z ponowienia na konfiguracji głównej');
        $this->assertSame(0, RequirementUnderstanding::query()->count());
    }

    /** Profil bez klucza — cała paczka idzie na konfigurację główną, zanim padnie pierwsze zapytanie. */
    public function test_wave_moved_to_main_config_as_a_whole_stores_nothing(): void
    {
        $this->settings(profileKey: null);
        $this->modelAnswers(fn (Request $r): PromiseInterface => self::understood('rękawice lateksowe flokowane', 'deepseek/deepseek-v4-flash-0731', 'DeepInfra'));

        app(ProductAiSearchService::class)->searchMany([self::GLOVES, self::MASK], 5, false, AiTask::ProductSearch);

        Http::assertSent(fn (Request $r): bool => self::toMain($r));
        $this->assertSame(0, RequirementUnderstanding::query()->count());
    }

    public function test_store_writes_origin_and_skips_fallback_answers(): void
    {
        $store = app(RequirementUnderstandingStore::class);
        $answer = ['needed' => 'rękawice lateksowe', 'constraints' => []];

        $store->put(self::MASK, 'v-test', $answer, ['model' => 'deepseek', 'provider' => 'DeepInfra', 'profile' => 'konfiguracja główna', 'fallback' => true]);
        $store->put(self::GLOVES, 'v-test', $answer);

        $this->assertNull($store->get(self::MASK, 'v-test'));
        $row = RequirementUnderstanding::query()->sole();
        $this->assertSame([null, null, null], [$row->model, $row->provider, $row->profile], 'bez znanego pochodzenia (atrapa klienta) zapis bez niego');
    }

    public function test_origin_columns_migration_is_reversible(): void
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_09_25_010000_add_origin_to_requirement_understandings_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumns('requirement_understandings', ['model', 'provider', 'profile']));
        $migration->up();
        $this->assertTrue(Schema::hasColumns('requirement_understandings', ['model', 'provider', 'profile']));
    }

    private function settings(bool $withProfile = true, ?string $profileKey = 'sk-local-123'): void
    {
        $row = AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => self::MAIN,
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'deepseek/deepseek-v4-flash-0731',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
        ]);
        if ($withProfile) {
            $row->forceFill(['model_profiles' => [[
                'id' => 'local',
                'name' => 'Profil 1',
                'base_url' => self::LOCAL,
                'model' => 'qwen36-35b-a3b',
                'api_key' => $profileKey,
                'tasks' => [AiTask::ProductSearch->value],
            ]]])->save();
        }
    }

    /**
     * Odpowiedź na „zrozum” z $understand; każde inne zapytanie modelu (ocena, przepisanie) dostaje pusty ranking,
     * a zapytania spoza chat/completions — 404.
     *
     * @param  callable(Request): PromiseInterface  $understand
     */
    private function modelAnswers(callable $understand): void
    {
        Http::fake(function (Request $request) use ($understand): PromiseInterface {
            if (! str_ends_with($request->url(), '/chat/completions')) {
                return Http::response([], 404);
            }
            $messages = is_array($request['messages'] ?? null) ? $request['messages'] : [];
            $kind = FakeSearchLlm::kind($messages);
            if ($kind === FakeSearchLlm::KIND_RANK || $kind === FakeSearchLlm::KIND_REWRITE) {
                return self::reply('{"matches":[]}', 'qwen36-35b-a3b', null);
            }

            return $understand($request);
        });
    }

    private static function toMain(Request $request): bool
    {
        return str_starts_with($request->url(), self::MAIN);
    }

    private static function understood(string $needed, string $model, ?string $provider): PromiseInterface
    {
        return self::reply((string) json_encode([
            'needed' => $needed,
            'search_steps' => [$needed],
            'manufacturer' => null,
            'model_name' => null,
            'search_phrases' => [$needed],
            'constraints' => [],
        ], JSON_UNESCAPED_UNICODE), $model, $provider);
    }

    private static function reply(string $content, string $model, ?string $provider): PromiseInterface
    {
        return Http::response([
            'model' => $model,
            'provider' => $provider,
            'choices' => [['message' => ['content' => $content], 'finish_reason' => 'stop']],
        ]);
    }
}
