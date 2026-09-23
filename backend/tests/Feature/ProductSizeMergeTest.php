<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use App\Models\ProductImage;
use App\Models\ProductImageRejection;
use App\Models\ProductShopCard;
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

    public function test_merge_moves_identifiers_of_absorbed_cards_to_kept_card(): void
    {
        Queue::fake();
        $cards = [];
        foreach (['L' => 'HM5500BL', 'M' => 'HM5500BM', 'S' => 'HM5500BS'] as $size => $sku) {
            $cards[$size] = Product::query()->create([
                'sku' => $sku,
                'name' => 'HM5500 BAYONET HALF-MASK ELASTOMERIC '.$size,
                'manufacturer' => 'PIP',
                'description' => $size === 'M' ? str_repeat('Półmaska PIP HM5500 Bayonet. ', 3) : null,
                'catalog_price_net' => 348,
                'purchase_price' => 292.32,
                'currency' => 'EUR',
                'stock' => 1,
            ]);
            ProductIdentifier::query()->create([
                'product_id' => $cards[$size]->id,
                'source_key' => 'file:1',
                'position_key' => $sku,
                'type' => ProductIdentifier::TYPE_SOURCE_CODE,
                'value' => $sku,
                'normalized' => $sku,
            ]);
        }

        app(ProductSizeMergeService::class)->merge('PIP', false);

        $this->assertSame(1, Product::query()->count());
        $this->assertSame(3, ProductIdentifier::query()->count());
        $this->assertSame([$cards['M']->id], ProductIdentifier::query()->pluck('product_id')->unique()->values()->all());
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

    public function test_merges_size_letter_inside_sku_and_keeps_size_list(): void
    {
        Queue::fake();

        $small = Product::query()->create([
            'sku' => 'S56T0SS0',
            'name' => 'Półmaska SECURA 3000 (nagłowie jednoczęściowe)',
            'manufacturer' => 'SECURA',
            'catalog_price_net' => 80.25,
            'purchase_price' => 80.25,
            'stock' => 1,
        ]);
        $medium = Product::query()->create([
            'sku' => 'S56T0SM0',
            'name' => 'Półmaska SECURA 3000 (nagłowie jednoczęściowe)',
            'manufacturer' => 'SECURA',
            'description' => str_repeat('Półmaska SECURA 3000 z nagłowiem jednoczęściowym. ', 3),
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'catalog_price_net' => 80.25,
            'purchase_price' => 80.25,
            'stock' => 2,
        ]);
        $large = Product::query()->create([
            'sku' => 'S56T0SL0',
            'name' => 'Półmaska SECURA 3000 (nagłowie jednoczęściowe)',
            'manufacturer' => 'SECURA',
            'catalog_price_net' => 80.25,
            'purchase_price' => 80.25,
            'stock' => 4,
        ]);

        $result = app(ProductSizeMergeService::class)->merge('SECURA', false);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['groups']);
        $this->assertSame(2, $result['deleted']);
        $this->assertNull(Product::query()->find($small->id));
        $this->assertNull(Product::query()->find($large->id));
        $kept = Product::query()->find($medium->id);
        $this->assertNotNull($kept);
        $this->assertSame('S56T0SM0', $kept->sku);
        $this->assertSame(7, $kept->stock);
        $this->assertSame('S, M, L', $kept->packaging);
        $payload = is_array($kept->enrichment_payload) ? $kept->enrichment_payload : [];
        $this->assertSame(['S56T0SS0', 'S56T0SL0'], $payload['merged_size_skus']);
        $this->assertSame(
            ['S56T0SS0' => 'S', 'S56T0SM0' => 'M', 'S56T0SL0' => 'L'],
            $payload['merged_size_variants'],
        );
    }

    public function test_does_not_merge_dimension_variants_with_digit_codes(): void
    {
        Queue::fake();

        // Chodnik 20 KV w dwóch wymiarach — wymiaru nie ma ani w nazwie, ani w kodzie.
        Product::query()->create([
            'sku' => 'T5921002',
            'name' => 'Chodnik elektroizolacyjny 20 KV',
            'manufacturer' => 'Secura',
            'catalog_price_net' => 410.00,
            'purchase_price' => 410.00,
            'stock' => 1,
        ]);
        Product::query()->create([
            'sku' => 'T5921003',
            'name' => 'Chodnik elektroizolacyjny 20 KV',
            'manufacturer' => 'Secura',
            'catalog_price_net' => 410.00,
            'purchase_price' => 410.00,
            'stock' => 1,
        ]);

        $result = app(ProductSizeMergeService::class)->merge('Secura', false);

        $this->assertSame(0, $result['groups']);
        $this->assertSame(2, Product::query()->count());
    }

    public function test_does_not_merge_size_letter_inside_sku_when_names_differ(): void
    {
        Queue::fake();

        Product::query()->create([
            'sku' => 'S56T0SS0',
            'name' => 'Półmaska SECURA 3000 (nagłowie jednoczęściowe)',
            'manufacturer' => 'SECURA',
            'catalog_price_net' => 80.25,
            'purchase_price' => 80.25,
            'stock' => 1,
        ]);
        Product::query()->create([
            'sku' => 'S56T0SM0',
            'name' => 'Półmaska SECURA 4000 (nagłowie dwuczęściowe)',
            'manufacturer' => 'SECURA',
            'catalog_price_net' => 80.25,
            'purchase_price' => 80.25,
            'stock' => 1,
        ]);

        $result = app(ProductSizeMergeService::class)->merge('SECURA', false);

        $this->assertSame(0, $result['groups']);
        $this->assertSame(2, Product::query()->count());
    }

    public function test_does_not_merge_size_letter_inside_sku_when_price_differs(): void
    {
        Queue::fake();

        Product::query()->create([
            'sku' => 'S56T0SS0',
            'name' => 'Półmaska SECURA 3000 (nagłowie jednoczęściowe)',
            'manufacturer' => 'SECURA',
            'catalog_price_net' => 83.69,
            'purchase_price' => 83.69,
            'stock' => 1,
        ]);
        Product::query()->create([
            'sku' => 'S56T0SM0',
            'name' => 'Półmaska SECURA 3000 (nagłowie jednoczęściowe)',
            'manufacturer' => 'SECURA',
            'catalog_price_net' => 74.17,
            'purchase_price' => 74.17,
            'stock' => 1,
        ]);

        $result = app(ProductSizeMergeService::class)->merge('SECURA', false);

        $this->assertSame(0, $result['groups']);
        $this->assertSame(2, Product::query()->count());
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

    public function test_size_cards_recreated_after_merge_join_the_earlier_model_card(): void
    {
        Queue::fake();

        // Karta modelu z wcześniejszego łączenia: nazwa bez rozmiaru, SKU = rdzeń, kody w merged_size_skus.
        $model = Product::query()->create([
            'sku' => '60492',
            'name' => 'Rękawice C500 WET',
            'manufacturer' => 'UVEX',
            'description' => str_repeat('Rękawice antyprzecięciowe UVEX C500 wet. ', 3),
            'catalog_price_net' => 37.10,
            'purchase_price' => 37.10,
            'stock' => 2,
            'enrichment_payload' => ['merged_size_skus' => ['6049208', '6049209']],
        ]);
        // Synchronizacja B2B założyła skasowane rozmiary od nowa (przed 7b2911f łączenie gubiło ich powiązania).
        $eight = Product::query()->create([
            'sku' => '6049208', 'name' => 'Rękawice C500 WET/8', 'manufacturer' => 'UVEX',
            'catalog_price_net' => 37.10, 'purchase_price' => 37.10, 'stock' => 1,
        ]);
        $nine = Product::query()->create([
            'sku' => '6049209', 'name' => 'Rękawice C500 WET/9', 'manufacturer' => 'UVEX',
            'catalog_price_net' => 37.10, 'purchase_price' => 37.10, 'stock' => 1,
        ]);
        // Ta sama nazwa bez śladu łączenia — nie dołącza (dowodem jest wcześniejsza decyzja, nie sama nazwa).
        $sameName = Product::query()->create([
            'sku' => 'C500-WET-KPL', 'name' => 'Rękawice C500 WET', 'manufacturer' => 'UVEX',
            'catalog_price_net' => 37.10, 'purchase_price' => 37.10,
        ]);

        $result = app(ProductSizeMergeService::class)->merge('UVEX', false);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['groups']);
        $this->assertSame(2, $result['deleted']);
        $this->assertNull(Product::query()->find($eight->id));
        $this->assertNull(Product::query()->find($nine->id));
        $kept = $model->fresh();
        $this->assertNotNull($kept);
        $this->assertSame('60492', $kept->sku);
        $this->assertSame('Rękawice C500 WET', $kept->name);
        $this->assertSame(4, $kept->stock);
        $this->assertNotNull($sameName->fresh());
    }

    public function test_single_recreated_card_joins_model_card_in_other_price_bucket_and_keeps_its_sizes(): void
    {
        Queue::fake();

        // Karta modelu ma cenę z pliku, odtworzona karta tylko cenę B2B — dowodem jest kod na liście scalonych.
        $model = Product::query()->create([
            'sku' => '60278',
            'name' => 'Rękawice Unilite 7710F',
            'manufacturer' => 'UVEX',
            'packaging' => '7, 8, 9, 10, 11',
            'catalog_price_net' => 12.00,
            'purchase_price' => 12.00,
            'enrichment_payload' => ['merged_size_skus' => ['6027808']],
        ]);
        $recreated = Product::query()->create([
            'sku' => '6027808', 'name' => 'Rękawice Unilite 7710F/8', 'manufacturer' => 'UVEX',
            'catalog_price_net' => 13.65, 'purchase_price' => 13.65,
        ]);
        // ten sam kod na liście scalonych u innego producenta — bez klucza z producentem dowód byłby niejednoznaczny
        $foreign = Product::query()->create([
            'sku' => 'INNY-7710', 'name' => 'Rękawice innego producenta', 'manufacturer' => 'Inny',
            'catalog_price_net' => 13.65, 'purchase_price' => 13.65,
            'enrichment_payload' => ['merged_size_skus' => ['6027808']],
        ]);

        $result = app(ProductSizeMergeService::class)->merge(null, false);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['groups']);
        $this->assertNull(Product::query()->find($recreated->id));
        $this->assertNotNull($foreign->fresh());
        $kept = $model->fresh();
        $this->assertSame('60278', $kept->sku);
        $this->assertSame('Rękawice Unilite 7710F', $kept->name);
        $this->assertSame('7, 8, 9, 10, 11', $kept->packaging);
    }

    public function test_group_pointing_at_two_model_cards_is_skipped_and_reported(): void
    {
        Queue::fake();

        foreach (['6659/07 FOAM' => ['6659/09 FOAM'], '6659/8*' => ['6659/10 FOAM']] as $sku => $merged) {
            Product::query()->create([
                'sku' => $sku, 'name' => 'Rękawice antyprzecięciowe 6659 foam', 'manufacturer' => 'UVEX',
                'catalog_price_net' => 19.60, 'purchase_price' => 19.60,
                'enrichment_payload' => ['merged_size_skus' => $merged],
            ]);
        }
        foreach (['09' => 9, '10' => 10] as $code => $size) {
            Product::query()->create([
                'sku' => '6659/'.$code.' FOAM', 'name' => 'Rękawice antyprzecięciowe 6659 foam rozm. '.$size,
                'manufacturer' => 'UVEX', 'catalog_price_net' => 19.60, 'purchase_price' => 19.60,
            ]);
        }

        $result = app(ProductSizeMergeService::class)->merge('UVEX', false);

        $this->assertSame(0, $result['groups']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('kilku kart', $result['errors'][0]);
        $this->assertSame(4, Product::query()->count());
    }

    public function test_code_tail_group_does_not_rejoin_earlier_model_card(): void
    {
        Queue::fake();

        // Szyby 420x297 i 450x300 skleiło kiedyś odczytanie „06” z kodu jako rozmiaru — nie powtarzamy tego.
        $model = Product::query()->create([
            'sku' => '000P1P1026',
            'name' => 'Szyba chroniąca przed laserem 420x297x6 mm filtr P1P10',
            'manufacturer' => 'UVEX',
            'catalog_price_net' => 900,
            'purchase_price' => 900,
            'enrichment_payload' => ['merged_size_skus' => ['000P1P102606']],
        ]);
        $other = Product::query()->create([
            'sku' => '000P1P102606',
            'name' => 'Szyba chroniąca przed laserem 450x300x6 mm filtr P1P10',
            'manufacturer' => 'UVEX',
            'catalog_price_net' => 900,
            'purchase_price' => 900,
        ]);

        $result = app(ProductSizeMergeService::class)->merge('UVEX', false);

        $this->assertSame(0, $result['groups']);
        $this->assertNotNull($model->fresh());
        $this->assertNotNull($other->fresh());
    }

    public function test_does_not_merge_dotted_digit_codes_by_code_tail(): void
    {
        Queue::fake();

        // Po kropce stoi wersja albo model, nie rozmiar: kolory K JUNIOR, klasy spawalnicze i-5, modele TEGERA.
        $cards = [
            ['2600.011', 'Ochronniki słuchu uvex K JUNIOR limonka 2600.011', 'UVEX'],
            ['2600.013', 'Ochronniki słuchu uvex K JUNIOR różowy 2600.013', 'UVEX'],
            ['9183.043', 'Okulary i-5 spawalnicze 9183.043', 'UVEX'],
            ['9183.045', 'Okulary i-5 spawalnicze 9183.045', 'UVEX'],
            ['12.012', 'Rękawice TEGERA 12 kozia skóra licowa, mankiet', 'TEGERA'],
            ['12.013', 'Rękawice TEGERA 13 kozia skóra licowa, sciągacz zapięcie na rzep', 'TEGERA'],
        ];
        foreach ($cards as [$sku, $name, $manufacturer]) {
            Product::query()->create([
                'sku' => $sku,
                'name' => $name,
                'manufacturer' => $manufacturer,
                'catalog_price_net' => 62.30,
                'purchase_price' => 62.30,
            ]);
        }

        $result = app(ProductSizeMergeService::class)->merge(null, false);

        $this->assertSame(0, $result['groups']);
        $this->assertSame(6, Product::query()->count());
    }

    public function test_merge_moves_b2b_links_shop_cards_and_image_rejections_to_winner(): void
    {
        Queue::fake();

        // Łączenie po imporcie cennika bierze cały katalog producenta — także karty z synchronizacji B2B.
        $winner = Product::query()->create([
            'sku' => '37695VP100',
            'name' => 'AlphaTec 37695VP Size 10.0',
            'manufacturer' => 'Ansell',
            'description' => str_repeat('Rękawice chemiczne Ansell AlphaTec. ', 3),
            'catalog_price_net' => 2.85,
            'purchase_price' => 2.85,
        ]);
        $loser = Product::query()->create([
            'sku' => '37695VP070',
            'name' => 'AlphaTec 37695VP Size 7.0',
            'manufacturer' => 'Ansell',
            'catalog_price_net' => 2.85,
            'purchase_price' => 2.85,
        ]);
        $anro = B2bAccount::query()->create(['username' => 'anro', 'password' => 'x', 'sites' => ['b2b.anro.net.pl'], 'connector' => 'anro']);
        $uvex = B2bAccount::query()->create(['username' => 'uvex', 'password' => 'x', 'sites' => ['b2b.uvex.pl'], 'connector' => 'uvex']);
        B2bProductLink::query()->create(['b2b_account_id' => $anro->id, 'remote_id' => '100', 'product_id' => $winner->id]);
        B2bProductLink::query()->create(['b2b_account_id' => $anro->id, 'remote_id' => '70', 'product_id' => $loser->id]);
        B2bProductLink::query()->create(['b2b_account_id' => $uvex->id, 'remote_id' => 'u70', 'product_id' => $loser->id]);
        $shopCard = static fn (Product $p, B2bAccount $a, string $value, string $at): ProductShopCard => ProductShopCard::query()->create([
            'product_id' => $p->id,
            'b2b_account_id' => $a->id,
            'fields' => [['section' => '', 'rows' => [['name' => 'Materiał', 'value' => $value]]]],
            'synced_at' => $at,
        ]);
        $shopCard($winner, $anro, 'nitryl stary', '2026-09-01 10:00');
        $newerAnro = $shopCard($loser, $anro, 'nitryl nowy', '2026-09-10 10:00');
        $uvexCard = $shopCard($loser, $uvex, 'lateks', '2026-09-05 10:00');
        foreach ([$winner, $loser] as $p) {
            ProductImageRejection::query()->create([
                'product_id' => $p->id,
                'file_key_hash' => hash('sha256', 'https://b2b/zdjecie.jpg'),
                'file_key' => 'https://b2b/zdjecie.jpg',
                'reason' => ProductImageRejection::REASON_MANUAL,
            ]);
        }
        ProductImageRejection::query()->create([
            'product_id' => $loser->id,
            'file_key_hash' => hash('sha256', 'https://b2b/inne.jpg'),
            'file_key' => 'https://b2b/inne.jpg',
            'reason' => ProductImageRejection::REASON_AUDIT,
        ]);

        $result = app(ProductSizeMergeService::class)->merge('Ansell', false);

        $this->assertSame([], $result['errors']);
        $this->assertSame(1, $result['groups']);
        $this->assertNull(Product::query()->find($loser->id));
        $this->assertSame(
            ['100', '70', 'u70'],
            B2bProductLink::query()->where('product_id', $winner->id)->orderBy('remote_id')->pluck('remote_id')->all(),
        );
        $this->assertSame(3, B2bProductLink::query()->count());
        // ta sama para (karta, konto) jest UNIQUE — zostaje tabelka pobrana później
        $cards = ProductShopCard::query()->where('product_id', $winner->id)->orderBy('b2b_account_id')->pluck('id')->all();
        $this->assertSame([$newerAnro->id, $uvexCard->id], $cards);
        $this->assertSame(2, ProductShopCard::query()->count());
        $summary = (string) $winner->fresh()->shop_fields_summary;
        $this->assertStringContainsString('Materiał: nitryl nowy', $summary);
        $this->assertStringContainsString('Materiał: lateks', $summary);
        $this->assertStringNotContainsString('nitryl stary', $summary);
        $this->assertSame(2, ProductImageRejection::query()->where('product_id', $winner->id)->count());
        $this->assertSame(2, ProductImageRejection::query()->count());
    }
}
