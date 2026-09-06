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

final class ProductAiSearchPhynomicPerspectaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_phynomic_esd_keeps_airlite_and_drops_c3(): void
    {
        $this->seedPhynomic();
        $this->bindEmptyLlm();
        $q = 'Rękawice montażowe powlekane uvex phynomic z funkcją ESD';
        $skus = array_column($this->app->make(ProductAiSearchService::class)->search($q, 10)['products'] ?? [], 'sku');

        $this->assertContains('60078', $skus);
        $this->assertContains('60084', $skus);
        $this->assertNotContains('60080', $skus);
        $this->assertNotContains('60081', $skus);
    }

    public function test_perspecta_2047w_does_not_return_9000_or_1070(): void
    {
        $this->seedPerspecta();
        $this->bindEmptyLlm();
        $q = 'Okulary ochronne MSA PERSPECTA 2047W';
        $skus = array_column($this->app->make(ProductAiSearchService::class)->search($q, 10)['products'] ?? [], 'sku');

        $this->assertContains('10064800', $skus);
        $this->assertNotContains('10045516', $skus);
        $this->assertNotContains('10064797', $skus);
    }

    private function bindEmptyLlm(): void
    {
        $llm = Mockery::mock(OpenAiCompatibleClient::class);
        $llm->shouldReceive('chatJson')->andReturn([
            'needed' => '',
            'search_phrases' => [],
            'matches' => [],
        ]);
        $this->app->instance(OpenAiCompatibleClient::class, $llm);
    }

    private function seedPhynomic(): void
    {
        $base = [
            'manufacturer' => 'SUNGBOO',
            'category' => 'Rękawice montażowe',
            'stock' => 5,
            'ppe_family' => PpeAssortment::FAMILY_GLOVES,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subYear(),
        ];
        Product::query()->create($base + [
            'sku' => '60078',
            'name' => 'uvex phynomic airLite B ESD 6,7,8,9,10,11,12 10 4',
            'description' => 'Rękawice uvex phynomic airLite B ESD.',
            'catalog_price_net' => 20,
            'purchase_price' => 10,
        ]);
        Product::query()->create($base + [
            'sku' => '60084',
            'name' => 'uvex phynomic airLite C ESD 6,7,8,9,10,11,12 10 4',
            'description' => 'Rękawice uvex phynomic airLite C ESD.',
            'catalog_price_net' => 22,
            'purchase_price' => 11,
        ]);
        Product::query()->create($base + [
            'sku' => '60080',
            'name' => 'uvex phynomic C3 6,7,8,9,10,11,12 10',
            'description' => 'Rękawice uvex phynomic C3 do prac mechanicznych.',
            'catalog_price_net' => 8,
            'purchase_price' => 4,
        ]);
        Product::query()->create($base + [
            'sku' => '60081',
            'name' => 'uvex phynomic C5 6,7,8,9,10,11,12 10',
            'description' => 'Rękawice uvex phynomic C5 do prac mechanicznych.',
            'catalog_price_net' => 9,
            'purchase_price' => 5,
        ]);
    }

    private function seedPerspecta(): void
    {
        $base = [
            'manufacturer' => 'MSA',
            'category' => 'Ochrona oczu / Okulary',
            'stock' => 5,
            'ppe_family' => PpeAssortment::FAMILY_EYES,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now()->subYear(),
        ];
        Product::query()->create($base + [
            'sku' => '10064800',
            'name' => 'Okulary PERSPECTA 2047W (12szt), bezbarwne',
            'description' => 'Perspecta 2047W.',
            'catalog_price_net' => 40,
            'purchase_price' => 20,
        ]);
        Product::query()->create($base + [
            'sku' => '10045516',
            'name' => 'Okulary PERSPECTA 9000 (12szt), bezbarwne',
            'description' => 'Perspecta 9000.',
            'catalog_price_net' => 30,
            'purchase_price' => 12,
        ]);
        Product::query()->create($base + [
            'sku' => '10064797',
            'name' => 'Okulary PERSPECTA 1070 (12szt), bezbarwne',
            'description' => 'Perspecta 1070.',
            'catalog_price_net' => 28,
            'purchase_price' => 11,
        ]);
    }
}
