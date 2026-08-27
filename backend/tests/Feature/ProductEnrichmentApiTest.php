<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\ProductSourcesNotFoundException;
use App\Jobs\EnrichProductJob;
use App\Jobs\PrefetchProductSourcesJob;
use App\Models\AiSetting;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Enrichment\DuckDuckGoHtmlSearch;
use App\Services\Enrichment\HybridWebSearchService;
use App\Services\Enrichment\ManufacturerDomainResolver;
use App\Services\Enrichment\ProductDocumentDownloader;
use App\Services\Enrichment\EnrichmentSlots;
use App\Services\Enrichment\PrefetchSlots;
use App\Services\Enrichment\ProductDocumentFinder;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Enrichment\ProductImageCandidateVerifier;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\Enrichment\ProductPageFetcher;
use App\Services\Enrichment\ProductSearchIdentity;
use App\Support\BhpAttributeNormalizer;
use App\Support\PpeAssortment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
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

    public function test_skips_document_search_only_when_pdf_url_mentions_sku(): void
    {
        $reflection = new \ReflectionClass(ProductEnrichmentService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('alreadyHasProductPdf');
        $method->setAccessible(true);
        $product = new Product(['sku' => '60549', 'name' => 'Uvex C500', 'manufacturer' => 'Uvex']);

        $this->assertTrue($method->invoke($service, [
            'https://cdn.example.com/DATASHEET/60549_PDB_EN.pdf',
        ], $product));
        $this->assertFalse($method->invoke($service, [
            'https://cdn.example.com/katalog-ogolny.pdf',
        ], $product));
        $this->assertFalse($method->invoke($service, [], $product));
    }

    public function test_single_product_enrichment_runs_synchronously(): void
    {
        Queue::fake();
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $product = $this->makeProduct();

        $search = Mockery::mock(HybridWebSearchService::class);
        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn([
                'results' => [[
                    'url' => 'https://example.com/product/'.$product->sku,
                    'title' => 'Karta',
                    'snippet' => 'RÄ™kawice '.$product->sku,
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
                '<html><body>'.$product->sku.' <img src="https://cdn.example.com/glove-'.$product->sku.'.jpg" alt="'.$product->sku.'"></body></html>',
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
        Queue::fake();
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $product = $this->makeProduct([
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'description' => 'Już jest',
            'enriched_at' => now(),
        ]);

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

        $search = Mockery::mock(HybridWebSearchService::class);
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
                    'snippet' => $product->sku,
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
                '<html>'.$product->sku.' <img src="https://cdn.example.com/glove-'.$product->sku.'.jpg"></html>',
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
            ->assertJsonMissing(['id' => $batch->id]);

        $batch->refresh();
        $this->assertSame(ProductEnrichmentBatch::STATUS_DONE, $batch->status);
        $this->assertSame(11, $batch->done);
        $this->assertNull($batch->current_sku);
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
        Queue::assertNotPushed(EnrichProductJob::class);
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

        $search = Mockery::mock(HybridWebSearchService::class);
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
        Http::assertSentCount(1);
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
            $lock = \Illuminate\Support\Facades\Cache::lock('enrichment_prefetch_gate:'.$i, 180);
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

    public function test_prefetch_runs_when_one_of_three_slots_is_free(): void
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

        $search = Mockery::mock(HybridWebSearchService::class);
        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn(['results' => [], 'errors' => []]);
        $this->app->instance(HybridWebSearchService::class, $search);

        $held = \Illuminate\Support\Facades\Cache::lock('enrichment_prefetch_gate:0', 180);
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

        $search = Mockery::mock(HybridWebSearchService::class);
        $search->shouldNotReceive('searchBothPhases');

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldNotReceive('chatJson');

        $service = new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ProductDocumentFinder::class),
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

    public function test_product_absent_from_web_goes_to_manual_and_leaves_queues(): void
    {
        Sanctum::actingAs($user = User::factory()->withRole('admin')->create());

        $product = $this->makeProduct([
            'sku' => 'UVEX-GK-PROGR-CR39',
            'manufacturer' => 'UVEX',
            'description' => null,
        ]);

        $search = Mockery::mock(HybridWebSearchService::class);
        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn(['results' => [], 'errors' => ['Brak stron produktu']]);

        $service = new ProductEnrichmentService(
            $search,
            app(ProductImageDownloader::class),
            app(ProductDocumentDownloader::class),
            app(ProductPageFetcher::class),
            app(ProductDocumentFinder::class),
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

    public function test_enrichment_service_saves_description_and_image(): void
    {
        Storage::fake('public');

        $product = $this->makeProduct();

        $search = Mockery::mock(HybridWebSearchService::class);
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
            app(ProductDocumentFinder::class),
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
        $method = new \ReflectionMethod($service, 'pickPrimaryImageUrls');
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
        $method = new \ReflectionMethod(HybridWebSearchService::class, 'filterResultsByIdentity');
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
        $method = new \ReflectionMethod(HybridWebSearchService::class, 'filterResultsByIdentity');
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
        $method = new \ReflectionMethod(HybridWebSearchService::class, 'filterResultsByIdentity');
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
        $method = new \ReflectionMethod(HybridWebSearchService::class, 'filterResultsByIdentity');
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
            'manufacturer' => 'GVS',
        ]);
        $hitUrl = 'https://hurtownia.example/products/nv2032ce';
        $tavilyCalls = 0;
        Http::fake(function ($request) use ($hitUrl, &$tavilyCalls) {
            $this->assertStringContainsString('tavily.com', $request->url());
            $tavilyCalls++;
            $this->assertSame([], $request->data()['include_domains'] ?? []);

            return Http::response([
                'results' => [[
                    'url' => $hitUrl,
                    'title' => 'NV2032CE Astro Cleat',
                    'content' => 'Cable cleat',
                ]],
            ], 200);
        });

        $pack = app(HybridWebSearchService::class)->searchProduct($product, 'manufacturer');

        $this->assertSame(1, $tavilyCalls);
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

        // najpierw sam kod z producentem, potem nazwa z kodem, fraza z BHP, na końcu kod w cudzysłowie
        $this->assertSame([
            'NV2032CE Novacleat',
            'Astro Cleat NV2032CE Novacleat',
            'Astro Cleat NV2032CE Novacleat BHP',
            '"NV2032CE" Novacleat',
        ], $openQueries);
        $this->assertNotEmpty($discoverQueries);
        $this->assertStringContainsString('NV2032CE', $discoverQueries[0]);
        $this->assertStringContainsString('Novacleat', $discoverQueries[0]);
        $this->assertSame(['NV2032CE Novacleat'], $mfrQueries);
        $this->assertSame('tavily_manufacturer', $pack['provider']);
        $this->assertSame($mfrUrl, $pack['results'][0]['url'] ?? null);
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
}
