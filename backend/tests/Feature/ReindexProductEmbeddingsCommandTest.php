<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiSetting;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ReindexProductEmbeddingsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_drops_collection_and_clears_hashes(): void
    {
        Queue::fake();

        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://openrouter.ai/api/v1',
            'api_key' => 'sk-or-v1-test-1234567890',
            'model' => 'openai/gpt-4o',
            'timeout_seconds' => 60,
            'temperature' => 0.1,
            'vector_enabled' => true,
            'qdrant_url' => 'http://qdrant.test:6333',
            'qdrant_collection' => 'products',
            'embedding_provider' => 'openrouter',
            'embedding_cloud_model' => 'qwen/qwen3-embedding-8b',
        ]);

        $product = Product::query()->create([
            'sku' => 'VEC-FRESH-1',
            'name' => 'Rękawice testowe',
            'manufacturer' => 'Test',
            'catalog_price_net' => 1,
            'purchase_price' => 1,
            'stock' => 1,
            'embedding_hash' => str_repeat('b', 64),
            'embedding_synced_at' => now(),
        ]);

        Http::fake([
            'qdrant.test:6333/collections/products_openrouter' => Http::response(['result' => true], 200),
        ]);

        $this->artisan('products:reindex-embeddings', ['--fresh' => true])
            ->assertSuccessful();

        Http::assertSent(static fn ($request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/collections/products_openrouter'));

        $product->refresh();
        $this->assertNull($product->embedding_hash);
        $this->assertNull($product->embedding_synced_at);
    }
}
