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
            ->assertJsonPath('applied', 1)
            ->assertJsonPath('maps.0.local_category', 'Środki czystości / Ręczniki / Ręczniki bawełniane')
            ->assertJsonPath('maps.0.presta_id', 88)
            ->assertJsonPath('maps.0.product_count', 1);

        $this->assertDatabaseHas('products', [
            'sku' => 'SKU-1',
            'category' => 'Środki czystości / Ręczniki / Ręczniki bawełniane',
        ]);

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

    public function test_auto_map_fills_unique_name_not_ambiguous_parent(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->makeProduct(['sku' => 'A', 'category' => 'Ręczniki bawełniane']);
        $this->makeProduct(['sku' => 'B', 'category' => 'Chemia XYZ']);
        app(PrestaCategorySyncService::class)->storeCategories([
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

        $this->postJson('/api/admin/presta-categories/auto-map')
            ->assertOk()
            ->assertJsonPath('filled', 1);

        $this->assertDatabaseHas('presta_category_maps', [
            'local_category' => 'Ręczniki bawełniane',
            'presta_id' => 88,
        ]);
        $this->assertDatabaseMissing('presta_category_maps', [
            'local_category' => 'Chemia XYZ',
            'presta_id' => 88,
        ]);
        $this->assertDatabaseHas('presta_category_maps', [
            'local_category' => 'Chemia XYZ',
            'presta_id' => null,
        ]);
    }

    public function test_rewrite_moves_garbage_to_presta_path(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $this->makeProduct([
            'sku' => 'GOMEZ-1',
            'name' => 'Ręcznik GOMEZ 500g/m2 Biały',
            'category' => "='Trad. gammes'!C10",
        ]);
        $this->makeProduct([
            'sku' => 'JUNK-1',
            'name' => 'Bezrodziny XYZ',
            'category' => 'A',
        ]);
        app(PrestaCategorySyncService::class)->storeCategories([
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
        ]);

        $this->postJson('/api/admin/presta-categories/rewrite')
            ->assertOk()
            ->assertJsonPath('updated', 1)
            ->assertJsonPath('cleared', 1);

        $this->assertDatabaseHas('products', [
            'sku' => 'GOMEZ-1',
            'category' => 'Ręczniki / Ręczniki bawełniane',
        ]);
        $this->assertDatabaseHas('products', [
            'sku' => 'JUNK-1',
            'category' => null,
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
