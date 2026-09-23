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
use Illuminate\Support\Facades\Schema;
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

    /**
     * Laravel bierze limit zadania przed `--timeout` workera, a klient osadzeń czeka na odpowiedź
     * tyle, ile wynosi „Limit czasu” w Ustawieniach AI. Sztywne 90 sekund przy ustawieniu 240
     * ubijało workera w środku zapytania — wiersz zostawał zarezerwowany i wracał jako
     * przekroczenie prób.
     */
    public function test_job_timeout_outlives_the_configured_embedding_request(): void
    {
        config(['ai.timeout_seconds' => 240]);
        $this->assertSame(300, (new ReindexProductEmbeddingJob(1))->timeout);

        // limit workera to 420 s, a retry_after 480 s — limit zadania musi zostać pod spodem
        config(['ai.timeout_seconds' => 900]);
        $this->assertSame(400, (new ReindexProductEmbeddingJob(1))->timeout);

        // bezsensowne ustawienie nie może dać zadania bez limitu
        config(['ai.timeout_seconds' => 0]);
        $this->assertSame(300, (new ReindexProductEmbeddingJob(1))->timeout);
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
        DB::table(ReindexProductEmbeddingJob::TABLE)->insert($rows);
        DB::table('jobs')->insert([
            'queue' => 'enrich',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);

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
        $this->assertSame(1, DB::table(ReindexProductEmbeddingJob::TABLE)->where('queue', ReindexProductEmbeddingJob::QUEUE)->count());
        // inne kolejki nietknięte
        $this->assertSame(1, DB::table('jobs')->where('queue', 'enrich')->count());
    }

    /**
     * MariaDB 10.5 na serwerze nie zna SKIP LOCKED — przy wspólnej tabeli `jobs` workery wektorów
     * zakleszczały się z resztą przy każdej synchronizacji B2B (23.09.2026). Przy kolejce w bazie
     * zadanie wektora ma trafić do własnej tabeli, a nie do `jobs`.
     */
    public function test_database_queue_routes_reindex_to_its_own_table(): void
    {
        $this->enableVectors();
        config([
            'queue.default' => 'database',
            'queue.embeddings_connection' => 'database_embeddings',
        ]);

        $product = Product::withoutEvents(fn (): Product => $this->product('ROUTE-1'));
        ReindexProductEmbeddingJob::dispatch((int) $product->id);

        $this->assertSame(1, DB::table(ReindexProductEmbeddingJob::TABLE)->where('queue', ReindexProductEmbeddingJob::QUEUE)->count());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(ReindexProductEmbeddingJob::TABLE, config('queue.connections.database_embeddings.table'));
    }

    public function test_migration_moves_waiting_reindex_jobs_out_of_the_shared_table(): void
    {
        $row = fn (string $queue, ?int $reservedAt): array => [
            'queue' => $queue,
            'payload' => '{"id":"'.$queue.'"}',
            'attempts' => $reservedAt === null ? 0 : 1,
            'reserved_at' => $reservedAt,
            'available_at' => 1000,
            'created_at' => 900,
        ];
        $rows = [];
        for ($i = 0; $i < 205; $i++) {
            $rows[] = $row(ReindexProductEmbeddingJob::QUEUE, null);
        }
        // zadanie w trakcie wykonania zostaje — zamknie je stary worker
        $rows[] = $row(ReindexProductEmbeddingJob::QUEUE, time());
        $rows[] = $row('enrich', null);
        DB::table('jobs')->insert($rows);

        $migration = require database_path('migrations/2026_09_23_160000_create_jobs_embeddings_table.php');
        $migration->up();

        $this->assertSame(205, DB::table(ReindexProductEmbeddingJob::TABLE)->count());
        $moved = DB::table(ReindexProductEmbeddingJob::TABLE)->first();
        $this->assertSame(ReindexProductEmbeddingJob::QUEUE, $moved->queue);
        $this->assertSame('{"id":"embeddings"}', $moved->payload);
        $this->assertSame(1000, (int) $moved->available_at);
        $this->assertNull($moved->reserved_at);
        $this->assertSame(1, DB::table('jobs')->where('queue', ReindexProductEmbeddingJob::QUEUE)->count());
        $this->assertSame(1, DB::table('jobs')->where('queue', 'enrich')->count());

        // wycofanie oddaje czekające zadania do wspólnej tabeli
        $migration->down();
        $this->assertFalse(Schema::hasTable(ReindexProductEmbeddingJob::TABLE));
        $this->assertSame(206, DB::table('jobs')->where('queue', ReindexProductEmbeddingJob::QUEUE)->count());
    }
}
