<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ProductIndexApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_products_can_be_filtered_by_manufacturer(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Product::query()->create([
            'sku' => 'UVEX-1',
            'name' => 'Okulary A',
            'manufacturer' => 'Uvex',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
        ]);
        Product::query()->create([
            'sku' => 'PROS-1',
            'name' => 'Kurtka B',
            'manufacturer' => 'PROS',
            'catalog_price_net' => 20,
            'purchase_price' => 10,
            'stock' => 1,
        ]);

        $this->getJson('/api/products?manufacturer=PROS')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.sku', 'PROS-1')
            ->assertJsonPath('data.0.manufacturer', 'PROS');
    }

    public function test_manufacturers_endpoint_returns_distinct_sorted_list(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Product::query()->create([
            'sku' => 'A-1',
            'name' => 'A',
            'manufacturer' => 'Zebra',
            'catalog_price_net' => 1,
            'purchase_price' => 1,
            'stock' => 1,
        ]);
        Product::query()->create([
            'sku' => 'B-1',
            'name' => 'B',
            'manufacturer' => 'Alpha',
            'catalog_price_net' => 1,
            'purchase_price' => 1,
            'stock' => 1,
        ]);
        Product::query()->create([
            'sku' => 'C-1',
            'name' => 'C',
            'manufacturer' => 'Alpha',
            'catalog_price_net' => 1,
            'purchase_price' => 1,
            'stock' => 1,
        ]);

        $this->getJson('/api/products/manufacturers')
            ->assertOk()
            ->assertExactJson(['data' => ['Alpha', 'Zebra']]);
    }
}
