<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\EmbeddingRequestException;
use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\AiSetting;
use App\Models\Product;
use App\Services\Vector\ProductEmbeddingIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ProductEmbeddingIndexFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(ReindexProductEmbeddingJob::HALT_CACHE_KEY);
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://emb.test/v1',
            'api_key' => 'sk-test-embed',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 30,
            'temperature' => 0.1,
            'vector_enabled' => true,
            'qdrant_url' => 'http://qdrant.test:6333',
            'qdrant_collection' => 'products',
            'embedding_provider' => 'local',
            'embedding_base_url' => 'https://emb.test/v1',
            'embedding_api_key' => 'sk-test-embed',
            'embedding_model' => 'text-embedding-3-small',
        ]);
    }

    public function test_http_401_does_not_set_synced_at_and_is_terminal(): void
    {
        $product = $this->product('VEC-401');
        Http::fake([
            'https://emb.test/v1/embeddings' => Http::response(['error' => ['message' => 'User not found.']], 401),
        ]);

        try {
            $this->app->make(ProductEmbeddingIndexer::class)->index($product);
            $this->fail('Oczekiwano EmbeddingRequestException');
        } catch (EmbeddingRequestException $e) {
            $this->assertTrue($e->isTerminal());
            $this->assertSame(401, $e->status);
        }

        $this->assertNull($product->refresh()->embedding_synced_at);
    }

    public function test_http_503_is_not_terminal(): void
    {
        $product = $this->product('VEC-503');
        Http::fake([
            'https://emb.test/v1/embeddings' => Http::response(['error' => ['message' => 'busy']], 503),
        ]);

        try {
            $this->app->make(ProductEmbeddingIndexer::class)->index($product);
            $this->fail('Oczekiwano EmbeddingRequestException');
        } catch (EmbeddingRequestException $e) {
            $this->assertFalse($e->isTerminal());
            $this->assertSame(503, $e->status);
        }

        $this->assertNull($product->refresh()->embedding_synced_at);
    }

    public function test_job_401_halts_and_drops_pending_embeddings_jobs(): void
    {
        $product = $this->product('VEC-HALT');
        DB::table('jobs')->insert([
            'queue' => ReindexProductEmbeddingJob::QUEUE,
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);
        Http::fake([
            'https://emb.test/v1/embeddings' => Http::response(['error' => ['message' => 'User not found.']], 401),
        ]);

        $this->expectException(EmbeddingRequestException::class);
        (new ReindexProductEmbeddingJob((int) $product->id))->handle(
            $this->app->make(ProductEmbeddingIndexer::class)
        );
    }

    public function test_job_401_clears_unreserved_queue_and_sets_halt(): void
    {
        $product = $this->product('VEC-HALT-2');
        DB::table('jobs')->insert([
            'queue' => ReindexProductEmbeddingJob::QUEUE,
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);
        Http::fake([
            'https://emb.test/v1/embeddings' => Http::response(['error' => ['message' => 'User not found.']], 401),
        ]);

        try {
            (new ReindexProductEmbeddingJob((int) $product->id))->handle(
                $this->app->make(ProductEmbeddingIndexer::class)
            );
        } catch (EmbeddingRequestException) {
        }

        $this->assertTrue(Cache::has(ReindexProductEmbeddingJob::HALT_CACHE_KEY));
        $this->assertSame(0, DB::table('jobs')->where('queue', ReindexProductEmbeddingJob::QUEUE)->count());
        $this->assertNull($product->refresh()->embedding_synced_at);
    }

    public function test_halted_job_skips_without_calling_api(): void
    {
        Cache::put(ReindexProductEmbeddingJob::HALT_CACHE_KEY, 1, 60);
        $product = $this->product('VEC-SKIP');
        Http::fake();

        (new ReindexProductEmbeddingJob((int) $product->id))->handle(
            $this->app->make(ProductEmbeddingIndexer::class)
        );

        Http::assertNothingSent();
        $this->assertNull($product->refresh()->embedding_synced_at);
    }

    public function test_successful_index_sets_synced_at(): void
    {
        $product = $this->product('VEC-OK');
        $vector = array_fill(0, 8, 0.1);
        Http::fake([
            'https://emb.test/v1/embeddings' => Http::response([
                'data' => [['embedding' => $vector]],
            ]),
            'qdrant.test:6333/*' => Http::response(['result' => ['status' => 'ok']], 200),
        ]);

        $ok = $this->app->make(ProductEmbeddingIndexer::class)->index($product);

        $this->assertTrue($ok);
        $this->assertNotNull($product->refresh()->embedding_synced_at);
    }

    public function test_reindex_command_clears_halt(): void
    {
        Cache::put(ReindexProductEmbeddingJob::HALT_CACHE_KEY, 1, 60);
        Http::fake([
            'qdrant.test:6333/*' => Http::response(['result' => true], 200),
        ]);

        $this->artisan('products:reindex-embeddings')->assertSuccessful();

        $this->assertFalse(Cache::has(ReindexProductEmbeddingJob::HALT_CACHE_KEY));
    }

    private function product(string $sku): Product
    {
        return Product::withoutEvents(fn (): Product => Product::query()->create([
            'sku' => $sku,
            'name' => 'Rękawice testowe',
            'manufacturer' => 'Test',
            'catalog_price_net' => 1,
            'purchase_price' => 1,
            'stock' => 1,
        ]));
    }
}
