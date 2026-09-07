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
 * Golden Araukan 940 6060 S3: dokładny 940 S3, nie tańszy S2 i nie sąsiedni indeks 9403.
 */
final class ProductAiSearchAraukanTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'Trzewiki Araukan 940 6060 S3 · EN ISO 20345';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_araukan_940_s3_ranks_before_9403_and_drops_s2(): void
    {
        $this->seedAraukanCatalog();
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => self::QUERY,
            'search_phrases' => ['trzewiki araukan'],
            'matches' => [],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $skus = array_column(
            $this->app->make(ProductAiSearchService::class)->search(self::QUERY, 10)['products'] ?? [],
            'sku'
        );

        $this->assertSame('ARAUKAN 940 6060 S3', $skus[0] ?? null);
        $this->assertContains('ARAUKAN 940 6060 S3 CI', $skus);
        $this->assertNotContains('ARAUKAN 940 6060 S2', $skus);
        $this->assertNotContains('ARAUKAN 940 6060 O2 FO', $skus);
    }

    private function seedAraukanCatalog(): void
    {
        $base = [
            'category' => 'Obuwie ochronne / Trzewiki',
            'stock' => 3,
            'ppe_family' => PpeAssortment::FAMILY_FOOTWEAR,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
            'manufacturer' => 'Araukan',
            'currency' => 'EUR',
        ];
        Product::query()->create($base + [
            'sku' => 'ARAUKAN 940 6060 S3',
            'name' => 'ARAUKAN 940 6060 S3',
            'description' => 'Trzewiki S3.',
            'catalog_price_net' => 30,
            'purchase_price' => 24.97,
        ]);
        Product::query()->create($base + [
            'sku' => 'ARAUKAN 940 6060 S3 CI',
            'name' => 'ARAUKAN 940 6060 S3 CI',
            'description' => 'Trzewiki S3 ocieplane.',
            'catalog_price_net' => 34,
            'purchase_price' => 28.16,
        ]);
        Product::query()->create($base + [
            'sku' => 'ARAUKAN 9403 6060 S3L',
            'name' => 'ARAUKAN 9403 6060 S3L',
            'description' => 'Trzewiki S3L.',
            'catalog_price_net' => 32,
            'purchase_price' => 26.50,
        ]);
        Product::query()->create($base + [
            'sku' => 'ARAUKAN 940 6060 S2',
            'name' => 'ARAUKAN 940 6060 S2',
            'description' => 'Trzewiki S2.',
            'catalog_price_net' => 28,
            'purchase_price' => 23.46,
        ]);
        Product::query()->create($base + [
            'sku' => 'ARAUKAN 940 6060 O2 FO',
            'name' => 'ARAUKAN 940 6060 O2 FO',
            'description' => 'Półbuty O2.',
            'catalog_price_net' => 27,
            'purchase_price' => 22.89,
        ]);
    }
}
