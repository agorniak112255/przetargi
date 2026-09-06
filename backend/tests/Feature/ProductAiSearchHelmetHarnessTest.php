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
 * Golden case helm-wentylowany-msa-super-v: więźba i wentylacja z nazwy, nie sam V-Gard 500.
 */
final class ProductAiSearchHelmetHarnessTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'Hełm wentylowany MSA SUPER V - GARD 500 ATEX czasza ABS - różne kolory, więźba Fas-Trac';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_named_path_returns_fas_trac_vent_not_push_key(): void
    {
        $this->seedHelmets();
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => self::QUERY,
            'search_phrases' => ['hełm ochronny', 'v-gard 500'],
            'matches' => [],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);

        $search = $this->app->make(ProductAiSearchService::class);
        $result = $search->search(self::QUERY, 10);
        $skus = array_column($result['products'] ?? [], 'sku');

        $this->assertContains('GV412-0000000-000', $skus);
        $this->assertNotContains('GV411-0000000-000', $skus);
        $this->assertNotContains('GV512-0000000-000', $skus);
        $this->assertNotContains('GV112-0000000-000', $skus);
    }

    private function seedHelmets(): void
    {
        $base = [
            'manufacturer' => 'MSA',
            'category' => 'Ochrona głowy',
            'stock' => 3,
            'ppe_family' => PpeAssortment::FAMILY_HEAD,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subYear(),
        ];
        Product::query()->create($base + [
            'sku' => 'GV412-0000000-000',
            'name' => 'V-Gard 500, biały, wentylowany, więźba Fas-Trac III',
            'description' => 'Hełm V-Gard 500 wentylowany Fas-Trac.',
            'catalog_price_net' => 80,
            'purchase_price' => 50,
        ]);
        Product::query()->create($base + [
            'sku' => 'GV411-0000000-000',
            'name' => 'V-Gard 500, biały, wentylowany, więźba Push-Key',
            'description' => 'Hełm V-Gard 500 wentylowany Push-Key.',
            'catalog_price_net' => 40,
            'purchase_price' => 20,
        ]);
        Product::query()->create($base + [
            'sku' => 'GV512-0000000-000',
            'name' => 'V-Gard 500, biały, więźba Fas-Trac III',
            'description' => 'Hełm V-Gard 500 bez wentylacji.',
            'catalog_price_net' => 70,
            'purchase_price' => 40,
        ]);
        Product::query()->create($base + [
            'sku' => 'GV112-0000000-000',
            'name' => 'V-Gard, biały, więźba Fas-Trac III',
            'description' => 'Hełm V-Gard bez 500, bez wentylacji.',
            'catalog_price_net' => 30,
            'purchase_price' => 15,
        ]);
    }
}
