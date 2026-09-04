<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use App\Support\CatalogManufacturerContext;
use App\Support\PpeAssortment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

final class ProductAiSearchCascadeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
    }

    public function test_catalog_and_tender_paths_peel_missing_brand_to_nitrile(): void
    {
        Product::query()->create([
            'sku' => 'BOOT-1',
            'name' => 'Trzewiki skórzane S3',
            'manufacturer' => 'Reis',
            'description' => 'Trzewiki.',
            'catalog_price_net' => 80,
            'purchase_price' => 40,
            'stock' => 2,
            'ppe_family' => PpeAssortment::FAMILY_FOOTWEAR,
        ]);
        Product::query()->create([
            'sku' => '93-843',
            'name' => 'Niebieskie bezpudrowe rękawice nitrylowe',
            'manufacturer' => 'Ansell',
            'description' => 'Jednorazowe rękawice nitrylowe bezpudrowe.',
            'catalog_price_net' => 12,
            'purchase_price' => 6,
            'stock' => 20,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        CatalogManufacturerContext::forgetCache();

        $this->app->instance(OpenAiCompatibleClient::class, $this->emptyRankLlm());

        $catalog = $this->postJson('/api/products/ai-search', [
            'query' => 'Rękawice nitrylowe RTELA',
            'limit' => 10,
        ])->assertOk();
        $this->assertContains('93-843', array_column($catalog->json('products'), 'sku'));
        $this->assertNotContains('BOOT-1', array_column($catalog->json('products'), 'sku'));

        $tender = $this->app->make(ProductAiSearchService::class)->searchMany(
            ['Rękawice nitrylowe RTELA'],
            10
        );
        $this->assertContains('93-843', array_column($tender[0]['products'] ?? [], 'sku'));
    }

    private function emptyRankLlm(): OpenAiCompatibleClient
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn(['matches' => []]);
        $llm->shouldReceive('chatJsonMany')->andReturnUsing(static function (array $messages): array {
            return array_fill(0, count($messages), ['matches' => []]);
        });

        return $llm;
    }
}
