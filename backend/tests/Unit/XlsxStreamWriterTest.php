<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\XlsxStreamWriter;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PHPUnit\Framework\TestCase;

final class XlsxStreamWriterTest extends TestCase
{
    public function test_file_reads_back_with_types_dates_and_odd_text(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'xlsxt');
        $writer = new XlsxStreamWriter($path);
        $writer->addSheet('Towary: [test]/*?', [10, 20], header: true);
        $writer->addRow(array_map(static fn (int $i): string => 'K'.$i, range(1, 28)));
        $writer->addRow(['Zażółć <&> "gęślą"'."\x01", 12.5, null, 7, XlsxStreamWriter::date('2026-09-30'),
            XlsxStreamWriter::dateTime(new DateTimeImmutable('2026-10-05 13:45:00')), true, '', XlsxStreamWriter::date(null)]);
        $writer->addSheet('Drugi');
        $writer->addRow(['x']);
        $writer->close();

        $book = IOFactory::load($path);
        unlink($path);
        $this->assertSame(['Towary   test', 'Drugi'], $book->getSheetNames());
        $sheet = $book->getSheet(0);
        $this->assertSame('K28', (string) $sheet->getCell('AB1')->getValue());
        $this->assertTrue($sheet->getStyle('A1')->getFont()->getBold());
        $this->assertSame('A2', $sheet->getFreezePane());
        $this->assertSame('A1:AB2', $sheet->getAutoFilter()->getRange());
        $this->assertSame('Zażółć <&> "gęślą"', (string) $sheet->getCell('A2')->getValue());
        $this->assertSame(12.5, (float) $sheet->getCell('B2')->getValue());
        $this->assertNull($sheet->getCell('C2')->getValue());
        $this->assertSame('2026-09-30', ExcelDate::excelToDateTimeObject((float) $sheet->getCell('E2')->getValue())->format('Y-m-d'));
        $this->assertSame('yyyy-mm-dd', $sheet->getStyle('E2')->getNumberFormat()->getFormatCode());
        $this->assertSame('2026-10-05 13:45', ExcelDate::excelToDateTimeObject((float) $sheet->getCell('F2')->getValue())->format('Y-m-d H:i'));
        $this->assertSame('tak', (string) $sheet->getCell('G2')->getValue());
        $this->assertNull($sheet->getCell('H2')->getValue());
        $this->assertSame('x', (string) $book->getSheet(1)->getCell('A1')->getValue());
    }
}
