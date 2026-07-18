<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AssortmentGroup;
use App\Models\Product;
use App\Models\User;
use App\Services\AssortmentGroupService;
use App\Services\PriceListImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class AssortmentGroupImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_summarize_detects_groups_and_ungrouped(): void
    {
        AssortmentGroup::query()->create([
            'manufacturer' => 'Lebon',
            'name' => 'Rękawice',
            'discount_percent' => 12,
            'is_global' => false,
        ]);

        $summary = app(AssortmentGroupService::class)->summarize([
            ['sku' => 'A', 'category' => 'Rękawice'],
            ['sku' => 'B', 'category' => 'Rękawice'],
            ['sku' => 'C', 'category' => 'Oczy'],
            ['sku' => 'D', 'category' => null],
        ], 'Lebon');

        $this->assertTrue($summary['has_grouping']);
        $this->assertSame(1, $summary['ungrouped_count']);
        $this->assertCount(2, $summary['detected']);
        $rekawice = collect($summary['detected'])->firstWhere('name', 'Rękawice');
        $this->assertNotNull($rekawice);
        $this->assertSame(2, $rekawice['product_count']);
        $this->assertSame(12.0, $rekawice['discount_percent']);
    }

    public function test_apply_group_discounts_and_persist_groups(): void
    {
        $service = app(AssortmentGroupService::class);
        $products = $service->applyToProducts(
            [
                [
                    'sku' => 'SKU-1',
                    'name' => 'Produkt 1',
                    'category' => 'Rękawice',
                    'catalog_price_net' => 100,
                    'discount_percent' => 0,
                    'purchase_price' => 100,
                ],
                [
                    'sku' => 'SKU-2',
                    'name' => 'Produkt 2',
                    'category' => null,
                    'catalog_price_net' => 200,
                    'discount_percent' => 0,
                    'purchase_price' => 200,
                ],
            ],
            'Ansell',
            [
                'groups' => [
                    ['name' => 'Rękawice', 'discount_percent' => 20],
                    ['name' => 'Inne', 'discount_percent' => 10],
                ],
                'ungrouped_group' => 'Inne',
            ],
        );

        $this->assertCount(2, $products);
        $this->assertSame(20.0, $products[0]['discount_percent']);
        $this->assertSame(80.0, $products[0]['purchase_price']);
        $this->assertSame('Inne', $products[1]['category']);
        $this->assertSame(10.0, $products[1]['discount_percent']);
        $this->assertSame(180.0, $products[1]['purchase_price']);

        $this->assertDatabaseHas('assortment_groups', [
            'manufacturer' => 'Ansell',
            'name' => 'Rękawice',
            'discount_percent' => 20,
        ]);
        $this->assertDatabaseHas('assortment_groups', [
            'manufacturer' => 'Ansell',
            'name' => 'Inne',
            'discount_percent' => 10,
        ]);
    }

    public function test_missing_group_assignment_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        app(AssortmentGroupService::class)->applyToProducts(
            [
                [
                    'sku' => 'X',
                    'name' => 'Bez grupy',
                    'category' => null,
                    'catalog_price_net' => 50,
                ],
            ],
            'PROS',
            [
                'groups' => [
                    ['name' => 'Odzież', 'discount_percent' => 5],
                ],
            ],
        );
    }

    public function test_global_discount_without_groups(): void
    {
        $products = app(AssortmentGroupService::class)->applyToProducts(
            [
                [
                    'sku' => 'G1',
                    'name' => 'A',
                    'catalog_price_net' => 100,
                    'discount_percent' => 0,
                    'purchase_price' => 100,
                ],
            ],
            'uvex',
            ['groups' => [], 'default_discount' => 15],
        );

        $this->assertSame(15.0, $products[0]['discount_percent']);
        $this->assertSame(85.0, $products[0]['purchase_price']);
        $this->assertDatabaseHas('assortment_groups', [
            'manufacturer' => 'uvex',
            'name' => AssortmentGroup::GLOBAL_NAME,
            'is_global' => 1,
            'discount_percent' => 15,
        ]);
    }

    public function test_import_with_group_options_assigns_products(): void
    {
        $path = $this->makeDemoSpreadsheet();
        $user = User::factory()->create();
        $file = new UploadedFile($path, 'cennik-grupy.xlsx', null, null, true);

        $result = app(PriceListImportService::class)->import(
            $file,
            'TestBrand',
            '2026-07',
            $user,
            null,
            [
                'groups' => [
                    ['name' => 'Rękawice', 'discount_percent' => 25],
                    ['name' => 'Oczy', 'discount_percent' => 15],
                    ['name' => 'Inne', 'discount_percent' => 5],
                ],
                'ungrouped_group' => 'Inne',
            ],
        );

        $this->assertNotNull(
            $result['price_list'],
            implode('; ', $result['errors'] ?? []),
        );
        $this->assertGreaterThan(0, $result['created']);

        $product = Product::query()->where('sku', 'SKU-A')->first();
        $this->assertNotNull($product);
        $this->assertSame('Rękawice', $product->category);
        $this->assertNotNull($product->assortment_group_id);
        $this->assertEqualsWithDelta(25.0, (float) $product->discount_percent, 0.001);
        $this->assertEqualsWithDelta(75.0, (float) $product->purchase_price, 0.001);

        $ungrouped = Product::query()->where('sku', 'SKU-C')->first();
        $this->assertNotNull($ungrouped);
        $this->assertSame('Inne', $ungrouped->category);
        $this->assertEqualsWithDelta(5.0, (float) $ungrouped->discount_percent, 0.001);
    }

    private function makeDemoSpreadsheet(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['sku', 'nazwa', 'grupa', 'cena'],
            ['SKU-A', 'Rękawica A', 'Rękawice', 100],
            ['SKU-B', 'Okulary B', 'Oczy', 40],
            ['SKU-C', 'Bez grupy C', '', 20],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'cennik').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
