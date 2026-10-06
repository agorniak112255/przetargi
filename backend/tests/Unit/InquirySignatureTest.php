<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\InquirySignature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InquirySignatureTest extends TestCase
{
    public function test_reads_polish_company_footer_without_separator(): void
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
            'www.proferis.pl',
            'Uwaga: Wiadomość przeznaczona jest tylko dla jej adresata.',
        ]);

        $contact = InquirySignature::extract($mail);

        $this->assertNotNull($contact);
        $this->assertSame('Mateusz Baniak', $contact['person']);
        $this->assertSame('PROFERIS', $contact['company']);
        // adres z `mailto:` liczy się raz
        $this->assertSame(['pomoc@proferis.pl'], $contact['emails']);
        // numer zapisany tak, jak stoi w mailu — bez normalizacji
        $this->assertSame(['17 785 22 46'], $contact['phones']);
        $this->assertSame('Al. gen. L. Okulickiego 18, 35-206 Rzeszów', $contact['address']);
        $this->assertSame('www.proferis.pl', $contact['website']);
        $this->assertStringStartsWith('Pozdrawiam,', $contact['raw']);
        // treść zapytania nie jest częścią stopki
        $this->assertStringNotContainsString('20 szt. rękawic', $contact['raw']);

        $this->assertEveryValueIsInRaw($contact);
    }

    public function test_reads_footer_after_double_dash_with_address_and_mobile(): void
    {
        $mail = implode("\n", [
            'Dzień dobry,',
            '',
            'proszę o wycenę 10 szt. rękawic nitrylowych rozmiar 9.',
            '',
            'Pozdrawiam',
            'Jan Kowalski',
            '-- ',
            'SUPON S.A.',
            'ul. Przemysłowa 12',
            '35-001 Rzeszów',
            'tel. +48 500 123 456',
        ]);

        $contact = InquirySignature::extract($mail);

        $this->assertNotNull($contact);
        $this->assertSame('Jan Kowalski', $contact['person']);
        $this->assertSame('SUPON S.A.', $contact['company']);
        $this->assertSame(['+48 500 123 456'], $contact['phones']);
        $this->assertSame('ul. Przemysłowa 12, 35-001 Rzeszów', $contact['address']);
        $this->assertSame([], $contact['emails']);
        $this->assertNull($contact['website']);

        $this->assertEveryValueIsInRaw($contact);
    }

    public function test_sender_address_goes_first_but_is_never_added(): void
    {
        $mail = implode("\n", [
            'Dzień dobry,',
            '',
            'proszę o wycenę 15 szt. kasków ochronnych.',
            '',
            'Pozdrawiam,',
            'Anna Nowak',
            'biuro@firma.pl',
            'anna.nowak@firma.pl',
        ]);

        $contact = InquirySignature::extract($mail, 'anna.nowak@firma.pl');
        $this->assertNotNull($contact);
        $this->assertSame(['anna.nowak@firma.pl', 'biuro@firma.pl'], $contact['emails']);

        // adres nadawcy spoza stopki nie jest do niej dopisywany
        $other = InquirySignature::extract($mail, 'sekretariat@inna.pl');
        $this->assertNotNull($other);
        $this->assertSame(['biuro@firma.pl', 'anna.nowak@firma.pl'], $other['emails']);
    }

    public function test_mail_without_footer_gives_null(): void
    {
        $mail = "Dzień dobry,\n\nproszę o wycenę 30 szt. kamizelek ostrzegawczych.";

        $this->assertNull(InquirySignature::extract($mail));
    }

    public function test_confidentiality_clause_alone_gives_null(): void
    {
        $mail = implode("\n", [
            'Dzień dobry,',
            '',
            'proszę o wycenę 15 szt. kasków ochronnych.',
            '',
            'Uwaga: Wiadomość przeznaczona jest tylko dla jej adresata.',
            'Jeżeli otrzymali Państwo tę wiadomość przez pomyłkę, prosimy o jej usunięcie.',
        ]);

        $this->assertNull(InquirySignature::extract($mail));
    }

    public function test_company_without_person_leaves_person_null(): void
    {
        $mail = implode("\n", [
            'Dzień dobry,',
            '',
            'proszę o wycenę 12 szt. okularów ochronnych.',
            '',
            '-- ',
            'Dział Zakupów',
            'ELEKTRO SILVER Sp. z o.o.',
            'NIP 813 33 11 222',
        ]);

        $contact = InquirySignature::extract($mail);

        $this->assertNotNull($contact);
        // „Dział Zakupów” to nie nazwisko — wolimy null niż strzał
        $this->assertNull($contact['person']);
        $this->assertSame('ELEKTRO SILVER Sp. z o.o.', $contact['company']);
        // NIP nie jest telefonem
        $this->assertSame([], $contact['phones']);
    }

    public function test_quoted_previous_message_is_not_taken_for_a_footer(): void
    {
        $mail = implode("\n", [
            'Dzień dobry,',
            '',
            'potrzebuję jeszcze 20 szt. okularów ochronnych.',
            '',
            'W dniu 2026-09-10 o 10:12, Anna Nowak napisał(a):',
            '> Pozdrawiam,',
            '> Anna Nowak',
            '> anna@inna-firma.pl',
            '> tel. 600 700 800',
        ]);

        $contact = InquirySignature::extract($mail);

        // dane z cudzej, cytowanej wiadomości nie są kontaktem nadawcy
        $this->assertNull($contact);
    }

    /**
     * Układ z produkcji (zapytania #91 i #93, 06.10.2026; dane osobowe zmienione): klientka odpisuje Outlookiem
     * nad naszą ofertą. Stopka sięgała do końca maila, więc kontakt dostawał adresy handlowców z „Do:/DW:”,
     * nasz podpis z cytatu i numer zapytania z „Temat:” jako telefon.
     */
    public function test_reply_over_outlook_quote_reads_only_the_senders_footer(): void
    {
        $mail = implode("\n", [
            'Dzień dobry,',
            'proszę jeszcze o 5 szt. kasków ochronnych białych.',
            '',
            'Pozdrawiam',
            'Anna Nowak',
            'anna.nowak@firma.pl',
            'tel. 600 100 200',
            '________________________________',
            'Od: Handel - Supon <handel@supon.rzeszow.pl>',
            'Wysłane: czwartek, 1 października 2026 11:05',
            'Do: Nowak, Anna <anna.nowak@firma.pl>',
            'DW: Izabela - Supon <izabela@supon.rzeszow.pl>',
            'Temat: RE: Zapytanie ofertowe 056709365',
            '',
            'W załączeniu oferta.',
            '',
            'Pozdrawiam',
            'Jan Handlowiec',
            'PHT Supon Sp. z o.o.',
            'ul. Miłocińska 17, 35-232 Rzeszów',
            'tel. 017 860 28 53',
        ]);

        $contact = InquirySignature::extract($mail, 'anna.nowak@firma.pl', 'RE: Zapytanie ofertowe 056709365', 'anna.nowak@firma.pl');

        $this->assertNotNull($contact);
        $this->assertSame('Anna Nowak', $contact['person']);
        $this->assertSame(['anna.nowak@firma.pl'], $contact['emails']);
        $this->assertSame(['600 100 200'], $contact['phones']);
        $this->assertNull($contact['company']);
        $this->assertNull($contact['address']);
        $this->assertStringNotContainsString('Temat:', $contact['raw']);

        $this->assertEveryValueIsInRaw($contact);
    }

    /**
     * Ponaglenie #93 bez rozpoznanego nadawcy wątku (tak liczył się kontakt przed 4c7b889): pierwszy blok
     * Outlooka to cytat. Nad nim nie ma rozpoznawalnej stopki, więc kontaktu nie ma — zamiast adresów
     * handlowców i numeru zapytania z historii.
     */
    public function test_follow_up_without_thread_sender_takes_nothing_from_the_quoted_history(): void
    {
        $subject = 'Zapytanie ofertowe 056709365';

        $this->assertNull(InquirySignature::extract(InquiryMailTextTest::clientFollowUpMail(), 'anna.nowak@firma.pl', $subject));
    }

    /**
     * Układ z produkcji (zapytanie #83, 06.10.2026): Thunderbird cytuje naszą ofertę bez „>”, a klient nie
     * podpisał się nad cytatem. Pierwszy zwrot grzecznościowy stoi dopiero w cytacie — to podpis handlowca,
     * nie kontakt klienta.
     */
    public function test_closing_inside_unmarked_quote_is_not_the_senders_footer(): void
    {
        $mail = implode("\n", [
            'Dzień dobry,',
            'czy oferta na kaski jest już gotowa?',
            '',
            'W dniu 1.10.2026',
            'o 11:05, Handel - Supon pisze:',
            'W załączeniu oferta.',
            '',
            'Pozdrawiam',
            'Jan Handlowiec',
            'PHT Supon Sp. z o.o.',
            'tel. 017 860 28 53',
        ]);

        $this->assertNull(InquirySignature::extract($mail, 'anna.nowak@firma.pl', 'RE: Kaski', 'anna.nowak@firma.pl'));
    }

    /**
     * @return iterable<string, array{0: string, 1: string|null, 2: string|null}>
     */
    public static function fromHeaders(): iterable
    {
        yield 'nazwa i adres' => ['Jan Kowalski <jan@firma.pl>', 'Jan Kowalski', 'jan@firma.pl'];
        yield 'sam adres' => ['jan@firma.pl', null, 'jan@firma.pl'];
        yield 'nazwa w cudzysłowie' => ['"Kowalski, Jan" <jan@firma.pl>', 'Kowalski, Jan', 'jan@firma.pl'];
        yield 'adres w cudzysłowie' => ['"jan@firma.pl" <jan@firma.pl>', null, 'jan@firma.pl'];
        yield 'puste' => ['', null, null];
        yield 'sama nazwa' => ['Jan Kowalski', 'Jan Kowalski', null];
    }

    #[DataProvider('fromHeaders')]
    public function test_splits_from_header(string $from, ?string $name, ?string $email): void
    {
        $this->assertSame(['name' => $name, 'email' => $email], InquirySignature::splitFrom($from));
    }

    /**
     * Wymóg jakości danych: każda wartość w kontakcie musi dać się odnaleźć
     * w surowym bloku stopki.
     *
     * @param  array<string, mixed>  $contact
     */
    private function assertEveryValueIsInRaw(array $contact): void
    {
        $raw = (string) preg_replace('/\s+/u', ' ', (string) $contact['raw']);

        foreach (['person', 'company', 'website'] as $key) {
            if ($contact[$key] !== null) {
                $this->assertStringContainsString((string) $contact[$key], $raw, $key.' nie ma w stopce');
            }
        }

        foreach ([...$contact['emails'], ...$contact['phones']] as $value) {
            $this->assertStringContainsString($value, $raw, $value.' nie ma w stopce');
        }

        // adres bywa sklejony z segmentów — każdy z nich musi być w stopce
        foreach (explode(', ', (string) $contact['address']) as $part) {
            $this->assertStringContainsString($part, $raw, $part.' nie ma w stopce');
        }
    }
}
