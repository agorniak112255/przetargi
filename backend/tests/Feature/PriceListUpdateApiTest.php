<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PriceListUpdateApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_update_manufacturer_propagates_to_products(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $p1 = Product::query()->create([
            'sku' => 'PL-1',
            'name' => 'Produkt 1',
            'manufacturer' => 'Zly Producent',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
        ]);
        $p2 = Product::query()->create([
            'sku' => 'PL-2',
            'name' => 'Produkt 2',
            'manufacturer' => 'Zly Producent',
            'catalog_price_net' => 12,
            'purchase_price' => 6,
            'stock' => 1,
        ]);

        $list = PriceList::query()->create([
            'manufacturer' => 'Zly Producent',
            'version' => '2026',
            'original_filename' => 'c.xlsx',
            'rows_total' => 2,
            'products_created' => 2,
            'products_updated' => 0,
            'rows_skipped' => 0,
            'product_ids' => [$p1->id, $p2->id],
        ]);

        $this->patchJson("/api/price-lists/{$list->id}", [
            'manufacturer' => 'ATG',
            'version' => '2026-07',
        ])
            ->assertOk()
            ->assertJsonPath('products_updated', 2)
            ->assertJsonPath('price_list.manufacturer', 'ATG')
            ->assertJsonPath('price_list.version', '2026-07');

        $this->assertDatabaseHas('products', ['id' => $p1->id, 'manufacturer' => 'ATG']);
        $this->assertDatabaseHas('products', ['id' => $p2->id, 'manufacturer' => 'ATG']);
    }
}
