<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PriceListPdfTextExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Ścieżka cennika ze znakiem `!` albo `%` (np. „Cenniki/!Wojtek/…”, „PROS cennik 2016 25%.pdf”)
 * musi trafić do pdftotext w całości. escapeshellarg na Windows wycinał te znaki, przez co
 * pdftotext dostawał nieistniejący plik i import cicho schodził na słabszy fallback smalot.
 */
final class PriceListPdfToTextPathTest extends TestCase
{
    private const LINE = 'KOD ARTYKULU 12345 CENA NETTO 123,45 PLN ZA PARE';

    public function test_reads_pdf_from_path_with_exclamation_and_percent(): void
    {
        $extractor = new PriceListPdfTextExtractor;
        $plain = $this->writeTextPdf(sys_get_temp_dir().'/pdftotext_plain_'.getmypid().'.pdf');

        try {
            if ($extractor->extractLayout($plain) === null) {
                $this->markTestSkipped('Brak pdftotext w systemie');
            }

            $dir = sys_get_temp_dir().'/pdftotext !arg %dir '.getmypid();
            @mkdir($dir, 0777, true);
            $weird = $this->writeTextPdf($dir.'/cennik 2016 25% !nowy.pdf');

            try {
                $text = $extractor->extractLayout($weird);
                $this->assertNotNull($text, 'pdftotext nie odczytał pliku ze znakami ! i % w ścieżce');
                $this->assertStringContainsString(self::LINE, $text);
                $this->assertSame($extractor->extractLayout($plain), $text);
                $this->assertStringContainsString(self::LINE, $extractor->extract($weird));
            } finally {
                @unlink($weird);
                @rmdir($dir);
            }
        } finally {
            @unlink($plain);
        }
    }

    /**
     * Minimalny PDF z warstwą tekstową (Helvetica, strumień bez kompresji).
     */
    private function writeTextPdf(string $path): string
    {
        $content = 'BT /F1 12 Tf 40 800 Td ('.self::LINE.') Tj ET'."\n";
        $objects = [
            1 => "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            2 => "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            3 => "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842]"
                ." /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n",
            4 => "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
            5 => '5 0 obj'."\n".'<< /Length '.strlen($content).' >>'."\nstream\n".$content."endstream\nendobj\n",
        ];

        $body = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $obj) {
            $offsets[$id] = strlen($body);
            $body .= $obj;
        }

        $xref = "xref\n0 6\n0000000000 65535 f \n";
        foreach ([1, 2, 3, 4, 5] as $id) {
            $xref .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $startxref = strlen($body);
        file_put_contents($path, $body.$xref."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$startxref}\n%%EOF\n");

        return $path;
    }
}
