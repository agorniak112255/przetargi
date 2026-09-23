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

        // Jednowyrazowa notatka („zapytanie:”) nic nie wnosi, a dokłada szumu
        // do wyszukiwania w katalogu — zostaje pominięta. Notatka z treścią
        // jest zachowywana, co sprawdza osobny test.
        $this->assertStringContainsString('6 szt. wycieraczek gumowych', $clean);
        // nagłówek przekazania znika, żeby data i adresy nie trafiły do analizy
        $this->assertStringNotContainsString('Nadawca:', $clean);
        $this->assertStringNotContainsString('oferty@elektrosilver.pl', $clean);
        $this->assertStringNotContainsString('4 Aug 2026', $clean);
    }

    /**
     * Prawdziwy mail z produkcji (zapytanie #7): podpis handlowca stoi NAD treścią,
     * pod nim dwa przekazania. Wcześniej z całego maila zostawało „Pozdrawiam”.
     */
    public function test_twice_forwarded_mail_with_signature_on_top_keeps_the_inquiry(): void
    {
        $mail = implode("\n", [
            'Pozdrawiam',
            '-- ',
            'Artur Górniak',
            'PHT Supon Sp. z o.o. w Rzeszowie',
            '35-232 Rzeszów, ul. Miłocińska 17',
            'tel. 017 860 28 53, fax 017 863 08 10',
            'NIP: 813-22-83-737',
            '',
            '--- Treść przekazanej wiadomości ---',
            "Temat: \tFwd: OFERTA Skalmierzyce - Zakupy",
            "Data: \tTue, 1 Sep 2026 09:40:19 +0200",
            'Nadawca: 	Wojciech Dzierżak <marketing@supon.rzeszow.pl>',
            'Adresat: 	Artur - PHT Supon Rzeszów <artur@supon.rzeszow.pl>',
            '',
            '  zapytanie:',
            '',
            '--- Treść przekazanej wiadomości ---',
            "Temat: \tOFERTA Skalmierzyce - Zakupy",
            "Data: \tTue, 4 Aug 2026 13:09:32 +0200",
            'Nadawca: 	Wojciech Dzierżak <marketing@supon.rzeszow.pl>',
            'Adresat: 	oferty@elektrosilver.pl',
            '',
            '  Dzień dobry,',
            '',
            'W odpowiedzi, przesyłam ofertę na wybrane pozycje z zapytania:',
            '',
            ' 1. **(poz6)Wycieraczka gumowa:rozm:',
            '      40x60cm, c. netto......24,00 PLN/szt',
            '      50x100cm, c. netto...... 39,00 PLN/szt.',
            '  2.  (poz9). Łopata do śniegu,c. netto......97,00 PLN/szt.',
        ]);

        $clean = InquiryMailText::forAnalysis($mail);

        $this->assertStringContainsString('Wycieraczka gumowa', $clean);
        $this->assertStringContainsString('Łopata do śniegu', $clean);
        $this->assertStringContainsString('40x60cm', $clean);
        // podpis osoby przekazującej i jej dane firmowe nie wchodzą do analizy
        $this->assertStringNotContainsString('Miłocińska', $clean);
        $this->assertStringNotContainsString('813-22-83-737', $clean);
        $this->assertStringNotContainsString('Nadawca:', $clean);
        // „Pozdrawiam” samo w sobie to nie jest zapytanie
        $this->assertNotSame('Pozdrawiam', trim($clean));
    }

    public function test_note_of_the_person_forwarding_is_kept_when_it_says_something(): void
    {
        $mail = implode("\n", [
            'Proszę wycenić tylko pozycje 1 i 3, reszta odpada.',
            '',
            '--- Treść przekazanej wiadomości ---',
            'Temat: Zapytanie',
            'Nadawca: klient@firma.pl',
            '',
            'Dzień dobry, proszę o wycenę 10 szt. rękawic nitrylowych rozmiar 9.',
        ]);

        $clean = InquiryMailText::forAnalysis($mail);

        $this->assertStringContainsString('tylko pozycje 1 i 3', $clean);
        $this->assertStringContainsString('rękawic nitrylowych', $clean);
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

    /**
     * Prawdziwy mail (zapytanie Cederroth, 23.09.2026): stopka bez „Pozdrawiam” i bez
     * „-- ” stoi zaraz pod treścią. Telefon „600 903 483 <tel:…>” wchodził do analizy
     * i wracał z niej jako pozycja z ilością 600.
     */
    public function test_cuts_contact_footer_without_closing(): void
    {
        $clean = InquiryMailText::forAnalysis(self::cederrothMail());

        $this->assertStringContainsString('(nr 7251-7200) – 10 szt.', $clean);
        $this->assertStringContainsString('5szt./kompletów.', $clean);
        $this->assertStringContainsString('koszt dostawy', $clean);
        $this->assertStringNotContainsString('600 903 483', $clean);
        $this->assertStringNotContainsString('PL 62 1240', $clean);
        $this->assertStringNotContainsString('NIP:', $clean);
        // odcięta stopka zostaje do odczytu kontaktu
        $this->assertStringContainsString('600 903 483', InquiryMailText::footerOf(self::cederrothMail()));
    }

    public function test_contact_line_inside_the_inquiry_does_not_cut_the_order(): void
    {
        $mail = implode("\n", [
            'Dzień dobry,',
            'proszę o ofertę, w razie pytań proszę dzwonić:',
            'tel. 600 900 900',
            '10 szt. rękawic nitrylowych rozmiar 9',
            '',
            'Jan Kowalski',
            '600 903 483 <tel:+48600903483>',
            'NIP: 813-22-83-737',
        ]);

        $clean = InquiryMailText::forAnalysis($mail);

        $this->assertStringContainsString('10 szt. rękawic nitrylowych', $clean);
        // stopka pod ostatnią pozycją i tak odpada
        $this->assertStringNotContainsString('600 903 483', $clean);
        $this->assertStringNotContainsString('NIP:', $clean);
    }

    public function test_contact_lines_are_recognised_but_order_rows_are_not(): void
    {
        foreach ([
            '600 903 483 <tel:+48600903483> / (17) 860-28-49 <tel:+48178602849>',
            '(17) 860-28-49',
            '17 785 22 46',
            '+48 600 903 483',
            'tel. 17 785 22 46',
            'NIP: 813-22-83-737 | REGON: 690462358',
            'KRS: 0000063924 | Sąd Rejonowy w Rzeszowie',
            'PL 62 1240 1792 1111 0010 4150 7426',
            'lzielinski@supon.rzeszow.pl',
        ] as $line) {
            $this->assertTrue(InquiryMailText::isContactLine($line), $line);
        }

        foreach ([
            '10 szt. rękawic nitrylowych',
            '2. Płukanka do oczu Cederroth 2-pack 2 x butelka 500 ml (nr 725200)  -',
            '40-42 10 par',
            '100 200 par',
            '5901234123457 Rękawice nitrylowe',
        ] as $line) {
            $this->assertFalse(InquiryMailText::isContactLine($line), $line);
        }
    }

    public static function cederrothMail(): string
    {
        return implode("\n", [
            'Proszę o ofertę na: 1. Płukanka do oczu Cederroth 500 ml z uchwytem',
            'ściennym (nr 7251-7200) – 10 szt.',
            '',
            '2. Płukanka do oczu Cederroth 2-pack 2 x butelka 500 ml (nr 725200)  -',
            '5szt./kompletów.',
            '',
            'Chodzi mi o cenę w przypadku zamówienia jednej albo drugiej pozycji nie',
            'obu na raz.',
            '',
            'Proszę o rabat handlowy oraz przybliżony czas i koszt dostawy.',
            '',
            'Supon Rzeszów <https://www.supon.rzeszow.pl/>',
            'Łukasz Zieliński Obsługa sklepu internetowego',
            '☎',
            '   600 903 483 <tel:+48600903483> / (17) 860-28-49 <tel:+48178602849>',
            '',
            '@',
            '   lzielinski@supon.rzeszow.pl',
            '',
            '⌘',
            '   www.supon.rzeszow.pl <https://www.supon.rzeszow.pl/>',
            '',
            'Sprawdź:     Promocje <https://www.supon.rzeszow.pl/231-promocja>',
            'Outlet <https://www.supon.rzeszow.pl/259-outlet-bhp>      Blog',
            '<https://www.supon.rzeszow.pl/nowosci>',
            '',
            'Uwaga ! Zmiana numeru konta bankowego',
            'Od dnia 24.07.2026 płatności za zamówienia internetowe proszę kierować',
            'na nowy numer konta w banku PEKAO S.A :',
            'PL 62 1240 1792 1111 0010 4150 7426',
            '',
            '*PHT Supon Sp. z o.o.*',
            'ul. Miłocińska 17, 35-232 Rzeszów',
            'NIP: 813-22-83-737 | REGON: 690462358',
            'KRS: 0000063924 | Sąd Rejonowy w Rzeszowie',
            '',
            'Znajdź nas:',
            'f Facebook',
            '',
            '<https://www.facebook.com/supon.rzeszow/>',
            'in LinkedIn',
            '',
            '<https://pl.linkedin.com/company/supon-rzesz%C3%B3w>',
        ]);
    }

    public function test_forwarded_subject_is_the_clients_own(): void
    {
        $mail = implode("\n", [
            'Zobacz, proszę.',
            '--- Treść przekazanej wiadomości ---',
            "Temat: \tRe: rękawice",
            "Data: \tMon, 21 Sep 2026 10:00:00 +0000",
            '',
            'Notatka',
            '--- Treść przekazanej wiadomości ---',
            "Temat: \t11-571",
            "Nadawca: \tJan <jan@example.com>",
            '',
            'Czy ma Pani r. 11 40-50 par?',
        ]);

        $this->assertSame('11-571', InquiryMailText::forwardedSubject($mail));
        $this->assertNull(InquiryMailText::forwardedSubject("Temat: 11-571\nzwykły mail bez przekazania"));
    }
}
