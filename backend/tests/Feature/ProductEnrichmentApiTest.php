<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\EnrichProductJob;
use App\Models\AiSetting;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Enrichment\HybridWebSearchService;
use App\Services\Enrichment\ProductEnrichmentService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

final class ProductEnrichmentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_enqueue_product_enrichment_creates_batch_and_job(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $product = $this->makeProduct();

        $this->postJson("/api/products/{$product->id}/enrich")
            ->assertStatus(202)
            ->assertJsonPath('batch.total', 1)
            ->assertJsonPath('batch.scope', 'product')
            ->assertJsonPath('product_id', $product->id);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'enrichment_status' => Product::ENRICHMENT_QUEUED,
        ]);

        Queue::assertPushed(EnrichProductJob::class, function (EnrichProductJob $job) use ($product): bool {
            return $job->productId === $product->id && $job->force === false;
        });
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

    public function test_force_reenqueues_done_product(): void
    {
        Queue::fake();
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $product = $this->makeProduct([
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'description' => 'Stary opis',
            'enriched_at' => now(),
        ]);

        $this->postJson("/api/products/{$product->id}/enrich", ['force' => true])
            ->assertStatus(202);

        Queue::assertPushed(EnrichProductJob::class, fn (EnrichProductJob $job): bool => $job->force === true);
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
            ->assertJsonPath('batch.scope', 'price_list');

        Queue::assertPushed(EnrichProductJob::class, 1);
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
            ->assertJsonPath('batch.scope', 'products');

        Queue::assertPushed(EnrichProductJob::class, 2);
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
            app(\App\Services\Enrichment\ProductImageDownloader::class),
            app(\App\Services\Enrichment\ProductPageFetcher::class),
            $llm,
        );

        $service->enrichProduct($product, false);

        $product->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status);
        $this->assertSame('Opis z cache SKU.', $product->description);
        $this->assertTrue((bool) ($product->enrichment_payload['from_cache'] ?? false));
    }

    public function test_enrichment_service_saves_description_and_image(): void
    {
        Storage::fake('public');

        $product = $this->makeProduct();

        $search = Mockery::mock(HybridWebSearchService::class);
        $search->shouldReceive('searchBothPhases')
            ->once()
            ->andReturn([
                'results' => [
                    [
                        'url' => 'https://example.com/product/'.$product->sku,
                        'title' => 'Karta',
                        'snippet' => 'Rękawice ochronne nitrylowe EN 388',
                    ],
                ],
                'errors' => [],
            ]);

        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')
            ->once()
            ->andReturn([
                'description' => 'Rękawice nitrylowe do pracy w przemyśle. Spełniają normy EN 388.',
                'features' => ['nitryl', 'antypoślizgowe'],
                'specs' => ['Długość: 30 cm'],
                'norms' => ['EN 388'],
                'certificates' => ['CE'],
                'materials' => ['nitryl'],
                'use_cases' => ['montaż'],
                'image_urls' => ['https://cdn.example.com/glove-'.$product->sku.'.jpg'],
                'source_urls' => ['https://example.com/product/'.$product->sku],
                'confidence' => 0.9,
            ]);

        Http::fake([
            'https://example.com/*' => Http::response(
                '<html><body>Rękawice '.$product->sku.' EN 388 <img src="https://cdn.example.com/glove-'.$product->sku.'.jpg" alt="glove '.$product->sku.'"></body></html>',
                200,
                ['Content-Type' => 'text/html']
            ),
            'https://cdn.example.com/*' => Http::response(
                $this->tinyJpeg(),
                200,
                ['Content-Type' => 'image/jpeg']
            ),
        ]);

        $service = new ProductEnrichmentService(
            $search,
            app(\App\Services\Enrichment\ProductImageDownloader::class),
            app(\App\Services\Enrichment\ProductPageFetcher::class),
            $llm,
        );

        $service->enrichProduct($product, false);

        $product->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status);
        $this->assertStringContainsString('Rękawice nitrylowe', (string) $product->description);
        $this->assertStringContainsString('Specyfikacja', (string) $product->description);
        $this->assertIsArray($product->enrichment_payload);
        $this->assertSame(['nitryl', 'antypoślizgowe'], $product->enrichment_payload['features'] ?? null);
        $this->assertSame(1, ProductImage::query()->where('product_id', $product->id)->count());
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
        // 1x1 JPEG
        return base64_decode(
            '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0aHBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/2wBDAQkJCQwLDBgNDRgyIRwhMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjIyMjL/wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAn/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/8QAFQEBAQAAAAAAAAAAAAAAAAAAAAX/xAAUEQEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAGfAP/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAQUCf//EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQMBAT8Bf//EABQRAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQIBAT8Bf//Z'
        ) ?: '';
    }
}
