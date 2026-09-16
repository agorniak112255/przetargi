<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\B2b\B2bDocumentText;
use Tests\TestCase;

/**
 * Odczyt tekstu z pliku dostawcy: tylko PDF, w osobnym procesie z limitem czasu — jeden plik nie może
 * zatrzymać pobierania całego cennika.
 */
final class B2bDocumentTextTest extends TestCase
{
    public function test_reads_text_from_a_pdf_datasheet(): void
    {
        $bytes = (string) file_get_contents(base_path('tests/Fixtures/uvex/sst_9970005.pdf'));

        $text = (new B2bDocumentText)->fromFile($bytes, 'application/pdf');

        $this->assertStringContainsString('EN ISO 20345:2011 S1 P SRC', $text);
        $this->assertLessThanOrEqual(B2bDocumentText::LIMIT, mb_strlen($text));
    }

    public function test_file_that_is_not_a_readable_pdf_gives_no_text(): void
    {
        $service = new B2bDocumentText;

        $this->assertSame('', $service->fromFile('%PDF-1.4 to nie jest prawdziwy plik', 'application/pdf'));
        $this->assertSame('', $service->fromFile('GIF89a', 'image/gif'));
        $this->assertSame('', $service->fromFile('cokolwiek', 'application/pdf'), 'bez nagłówka %PDF nie próbujemy czytać');
    }
}
