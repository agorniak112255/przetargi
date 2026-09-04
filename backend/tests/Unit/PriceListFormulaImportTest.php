<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PriceListImportService;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class PriceListFormulaImportTest extends TestCase
{
    public function test_preview_uses_calculated_cross_sheet_formulas(): void
    {
        $path = $this->makeRostaingLikeWorkbook();
        try {
            $preview = app(PriceListImportService::class)->previewFromMapping($path, [
                'currency' => 'EUR',
                'notes' => 'test',
                'sheets' => [
                    [
                        'sheet' => 'ROSTAING 2026',
                        'include' => true,
                        'role' => 'catalog',
                        'header_excel_row' => 15,
                        'columns' => [
                            'sku' => 4,
                            'name' => 1,
                            'catalog_price' => 8,
                            'pack_qty' => 7,
                            'packaging' => 5,
                        ],
                        'repeating_headers' => false,
                        'confidence' => 0.9,
                    ],
                ],
            ], 8);

            $this->assertSame(1, $preview['products_found']);
            $row = $preview['products'][0];
            $this->assertSame('35X30SPEEDNET', $row['sku']);
            $this->assertSame('RECTANGLE 35X30 CM MICROFIBRE', $row['name']);
            $this->assertEqualsWithDelta(4.944, (float) $row['catalog_price_net'], 0.001);
            $this->assertStringNotContainsString('Trad. articles', (string) $row['sku']);
            $this->assertStringNotContainsString('=', (string) $row['name']);
        } finally {
            @unlink($path);
        }
    }

    private function makeRostaingLikeWorkbook(): string
    {
        $spreadsheet = new Spreadsheet;
        $trad = $spreadsheet->getActiveSheet();
        $trad->setTitle('Trad. articles');
        $trad->fromArray([
            ['idx', 'EAN', 'NAME', 'REFERENCE'],
            [2, '3353090014158', 'CROSS-MARKETING', '1951'],
            [3, '3353090016794', 'RECTANGLE 35X30 CM MICROFIBRE', '35X30SPEEDNET'],
        ]);

        $view = $spreadsheet->createSheet();
        $view->setTitle('ROSTAING 2026');
        $view->setCellValue('B15', 'Product Description');
        $view->setCellValue('E15', 'Reference');
        $view->setCellValue('F15', 'Unit or Pair');
        $view->setCellValue('H15', 'Quantity per box');
        $view->setCellValue('I15', 'Base price 2026 in Euro');

        $view->setCellValue('B16', "='Trad. articles'!C2");
        $view->setCellValue('E16', "='Trad. articles'!D2");
        $view->setCellValue('F16', 'U');
        $view->setCellValue('H16', 20);
        $view->setCellValue('I16', '-');

        $view->setCellValue('B17', "='Trad. articles'!C3");
        $view->setCellValue('E17', "='Trad. articles'!D3");
        $view->setCellValue('F17', 'U');
        $view->setCellValue('H17', 50);
        $view->setCellValue('I17', 4.944);

        $path = tempnam(sys_get_temp_dir(), 'rostaing-f').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
