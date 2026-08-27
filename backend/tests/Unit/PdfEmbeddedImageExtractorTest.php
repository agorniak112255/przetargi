<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PdfEmbeddedImageExtractor;
use App\Services\PriceListPdfTextExtractor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PdfEmbeddedImageExtractorTest extends TestCase
{
    public function test_extracts_jpeg_from_image_only_pdf_with_broken_xref(): void
    {
        $jpeg = $this->tinyJpeg();
        $path = $this->writeBrokenXrefImagePdf($jpeg);

        try {
            $images = (new PdfEmbeddedImageExtractor)->extract($path);
            $this->assertCount(1, $images);
            $this->assertSame('image/jpeg', $images[0]['mime']);
            $this->assertSame('Strona 1', $images[0]['label']);
            $this->assertStringStartsWith("\xFF\xD8", $images[0]['bytes']);
        } finally {
            @unlink($path);
        }
    }

    public function test_prepare_for_vision_downscales_large_page(): void
    {
        $im = imagecreatetruecolor(2000, 2800);
        $this->assertNotFalse($im);
        imagefilledrectangle($im, 0, 0, 1999, 2799, imagecolorallocate($im, 255, 255, 255));
        ob_start();
        imagejpeg($im, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($im);

        $out = (new PdfEmbeddedImageExtractor)->prepareForVision([
            ['bytes' => $jpeg, 'mime' => 'image/jpeg', 'label' => 'Strona 1'],
        ], 1400, 72);

        $info = getimagesizefromstring($out[0]['bytes']);
        $this->assertIsArray($info);
        $this->assertLessThanOrEqual(1400, max((int) $info[0], (int) $info[1]));
        $this->assertLessThan(strlen($jpeg), strlen($out[0]['bytes']));
    }

    public function test_strzelce_scan_has_two_jpeg_pages_and_no_missing_catalog(): void
    {
        $path = 'c:/xampp/htdocs/Przetargi/Cenniki/Strzelce Opolskie - 2018-02-01.pdf';
        if (! is_file($path)) {
            $this->markTestSkipped('Brak lokalnego cennika PPO Strzelce');
        }

        $images = (new PdfEmbeddedImageExtractor)->extract($path);
        $this->assertCount(2, $images);
        $this->assertSame('image/jpeg', $images[0]['mime']);
        $this->assertGreaterThan(100_000, strlen($images[0]['bytes']));

        try {
            (new PriceListPdfTextExtractor)->extract($path);
            $this->fail('Skan powinien rzucić wyjątek braku tekstu');
        } catch (RuntimeException $e) {
            $this->assertStringNotContainsString('Missing catalog', $e->getMessage());
            $this->assertStringContainsStringIgnoringCase('skan', $e->getMessage());
        }
    }

    private function writeBrokenXrefImagePdf(string $jpeg): string
    {
        $len = strlen($jpeg);
        $objects = [];
        $objects[1] = "1 0 obj\n<</Type /XObject /Subtype /Image /Name /Im1 /Width 800 /Height 600"
            ." /Length {$len}/ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter [ /DCTDecode ] >>\nstream\n"
            .$jpeg
            ."\nendstream\nendobj\n";
        $objects[2] = "2 0 obj\n<< /Length 44 >>\nstream\nq 595 0 0 842 0 0 cm 1 g /Im1 Do Q\nendstream\nendobj\n";
        $objects[3] = "3 0 obj\n<< /Type /Page /MediaBox [0 0 596 842] /Parent 4 0 R"
            ." /Resources << /XObject << /Im1 1 0 R >> >> /Contents [ 2 0 R ] >>\nendobj\n";
        $objects[4] = "4 0 obj\n<< /Type /Pages /Kids [ 3 0 R ] /Count 1 >>\nendobj\n";
        $objects[5] = "5 0 obj\n<< /Type /Catalog /Pages 4 0 R >>\nendobj\n";

        $body = "%PDF-1.3\n";
        $offsets = [0 => 0];
        foreach ($objects as $id => $obj) {
            $offsets[$id] = strlen($body);
            $body .= $obj;
        }

        // Jak w skanie PPO: „1 6” + wpis free obiektu 0 na początku.
        $xref = "xref\n1 6\n0000000000 65535 f \n";
        foreach ([1, 2, 3, 4, 5] as $id) {
            $xref .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $startxref = strlen($body);
        $pdf = $body.$xref."trailer\n<< /Size 6 /Root 5 0 R >>\nstartxref\n{$startxref}\n%%EOF\n";

        $path = tempnam(sys_get_temp_dir(), 'ppo').'.pdf';
        file_put_contents($path, $pdf);

        return $path;
    }

    private function tinyJpeg(): string
    {
        $im = imagecreatetruecolor(32, 32);
        $this->assertNotFalse($im);
        imagefilledrectangle($im, 0, 0, 31, 31, imagecolorallocate($im, 240, 240, 240));
        ob_start();
        imagejpeg($im, null, 80);
        $jpeg = (string) ob_get_clean();
        imagedestroy($im);
        $this->assertStringStartsWith("\xFF\xD8", $jpeg);

        return $jpeg;
    }
}
