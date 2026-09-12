<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ProductSourcesNotFoundException;
use App\Jobs\EnrichProductJob;
use App\Jobs\PrefetchProductSourcesJob;
use App\Models\AiSetting;
use App\Models\CatalogHost;
use App\Models\CatalogPage;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductEnrichmentBatchItem;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Enrichment\DuckDuckGoHtmlSearch;
use App\Services\Enrichment\EnrichmentAttemptLog;
use App\Services\Enrichment\EnrichmentSlots;
use App\Services\Enrichment\HybridWebSearchService;
use App\Services\Enrichment\ManufacturerDomainResolver;
use App\Services\Enrichment\PrefetchSlots;
use App\Services\Enrichment\ProductDocumentDownloader;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Enrichment\ProductImageCandidateVerifier;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\Enrichment\ProductPageFetcher;
use App\Services\Enrichment\ProductSearchIdentity;
use App\Support\BhpAttributeNormalizer;
use App\Support\PpeAssortment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Mockery\MockInterface;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class ProductEnrichmentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_document_only_search_result_does_not_finish_product_page_search(): void
    {
        $reflection = new \ReflectionClass(HybridWebSearchService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('hasEnoughPageResults');
        $method->setAccessible(true);

        $documentOnly = [[
            'url' => 'https://urgent.pl/file/show/file/69034c7e0ad8b/filename/deklaracja_1005.pdf',
            'title' => 'Deklaracja zgodności 1005',
            'snippet' => 'URGENT 1005',
        ]];
        $productPage = [[
            'url' => 'https://urgent.pl/product/show/productid/439',
            'title' => 'Rękawice impregnowane pokryte nitrylem 1005',
            'snippet' => 'URGENT 1005',
        ]];

        $this->assertFalse($method->invoke($service, $documentOnly, 1));
        $this->assertTrue($method->invoke($service, $productPage, 1));
    }

    public function test_single_product_enrichment_runs_synchronously(): void
    {
        Queue::fake();
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $product = $this->makeProduct();

        $search = $this->searchMock();
        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn([
                'results' => [[
                    'url' => 'https://example.com/product/'.$product->sku,
                    'title' => 'Karta',
                    'snippet' => 'Rękawice Ansell '.$product->sku,
                ]],
                'errors' => [],
            ]);
        $this->app->instance(HybridWebSearchService::class, $search);

        $llm = $this->mockLlmWithSanitize([
            'description' => 'Rękawice nitrylowe Ansell '.$product->sku.' do pracy w przemyśle. Spełniają normy EN 388 i chronią przed ścieraniem. Przeznaczone do montażu oraz prac precyzyjnych w warunkach suchych. Trwała powłoka nitrylowa zwiększa żywotność przy codziennym użytkowaniu.',
            'features' => ['nitryl'],
            'specs' => ['Długość: 30 cm'],
            'norms' => ['EN 388'],
            'certificates' => [],
            'materials' => ['nitryl'],
            'use_cases' => ['montaż'],
            'image_urls' => ['https://cdn.example.com/glove-'.$product->sku.'.jpg'],
            'source_urls' => ['https://example.com/product/'.$product->sku],
            'confidence' => 0.9,
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        Http::fake([
            'https://example.com/*' => Http::response(
                '<html><body>Ansell Rękawice testowe '.$product->sku.' <img src="https://cdn.example.com/glove-'.$product->sku.'.jpg" alt="'.$product->sku.'"></body></html>',
                200
            ),
            'https://cdn.example.com/*' => Http::response($this->tinyJpeg(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->postJson("/api/products/{$product->id}/enrich")
            ->assertOk()
            ->assertJsonPath('batch.total', 1)
            ->assertJsonPath('batch.status', ProductEnrichmentBatch::STATUS_DONE)
            ->assertJsonPath('images_count', 1)
            ->assertJsonPath('product.enrichment_status', Product::ENRICHMENT_DONE);

        Queue::assertNotPushed(EnrichProductJob::class);
        Queue::assertNotPushed(PrefetchProductSourcesJob::class);
    }

    public function test_skip_done_product_without_force(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $product = $this->makeProduct([
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'description' => 'Już jest',
            'enriched_at' => now(),
        ]);
        // Fake dopiero po fixture: zapis produktu sam kolejkuje reindeks embeddingu,
        // a ten test pilnuje tego, czego NIE zrobił endpoint enrichmentu.
        Queue::fake();

        $this->postJson("/api/products/{$product->id}/enrich")
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Produkt ma już pobrane dane. Użyj force=true, aby pobrać ponownie.']);

        Queue::assertNothingPushed();
    }

    public function test_force_sync_reenriches_done_product(): void
    {
        Queue::fake();
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $product = $this->makeProduct([
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'description' => 'Stary opis',
            'enriched_at' => now(),
        ]);

        $search = $this->searchMock();
        $search->shouldReceive('forgetProductCache')
            ->once()
            ->with(Mockery::on(
                static fn (Product $candidate): bool => $candidate->id === $product->id
            ));
        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn([
                'results' => [[
                    'url' => 'https://example.com/product/'.$product->sku,
                    'title' => 'Karta',
                    'snippet' => 'Ansell '.$product->sku,
                ]],
                'errors' => [],
            ]);
        $this->app->instance(HybridWebSearchService::class, $search);

        $llm = $this->mockLlmWithSanitize([
            'description' => 'Rękawice testowe Ansell. Nowy opis po force. Spełnia normy EN 388 i chroni dłonie przy montażu. Trwała powłoka nitrylowa do codziennej pracy w zakładzie produkcyjnym oraz warsztacie.',
            'features' => [],
            'specs' => [],
            'norms' => ['EN 388'],
            'certificates' => [],
            'materials' => ['nitryl'],
            'use_cases' => ['montaż'],
            'image_urls' => ['https://cdn.example.com/glove-'.$product->sku.'.jpg'],
            'source_urls' => ['https://example.com/product/'.$product->sku],
            'confidence' => 0.8,
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        Http::fake([
            'https://example.com/*' => Http::response(
                '<html>Ansell Rękawice testowe '.$product->sku.' <img src="https://cdn.example.com/glove-'.$product->sku.'.jpg"></html>',
                200
            ),
            'https://cdn.example.com/*' => Http::response($this->tinyJpeg(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->postJson("/api/products/{$product->id}/enrich", ['force' => true])
            ->assertOk()
            ->assertJsonPath('product.enrichment_status', Product::ENRICHMENT_DONE)
            ->assertJsonPath('images_count', 1);

        $product->refresh();
        $this->assertStringContainsString('Nowy opis po force', (string) $product->description);

        Queue::assertNotPushed(EnrichProductJob::class);
        Queue::assertNotPushed(PrefetchProductSourcesJob::class);
    }

    public function test_enqueue_price_list_enrichment(): void
    {
        Queue::fake();
        $user = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($user);

        $p1 = $this->makeProduct(['sku' => 'A-1']);
        $p2 = $this->makeProduct(['sku' => 'A-2', 'enrichment_status' => Product::ENRICHMENT_DONE]);

        $priceList = PriceList::query()->create([
            'manufacturer' => 'Ansell',
            'version' => '2026',
            'imported_by' => $user->id,
            'rows_total' => 2,
            'products_created' => 2,
            'products_updated' => 0,
            'prices_changed' => 0,
            'rows_skipped' => 0,
            'product_ids' => [$p1->id, $p2->id],
        ]);

        $this->postJson("/api/price-lists/{$priceList->id}/enrich")
            ->assertStatus(202)
            ->assertJsonPath('batch.total', 1)
            ->assertJsonPath('batch.scope', 'price_list')
            ->assertJsonPath('batch.manufacturer', 'Ansell')
            ->assertJsonPath('batch.price_list_id', $priceList->id)
            ->assertJsonPath('product_ids.0', $p1->id);

        Queue::assertPushed(PrefetchProductSourcesJob::class, 1);
        Queue::assertNotPushed(EnrichProductJob::class);
    }

    public function test_batch_payload_includes_manufacturer_and_current_product(): void
    {
        $user = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($user);

        $product = $this->makeProduct([
            'sku' => 'RS20164',
            'name' => 'RUSH ESD S3 CI SRC',
            'manufacturer' => 'Honeywell',
        ]);
        $priceList = PriceList::query()->create([
            'manufacturer' => 'Honeywell',
            'version' => '2026',
            'imported_by' => $user->id,
            'rows_total' => 1,
            'products_created' => 1,
            'products_updated' => 0,
            'prices_changed' => 0,
            'rows_skipped' => 0,
            'product_ids' => [$product->id],
        ]);
        $batch = ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRICE_LIST,
            'scope_id' => $priceList->id,
            'total' => 1,
            'done' => 0,
            'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_RUNNING,
            'created_by' => $user->id,
            'force' => false,
            'current_sku' => 'RS20164',
            'current_name' => $product->name,
        ]);

        $this->getJson("/api/product-enrichment-batches/{$batch->id}")
            ->assertOk()
            ->assertJsonPath('manufacturer', 'Honeywell')
            ->assertJsonPath('current_product_id', $product->id)
            ->assertJsonPath('price_list_id', $priceList->id);

        $this->getJson('/api/product-enrichment-batches/active')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $batch->id,
                'manufacturer' => 'Honeywell',
                'current_product_id' => $product->id,
            ]);
    }

    public function test_batch_status_endpoint(): void
    {
        $user = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($user);

        $batch = ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRODUCT,
            'scope_id' => 1,
            'total' => 2,
            'done' => 1,
            'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_RUNNING,
            'created_by' => $user->id,
            'force' => false,
        ]);

        $this->getJson("/api/product-enrichment-batches/{$batch->id}")
            ->assertOk()
            ->assertJsonPath('id', $batch->id)
            ->assertJsonPath('progress_percent', 50)
            ->assertJsonPath('done', 1);

        $this->getJson('/api/product-enrichment-batches/active')
            ->assertOk()
            ->assertJsonFragment(['id' => $batch->id, 'status' => 'running']);
    }

    public function test_job_counts_already_manual_product_and_closes_batch(): void
    {
        $product = $this->makeProduct([
            'sku' => 'INT-001',
            'enrichment_status' => Product::ENRICHMENT_MANUAL,
        ]);
        $batch = ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRODUCTS,
            'scope_id' => 6,
            'total' => 2,
            'done' => 1,
            'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_RUNNING,
            'force' => false,
            'current_sku' => '3-60NM',
        ]);

        $job = new EnrichProductJob($product->id, $batch->id);
        $job->handle(
            app(ProductEnrichmentService::class),
            app(AiSettingsService::class),
            app(EnrichmentSlots::class),
        );

        $batch->refresh();
        $this->assertSame(2, $batch->done);
        $this->assertSame(ProductEnrichmentBatch::STATUS_DONE, $batch->status);
        $this->assertNull($batch->current_sku);
        $this->assertSame(Product::ENRICHMENT_MANUAL, $product->fresh()?->enrichment_status);
    }

    public function test_active_batches_close_stale_running_batch_without_jobs(): void
    {
        $user = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($user);

        $batch = ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRODUCTS,
            'scope_id' => 6,
            'total' => 11,
            'done' => 6,
            'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_RUNNING,
            'created_by' => $user->id,
            'force' => false,
            'current_sku' => '3-60NM',
            'message' => 'OK 6 · błędy 0 · pozostało 5',
        ]);
        ProductEnrichmentBatch::query()->whereKey($batch->id)->update([
            'updated_at' => now()->subMinutes(15),
        ]);

        $this->getJson('/api/product-enrichment-batches/active')
            ->assertOk()
            ->assertJsonPath('batches', [])
            ->assertJsonPath('recent.0.id', $batch->id)
            ->assertJsonPath('recent.0.status', ProductEnrichmentBatch::STATUS_DONE);

        $batch->refresh();
        $this->assertSame(ProductEnrichmentBatch::STATUS_DONE, $batch->status);
        $this->assertSame(11, $batch->done);
        $this->assertNull($batch->current_sku);
    }

    public function test_history_lists_finished_batches_and_skips_running(): void
    {
        $user = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($user);

        $done = ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRODUCTS,
            'scope_id' => 6,
            'total' => 3,
            'done' => 2,
            'failed' => 1,
            'status' => ProductEnrichmentBatch::STATUS_DONE,
            'created_by' => $user->id,
            'force' => false,
            'message' => 'Gotowe',
        ]);
        ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRODUCT,
            'scope_id' => 1,
            'total' => 1,
            'done' => 0,
            'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_RUNNING,
            'created_by' => $user->id,
            'force' => false,
        ]);
        $failed = ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRODUCT,
            'scope_id' => 9,
            'total' => 1,
            'done' => 0,
            'failed' => 1,
            'status' => ProductEnrichmentBatch::STATUS_FAILED,
            'created_by' => $user->id,
            'force' => false,
            'message' => 'Błąd',
        ]);

        $this->getJson('/api/product-enrichment-batches/history')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', $failed->id)
            ->assertJsonPath('data.0.status', ProductEnrichmentBatch::STATUS_FAILED)
            ->assertJsonPath('data.0.created_by_name', $user->name)
            ->assertJsonPath('data.1.id', $done->id);

        $this->getJson('/api/product-enrichment-batches/history?status=done')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $done->id);
    }

    public function test_active_batches_keep_running_when_json_job_payload_exists(): void
    {
        $user = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($user);

        $batch = ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRODUCTS,
            'scope_id' => 6,
            'total' => 11,
            'done' => 2,
            'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_RUNNING,
            'created_by' => $user->id,
            'force' => false,
            'current_sku' => '420000300000',
            'current_name' => 'Rękawice CXS',
            'message' => 'DuckDuckGo + lokalny model…',
        ]);
        ProductEnrichmentBatch::query()->whereKey($batch->id)->update([
            'updated_at' => now()->subMinutes(15),
        ]);

        $command = 'O:27:"App\\Jobs\\EnrichProductJob":2:{s:9:"productId";i:99;s:7:"batchId";i:'.$batch->id.';}';
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => EnrichProductJob::class,
                'data' => ['command' => $command],
            ], JSON_UNESCAPED_SLASHES),
            'attempts' => 1,
            'reserved_at' => time(),
            'available_at' => time(),
            'created_at' => time(),
        ]);

        $this->getJson('/api/product-enrichment-batches/active')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $batch->id,
                'status' => 'running',
                'current_sku' => '420000300000',
            ]);

        $batch->refresh();
        $this->assertSame(ProductEnrichmentBatch::STATUS_RUNNING, $batch->status);
        $this->assertSame('420000300000', $batch->current_sku);
    }

    public function test_active_batches_keep_stale_when_products_still_queued(): void
    {
        $user = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($user);

        $this->makeProduct([
            'sku' => 'Q-STALE',
            'enrichment_status' => Product::ENRICHMENT_QUEUED,
        ]);
        $batch = ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRODUCTS,
            'scope_id' => 6,
            'total' => 11,
            'done' => 6,
            'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_RUNNING,
            'created_by' => $user->id,
            'force' => false,
            'current_sku' => 'Q-STALE',
        ]);
        ProductEnrichmentBatch::query()->whereKey($batch->id)->update([
            'updated_at' => now()->subMinutes(15),
        ]);

        $this->getJson('/api/product-enrichment-batches/active')
            ->assertOk()
            ->assertJsonPath('queued_products', 1)
            ->assertJsonFragment(['id' => $batch->id, 'status' => 'running']);

        $batch->refresh();
        $this->assertSame(ProductEnrichmentBatch::STATUS_RUNNING, $batch->status);
        $this->assertSame(6, $batch->done);
    }

    public function test_stop_all_kills_jobs_of_hidden_done_batch(): void
    {
        $user = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($user);

        $product = $this->makeProduct([
            'sku' => 'GHOST-1',
            'enrichment_status' => Product::ENRICHMENT_QUEUED,
        ]);
        $batch = ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRODUCTS,
            'scope_id' => 6,
            'total' => 11,
            'done' => 11,
            'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_DONE,
            'created_by' => $user->id,
            'force' => false,
        ]);

        $command = 'O:27:"App\\Jobs\\EnrichProductJob":2:{s:9:"productId";i:'.$product->id.';s:7:"batchId";i:'.$batch->id.';}';
        $jobId = DB::table('jobs')->insertGetId([
            'queue' => 'default',
            'payload' => json_encode([
                'displayName' => EnrichProductJob::class,
                'data' => ['command' => $command],
            ], JSON_UNESCAPED_SLASHES),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        $this->postJson('/api/product-enrichment-batches/stop-all')
            ->assertOk()
            ->assertJsonPath('removed_jobs', 1)
            ->assertJsonPath('marked_products', 1)
            ->assertJsonPath('queued_products', 0)
            ->assertJsonPath('running_products', 0);

        $this->assertDatabaseMissing('jobs', ['id' => $jobId]);
        $this->assertSame(Product::ENRICHMENT_FAILED, $product->fresh()?->enrichment_status);
        $this->assertTrue($batch->fresh()?->isCancelled());
    }

    public function test_handlowiec_cannot_stop_all_enrichment(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $this->postJson('/api/product-enrichment-batches/stop-all')->assertForbidden();
    }

    public function test_handlowiec_cannot_enrich(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());
        $product = $this->makeProduct();

        $this->postJson("/api/products/{$product->id}/enrich")->assertForbidden();
    }

    public function test_bulk_enrich_products_from_list(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $p1 = $this->makeProduct(['sku' => 'B-1']);
        $p2 = $this->makeProduct(['sku' => 'B-2']);

        $this->postJson('/api/products/enrich', [
            'product_ids' => [$p1->id, $p2->id],
        ])
            ->assertStatus(202)
            ->assertJsonPath('batch.total', 2)
            ->assertJsonPath('batch.scope', 'products')
            ->assertJsonPath('product_ids.0', $p1->id)
            ->assertJsonPath('product_ids.1', $p2->id);

        Queue::assertPushed(PrefetchProductSourcesJob::class, 2);
        Queue::assertPushedOn('prefetch', PrefetchProductSourcesJob::class);
        Queue::assertNotPushed(EnrichProductJob::class);
    }

    public function test_batch_item_log_lists_products_sorted_by_status(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $ok = $this->makeProduct(['sku' => 'LOG-OK', 'name' => 'But OK']);
        $fail = $this->makeProduct(['sku' => 'LOG-FAIL', 'name' => 'But błąd']);
        $queued = $this->makeProduct(['sku' => 'LOG-Q', 'name' => 'But kolejka']);

        $res = $this->postJson('/api/products/enrich', [
            'product_ids' => [$ok->id, $fail->id, $queued->id],
        ])->assertStatus(202);
        $batchId = (int) $res->json('batch.id');
        $batch = ProductEnrichmentBatch::query()->findOrFail($batchId);
        $service = app(ProductEnrichmentService::class);
        $service->recordBatchProduct($batch, $ok, ProductEnrichmentBatchItem::STATUS_DONE);
        $service->recordBatchProduct(
            $batch,
            $fail,
            ProductEnrichmentBatchItem::STATUS_FAILED,
            'Nie znaleziono karty',
        );

        $this->getJson("/api/product-enrichment-batches/{$batchId}/items?sort=status")
            ->assertOk()
            ->assertJsonPath('items.0.sku', 'LOG-FAIL')
            ->assertJsonPath('items.0.status', 'failed')
            ->assertJsonPath('items.1.sku', 'LOG-Q')
            ->assertJsonPath('items.1.status', 'queued')
            ->assertJsonPath('items.2.sku', 'LOG-OK')
            ->assertJsonPath('items.2.status', 'done')
            ->assertJsonPath('counts.queued', 1)
            ->assertJsonPath('counts.done', 1)
            ->assertJsonPath('counts.failed', 1);

        $this->getJson("/api/product-enrichment-batches/{$batchId}/items?status=failed")
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.product_id', $fail->id);
    }

    public function test_prefetch_job_dispatches_enrich_after_search(): void
    {
        Queue::fake();
        $product = $this->makeProduct(['sku' => 'PF-1', 'manufacturer' => 'Uvex']);
        $batch = ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRODUCT,
            'scope_id' => $product->id,
            'total' => 1,
            'done' => 0,
            'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_QUEUED,
            'force' => false,
        ]);

        $search = $this->searchMock();
        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn([
                'results' => [[
                    'url' => 'https://shop.example.com/pf-1',
                    'title' => 'PF-1',
                    'snippet' => 'Rękawice PF-1',
                ]],
                'errors' => [],
            ]);
        $this->app->instance(HybridWebSearchService::class, $search);

        Http::fake([
            'https://shop.example.com/*' => Http::response(
                '<html><body><h1>PF-1</h1><p>'.str_repeat('Rękawice PF-1 EN 388. ', 40).'</p></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        (new PrefetchProductSourcesJob($product->id, $batch->id))
            ->handle(app(ProductEnrichmentService::class), app(PrefetchSlots::class));

        Queue::assertPushed(EnrichProductJob::class, 1);
        Queue::assertPushedOn('enrich', EnrichProductJob::class);
        Http::assertSentCount(1);
    }

    public function test_enrich_reuses_prefetch_search_pack_without_second_search(): void
    {
        Storage::fake('public');
        $product = $this->makeProduct(['sku' => 'PF-REUSE', 'manufacturer' => 'Uvex']);

        $search = $this->searchMock();
        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn([
                'results' => [[
                    'url' => 'https://shop.example.com/pf-reuse',
                    'title' => 'PF-REUSE Uvex',
                    'snippet' => 'Rękawice PF-REUSE Uvex',
                ]],
                'errors' => [],
            ]);
        $search->shouldReceive('forgetProductCache')->zeroOrMoreTimes();
        $this->app->instance(HybridWebSearchService::class, $search);

        $llm = $this->mockLlmWithSanitize([
            'description' => 'Rękawice Uvex PF-REUSE do montażu. Spełniają EN 388. Chronią przed ścieraniem w suchych warunkach. Trwała powłoka do codziennej pracy w zakładzie. Przeznaczone do prac precyzyjnych i kompletacji. Wygodny mankiet nie ogranicza ruchów.',
            'features' => ['nitryl'],
            'specs' => ['SKU: PF-REUSE'],
            'norms' => ['EN 388'],
            'certificates' => [],
            'materials' => ['nitryl'],
            'use_cases' => ['montaż'],
            'image_urls' => [],
            'source_urls' => ['https://shop.example.com/pf-reuse'],
            'confidence' => 0.8,
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        Http::fake([
            'https://shop.example.com/*' => Http::response(
                '<html><body><h1>Uvex PF-REUSE</h1><p>'.str_repeat('Rękawice Uvex PF-REUSE EN 388. ', 40).'</p></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $service = app(ProductEnrichmentService::class);
        $service->prefetchProductSources($product, false);
        $service->enrichProduct($product, false);

        $product->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status);
        $this->assertNotNull($product->enrichment_error);
        $this->assertIsArray($product->enrichment_trace);
    }

    public function test_prefetch_waits_when_all_search_slots_busy(): void
    {
        Queue::fake();
        $product = $this->makeProduct(['sku' => 'PF-2']);
        $batch = ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRODUCT,
            'scope_id' => $product->id,
            'total' => 1,
            'done' => 0,
            'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_QUEUED,
            'force' => false,
        ]);

        $busy = [];
        for ($i = 0; $i < app(PrefetchSlots::class)->limit(); $i++) {
            $lock = Cache::lock('enrichment_prefetch_gate:'.$i, 180);
            $this->assertTrue((bool) $lock->get());
            $busy[] = $lock;
        }

        (new PrefetchProductSourcesJob($product->id, $batch->id))
            ->handle(app(ProductEnrichmentService::class), app(PrefetchSlots::class));

        Queue::assertPushed(PrefetchProductSourcesJob::class, 1);
        Queue::assertNotPushed(EnrichProductJob::class);
        foreach ($busy as $lock) {
            $lock->release();
        }
    }

    public function test_prefetch_runs_when_one_slot_is_busy(): void
    {
        Queue::fake();
        $product = $this->makeProduct(['sku' => 'PF-3', 'manufacturer' => 'Uvex']);
        $batch = ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRODUCT,
            'scope_id' => $product->id,
            'total' => 1,
            'done' => 0,
            'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_QUEUED,
            'force' => false,
        ]);

        $search = $this->searchMock();
        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn(['results' => [], 'errors' => []]);
        $this->app->instance(HybridWebSearchService::class, $search);

        $held = Cache::lock('enrichment_prefetch_gate:0', 180);
        $this->assertTrue((bool) $held->get());

        (new PrefetchProductSourcesJob($product->id, $batch->id))
            ->handle(app(ProductEnrichmentService::class), app(PrefetchSlots::class));

        Queue::assertPushed(EnrichProductJob::class, 1);
        Queue::assertNotPushed(PrefetchProductSourcesJob::class);
        $held->release();
    }

    public function test_process_batch_item_uses_sku_cache(): void
    {
        Queue::fake();
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        ProductEnrichmentCache::query()->create([
            'manufacturer' => 'ansell',
            'sku' => 'pool-1',
            'description' => 'Opis z cache SKU.',
            'enrichment_payload' => ['features' => ['x'], 'from_cache' => false],
            'image_urls' => [],
            'source_urls' => ['https://example.com/p'],
        ]);

        $product = $this->makeProduct([
            'sku' => 'POOL-1',
            'manufacturer' => 'Ansell',
        ]);

        $queued = $this->postJson('/api/products/enrich', [
            'product_ids' => [$product->id],
        ])->assertStatus(202);

        $batchId = (int) $queued->json('batch.id');

        $this->postJson("/api/product-enrichment-batches/{$batchId}/items/{$product->id}")
            ->assertOk()
            ->assertJsonPath('batch.done', 1);

        $this->assertSame(Product::ENRICHMENT_DONE, $product->fresh()?->enrichment_status);
    }

    public function test_enrichment_limits_include_concurrency(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://api.openai.com/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'gpt-4o-mini',
            'timeout_seconds' => 90,
            'temperature' => 0.1,
            'match_concurrency' => 12,
            'enrichment_batch_limit' => 20,
        ]);

        $this->getJson('/api/product-enrichment/limits')
            ->assertOk()
            ->assertJsonPath('match_concurrency', 12)
            ->assertJsonPath('enrichment_batch_limit', 20);
    }

    public function test_products_index_includes_enrichment_columns(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->makeProduct([
            'description' => 'Opis testowy',
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.enrichment_status', Product::ENRICHMENT_DONE)
            ->assertJsonPath('data.0.description', 'Opis testowy')
            ->assertJsonStructure(['data' => [['images_count', 'images']]]);
    }

    public function test_enrichment_uses_sku_cache_without_search(): void
    {
        Storage::fake('public');

        ProductEnrichmentCache::query()->create([
            'manufacturer' => 'ansell',
            'sku' => 'cache-1',
            'description' => 'Opis z cache SKU.',
            'enrichment_payload' => ['features' => ['x'], 'from_cache' => false],
            'image_urls' => [],
            'source_urls' => ['https://example.com/p'],
        ]);

        $product = $this->makeProduct([
            'sku' => 'CACHE-1',
            'manufacturer' => 'Ansell',
        ]);

        $search = $this->searchMock();
        $search->shouldNotReceive('searchBothPhases');

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldNotReceive('chatJson');

        $service = new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            $llm,
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            app(ProductImageCandidateVerifier::class),
            app(PpeAssortment::class),
        );

        $service->enrichProduct($product, false);

        $product->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status);
        $this->assertSame('Opis z cache SKU.', $product->description);
        $this->assertTrue((bool) ($product->enrichment_payload['from_cache'] ?? false));
    }

    public function test_shop_source_url_skips_sku_cache_and_fetches_hinted_page(): void
    {
        Storage::fake('public');

        ProductEnrichmentCache::query()->create([
            'manufacturer' => 'ansell',
            'sku' => 'cache-hint',
            'description' => 'Opis z cache SKU — nie ten sklep.',
            'enrichment_payload' => ['features' => ['x'], 'from_cache' => false],
            'image_urls' => [],
            'source_urls' => ['https://example.com/p'],
        ]);

        $product = $this->makeProduct([
            'sku' => 'CACHE-HINT',
            'name' => 'Rękawice testowe',
            'manufacturer' => 'Ansell',
            'shop_source_url' => 'https://hint.example.com/karta-cache-hint',
        ]);

        $search = $this->searchMock();
        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn(['results' => [], 'errors' => ['Brak stron produktu']]);
        $search->shouldReceive('forgetProductCache')->zeroOrMoreTimes();

        $llm = $this->mockLlmWithSanitize([
            'description' => 'Rękawice nitrylowe Ansell CACHE-HINT ze wskazanego sklepu. Spełniają EN 388 i chronią przed ścieraniem. Przeznaczone do montażu oraz prac precyzyjnych w warunkach suchych. Trwała powłoka nitrylowa zwiększa żywotność przy codziennym użytkowaniu.',
            'features' => ['nitryl'],
            'specs' => ['SKU: CACHE-HINT'],
            'norms' => ['EN 388'],
            'certificates' => [],
            'materials' => ['nitryl'],
            'use_cases' => ['montaż'],
            'image_urls' => [],
            'source_urls' => ['https://hint.example.com/karta-cache-hint'],
            'confidence' => 0.8,
        ]);

        $service = new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            $llm,
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            app(ProductImageCandidateVerifier::class),
            app(PpeAssortment::class),
        );

        Http::fake([
            'https://hint.example.com/*' => Http::response(
                '<html><body><h1>Ansell Rękawice testowe CACHE-HINT</h1><p>'
                .str_repeat('Rękawice nitrylowe Ansell CACHE-HINT EN 388. ', 40)
                .'</p></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $service->enrichProduct($product, false);

        $product->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status);
        $this->assertNotSame('Opis z cache SKU — nie ten sklep.', $product->description);
        $this->assertFalse((bool) ($product->enrichment_payload['from_cache'] ?? false));
        $this->assertStringContainsString('CACHE-HINT', (string) $product->description);
    }

    public function test_hinted_shoper_card_saves_image_when_description_is_thin(): void
    {
        Storage::fake('public');

        $pageUrl = 'https://centrumelektronarzedzi.pl/pl/p/Chodnik-elektroizolacyjny-20-KV-wymiary-1%2C1-x-8-m-Secura/48607';
        $img = 'https://centrumelektronarzedzi.pl/userdata/public/gfx/46771/Chodnik-i-dywanik-elektroizolacyjny.jpg';
        $product = $this->makeProduct([
            'sku' => 'CH-20KV-8',
            'name' => 'Chodnik elektroizolacyjny 20 KV (wymiary 1,1 x 8 m) Secura',
            'manufacturer' => 'SECURA',
            'shop_source_url' => $pageUrl,
        ]);

        $search = $this->searchMock();
        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn(['results' => [], 'errors' => ['Brak stron produktu']]);
        $search->shouldReceive('forgetProductCache')->zeroOrMoreTimes();

        $llm = $this->mockLlmWithSanitize([
            'description' => 'Chodnik Secura.',
            'features' => [],
            'specs' => [],
            'norms' => [],
            'certificates' => [],
            'materials' => [],
            'use_cases' => [],
            'image_urls' => [],
            'source_urls' => [$pageUrl],
            'confidence' => 0.2,
        ]);

        $html = '<html><head>'
            .'<meta property="og:image" content="https://centrumelektronarzedzi.pl/upload/img/seo/centrumelektronarzedzi-pl.png">'
            .'<script type="application/ld+json">{"image":["https:\\/\\/centrumelektronarzedzi.pl\\/userdata\\/public\\/gfx\\/46771\\/Chodnik-i-dywanik-elektroizolacyjny.jpg"]}</script>'
            .'</head><body>'
            .'<h1>Chodnik elektroizolacyjny 20 KV (wymiary 1,1 x 8 m) Secura</h1>'
            .'<div class="description newsletter__description">Podaj swój adres e-mail, jeżeli chcesz otrzymywać informacje o nowościach.</div>'
            .'<div class="resetcss"><p>Chodniki elektroizolacyjne w kl. 2 są przeznaczone do wykładania podłóg w celu ochrony pracowników przed zagrożeniami elektrycznymi. Wymiary 1,1 x 8 m. Marka Secura. Klasa 2.</p></div>'
            .'<a href="'.$img.'"><img src="https://centrumelektronarzedzi.pl/environment/cache/images/productGfx_46771_750_750/Chodnik-i-dywanik-elektroizolacyjny.webp" alt="Chodnik"></a>'
            .'</body></html>';

        Http::fake(function (Request $request) use ($html) {
            $url = $request->url();
            if (str_contains($url, '/userdata/') || str_contains($url, 'productGfx') || str_ends_with($url, '.jpg') || str_ends_with($url, '.webp')) {
                return Http::response($this->tinyJpeg(), 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response($html, 200, ['Content-Type' => 'text/html']);
        });

        $service = new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            $llm,
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            app(ProductImageCandidateVerifier::class),
            app(PpeAssortment::class),
        );

        $service->enrichProduct($product, false);

        $product->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status);
        $this->assertSame(1, $product->images()->count());
        $this->assertStringContainsString('userdata/public/gfx/46771', (string) $product->images()->first()?->source_url);
    }

    public function test_weak_index_hit_does_not_block_the_open_web_search(): void
    {
        // Indeks trafil „…-ac01-p-5502” samym „ac01”. Tresc tej karty produktu nie
        // potwierdza, a wczesniej takie trafienie zamykalo droge do wyszukiwarki —
        // akcesorium konczylo na „wpisz recznie” bez jednego zapytania do internetu.
        $indexUrl = 'https://pol-paw.pl/rekawica-kolczugowa-1-sztuka-ac01-p-5502.html';
        $product = $this->makeProduct([
            'sku' => 'AC01P-00022-00-N0C',
            'name' => 'AVNT PASSTHRU WHSTL & RECTUS 96KS',
            'manufacturer' => 'Ansell',
        ]);

        $search = $this->searchMock();
        $search->shouldReceive('searchBothPhases')
            ->andReturn([
                'results' => [[
                    'url' => $indexUrl,
                    'title' => 'Rekawica kolczugowa AC01',
                    'snippet' => 'Rekawica kolczugowa jednoczesciowa',
                ]],
                'errors' => [],
            ]);
        // to jest sedno testu: internet musi zostac zapytany mimo trafienia w indeksie
        $search->shouldReceive('searchWebWithoutLocalIndex')
            ->once()
            ->andReturn(['results' => [], 'images' => [], 'errors' => []]);

        // zadna strona sie nie potwierdzi, wiec model opisu moze nie byc wolany wcale
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonEnrichment')->zeroOrMoreTimes()->andReturn([]);
        $llm->shouldReceive('chatJson')->zeroOrMoreTimes()->andReturn([]);
        $llm->shouldReceive('chatJsonWithImages')->zeroOrMoreTimes()->andReturn(['candidates' => []]);

        Http::fake(['*' => Http::response(
            '<html><body><h1>Rekawica kolczugowa AC01</h1>'
            .'<p>Rekawica kolczugowa jednoczesciowa ze stali nierdzewnej.</p></body></html>',
            200,
            ['Content-Type' => 'text/html']
        )]);

        $service = new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            $llm,
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            app(ProductImageCandidateVerifier::class),
            app(PpeAssortment::class),
        );

        try {
            $service->enrichProduct($product, false);
        } catch (ProductSourcesNotFoundException) {
            // karty i tak nie ma — liczy sie to, ze internet zostal zapytany
        }

        $search->shouldHaveReceived('searchWebWithoutLocalIndex');
    }

    public function test_measurement_is_not_a_code_and_glossary_norms_are_dropped(): void
    {
        $service = app(ProductEnrichmentService::class);
        $belt = $this->makeProduct([
            'sku' => 'AC01P-00014-00',
            'name' => 'GREY PES BLT & YKK BCKL LENGTH 150CM',
            'manufacturer' => 'Ansell',
        ]);

        // „150cm” wygladalo jak kod (cyfry + litery) i wazylo 2 — razem z „length”
        // wystarczalo, zeby tekst o sznurowkach „wymienial” pas
        $tokens = new ReflectionMethod($service, 'discriminativeNameTokens');
        $tokens->setAccessible(true);
        $weights = $tokens->invoke($service, $belt);
        $this->assertArrayNotHasKey('150cm', $weights);
        $this->assertArrayNotHasKey('length', $weights);

        // slowniczek klas obuwia sklepu — siedem norm z surowego tekstu to lista
        // standardow sklepu, nie normy produktu
        $enrich = new ReflectionMethod($service, 'enrichStructuredFieldsFromPages');
        $enrich->setAccessible(true);
        $glossary = 'S1 - All SB + Antistatic EN 20345 Footwear Properties S1, EN 20347 Occupational Footwear O1,'
            .' EN 13832 Chemical Protective Footwear, EN 13832-2 Limited Contact, EN 13832-3 Prolonged Contact,'
            .' EN 17249 Chainsaw Cut Resistant Footwear, EN 15090 Firefighter Footwear';
        $out = $enrich->invoke($service, ['norms' => []], [['url' => 'https://shop.example/x', 'text' => $glossary]]);
        $this->assertSame([], $out['norms']);

        // dwie normy z tresci karty zostaja
        $card = 'Rekawice antyprzecieciowe, EN 388:2016 4X43C, EN 407 X1XXXX.';
        $out = $enrich->invoke($service, ['norms' => []], [['url' => 'https://shop.example/y', 'text' => $card]]);
        $this->assertCount(2, $out['norms']);
    }

    public function test_open_web_keeps_exact_name_hits_next_to_sibling_code_hits(): void
    {
        // Adresy z przebiegu AC01P-00022-00-N0C na produkcji: hahn-kolb niesie kod
        // rodzenstwa (-N00), regalbau i lms-lab dokladna nazwe, rectus to zlaczka
        // innego producenta.
        $product = $this->makeProduct([
            'sku' => 'AC01P-00022-00-N0C',
            'name' => 'AVNT PASSTHRU WHSTL & RECTUS 96KS',
            'manufacturer' => 'Ansell',
        ]);
        $sibling = 'https://www.hahn-kolb.net/ANSELL-Avant-pass-through-with-whistle-no-connector-AC01P-00022-00-N00/95282540.sku/cs/CZ/EUR/';
        $named1 = 'https://www.regalbau-service.de/ANSELL-AVNT-PASSTHRU-WHSTL-RECTUS-96KS';
        $named2 = 'https://www.lms-lab.de/en/avnt-passthru-whstl-rectus-96ks/2593587';
        $foreign = 'https://rectus.pl/produkty/szybkozlacze-typ-96ks/';
        $hits = [];
        foreach ([$foreign, $named1, $sibling, $named2] as $url) {
            $hits[] = ['url' => $url, 'title' => '', 'snippet' => ''];
        }

        $hybrid = app(HybridWebSearchService::class);
        $pick = new ReflectionMethod($hybrid, 'codedThenNamed');
        $pick->setAccessible(true);
        $coded = [['url' => $sibling, 'title' => '', 'snippet' => '']];
        $urls = array_column($pick->invoke($hybrid, $hits, $coded, $product), 'url');

        $this->assertSame([$sibling, $named1, $named2], $urls);
    }

    public function test_empty_description_from_index_cards_still_reaches_open_web(): void
    {
        // Pas trafial w indeksie na karty potwierdzone adresem, model nie wyciagal
        // z nich opisu i produkt konczyl na "wpisz recznie" bez pytania internetu.
        $cardUrl = 'https://icd.pl/pas-ansell-ac01p-00014-00.html';
        $product = $this->makeProduct([
            'sku' => 'AC01P-00014-00',
            'name' => 'GREY PES BLT & YKK BCKL LENGTH 150CM',
            'manufacturer' => 'Ansell',
        ]);

        $search = $this->searchMock();
        $search->shouldReceive('searchBothPhases')
            ->andReturn(['results' => [['url' => $cardUrl, 'title' => 'Pas Ansell AC01P-00014-00', 'snippet' => '']], 'errors' => []]);
        $search->shouldReceive('searchWebWithoutLocalIndex')
            ->once()
            ->andReturn(['results' => [], 'images' => [], 'errors' => []]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonEnrichment')->zeroOrMoreTimes()->andReturn([
            'description' => '', 'features' => [], 'specs' => [], 'norms' => [], 'certificates' => [],
            'materials' => [], 'use_cases' => [], 'image_urls' => [], 'source_urls' => [], 'confidence' => 0.1,
        ]);
        $llm->shouldReceive('chatJson')->zeroOrMoreTimes()->andReturn([]);
        $llm->shouldReceive('chatJsonWithImages')->zeroOrMoreTimes()->andReturn(['candidates' => []]);

        Http::fake(['*' => Http::response(
            '<html><body><h1>Pas Ansell AC01P-00014-00</h1><p>Pas PES z klamra YKK, dlugosc 150 cm.</p></body></html>',
            200,
            ['Content-Type' => 'text/html']
        )]);

        $service = new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            $llm,
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            app(ProductImageCandidateVerifier::class),
            app(PpeAssortment::class),
        );

        try {
            $service->enrichProduct($product, false);
        } catch (ProductSourcesNotFoundException) {
            // karty i tak nie ma - liczy sie to, ze internet zostal zapytany
        }

        $search->shouldHaveReceived('searchWebWithoutLocalIndex');
    }

    public function test_site_queries_skip_shops_we_have_in_the_local_index(): void
    {
        Cache::flush();
        // O tym, czy sklep mamy u siebie, decyduja realne strony w catalog_pages.
        foreach (['icd.pl' => 'https://icd.pl/mata-super-mat-150cm-x-mb.html',
            'www.bhp-gabi.pl' => 'https://www.bhp-gabi.pl/p28924,gogle-ochronne-3m.html'] as $host => $url) {
            CatalogPage::query()->create([
                'host' => $host, 'url' => $url, 'url_hash' => hash('sha256', $url), 'haystack' => $url,
            ]);
        }
        // licznik w catalog_hosts zostal po skasowaniu stron (hahn-kolb: 250 000,
        // stron zero) — taki host NIE jest zaindeksowany i wolno o niego pytac
        CatalogHost::query()->create(['host' => 'hahn-kolb.net', 'pages_count' => 250000]);
        CatalogHost::query()->create(['host' => 'pusty-sklep.pl', 'pages_count' => 0]);

        $product = $this->makeProduct([
            'sku' => 'AC01P-00022-00-N0C',
            'name' => 'AVNT PASSTHRU WHSTL & RECTUS 96KS',
            'manufacturer' => 'Ansell',
        ]);

        $hybrid = app(HybridWebSearchService::class);
        $build = new ReflectionMethod($hybrid, 'openSearchQueries');
        $build->setAccessible(true);

        $queries = [
            'site:icd.pl AlphaTec Pass-through',
            'site:www.bhp-gabi.pl AlphaTec Pass-through',
            'site:hahn-kolb.net AlphaTec Pass-through',
            'site:nieznany-sklep.pl AlphaTec Pass-through',
            'AlphaTec Pass-through Ansell',
        ];

        $ladder = $build->invoke($hybrid, $product, $queries);
        $joined = implode(' | ', $ladder);

        // sklepy, ktore mamy u siebie w calosci - wyszukiwarka nic nie doda
        $this->assertStringNotContainsString('site:icd.pl', $joined);
        $this->assertStringNotContainsString('site:www.bhp-gabi.pl', $joined);
        // reszta idzie do wyszukiwarki jak dotad
        // drabinka bierze najwyzej SITE_QUERY_ATTEMPTS (4) zapytan site:, stad cztery hosty
        $this->assertStringContainsString('site:hahn-kolb.net', $joined, 'stary licznik bez stron nie czyni hosta zaindeksowanym');
        $this->assertStringContainsString('site:nieznany-sklep.pl', $joined);

        // ten sam filtr chroni druga sciezke - zapytania do zmapowanych sklepow
        $host = new ReflectionMethod($hybrid, 'siteQueryHost');
        $host->setAccessible(true);
        $indexed = new ReflectionMethod($hybrid, 'hostIsIndexedLocally');
        $indexed->setAccessible(true);
        // sciezka i „www.” w operatorze nie moga zmylic dopasowania hosta
        $this->assertSame('icd.pl', $host->invoke($hybrid, 'site:icd.pl/products AlphaTec'));
        $this->assertSame('bhp-gabi.pl', $host->invoke($hybrid, 'site:www.bhp-gabi.pl AlphaTec'));
        $this->assertTrue($indexed->invoke($hybrid, 'icd.pl'));
        $this->assertFalse($indexed->invoke($hybrid, 'hahn-kolb.net'));
        $this->assertFalse($indexed->invoke($hybrid, 'pusty-sklep.pl'));
        $this->assertFalse($indexed->invoke($hybrid, ''));

        // log przebiegu musi umiec nazwac sklepy, o ktore nie pytalismy
        $among = new ReflectionMethod($hybrid, 'indexedHostsAmong');
        $among->setAccessible(true);
        $this->assertSame(
            ['icd.pl', 'bhp-gabi.pl'],
            $among->invoke($hybrid, ['www.icd.pl', 'BHP-Gabi.pl', 'pusty-sklep.pl', 'nieznany-sklep.pl'])
        );

        // czyszczenie cache musi widziec pelna drabinke, tez wyciete site:
        $full = implode(' | ', $build->invoke($hybrid, $product, $queries, false));
        $this->assertStringContainsString('site:icd.pl', $full);
    }

    public function test_image_from_unconfirmed_card_needs_product_code_in_url(): void
    {
        // Adresy prosto z przebiegow na produkcji. Gdy opis nie potwierdzil
        // produktu, zdjecie wolno wziac tylko z karty niosacej kod produktu.
        $service = app(ProductEnrichmentService::class);
        $decide = new ReflectionMethod($service, 'cardsCarryProductCode');
        $decide->setAccessible(true);

        // pas 150 cm: karty mat i oslon kabli pasowaly samym „150cm”
        $belt = $this->makeProduct([
            'sku' => 'AC01P-00014-00',
            'name' => 'GREY PES BLT & YKK BCKL LENGTH 150CM',
            'manufacturer' => 'Ansell',
        ]);
        $this->assertFalse($decide->invoke($service, [
            ['url' => 'https://icd.pl/mata-monotone-150cm-x-mb.html', 'title' => 'Mata monotone 150cm'],
            ['url' => 'https://icd.pl/dancop-oslona-kabli-150cm.html', 'title' => 'Dancop oslona kabli 150cm'],
        ], $belt));

        // te same warunki, ale karta niesie kod — zdjecie jest wlasciwe
        $microflex = $this->makeProduct([
            'sku' => '93833100',
            'name' => 'MICROFLEX 93833 SIZE XL (9.5-10.0)',
            'manufacturer' => 'Ansell',
        ]);
        $this->assertTrue($decide->invoke($service, [
            ['url' => 'https://www.ansell.com/pl/pl/products/microflex-93-833', 'title' => 'MICROFLEX 93-833'],
        ], $microflex));

        $hyflex = $this->makeProduct([
            'sku' => '72286100',
            'name' => 'HYFLEX 72286',
            'manufacturer' => 'Ansell',
        ]);
        $this->assertTrue($decide->invoke($service, [
            ['url' => 'https://cas-technik.eu/hand-protect/cut-and-stab-protection-gloves/'
                .'ansell-profood-spectra-72-286-dyneema-cut-resistant-gloves/ih-72286-10',
                'title' => 'Ansell 72-286'],
        ], $hyflex));

        // kombinezon „102” dostal karte spodni Carhartt 102438
        $coverall = $this->makeProduct([
            'sku' => 'WH20B-00102-09',
            'name' => '2000-WH STD CVRL HOOD, LOOPS 102.5',
            'manufacturer' => 'Ansell',
        ]);
        $this->assertFalse($decide->invoke($service, [
            ['url' => 'https://workwearnation.com/products/carhartt-102438-rugged-flex-loose-fit-canvas-bib-overall', 'title' => ''],
        ], $coverall));

        // ochraniacze „400” dostaly karte butow Carhartt 400022
        $overshoes = $this->makeProduct([
            'sku' => 'WH25B-00400-00',
            'name' => '2500-WH STD OVERSHOES 400.42-46',
            'manufacturer' => 'Ansell',
        ]);
        $this->assertFalse($decide->invoke($service, [
            ['url' => 'https://www.bhp-gabi.pl/p34016,400022-001-buty-carhartt-greenfields-2-chelsea-boot.html', 'title' => ''],
        ], $overshoes));

        // ta sama rodzina kodow, ale karta producenta z modelem 417 — zostaje
        $bound = $this->makeProduct([
            'sku' => 'WH20B-00417-02',
            'name' => '2000-WH OVERSHOES 417.39-42',
            'manufacturer' => 'Ansell',
        ]);
        $this->assertTrue($decide->invoke($service, [
            ['url' => 'https://www.ansell.com/pl/pl/products/alphatec-2000-standard-overshoes-bound-model-417', 'title' => ''],
        ], $bound));

        // recznie wskazany adres sklepu zostaje zaufany — to czlowiek go wybral
        $hinted = $this->makeProduct([
            'sku' => 'AC01P-00014-05',
            'name' => 'GREY PES BLT & YKK BCKL LENGTH 150CM',
            'manufacturer' => 'Ansell',
            'shop_source_url' => 'https://icd.pl/dancop-oslona-kabli-150cm.html',
        ]);
        $this->assertTrue($decide->invoke($service, [], $hinted));
    }

    public function test_confirmed_card_saves_page_description_when_llm_drops_model(): void
    {
        Storage::fake('public');

        $pageUrl = 'https://bpbhp.pl/kombinezon-ansell-alphatec-4000-model-151';
        $img = 'https://bpbhp.pl/media/catalog/product/a/l/alphatec-4000-151.jpg';
        $product = $this->makeProduct([
            'sku' => 'GR40T-00151-09',
            'name' => '4000-GR CVRL FACESEAL 151.5XL',
            'manufacturer' => 'ANSELL',
        ]);

        $search = $this->searchMock();
        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn([
                'results' => [[
                    'url' => $pageUrl,
                    'title' => 'Kombinezon Ansell AlphaTec 4000 model 151',
                    'snippet' => 'Kombinezon chemoodporny AlphaTec 4000',
                ]],
                'errors' => [],
            ]);
        $search->shouldReceive('forgetProductCache')->zeroOrMoreTimes();
        $search->shouldReceive('dropListingResults')
            ->zeroOrMoreTimes()
            ->andReturnUsing(static fn (array $results): array => $results);

        $llm = $this->mockLlmWithSanitize([
            'description' => 'Kombinezon chemiczny.',
            'features' => [],
            'specs' => [],
            'norms' => [],
            'certificates' => [],
            'materials' => [],
            'use_cases' => [],
            'image_urls' => [],
            'source_urls' => [$pageUrl],
            'confidence' => 0.2,
        ]);

        $html = '<html><head>'
            .'<meta property="og:image" content="'.$img.'">'
            .'</head><body>'
            .'<h1>Kombinezon Ansell AlphaTec 4000 model 151</h1>'
            .'<div class="product-description">'
            .'Innowacyjna wielowarstwowa bariera przed chemikaliami, ochrona typu 3/4/5. '
            .'Doskonała ochrona przed przenikaniem ponad 200 substancji chemicznych. '
            .'Zgrzewane i zabezpieczone taśmą szwy to najskuteczniejsza bariera chroniąca przed cieczami. '
            .'Antyelektrostatyczny — testowany zgodnie z normą EN 1149-5. '
            .'Przeznaczony do pracy ze środkami chemicznymi oraz w procesach ratunkowych.'
            .'</div>'
            .'<img src="'.$img.'" alt="AlphaTec 4000">'
            .'</body></html>';

        Http::fake(function (Request $request) use ($html) {
            $url = $request->url();
            if (str_contains($url, '.jpg') || str_contains($url, 'alphatec-4000-151')) {
                return Http::response($this->tinyJpeg(), 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response($html, 200, ['Content-Type' => 'text/html']);
        });

        $service = new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            $llm,
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            app(ProductImageCandidateVerifier::class),
            app(PpeAssortment::class),
        );

        $service->enrichProduct($product, false);

        $product->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status);
        $this->assertStringContainsString('wielowarstwowa bariera', (string) $product->description);
        $this->assertNotSame('Zdjęcie z karty sklepu. Opis wpisz ręcznie.', (string) $product->enrichment_error);
    }

    public function test_expert_description_is_not_replaced_by_shopify_price_dump(): void
    {
        Storage::fake('public');

        $pageUrl = 'https://artra.pl/products/3815448-armen-9003-6660-s1-esd';
        $img = 'https://artra.pl/cdn/armen-9003.jpg';
        $product = $this->makeProduct([
            'sku' => 'ARMEN 9003 6660 S1 ESD',
            'name' => 'Półbuty ARMEN 9003 6660 S1 ESD',
            'manufacturer' => 'ARTRA',
        ]);
        $expert = 'Półbuty ARMEN 9003 6660 S1 ESD to obuwie bezpieczne z kompozytowym podnoskiem LIBERYUM. '
            .'Cholewka PURYA SKINYUM i podeszwa LYFTOR spełniają EN ISO 20345:2022 S1 FO SR. '
            .'Przeznaczone do stref ESD, montażu i logistyki.';

        $search = $this->searchMock();
        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn([
                'results' => [[
                    'url' => $pageUrl,
                    'title' => 'ARMEN 9003 6660 S1 ESD ARTRA',
                    'snippet' => 'Półbuty robocze ARMEN 9003 S1 ESD',
                ]],
                'errors' => [],
            ]);
        $search->shouldReceive('forgetProductCache')->zeroOrMoreTimes();
        $search->shouldReceive('dropListingResults')
            ->zeroOrMoreTimes()
            ->andReturnUsing(static fn (array $results): array => $results);

        $llm = $this->mockLlmWithSanitize([
            'description' => $expert,
            'features' => ['Kompozytowy podnosek LIBERYUM'],
            'specs' => ['Klasa: S1', 'Rozmiary: EU 35-48'],
            'norms' => ['EN ISO 20345:2022 S1 FO SR'],
            'certificates' => [],
            'materials' => ['PURYA SKINYUM'],
            'use_cases' => ['Strefy ESD', 'Montaż'],
            'image_urls' => [$img],
            'source_urls' => [$pageUrl],
            'confidence' => 0.9,
        ]);

        $html = '<html><head><meta property="og:image" content="'.$img.'"></head><body>'
            .'<div class="product-description">'
            .'<h1>ARMEN 9003 6660 S1 ESD</h1>'
            .'<p>EU 35 - 309 złEU 36 - 309 złEU 37 - 309 złEU 38 - 309 złEU 39 - 309 zł'
            .'EU 40 - 309 złEU 41 - 309 złEU 42 - 309 zł Wariant</p>'
            .'<p>Półbuty ARMEN 9003 6660 S1 ESD z podnoskiem LIBERYUM. EN ISO 20345.</p>'
            .'</div>'
            .'<img src="'.$img.'" alt="ARMEN 9003">'
            .'</body></html>';

        Http::fake(function (Request $request) use ($html) {
            if (str_contains($request->url(), '.jpg')) {
                return Http::response($this->tinyJpeg(), 200, ['Content-Type' => 'image/jpeg']);
            }

            return Http::response($html, 200, ['Content-Type' => 'text/html']);
        });

        $service = new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            $llm,
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            app(ProductImageCandidateVerifier::class),
            app(PpeAssortment::class),
        );

        $service->enrichProduct($product, false);

        $product->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status);
        $this->assertStringContainsString('podnoskiem LIBERYUM', (string) $product->description);
        $this->assertStringContainsString('EN ISO 20345', (string) $product->description);
        $this->assertStringNotContainsString('309 zł', (string) $product->description);
        $this->assertStringNotContainsString('Wariant', (string) $product->description);
    }

    public function test_product_absent_from_web_goes_to_manual_and_leaves_queues(): void
    {
        Sanctum::actingAs($user = User::factory()->withRole('admin')->create());

        $product = $this->makeProduct([
            'sku' => 'UVEX-GK-PROGR-CR39',
            'manufacturer' => 'UVEX',
            'description' => null,
        ]);

        $search = $this->searchMock();
        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn(['results' => [], 'errors' => ['Brak stron produktu']]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonEnrichment')->never();

        $service = new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            $llm,
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            app(ProductImageCandidateVerifier::class),
            app(PpeAssortment::class),
        );

        try {
            $service->enrichProduct($product, false);
            $this->fail('Oczekiwano ProductSourcesNotFoundException.');
        } catch (ProductSourcesNotFoundException) {
            // status ma zostać ustawiony mimo wyjątku
        }

        $product->refresh();
        $this->assertSame(Product::ENRICHMENT_MANUAL, $product->enrichment_status);

        $this->getJson('/api/products/catalog-health')
            ->assertOk()
            ->assertJsonPath('manual_review', 1)
            ->assertJsonPath('missing_description', 0);

        // ponowne kolejkowanie ma go pominąć, ręczne wymuszenie nadal działa
        $this->expectException(RuntimeException::class);
        app(ProductEnrichmentService::class)->enqueueProductIds([$product->id], $user, false);
    }

    public function test_empty_search_does_not_invent_description_without_card(): void
    {
        $product = $this->makeProduct([
            'sku' => '23201',
            'name' => 'AlphaTec 23201',
            'manufacturer' => 'Ansell',
            'description' => null,
        ]);

        $search = $this->searchMock();
        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn(['results' => [], 'errors' => ['Brak stron produktu']]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonEnrichment')->never();

        $service = new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            $llm,
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            app(ProductImageCandidateVerifier::class),
            app(PpeAssortment::class),
        );

        try {
            $service->enrichProduct($product, false);
            $this->fail('Oczekiwano ProductSourcesNotFoundException.');
        } catch (ProductSourcesNotFoundException $e) {
            $this->assertStringContainsString('bez strony', $e->getMessage());
        }
        $fresh = $product->fresh();
        $this->assertNotSame(Product::ENRICHMENT_DONE, $fresh?->enrichment_status);
        $this->assertIsArray($fresh?->enrichment_trace);
        $types = array_column($fresh?->enrichment_trace['steps'] ?? [], 't');
        $this->assertContains('start', $types);
        $this->assertContains('search', $types);
        $this->assertContains('fail', $types);
    }

    public function test_force_without_sign_card_clears_foreign_clothing_description(): void
    {
        $product = $this->makeProduct([
            'sku' => 'T-31',
            'name' => 'Tablica pionowa AED + krok po kroku ZIELONA',
            'manufacturer' => 'CABINAID',
            'category' => 'Tablice / Oznakowanie',
            'description' => 'Koszula flanelowa ARDON URBAN+ kod T-31, 100% bawełna, rozmiary S-4XL.',
            'enrichment_payload' => ['attributes' => ['rozmiar' => 's-xl']],
            'enrichment_status' => Product::ENRICHMENT_MANUAL,
        ]);

        $search = $this->searchMock();
        $search->shouldReceive('forgetProductCache')->once();
        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn(['results' => [], 'errors' => ['Brak stron produktu']]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonEnrichment')->never();

        $service = new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            $llm,
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            app(ProductImageCandidateVerifier::class),
            app(PpeAssortment::class),
        );

        try {
            $service->enrichProduct($product, true);
            $this->fail('Oczekiwano ProductSourcesNotFoundException.');
        } catch (ProductSourcesNotFoundException) {
        }

        $product->refresh();
        $this->assertSame(Product::ENRICHMENT_MANUAL, $product->enrichment_status);
        $this->assertSame('', (string) $product->description);
        $this->assertNull($product->enrichment_payload);
        $this->assertNotEmpty($product->enrichment_error);
        $this->assertIsArray($product->enrichment_trace);
        $this->assertNotEmpty($product->enrichment_trace['steps'] ?? []);
    }

    public function test_failed_force_keeps_photo_when_old_description_is_ours(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/arya.jpg', 'x');
        $product = $this->makeProduct([
            'sku' => 'ARYA 300 673560 S1 P',
            'name' => 'ARYA 300 673560 S1 PL',
            'manufacturer' => 'ARTRA',
            'category' => 'Obuwie',
            'description' => 'Obuwie ochronne ARTRA ARYA 300 673560 S1 PL z kompozytowym podnoskiem LIBERYUM, '
                .'norma EN ISO 20345:2022 S1 PL FO SR.',
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
        $product->images()->create([
            'path' => 'products/arya.jpg',
            'source_url' => 'https://artra.pl/arya.jpg',
            'is_primary' => true,
            'sort_order' => 0,
            'checksum' => sha1('x'),
        ]);

        $this->failForcedEnrichmentWithoutCard($product);

        $product->refresh();
        $this->assertSame(1, $product->images()->count());
        Storage::disk('public')->assertExists('products/arya.jpg');
        $this->assertStringContainsString('ARYA 300', (string) $product->description);
    }

    public function test_failed_force_clears_photo_with_foreign_description(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('products/koszula.jpg', 'x');
        $product = $this->makeProduct([
            'sku' => 'T-31',
            'name' => 'Tablica pionowa AED + krok po kroku ZIELONA',
            'manufacturer' => 'CABINAID',
            'category' => 'Tablice / Oznakowanie',
            'description' => 'Koszula flanelowa ARDON URBAN+ kod T-31, 100% bawełna, rozmiary S-4XL.',
            'enrichment_status' => Product::ENRICHMENT_MANUAL,
        ]);
        $product->images()->create([
            'path' => 'products/koszula.jpg',
            'source_url' => 'https://ardon.pl/koszula.jpg',
            'is_primary' => true,
            'sort_order' => 0,
            'checksum' => sha1('x'),
        ]);

        $this->failForcedEnrichmentWithoutCard($product);

        $product->refresh();
        $this->assertSame(0, $product->images()->count());
        Storage::disk('public')->assertMissing('products/koszula.jpg');
        $this->assertSame('', (string) $product->description);
    }

    private function failForcedEnrichmentWithoutCard(Product $product): void
    {
        $search = $this->searchMock();
        $search->shouldReceive('forgetProductCache')->once();
        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn(['results' => [], 'errors' => ['Brak stron produktu']]);
        $search->shouldReceive('dropListingResults')
            ->andReturnUsing(static fn (array $results): array => $results);
        $search->shouldReceive('searchMappedRetailers')->andReturn([]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonEnrichment')->never();

        $service = new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            $llm,
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            app(ProductImageCandidateVerifier::class),
            app(PpeAssortment::class),
        );

        try {
            $service->enrichProduct($product, true);
            $this->fail('Oczekiwano ProductSourcesNotFoundException.');
        } catch (ProductSourcesNotFoundException) {
        }
    }

    public function test_search_snippet_of_blocked_card_is_not_a_confirmed_card(): void
    {
        // ansell.com za Incapsulą, reader chwilowo nie odpowiada — zostaje sam fragment
        // z wyszukiwarki. Uznany za kartę samym adresem kończył szukanie pustym opisem.
        $url = 'https://www.ansell.com/pl/pl/products/ringers-r259';
        Http::fake([
            'www.ansell.com/*' => Http::response(
                '<html><head><script src="/_Incapsula_Resource?SWJIYLWA=1"></script></head>'
                .'<body>Request unsuccessful. Incapsula incident ID: 1</body></html>',
                200
            ),
            'r.jina.ai/*' => Http::response('Rate limit exceeded', 429),
            '*' => Http::response('', 404),
        ]);
        $product = $this->makeProduct([
            'sku' => '259-13',
            'name' => 'Ringers 259 Size 13.0',
            'manufacturer' => 'Ansell',
        ]);

        $search = $this->searchMock();
        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn(['results' => [[
                'url' => $url,
                'title' => 'RINGERS R259',
                'snippet' => 'Wytrzymałe rękawice udarowe RINGERS R259',
            ]]]);
        $search->shouldReceive('dropListingResults')
            ->andReturnUsing(static fn (array $results): array => $results);
        // karta nie potwierdzona — szukanie idzie dalej, zamiast kończyć się na fragmencie
        $search->shouldReceive('moreCatalogHits')->atLeast()->once()->andReturn([]);
        $search->shouldReceive('searchMappedRetailers')->once()->andReturn([]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonEnrichment')->never();

        $service = new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            $llm,
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            app(ProductImageCandidateVerifier::class),
            app(PpeAssortment::class),
        );

        try {
            $service->enrichProduct($product);
            $this->fail('Oczekiwano ProductSourcesNotFoundException.');
        } catch (ProductSourcesNotFoundException $e) {
            // zapora + padniety reader: czlowiek dostaje adres karty do otwarcia,
            // zamiast golego „nie znaleziono” — fragment z wyszukiwarki nadal nie jest karta
            $this->assertStringContainsString('ringers-r259', $e->getMessage());
            $this->assertStringContainsString('sklep blokuje pobieranie', $e->getMessage());
            $this->assertStringContainsString('wpisz opis ręcznie', $e->getMessage());
        }
    }

    public function test_card_text_that_does_not_name_product_is_not_a_description(): void
    {
        $service = app(ProductEnrichmentService::class);
        $method = new ReflectionMethod($service, 'usableCardDescription');
        $method->setAccessible(true);

        // ekran błędu sklepu Ansell zapisywał się jako opis HyFlex ze statusem „Gotowe”
        $hyflex = $this->makeProduct([
            'sku' => '11819PRO110',
            'name' => 'HyFlex 11819PRO SIZE 11,0',
            'manufacturer' => 'Ansell',
        ]);
        $junk = [[
            'url' => 'https://shop.ansell.com/eu/s/product/hyflex-1181',
            'text' => "ANSELL | Protection solutions, gloves, personal protective equipment across Europe\n\n"
                .'ANSELL Protection solutions, gloves, personal protective equipment across Europe '
                .'Loading ×Sorry to interrupt CSS Error',
        ]];
        $this->assertSame('', $method->invoke($service, $junk, $hyflex));

        // prawdziwa karta, która nazywa produkt, dalej daje opis
        $ringers = $this->makeProduct([
            'sku' => '259-13',
            'name' => 'Ringers 259 Size 13.0',
            'manufacturer' => 'Ansell',
        ]);
        $card = [[
            'url' => 'https://www.ansell.com/pl/pl/products/ringers-r259',
            'text' => 'RINGERS™ R259 to wytrzymałe rękawice robocze o konstrukcji z TPR (gumy termoplastycznej) '
                .'i technologii F3™, które zapewniają ochronę przed uderzeniami oraz sprawność manualną i wygodę. '
                .'Wykonana z syntetycznej skóry dłoń zapewnia lepszą przyczepność i odporność na ścieranie. '
                .'Dodatkowa warstwa dłoni z Kevlaru™ zapewnia odporność na przecięcia na poziomie EN 388 E '
                .'i ANSI/ISEA A5. Przedłużone neoprenowe zapięcie na nadgarstku zapewnia bezpieczne dopasowanie. '
                .'Przeznaczone do obsługi ciężkiego sprzętu w przemyśle naftowym, gazowym i górniczym.',
        ]];
        $this->assertNotSame('', $method->invoke($service, $card, $ringers));
    }

    public function test_empty_description_from_confirmed_card_tries_next_catalog_cards(): void
    {
        Storage::fake('public');

        $junkUrl = 'https://sklepa.example.com/karta-retry-1';
        $goodUrl = 'https://sklepb.example.com/karta-retry-1';

        // karta potwierdza produkt nazwą i producentem, ale treść to ekran błędu sklepu —
        // opis wychodził pusty i produkt kończył jako „nie znaleziono”, mimo że karta była
        Http::fake([
            'sklepa.example.com/*' => Http::response(
                '<html><body><h1>Ansell Rękawice testowe RETRY-1</h1><p>'
                .str_repeat('Loading ×Sorry to interrupt CSS Error ', 30)
                .'</p></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'sklepb.example.com/*' => Http::response(
                '<html><body><h1>Ansell Rękawice testowe RETRY-1</h1><p>'
                .str_repeat('Rękawice RETRY-1 marki Ansell chronią dłonie przy pracach montażowych. ', 12)
                .'</p></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            '*' => Http::response('', 404),
        ]);

        $product = $this->makeProduct([
            'sku' => 'RETRY-1',
            'name' => 'Rękawice testowe RETRY-1',
            'manufacturer' => 'Ansell',
        ]);

        $search = $this->searchMock();
        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn(['results' => [[
                'url' => $junkUrl,
                'title' => 'Rękawice testowe RETRY-1 Ansell',
                'snippet' => 'Rękawice testowe RETRY-1 Ansell',
            ]]]);
        $rounds = 0;
        $search->shouldReceive('moreCatalogHits')
            ->atLeast()
            ->once()
            ->andReturnUsing(static function () use (&$rounds, $goodUrl): array {
                $rounds++;

                return $rounds === 1 ? [[
                    'url' => $goodUrl,
                    'title' => 'Rękawice testowe RETRY-1 Ansell',
                    'snippet' => 'Rękawice testowe RETRY-1 Ansell',
                ]] : [];
            });

        $good = 'Rękawice ochronne Ansell RETRY-1 przeznaczone do prac montażowych i precyzyjnych. '
            .'Powłoka nitrylowa zapewnia pewny chwyt oraz odporność na ścieranie. Spełniają normę EN 388 '
            .'i chronią dłonie przy codziennej pracy w warsztacie oraz na produkcji.';
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $handler = function (array $messages) use ($good, $goodUrl): array {
            $system = (string) ($messages[0]['content'] ?? '');
            $user = (string) ($messages[1]['content'] ?? '');
            if (str_contains($system, 'filtrem treści')) {
                $pages = [];
                if (preg_match_all('#"url"\s*:\s*"(https?://[^"]+)"#', $user, $m)) {
                    foreach ($m[1] as $url) {
                        $pages[] = [
                            'url' => $url,
                            'text' => str_contains($url, 'sklepb')
                                ? $good
                                : 'Loading ×Sorry to interrupt CSS Error',
                        ];
                    }
                }

                return ['pages' => $pages];
            }

            // z pierwszej karty model nie ma czego wyciągnąć, z drugiej już tak
            if (! str_contains($user, 'sklepb')) {
                return ['description' => '', 'features' => [], 'specs' => [], 'norms' => []];
            }

            return [
                'description' => $good,
                'features' => ['nitryl'],
                'specs' => ['SKU: RETRY-1'],
                'norms' => ['EN 388'],
                'certificates' => [],
                'materials' => ['nitryl'],
                'use_cases' => ['montaż'],
                'image_urls' => [],
                'source_urls' => [$goodUrl],
                'confidence' => 0.8,
            ];
        };
        $llm->shouldReceive('chatJsonEnrichment')->atLeast()->once()->andReturnUsing($handler);
        $llm->shouldReceive('chatJson')->zeroOrMoreTimes()->andReturnUsing($handler);
        $llm->shouldReceive('chatJsonWithImages')->zeroOrMoreTimes()->andReturn(['candidates' => []]);

        $service = new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            $llm,
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            app(ProductImageCandidateVerifier::class),
            app(PpeAssortment::class),
        );

        $service->enrichProduct($product, false);

        $product->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status);
        $this->assertStringContainsString('RETRY-1', (string) $product->description);
        $this->assertStringNotContainsString('Sorry to interrupt', (string) $product->description);
    }

    public function test_prompt_names_manufacturer_model_code_for_ringers(): void
    {
        $product = $this->makeProduct([
            'sku' => '259-13',
            'name' => 'Ringers 259 Size 13.0',
            'manufacturer' => 'Ansell',
        ]);
        $sent = [];
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonEnrichment')
            ->once()
            ->andReturnUsing(function (array $messages) use (&$sent): array {
                $sent = $messages;

                return ['pages' => []];
            });
        $service = new ProductEnrichmentService(
            Mockery::mock(HybridWebSearchService::class),
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            $llm,
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            app(ProductImageCandidateVerifier::class),
            app(PpeAssortment::class),
        );

        $sanitize = new ReflectionMethod($service, 'sanitizePagesWithLlm');
        $sanitize->setAccessible(true);
        $sanitize->invoke($service, $product, [[
            'url' => 'https://www.ansell.com/pl/pl/products/ringers-r259',
            'text' => 'Wytrzymałe rękawice robocze z TPR, odporność na przecięcia EN 388 E.',
        ]]);

        // na karcie Ansell pisze „RINGERS™ R259”, a nie nasze 259-13 — model musi wiedzieć, że to ten sam produkt
        $this->assertStringContainsString(
            'Oznaczenie modelu u producenta (ten sam produkt): R259, R-259',
            (string) ($sent[1]['content'] ?? '')
        );
    }

    public function test_prefetch_search_steps_land_in_product_trace(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $product = $this->makeProduct(['sku' => '11618110', 'name' => 'HyFlex 11618 Size 11,0', 'manufacturer' => 'Ansell']);

        $search = $this->searchMock();
        $search->shouldReceive('searchBothPhases')->andReturnUsing(static function (): array {
            app(EnrichmentAttemptLog::class)->add('query', '„HyFlex 11618 Ansell” → 0 wyników');

            return ['results' => [], 'errors' => ['brak wyników']];
        });
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonEnrichment')->never();

        $service = new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            $llm,
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            app(ProductImageCandidateVerifier::class),
            app(PpeAssortment::class),
        );

        $service->prefetchProductSources($product, true);
        try {
            $service->enrichProduct($product, true);
            $this->fail('Oczekiwano ProductSourcesNotFoundException.');
        } catch (ProductSourcesNotFoundException) {
        }

        // prefetch szuka w osobnym zadaniu — bez przeniesienia kroków przebieg miał tylko 4 pozycje
        $steps = $product->fresh()->enrichment_trace['steps'] ?? [];
        $messages = implode(' | ', array_column($steps, 'm'));
        $this->assertStringContainsString('HyFlex 11618 Ansell', $messages);
    }

    public function test_searxng_outage_marks_failed_not_manual(): void
    {
        $product = $this->makeProduct([
            'sku' => 'R30X',
            'manufacturer' => 'Honeywell',
            'description' => null,
        ]);
        $search = $this->searchMock();
        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn([
                'results' => [],
                'errors' => ['manufacturer: SearXNG: silniki zablokowane (429/CAPTCHA)'],
            ]);

        $service = new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            Mockery::mock(OpenAiCompatibleClient::class),
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            app(ProductImageCandidateVerifier::class),
            app(PpeAssortment::class),
        );

        try {
            $service->enrichProduct($product, false);
            $this->fail('Oczekiwano ProductSourcesNotFoundException.');
        } catch (ProductSourcesNotFoundException) {
        }

        $this->assertSame(Product::ENRICHMENT_FAILED, $product->fresh()?->enrichment_status);
    }

    /** Batch #246: wszystkie darmowe silniki odmówiły, a produkt szedł do „wpisz ręcznie”. */
    public function test_http_throttled_engines_mark_failed_not_manual(): void
    {
        $product = $this->makeProduct([
            'sku' => 'AC01P-00012-00',
            'manufacturer' => 'Ansell',
            'description' => null,
        ]);
        $search = $this->searchMock();
        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn([
                'results' => [],
                'errors' => [
                    '„site:bhp-sklep.com.pl AlphaTec”: Google HTTP 429: brak wyników wyszukiwania.'
                        .' | Bing: brak wyników | DuckDuckGo HTTP 202: brak wyników wyszukiwania.'
                        .' | Qwant HTTP 403: brak wyników wyszukiwania.',
                ],
            ]);

        $service = new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            Mockery::mock(OpenAiCompatibleClient::class),
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            app(ProductImageCandidateVerifier::class),
            app(PpeAssortment::class),
        );

        try {
            $service->enrichProduct($product, false);
            $this->fail('Oczekiwano ProductSourcesNotFoundException.');
        } catch (ProductSourcesNotFoundException $e) {
            $this->assertStringNotContainsString('wpisz ręcznie', $e->getMessage());
            $this->assertStringContainsString('Wyszukiwarka nie odpowiedziała', $e->getMessage());
        }

        $this->assertSame(Product::ENRICHMENT_FAILED, $product->fresh()?->enrichment_status);
    }

    /** SearXNG wyłączony: cURL 7 to awaria, nie dowód, że karty nie ma. */
    public function test_searxng_connection_refused_marks_failed_not_manual(): void
    {
        $product = $this->makeProduct([
            'sku' => 'SC36BCPE',
            'manufacturer' => 'Ansell',
            'description' => null,
        ]);
        $search = $this->searchMock();
        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn([
                'results' => [],
                'errors' => [
                    'SearXNG (http://127.0.0.1:8088/search) nie odpowiada:'
                        .' cURL error 7: Failed to connect to 127.0.0.1 port 8088',
                ],
            ]);

        $service = new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            Mockery::mock(OpenAiCompatibleClient::class),
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            app(ProductImageCandidateVerifier::class),
            app(PpeAssortment::class),
        );

        try {
            $service->enrichProduct($product, false);
            $this->fail('Oczekiwano ProductSourcesNotFoundException.');
        } catch (ProductSourcesNotFoundException) {
        }

        $this->assertSame(Product::ENRICHMENT_FAILED, $product->fresh()?->enrichment_status);
    }

    /**
     * Wyszukiwarka znalazła 4 karty na hahn-kolb, żadna nie odpowiedziała (blokada/timeout),
     * a produkt szedł do „wpisz ręcznie” — jakby karty nie było. Karty są, sklep nie
     * odpowiedział: to awaria do ponowienia, tak jak padnięta wyszukiwarka.
     */
    public function test_found_cards_that_do_not_respond_mark_failed_not_manual(): void
    {
        $product = $this->makeProduct([
            'sku' => '49-00-0142',
            'name' => 'Rękawice ochronne HyFlex 11-840',
            'manufacturer' => 'Ansell',
            'description' => null,
        ]);
        $search = $this->searchMock();
        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn([
                'results' => [[
                    'url' => 'https://pol-paw.pl/rekawice-hyflex-11-840-p-9911.html',
                    'title' => 'Rękawice HyFlex 11-840',
                    'snippet' => 'Rękawice montażowe',
                ]],
                'errors' => [],
            ]);
        $search->shouldReceive('searchWebWithoutLocalIndex')
            ->once()
            ->andReturn([
                'results' => [
                    ['url' => 'https://www.hahn-kolb.de/Handschuhe/49-00-0142.html', 'title' => 'HyFlex 11-840', 'snippet' => 'Ansell 49-00-0142'],
                    ['url' => 'https://www.hahn-kolb.net/en/Gloves/49-00-0142.html', 'title' => 'HyFlex 11-840', 'snippet' => 'Ansell 49-00-0142'],
                    ['url' => 'https://www.hahn-kolb.de/Arbeitsschutz/HyFlex-11-840.html', 'title' => 'HyFlex 11-840', 'snippet' => 'Ansell'],
                    ['url' => 'https://www.hahn-kolb.net/en/PPE/HyFlex-11-840.html', 'title' => 'HyFlex 11-840', 'snippet' => 'Ansell'],
                ],
                'images' => [],
                'errors' => [],
            ]);

        // żaden sklep nie odpowiada — connection timeout na każdym adresie
        Http::fake(['*' => Http::failedConnection('cURL error 28: Connection timed out after 4001 milliseconds')]);

        $service = new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            Mockery::mock(OpenAiCompatibleClient::class),
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            app(ProductImageCandidateVerifier::class),
            app(PpeAssortment::class),
        );

        try {
            $service->enrichProduct($product, false);
            $this->fail('Oczekiwano ProductSourcesNotFoundException.');
        } catch (ProductSourcesNotFoundException $e) {
            $this->assertStringNotContainsString('wpisz ręcznie', $e->getMessage());
            $this->assertStringContainsString('nie odpowiedziały', $e->getMessage());
        }

        $product->refresh();
        $this->assertNotSame(Product::ENRICHMENT_MANUAL, $product->enrichment_status);
        $this->assertSame(Product::ENRICHMENT_FAILED, $product->enrichment_status);
    }

    /**
     * hahn-kolb (Akamai) oddaje 403 „Access Denied” dla wszystkiego, także dla readera —
     * to blokada stała, nie chwilowy brak odpowiedzi. Produkt ma trafić do ręki
     * z adresem karty, a nie do kolejki na „ponów później”.
     */
    public function test_found_cards_behind_a_waf_go_to_manual_with_the_url(): void
    {
        $card = 'https://www.hahn-kolb.net/ANSELL-Replacement-belt-and-buckle-AC01P-00014-00/95282536.sku/en/US/EUR/';
        $product = $this->makeProduct([
            'sku' => 'AC01P-00014-00',
            'name' => 'GREY PES BLT & YKK BCKL LENGTH 150CM',
            'manufacturer' => 'Ansell',
            'description' => null,
        ]);
        $search = $this->searchMock();
        // jak na produkcji: pierwsze szukanie daje rekawice kolczugowa pol-paw (ta sama
        // koncowka „ac01”), jej tresc nie potwierdza pasa, dopiero internet znajduje karte
        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn([
                'results' => [[
                    'url' => 'https://pol-paw.pl/rekawica-kolczugowa-1-sztuka-ac01-p-5502.html',
                    'title' => 'Rekawica kolczugowa AC01',
                    'snippet' => 'Rekawica kolczugowa jednoczesciowa',
                ]],
                'errors' => [],
            ]);
        $search->shouldReceive('searchWebWithoutLocalIndex')
            ->once()
            ->andReturn([
                'results' => [['url' => $card, 'title' => 'ANSELL Replacement belt and buckle AC01P-00014-00', 'snippet' => 'Ansell']],
                'images' => [],
                'errors' => [],
            ]);

        // 403 z WAF-u dla karty i dla readera (r.jina.ai idzie przez to samo Http)
        Http::fake(['*' => Http::response('Access Denied', 403)]);

        $service = new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            Mockery::mock(OpenAiCompatibleClient::class),
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            app(ProductImageCandidateVerifier::class),
            app(PpeAssortment::class),
        );

        try {
            $service->enrichProduct($product, false);
            $this->fail('Oczekiwano ProductSourcesNotFoundException.');
        } catch (ProductSourcesNotFoundException $e) {
            $this->assertStringContainsString('prawdopodobnie tu', $e->getMessage());
            $this->assertStringContainsString('hahn-kolb.net', $e->getMessage());
            // liczba zalezy od tego, ile kart 403 poszlo przez reader w calym przebiegu
            $this->assertMatchesRegularExpression('/reader: odmowa 403 ×[0-9]+/u', $e->getMessage());
            $this->assertStringContainsString('wpisz opis ręcznie', $e->getMessage());
            $this->assertStringNotContainsString('niepewnych', $e->getMessage());
            $this->assertStringNotContainsString('Ponów', $e->getMessage());
        }

        $product->refresh();
        $this->assertSame(Product::ENRICHMENT_MANUAL, $product->enrichment_status);
    }

    /**
     * AlphaTec 58-301: za zapora zostaly 58-530w i 58-201 - cudze modele. Komunikat
     * nie moze mowic „karta istnieje”, bo nikt tego nie sprawdzil; ma podpisac je
     * jako niepewnych kandydatow. A gdy zapore spotyka juz pierwsze pobranie
     * (HyFlex 11-130), adres tez ma trafic do komunikatu.
     */
    public function test_walled_sibling_candidates_are_labelled_uncertain_even_on_first_fetch(): void
    {
        $product = $this->makeProduct([
            'sku' => '58301110',
            'name' => 'AlphaTec 58-301',
            'manufacturer' => 'Ansell',
            'description' => null,
        ]);
        $search = $this->searchMock();
        // pierwsze szukanie od razu daje karty producenta - i one od razu dostaja 403
        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn([
                'results' => [
                    ['url' => 'https://www.ansell.com/pl/pl/products/alphatec-58-530w', 'title' => 'AlphaTec 58-530W', 'snippet' => ''],
                    ['url' => 'https://www.ansell.com/pl/pl/products/alphatec-58-201', 'title' => 'AlphaTec 58-201', 'snippet' => ''],
                ],
                'errors' => [],
            ]);
        $search->shouldReceive('searchWebWithoutLocalIndex')
            ->zeroOrMoreTimes()
            ->andReturn(['results' => [], 'images' => [], 'errors' => []]);

        Http::fake(['*' => Http::response('Access Denied', 403)]);

        $service = new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            Mockery::mock(OpenAiCompatibleClient::class),
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            app(ProductImageCandidateVerifier::class),
            app(PpeAssortment::class),
        );

        try {
            $service->enrichProduct($product, false);
            $this->fail('Oczekiwano ProductSourcesNotFoundException.');
        } catch (ProductSourcesNotFoundException $e) {
            $this->assertStringContainsString('niepewnych kandydatów', $e->getMessage());
            $this->assertStringContainsString('alphatec-58-530w', $e->getMessage());
            $this->assertStringNotContainsString('prawdopodobnie tu', $e->getMessage());
            $this->assertStringContainsString('sprawdź, czy to ten produkt', $e->getMessage());
        }
        $this->assertSame(Product::ENRICHMENT_MANUAL, $product->fresh()?->enrichment_status);
    }

    public function test_enrichment_service_saves_description_and_image(): void
    {
        Storage::fake('public');

        $product = $this->makeProduct();

        $search = $this->searchMock();
        $shopUrl = 'https://bhp-sklep.com.pl/produkt/'.$product->sku;
        $mfrUrl = 'https://www.ansell.com/product/'.$product->sku;

        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn([
                'results' => [
                    [
                        'url' => $mfrUrl,
                        'title' => 'Ansell official',
                        'snippet' => 'Datasheet '.$product->sku,
                    ],
                    [
                        'url' => $shopUrl,
                        'title' => 'Karta sklep',
                        'snippet' => 'Rękawice ochronne nitrylowe EN 388',
                    ],
                ],
                'errors' => [],
            ]);

        $richDescription = 'Rękawice nitrylowe Ansell '.$product->sku.' do pracy w przemyśle. '
            .'Spełniają normy EN 388 i chronią przed ścieraniem. '
            .'Przeznaczone do montażu oraz prac precyzyjnych w warunkach suchych. '
            .'Trwała powłoka nitrylowa zwiększa żywotność przy codziennym użytkowaniu w zakładzie.';

        $llm = $this->mockLlmWithSanitize([
            'description' => $richDescription,
            'features' => ['nitryl', 'antypoślizgowe'],
            'specs' => ['Długość: 30 cm'],
            'norms' => ['EN 388'],
            'certificates' => ['CE'],
            'materials' => ['nitryl'],
            'use_cases' => ['montaż'],
            'image_urls' => ['https://cdn.example.com/glove-'.$product->sku.'.jpg'],
            'document_urls' => [],
            'source_urls' => [$shopUrl],
            'confidence' => 0.9,
        ]);

        $pdf = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";

        Http::fake([
            'https://api.tavily.com/*' => Http::response(['results' => []], 200),
            'https://www.ansell.com/docs/*' => Http::response(
                $pdf,
                200,
                ['Content-Type' => 'application/pdf']
            ),
            'https://cdn.example.com/*' => Http::response(
                $this->tinyJpeg(),
                200,
                ['Content-Type' => 'image/jpeg']
            ),
            'https://bhp-sklep.com.pl/*' => Http::response(
                '<html><body>Rękawice '.$product->sku.' EN 388 '
                .'<img src="https://cdn.example.com/glove-'.$product->sku.'.jpg" alt="glove '.$product->sku.'">'
                .'</body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'https://www.ansell.com/*' => Http::response(
                '<html><body>Rękawice '.$product->sku
                .' <a href="https://www.ansell.com/docs/cert-'.$product->sku.'.pdf">Certificate PDF</a></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $service = new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ManufacturerDomainResolver::class),
            $llm,
            app(AiSettingsService::class),
            app(BhpAttributeNormalizer::class),
            app(ProductSearchIdentity::class),
            new ProductImageCandidateVerifier(
                app(ProductSearchIdentity::class),
                $llm,
            ),
            app(PpeAssortment::class),
        );

        $service->enrichProduct($product, false);

        $product->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status);
        $this->assertStringContainsString('Rękawice nitrylowe', (string) $product->description);
        $this->assertIsArray($product->enrichment_payload);
        $this->assertSame(['nitryl', 'antypoślizgowe'], $product->enrichment_payload['features'] ?? null);
        $this->assertSame(1, ProductImage::query()->where('product_id', $product->id)->count());
        $this->assertSame(1, ProductDocument::query()->where('product_id', $product->id)->count());
        $this->assertNull($product->enrichment_trace);
        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), 'tavily.com'));
    }

    public function test_keeps_shop_radio_sizes_after_llm_drops_them_from_text(): void
    {
        Storage::fake('public');
        $product = $this->makeProduct([
            'sku' => 'NAVARA-S1P',
            'name' => 'Półbuty Jet3 S1P SRC NAVARA',
            'manufacturer' => 'Delta Plus',
            'category' => 'Obuwie',
        ]);
        $shopUrl = 'https://www.bhp-gabi.pl/p22243,polbuty-jet3-s1p-src.html';

        $search = $this->searchMock();
        $search->shouldReceive('searchBothPhases')->once()->andReturn([
            'results' => [[
                'url' => $shopUrl,
                'title' => 'Półbuty Jet3 S1P SRC Delta Plus NAVARA-S1P',
                'snippet' => 'Półbuty Jet3 S1P SRC Delta Plus NAVARA-S1P',
            ]],
            'errors' => [],
        ]);
        $this->app->instance(HybridWebSearchService::class, $search);

        $desc = 'Półbuty Jet3 S1P SRC NAVARA-S1P marki Delta Plus ze skórzanego kruponu. '
            .'Podszewka poliamid mesh, wkładka EVA, podeszwa poliuretanowa. '
            .'Przeznaczone do prac na budowie i w warsztacie. Norma EN ISO 20345 S1P SRC.';
        $this->app->instance(OpenAiCompatibleClient::class, $this->mockLlmWithSanitize([
            'description' => $desc,
            'features' => ['S1P', 'SRC'],
            'specs' => ['Cholewka: skóra'],
            'norms' => ['EN ISO 20345', 'S1P', 'SRC'],
            'materials' => ['skóra'],
            'use_cases' => ['budowa'],
            'image_urls' => [],
            'source_urls' => [$shopUrl],
            'confidence' => 0.9,
            'attributes' => ['kategoria_bhp' => 'obuwie'],
        ]));

        $radios = '';
        foreach (range(36, 47) as $i => $size) {
            $id = $i === 0 ? 'atrybuty_22243_20_0' : 'atrybuty_22243_20_0_'.($i + 1);
            $radios .= '<p><input type="radio" name="atrybuty_22243[20]" id="'.$id.'">'
                .' <label for="'.$id.'">'.$size.'</label></p>';
        }

        Http::fake([
            'https://www.bhp-gabi.pl/*' => Http::response(
                '<html><body><h1>Półbuty Jet3 S1P SRC Delta Plus NAVARA-S1P</h1>'
                .'<article id="mod_opis"><p>Półbuty Jet3 S1P SRC NAVARA-S1P Delta Plus. '
                .'Cholewka skórzany krupon. Podszewka poliamid. Podeszwa poliuretan. '
                .'EN ISO 20345 S1P SRC do budowy i warsztatu.</p></article>'
                .'<div class="attributes-box"><div class="attribute-a">'
                .'<div class="name"><p>Rozmiar:</p><div class="selected"><p>Wybrano:</p></div></div>'
                .'<div class="list"><div id="opcja_22243_20_0">'.$radios.'</div></div>'
                .'</div></div></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'https://api.tavily.com/*' => Http::response(['results' => []], 200),
        ]);

        app(ProductEnrichmentService::class)->enrichProduct($product, false);

        $product->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status);
        $this->assertSame('36-47', $product->enrichment_payload['attributes']['rozmiar'] ?? null);
        $this->assertSame('36-47', $product->packaging);
    }

    public function test_keeps_idosell_select2_glove_sizes_not_footwear_range(): void
    {
        Storage::fake('public');
        $product = $this->makeProduct([
            'sku' => 'A5016',
            'name' => 'Rękawice robocze BRAD A5016 ARDON',
            'manufacturer' => 'Ardon',
            'category' => 'Rękawice',
        ]);
        $shopUrl = 'https://optimumbhp.pl/REKAWICE-ROBOCZE-Z-POWLOKA-NITRYLOWA-BRAD-A5016-ARDON-p134792';

        $search = $this->searchMock();
        $search->shouldReceive('searchBothPhases')->once()->andReturn([
            'results' => [[
                'url' => $shopUrl,
                'title' => 'Rękawice robocze BRAD A5016 ARDON',
                'snippet' => 'Rękawice robocze BRAD A5016 ARDON nitryl',
            ]],
            'errors' => [],
        ]);
        $this->app->instance(HybridWebSearchService::class, $search);

        $desc = 'Rękawice robocze Brad A5016 Ardon z nylonu z powłoką nitrylową. '
            .'Bezszwowe, niepylące, odporne na oleje i tłuszcze. '
            .'Przeznaczone do prac technicznych, przemysłowych i montażu. Norma EN 388:2016 212XX.';
        $this->app->instance(OpenAiCompatibleClient::class, $this->mockLlmWithSanitize([
            'description' => $desc,
            'features' => ['nitryl', 'bezszwowe'],
            'specs' => ['Powłoka: nitryl'],
            'norms' => ['EN 388:2016'],
            'materials' => ['nitryl', 'nylon'],
            'use_cases' => ['przemysł'],
            'image_urls' => [],
            'source_urls' => [$shopUrl],
            'confidence' => 0.9,
            'attributes' => ['kategoria_bhp' => 'rekawice', 'rozmiar' => '35-49'],
        ]));

        Http::fake([
            '*' => Http::response(
                '<html><body><h1>Rękawice robocze BRAD A5016 ARDON</h1>'
                .'<article><p>Rękawice robocze Brad A5016 Ardon z nylonu z powłoką nitrylową. '
                .'Model bezszwowy, niepylący, odporny na oleje i tłuszcze. '
                .'Przeznaczone do prac technicznych, przemysłowych, montażu i transportu. '
                .'EN 388:2016 212XX. Producent Ardon Safety. Kod A5016. '
                .str_repeat('Opis karty produktu BHP. ', 20)
                .'</p></article>'
                .'<table class="product-parameters"><tr><td>'
                .'<span class="parameter-name">Rozmiary rękawic</span> <br></td><td>'
                .'<select class="select-field-select2 core_parseOption" data-placeholder="Wybierz">'
                .'<option></option>'
                .'<option value="14220" name="option_15-134792">6</option>'
                .'<option value="14221" name="option_15-134792">7</option>'
                .'<option value="14222" name="option_15-134792">8</option>'
                .'<option value="14223" name="option_15-134792">9</option>'
                .'<option value="14224" name="option_15-134792">10</option>'
                .'<option value="14225" name="option_15-134792">11</option>'
                .'</select></td></tr></table>'
                .'<p>Rozmiary unisex od 35 do 49.</p></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'https://api.tavily.com/*' => Http::response(['results' => []], 200),
        ]);

        $fetched = app(ProductPageFetcher::class)->fetch(
            [['url' => $shopUrl, 'title' => 'Rękawice robocze BRAD A5016 ARDON', 'snippet' => 'A5016']],
            'A5016',
            3,
            [],
            $product
        );
        $this->assertSame(
            ['6', '7', '8', '9', '10', '11'],
            $fetched['pages'][0]['option_sizes'] ?? []
        );

        app(ProductEnrichmentService::class)->enrichProduct($product, false);

        $product->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status);
        $this->assertSame('6-11', $product->enrichment_payload['attributes']['rozmiar'] ?? null);
        $this->assertSame('6-11', $product->packaging);
    }

    public function test_retries_shop_image_instead_of_manufacturer_screenshot(): void
    {
        Storage::fake('public');
        $product = $this->makeProduct(['sku' => '54335', 'name' => 'KG G10 Flex Ntrl Glv Blue XL']);
        $ansell = 'https://www.ansell.com/int/en/products/kleenguard-g10-flex-blue-nitrile-gloves-54335';
        $shop = 'https://labproinc.com/products/kg-g10-flex-ntrl-glv-blue-xl-54335';
        $shopImg = 'https://cdn.shop.example/g10-flex-54335.jpg';

        $search = $this->searchMock();
        $search->shouldReceive('searchBothPhases')->once()->andReturn([
            'results' => [
                ['url' => $ansell, 'title' => 'KleenGuard G10 Flex 54335', 'snippet' => 'Nitrile gloves 54335'],
                ['url' => $shop, 'title' => 'KG G10 Flex Ntrl Glv Blue XL 54335', 'snippet' => 'Nitrile gloves 54335'],
            ],
            'errors' => [],
        ]);
        $this->app->instance(HybridWebSearchService::class, $search);

        $desc = 'Rękawice nitrylowe KleenGuard G10 Flex 54335 do prac przemysłowych. '
            .'Ambidextralne, bezpudrowe, teksturowane opuszki. Spełniają wymagania kontaktu z żywnością. '
            .'Grubość 3 mil, mankiet zapobiegający zsunięciu. Przeznaczone do montażu i gastronomii.';
        $this->app->instance(OpenAiCompatibleClient::class, $this->mockLlmWithSanitize([
            'description' => $desc,
            'features' => ['nitryl'],
            'specs' => ['SKU: 54335'],
            'norms' => [],
            'materials' => ['nitryl'],
            'use_cases' => ['przemysł'],
            'image_urls' => [],
            'source_urls' => [$ansell],
            'confidence' => 0.8,
        ]));

        Http::fake([
            $ansell => Http::response(
                '<html><body><h1>KleenGuard G10 Flex Blue Nitrile Gloves 54335</h1>'
                .'<p>'.str_repeat('Nitrile gloves KleenGuard G10 Flex 54335 powder free. ', 30).'</p></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            $shop => Http::response(
                '<html><head><meta property="og:image" content="'.$shopImg.'"></head><body>'
                .'<h1>KG G10 Flex Ntrl Glv Blue XL 54335</h1>'
                .'<img src="'.$shopImg.'">'
                .'<p>'.str_repeat('Nitrile gloves KleenGuard G10 Flex 54335 powder free. ', 30).'</p></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            $shopImg => Http::response($this->tinyJpeg(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        app(ProductEnrichmentService::class)->enrichProduct($product, false);

        $image = ProductImage::query()->where('product_id', $product->id)->first();
        $this->assertNotNull($image);
        $this->assertSame($shopImg, $image->source_url);
        $this->assertStringNotContainsString('#screenshot', (string) $image->source_url);
    }

    public function test_ai_vision_accepts_urgent_image_without_sku_in_url_and_rejects_unrelated_one(): void
    {
        $product = $this->makeProduct([
            'sku' => 'URGENT-1005',
            'name' => '1005',
            'manufacturer' => 'URGENT',
            'category' => 'Rękawice',
            'norms' => 'EN 420, EN 388',
        ]);
        $gloveUrl = 'https://cdn.example.com/media/cache/7f3a91c2.jpg';
        $unrelatedUrl = 'https://cdn.example.com/media/cache/91ad884e.jpg';
        Http::fake([
            'https://cdn.example.com/*' => Http::response(
                $this->tinyJpeg(),
                200,
                ['Content-Type' => 'image/jpeg']
            ),
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonWithImages')
            ->once()
            ->with(
                Mockery::on(static fn (string $prompt): bool => str_contains($prompt, 'URGENT-1005')),
                Mockery::on(static fn (array $images): bool => count($images) === 2),
                AiTask::ImageVerification
            )
            ->andReturn([
                'candidates' => [
                    [
                        'index' => 0,
                        'is_relevant_product' => true,
                        'is_logo_or_banner' => false,
                        'confidence' => 0.96,
                        'reason' => 'Zdjęcie rękawicy z karty produktu.',
                    ],
                    [
                        'index' => 1,
                        'is_relevant_product' => false,
                        'is_logo_or_banner' => false,
                        'confidence' => 0.99,
                        'reason' => 'Inny produkt.',
                    ],
                ],
            ]);

        $verifier = new ProductImageCandidateVerifier(
            app(ProductSearchIdentity::class),
            $llm,
        );
        $selected = $verifier->select(
            $product,
            [$gloveUrl, $unrelatedUrl],
            [[
                'url' => 'https://sklep.example.com/rekawice-urgent-1005',
                'text' => 'URGENT 1005 rękawice ochronne EN 388.',
            ]],
            1
        );

        $this->assertSame([$gloveUrl], $selected);
    }

    public function test_cap_vision_prompt_requires_headwear_and_rejects_line_name_only(): void
    {
        $product = $this->makeProduct([
            'sku' => 'CZAPKA-DASZKIEM-GRZMOT-43',
            'name' => 'Czapka daszkiem GRZMOT',
            'manufacturer' => 'PANTHER',
        ]);
        $pantsUrl = 'https://cdn.example.com/media/cache/grzmot-kolekcja.jpg';
        Http::fake([
            'https://cdn.example.com/*' => Http::response(
                $this->tinyJpeg(),
                200,
                ['Content-Type' => 'image/jpeg']
            ),
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonWithImages')
            ->once()
            ->with(
                Mockery::on(static function (string $prompt): bool {
                    return str_contains($prompt, 'czapka / nakrycie głowy z daszkiem')
                        && str_contains($prompt, 'Inny rodzaj')
                        && str_contains($prompt, 'GRZMOT')
                        && str_contains($prompt, 'ludzie');
                }),
                Mockery::on(static fn (array $images): bool => count($images) === 1),
                AiTask::ImageVerification
            )
            ->andReturn([
                'candidates' => [[
                    'index' => 0,
                    'is_relevant_product' => false,
                    'is_logo_or_banner' => false,
                    'confidence' => 0.99,
                    'reason' => 'Na zdjęciu spodnie, nie czapka.',
                ]],
            ]);

        $verifier = new ProductImageCandidateVerifier(
            app(ProductSearchIdentity::class),
            $llm,
        );
        $selected = $verifier->select(
            $product,
            [$pantsUrl],
            [[
                'url' => 'https://sklep.example.com/czapka-grzmot',
                'text' => 'Czapka daszkiem GRZMOT PANTHER.',
            ]],
            1,
            [$pantsUrl]
        );

        $this->assertSame([], $selected);
    }

    public function test_structured_product_image_skips_ai_even_without_sku_in_url(): void
    {
        $product = $this->makeProduct([
            'sku' => 'WH25T-00122-04',
            'name' => 'AlphaTec 2500 Plus',
            'manufacturer' => 'Ansell',
        ]);
        $imageUrl = 'https://res.cloudinary.com/rsc/image/upload/w_700/Y0428245-01.jpg';

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldNotReceive('chatJsonWithImages');
        $verifier = new ProductImageCandidateVerifier(
            app(ProductSearchIdentity::class),
            $llm,
        );

        $selected = $verifier->select(
            $product,
            [$imageUrl],
            [['url' => 'https://shop.example.com/product/wh25t-00122-04', 'text' => 'Ansell AlphaTec']],
            1,
            [$imageUrl]
        );

        $this->assertSame([$imageUrl], $selected);
    }

    public function test_confirmed_card_og_image_skips_vision_when_name_requires_type(): void
    {
        $product = $this->makeProduct([
            'sku' => '121',
            'name' => 'Fartuch przedni z rękawami 120/100',
            'manufacturer' => 'AJ GROUP',
        ]);
        $og = 'https://icd.pl/media/catalog/product/f/a/fartuch-pros-wodoochronny-121-1.jpg';
        $thumb = 'https://icd.pl/media/catalog/product/cache/619fea8990fc50f1f0f0c116cd818ee3/f/a/fartuch-pros-wodoochronny-121-1.jpg';

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldNotReceive('chatJsonWithImages');
        $verifier = new ProductImageCandidateVerifier(
            app(ProductSearchIdentity::class),
            $llm,
        );

        $selected = $verifier->select(
            $product,
            [$og, $thumb],
            [[
                'url' => 'https://icd.pl/fartuch-wodoochronny-pros-121-bialy.html',
                'text' => 'Fartuch wodoochronny przedni z rękawami model 121 PROS.',
            ]],
            1,
            [$og]
        );

        $this->assertSame([$og], $selected);
    }

    public function test_skips_watermarked_image_and_takes_other_host(): void
    {
        $product = $this->makeProduct([
            'sku' => 'URGENT-1005',
            'name' => 'Rękawice URGENT 1005',
            'manufacturer' => 'URGENT',
        ]);
        $marked = 'https://sklep-a.example.com/media/urgent-1005.jpg';
        $clean = 'https://sklep-b.example.com/media/urgent-1005.jpg';
        Http::fake([
            'https://sklep-a.example.com/*' => Http::response($this->tinyJpeg(), 200, ['Content-Type' => 'image/jpeg']),
            'https://sklep-b.example.com/*' => Http::response($this->tinyJpeg(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonWithImages')
            ->once()
            ->andReturn([
                'candidates' => [
                    [
                        'index' => 0,
                        'is_relevant_product' => true,
                        'is_logo_or_banner' => false,
                        'is_watermarked' => true,
                        'confidence' => 0.99,
                        'reason' => 'Logo sklepu na rękawicy.',
                    ],
                    [
                        'index' => 1,
                        'is_relevant_product' => true,
                        'is_logo_or_banner' => false,
                        'is_watermarked' => false,
                        'confidence' => 0.94,
                        'reason' => 'Czysty packshot.',
                    ],
                ],
            ]);

        $verifier = new ProductImageCandidateVerifier(
            app(ProductSearchIdentity::class),
            $llm,
        );
        $selected = $verifier->select(
            $product,
            [$marked, $clean],
            [[
                'url' => 'https://sklep-a.example.com/rekawice',
                'text' => 'URGENT 1005',
            ]],
            1
        );

        $this->assertSame([$clean], $selected);
    }

    public function test_keeps_packshot_when_only_brand_mark_is_flagged_as_watermark(): void
    {
        $product = $this->makeProduct([
            'sku' => '001/A/ELR',
            'name' => 'Spodnie ogrodniczki z elementami odblaskowymi',
            'manufacturer' => 'AJ GROUP',
        ]);
        $url = 'https://pros.pl/81-large_default/spodnie-ogrodniczki-antystatyczne.jpg';
        Http::fake([
            'https://pros.pl/*' => Http::response($this->tinyJpeg(), 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJsonWithImages')
            ->once()
            ->andReturn([
                'candidates' => [
                    [
                        'index' => 0,
                        'is_relevant_product' => true,
                        'is_logo_or_banner' => false,
                        'is_watermarked' => true,
                        'confidence' => 0.96,
                        'reason' => 'Naszywka PROS na ogrodniczkach.',
                    ],
                ],
            ]);

        $verifier = new ProductImageCandidateVerifier(
            app(ProductSearchIdentity::class),
            $llm,
        );
        $selected = $verifier->select(
            $product,
            [$url],
            [[
                'url' => 'https://pros.pl/pl/odziez-wodoochronna-antystatyczna/81-spodnie-ogrodniczki-antystatyczne-model-001a.html',
                'text' => 'Spodnie ogrodniczki antystatyczne model 001A PROS.',
            ]],
            1
        );

        $this->assertSame([$url], $selected);
    }

    public function test_pick_primary_keeps_verified_candidate_without_sku_in_url(): void
    {
        $product = $this->makeProduct([
            'sku' => 'WH25T-00122-04',
            'name' => '2500-WH PLUS CVRL HOOD SOCKS 122.L',
            'manufacturer' => 'Ansell',
        ]);
        // RS Components: kod dystrybutora Y0428245 zamiast SKU Ansell w URL
        $verifiedUrl = 'https://res.cloudinary.com/rsc/image/upload/c_pad,w_700/Y0428245-01.jpg';

        $service = app(ProductEnrichmentService::class);
        $method = new ReflectionMethod($service, 'pickPrimaryImageUrls');
        $picked = $method->invoke(
            $service,
            [$verifiedUrl],
            [],
            (string) $product->sku,
            (string) $product->name,
            $product,
        );

        $this->assertSame([$verifiedUrl], $picked);
    }

    public function test_pick_primary_keeps_redcart_gallery_from_card(): void
    {
        $product = $this->makeProduct([
            'sku' => '205',
            'name' => 'Kurtka oddychająca zapinana na zamek bryzgoszczelny',
            'manufacturer' => 'AJ GROUP',
        ]);
        $img = 'https://static4.redcart.pl/templates/images/thumb/4697/1024/1024/pl/0/templates/images/products/4697/7dbc670fc4f6909d7aaae4bad4830a19.jpg';

        $service = app(ProductEnrichmentService::class);
        $method = new ReflectionMethod($service, 'pickPrimaryImageUrls');
        $picked = $method->invoke(
            $service,
            [$img],
            [],
            (string) $product->sku,
            (string) $product->name,
            $product,
        );

        $this->assertSame([$img], $picked);
    }

    public function test_description_images_stay_on_source_card(): void
    {
        $service = app(ProductEnrichmentService::class);
        $pages = [
            [
                'url' => 'https://www.bhp-gabi.pl/p34411,ubranie-101-112-p',
                'text' => 'Ubranie 101/112',
                'trusted_image_urls' => ['https://www.bhp-gabi.pl/media/101-112.jpg'],
                'image_urls' => ['https://www.bhp-gabi.pl/media/101-112.jpg'],
            ],
            [
                'url' => 'https://icd.pl/inny-model.html',
                'text' => 'Inny model',
                'trusted_image_urls' => ['https://icd.pl/media/obcy.jpg'],
                'image_urls' => ['https://icd.pl/media/obcy.jpg'],
            ],
        ];
        $desc = (new ReflectionMethod($service, 'pagesForDescriptionImages'))
            ->invoke($service, $pages, ['https://www.bhp-gabi.pl/p34411,ubranie-101-112-p']);
        $images = (new ReflectionMethod($service, 'imagesFromDescriptionPages'))
            ->invoke($service, $desc);

        $this->assertSame(['https://www.bhp-gabi.pl/media/101-112.jpg'], $images['trusted']);
        $this->assertNotContains('https://icd.pl/media/obcy.jpg', $images['all']);
    }

    public function test_ai_settings_accept_web_search_fields(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->putJson('/api/ai-settings', [
            'enabled' => true,
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-test-key-1234567890',
            'web_search_enabled' => true,
            'tavily_api_key' => 'tvly-test-key-1234567890',
            'search_fallback' => 'tavily',
        ])->assertOk()
            ->assertJsonPath('web_search_enabled', true)
            ->assertJsonPath('has_tavily_api_key', true)
            ->assertJsonPath('search_fallback', 'tavily')
            ->assertJsonMissingPath('tavily_api_key');

        $row = AiSetting::query()->first();
        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->web_search_enabled);
        $this->assertSame('tvly-test-key-1234567890', $row->tavily_api_key);
    }

    public function test_sku_in_url_matches_without_ppe_keywords(): void
    {
        $product = $this->makeProduct([
            'sku' => 'NV2032CE',
            'name' => 'Astro Cleat',
            'manufacturer' => 'GVS',
        ]);
        $method = new ReflectionMethod(HybridWebSearchService::class, 'filterResultsByIdentity');
        $filtered = $method->invoke(app(HybridWebSearchService::class), [
            [
                'url' => 'https://www.gvs.com/products/nv2032ce-astro-cleat',
                'title' => 'NV2032CE Astro Cleat',
                'snippet' => 'Cable cleat for cables',
            ],
            [
                'url' => 'https://gvs.sklep.pl/knx-gateway',
                'title' => 'Bramka KNX',
                'snippet' => 'GVS KNX',
            ],
        ], $product);

        $this->assertCount(1, $filtered);
        $this->assertStringContainsString('nv2032ce', mb_strtolower($filtered[0]['url']));
    }

    public function test_distinctive_sku_in_snippet_is_enough(): void
    {
        $product = $this->makeProduct([
            'sku' => 'ROBFM',
            'name' => 'ROBFM',
            'manufacturer' => 'JS Gloves',
        ]);
        $method = new ReflectionMethod(HybridWebSearchService::class, 'filterResultsByIdentity');
        $filtered = $method->invoke(app(HybridWebSearchService::class), [
            [
                'url' => 'https://shop.example/rekawice-termiczne',
                'title' => 'Rękawice termiczne',
                'snippet' => 'Model ROBFM JS Gloves do 250C',
            ],
        ], $product);

        $this->assertCount(1, $filtered);
        $this->assertStringContainsString('rekawice-termiczne', $filtered[0]['url']);
    }

    public function test_numeric_sku_without_brand_is_rejected(): void
    {
        $product = $this->makeProduct([
            'sku' => '1202',
            'name' => 'Rękawice 1202 kozia czerwona',
            'manufacturer' => 'Urgent',
        ]);
        $method = new ReflectionMethod(HybridWebSearchService::class, 'filterResultsByIdentity');
        $filtered = $method->invoke(app(HybridWebSearchService::class), [
            [
                'url' => 'https://www.hq.nasa.gov/alsj/a11/a11.landing.html',
                'title' => 'Apollo 11 Lunar Surface Journal: Program Alarms',
                'snippet' => 'The 1202 alarm was urgently analysed by the crew.',
            ],
            [
                'url' => 'https://pl.wikipedia.org/wiki/FSO_Warszawa',
                'title' => 'FSO Warszawa',
                'snippet' => 'Samochód 1202 kg masy własnej.',
            ],
        ], $product);

        $this->assertSame([], $filtered);
    }

    public function test_numeric_sku_with_brand_still_matches(): void
    {
        $product = $this->makeProduct([
            'sku' => '1202',
            'name' => 'Rękawice 1202 kozia czerwona',
            'manufacturer' => 'Urgent',
        ]);
        $method = new ReflectionMethod(HybridWebSearchService::class, 'filterResultsByIdentity');
        $filtered = $method->invoke(app(HybridWebSearchService::class), [
            [
                'url' => 'https://sklep.example/rekawice-urgent-1202',
                'title' => 'Rękawice Urgent 1202 kozia czerwona',
                'snippet' => 'Rękawice robocze Urgent 1202 ze skóry koziej.',
            ],
        ], $product);

        $this->assertCount(1, $filtered);
    }

    public function test_thumbnail_width_is_not_treated_as_numeric_sku(): void
    {
        $product = $this->makeProduct([
            'sku' => '1202',
            'name' => 'Rękawice 1202 kozia czerwona',
            'manufacturer' => 'Urgent',
        ]);
        $identity = app(ProductSearchIdentity::class);

        $this->assertFalse($identity->imageUrlMentionsProduct(
            'https://upload.wikimedia.org/wikipedia/commons/thumb/a/a1/Warszawa.jpg/1202px-Warszawa.jpg',
            $product
        ));
        $this->assertTrue($identity->imageUrlMentionsProduct(
            'https://urgent.pl/media/products/rekawice-1202.jpg',
            $product
        ));
    }

    public function test_keeps_shop_page_when_sku_only_in_html(): void
    {
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'http://127.0.0.1:8081/v1',
            'api_key' => 'local',
            'model' => 'qwen38-27b-fast',
            'timeout_seconds' => 30,
            'temperature' => 0.1,
            'search_engine' => 'duckduckgo',
            'web_search_enabled' => false,
        ]);
        $product = $this->makeProduct([
            'sku' => 'ROBFM',
            'name' => 'ROBFM',
            'manufacturer' => 'JS Gloves',
        ]);
        $pageUrl = 'https://shop.example/rekawice-termiczne';
        Http::fake(function ($request) use ($pageUrl) {
            if (str_contains($request->url(), 'google.com/search')) {
                return Http::response(
                    '<a href="/url?q='.rawurlencode($pageUrl).'&amp;sa=U">Rękawice termiczne</a>',
                    200
                );
            }
            if ($request->url() === $pageUrl) {
                return Http::response(
                    '<html><body><h1>Rękawice termiczne</h1><p>'
                    .str_repeat('Rękawice ochronne ROBFM JS Gloves do 250C. ', 40)
                    .'</p></body></html>',
                    200,
                    ['Content-Type' => 'text/html']
                );
            }

            return Http::response('unused', 404);
        });

        $pack = app(HybridWebSearchService::class)->searchProduct($product, 'manufacturer');

        $this->assertSame($pageUrl, $pack['results'][0]['url'] ?? null);
    }

    public function test_searxng_instance_is_used_instead_of_public_engines(): void
    {
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'http://127.0.0.1:8081/v1',
            'api_key' => 'local',
            'model' => 'qwen38-27b-fast',
            'timeout_seconds' => 30,
            'temperature' => 0.1,
            'search_engine' => 'searxng',
            'searxng_url' => 'http://127.0.0.1:8088',
            'web_search_enabled' => false,
        ]);
        $product = $this->makeProduct([
            'sku' => 'ROBFM',
            'name' => 'Rękawice termiczne',
            'manufacturer' => 'JS Gloves',
        ]);
        $hitUrl = 'https://bhpsklep.example/rekawice-robfm';
        Http::fake(function ($request) use ($hitUrl) {
            if (str_contains($request->url(), '127.0.0.1:8088/search')) {
                return Http::response([
                    'results' => [[
                        'url' => $hitUrl,
                        'title' => 'Rękawice ROBFM JS Gloves',
                        'content' => 'Rękawice termiczne ROBFM do 250C',
                    ]],
                ], 200);
            }

            return Http::response('unused', 404);
        });

        $pack = app(HybridWebSearchService::class)->searchProduct($product, 'manufacturer');

        $this->assertSame('searxng', $pack['provider']);
        $this->assertSame($hitUrl, $pack['results'][0]['url'] ?? null);
        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), 'google.com'));
        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), 'bing.com'));
        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), 'tavily.com'));
    }

    public function test_searxng_blocked_does_not_call_tavily(): void
    {
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'http://127.0.0.1:8081/v1',
            'api_key' => 'local',
            'model' => 'qwen38-27b-fast',
            'timeout_seconds' => 30,
            'temperature' => 0.1,
            'search_engine' => 'searxng',
            'searxng_url' => 'http://127.0.0.1:8088',
            'search_fallback' => 'tavily',
            'tavily_api_key' => 'tvly-test-key-1234567890',
            'web_search_enabled' => false,
        ]);
        $product = $this->makeProduct([
            'sku' => '2205',
            'name' => 'Kurtka robocza',
            'manufacturer' => 'Portwest',
        ]);
        Http::fake(function ($request) {
            if (str_contains($request->url(), '127.0.0.1:8088/search')) {
                return Http::response([
                    'results' => [],
                    'unresponsive_engines' => [
                        ['brave', 'Zawieszone: za dużo zapytań'],
                        ['duckduckgo', 'CAPTCHA'],
                        ['google cse', 'Zawieszone: za dużo zapytań'],
                        ['qwant', 'CAPTCHA'],
                    ],
                ], 200);
            }

            return Http::response('blocked', 403);
        });

        try {
            app(HybridWebSearchService::class)->searchProduct($product, 'manufacturer');
            $this->fail('Oczekiwano wyjątku bez wyników SearXNG.');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('tavily', strtolower($e->getMessage()));
        }

        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), 'tavily.com'));
    }

    public function test_searxng_blocked_does_not_hit_google_or_bing(): void
    {
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'http://127.0.0.1:8081/v1',
            'api_key' => 'local',
            'model' => 'qwen38-27b-fast',
            'timeout_seconds' => 30,
            'temperature' => 0.1,
            'search_engine' => 'searxng',
            'searxng_url' => 'http://127.0.0.1:8088',
            'web_search_enabled' => false,
        ]);
        Cache::put('searxng_engines_blocked_v1', 1, 600);
        Http::fake(['*' => Http::response('should-not-run', 200)]);

        try {
            app(DuckDuckGoHtmlSearch::class)->search('honeywell R30X', 8, [], false);
            $this->fail('Oczekiwano wyjątku przy zablokowanym SearXNG.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('silniki zablokowane', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_domain_narrowed_query_uses_single_site_operator(): void
    {
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'http://127.0.0.1:8081/v1',
            'api_key' => 'local',
            'model' => 'qwen38-27b-fast',
            'timeout_seconds' => 30,
            'temperature' => 0.1,
            'search_engine' => 'searxng',
            'searxng_url' => 'http://127.0.0.1:8088',
            'web_search_enabled' => false,
        ]);
        Http::fake(['*' => Http::response([
            'results' => [[
                'url' => 'https://urgent.com.pl/bluza-hsv',
                'title' => 'Bluza ostrzegawcza Urgent',
                'content' => 'Bluza HSV',
            ]],
        ], 200)]);

        app(DuckDuckGoHtmlSearch::class)->search(
            'Urgent bluza ostrzegawcza',
            5,
            ['urgent.com.pl', 'www.urgent.pl', 'sklep.urgent.pl'],
        );

        Http::assertSent(static function ($request): bool {
            $query = urldecode($request->url());

            return str_contains($query, 'site:urgent.com.pl')
                && substr_count($query, 'site:') === 1;
        });
    }

    public function test_search_stops_when_sku_hits_open_web(): void
    {
        $this->seedTavilySettings();
        $product = $this->makeProduct([
            'sku' => 'NV2032CE',
            'name' => 'Astro Cleat',
            'manufacturer' => 'NoSuchBrandXYZ',
        ]);
        $hitUrl = 'https://hurtownia.example/products/nv2032ce-astro-cleat';
        $openCalls = 0;
        Http::fake(function ($request) use ($hitUrl, &$openCalls) {
            $this->assertStringContainsString('tavily.com', $request->url());
            $data = $request->data();
            $query = (string) ($data['query'] ?? '');
            $domains = $data['include_domains'] ?? [];
            if ((is_array($domains) && $domains !== [])
                || str_contains(mb_strtolower($query), 'official')
                || str_contains(mb_strtolower($query), 'strona oficjalna')
                || preg_match('/\bsite:/i', $query) === 1) {
                return Http::response(['results' => []], 200);
            }
            $openCalls++;

            return Http::response([
                'results' => [[
                    'url' => $hitUrl,
                    'title' => 'NV2032CE Astro Cleat NoSuchBrandXYZ',
                    'content' => 'Astro Cleat cable cleat NV2032CE',
                ]],
            ], 200);
        });

        $pack = app(HybridWebSearchService::class)->searchProduct($product, 'manufacturer');

        $this->assertSame(1, $openCalls);
        $this->assertSame('tavily', $pack['provider']);
        $this->assertSame($hitUrl, $pack['results'][0]['url'] ?? null);
    }

    public function test_free_search_also_tries_name_query_for_single_hit(): void
    {
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'http://127.0.0.1:8081/v1',
            'api_key' => 'local',
            'model' => 'qwen38-27b-fast',
            'timeout_seconds' => 30,
            'temperature' => 0.1,
            'search_engine' => 'duckduckgo',
            'web_search_enabled' => false,
        ]);
        $product = $this->makeProduct([
            'sku' => '1202',
            'name' => 'Rękawice 1202 kozia czerwona',
            'manufacturer' => 'Urgent',
        ]);
        $queries = [];
        Http::fake(function ($request) use (&$queries) {
            if (! str_contains($request->url(), 'google.com/search')) {
                return Http::response('unused', 404);
            }
            $query = urldecode((string) (parse_url($request->url(), PHP_URL_QUERY) ?? ''));
            $queries[] = $query;
            $url = str_contains($query, 'kozia')
                ? 'https://sklep.example/rekawice-urgent-1202-kozia'
                : 'https://hurtownia.example/urgent-1202';

            return Http::response(
                '<a href="/url?q='.rawurlencode($url).'&amp;sa=U">Rękawice Urgent 1202</a>',
                200
            );
        });

        $pack = app(HybridWebSearchService::class)->searchProduct($product, 'manufacturer');
        $urls = array_column($pack['results'], 'url');

        $this->assertContains('https://hurtownia.example/urgent-1202', $urls);
        $this->assertContains('https://sklep.example/rekawice-urgent-1202-kozia', $urls);
        $this->assertTrue(
            (bool) array_filter($queries, static fn (string $q): bool => str_contains($q, 'kozia')),
            'Druga fraza z nazwą produktu musi polecieć, gdy pierwsza dała jedną kartę'
        );
    }

    public function test_duckduckgo_search_skips_tavily(): void
    {
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'http://127.0.0.1:8081/v1',
            'api_key' => 'local',
            'model' => 'qwen38-27b-fast',
            'timeout_seconds' => 30,
            'temperature' => 0.1,
            'search_engine' => 'duckduckgo',
            'web_search_enabled' => false,
        ]);
        $product = $this->makeProduct([
            'sku' => 'NV2032CE',
            'name' => 'Astro Cleat',
            'manufacturer' => 'GVS',
        ]);
        $hitUrl = 'https://hurtownia.example/products/nv2032ce';
        Http::fake(function ($request) use ($hitUrl) {
            $this->assertStringNotContainsString('tavily.com', $request->url());
            if (str_contains($request->url(), 'google.com/search')) {
                return Http::response(
                    '<a href="/url?q='.rawurlencode($hitUrl).'&amp;sa=U">NV2032CE Astro Cleat</a>',
                    200
                );
            }

            return Http::response('unused', 404);
        });

        $pack = app(HybridWebSearchService::class)->searchProduct($product, 'manufacturer');

        $this->assertSame('duckduckgo', $pack['provider']);
        $this->assertSame($hitUrl, $pack['results'][0]['url'] ?? null);
        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), 'tavily.com'));
    }

    public function test_search_falls_back_to_manufacturer_site_when_sku_misses(): void
    {
        $this->seedTavilySettings();
        // marka spoza config/enrichment.php — inaczej domena byłaby znana i nie byłoby czego szukać
        $product = $this->makeProduct([
            'sku' => 'NV2032CE',
            'name' => 'Astro Cleat',
            'manufacturer' => 'Novacleat',
        ]);
        $mfrUrl = 'https://www.novacleat.com/products/nv2032ce-astro-cleat';
        $openQueries = [];
        $discoverQueries = [];
        $mfrQueries = [];

        Http::fake(function ($request) use ($mfrUrl, &$openQueries, &$discoverQueries, &$mfrQueries) {
            $this->assertStringContainsString('tavily.com', $request->url());
            $data = $request->data();
            $query = (string) ($data['query'] ?? '');
            $domains = $data['include_domains'] ?? [];
            if (is_array($domains) && $domains !== []) {
                $mfrQueries[] = $query;

                return Http::response([
                    'results' => [[
                        'url' => $mfrUrl,
                        'title' => 'NV2032CE Astro Cleat',
                        'content' => 'Cable cleat',
                    ]],
                ], 200);
            }
            if (str_contains(mb_strtolower($query), 'official')
                || str_contains(mb_strtolower($query), 'strona oficjalna')) {
                $discoverQueries[] = $query;

                return Http::response([
                    'results' => [[
                        'url' => $mfrUrl,
                        'title' => 'NV2032CE',
                        'content' => 'Novacleat',
                    ]],
                ], 200);
            }
            $openQueries[] = $query;

            return Http::response([
                'results' => [[
                    'url' => 'https://novacleat.sklep.pl/knx',
                    'title' => 'Novacleat KNX',
                    'content' => 'Bramka KNX',
                ]],
            ], 200);
        });

        $pack = app(HybridWebSearchService::class)->searchProduct($product, 'manufacturer');

        $this->assertSame([], $openQueries);
        $this->assertNotEmpty($discoverQueries);
        $this->assertStringContainsString('NV2032CE', $discoverQueries[0]);
        $this->assertStringContainsString('Novacleat', $discoverQueries[0]);
        $this->assertSame(['NV2032CE Novacleat'], $mfrQueries);
        $this->assertSame('tavily_manufacturer', $pack['provider']);
        $this->assertSame($mfrUrl, $pack['results'][0]['url'] ?? null);
    }

    public function test_artra_manufacturer_miss_searches_mapped_shops(): void
    {
        $this->seedTavilySettings();
        config([
            'enrichment.preferred_domains' => ['natare.pl'],
            'enrichment.retailer_domains' => ['natare.pl'],
            'enrichment.catalog_search_hosts' => [],
        ]);
        $product = $this->makeProduct([
            'sku' => 'ARISAKA 333 631460 S2 ESD',
            'name' => 'ARISAKA 333 631460 S2 ESD',
            'manufacturer' => 'ARTRA',
        ]);
        $shopUrl = 'https://natare.pl/polbuty-robocze-artra/9977-buty-robocze-polbuty-arisaka-333-631460-s2-esd-artra.html';
        $shopQueries = [];
        Http::fake(function ($request) use ($shopUrl, &$shopQueries) {
            if (! str_contains($request->url(), 'tavily.com')) {
                return Http::response('unused', 404);
            }
            $data = $request->data();
            $query = (string) ($data['query'] ?? '');
            $domains = $data['include_domains'] ?? [];
            $onNatare = str_contains(mb_strtolower($query), 'site:natare.pl')
                || (is_array($domains) && in_array('natare.pl', $domains, true));
            if ($onNatare) {
                $shopQueries[] = $query;

                return Http::response([
                    'results' => [[
                        'url' => $shopUrl,
                        'title' => 'ARISAKA 333 631460 S2 ESD ARTRA',
                        'content' => 'Półbuty robocze ARISAKA 333 S2 ESD',
                    ]],
                ], 200);
            }

            return Http::response(['results' => []], 200);
        });

        $pack = app(HybridWebSearchService::class)->searchProduct($product, 'manufacturer');

        $this->assertNotEmpty($shopQueries);
        $this->assertSame($shopUrl, $pack['results'][0]['url'] ?? null);
    }

    public function test_large_model_search_skips_tavily_and_uses_ai_web_search(): void
    {
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
            'web_search_enabled' => false,
        ]);

        $product = $this->makeProduct([
            'sku' => 'R065-TESTLARGE',
            'name' => 'RINGERS R065 rękawice',
            'manufacturer' => 'Ansell',
        ]);
        $productUrl = 'https://www.ansell.com/pl/pl/products/ringers-r065-testlarge';

        Http::fake(function ($request) use ($productUrl) {
            $url = $request->url();
            if (str_contains($url, 'tavily.com')) {
                return Http::response(['error' => 'tavily should not be called'], 500);
            }
            if (str_contains($url, '/chat/completions')) {
                return Http::response([
                    'model' => 'openai/gpt-4o',
                    'choices' => [[
                        'message' => [
                            'content' => $productUrl,
                            'annotations' => [[
                                'type' => 'url_citation',
                                'url_citation' => [
                                    'url' => $productUrl,
                                    'title' => 'Rękawice Ansell RINGERS R065-TESTLARGE',
                                ],
                            ]],
                        ],
                    ]],
                ], 200);
            }

            return Http::response(['unexpected' => $url], 599);
        });

        $pack = app(HybridWebSearchService::class)->searchProduct($product, 'manufacturer');

        $this->assertSame('ai_web_search', $pack['provider']);
        $this->assertSame($productUrl, $pack['results'][0]['url'] ?? null);
        Http::assertNotSent(static fn ($request): bool => str_contains($request->url(), 'tavily.com'));
        Http::assertSent(static fn ($request): bool => str_contains($request->url(), '/chat/completions'));
    }

    public function test_ansell_declaration_is_saved_when_direct_pdf_is_blocked(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('Test wymaga rozszerzenia GD.');
        }

        Storage::fake('public');
        $product = $this->makeProduct([
            'sku' => '065-06',
            'name' => 'RINGERS R065',
            'manufacturer' => 'Ansell',
        ]);
        $url = 'https://www.ansell.com/pl/pl/products/ringers-r065/doc/Go87cSZ9VhPOWkcvnKfw6Q';

        Http::fake([
            'https://r.jina.ai/*' => Http::response(
                $this->certificatePng(),
                200,
                ['Content-Type' => 'image/png']
            ),
            'https://www.ansell.com/*' => Http::response(
                '<html>Incapsula</html>',
                403,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $documents = app(ProductDocumentDownloader::class)->downloadMany($product, [$url], 1);

        $this->assertCount(1, $documents);
        $this->assertSame(ProductDocument::KIND_CERTIFICATE, $documents[0]->kind);
        $this->assertSame('Deklaracja zgodności UE.pdf', $documents[0]->title);
        $this->assertSame($url, $documents[0]->source_url);
        Storage::disk('public')->assertExists($documents[0]->path);
        $this->assertStringStartsWith(
            '%PDF',
            (string) Storage::disk('public')->get($documents[0]->path)
        );
    }

    /**
     * sanitizePagesWithLlm + extractWithLlm (chatJsonEnrichment).
     *
     * @param  array<string, mixed>  $extractPayload
     */
    private function mockLlmWithSanitize(array $extractPayload): OpenAiCompatibleClient
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $handler = function (array $messages) use ($extractPayload): array {
            $system = (string) ($messages[0]['content'] ?? '');
            if (str_contains($system, 'filtrem treści')) {
                $user = (string) ($messages[1]['content'] ?? '');
                $pages = [];
                if (preg_match_all('#"url"\s*:\s*"(https?://[^"]+)"#', $user, $m)) {
                    foreach ($m[1] as $url) {
                        $pages[] = [
                            'url' => $url,
                            'text' => 'Produkt BHP. Norma EN 388. Przeznaczony do pracy ochronnej.',
                        ];
                    }
                }

                return ['pages' => $pages !== [] ? $pages : [[
                    'url' => 'https://example.com/p',
                    'text' => 'Produkt BHP. Norma EN 388.',
                ]]];
            }

            return $extractPayload;
        };
        $llm->shouldReceive('chatJsonEnrichment')
            ->atLeast()
            ->once()
            ->andReturnUsing($handler);
        $llm->shouldReceive('chatJson')
            ->zeroOrMoreTimes()
            ->andReturnUsing($handler);
        $llm->shouldReceive('chatJsonWithImages')
            ->zeroOrMoreTimes()
            ->andReturn(['candidates' => []])
            ->byDefault();

        return $llm;
    }

    private function seedTavilySettings(): void
    {
        AiSetting::query()->create([
            'enabled' => true,
            'provider' => 'openai_compatible',
            'base_url' => 'https://openrouter.ai/api/v1',
            'api_key' => 'sk-test-key-1234567890',
            'model' => 'openai/gpt-4o',
            'timeout_seconds' => 30,
            'temperature' => 0.1,
            'web_search_enabled' => false,
            'tavily_api_key' => 'tvly-test-key-1234567890',
            'tavily_search_mode' => 'balanced',
            'search_engine' => 'tavily',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    /**
     * Atrapa wyszukiwarki z bezpiecznymi domyślnymi odpowiedziami na kroki pomocnicze.
     * Bez nich każde wywołanie dropListingResults wysypywało test na starcie i ścieżka
     * wzbogacania przez długi czas nie była sprawdzana. Oczekiwania testu mają pierwszeństwo.
     */
    private function searchMock(): MockInterface
    {
        $mock = Mockery::mock(HybridWebSearchService::class);
        $mock->shouldReceive('dropListingResults')
            ->andReturnUsing(static fn (array $results): array => $results)
            ->byDefault();
        $mock->shouldReceive('moreCatalogHits')->andReturn([])->byDefault();
        $mock->shouldReceive('searchMappedRetailers')->andReturn([])->byDefault();
        $mock->shouldReceive('searchWebWithoutLocalIndex')
            ->andReturn(['results' => [], 'images' => [], 'errors' => []])
            ->byDefault();
        $mock->shouldReceive('forgetProductCache')->byDefault();

        return $mock;
    }

    private function makeProduct(array $overrides = []): Product
    {
        return Product::query()->create(array_merge([
            'sku' => 'SKU-'.uniqid(),
            'name' => 'Rękawice testowe',
            'manufacturer' => 'Ansell',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ], $overrides));
    }

    private function tinyJpeg(): string
    {
        // >= 80x80 â€” downloader odrzuca placeholdery mniejsze niĹĽ 80 px
        if (function_exists('imagecreatetruecolor')) {
            $img = imagecreatetruecolor(220, 220);
            $bg = imagecolorallocate($img, 40, 120, 200);
            imagefill($img, 0, 0, $bg);
            ob_start();
            imagejpeg($img, null, 85);
            imagedestroy($img);
            $bytes = ob_get_clean();

            return is_string($bytes) ? $bytes : '';
        }

        return base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAGfAP/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAQUCf//EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQMBAT8Bf//EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQIBAT8Bf//Z'
        ) ?: '';
    }

    private function certificatePng(): string
    {
        $image = imagecreatetruecolor(1190, 1684);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefill($image, 0, 0, $white);
        for ($y = 0; $y < 1684; $y += 4) {
            $color = imagecolorallocate(
                $image,
                ($y * 17) % 230,
                ($y * 29) % 230,
                ($y * 43) % 230
            );
            imageline($image, 0, $y, 1189, $y, $color);
        }
        ob_start();
        imagepng($image);
        imagedestroy($image);
        $bytes = ob_get_clean();

        return is_string($bytes) ? $bytes : '';
    }

    public function test_active_batches_releases_product_stuck_in_running(): void
    {
        $user = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($user);

        $product = $this->makeProduct([
            'sku' => 'SG-RWT001',
            'name' => 'SPODNIE DO PASA',
            'manufacturer' => 'AJ GROUP',
            'enrichment_status' => Product::ENRICHMENT_RUNNING,
        ]);
        DB::table('products')->where('id', $product->id)->update([
            'updated_at' => now()->subHours(3),
        ]);

        $this->getJson('/api/product-enrichment-batches/active')->assertOk();

        $product->refresh();
        $this->assertSame(Product::ENRICHMENT_FAILED, $product->enrichment_status);
        $this->assertStringContainsString('proces zniknął', (string) $product->enrichment_error);
    }

    public function test_active_batches_leaves_product_that_only_just_started(): void
    {
        $user = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($user);

        $product = $this->makeProduct([
            'sku' => 'SG-RWT002',
            'enrichment_status' => Product::ENRICHMENT_RUNNING,
        ]);
        DB::table('products')->where('id', $product->id)->update([
            'updated_at' => now()->subMinutes(3),
        ]);

        $this->getJson('/api/product-enrichment-batches/active')->assertOk();

        $this->assertSame(Product::ENRICHMENT_RUNNING, $product->refresh()->enrichment_status);
    }

    public function test_active_batches_leaves_stale_product_whose_job_still_waits(): void
    {
        $user = User::factory()->withRole('admin')->create();
        Sanctum::actingAs($user);

        $product = $this->makeProduct([
            'sku' => 'SG-RWT003',
            'enrichment_status' => Product::ENRICHMENT_RUNNING,
        ]);
        DB::table('products')->where('id', $product->id)->update([
            'updated_at' => now()->subHours(3),
        ]);
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{"data":{"command":"O:31:\\"App\\Jobs\\EnrichProductJob\\":1:{s:9:\\"productId\\";i:'
                .$product->id.';}"}}',
            'attempts' => 0,
            'available_at' => time(),
            'created_at' => time(),
        ]);

        $this->getJson('/api/product-enrichment-batches/active')->assertOk();

        $this->assertSame(Product::ENRICHMENT_RUNNING, $product->refresh()->enrichment_status);
    }
}
