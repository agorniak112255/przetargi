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

    public function test_empty_rank_peels_query_steps_to_show_catalog_cloth(): void
    {
        Product::query()->create([
            'sku' => 'SB085290N',
            'name' => 'Scotch-Brite Ścierka z mikrowłókna',
            'manufacturer' => '3M',
            'description' => 'Ścierka z mikrowłókna do czyszczenia.',
            'catalog_price_net' => 6.24,
            'purchase_price' => 3.4,
            'stock' => 20,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        Product::query()->create([
            'sku' => 'PAD-505',
            'name' => '3M Pad podłogowy linia Klasyczna, brązowy, 505 mm',
            'manufacturer' => '3M',
            'description' => 'Pad, tetra w dokumentacji.',
            'search_blob' => 'tetra pad podlogowy',
            'catalog_price_net' => 20,
            'purchase_price' => 10,
            'stock' => 4,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $this->app->instance(OpenAiCompatibleClient::class, $this->emptyRankLlm());

        $skus = $this->postJson('/api/products/ai-search', [
            'query' => 'ŚCIERKA TETRA 60 x 85 cm',
            'limit' => 10,
        ])
            ->assertOk()
            ->json('products');

        $this->assertContains('SB085290N', array_column($skus, 'sku'));
        $this->assertNotContains('PAD-505', array_column($skus, 'sku'));
    }

    public function test_apparel_set_shows_jacket_and_bibs_not_hood(): void
    {
        Product::query()->create([
            'sku' => 'BLUZA-KOLPEO',
            'name' => 'Bluza KOLPEO BASIC ZIPPER - zamek',
            'manufacturer' => 'Cerva',
            'norms' => 'EN ISO 11611:2015, EN 1149-5:2018',
            'description' => 'Bluza trudnopalna, wzmianka EN 20471.',
            'catalog_price_net' => 80,
            'purchase_price' => 50,
            'stock' => 3,
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        Product::query()->create([
            'sku' => 'OGROD-KOLPEO',
            'name' => 'Spodnie ogrodniczki KOLPEO BASIC',
            'manufacturer' => 'Cerva',
            'norms' => 'EN ISO 11611:2015, EN 1149-5:2018',
            'description' => 'Ogrodniczki trudnopalne.',
            'catalog_price_net' => 70,
            'purchase_price' => 45,
            'stock' => 3,
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        Product::query()->create([
            'sku' => 'CAFR1',
            'name' => 'KAPTUR NIEPALNY I ANTYELEKTROSTATYCZNY',
            'manufacturer' => 'Delta Plus',
            'norms' => 'EN 1149-5',
            'description' => 'Kaptur ESD.',
            'catalog_price_net' => 130,
            'purchase_price' => 100,
            'stock' => 2,
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $this->app->instance(OpenAiCompatibleClient::class, $this->emptyRankLlm());

        $wear = $this->postJson('/api/products/ai-search', [
            'query' => 'Ubranie antyelektrostatyczne, trudnopalne (bluza + spodnie do pasa lub ogrodniczki) EN ISO 11611 kl. 2 EN 1149-5',
            'limit' => 10,
        ])->assertOk()->json('products');
        $skus = array_column($wear, 'sku');
        $this->assertContains('BLUZA-KOLPEO', $skus);
        $this->assertContains('OGROD-KOLPEO', $skus);
        $this->assertNotContains('CAFR1', $skus);
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
