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
 * Golden zestaw-higieniczny HY51: kod zestawu, nie linia Optime II/III.
 */
final class ProductAiSearchHygieneKitTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'Zestaw higieniczny do nauszników 3M OPTIME I HY51';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_search_returns_hy51_not_optime_ii_or_iii_kit(): void
    {
        $this->seedKits();
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => self::QUERY,
            'search_phrases' => ['zestaw higieniczny'],
            'matches' => [],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $skus = array_column(
            $this->app->make(ProductAiSearchService::class)->search(self::QUERY, 10)['products'] ?? [],
            'sku'
        );

        $this->assertContains('HY51', $skus);
        $this->assertNotContains('HY52', $skus);
        $this->assertNotContains('HY54', $skus);
        $this->assertNotContains('7100383166', $skus);
    }

    private function seedKits(): void
    {
        $base = [
            'manufacturer' => '3M',
            'category' => 'Ochrona słuchu / Akcesoria',
            'stock' => 4,
            'ppe_family' => PpeAssortment::FAMILY_HEARING,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subYear(),
        ];
        Product::query()->create($base + [
            'sku' => 'HY51',
            'name' => '3M Zestaw higieniczny do nauszników Bull\'s Eye I / Optime I',
            'description' => 'Zestaw higieniczny HY51 do Optime I.',
            'catalog_price_net' => 40,
            'purchase_price' => 25,
        ]);
        Product::query()->create($base + [
            'sku' => 'HY52',
            'name' => '3M Zestaw higieniczny do nauszników PELTOR H31 / Optime II',
            'description' => 'Zestaw higieniczny HY52 do Optime II.',
            'catalog_price_net' => 20,
            'purchase_price' => 10,
        ]);
        Product::query()->create($base + [
            'sku' => 'HY54',
            'name' => '3M Zestaw higieniczny do nauszników PELTOR Optime III',
            'description' => 'Zestaw higieniczny HY54 do Optime III.',
            'catalog_price_net' => 18,
            'purchase_price' => 8,
        ]);
        Product::query()->create($base + [
            'sku' => '7100383166',
            'name' => 'Zestaw do higienicznej wymiany nauszników 3M PELTOR, Optime II, HYX2, X2',
            'description' => 'HYX2 do Optime II.',
            'catalog_price_net' => 15,
            'purchase_price' => 7,
        ]);
    }
}
