<?php

declare(strict_types=1);

namespace Tests\Unit\RequirementCheck;

use App\Support\RequirementCheck\En407Code;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class En407CodeTest extends TestCase
{
    #[Test]
    public function reads_six_position_code(): void
    {
        $code = En407Code::first('EN 388:2016+A1:2018 (1X42C), EN 407:2020 (X1XXXX), EN ISO 21420:2020');

        $this->assertSame('X1XXXX', $code?->text);
        $this->assertSame('1', $code->levels['contact']);
    }

    #[Test]
    public function accepts_truncated_dotted_code(): void
    {
        // 11202000: karta podaje pięć pozycji — szóstej nie dopisujemy
        $code = En407Code::first('EN 420:2003 + A1:2009, EN 388:2016 (2.X.4.2.C), EN 407 (X.1.X.X.X)');

        $this->assertSame('X1XXX-', $code?->canonical());
        $this->assertNull($code->levels['large_splash']);
    }

    #[Test]
    public function reads_code_after_norm_title_and_without_space(): void
    {
        $this->assertSame('X1XXXX', En407Code::first('EN 407:2004 - Rękawice chroniące przed zagrożeniami termicznymi (X1XXXX)')?->text);
        $this->assertSame('X1XXX-', En407Code::first('Standards: EN 420:2003 + A1:2009, Cat.III EN 407(X.1.X.X.X), EN388 (2.X.4.2.C),')?->canonical());
    }

    #[Test]
    public function digits_later_in_the_text_are_not_a_code(): void
    {
        // recenzja: „EN 407. Opakowanie 1200 szt” dawało kod 1200 i ok dla X1XXXX
        $this->assertNull(En407Code::first('EN 407. Opakowanie 1200 szt'));
        $this->assertNull(En407Code::first('Zgodne z EN 407, nr kat. 2143'));
    }

    #[Test]
    public function reads_worded_contact_heat_as_second_position(): void
    {
        $code = En407Code::first('EN 407 – odporność na ciepło kontaktowe poziom 1 (do 100°C).');

        $this->assertSame('-1----', $code?->canonical());
        $this->assertTrue($code->worded);
    }

    #[Test]
    public function reads_contact_heat_term_without_norm_number(): void
    {
        // specyfikacja 44-304 nie powtarza numeru normy w tym polu
        $code = En407Code::first('Ciepło kontaktowe: poziom 1 (do 100°C/15s)');

        $this->assertSame('1', $code?->levels['contact']);
        $this->assertSame('Ciepło kontaktowe: poziom 1', $code->text);
    }

    #[Test]
    public function reads_not_tested_positions_from_card_description(): void
    {
        $code = En407Code::first('EN 407:2004 - Rękawice chroniące przed zagrożeniami termicznymi. Odporność na palenie - x (brak testu) Odporność na ciepło kontaktowe - 1 Odporność na ciepło konwekcyjne - x (brak testu)');

        $this->assertSame('X', $code?->levels['flame']);
        $this->assertSame('1', $code->levels['contact']);
        $this->assertSame('X', $code->levels['convective']);
    }

    #[Test]
    public function temperature_is_not_a_level(): void
    {
        // poz. 7: „ciepłem kontaktowym do 100°C” — 100 to temperatura, nie poziom 1
        $this->assertNull(En407Code::first('ochrona przed ciepłem kontaktowym do 100°C przez 15 s'));
        $this->assertNull(En407Code::first('Norma: EN 407:2004'), 'rok normy to nie kod');
    }

    #[Test]
    public function level_without_parameter_name_is_reported_as_unspecified(): void
    {
        $this->assertSame('EN 407 poziom 1', En407Code::unspecifiedLevel('Rękawice termiczne EN 407 poziom 1 (do 100°C).'));
        $this->assertNull(En407Code::unspecifiedLevel('EN 407 – odporność na ciepło kontaktowe poziom 1 (do 100°C).'), 'pozycja jest nazwana');
        $this->assertNull(En407Code::unspecifiedLevel('Zgodność z EN 407.'));
    }
}
