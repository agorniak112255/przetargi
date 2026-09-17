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
