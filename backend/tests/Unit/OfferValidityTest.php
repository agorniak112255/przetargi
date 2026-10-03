<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OfferValidity;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OfferValidityTest extends TestCase
{
    /**
     * Odpowiedź wysłana w czwartek 1.10.2026 o 9:00 czasu polskiego.
     *
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function texts(): array
    {
        return [
            'sama liczba' => ['14', '2026-10-15'],
            'dni' => ['14 dni', '2026-10-15'],
            'dzień' => ['1 dzień', '2026-10-02'],
            'dnia' => ['7 dnia', '2026-10-08'],
            'kalendarzowych' => ['30 dni kalendarzowych', '2026-10-31'],
            'wielkie litery i kropka' => ['  Oferta ważna 30 DNI. ', '2026-10-31'],
            'przez' => ['ważna przez 14 dni', '2026-10-15'],
            'od daty oferty' => ['30 dni od daty wystawienia oferty', '2026-10-31'],
            // czwartek + 5 dni roboczych: pt, pn, wt, śr, czw
            'dni robocze' => ['5 dni roboczych', '2026-10-08'],
            'dzień roboczy' => ['1 dzień roboczy', '2026-10-02'],
            'dni robocze przez weekend' => ['2 dni robocze', '2026-10-05'],
            'tygodnie' => ['2 tygodnie', '2026-10-15'],
            'tydzień' => ['tydzień', '2026-10-08'],
            'tygodni' => ['6 tygodni', '2026-11-12'],
            'miesiąc' => ['miesiąc', '2026-11-01'],
            'miesiące' => ['3 miesiące', '2027-01-01'],
            'miesięcy' => ['6 miesięcy', '2027-04-01'],
            'data' => ['31.10.2026', '2026-10-31'],
            'data z do i r.' => ['do 31.10.2026 r.', '2026-10-31'],
            'data krótka' => ['5.1.2027', '2027-01-05'],
            'data z ukośnikami' => ['15/11/2026', '2026-11-15'],
            'dzień odpowiedzi' => ['01.10.2026', '2026-10-01'],
            // niejednoznaczne albo poza zasadami — bez zgadywania
            'pusty' => ['', null],
            'null' => [null, null],
            'do odwołania' => ['do odwołania', null],
            'do wyczerpania zapasów' => ['do wyczerpania zapasów', null],
            'zero dni' => ['0 dni', null],
            'tekst przed liczbą' => ['około 14 dni', null],
            'dwie liczby' => ['14-30 dni', null],
            'nieistniejąca data' => ['31.02.2027', null],
            'data sprzed odpowiedzi' => ['30.09.2026', null],
            'ponad rok' => ['400 dni', null],
            'ponad rok w miesiącach' => ['13 miesięcy', null],
            'data za ponad rok' => ['02.10.2027', null],
            'rok dokładnie' => ['365 dni', '2027-10-01'],
        ];
    }

    #[DataProvider('texts')]
    public function test_validity_text_gives_last_valid_day(?string $text, ?string $expected): void
    {
        $repliedAt = CarbonImmutable::parse('2026-10-01 07:00:00', 'UTC');

        $until = OfferValidity::until($text, $repliedAt);

        $this->assertSame($expected, $until?->format('Y-m-d'));
        if ($until !== null) {
            $this->assertSame('Europe/Warsaw', $until->getTimezone()->getName());
            $this->assertSame('00:00:00', $until->format('H:i:s'));
        }
    }

    public function test_reply_day_is_the_polish_calendar_day(): void
    {
        // 23:30 UTC 30 września = 1:30 czasu polskiego 1 października
        $this->assertSame('2026-10-15', OfferValidity::until('14', CarbonImmutable::parse('2026-09-30 23:30:00', 'UTC'))?->format('Y-m-d'));
    }
}
