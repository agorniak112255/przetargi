<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Product;
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
            'reasoning_effort' => 'low',
            'match_concurrency' => 6,
            'search_engine' => 'duckduckgo',
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
            ->assertJsonPath('match_concurrency', 6)
            ->assertJsonPath('search_engine', 'duckduckgo')
            ->assertJsonPath('reasoning_effort', 'low');

        $this->putJson('/api/ai-settings', ['match_concurrency' => 100])
            ->assertOk()
            ->assertJsonPath('match_concurrency', 100);

        $this->putJson('/api/ai-settings', ['match_concurrency' => 101])
            ->assertStatus(422);

        $this->putJson('/api/ai-settings', ['enrichment_batch_limit' => 5000])
            ->assertOk()
            ->assertJsonPath('enrichment_batch_limit', 5000);

        $this->putJson('/api/ai-settings', ['enrichment_batch_limit' => 0])
            ->assertStatus(422);

        $this->putJson('/api/ai-settings', ['reasoning_effort' => 'high'])
            ->assertStatus(422);

        $this->putJson('/api/ai-settings', [
            'search_engine' => 'searxng',
            'searxng_url' => 'http://127.0.0.1:8088/',
        ])->assertOk()
            ->assertJsonPath('search_engine', 'searxng')
            ->assertJsonPath('searxng_url', 'http://127.0.0.1:8088');

        $this->putJson('/api/ai-settings', ['search_engine' => 'niema'])
            ->assertStatus(422);

        $this->putJson('/api/ai-settings', ['search_engine' => 'duckduckgo'])
            ->assertOk()
            ->assertJsonPath('search_engine', 'duckduckgo');

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

    public function test_embedding_provider_switch_uses_openai_and_own_collection(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $product = Product::query()->create([
            'sku' => 'EMB-1',
            'name' => 'Rękawice testowe',
            'manufacturer' => 'Test',
            'catalog_price_net' => 1,
            'purchase_price' => 1,
            'stock' => 1,
            'embedding_hash' => str_repeat('a', 64),
            'embedding_synced_at' => now(),
        ]);

        $this->putJson('/api/ai-settings', [
            'vector_enabled' => true,
            'qdrant_url' => 'http://127.0.0.1:6333',
            'qdrant_collection' => 'products',
            'embedding_base_url' => 'http://127.0.0.1:8080/v1',
            'embedding_model' => 'e5-large',
            'embedding_api_key' => 'local-key-123',
        ])->assertOk()
            ->assertJsonPath('embedding_provider', 'local')
            ->assertJsonPath('embedding_collection', 'products');

        $settings = app(AiSettingsService::class);
        $this->assertSame([
            'provider' => 'local',
            'base_url' => 'http://127.0.0.1:8080/v1',
            'api_key' => 'local-key-123',
            'model' => 'e5-large',
        ], $settings->embeddingProfile());

        $this->putJson('/api/ai-settings', [
            'embedding_provider' => 'openai',
            'embedding_cloud_api_key' => 'sk-openai-embeddings-123',
        ])->assertOk()
            ->assertJsonPath('embedding_provider', 'openai')
            ->assertJsonPath('embedding_collection', 'products_openai')
            ->assertJsonPath('has_embedding_cloud_api_key', true);

        $this->assertSame([
            'provider' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-openai-embeddings-123',
            'model' => 'text-embedding-3-small',
        ], app(AiSettingsService::class)->embeddingProfile());

        $product->refresh();
        $this->assertNull($product->embedding_hash);
        $this->assertNull($product->embedding_synced_at);

        $this->putJson('/api/ai-settings', ['embedding_provider' => 'gemini'])
            ->assertStatus(422);
    }

    public function test_openrouter_embeddings_fall_back_to_chat_key(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://openrouter.ai/api/v1',
            'api_key' => 'sk-or-v1-czat-1234567890',
            'model' => 'openai/gpt-4o',
            'timeout_seconds' => 90,
            'temperature' => 0.1,
            'vector_enabled' => true,
            'qdrant_url' => 'http://127.0.0.1:6333',
            'qdrant_collection' => 'products',
        ]);

        $this->putJson('/api/ai-settings', [
            'embedding_provider' => 'openrouter',
            'embedding_cloud_model' => 'baai/bge-m3',
        ])->assertOk()
            ->assertJsonPath('embedding_collection', 'products_openrouter');

        $this->assertSame([
            'provider' => 'openrouter',
            'base_url' => 'https://openrouter.ai/api/v1',
            'api_key' => 'sk-or-v1-czat-1234567890',
            'model' => 'baai/bge-m3',
        ], app(AiSettingsService::class)->embeddingProfile());

        // czat na innym dostawcy — klucz czatu nie może wyciec do OpenAI
        $this->putJson('/api/ai-settings', [
            'base_url' => 'https://api.deepseek.com/v1',
            'embedding_provider' => 'openai',
        ])->assertOk();

        $this->assertNull(app(AiSettingsService::class)->embeddingProfile()['api_key']);
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
