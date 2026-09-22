<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\EmbeddingRequestException;
use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\AiSetting;
use App\Models\Product;
use App\Services\Vector\ProductEmbeddingIndexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Kolejka wektorów przy wyłączonym wyszukiwaniu wektorowym. 22.09.2026 na produkcji
 * każdy zapis karty zlecał reindeks, choć Qdrant był wyłączony: 41 567 kart bez jednego
 * wektora, 620 wpisów w failed_jobs (MaxAttemptsExceededException) i zakleszczenia MySQL
 * na tabeli `jobs`, bo 16 workerów w kółko pobierało zadania kończące się natychmiast.
 */
final class EmbeddingQueueGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget(ReindexProductEmbeddingJob::HALT_CACHE_KEY);
    }

    private function enableVectors(): void
    {
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

    private function product(string $sku): Product
    {
        return Product::query()->create([
            'sku' => $sku,
            'name' => 'Rękawice chemiczne',
            'manufacturer' => 'Ansell',
            'description' => 'Odporne na kwasy.',
            'catalog_price_net' => 10,
            'purchase_price' => 6,
            'stock' => 5,
        ]);
    }

    public function test_save_does_not_queue_reindex_when_vector_search_is_off(): void
    {
        // domyślne ustawienia testowe = wektory wyłączone, tak jak na produkcji 22.09.2026
        $this->assertFalse($this->app->make(ProductEmbeddingIndexer::class)->shouldIndex());

        Bus::fake();

        $product = $this->product('GATE-OFF');
        $product->update(['description' => 'Odporne na kwasy i amoniak, EN 374.']);

        Bus::assertNotDispatched(ReindexProductEmbeddingJob::class);
    }

    public function test_save_queues_reindex_when_vector_search_is_on(): void
    {
        $this->enableVectors();
        Bus::fake();

        $product = $this->product('GATE-ON');

        Bus::assertDispatched(
            ReindexProductEmbeddingJob::class,
            static fn (ReindexProductEmbeddingJob $job): bool => $job->productId === $product->id,
        );
    }

    public function test_halt_clears_waiting_jobs_in_chunks_and_leaves_reserved_ones(): void
    {
        $this->enableVectors();
        Http::fake([
            'https://emb.test/v1/embeddings' => Http::response(['error' => ['message' => 'no model']], 404),
        ]);

        // więcej niż jedna porcja kasowania (HALT_DELETE_CHUNK = 200)
        $rows = [];
        for ($i = 0; $i < 250; $i++) {
            $rows[] = [
                'queue' => ReindexProductEmbeddingJob::QUEUE,
                'payload' => '{}',
                'attempts' => 0,
                'reserved_at' => null,
                'available_at' => time(),
                'created_at' => time(),
            ];
        }
        $rows[] = [
            'queue' => ReindexProductEmbeddingJob::QUEUE,
            'payload' => '{}',
            'attempts' => 1,
            'reserved_at' => time(),
            'available_at' => time(),
            'created_at' => time(),
        ];
        $rows[] = [
            'queue' => 'enrich',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ];
        DB::table('jobs')->insert($rows);

        // bez haka zapisu — zadanie odpalamy niżej ręcznie, żeby zmierzyć samo zatrzymanie kolejki
        $product = Product::withoutEvents(fn (): Product => $this->product('HALT-1'));

        try {
            (new ReindexProductEmbeddingJob((int) $product->id))->handle(
                $this->app->make(ProductEmbeddingIndexer::class)
            );
            $this->fail('Oczekiwano EmbeddingRequestException');
        } catch (EmbeddingRequestException $e) {
            $this->assertTrue($e->isTerminal());
        }

        $this->assertTrue(Cache::has(ReindexProductEmbeddingJob::HALT_CACHE_KEY));
        // zadanie w trakcie wykonania zostaje — worker sam je zamknie
        $this->assertSame(1, DB::table('jobs')->where('queue', ReindexProductEmbeddingJob::QUEUE)->count());
        // inne kolejki nietknięte
        $this->assertSame(1, DB::table('jobs')->where('queue', 'enrich')->count());
    }
}
