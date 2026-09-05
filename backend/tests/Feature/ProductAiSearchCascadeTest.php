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
        Product::query()->create([
            'sku' => '51548',
            'name' => 'Krążek ścierny 3M Hookit Gold 288U, 150 mm',
            'manufacturer' => '3M',
            'description' => 'Krążek ścierny.',
            'catalog_price_net' => 2,
            'purchase_price' => 1,
            'stock' => 50,
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
        $this->assertNotContains('51548', array_column($skus, 'sku'));
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

    public function test_live_siwz_pool_keeps_jacket_when_recent_hits_are_sku_only(): void
    {
        for ($i = 0; $i < 80; $i++) {
            Product::query()->create([
                'sku' => 'FR'.(740 + $i),
                'name' => 'FR'.(740 + $i),
                'manufacturer' => 'Portwest',
                'norms' => 'EN ISO 11611:2015, EN 1149-5:2018',
                'description' => 'FR',
                'catalog_price_net' => 10,
                'purchase_price' => 5,
                'stock' => 1,
                'ppe_family' => PpeAssortment::FAMILY_APPAREL,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enriched_at' => now(),
            ]);
        }
        Product::query()->create([
            'sku' => 'BLUZA-KOLPEO',
            'name' => 'Bluza KOLPEO BASIC ZIPPER - zamek',
            'manufacturer' => 'Cerva',
            'norms' => 'EN ISO 11611:2015, EN 1149-5:2018',
            'description' => 'Bluza trudnopalna.',
            'catalog_price_net' => 80,
            'purchase_price' => 50,
            'stock' => 3,
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subYear(),
        ]);

        $this->app->instance(OpenAiCompatibleClient::class, $this->emptyRankLlm());

        $wear = $this->postJson('/api/products/ai-search', [
            'query' => "Ubranie antyelektrostatyczne, trudnopalne (bluza + spodnie do pasa lub ogrodniczki)\n· EN ISO 11611 kl. 2\nEN 1149-5",
            'limit' => 10,
        ])->assertOk()->json('products');

        $this->assertContains('BLUZA-KOLPEO', array_column($wear, 'sku'));
        $this->assertNotContains('FR740', array_column($wear, 'sku'));
    }

    public function test_apparel_set_interleaves_jacket_among_cheaper_bibs(): void
    {
        for ($i = 0; $i < 6; $i++) {
            Product::query()->create([
                'sku' => 'OGROD-'.$i,
                'name' => 'Spodnie ogrodniczki TARAJ '.$i,
                'manufacturer' => 'Panther',
                'norms' => 'EN ISO 11611:2015, EN 1149-5:2018',
                'description' => 'Ogrodniczki trudnopalne.',
                'catalog_price_net' => 80,
                'purchase_price' => 20 + $i,
                'stock' => 2,
                'ppe_family' => PpeAssortment::FAMILY_APPAREL,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enriched_at' => now(),
            ]);
        }
        Product::query()->create([
            'sku' => 'BLUZA-KOLPEO',
            'name' => 'Bluza KOLPEO BASIC ZIPPER - zamek',
            'manufacturer' => 'Cerva',
            'norms' => 'EN ISO 11611:2015, EN 1149-5:2018',
            'description' => 'Bluza trudnopalna.',
            'catalog_price_net' => 400,
            'purchase_price' => 300,
            'stock' => 3,
            'ppe_family' => PpeAssortment::FAMILY_APPAREL,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $this->app->instance(OpenAiCompatibleClient::class, $this->emptyRankLlm());

        $wear = $this->postJson('/api/products/ai-search', [
            'query' => 'Ubranie antyelektrostatyczne, trudnopalne (bluza + spodnie do pasa lub ogrodniczki) EN ISO 11611 kl. 2 EN 1149-5',
            'limit' => 4,
        ])->assertOk()->json('products');
        $skus = array_column($wear, 'sku');
        $this->assertContains('BLUZA-KOLPEO', $skus);
        $this->assertContains('OGROD-0', $skus);
    }

    public function test_glasses_plus_etui_returns_case_among_glasses(): void
    {
        for ($i = 0; $i < 4; $i++) {
            Product::query()->create([
                'sku' => 'GLASS-'.$i,
                'name' => '3M Virtua AP Okulary ochronne '.$i,
                'manufacturer' => '3M',
                'description' => 'Okulary ochronne.',
                'catalog_price_net' => 2,
                'purchase_price' => 1 + $i,
                'stock' => 10,
                'ppe_family' => PpeAssortment::FAMILY_EYES,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enriched_at' => now(),
            ]);
        }
        Product::query()->create([
            'sku' => 'HUBIX-H049',
            'name' => 'HUBIX H049 Okulary ochronne',
            'manufacturer' => 'HUBIX',
            'description' => 'Okulary ochronne H049.',
            'catalog_price_net' => 40,
            'purchase_price' => 25,
            'stock' => 3,
            'ppe_family' => PpeAssortment::FAMILY_EYES,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        for ($i = 0; $i < 6; $i++) {
            Product::query()->create([
                'sku' => 'POUCH-'.$i,
                'name' => 'woreczek dla wszystkich modeli okularów '.$i,
                'manufacturer' => 'uvex',
                'description' => 'Woreczek na okulary.',
                'catalog_price_net' => 1,
                'purchase_price' => 0.3,
                'stock' => 20,
                'ppe_family' => PpeAssortment::FAMILY_EYES,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enriched_at' => now(),
            ]);
        }
        Product::query()->create([
            'sku' => 'ETUI-HUBIX',
            'name' => 'Sztywne etui na okulary ochronne HUBIX',
            'manufacturer' => 'HUBIX',
            'description' => 'Etui na okulary.',
            'catalog_price_net' => 20,
            'purchase_price' => 12,
            'stock' => 5,
            'ppe_family' => PpeAssortment::FAMILY_EYES,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $this->app->instance(OpenAiCompatibleClient::class, $this->emptyRankLlm());

        $rows = $this->postJson('/api/products/ai-search', [
            'query' => 'Okulary ochronne HUBIX H049 + etui',
            'limit' => 6,
        ])->assertOk()->json('products');
        $skus = array_column($rows, 'sku');
        $this->assertContains('ETUI-HUBIX', $skus);
        $this->assertContains('HUBIX-H049', $skus);
        $this->assertSame('glasses', (new PpeAssortment)->eyeWearRole((string) ($rows[0]['name'] ?? '')));
        $etuiAt = array_search('ETUI-HUBIX', $skus, true);
        $this->assertIsInt($etuiAt);
        $this->assertGreaterThan(0, $etuiAt);
    }

    public function test_glasses_only_query_still_excludes_etui(): void
    {
        Product::query()->create([
            'sku' => 'GLASS-1',
            'name' => 'MSA PERSPECTA 010 - Okulary ochronne',
            'manufacturer' => 'MSA',
            'description' => 'Okulary ochronne.',
            'catalog_price_net' => 50,
            'purchase_price' => 30,
            'stock' => 2,
            'ppe_family' => PpeAssortment::FAMILY_EYES,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);
        Product::query()->create([
            'sku' => 'ETUI-MSA',
            'name' => 'Sztywne etui na okulary Perspecta',
            'manufacturer' => 'MSA',
            'description' => 'Etui.',
            'catalog_price_net' => 12,
            'purchase_price' => 6,
            'stock' => 4,
            'ppe_family' => PpeAssortment::FAMILY_EYES,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
        ]);

        $this->app->instance(OpenAiCompatibleClient::class, $this->emptyRankLlm());

        $rows = $this->postJson('/api/products/ai-search', [
            'query' => 'OKULARY OCHRONNE MSA PERSPECTA 010',
            'limit' => 5,
        ])->assertOk()->json('products');
        $this->assertNotContains('ETUI-MSA', array_column($rows, 'sku'));
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
