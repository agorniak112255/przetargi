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

    /**
     * Karta katalogowa Protektu (tekst z pliku „Karta produktowa”, 16.09.2026): opisuje całą rodzinę WS, więc
     * poniżej nagłówka „CENNIK” idzie tabela cen katalogowych z numerami wszystkich długości, nazwa rodziny
     * i stopka firmowa. Na karcie jednego wyrobu ma zostać tylko to, co mówi o wyrobie.
     */
    public function test_manufacturer_price_list_and_footer_are_left_out_of_the_card_description(): void
    {
        $raw = implode("\n", [
            'PODSTAWOWE ZALETY ZAWIESI TAŚMOWYCH',
            '• szeroki zakres temperatur użytkowania (-40˚C do +100˚C)',
            'Taśma poliester',
            'EN 1492-1',
            'CECHY SZCZEGÓLNE',
            'PARAMETRY',
            'CENNIK',
            'WS Zawiesia taśmowe dwuwarstwowe',
            'PROTEKT Grzegorz Łaszkiewicz Spółka z o.o.ul. Starorudzka 9, 93-403 Łodź, POLSKA',
            'KRS: 0001009727 | REGON: 524047958 | NIP: 7292747932',
            'DZIAŁ HANDLOWY ul. Skromna 6, 93-405 Łódź, POLSKA',
            'tel.+48 42 29-29-500, handlowy@protekt.com.pl, Fax:+48 42 680-20-93',
            'WWW.PROTEKT.PL',
            'CENNIK',
            'L - Długość',
            '0,5 m 1 m 2 m 3 m 4 m 5 m 6 m 8 m 10 m 12 m',
            '23,80 /',
            'szt.',
            'WS 005 50',
            '100,40 /',
            'WS 020 08',
        ]);

        $text = B2bDocumentText::forCard($raw);

        foreach (['23,80', '100,40', 'WS 005 50', 'WS 020 08', 'L - Długość', 'Starorudzka', 'Skromna',
            'handlowy@protekt.com.pl', 'KRS', 'NIP', 'WWW.PROTEKT.PL', 'CENNIK'] as $noise) {
            $this->assertStringNotContainsString($noise, $text, 'cennik rodziny i stopka nie należą do opisu wyrobu');
        }

        $this->assertStringContainsString('PODSTAWOWE ZALETY ZAWIESI TAŚMOWYCH', $text);
        $this->assertStringContainsString('szeroki zakres temperatur użytkowania (-40˚C do +100˚C)', $text);
        $this->assertStringContainsString('Taśma poliester', $text);
        $this->assertStringContainsString('EN 1492-1', $text);
    }

    /** Nagłówek bez tabeli cen pod spodem nie jest granicą — treść pod nim zostaje. */
    public function test_a_heading_without_prices_below_does_not_cut_the_text(): void
    {
        $text = B2bDocumentText::forCard("Waga 20 g\nCENNIK\nMateriał poliester\nKolor czarny");

        $this->assertStringContainsString('Materiał poliester', $text);
        $this->assertStringContainsString('Kolor czarny', $text);
    }

    public function test_file_that_is_not_a_readable_pdf_gives_no_text(): void
    {
        $service = new B2bDocumentText;

        $this->assertSame('', $service->fromFile('%PDF-1.4 to nie jest prawdziwy plik', 'application/pdf'));
        $this->assertSame('', $service->fromFile('GIF89a', 'image/gif'));
        $this->assertSame('', $service->fromFile('cokolwiek', 'application/pdf'), 'bez nagłówka %PDF nie próbujemy czytać');
    }
}
