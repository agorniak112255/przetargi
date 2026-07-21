<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\EnrichProductJob;
use App\Models\AiSetting;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Enrichment\HybridWebSearchService;
use App\Services\Enrichment\ProductDocumentDownloader;
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
            'description' => 'Rękawice nitrylowe do pracy w przemyśle. Spełniają normy EN 388 i chronią przed ścieraniem. Przeznaczone do montażu oraz prac precyzyjnych w warunkach suchych. Trwała powłoka nitrylowa zwiększa żywotność przy codziennym użytkowaniu.',
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
            'description' => 'Nowy opis po force. Spełnia normy EN 388 i chroni dłonie przy montażu. Trwała powłoka nitrylowa do codziennej pracy w zakładzie produkcyjnym oraz warsztacie.',
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

        $this->getJson('/api/product-enrichment-batches/active')
            ->assertOk()
            ->assertJsonFragment(['id' => $batch->id, 'status' => 'running']);
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
            app(\App\Services\Enrichment\ProductDocumentDownloader::class),
            app(\App\Services\Enrichment\ProductPageFetcher::class),
            app(\App\Services\Enrichment\ProductDocumentFinder::class),
            app(\App\Services\Enrichment\ManufacturerDomainResolver::class),
            $llm,
            app(\App\Services\Ai\AiSettingsService::class),
            app(\App\Support\BhpAttributeNormalizer::class),
            app(\App\Services\Enrichment\ProductSearchIdentity::class),
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

        $richDescription = 'Rękawice nitrylowe do pracy w przemyśle. Spełniają normy EN 388 i chronią przed ścieraniem. '
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
            app(\App\Services\Enrichment\ProductImageDownloader::class),
            app(\App\Services\Enrichment\ProductDocumentDownloader::class),
            app(\App\Services\Enrichment\ProductPageFetcher::class),
            app(\App\Services\Enrichment\ProductDocumentFinder::class),
            app(\App\Services\Enrichment\ManufacturerDomainResolver::class),
            $llm,
            app(\App\Services\Ai\AiSettingsService::class),
            app(\App\Support\BhpAttributeNormalizer::class),
            app(\App\Services\Enrichment\ProductSearchIdentity::class),
        );

        $service->enrichProduct($product, false);

        $product->refresh();
        $this->assertSame(Product::ENRICHMENT_DONE, $product->enrichment_status);
        $this->assertStringContainsString('Rękawice nitrylowe', (string) $product->description);
        $this->assertIsArray($product->enrichment_payload);
        $this->assertSame(['nitryl', 'antypoślizgowe'], $product->enrichment_payload['features'] ?? null);
        $this->assertSame(1, ProductImage::query()->where('product_id', $product->id)->count());
        $this->assertSame(1, \App\Models\ProductDocument::query()->where('product_id', $product->id)->count());
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

        return $llm;
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
