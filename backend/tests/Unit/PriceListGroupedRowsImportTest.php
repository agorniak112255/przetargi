<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PriceListImportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class PriceListGroupedRowsImportTest extends TestCase
{
    public function test_forward_fills_name_and_keeps_article_sku(): void
    {
        $path = $this->makeGroupedSpreadsheet();
        try {
            $service = app(PriceListImportService::class);
            $mapping = [
                'currency' => 'EUR',
                'sheets' => [
                    [
                        'sheet' => 'Industrial (2)',
                        'include' => true,
                        'header_excel_row' => 2,
                        'columns' => [
                            'sku' => 5,
                            'name' => 4,
                            'catalog_price' => 9,
                            'discount' => null,
                            'purchase' => null,
                            'pack_qty' => 7,
                            'packaging' => 6,
                            'currency' => null,
                            'ean' => null,
                            'category' => 1,
                        ],
                        'repeating_headers' => false,
                        'confidence' => 1.0,
                    ],
                ],
            ];

            $preview = $service->previewFromMapping($path, $mapping, 20);
            $bySku = [];
            foreach ($preview['products'] as $p) {
                $bySku[$p['sku']] = $p;
            }

            $this->assertArrayHasKey('D14681379', $bySku);
            $this->assertArrayHasKey('D14681380', $bySku);
            $this->assertArrayHasKey('D14681398', $bySku);
            $this->assertArrayHasKey('D13495380', $bySku);

            $this->assertSame('NEW! TYVEK Dual Combi', $bySku['D14681379']['name']);
            $this->assertSame('NEW! TYVEK Dual Combi', $bySku['D14681380']['name']);
            $this->assertSame('NEW! TYVEK Dual Combi', $bySku['D14681398']['name']);
            $this->assertNotNull($bySku['D14681380']['description'] ?? null);
            $this->assertStringContainsString('Collared coverall', (string) $bySku['D14681380']['description']);

            $this->assertSame('S', $bySku['D14681379']['packaging']);
            $this->assertSame(25, $bySku['D14681379']['pack_qty']);
            $this->assertEqualsWithDelta(1074.89, (float) $bySku['D13495380']['catalog_price_net'], 0.001);
            $this->assertStringStartsNotWith('FOR-USE-WITH-SCBA', $bySku['D13495380']['sku']);
            $this->assertNotSame('NEW! TYVEK Dual Combi', $bySku['D13495380']['name']);
            $this->assertStringContainsString('SCBA', $bySku['D13495380']['name']);
        } finally {
            @unlink($path);
        }
    }

    public function test_flat_price_list_still_imports(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'flat').'.xlsx';
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['sku', 'nazwa', 'cena'],
            ['ABC-1', 'Rękawica test', 12.5],
            ['ABC-2', 'Okulary test', 8],
        ]);
        (new Xlsx($spreadsheet))->save($path);

        try {
            $service = app(PriceListImportService::class);
            $mapping = [
                'currency' => 'PLN',
                'sheets' => [
                    [
                        'sheet' => $spreadsheet->getActiveSheet()->getTitle(),
                        'include' => true,
                        'header_excel_row' => 1,
                        'columns' => [
                            'sku' => 0,
                            'name' => 1,
                            'catalog_price' => 2,
                            'discount' => null,
                            'purchase' => null,
                            'pack_qty' => null,
                            'packaging' => null,
                            'currency' => null,
                            'ean' => null,
                            'category' => null,
                        ],
                        'repeating_headers' => false,
                        'confidence' => 1.0,
                    ],
                ],
            ];
            $preview = $service->previewFromMapping($path, $mapping, 10);
            $this->assertSame(2, $preview['products_found']);
            $this->assertSame('ABC-1', $preview['products'][0]['sku']);
            $this->assertSame('Rękawica test', $preview['products'][0]['name']);
        } finally {
            @unlink($path);
        }
    }

    private function makeGroupedSpreadsheet(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Industrial (2)');
        $sheet->fromArray([
            [null, 'Pricelist 2018', null, null, 'Core', null, null, null, null, '10/12/2017'],
            [null, 'Category/Type', 'Reference', 'Product Image', 'Model Name and Description', 'Article Number', 'Size', 'Quantity per box', 'Minimum Order Quantity', 'Price(€/pc.) for Min. of 5000€'],
            [null, 'TYVEK®', null, null, null, null, null, null, null, null],
            ['TD 0125 S WH 00', 'Cat.III', 'TD 0125 S WH 00', null, 'NEW! TYVEK Dual Combi', 'D14681379', 'S', '25', '1600', '2.68'],
            ['TD 0125 S WH 00', null, null, null, 'Collared coverall combining Tyvek with a light polypropylene back panel. Elasticated wrists, waist and ankles. Zipper flap. Thumbhole on sleeve.', 'D14681380', 'M', '25', '1600', '2.68'],
            ['TD 0125 S WH 00', null, null, null, null, 'D14681398', 'L', '25', '1600', '2.68'],
            ['TK GEVJ T YL 00', null, null, null, 'For use with SCBA. Attached (detachable) overboots. Attached double gloves (removable). Wide panoramic visor. Boot size available up to size 46.', 'D13495380*', 'M*', '1', 'N/A', '1074.89'],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'grouped').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
