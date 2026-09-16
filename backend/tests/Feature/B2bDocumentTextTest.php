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

    public function test_company_letterhead_is_left_out_of_the_card_description(): void
    {
        $raw = implode('
', [
            'UVEX SAFETY POLSKA SP. z o.o. Sp. K. ul. Głogowska 3A Większyce · 47-208 Reńska Wieś',
            '',
            'UVEX SAFETY POLSKA Sp. z o.o. Sp. K.',
            'ul. Głogowska 3A, 47-208 Reńska Wieś',
            'T +48 77 482 62 58',
            'F +48 77 482 62 57',
            'E uvex@uvex-integra.pl',
            'I uvex-safety.pl',
            'NIP 749-18-06-904',
            'Regon: 531 504 413',
            'KRS 0000334106',
            'SR w Opolu VIII Wydz. Gosp.',
            '',
            '',
            '',
            'Strona 1 z 1 2018-03-12',
            'Filtr P1P10',
            'Normy EN ISO 20345:2011 S1 P SRC',
            'VLT (przepuszczalność światła) 16%',
            'Jednostka notyfikowana: ANCI-Servici Srl, nr 0465',
            'Producent: UVEX ARBEITSSCHUTZ GMBH, Wurzburger Str. 181-189, 90766 Furth, Niemcy',
        ]);

        $text = B2bDocumentText::forCard($raw);

        foreach (['NIP', 'KRS', 'Regon', 'uvex@uvex-integra.pl', 'ul. Głogowska', 'Wydz. Gosp.', 'Strona 1 z 1'] as $noise) {
            $this->assertStringNotContainsString($noise, $text, 'stopka firmowa nie należy do opisu wyrobu');
        }

        // fakty o wyrobie zostają, także producent i jednostka notyfikowana
        $this->assertStringContainsString('Normy EN ISO 20345:2011 S1 P SRC', $text);
        $this->assertStringContainsString('VLT (przepuszczalność światła) 16%', $text);
        $this->assertStringContainsString('Producent: UVEX ARBEITSSCHUTZ GMBH', $text);
        $this->assertStringContainsString('Jednostka notyfikowana: ANCI-Servici Srl, nr 0465', $text);
        $this->assertStringNotContainsString('


', $text, 'puste akapity zwijamy');
    }

    public function test_file_that_is_not_a_readable_pdf_gives_no_text(): void
    {
        $service = new B2bDocumentText;

        $this->assertSame('', $service->fromFile('%PDF-1.4 to nie jest prawdziwy plik', 'application/pdf'));
        $this->assertSame('', $service->fromFile('GIF89a', 'image/gif'));
        $this->assertSame('', $service->fromFile('cokolwiek', 'application/pdf'), 'bez nagłówka %PDF nie próbujemy czytać');
    }
}
