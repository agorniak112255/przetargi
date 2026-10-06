<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\InquiryMailText;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Układ maila z produkcji (zapytanie #91, 02.10.2026; dane osobowe zmienione): klientka przekazała
     * Outlookiem („PD:”) własne zapytanie, a nad nim dopisała „Proszę o ofertę”. Outlook stawia nad każdą
     * wcześniejszą wiadomością „____” i blok „Od:/Wysłane:/Do:/Temat:” — ten sam co przy odpowiedzi. Całe
     * zapytanie odpadało jako cytat, model dostawał samo „Proszę o ofertę” i zapytanie miało zero pozycji.
     */
    public function test_outlook_forward_keeps_the_forwarded_inquiry(): void
    {
        $clean = InquiryMailText::forAnalysis(self::outlookForwardMail(), 'Zapytanie ofertowe 056709365');

        $this->assertStringContainsString('Bolle STKS 420 STK42N10E', $clean);
        $this->assertStringContainsString('Bolle Cobra COBPSI', $clean);
        $this->assertStringContainsString('Tryon TRYONN10E', $clean);
        $this->assertStringContainsString('Rush+ 2.0 XP RUSXMN10E', $clean);
        $this->assertStringContainsString('Numeru katalogowego producenta MPN', $clean);
        $this->assertStringContainsString('Proszę o ofertę.', $clean);
        // nagłówki Outlooka (adresy, daty) nie wchodzą do analizy
        $this->assertStringNotContainsString('Wysłane:', $clean);
        $this->assertStringNotContainsString('handel@supon.rzeszow.pl', $clean);
        $this->assertStringNotContainsString('Pozdrawiam/Regards', $clean);
        $this->assertSame('Zapytanie ofertowe 056709365', InquiryMailText::forwardedSubject(self::outlookForwardMail()));
    }

    /** Temat samego maila z „PD:” — pod jedynym blokiem Outlooka jest przekazane zapytanie, nie cytat. */
    public function test_outlook_forward_recognised_by_the_mail_subject(): void
    {
        $mail = implode("\n", [
            'Przesyłam zapytanie od kolegi z działu utrzymania ruchu, proszę o szybką wycenę.',
            '',
            '________________________________',
            'Od: Jan Kowalski <jan.kowalski@firma.pl>',
            'Wysłane: środa, 30 września 2026 08:15',
            'Do: Anna Nowak <anna.nowak@firma.pl>',
            'Temat: Rękawice',
            '',
            'Potrzebujemy 40 par rękawic nitrylowych rozmiar 9.',
        ]);

        $this->assertStringContainsString('40 par rękawic nitrylowych', InquiryMailText::forAnalysis($mail, 'PD: Rękawice'));
        // bez „PD:” w temacie to zwykła odpowiedź z cytatem — cytat dalej odpada
        $this->assertStringNotContainsString('40 par rękawic nitrylowych', InquiryMailText::forAnalysis($mail, 'RE: Rękawice'));
        $this->assertStringNotContainsString('40 par rękawic nitrylowych', InquiryMailText::forAnalysis($mail));
    }

    /**
     * Odpowiedź Outlookiem z cytatem dawnego przekazania niżej: liczy się nowa treść, a przekazanie sprzed
     * odpowiedzi to historia — jego pozycje nie mogą wrócić do analizy.
     */
    public function test_outlook_reply_does_not_pull_forward_from_quoted_history(): void
    {
        $mail = implode("\n", [
            'Dzień dobry,',
            'proszę jeszcze o 5 szt. kasków ochronnych w kolorze białym, z dostawą do magazynu.',
            '',
            '________________________________',
            'Od: Handel - Supon <handel@supon.rzeszow.pl>',
            'Wysłane: wtorek, 29 września 2026 10:00',
            'Do: Anna Nowak <anna.nowak@firma.pl>',
            'Temat: RE: PD: Okulary',
            '',
            'W załączeniu oferta.',
            '',
            '________________________________',
            'Od: Anna Nowak <anna.nowak@firma.pl>',
            'Wysłane: poniedziałek, 28 września 2026 09:00',
            'Do: Handel - Supon <handel@supon.rzeszow.pl>',
            'Temat: PD: Okulary',
            '',
            '________________________________',
            'Od: Jan Kowalski <jan.kowalski@firma.pl>',
            'Wysłane: poniedziałek, 28 września 2026 08:00',
            'Do: Anna Nowak <anna.nowak@firma.pl>',
            'Temat: Okulary',
            '',
            '20 szt. okularów ochronnych bezbarwnych.',
        ]);

        $clean = InquiryMailText::forAnalysis($mail, 'RE: Okulary');

        $this->assertStringContainsString('5 szt. kasków ochronnych', $clean);
        $this->assertStringNotContainsString('okularów ochronnych', $clean);
        $this->assertStringNotContainsString('W załączeniu oferta', $clean);
        // z adresem klientki wynik ten sam: pierwszy blok napisał handlowiec, więc to cytat naszej odpowiedzi
        $this->assertSame($clean, InquiryMailText::forAnalysis($mail, 'RE: Okulary', 'anna.nowak@firma.pl'));
    }

    /** „Fwd:” w temacie należy do nagłówka Thunderbirda — cytat odpowiedzi Outlooka w przekazanym mailu dalej odpada. */
    public function test_forward_subject_is_consumed_by_explicit_forward_header(): void
    {
        $mail = implode("\n", [
            'Do wyceny, proszę o pilną odpowiedź do klienta jeszcze dzisiaj.',
            '',
            '--- Treść przekazanej wiadomości ---',
            'Temat: RE: Kaski',
            'Nadawca: Jan Kowalski <jan.kowalski@firma.pl>',
            '',
            'Dzień dobry, proszę o wycenę 12 szt. kasków ochronnych z regulacją pokrętłem.',
            '',
            '________________________________',
            'Od: Handel - Supon <handel@supon.rzeszow.pl>',
            'Wysłane: wtorek, 29 września 2026 10:00',
            'Do: Jan Kowalski <jan.kowalski@firma.pl>',
            'Temat: Kaski',
            '',
            'Stara oferta: 7 szt. okularów ochronnych.',
        ]);

        $clean = InquiryMailText::forAnalysis($mail, 'Fwd: RE: Kaski');

        $this->assertStringContainsString('12 szt. kasków ochronnych', $clean);
        $this->assertStringNotContainsString('okularów ochronnych', $clean);
    }

    /**
     * Układ maila z produkcji (zapytanie #93, 06.10.2026; dane osobowe zmienione): klientka ponagla
     * („Czy otrzymam ofertę?”) nad własną odpowiedzią „ODP:” do własnego przekazania „PD:” z listą pozycji.
     * Pierwszy blok Outlooka bez „PD:” był brany za cytat naszej odpowiedzi — model dostał samo ponaglenie
     * i zapytanie miało zero pozycji. Blok od nadawcy maila w tym samym wątku to jego wcześniejsza wiadomość.
     */
    public function test_client_follow_up_over_own_thread_keeps_the_original_inquiry(): void
    {
        $mail = self::clientFollowUpMail();
        $subject = 'Zapytanie ofertowe 056709365';
        // adres z nagłówka From małymi literami, w bloku „Od:” z wielkimi — porównanie bez wielkości liter
        $clean = InquiryMailText::forAnalysis($mail, $subject, 'anna.nowak@firma.pl');

        $this->assertStringContainsString('Czy otrzymam ofertę?', $clean);
        $this->assertStringContainsString('Proszę o ofertę.', $clean);
        $this->assertStringContainsString('Bolle STKS 420 STK42N10E', $clean);
        $this->assertStringContainsString('Bolle Cobra COBPSI', $clean);
        $this->assertStringContainsString('Tryon TRYONN10E', $clean);
        $this->assertStringContainsString('Rush+ 2.0 XP RUSXMN10E', $clean);
        // nagłówki Outlooka (nasze adresy, daty) nie wchodzą do analizy
        $this->assertStringNotContainsString('Wysłane:', $clean);
        $this->assertStringNotContainsString('@supon.rzeszow.pl', $clean);
        $this->assertStringNotContainsString('Pozdrawiam/Regards', $clean);

        // temat nadany przez klientkę, nie „ODP: …” z bloku ponaglenia
        $this->assertSame($subject, InquiryMailText::forwardedSubject($mail, $subject, 'anna.nowak@firma.pl'));
        // stopka to podpis spod oryginału, a nie cała historia z adresami handlowców
        $footer = InquiryMailText::footerOf($mail, $subject, 'anna.nowak@firma.pl');
        $this->assertStringContainsString('Pozdrawiam/Regards', $footer);
        $this->assertStringNotContainsString('@supon.rzeszow.pl', $footer);
    }

    /** Bez nadawcy, od innego adresu albo w innym wątku blok dalej jest cytatem — zachowanie jak dotąd. */
    public function test_follow_up_rule_needs_the_same_sender_and_thread(): void
    {
        $mail = self::clientFollowUpMail();
        $subject = 'Zapytanie ofertowe 056709365';

        foreach ([
            'brak nadawcy (nasza skrzynka, formularz)' => [$subject, null],
            'kolega z tej samej firmy' => [$subject, 'jan.kowalski@firma.pl'],
            'nowe zapytanie nad starym wątkiem' => ['Kaski', 'anna.nowak@firma.pl'],
        ] as $case => [$mailSubject, $sender]) {
            $clean = InquiryMailText::forAnalysis($mail, $mailSubject, $sender);

            $this->assertStringContainsString('Czy otrzymam ofertę?', $clean, $case);
            $this->assertStringNotContainsString('STK42N10E', $clean, $case);
            $this->assertStringNotContainsString('Proszę o ofertę.', $clean, $case);
        }
    }

    /**
     * Ponaglenie nad własnym dopiskiem, a pod nim nasza oferta: dopisek klienta zostaje, a od naszego bloku
     * (adres klienta stoi w nim w „Do:”, nie w „Od:”) to już historia — stara lista nie wraca do analizy.
     */
    public function test_follow_up_stops_at_our_reply_in_the_thread(): void
    {
        $mail = implode("\n", [
            'Dzień dobry, czy oferta jest już gotowa?',
            '',
            'Pozdrawiam',
            'Anna Nowak',
            'tel. 600 100 200',
            '________________________________',
            'Od: Nowak, Anna <anna.nowak@firma.pl>',
            'Wysłane: środa, 30 września 2026 09:00',
            'Do: Handel - Supon <handel@supon.rzeszow.pl>',
            'Temat: RE: Okulary',
            '',
            'Proszę jeszcze dopisać 5 szt. kasków ochronnych białych.',
            '',
            '________________________________',
            'Od: Handel - Supon <handel@supon.rzeszow.pl>',
            'Wysłane: wtorek, 29 września 2026 10:00',
            'Do: Nowak, Anna <anna.nowak@firma.pl>',
            'Temat: RE: Okulary',
            '',
            'W załączeniu oferta.',
            '',
            '________________________________',
            'Od: Nowak, Anna <anna.nowak@firma.pl>',
            'Wysłane: poniedziałek, 28 września 2026 08:00',
            'Do: Handel - Supon <handel@supon.rzeszow.pl>',
            'Temat: Okulary',
            '',
            '20 szt. okularów ochronnych bezbarwnych.',
        ]);

        $clean = InquiryMailText::forAnalysis($mail, 'RE: Okulary', 'anna.nowak@firma.pl');

        $this->assertStringContainsString('czy oferta jest już gotowa', $clean);
        $this->assertStringContainsString('5 szt. kasków ochronnych', $clean);
        $this->assertStringNotContainsString('W załączeniu oferta', $clean);
        $this->assertStringNotContainsString('okularów ochronnych', $clean);
        // podpis ponaglenia to ten sam klient — idzie do stopki (kontakt), nie do analizy
        $this->assertStringNotContainsString('600 100 200', $clean);
        $footer = InquiryMailText::footerOf($mail, 'RE: Okulary', 'anna.nowak@firma.pl');
        $this->assertStringContainsString('tel. 600 100 200', $footer);
        // nasza oferta pod dopiskiem to cytat — nie stopka klienta
        $this->assertStringNotContainsString('@supon.rzeszow.pl', $footer);
        $this->assertStringNotContainsString('W załączeniu oferta', $footer);
    }

    /**
     * Zwykła odpowiedź z cytatem (zapytania #91 i #93, 06.10.2026): stopka sięgała od podpisu do końca maila,
     * więc do kontaktu wchodziły adresy handlowców z „Do:” i numer zapytania z „Temat:” jako telefon.
     * Stopka kończy się na początku cytatu — w każdej postaci, w jakiej programy pocztowe go zaczynają.
     *
     * @return iterable<string, array{0: list<string>}>
     */
    public static function quoteHeaders(): iterable
    {
        yield 'Outlook' => [[
            '________________________________',
            'Od: Handel - Supon <handel@supon.rzeszow.pl>',
            'Wysłane: czwartek, 1 października 2026 11:05',
            'Do: Nowak, Anna <anna.nowak@firma.pl>',
            'DW: Izabela - Supon <izabela@supon.rzeszow.pl>',
            'Temat: RE: Zapytanie ofertowe 056709365',
        ]];
        yield 'Original Message' => [[
            '-----Original Message-----',
            'From: Handel - Supon <handel@supon.rzeszow.pl>',
            'Sent: Thursday, October 1, 2026 11:05 AM',
            'Subject: RE: Zapytanie ofertowe 056709365',
        ]];
        yield 'W dniu … napisał bez „>”' => [[
            'W dniu 1.10.2026 o 11:05, Handel - Supon <handel@supon.rzeszow.pl> napisał(a):',
        ]];
        yield 'Thunderbird „pisze:”' => [[
            'W dniu 1.10.2026 o 11:05, Handel - Supon pisze:',
        ]];
        // układ z zapytania #83
        yield 'wstęp zawinięty na dwie linie' => [[
            'W dniu 1.10.2026',
            'o 11:05, Handel - Supon pisze:',
        ]];
        yield 'Gmail: „napisał(a):” w drugiej linii' => [[
            'W dniu czw., 1 paź 2026 o 11:05 Handel - Supon <handel@supon.rzeszow.pl>',
            'napisał(a):',
        ]];
        yield 'sam blok nagłówków' => [[
            'From: Handel - Supon <handel@supon.rzeszow.pl>',
            'Sent: Thursday, October 1, 2026 11:05 AM',
            'Subject: RE: Zapytanie ofertowe 056709365',
        ]];
    }

    /**
     * @param  list<string>  $quoteHeader
     */
    #[DataProvider('quoteHeaders')]
    public function test_footer_stops_where_the_quote_begins(array $quoteHeader): void
    {
        $mail = implode("\n", [
            'Dzień dobry,',
            'proszę jeszcze o 5 szt. kasków ochronnych białych.',
            '',
            'Pozdrawiam',
            'Anna Nowak',
            'tel. 600 100 200',
            ...$quoteHeader,
            '',
            'W załączeniu oferta.',
            '',
            'Pozdrawiam',
            'Jan Handlowiec',
            'PHT Supon Sp. z o.o.',
            'ul. Miłocińska 17, 35-232 Rzeszów',
            'tel. 017 860 28 53',
        ]);

        $footer = InquiryMailText::footerOf($mail, 'RE: Zapytanie ofertowe 056709365', 'anna.nowak@firma.pl');

        $this->assertSame("Pozdrawiam\nAnna Nowak\ntel. 600 100 200", $footer);
        // treść do analizy bez zmian: nowa wiadomość zostaje, cytat odpada
        $clean = InquiryMailText::forAnalysis($mail, 'RE: Zapytanie ofertowe 056709365', 'anna.nowak@firma.pl');
        $this->assertStringContainsString('5 szt. kasków ochronnych', $clean);
        $this->assertStringNotContainsString('W załączeniu oferta', $clean);
    }

    /** Podpis pod „-- ” to stopka nadawcy — kończy się dopiero na cytacie pod nim. */
    public function test_signature_under_double_dash_stays_in_the_footer_up_to_the_quote(): void
    {
        $mail = implode("\n", [
            'Dzień dobry,',
            'proszę jeszcze o 5 szt. kasków ochronnych białych.',
            '-- ',
            'Anna Nowak',
            'FIRMA Sp. z o.o.',
            'tel. 600 100 200',
            '________________________________',
            'Od: Handel - Supon <handel@supon.rzeszow.pl>',
            'Wysłane: czwartek, 1 października 2026 11:05',
            'Do: Nowak, Anna <anna.nowak@firma.pl>',
            'Temat: RE: Zapytanie ofertowe 056709365',
            '',
            'W załączeniu oferta.',
        ]);

        $footer = InquiryMailText::footerOf($mail, 'RE: Zapytanie ofertowe 056709365');

        $this->assertSame("-- \nAnna Nowak\nFIRMA Sp. z o.o.\ntel. 600 100 200", $footer);
    }

    public static function clientFollowUpMail(): string
    {
        $forward = self::outlookForwardMail();
        // pod „ODP:” stoi samo „Proszę o ofertę.”, bez podpisu — dalej przekazanie i oryginał jak w #91
        $history = substr($forward, (int) strpos($forward, '________________________________'));

        return implode("\n", [
            'Dzień dobry,',
            'Czy otrzymam ofertę? Proszę o informację.',
            '',
            'Anna ',
            'Nowak',
            'Buyer I - Integrated Supply',
            'P:+48 (500) 100-200',
            'A:ul Przykładowa 1, Rzeszow, Podkarpackie, 35-001',
            'E:Anna.Nowak@firma.pl',
            '________________________________',
            'Od: Nowak, Anna <Anna.Nowak@firma.pl>',
            'Wysłane: piątek, 2 października 2026 10:32',
            'Do: Izabela - Supon <izabela@supon.rzeszow.pl>; Handel - Supon Rzeszów <handel@supon.rzeszow.pl>',
            'Temat: ODP: Zapytanie ofertowe 056709365',
            '',
            'Dzień dobry,',
            'Proszę o ofertę.',
            $history,
        ]);
    }

    public static function outlookForwardMail(): string
    {
        return implode("\n", [
            'Dzień dobry,',
            'Proszę o ofertę.',
            '',
            'Anna ',
            'Nowak',
            'Buyer I - Integrated Supply',
            'P:+48 (500) 100-200',
            'A:ul Przykładowa 1, Rzeszow, Podkarpackie, 35-001',
            'E:Anna.Nowak@firma.pl',
            '________________________________',
            'Od: Nowak, Anna <Anna.Nowak@firma.pl>',
            'Wysłane: czwartek, 1 października 2026 13:16',
            'Do: Izabela - Supon <izabela@supon.rzeszow.pl>',
            'Temat: PD: Zapytanie ofertowe 056709365',
            '',
            '________________________________',
            'Od: Nowak, Anna <Anna.Nowak@firma.pl>',
            'Wysłane: czwartek, 1 października 2026 09:42',
            'Do: Handel - Supon Rzeszów <handel@supon.rzeszow.pl>',
            'Temat: Zapytanie ofertowe 056709365',
            '',
            'Dzień dobry,',
            '',
            'Proszę o przesłanie oferty cenowej na poniższe pozycje:',
            '',
            'ILOŚĆ',
            '',
            ' 1',
            '',
            ' Okulary z osłonami bocznymi Bolle STKS 420 STK42N10E - czarna oprawka',
            '',
            '20',
            '',
            ' 2',
            '',
            ' Okulary ochronne ASG Bolle Cobra COBPSI - bezbarwne, nieparujące',
            '',
            '20',
            '',
            ' 3',
            '',
            'Okulary nieparujące Bolle Tryon TRYONN10E - sportowy i lekki design',
            '',
            '20',
            '',
            ' 4',
            '',
            ' Gogle Bolle Rush+ 2.0 XP RUSXMN10E - bezbarwne z paskiem',
            '',
            '30',
            '',
            'Dostawa do zakładu w Kaliszu',
            '',
            'Proszę o udzielenie rabatu, ponieważ pozycje będą odsprzedawane i o zaznaczenie tego w ofercie.',
            '',
            'Proszę również o podanie:',
            '',
            '  1.  Numeru katalogowego producenta MPN (Manufacturer Part Number)',
            '  2.  Terminu realizacji',
            '  3.  Warunków oraz kosztów dostawy',
            '  4.  Formy oraz warunków płatności (30, 60-cio dniowy, odroczony termin płatności jest warunkiem preferowanym).',
            '  5.  Dodatkowych opłat oraz informacji niezbędnych do realizacji zamówienia.',
            '',
            'Będę wdzięczna za przesłanie wszystkich informacji w jak najkrótszym terminie.',
            '',
            'Dziękuję.',
            '',
            'Pozdrawiam/Regards',
        ]);
    }
}
