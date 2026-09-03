<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PrestaCategory;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ProductIndexApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Cache::forget('nbp.table_a.rates');
        Http::fake([
            'api.nbp.pl/*' => Http::response([[
                'effectiveDate' => '2026-08-27',
                'rates' => [
                    ['code' => 'EUR', 'mid' => 4.0],
                ],
            ]]),
        ]);
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

    public function test_products_q_finds_short_code_inside_longer_phrase(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Product::query()->create([
            'sku' => 'P3E',
            'name' => '3M Adapter P3E do mocowania osłony twarzy',
            'manufacturer' => '3M',
            'catalog_price_net' => 9,
            'purchase_price' => 6,
            'stock' => 2,
        ]);
        Product::query()->create([
            'sku' => 'FH-934',
            'name' => 'Adapter kaptura ochronnego 3M',
            'manufacturer' => '3M',
            'catalog_price_net' => 31,
            'purchase_price' => 15,
            'stock' => 1,
        ]);

        $this->getJson('/api/products?q='.rawurlencode('Adapter P3E do hełmu 3M'))
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.sku', 'P3E');
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

    public function test_products_can_be_filtered_by_enrichment_status(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Product::query()->create([
            'sku' => 'OK-1',
            'name' => 'Gotowy',
            'manufacturer' => 'Uvex',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
        Product::query()->create([
            'sku' => 'NONE-1',
            'name' => 'Pusty',
            'manufacturer' => 'Uvex',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);

        $this->getJson('/api/products?enrichment_status=done')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.sku', 'OK-1');

        $this->getJson('/api/products?enrichment_status=none')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.sku', 'NONE-1');
    }

    public function test_products_index_accepts_per_page_sizes_and_all(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $this->getJson('/api/products?per_page=50')
            ->assertOk()
            ->assertJsonPath('per_page', 50);

        $this->getJson('/api/products?per_page=1000')
            ->assertOk()
            ->assertJsonPath('per_page', 1000);

        $this->getJson('/api/products?per_page=2000')
            ->assertOk()
            ->assertJsonPath('per_page', 1000);

        $this->getJson('/api/products?per_page=all')
            ->assertOk()
            ->assertJsonPath('per_page', 25000);
    }

    public function test_products_index_sorts_price_by_pln_using_nbp_rate(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        Product::query()->create([
            'sku' => 'EUR-10',
            'name' => 'Euro',
            'manufacturer' => 'A',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'currency' => 'EUR',
            'stock' => 1,
        ]);
        Product::query()->create([
            'sku' => 'PLN-30',
            'name' => 'Zloty',
            'manufacturer' => 'A',
            'catalog_price_net' => 30,
            'purchase_price' => 15,
            'currency' => 'PLN',
            'stock' => 1,
        ]);

        $this->getJson('/api/products?sort=catalog_price_net&dir=desc')
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'EUR-10')
            ->assertJsonPath('data.1.sku', 'PLN-30')
            ->assertJsonPath('data.0.price_pln', 40)
            ->assertJsonPath('data.0.purchase_price_pln', 20);

        $this->getJson('/api/products?sort=catalog_price_net&dir=asc')
            ->assertOk()
            ->assertJsonPath('data.0.sku', 'PLN-30')
            ->assertJsonPath('data.1.sku', 'EUR-10');
    }

    public function test_category_options_return_presta_paths(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        PrestaCategory::query()->create([
            'presta_id' => 88,
            'parent_presta_id' => 10,
            'name' => 'Ręczniki bawełniane',
            'path' => 'Środki czystości / Ręczniki / Ręczniki bawełniane',
            'level_depth' => 3,
            'active' => true,
        ]);
        PrestaCategory::query()->create([
            'presta_id' => 9,
            'parent_presta_id' => 2,
            'name' => 'Ukryta',
            'path' => 'Ukryta',
            'level_depth' => 2,
            'active' => false,
        ]);

        $this->getJson('/api/products/categories')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.value', 'Środki czystości / Ręczniki / Ręczniki bawełniane');
    }

    public function test_product_category_can_be_updated_and_cleared(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $product = Product::query()->create([
            'sku' => 'GRP-1',
            'name' => 'Ręcznik',
            'manufacturer' => 'X',
            'category' => 'Felpa',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
        ]);

        $this->patchJson('/api/products/'.$product->id.'/category', [
            'category' => 'Środki czystości / Ręczniki / Ręczniki bawełniane',
        ])
            ->assertOk()
            ->assertJsonPath('category', 'Środki czystości / Ręczniki / Ręczniki bawełniane');

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'category' => 'Środki czystości / Ręczniki / Ręczniki bawełniane',
        ]);

        $this->patchJson('/api/products/'.$product->id.'/category', [
            'category' => null,
        ])
            ->assertOk()
            ->assertJsonPath('category', null);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'category' => null,
        ]);
    }
}
