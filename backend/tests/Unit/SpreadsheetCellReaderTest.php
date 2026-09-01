<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SpreadsheetCellReader;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;
use PHPUnit\Framework\TestCase;

final class SpreadsheetCellReaderTest extends TestCase
{
    public function test_stringify_skips_objects_and_excel_errors(): void
    {
        $reader = new SpreadsheetCellReader;

        $this->assertSame('', $reader->stringify(null));
        $this->assertSame('', $reader->stringify(new \stdClass));
        $this->assertSame('', $reader->stringify('#REF!'));
        $this->assertSame('', $reader->stringify('=#VALUE!'));
        $this->assertSame('3.06', $reader->stringify(3.06));
        $this->assertSame('D14886039', $reader->stringify('D14886039'));

        $rich = new RichText;
        $rich->createText('  TYVEK  ');
        $this->assertSame('TYVEK', $reader->stringify($rich));
    }

    public function test_to_rows_does_not_throw_on_in_cell_drawing(): void
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['sku', 'nazwa', 'cena'],
            ['D1', 'Tyvek', 3.06],
        ]);

        $im = imagecreatetruecolor(2, 2);
        $this->assertNotFalse($im);
        $drawing = new MemoryDrawing;
        $drawing->setImageResource($im);
        $drawing->setCoordinates('D2');
        $sheet->getCell('D2')->setValueExplicit($drawing, DataType::TYPE_DRAWING_IN_CELL);

        $rows = (new SpreadsheetCellReader)->toRows($sheet);

        $this->assertSame('D1', $rows[1][0]);
        $this->assertSame('Tyvek', $rows[1][1]);
        $this->assertSame('3.06', $rows[1][2]);
        $this->assertSame('', $rows[1][3] ?? '');
    }
}
