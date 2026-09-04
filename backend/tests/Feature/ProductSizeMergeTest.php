<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\PriceListImportService;
use App\Services\ProductSizeMergeService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    public function test_merges_trailing_numeric_size_in_name(): void
    {
        Queue::fake();

        Product::query()->create([
            'sku' => '34703090',
            'name' => '1st Winter Dry 9',
            'manufacturer' => 'Showa',
            'catalog_price_net' => 9.75,
            'purchase_price' => 8.78,
            'stock' => 1,
        ]);
        $ten = Product::query()->create([
            'sku' => '34703100',
            'name' => '1st Winter Dry 10',
            'manufacturer' => 'Showa',
            'description' => str_repeat('Rękawice Showa 1st Winter Dry. ', 3),
            'catalog_price_net' => 9.75,
            'purchase_price' => 8.78,
            'stock' => 1,
        ]);
        Product::query()->create([
            'sku' => '34703110',
            'name' => '1st Winter Dry 11',
            'manufacturer' => 'Showa',
            'catalog_price_net' => 9.75,
            'purchase_price' => 8.78,
            'stock' => 1,
        ]);
        $other = Product::query()->create([
            'sku' => '34704090',
            'name' => '1st Winter 9',
            'manufacturer' => 'Showa',
            'catalog_price_net' => 9.65,
            'purchase_price' => 8.69,
            'stock' => 1,
        ]);

        $result = app(ProductSizeMergeService::class)->merge('Showa', false);

        $this->assertSame(1, $result['groups']);
        $this->assertSame(2, $result['deleted']);
        $kept = Product::query()->find($ten->id);
        $this->assertNotNull($kept);
        $this->assertSame('1st Winter Dry', $kept->name);
        $this->assertSame('34703', $kept->sku);
        $this->assertSame(3, $kept->stock);
        $this->assertNotNull(Product::query()->find($other->id));
        $this->assertSame('34704090', $other->fresh()?->sku);
    }

    public function test_merges_letter_size_sku_suffix_same_price(): void
    {
        Queue::fake();

        Product::query()->create([
            'sku' => 'HM5500BS',
            'name' => 'HM5500 BAYONET HALF-MASK ELASTOMERIC L',
            'manufacturer' => 'PIP',
            'catalog_price_net' => 348,
            'purchase_price' => 292.32,
            'currency' => 'EUR',
            'stock' => 1,
        ]);
        $mid = Product::query()->create([
            'sku' => 'HM5500BM',
            'name' => 'HM5500 BAYONET HALF-MASK ELASTOMERIC M',
            'manufacturer' => 'PIP',
            'description' => str_repeat('Półmaska PIP HM5500 Bayonet. ', 3),
            'catalog_price_net' => 348,
            'purchase_price' => 292.32,
            'currency' => 'EUR',
            'stock' => 1,
        ]);
        Product::query()->create([
            'sku' => 'HM5500BL',
            'name' => 'HM5500 BAYONET HALF-MASK ELASTOMERIC S',
            'manufacturer' => 'PIP',
            'catalog_price_net' => 348,
            'purchase_price' => 292.32,
            'currency' => 'EUR',
            'stock' => 1,
        ]);

        $result = app(ProductSizeMergeService::class)->merge('PIP', false);

        $this->assertSame(1, $result['groups']);
        $this->assertSame(2, $result['deleted']);
        $kept = Product::query()->find($mid->id);
        $this->assertNotNull($kept);
        $this->assertSame('HM5500 BAYONET HALF-MASK ELASTOMERIC', $kept->name);
        $this->assertSame('HM5500B', $kept->sku);
        $this->assertSame(3, $kept->stock);
    }

    public function test_merges_rostaing_base_sku_with_sized_and_letter_stems(): void
    {
        Queue::fake();

        $base = Product::query()->create([
            'sku' => 'CANADA-IT',
            'name' => 'GLOVES CANADA NITRILE',
            'manufacturer' => 'Rostaing',
            'catalog_price_net' => 12.4,
            'purchase_price' => 12.4,
            'stock' => 2,
        ]);
        Product::query()->create([
            'sku' => 'CANADA-IT08',
            'name' => 'GLOVES CANADA NITRILE T8',
            'manufacturer' => 'Rostaing',
            'catalog_price_net' => 12.4,
            'purchase_price' => 12.4,
            'stock' => 1,
        ]);
        Product::query()->create([
            'sku' => 'CANADA-IT11',
            'name' => 'GLOVES CANADA NITRILE T11',
            'manufacturer' => 'Rostaing',
            'catalog_price_net' => 12.4,
            'purchase_price' => 12.4,
            'stock' => 1,
        ]);
        Product::query()->create([
            'sku' => 'MASTERTSHIRT-B03TS',
            'name' => 'T-SHIRT MASTER BLUE TS',
            'manufacturer' => 'Rostaing',
            'catalog_price_net' => 34.62,
            'purchase_price' => 34.62,
            'stock' => 1,
        ]);
        $blue = Product::query()->create([
            'sku' => 'MASTERTSHIRT-B03TXXXL',
            'name' => 'T-SHIRT MASTER BLUE TXXXL',
            'manufacturer' => 'Rostaing',
            'description' => str_repeat('Koszulka Rostaing Master Shirt. ', 3),
            'catalog_price_net' => 34.62,
            'purchase_price' => 34.62,
            'stock' => 1,
        ]);
        Product::query()->create([
            'sku' => 'MASTERTSHIRT-BTS',
            'name' => 'T-SHIRT MASTER ORANGE TS',
            'manufacturer' => 'Rostaing',
            'catalog_price_net' => 34.62,
            'purchase_price' => 34.62,
            'stock' => 1,
        ]);
        $orange = Product::query()->create([
            'sku' => 'MASTERTSHIRT-BTXL',
            'name' => 'T-SHIRT MASTER ORANGE TXL',
            'manufacturer' => 'Rostaing',
            'description' => str_repeat('Koszulka Rostaing Master Shirt orange. ', 3),
            'catalog_price_net' => 34.62,
            'purchase_price' => 34.62,
            'stock' => 1,
        ]);

        $result = app(ProductSizeMergeService::class)->merge('Rostaing', false);

        $this->assertSame(3, $result['groups']);
        $this->assertSame(4, $result['deleted']);
        $keptCanada = Product::query()->find($base->id);
        $this->assertNotNull($keptCanada);
        $this->assertSame('CANADA-IT', $keptCanada->sku);
        $this->assertSame(4, $keptCanada->stock);
        $keptBlue = Product::query()->find($blue->id);
        $this->assertNotNull($keptBlue);
        $this->assertSame('MASTERTSHIRT-B03', $keptBlue->sku);
        $this->assertSame(2, $keptBlue->stock);
        $keptOrange = Product::query()->find($orange->id);
        $this->assertNotNull($keptOrange);
        $this->assertSame('MASTERTSHIRT-B', $keptOrange->sku);
        $this->assertSame(2, $keptOrange->stock);
        $this->assertNull(Product::query()->where('sku', 'MASTERTSHIRT-B03TS')->first());
        $this->assertNull(Product::query()->where('sku', 'MASTERTSHIRT-BTS')->first());
        $this->assertSame(3, Product::query()->where('manufacturer', 'Rostaing')->count());
    }

    public function test_merges_glued_criot_and_slash_prosoud_leftovers(): void
    {
        Queue::fake();

        Product::query()->create([
            'sku' => 'CRIOT08',
            'name' => 'CRYOGENIC GLOVES T8 -196°C LEATHER  40CM',
            'manufacturer' => 'Rostaing',
            'catalog_price_net' => 82.99,
            'purchase_price' => 37.51,
            'stock' => 1,
        ]);
        Product::query()->create([
            'sku' => 'CRIOT09',
            'name' => 'CRYOGENIC GLOVES T9 -196°C LEATHER  40 CM',
            'manufacturer' => 'Rostaing',
            'catalog_price_net' => 82.99,
            'purchase_price' => 37.51,
            'stock' => 1,
        ]);
        $criot = Product::query()->create([
            'sku' => 'CRIOT',
            'name' => 'CRYOGENIC GLOVES T10 -196°C LEATHER RIGHT HAND 40 CM',
            'manufacturer' => 'Rostaing',
            'description' => str_repeat('Rękawice kriogeniczne Rostaing. ', 3),
            'catalog_price_net' => 82.99,
            'purchase_price' => 37.51,
            'stock' => 1,
        ]);
        Product::query()->create([
            'sku' => 'PROSOUD/1DRT08',
            'name' => '1 RIGHT HAND GLOVE T8 WELDER 100°C CUT OFF',
            'manufacturer' => 'Rostaing',
            'catalog_price_net' => 16.38,
            'purchase_price' => 7.41,
            'stock' => 1,
        ]);
        Product::query()->create([
            'sku' => 'PROSOUD/1DRT10',
            'name' => '1 RIGHT HAND GLOVE T10 WELDER 100°C CUT PROTECTION',
            'manufacturer' => 'Rostaing',
            'catalog_price_net' => 16.38,
            'purchase_price' => 7.41,
            'stock' => 1,
        ]);
        $prosoud = Product::query()->create([
            'sku' => 'PROSOUD/1DRT',
            'name' => '1 RIGHT HAND GLOVE WELDER 100°C CUT RESISTANCE',
            'manufacturer' => 'Rostaing',
            'description' => str_repeat('Rękawica spawalnicza Rostaing. ', 3),
            'catalog_price_net' => 16.38,
            'purchase_price' => 7.41,
            'stock' => 1,
        ]);

        $result = app(ProductSizeMergeService::class)->merge('Rostaing', false);

        $this->assertSame(2, $result['groups']);
        $this->assertSame(4, $result['deleted']);
        $this->assertNotNull(Product::query()->find($criot->id));
        $this->assertSame('CRIOT', Product::query()->find($criot->id)?->sku);
        $this->assertSame(3, Product::query()->find($criot->id)?->stock);
        $this->assertNotNull(Product::query()->find($prosoud->id));
        $this->assertSame('PROSOUD/1DRT', Product::query()->find($prosoud->id)?->sku);
        $this->assertSame(3, Product::query()->find($prosoud->id)?->stock);
        $this->assertNull(Product::query()->where('sku', 'CRIOT08')->first());
        $this->assertNull(Product::query()->where('sku', 'PROSOUD/1DRT08')->first());
        $this->assertSame(2, Product::query()->where('manufacturer', 'Rostaing')->count());
    }

    public function test_renames_winner_to_base_sku_after_deleting_loser(): void
    {
        Queue::fake();

        $base = Product::query()->create([
            'sku' => 'CRIOT',
            'name' => 'CRYOGENIC GLOVES T10 -196°C LEATHER RIGHT HAND 40 CM',
            'manufacturer' => 'Rostaing',
            'catalog_price_net' => 82.99,
            'purchase_price' => 37.51,
            'stock' => 1,
        ]);
        $sized = Product::query()->create([
            'sku' => 'CRIOT08',
            'name' => 'CRYOGENIC GLOVES T8 -196°C LEATHER  40CM',
            'manufacturer' => 'Rostaing',
            'description' => str_repeat('Rękawice kriogeniczne Rostaing. ', 3),
            'catalog_price_net' => 82.99,
            'purchase_price' => 37.51,
            'stock' => 1,
        ]);
        ProductImage::query()->create([
            'product_id' => $sized->id,
            'path' => 'products/criot.jpg',
            'is_primary' => true,
            'sort_order' => 0,
            'checksum' => 'criot123',
        ]);

        $result = app(ProductSizeMergeService::class)->merge('Rostaing', false);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['groups']);
        $this->assertSame(1, $result['deleted']);
        $this->assertNull(Product::query()->find($base->id));
        $kept = Product::query()->find($sized->id);
        $this->assertNotNull($kept);
        $this->assertSame('CRIOT', $kept->sku);
    }

    public function test_import_merges_leftover_size_skus(): void
    {
        Queue::fake();

        Product::query()->create([
            'sku' => 'CRIOT08',
            'name' => 'CRYOGENIC GLOVES T8 -196°C LEATHER  40CM',
            'manufacturer' => 'Rostaing',
            'catalog_price_net' => 82.99,
            'purchase_price' => 37.51,
            'stock' => 1,
        ]);
        Product::query()->create([
            'sku' => 'CRIOT09',
            'name' => 'CRYOGENIC GLOVES T9 -196°C LEATHER  40 CM',
            'manufacturer' => 'Rostaing',
            'catalog_price_net' => 82.99,
            'purchase_price' => 37.51,
            'stock' => 1,
        ]);

        $path = tempnam(sys_get_temp_dir(), 'criotimp').'.pdf';
        file_put_contents($path, "%PDF-1.4\n");
        $file = new UploadedFile($path, 'rostaing.pdf', 'application/pdf', null, true);

        try {
            app(PriceListImportService::class)->importFromProducts(
                $file,
                'Rostaing',
                '2026',
                User::factory()->create(),
                [
                    [
                        'sku' => 'CRIOT08',
                        'name' => 'CRYOGENIC GLOVES T8 -196°C LEATHER  40CM',
                        'catalog_price_net' => 82.99,
                        'purchase_price' => 37.51,
                    ],
                    [
                        'sku' => 'CRIOT09',
                        'name' => 'CRYOGENIC GLOVES T9 -196°C LEATHER  40 CM',
                        'catalog_price_net' => 82.99,
                        'purchase_price' => 37.51,
                    ],
                ],
            );

            $this->assertSame(1, Product::query()->where('manufacturer', 'Rostaing')->count());
            $kept = Product::query()->where('sku', 'CRIOT')->first();
            $this->assertNotNull($kept);
            $this->assertNull(Product::query()->where('sku', 'CRIOT08')->first());
        } finally {
            @unlink($path);
        }
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
