<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\InquiryRequirements;
use Tests\TestCase;

/**
 * Warunki szczególne z wiersza zapytania: co wyciągamy z tekstu klienta i kiedy
 * uznajemy, że karta wyrobu to potwierdza.
 *
 * Miara jest jedna: potwierdzenie musi stać w karcie. Brak wzmianki to „karta
 * tego nie podaje” — i taki wyrób nie ma prawa wejść do listu jako odpowiedź.
 */
final class InquiryRequirementsTest extends TestCase
{
    private const QUOTE = '8szt Kombinezon chemoodporny ( w szczególności na kwas siarkowy 96%) '
        .'antyelektrostatyczny, rozmiar uniwersalny';

    /** Karta kombinezonu AlphaTec 5000 z katalogu — o kwasie ani słowa. */
    private const CARD_WITHOUT = 'Kombinezon ochronny AlphaTec 5000 to lekkie, a zarazem wytrzymałe odzież '
        .'ochronna przeznaczona do pracy w środowisku zagrożonym działaniem niebezpiecznych substancji '
        .'chemicznych. Czas przenikania dla 14 z 15 substancji chemicznych wymienionych w normie '
        .'EN ISO 6529 przekracza 480 minut. EN 1149-5 (antyelektrostatyczność).';

    public function test_reads_the_substance_with_its_concentration(): void
    {
        $found = InquiryRequirements::fromQuote(self::QUOTE);

        $this->assertNotSame([], $found);
        $this->assertSame('kwas siarkowy 96%', $found[0]['text']);
        $this->assertSame('96', $found[0]['percent']);
        $this->assertTrue($found[0]['checkable']);
        $this->assertContains('h2so4', $found[0]['needles']);
    }

    public function test_card_without_the_substance_confirms_nothing(): void
    {
        $found = InquiryRequirements::fromQuote(self::QUOTE);

        $this->assertSame(
            ['kwas siarkowy 96%'],
            InquiryRequirements::unconfirmed($found, self::CARD_WITHOUT),
        );
    }

    public function test_card_naming_the_substance_and_the_concentration_confirms(): void
    {
        $card = 'Kombinezon Tychem 6000 F. Czas przenikania: kwas siarkowy 96% — powyżej 480 min, '
            .'wodorotlenek sodu 40% — powyżej 480 min.';

        $this->assertSame([], InquiryRequirements::unconfirmed(
            InquiryRequirements::fromQuote(self::QUOTE),
            $card,
        ));
    }

    public function test_card_with_a_lower_concentration_does_not_confirm(): void
    {
        // „kwas siarkowy 78%” nie jest odpowiedzią na wymagane 96%.
        $card = 'Tabela przenikania: kwas siarkowy 78% — powyżej 480 min.';

        $this->assertSame(
            ['kwas siarkowy 96%'],
            InquiryRequirements::unconfirmed(InquiryRequirements::fromQuote(self::QUOTE), $card),
        );
    }

    public function test_card_naming_the_substance_without_any_concentration_does_not_confirm(): void
    {
        $card = 'Kombinezon odporny na kwas siarkowy i inne substancje chemiczne.';

        $this->assertSame(
            ['kwas siarkowy 96%'],
            InquiryRequirements::unconfirmed(InquiryRequirements::fromQuote(self::QUOTE), $card),
        );
    }

    public function test_formula_in_the_card_counts_as_the_substance(): void
    {
        $card = 'Odporność chemiczna: H2SO4 96% > 480 min, NaOH 40% > 480 min.';

        $this->assertSame([], InquiryRequirements::unconfirmed(
            InquiryRequirements::fromQuote(self::QUOTE),
            $card,
        ));
    }

    public function test_substance_without_concentration_is_confirmed_by_the_name_alone(): void
    {
        $found = InquiryRequirements::fromQuote('Rękawice odporne na aceton, rozmiar 9');

        $this->assertSame('aceton', $found[0]['text']);
        $this->assertNull($found[0]['percent']);
        $this->assertSame([], InquiryRequirements::unconfirmed($found, 'Odporne na aceton i etanol.'));
        $this->assertSame(['aceton'], InquiryRequirements::unconfirmed($found, 'Odporne na oleje i smary.'));
    }

    public function test_clause_without_a_known_substance_is_left_for_the_human(): void
    {
        $found = InquiryRequirements::fromQuote('Obuwie robocze, w szczególności do prac w kanalizacji');

        $this->assertCount(1, $found);
        $this->assertSame('do prac w kanalizacji', $found[0]['text']);
        $this->assertFalse($found[0]['checkable']);
        // Warunku nie do sprawdzenia maszynowo nie udajemy, że sprawdziliśmy.
        $this->assertSame([], InquiryRequirements::unconfirmed($found, 'Obuwie S3 do prac budowlanych.'));
    }

    public function test_clause_in_polish_quotes_is_not_lost(): void
    {
        // trim() zdejmował z „ bajty wspólne z półpauzą, a na zepsutym UTF-8 klauzula przepadała w całości
        $found = InquiryRequirements::fromQuote('Rękawice nitrylowe odporne na „olej napędowy”');

        $this->assertCount(1, $found);
        $this->assertSame('„olej napędowy”', $found[0]['text']);
        $this->assertFalse($found[0]['checkable']);
    }

    public function test_declensions_are_read_as_the_base_name(): void
    {
        $found = InquiryRequirements::fromQuote('Fartuch z odpornością na kwasu siarkowego 96%');

        $this->assertSame('kwas siarkowy 96%', $found[0]['text']);
    }

    public function test_line_without_any_requirement_gives_nothing(): void
    {
        $this->assertSame([], InquiryRequirements::fromQuote('30szt Rękawice nitrylowe rozmiar 9'));
        $this->assertSame([], InquiryRequirements::fromQuote(''));
    }
}
