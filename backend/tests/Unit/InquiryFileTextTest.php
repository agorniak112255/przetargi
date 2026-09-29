<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\InquiryFileText;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory as WordIO;
use PhpOffice\PhpWord\PhpWord;
use RuntimeException;
use Tests\TestCase;

final class InquiryFileTextTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_spreadsheet_row_stays_one_line_with_cells_apart(): void
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([
            ['Lp.', 'Nazwa', 'Rozmiar', 'Ilość', 'j.m.'],
            [1, 'Rękawice MAPA 332', 9, 4, 'para'],
            [2, 'Rękawice MAPA 332', 10, 2, 'para'],
        ]);
        $path = $this->temp('xlsx');
        (new Xlsx($book))->save($path);

        $text = app(InquiryFileText::class)->extract($path, 'xlsx');

        // numer pozycji, rozmiar i ilość zostają rozróżnialne — nie „1 Rękawice MAPA 332 9 4 para”
        $this->assertSame(
            "Lp. | Nazwa | Rozmiar | Ilość | j.m.\n1 | Rękawice MAPA 332 | 9 | 4 | para\n2 | Rękawice MAPA 332 | 10 | 2 | para",
            $text,
        );
    }

    public function test_several_sheets_are_labelled_and_empty_ones_skipped(): void
    {
        $book = new Spreadsheet;
        $book->getActiveSheet()->setTitle('Obuwie')->fromArray([['Kalosze PVC S5', 'rozm. 43', '10 par']]);
        $book->createSheet()->setTitle('Puste');
        $book->createSheet()->setTitle('Rękawice')->fromArray([['Rękawice nitrylowe', 'rozm. 9', '100 par']]);
        $path = $this->temp('xlsx');
        (new Xlsx($book))->save($path);

        $text = app(InquiryFileText::class)->extract($path, 'xlsx');

        $this->assertSame(
            "Arkusz: Obuwie\nKalosze PVC S5 | rozm. 43 | 10 par\n\nArkusz: Rękawice\nRękawice nitrylowe | rozm. 9 | 100 par",
            $text,
        );
    }

    public function test_csv_from_polish_excel_keeps_polish_letters(): void
    {
        $path = $this->temp('csv');
        file_put_contents($path, (string) iconv('UTF-8', 'CP1250', "Nazwa;Ilość\nRękawice ochronne żółte;12 par\n"));

        $text = app(InquiryFileText::class)->extract($path, 'csv');

        $this->assertSame("Nazwa | Ilość\nRękawice ochronne żółte | 12 par", $text);
    }

    public function test_word_table_rows_keep_cells_apart_and_paragraphs_stay(): void
    {
        $word = new PhpWord;
        $section = $word->addSection();
        $section->addText('Zapytanie ofertowe nr 12/2026');
        $table = $section->addTable();
        foreach ([['Lp.', 'Nazwa', 'Ilość'], ['1', 'Rękawice MAPA 332 rozm. 9', '4 pary']] as $row) {
            $table->addRow();
            foreach ($row as $cell) {
                $table->addCell(2000)->addText($cell);
            }
        }
        $section->addText('Termin dostawy: 14 dni.');
        $path = $this->temp('docx');
        WordIO::createWriter($word, 'Word2007')->save($path);

        $text = app(InquiryFileText::class)->extract($path, 'docx');

        $this->assertStringContainsString("Zapytanie ofertowe nr 12/2026\nLp. | Nazwa | Ilość\n1 | Rękawice MAPA 332 rozm. 9 | 4 pary", $text);
        $this->assertStringContainsString('Termin dostawy: 14 dni.', $text);
    }

    public function test_pdf_table_row_stays_on_one_line(): void
    {
        $path = $this->temp('pdf');
        file_put_contents($path, Pdf::loadHTML(
            // DejaVu ma polskie litery — domyślna czcionka dompdf zamienia „ę” na „?” już w samym PDF-ie
            '<body style="font-family: DejaVu Sans"><p>Zapytanie ofertowe dla firmy SUPON</p><table><tr><td>1</td><td>Rękawice MAPA 332 rozm. 9</td><td>4 pary</td></tr>'
            .'<tr><td>2</td><td>Rękawice MAPA 332 rozm. 10</td><td>2 pary</td></tr></table></body>'
        )->output());

        $text = app(InquiryFileText::class)->extract($path, 'pdf');

        $this->assertMatchesRegularExpression('/1\s+Rękawice MAPA 332 rozm\. 9\s+4 pary/u', $text);
        $this->assertMatchesRegularExpression('/2\s+Rękawice MAPA 332 rozm\. 10\s+2 pary/u', $text);
    }

    public function test_empty_spreadsheet_is_refused_with_a_reason(): void
    {
        $path = $this->temp('xlsx');
        (new Xlsx(new Spreadsheet))->save($path);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Plik nie zawiera tekstu do analizy');

        app(InquiryFileText::class)->extract($path, 'xlsx');
    }

    public function test_other_formats_are_refused(): void
    {
        $path = $this->temp('txt');
        file_put_contents($path, 'Rękawice MAPA 332 rozm. 9 — 4 pary');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nieobsługiwany format pliku');

        app(InquiryFileText::class)->extract($path, 'txt');
    }

    private function temp(string $ext): string
    {
        $base = (string) tempnam(sys_get_temp_dir(), 'inqfile');
        $path = $base.'.'.$ext;
        array_push($this->files, $base, $path);

        return $path;
    }
}
