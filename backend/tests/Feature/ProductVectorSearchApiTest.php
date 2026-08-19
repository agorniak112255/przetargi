<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Product;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

final class ProductVectorSearchApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_ai_search_falls_back_to_like_when_vector_disabled(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
            'vector_enabled' => false,
        ]);

        $match = Product::query()->create([
            'sku' => 'GLOVE-VEC-OFF',
            'name' => 'Rękawice chemiczne AlphaTec',
            'manufacturer' => 'Ansell',
            'category' => 'Rękawice',
            'norms' => 'EN 374',
            'description' => 'Rękawice odporne na amoniak.',
            'catalog_price_net' => 12.5,
            'purchase_price' => 8,
            'stock' => 10,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturn(
                [
                    'needed' => 'rękawice do amoniaku',
                    'search_phrases' => ['rękawice chemiczne', 'amoniak'],
                ],
                [
                    'matches' => [
                        ['id' => $match->id, 'score' => 88, 'reason' => 'LIKE path'],
                    ],
                ],
            );
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        Http::fake();

        $this->postJson('/api/products/ai-search', [
            'query' => 'rękawice do pracy z amoniakiem',
        ])
            ->assertOk()
            ->assertJsonPath('products.0.id', $match->id);

        Http::assertNothingSent();
    }

    public function test_ai_search_uses_qdrant_prefilter_when_enabled(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
            'vector_enabled' => true,
            'qdrant_url' => 'http://qdrant.test:6333',
            'qdrant_collection' => 'products',
            'embedding_model' => 'text-embedding-3-small',
        ]);

        $vectorOnly = Product::query()->create([
            'sku' => 'VEC-CHEM-99',
            'name' => 'Rękawice chemiczne nitrylowe',
            'manufacturer' => 'Test',
            'category' => 'Rękawice',
            'norms' => 'EN 374',
            'description' => 'Odporność na amoniak i kwasy, nitryl',
            'catalog_price_net' => 1,
            'purchase_price' => 1,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        // mechaniczne — wysoki score wektorowy, ale filtr chemii ma je odrzucić
        $mechanical = Product::query()->create([
            'sku' => 'VEC-MECH-01',
            'name' => 'MaxiFlex Ultimate',
            'manufacturer' => 'ATG',
            'category' => 'Rękawice',
            'norms' => 'EN 388',
            'description' => 'Rękawice robocze precyzyjne',
            'catalog_price_net' => 1,
            'purchase_price' => 1,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $fakeVector = array_fill(0, 8, 0.1);

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => $fakeVector]],
            ], 200),
            'qdrant.test:6333/collections/products' => Http::response(['result' => ['status' => 'green']], 200),
            'qdrant.test:6333/collections/products/points/search' => Http::response([
                'result' => [
                    ['id' => $mechanical->id, 'score' => 0.99],
                    ['id' => $vectorOnly->id, 'score' => 0.91],
                ],
            ], 200),
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->andReturn(
                [
                    'needed' => 'rękawice chemiczne do amoniaku',
                    'search_phrases' => ['rękawice chemiczne', 'amoniak', 'nitryl'],
                ],
                [
                    'matches' => [
                        ['id' => $vectorOnly->id, 'score' => 90, 'reason' => 'Wektor'],
                    ],
                ],
            );
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $this->postJson('/api/products/ai-search', [
            'query' => 'rękawice do pracy z amoniakiem',
        ])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('products.0.id', $vectorOnly->id)
            ->assertJsonPath('products.0.ai_match_percent', 90);

        Http::assertSent(static fn ($request): bool => str_contains($request->url(), '/embeddings'));
        Http::assertSent(static fn ($request): bool => str_contains($request->url(), '/points/search'));
    }

    public function test_test_vector_endpoint_pings_qdrant_and_embeddings(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
            'vector_enabled' => true,
            'qdrant_url' => 'http://qdrant.test:6333',
            'qdrant_collection' => 'products',
            'embedding_model' => 'text-embedding-3-small',
        ]);

        $fakeVector = array_fill(0, 8, 0.05);

        Http::fake([
            'qdrant.test:6333/collections' => Http::response([
                'result' => ['collections' => [['name' => 'products']]],
            ], 200),
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => $fakeVector]],
            ], 200),
            'qdrant.test:6333/collections/products' => Http::response(['result' => []], 200),
        ]);

        $this->postJson('/api/ai-settings/test-vector')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('embedding_ok', true);
    }
}
