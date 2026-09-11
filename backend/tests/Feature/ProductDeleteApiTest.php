<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Models\User;
use App\Support\PermissionCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ProductDeleteApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_catalog_assigns_delete_only_to_admin(): void
    {
        $roles = PermissionCatalog::rolePermissions();

        $this->assertContains('products.delete', $roles['admin']);
        $this->assertNotContains('products.delete', $roles['handlowiec']);
        $this->assertNotContains('products.delete', $roles['kierownik']);
        $this->assertNotContains('products.delete', $roles['dyrektor']);
    }

    public function test_admin_can_delete_product_and_detach_from_price_list(): void
    {
        Storage::fake('public');
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $product = Product::query()->create([
            'sku' => 'DEL-1',
            'name' => 'Do usunięcia',
            'manufacturer' => 'SECURA',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
        ]);
        Storage::disk('public')->put('products/del-1.jpg', 'img');
        ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'products/del-1.jpg',
            'is_primary' => true,
            'sort_order' => 0,
        ]);
        $list = PriceList::query()->create([
            'manufacturer' => 'SECURA',
            'version' => 'v1',
            'original_filename' => 'a.xlsx',
            'rows_total' => 1,
            'products_created' => 1,
            'products_updated' => 0,
            'rows_skipped' => 0,
            'product_ids' => [$product->id],
        ]);

        $this->deleteJson("/api/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('deleted', 1)
            ->assertJsonPath('product_ids_deleted.0', $product->id);

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseMissing('product_images', ['product_id' => $product->id]);
        $this->assertSame([], $list->fresh()?->product_ids);
        Storage::disk('public')->assertMissing('products/del-1.jpg');
    }

    public function test_delete_unlinks_tender_item_product(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $product = Product::query()->create([
            'sku' => 'DEL-T',
            'name' => 'W przetargu',
            'manufacturer' => 'SECURA',
            'catalog_price_net' => 12,
            'purchase_price' => 6,
            'stock' => 1,
        ]);
        $owner = User::factory()->create();
        $client = Client::query()->create(['name' => 'Klient DEL']);
        $tender = Tender::query()->create([
            'number' => 'PRZ/DEL/P',
            'title' => 'Usuwanie produktu',
            'client_id' => $client->id,
            'owner_id' => $owner->id,
            'status' => 'wycena',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);
        $item = TenderItem::query()->create([
            'tender_id' => $tender->id,
            'line_no' => 1,
            'requirement' => 'Półmaska',
            'quantity' => 1,
            'status' => 'ok',
            'main_product_id' => $product->id,
        ]);

        $this->deleteJson("/api/products/{$product->id}")->assertOk();

        $this->assertDatabaseMissing('products', ['id' => $product->id]);
        $this->assertDatabaseHas('tender_items', [
            'id' => $item->id,
            'main_product_id' => null,
        ]);
    }

    public function test_admin_can_delete_many_products(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());

        $a = Product::query()->create([
            'sku' => 'DEL-A',
            'name' => 'A',
            'manufacturer' => 'X',
            'catalog_price_net' => 1,
            'purchase_price' => 1,
            'stock' => 1,
        ]);
        $b = Product::query()->create([
            'sku' => 'DEL-B',
            'name' => 'B',
            'manufacturer' => 'X',
            'catalog_price_net' => 2,
            'purchase_price' => 2,
            'stock' => 1,
        ]);

        $this->postJson('/api/products/delete', [
            'product_ids' => [$a->id, $b->id],
        ])
            ->assertOk()
            ->assertJsonPath('deleted', 2);

        $this->assertDatabaseMissing('products', ['id' => $a->id]);
        $this->assertDatabaseMissing('products', ['id' => $b->id]);
    }

    public function test_handlowiec_cannot_delete_product(): void
    {
        Sanctum::actingAs(User::factory()->withRole('handlowiec')->create());

        $product = Product::query()->create([
            'sku' => 'KEEP-1',
            'name' => 'Zostaje',
            'manufacturer' => 'X',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
        ]);

        $this->deleteJson("/api/products/{$product->id}")->assertForbidden();
        $this->postJson('/api/products/delete', ['product_ids' => [$product->id]])->assertForbidden();
        $this->assertDatabaseHas('products', ['id' => $product->id]);
    }
}
