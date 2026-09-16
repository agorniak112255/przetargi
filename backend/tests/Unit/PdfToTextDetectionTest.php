<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PriceListPdfTextExtractor;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Wyszukiwanie pdftotext nie może zależeć od `where` (polecenie Windows) — na Linuksie
 * nigdy się nie udawało, więc serwer czytał każdy cennik słabszym fallbackiem smalot.
 */
final class PdfToTextDetectionTest extends TestCase
{
    public function test_finds_binary_from_path_on_any_system(): void
    {
        $dir = sys_get_temp_dir().'/pdftotext_path_'.getmypid();
        @mkdir($dir, 0777, true);
        $fake = $dir.DIRECTORY_SEPARATOR.(PHP_OS_FAMILY === 'Windows' ? 'pdftotext.exe' : 'pdftotext');
        file_put_contents($fake, 'atrapa');
        @chmod($fake, 0755);
        $path = (string) getenv('PATH');

        try {
            putenv('PATH='.$dir);
            $found = $this->findPdfToText();

            $this->assertNotNull($found, 'Nie znaleziono pdftotext mimo katalogu w PATH');
            $this->assertTrue(is_file($found));
            $this->assertStringContainsString('pdftotext', basename($found));
            if (! $this->knownLocationExists()) {
                $this->assertSame($fake, $found);
            }
        } finally {
            putenv('PATH='.$path);
            @unlink($fake);
            @rmdir($dir);
        }
    }

    public function test_returns_null_when_path_has_no_binary(): void
    {
        $dir = sys_get_temp_dir().'/pdftotext_empty_'.getmypid();
        @mkdir($dir, 0777, true);
        $path = (string) getenv('PATH');

        try {
            putenv('PATH='.$dir);
            if ($this->knownLocationExists()) {
                $this->markTestSkipped('Systemowa lokalizacja pdftotext istnieje');
            }
            $this->assertNull($this->findPdfToText());
        } finally {
            putenv('PATH='.$path);
            @rmdir($dir);
        }
    }

    private function findPdfToText(): ?string
    {
        $method = new ReflectionMethod(PriceListPdfTextExtractor::class, 'findPdfToText');
        $method->setAccessible(true);

        return $method->invoke(new PriceListPdfTextExtractor);
    }

    private function knownLocationExists(): bool
    {
        $known = PHP_OS_FAMILY === 'Windows'
            ? [
                'C:\\Program Files\\Git\\mingw64\\bin\\pdftotext.exe',
                'C:\\Program Files\\Git\\usr\\bin\\pdftotext.exe',
            ]
            : ['/usr/bin/pdftotext', '/usr/local/bin/pdftotext', '/opt/poppler/bin/pdftotext'];

        foreach ($known as $candidate) {
            if (is_file($candidate)) {
                return true;
            }
        }

        return false;
    }
}
