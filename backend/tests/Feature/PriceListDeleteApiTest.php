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

final class PriceListDeleteApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_delete_price_list_removes_exclusive_products(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $exclusive = Product::query()->create([
            'sku' => 'ONLY-1',
            'name' => 'Tylko ten cennik',
            'manufacturer' => 'ATG',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
        ]);
        $shared = Product::query()->create([
            'sku' => 'SHARED-1',
            'name' => 'W dwóch cennikach',
            'manufacturer' => 'ATG',
            'catalog_price_net' => 12,
            'purchase_price' => 6,
            'stock' => 2,
        ]);

        $listA = PriceList::query()->create([
            'manufacturer' => 'ATG',
            'version' => 'v1',
            'original_filename' => 'a.xlsx',
            'rows_total' => 2,
            'products_created' => 2,
            'products_updated' => 0,
            'rows_skipped' => 0,
            'product_ids' => [$exclusive->id, $shared->id],
        ]);
        PriceList::query()->create([
            'manufacturer' => 'ATG',
            'version' => 'v2',
            'original_filename' => 'b.xlsx',
            'rows_total' => 1,
            'products_created' => 0,
            'products_updated' => 1,
            'rows_skipped' => 0,
            'product_ids' => [$shared->id],
        ]);

        $this->deleteJson("/api/price-lists/{$listA->id}")
            ->assertOk()
            ->assertJsonPath('products_deleted', 1)
            ->assertJsonPath('products_kept_shared', 1);

        $this->assertDatabaseMissing('price_lists', ['id' => $listA->id]);
        $this->assertDatabaseMissing('products', ['id' => $exclusive->id]);
        $this->assertDatabaseHas('products', ['id' => $shared->id]);
    }

    public function test_handlowiec_cannot_delete_price_list(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $list = PriceList::query()->create([
            'manufacturer' => 'X',
            'version' => '1',
            'rows_total' => 0,
            'products_created' => 0,
            'products_updated' => 0,
            'rows_skipped' => 0,
            'product_ids' => [],
        ]);

        $this->deleteJson("/api/price-lists/{$list->id}")->assertForbidden();
        $this->assertDatabaseHas('price_lists', ['id' => $list->id]);
    }

    public function test_index_includes_enrichment_counts(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $done = Product::query()->create([
            'sku' => 'DONE-1',
            'name' => 'Z opisem',
            'manufacturer' => 'ATG',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_DONE,
        ]);
        $pending = Product::query()->create([
            'sku' => 'PEND-1',
            'name' => 'Bez opisu',
            'manufacturer' => 'ATG',
            'catalog_price_net' => 11,
            'purchase_price' => 6,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ]);

        PriceList::query()->create([
            'manufacturer' => 'ATG',
            'version' => 'v-enrich',
            'original_filename' => 'e.xlsx',
            'rows_total' => 2,
            'products_created' => 2,
            'products_updated' => 0,
            'rows_skipped' => 0,
            'product_ids' => [$done->id, $pending->id],
        ]);

        $this->getJson('/api/price-lists')
            ->assertOk()
            ->assertJsonFragment([
                'version' => 'v-enrich',
                'enrichment_done' => 1,
                'enrichment_failed' => 0,
                'enrichment_total' => 2,
            ]);
    }
}
