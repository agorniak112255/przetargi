<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PrestaProductMatch;
use App\Models\Product;
use App\Models\ProductAccessory;
use App\Models\User;
use App\Services\ProductAccessorySyncService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ProductAccessorySyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_imports_presta_links_by_sku_and_keeps_unmatched(): void
    {
        $parent = $this->product(['sku' => 'SEC-3000', 'name' => 'Półmaska Secura 3000', 'manufacturer' => 'SECURA']);
        $child = $this->product(['sku' => '3025', 'name' => 'Pochłaniacz Secura 3025', 'manufacturer' => 'SECURA']);

        $result = app(ProductAccessorySyncService::class)->importPrestaLinks([
            [
                'parent_id' => 297,
                'child_id' => 289,
                'parent_sku' => 'SEC-3000',
                'child_sku' => '3025',
                'parent_ean' => '',
                'child_ean' => '',
                'parent_name' => 'Półmaska',
                'child_name' => 'Pochłaniacz 3025',
                'parent_manufacturer' => 'SECURA',
                'child_manufacturer' => 'SECURA',
            ],
            [
                'parent_id' => 297,
                'child_id' => 9999,
                'parent_sku' => 'SEC-3000',
                'child_sku' => 'BRAK-W-KATALOGU',
                'parent_ean' => '',
                'child_ean' => '',
                'parent_name' => 'Półmaska',
                'child_name' => 'Filtr nieznany',
                'parent_manufacturer' => 'SECURA',
                'child_manufacturer' => 'SECURA',
            ],
        ]);

        $this->assertSame(1, $result['parents']);
        $this->assertSame(2, $result['links']);
        $this->assertSame(1, $result['matched']);
        $this->assertSame(1, $result['unmatched']);
        $this->assertDatabaseHas('product_accessories', [
            'product_id' => $parent->id,
            'related_product_id' => $child->id,
            'source' => ProductAccessory::SOURCE_PRESTA,
        ]);
        $this->assertDatabaseHas('product_accessories', [
            'product_id' => $parent->id,
            'related_sku' => 'BRAK-W-KATALOGU',
            'related_product_id' => null,
        ]);
    }

    public function test_matches_enrichment_candidates_and_skips_self(): void
    {
        $mask = $this->product(['sku' => 'SEC-3000', 'name' => 'Półmaska Secura 3000', 'manufacturer' => 'SECURA']);
        $this->product(['sku' => '3041', 'name' => 'Filtropochłaniacz Secura 3041', 'manufacturer' => 'SECURA']);

        $count = app(ProductAccessorySyncService::class)->matchFromPages($mask, [
            ['sku' => 'SEC-3000', 'name' => 'Półmaska Secura 3000', 'ean' => '', 'manufacturer' => 'SECURA'],
            ['sku' => '3041', 'name' => 'Filtropochłaniacz Secura 3041', 'ean' => '', 'manufacturer' => 'SECURA'],
        ]);

        $this->assertSame(1, $count);
        $this->assertSame(1, $mask->accessories()->count());
        $this->assertSame('3041', $mask->accessories()->first()?->related_sku);
    }

    public function test_skips_color_and_brand_chrome_from_pages(): void
    {
        $parent = $this->product(['sku' => '99-3052', 'name' => 'Nadruk na ubraniu, czerwony', 'manufacturer' => 'Weldas']);
        $this->product(['sku' => '212500180000', 'name' => 'SAFETY STEEL JOGGER S1', 'manufacturer' => 'Safety Jogger']);
        $this->product(['sku' => '93-833', 'name' => 'Jasnoniebieskie bezpudrowe ergonomiczne rękawice nitrylowe', 'manufacturer' => 'X']);

        $count = app(ProductAccessorySyncService::class)->matchFromPages($parent, [
            ['sku' => '', 'name' => 'SAFETY JOGGER', 'ean' => '', 'manufacturer' => ''],
            ['sku' => '', 'name' => 'JASNONIEBIESKI', 'ean' => '', 'manufacturer' => ''],
            ['sku' => 'BHP', 'name' => 'ART. BHP', 'ean' => '', 'manufacturer' => ''],
        ]);

        $this->assertSame(0, $count);
        $this->assertSame(0, $parent->accessories()->count());
    }

    public function test_product_show_returns_accessories(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $parent = $this->product(['sku' => 'P-1', 'name' => 'Maska', 'manufacturer' => 'SECURA']);
        $child = $this->product(['sku' => 'C-1', 'name' => 'Filtr', 'manufacturer' => 'SECURA']);
        ProductAccessory::query()->create([
            'product_id' => $parent->id,
            'related_product_id' => $child->id,
            'source' => ProductAccessory::SOURCE_PRESTA,
            'link_key' => 's:c1',
            'related_sku' => 'C-1',
            'related_name' => 'Filtr',
            'score' => 96,
            'method' => 'sku',
        ]);

        $this->getJson('/api/products/'.$parent->id)
            ->assertOk()
            ->assertJsonPath('accessories.0.sku', 'C-1')
            ->assertJsonPath('accessories.0.matched', true);
    }

    public function test_matches_parent_by_presta_id(): void
    {
        $parent = $this->product(['sku' => 'INNY', 'name' => 'Półmaska', 'manufacturer' => 'SECURA']);
        PrestaProductMatch::query()->create([
            'product_id' => $parent->id,
            'presta_id' => 297,
            'method' => 'export',
            'score' => 100,
            'status' => PrestaProductMatch::STATUS_EXPORTED,
        ]);

        $result = app(ProductAccessorySyncService::class)->importPrestaLinks([
            [
                'parent_id' => 297,
                'child_id' => 289,
                'parent_sku' => '',
                'child_sku' => 'XYZ-NIEMA',
                'parent_ean' => '',
                'child_ean' => '',
                'parent_name' => '',
                'child_name' => 'Pochłaniacz',
                'parent_manufacturer' => 'SECURA',
                'child_manufacturer' => 'SECURA',
            ],
        ]);

        $this->assertSame(1, $result['parents']);
        $this->assertSame(1, $parent->accessories()->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function product(array $overrides): Product
    {
        return Product::query()->create(array_merge([
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ], $overrides));
    }
}
