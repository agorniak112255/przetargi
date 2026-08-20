<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class AiSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_read_and_update_ai_settings(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->getJson('/api/ai-settings')
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('has_api_key', false);

        $this->putJson('/api/ai-settings', [
            'enabled' => true,
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
            'enrichment_model' => 'openai/gpt-4o-mini',
            'enrichment_use_large_model' => true,
            'api_key' => 'sk-test-key-1234567890',
            'timeout_seconds' => 60,
            'temperature' => 0.2,
            'match_concurrency' => 6,
            'vector_enabled' => true,
            'qdrant_url' => 'http://127.0.0.1:6333',
            'qdrant_collection' => 'products',
            'embedding_model' => 'text-embedding-3-small',
        ])->assertOk()
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('has_api_key', true)
            ->assertJsonPath('model', 'gpt-4o-mini')
            ->assertJsonPath('enrichment_model', 'openai/gpt-4o-mini')
            ->assertJsonPath('enrichment_use_large_model', true)
            ->assertJsonPath('vector_enabled', true)
            ->assertJsonPath('qdrant_url', 'http://127.0.0.1:6333')
            ->assertJsonPath('embedding_model', 'text-embedding-3-small')
            ->assertJsonPath('match_concurrency', 6);

        $this->putJson('/api/ai-settings', ['match_concurrency' => 9])
            ->assertStatus(422);

        $this->getJson('/api/ai-settings')
            ->assertOk()
            ->assertJsonPath('source', 'database')
            ->assertJsonPath('enrichment_model', 'openai/gpt-4o-mini')
            ->assertJsonPath('enrichment_use_large_model', true)
            ->assertJsonPath('vector_enabled', true)
            ->assertJsonMissingPath('api_key')
            ->assertJsonMissingPath('qdrant_api_key');
    }

    public function test_large_model_option_uses_main_model_for_enrichment(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://openrouter.ai/api/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'openai/gpt-4o',
            'enrichment_model' => 'deepseek/deepseek-v4-flash-0731',
            'enrichment_use_large_model' => true,
            'timeout_seconds' => 90,
            'temperature' => 0.1,
        ]);

        $settings = app(AiSettingsService::class);

        $this->assertTrue($settings->enrichmentUsesLargeModel());
        $this->assertSame('openai/gpt-4o', $settings->enrichmentModel());
    }

    public function test_update_model_keeps_existing_api_keys(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://openrouter.ai/api/v1',
            'api_key' => 'sk-openrouter-keep-me-123',
            'tavily_api_key' => 'tvly-keep-me-4567890',
            'model' => 'deepseek/deepseek-v4-flash-0731',
            'timeout_seconds' => 90,
            'temperature' => 0.1,
        ]);

        $this->putJson('/api/ai-settings', [
            'model' => 'openai/gpt-4o',
            'api_key' => '',
            'tavily_api_key' => 'sk-***xxxx',
        ])->assertOk()
            ->assertJsonPath('model', 'openai/gpt-4o')
            ->assertJsonPath('has_api_key', true)
            ->assertJsonPath('has_tavily_api_key', true);

        $row = AiSetting::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('sk-openrouter-keep-me-123', $row->api_key);
        $this->assertSame('tvly-keep-me-4567890', $row->tavily_api_key);
    }
}
