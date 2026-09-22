<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\AiSetting;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class ReindexProductEmbeddingsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function vectorSettings(): void
    {
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
    }

    public function test_fresh_drops_collection_and_clears_hashes(): void
    {
        Queue::fake();
        $this->vectorSettings();

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
            // komenda sprawdza profil jednym osadzeniem, zanim zakolejkuje katalog
            'https://openrouter.ai/api/v1/embeddings' => Http::response([
                'data' => [['embedding' => array_fill(0, 8, 0.1)]],
            ]),
            'qdrant.test:6333/collections/products_openrouter' => Http::response(['result' => true], 200),
        ]);

        $this->artisan('products:reindex-embeddings', ['--fresh' => true])
            ->assertSuccessful();

        Http::assertSent(static fn ($request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/collections/products_openrouter'));

        // reindeks katalogu nie może blokować pobierania opisów z kolejki default
        Queue::assertPushed(
            ReindexProductEmbeddingJob::class,
            static fn (ReindexProductEmbeddingJob $job): bool => $job->queue === ReindexProductEmbeddingJob::QUEUE
        );

        $product->refresh();
        $this->assertNull($product->embedding_hash);
        $this->assertNull($product->embedding_synced_at);
    }

    /**
     * Zły profil osadzeń (adres bez /embeddings, model nieznany dostawcy) wychodził dopiero
     * na kilkudziesięciu tysiącach zadań w kolejce. Jedno osadzenie próbne kończy przebieg
     * od razu i nic nie kolejkuje.
     */
    public function test_broken_embedding_profile_stops_before_queueing(): void
    {
        Queue::fake();
        $this->vectorSettings();

        Product::withoutEvents(fn (): Product => Product::query()->create([
            'sku' => 'VEC-PROBE-1',
            'name' => 'Rękawice testowe',
            'manufacturer' => 'Test',
            'catalog_price_net' => 1,
            'purchase_price' => 1,
            'stock' => 1,
        ]));

        Http::fake([
            'https://openrouter.ai/api/v1/embeddings' => Http::response(
                ['error' => ['message' => 'No endpoints found for baai/bge-m3.']],
                404
            ),
        ]);

        $this->artisan('products:reindex-embeddings')
            ->expectsOutputToContain('Profil embeddingów nie odpowiada')
            ->assertFailed();

        Queue::assertNotPushed(ReindexProductEmbeddingJob::class);
    }
}
