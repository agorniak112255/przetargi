<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\ProductAiSearchService;
use App\Support\PpeAssortment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Golden case trzewiki-s3: klasa S3 z karty, nie cena i nie podciąg „S3” w LLM.
 */
final class ProductAiSearchS3Test extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'Trzewiki robocze w klasie ochrony S3 SRC z podnoskiem';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_s3_path_returns_manhattan_not_s1p_without_llm_pick(): void
    {
        $this->seedS3Catalog();
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => self::QUERY,
            'search_phrases' => ['trzewiki robocze'],
            'matches' => [],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $search = $this->app->make(ProductAiSearchService::class);
        $result = $search->search(self::QUERY, 10);
        $skus = array_column($result['products'] ?? [], 'sku');

        $this->assertContains('MANHATTAN S3 SRC', $skus);
        $this->assertContains('SAGA S3 SRC', $skus);
        $this->assertNotContains('VIRAGE S1P SRC', $skus);
        $this->assertNotContains('ARONA S1P SRC', $skus);
    }

    private function seedS3Catalog(): void
    {
        $base = [
            'category' => 'Obuwie ochronne / Trzewiki',
            'stock' => 4,
            'ppe_family' => PpeAssortment::FAMILY_FOOTWEAR,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subYear(),
        ];
        Product::query()->create($base + [
            'sku' => 'MANHATTAN S3 SRC',
            'name' => 'TRZEWIKI MANHATTAN S3 SRC z podnoskiem',
            'manufacturer' => 'Cerva',
            'description' => 'Trzewiki S3 SRC.',
            'catalog_price_net' => 400,
            'purchase_price' => 320,
        ]);
        Product::query()->create($base + [
            'sku' => 'SAGA S3 SRC',
            'name' => 'TRZEWIKI SAGA S3 SRC z podnoskiem',
            'manufacturer' => 'Cerva',
            'description' => 'Trzewiki S3 SRC.',
            'catalog_price_net' => 430,
            'purchase_price' => 350,
        ]);
        Product::query()->create($base + [
            'sku' => 'VIRAGE S1P SRC',
            'name' => 'TRZEWIKI VIRAGE S1P SRC',
            'manufacturer' => 'Cerva',
            'description' => 'Trzewiki S1P SRC.',
            'catalog_price_net' => 80,
            'purchase_price' => 40,
        ]);
        Product::query()->create($base + [
            'sku' => 'ARONA S1P SRC',
            'name' => 'TRZEWIKI ARONA S1P SRC',
            'manufacturer' => 'Cerva',
            'description' => 'Trzewiki S1P SRC.',
            'catalog_price_net' => 70,
            'purchase_price' => 30,
        ]);
        for ($i = 0; $i < 4; $i++) {
            Product::query()->create($base + [
                'sku' => 'JUMPER-S3-'.$i,
                'name' => 'TRZEWIKI JUMPER'.$i.' S3 SRC',
                'manufacturer' => 'Cerva',
                'description' => 'Trzewiki S3 SRC.',
                'catalog_price_net' => 90,
                'purchase_price' => 50,
            ]);
        }
    }
}
