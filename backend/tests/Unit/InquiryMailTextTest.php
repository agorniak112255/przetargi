<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\InquiryMailText;
use PHPUnit\Framework\TestCase;

final class InquiryMailTextTest extends TestCase
{
    public function test_cuts_signature_marked_with_double_dash(): void
    {
        $mail = implode("\n", [
            'Dzień dobry,',
            '',
            'proszę o wycenę:',
            '10 szt. rękawice nitrylowe rozmiar 9',
            '',
            'Pozdrawiam',
            'Jan Kowalski',
            '-- ',
            'SUPON S.A.',
            '35-001 Rzeszów, ul. Przemysłowa 12',
            'tel. 500 123 456',
        ]);

        $clean = InquiryMailText::forAnalysis($mail);

        $this->assertStringContainsString('10 szt. rękawice nitrylowe rozmiar 9', $clean);
        $this->assertStringNotContainsString('35-001', $clean);
        $this->assertStringNotContainsString('500 123 456', $clean);
    }

    public function test_cuts_company_footer_without_separator(): void
    {
        $mail = implode("\n", [
            'Dzień dobry,',
            '',
            'proszę o ofertę na 20 szt. rękawic roboczych oraz 10 par butów S3.',
            '',
            'Pozdrawiam,',
            'Mateusz Baniak',
            'pomoc@proferis.pl<mailto:pomoc@proferis.pl>',
            '| PROFERIS',
            'tel. 17 785 22 46,   Al. gen. L. Okulickiego 18,  35-206 Rzeszów',
            'Uwaga: Wiadomość przeznaczona jest tylko dla jej adresata.',
        ]);

        $clean = InquiryMailText::forAnalysis($mail);

        $this->assertStringContainsString('20 szt. rękawic roboczych', $clean);
        $this->assertStringNotContainsString('PROFERIS', $clean);
        $this->assertStringNotContainsString('17 785 22 46', $clean);
        $this->assertStringNotContainsString('35-206', $clean);
    }

    public function test_cuts_confidentiality_clause_without_closing(): void
    {
        $mail = implode("\n", [
            'Dzień dobry,',
            '',
            'proszę o wycenę 15 szt. kasków ochronnych.',
            '',
            'Uwaga: Wiadomość przeznaczona jest tylko dla jej adresata.',
            'tel. 17 785 22 46, 35-206 Rzeszów',
        ]);

        $clean = InquiryMailText::forAnalysis($mail);

        $this->assertStringContainsString('15 szt. kasków ochronnych', $clean);
        $this->assertStringNotContainsString('35-206', $clean);
    }

    public function test_keeps_note_and_forwarded_content_without_forward_header(): void
    {
        $mail = implode("\n", [
            'zapytanie:',
            '',
            '--- Treść przekazanej wiadomości ---',
            "\tTemat:OFERTA Skalmierzyce - Zakupy",
            "\tData:Tue, 4 Aug 2026 13:09:32 +0200",
            'Nadawca:Wojciech Dzierżak <marketing@supon.rzeszow.pl>',
            ' Adresat:oferty@elektrosilver.pl',
            '',
            'Dzień dobry,',
            '',
            'proszę o wycenę 6 szt. wycieraczek gumowych 40x60 cm.',
        ]);

        $clean = InquiryMailText::forAnalysis($mail);

        // notatka przekazującego zostaje — bywa w niej polecenie dla handlowca
        $this->assertStringContainsString('zapytanie:', $clean);
        $this->assertStringContainsString('6 szt. wycieraczek gumowych', $clean);
        // nagłówek przekazania znika, żeby data i adresy nie trafiły do analizy
        $this->assertStringNotContainsString('Nadawca:', $clean);
        $this->assertStringNotContainsString('oferty@elektrosilver.pl', $clean);
        $this->assertStringNotContainsString('4 Aug 2026', $clean);
    }

    public function test_cuts_quoted_reply(): void
    {
        $mail = implode("\n", [
            'Dzień dobry,',
            '',
            'potrzebuję jeszcze 20 szt. okularów ochronnych.',
            '',
            'W dniu 2026-09-10 o 10:12, Anna Nowak napisał(a):',
            '> 5 szt. kaski budowlane',
            '> 35-001 Rzeszów',
        ]);

        $clean = InquiryMailText::forAnalysis($mail);

        $this->assertStringContainsString('20 szt. okularów ochronnych', $clean);
        $this->assertStringNotContainsString('kaski budowlane', $clean);
    }

    public function test_short_mail_survives_greeting_in_second_line(): void
    {
        $mail = "Dzień dobry,\nPozdrawiam, proszę o 10 szt. rękawic nitrylowych rozmiar 9.";

        $clean = InquiryMailText::forAnalysis($mail);

        $this->assertStringContainsString('10 szt. rękawic nitrylowych', $clean);
    }

    public function test_plain_inquiry_is_left_untouched(): void
    {
        $mail = "Dzień dobry,\n\nproszę o wycenę 30 szt. kamizelek ostrzegawczych.";

        $this->assertSame($mail, InquiryMailText::forAnalysis($mail));
    }

    public function test_falls_back_to_raw_when_everything_would_be_cut(): void
    {
        $mail = "-- \nSUPON S.A.\n35-001 Rzeszów";

        $this->assertSame($mail, InquiryMailText::forAnalysis($mail));
    }
}
