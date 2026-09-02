<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PrestaCategory;
use App\Models\PrestaCategoryMap;
use App\Models\Product;
use App\Models\User;
use App\Services\Presta\PrestaCategorySyncService;
use App\Services\Presta\PrestaExportGateway;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakePrestaExportGateway;
use Tests\TestCase;

final class PrestaCategoryMapApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->app->instance(PrestaExportGateway::class, new FakePrestaExportGateway);
    }

    public function test_admin_can_save_and_list_maps(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->makeProduct(['category' => 'Ręczniki']);
        PrestaCategory::query()->create([
            'presta_id' => 88,
            'parent_presta_id' => 10,
            'name' => 'Ręczniki bawełniane',
            'path' => 'Środki czystości / Ręczniki / Ręczniki bawełniane',
            'level_depth' => 3,
            'active' => true,
        ]);

        $this->putJson('/api/admin/presta-categories/maps', [
            'maps' => [
                ['local_category' => 'Ręczniki', 'presta_id' => 88],
            ],
        ])->assertOk()
            ->assertJsonPath('maps.0.local_category', 'Ręczniki')
            ->assertJsonPath('maps.0.presta_id', 88)
            ->assertJsonPath('maps.0.product_count', 1);

        $this->getJson('/api/admin/presta-categories')
            ->assertOk()
            ->assertJsonPath('categories.0.presta_id', 88);
    }

    public function test_export_uses_mapped_presta_category(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $presta = new FakePrestaExportGateway;
        $this->app->instance(PrestaExportGateway::class, $presta);
        PrestaCategoryMap::query()->create([
            'local_category' => 'Ręczniki',
            'presta_id' => 88,
        ]);
        $product = $this->makeProduct(['category' => 'Ręczniki']);

        $this->postJson('/api/products/'.$product->id.'/presta-export')
            ->assertOk()
            ->assertJsonPath('action', 'created');

        $this->assertSame(88, $presta->created[0]['id_category']);
    }

    public function test_sync_imports_tree_and_exact_name_maps(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->makeProduct(['category' => 'Ręczniki bawełniane']);
        $sync = app(PrestaCategorySyncService::class);
        $imported = $sync->storeCategories([
            [
                'presta_id' => 10,
                'parent_presta_id' => 2,
                'name' => 'Ręczniki',
                'level_depth' => 2,
                'active' => true,
            ],
            [
                'presta_id' => 88,
                'parent_presta_id' => 10,
                'name' => 'Ręczniki bawełniane',
                'level_depth' => 3,
                'active' => true,
            ],
            [
                'presta_id' => 87,
                'parent_presta_id' => 10,
                'name' => 'Ręczniki papierowe',
                'level_depth' => 3,
                'active' => true,
            ],
        ]);
        $this->assertSame(3, $imported);
        $this->assertSame(1, $sync->ensureLocalMaps());
        $this->assertDatabaseHas('presta_category_maps', [
            'local_category' => 'Ręczniki bawełniane',
            'presta_id' => 88,
        ]);
        $this->assertDatabaseHas('presta_categories', [
            'presta_id' => 88,
            'path' => 'Ręczniki / Ręczniki bawełniane',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeProduct(array $overrides = []): Product
    {
        return Product::query()->create(array_merge([
            'sku' => 'SKU-1',
            'name' => 'Ręcznik',
            'manufacturer' => 'X',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
            'enrichment_status' => Product::ENRICHMENT_NONE,
        ], $overrides));
    }
}
