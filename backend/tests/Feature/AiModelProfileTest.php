<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AiModelProfileTest extends TestCase
{
    use RefreshDatabase;

    private function seedMainConfig(): void
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

    /**
     * @param  list<array<string, mixed>>  $profiles
     */
    private function saveProfiles(array $profiles): void
    {
        AiSetting::query()->first()?->forceFill(['model_profiles' => $profiles])->save();
    }

    private static function jsonReply(string $content): array
    {
        return ['choices' => [['message' => ['content' => $content]]]];
    }

    public function test_profile_sends_product_search_to_its_own_endpoint_and_model(): void
    {
        $this->seedMainConfig();
        $this->saveProfiles([[
            'id' => 'fast',
            'name' => 'OpenRouter szybki',
            'base_url' => 'https://openrouter.ai/api/v1',
            'model' => 'google/gemini-flash',
            'api_key' => 'sk-or-profile-123',
            'timeout_seconds' => null,
            'temperature' => null,
            'tasks' => [AiTask::ProductSearch->value],
        ]]);

        Http::fake([
            '*' => Http::response(self::jsonReply('{"ok":true}')),
        ]);

        app(OpenAiCompatibleClient::class)->chatJson(
            [['role' => 'user', 'content' => 'test']],
            null,
            null,
            null,
            AiTask::ProductSearch
        );

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
                && $request['model'] === 'google/gemini-flash'
                && $request->hasHeader('Authorization', 'Bearer sk-or-profile-123');
        });
    }

    public function test_task_without_profile_stays_on_the_main_config(): void
    {
        $this->seedMainConfig();
        $this->saveProfiles([[
            'id' => 'fast',
            'name' => 'OpenRouter szybki',
            'base_url' => 'https://openrouter.ai/api/v1',
            'model' => 'google/gemini-flash',
            'api_key' => 'sk-or-profile-123',
            'tasks' => [AiTask::ProductSearch->value],
        ]]);

        Http::fake(['*' => Http::response(self::jsonReply('{"ok":true}'))]);

        app(OpenAiCompatibleClient::class)->chatJson(
            [['role' => 'user', 'content' => 'test']],
            null,
            null,
            null,
            AiTask::TenderDocument
        );

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:26872/v1/chat/completions'
            && $request['model'] === 'qwen38-27b-fast');
    }

    public function test_profile_with_only_a_model_reuses_main_url_and_key(): void
    {
        $this->seedMainConfig();
        $this->saveProfiles([[
            'id' => 'cheap',
            'name' => 'Ten sam serwer, mniejszy model',
            'base_url' => null,
            'model' => 'qwen38-7b',
            'api_key' => null,
            'tasks' => [AiTask::SpreadsheetExtract->value],
        ]]);

        Http::fake(['*' => Http::response(self::jsonReply('{"ok":true}'))]);

        app(OpenAiCompatibleClient::class)->chatJson(
            [['role' => 'user', 'content' => 'test']],
            null,
            null,
            null,
            AiTask::SpreadsheetExtract
        );

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:26872/v1/chat/completions'
            && $request['model'] === 'qwen38-7b'
            && $request->hasHeader('Authorization', 'Bearer local-key-1234567890'));
    }

    public function test_dead_profile_falls_back_to_the_main_config(): void
    {
        $this->seedMainConfig();
        $this->saveProfiles([[
            'id' => 'fast',
            'name' => 'OpenRouter bez środków',
            'base_url' => 'https://openrouter.ai/api/v1',
            'model' => 'google/gemini-flash',
            'api_key' => 'sk-or-profile-123',
            'tasks' => [AiTask::ProductSearch->value],
        ]]);

        Http::fake([
            'openrouter.ai/*' => Http::response(['error' => ['message' => 'Insufficient credits']], 402),
            '*' => Http::response(self::jsonReply('{"ok":true}')),
        ]);

        $result = app(OpenAiCompatibleClient::class)->chatJson(
            [['role' => 'user', 'content' => 'test']],
            null,
            null,
            null,
            AiTask::ProductSearch
        );

        $this->assertSame(['ok' => true], $result);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://127.0.0.1:26872/v1/chat/completions');
    }

    public function test_a_task_cannot_be_claimed_by_two_profiles(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->seedMainConfig();

        $this->putJson('/api/ai-settings', [
            'model_profiles' => [
                ['id' => 'a', 'name' => 'Pierwszy', 'model' => 'model-a', 'tasks' => ['product_search', 'tender_match']],
                ['id' => 'b', 'name' => 'Drugi', 'model' => 'model-b', 'tasks' => ['tender_match', 'web_search']],
            ],
        ])->assertOk()
            ->assertJsonPath('model_profiles.0.tasks', ['product_search', 'tender_match'])
            ->assertJsonPath('model_profiles.1.tasks', ['web_search']);

        $profile = app(AiSettingsService::class)->profileForTask(AiTask::TenderMatch);
        $this->assertSame('model-a', $profile['model']);
    }

    public function test_profile_key_survives_a_save_that_sends_back_the_mask(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->seedMainConfig();

        $this->putJson('/api/ai-settings', [
            'model_profiles' => [[
                'id' => 'fast',
                'name' => 'OpenRouter',
                'base_url' => 'https://openrouter.ai/api/v1',
                'model' => 'google/gemini-flash',
                'api_key' => 'sk-or-secret-9876543210',
                'tasks' => ['product_search'],
            ]],
        ])->assertOk()
            ->assertJsonPath('model_profiles.0.has_api_key', true)
            ->assertJsonMissingPath('model_profiles.0.api_key');

        // Drugi zapis idzie z maską — dokładnie tak, jak zrobi to formularz.
        $this->putJson('/api/ai-settings', [
            'model_profiles' => [[
                'id' => 'fast',
                'name' => 'OpenRouter',
                'base_url' => 'https://openrouter.ai/api/v1',
                'model' => 'google/gemini-2-flash',
                'api_key' => 'sk-***3210',
                'tasks' => ['product_search'],
            ]],
        ])->assertOk()
            ->assertJsonPath('model_profiles.0.model', 'google/gemini-2-flash')
            ->assertJsonPath('model_profiles.0.has_api_key', true);

        $this->assertSame(
            'sk-or-secret-9876543210',
            app(AiSettingsService::class)->profileForTask(AiTask::ProductSearch)['api_key']
        );
    }

    public function test_enrichment_model_still_applies_until_a_profile_takes_over(): void
    {
        $this->seedMainConfig();
        AiSetting::query()->first()?->forceFill(['enrichment_model' => 'qwen38-3b-tani'])->save();

        Http::fake(['*' => Http::response(self::jsonReply('{"ok":true}'))]);

        app(OpenAiCompatibleClient::class)->chatJsonEnrichment([['role' => 'user', 'content' => 'x']]);
        Http::assertSent(fn (Request $request): bool => $request['model'] === 'qwen38-3b-tani');

        $this->saveProfiles([[
            'id' => 'vision',
            'name' => 'Chmura do opisów',
            'base_url' => 'https://openrouter.ai/api/v1',
            'model' => 'google/gemini-flash',
            'api_key' => 'sk-or-profile-123',
            'tasks' => [AiTask::Enrichment->value],
        ]]);

        app(OpenAiCompatibleClient::class)->chatJsonEnrichment([['role' => 'user', 'content' => 'x']]);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $request['model'] === 'google/gemini-flash');
    }
}
