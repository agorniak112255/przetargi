<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SpreadsheetMappingHeuristic;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

final class SpreadsheetMappingHeuristicTest extends TestCase
{
    public function test_prefers_article_number_over_sparse_reference(): void
    {
        $path = $this->makeDupontLikeSpreadsheet();
        try {
            $mapping = (new SpreadsheetMappingHeuristic)->detect($path);
            $this->assertNotNull($mapping);
            $sheet = $mapping['sheets'][0];
            $cols = $sheet['columns'];

            $this->assertSame(5, $cols['sku'], 'SKU = Article Number');
            $this->assertSame(2, $cols['model_key'], 'model_key = Reference');
            $this->assertSame(4, $cols['name'], 'Name = Model Name and Description');
            $this->assertSame(9, $cols['catalog_price']);
            $this->assertSame(2, $sheet['header_excel_row']);
            $this->assertSame('EUR', $mapping['currency']);
        } finally {
            @unlink($path);
        }
    }

    private function makeDupontLikeSpreadsheet(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Industrial (2)');
        $sheet->fromArray([
            [null, 'Pricelist 2018', null, null, 'Core', null, null, null, null, '10/12/2017'],
            [null, 'Category/Type', 'Reference', 'Product Image', 'Model Name and Description', 'Article Number', 'Size', 'Quantity per box', 'Minimum Order Quantity', 'Price(€/pc.) for Min. of 5000€'],
            [null, 'TYVEK®', null, null, null, null, null, null, null, null],
            ['TD 0125 S WH 00', 'Cat.III', 'TD 0125 S WH 00', null, 'NEW! TYVEK Dual Combi', 'D14681379', 'S', '25', '1600', '2.68'],
            ['TD 0125 S WH 00', null, null, null, 'Collared coverall combining Tyvek with a light polypropylene back panel. Elasticated wrists, waist and ankles. Zipper flap.', 'D14681380', 'M', '25', '1600', '2.68'],
            ['TD 0125 S WH 00', null, null, null, null, 'D14681398', 'L', '25', '1600', '2.68'],
            ['TK GEVJ T YL 00', null, null, null, 'For use with SCBA. Attached (detachable) overboots. Attached double gloves (removable). Wide panoramic visor.', 'D13495380*', 'M*', '1', 'N/A', '1074.89'],
        ]);

        $path = tempnam(sys_get_temp_dir(), 'dupont').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
