<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\ProductSizeMergeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ProductSizeMergeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_keeps_product_with_description_and_photo(): void
    {
        Queue::fake();

        $bare = Product::query()->create([
            'sku' => '37695VP070',
            'name' => 'AlphaTec 37695VP Size 7.0',
            'manufacturer' => 'Ansell',
            'catalog_price_net' => 2.85,
            'purchase_price' => 2.85,
            'stock' => 3,
        ]);
        $rich = Product::query()->create([
            'sku' => '37695VP100',
            'name' => 'AlphaTec 37695VP Size 10.0',
            'manufacturer' => 'Ansell',
            'description' => str_repeat('Rękawice chemiczne Ansell AlphaTec. ', 3),
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'catalog_price_net' => 2.85,
            'purchase_price' => 2.85,
            'stock' => 2,
        ]);
        ProductImage::query()->create([
            'product_id' => $rich->id,
            'path' => 'products/ansell.jpg',
            'is_primary' => true,
            'sort_order' => 0,
            'checksum' => 'abc123',
        ]);
        $other = Product::query()->create([
            'sku' => '37695VP110',
            'name' => 'AlphaTec 37695VP Size 11.0',
            'manufacturer' => 'Ansell',
            'catalog_price_net' => 2.85,
            'purchase_price' => 2.85,
            'stock' => 1,
        ]);

        $list = PriceList::query()->create([
            'manufacturer' => 'Ansell',
            'version' => '1',
            'original_filename' => 'a.xlsx',
            'rows_total' => 3,
            'products_created' => 3,
            'products_updated' => 0,
            'rows_skipped' => 0,
            'product_ids' => [$bare->id, $rich->id, $other->id],
        ]);

        $result = app(ProductSizeMergeService::class)->merge('Ansell', false);

        $this->assertSame(1, $result['groups']);
        $this->assertSame(2, $result['deleted']);
        $this->assertNull(Product::query()->find($bare->id));
        $this->assertNull(Product::query()->find($other->id));
        $kept = Product::query()->find($rich->id);
        $this->assertNotNull($kept);
        $this->assertSame('AlphaTec 37695VP', $kept->name);
        $this->assertSame('37695VP', $kept->sku);
        $this->assertSame(6, $kept->stock);
        $this->assertSame([$rich->id], $list->fresh()?->product_ids);
    }

    public function test_does_not_merge_when_price_differs(): void
    {
        Product::query()->create([
            'sku' => '37695VP070',
            'name' => 'AlphaTec 37695VP Size 7.0',
            'manufacturer' => 'Ansell',
            'catalog_price_net' => 2.85,
            'purchase_price' => 2.85,
            'stock' => 1,
        ]);
        Product::query()->create([
            'sku' => '37695VP100',
            'name' => 'AlphaTec 37695VP Size 10.0',
            'manufacturer' => 'Ansell',
            'catalog_price_net' => 4.10,
            'purchase_price' => 4.10,
            'stock' => 1,
        ]);

        $result = app(ProductSizeMergeService::class)->merge('Ansell', false);

        $this->assertSame(0, $result['groups']);
        $this->assertSame(2, Product::query()->count());
    }

    public function test_merge_sizes_endpoint(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        Queue::fake();

        Product::query()->create([
            'sku' => '37695VP070',
            'name' => 'AlphaTec 37695VP Size 7.0',
            'manufacturer' => 'Ansell',
            'catalog_price_net' => 2.85,
            'purchase_price' => 2.85,
        ]);
        Product::query()->create([
            'sku' => '37695VP100',
            'name' => 'AlphaTec 37695VP Size 10.0',
            'manufacturer' => 'Ansell',
            'catalog_price_net' => 2.85,
            'purchase_price' => 2.85,
        ]);

        $this->postJson('/api/products/catalog-health/merge-sizes', [
            'manufacturer' => 'Ansell',
        ])
            ->assertOk()
            ->assertJsonPath('groups', 1)
            ->assertJsonPath('deleted', 1);
    }

    public function test_merge_skips_duplicate_document_checksums(): void
    {
        Queue::fake();

        $a = Product::query()->create([
            'sku' => '37695VP070',
            'name' => 'AlphaTec 37695VP Size 7.0',
            'manufacturer' => 'Ansell',
            'description' => str_repeat('Rękawice chemiczne Ansell AlphaTec. ', 3),
            'catalog_price_net' => 2.85,
            'purchase_price' => 2.85,
        ]);
        $b = Product::query()->create([
            'sku' => '37695VP100',
            'name' => 'AlphaTec 37695VP Size 10.0',
            'manufacturer' => 'Ansell',
            'catalog_price_net' => 2.85,
            'purchase_price' => 2.85,
        ]);
        foreach ([$a, $b] as $p) {
            ProductDocument::query()->create([
                'product_id' => $p->id,
                'path' => 'docs/karta-'.$p->id.'.pdf',
                'kind' => 'datasheet',
                'checksum' => '535cdb725db8d9f8bc82933bee426281ac6b673f',
            ]);
        }

        $result = app(ProductSizeMergeService::class)->merge('Ansell', false);

        $this->assertSame(1, $result['groups']);
        $this->assertSame([], $result['errors']);
        $this->assertSame(1, Product::query()->count());
        $this->assertSame(1, ProductDocument::query()->count());
    }
}
