<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\OfferTermText;
use PHPUnit\Framework\TestCase;

final class OfferTermTextTest extends TestCase
{
    public function test_bare_number_gets_the_unit_of_its_field(): void
    {
        $this->assertSame('7 dni', OfferTermText::forLetter('lead_time', '7'));
        $this->assertSame('1 dzień', OfferTermText::forLetter('lead_time', '1'));
        $this->assertSame('30 dni', OfferTermText::forLetter('payment', ' 30 '));
        $this->assertSame('14 dni', OfferTermText::forLetter('validity', '14'));
        $this->assertSame('22,00 zł netto', OfferTermText::forLetter('delivery', '22'));
        $this->assertSame('20,50 zł netto', OfferTermText::forLetter('delivery', '20,5'));
        $this->assertSame('1 250,00 zł netto', OfferTermText::forLetter('delivery', '1250.00'));
    }

    public function test_text_written_by_hand_goes_to_the_letter_unchanged(): void
    {
        $this->assertSame('przedpłata', OfferTermText::forLetter('payment', 'przedpłata'));
        $this->assertSame('3 dni robocze od zamówienia', OfferTermText::forLetter('lead_time', '3 dni robocze od zamówienia'));
        $this->assertSame('25 zł brutto', OfferTermText::forLetter('delivery', '25 zł brutto'));
        $this->assertSame('kurier, 25 zł netto', OfferTermText::forLetter('delivery', 'kurier, 25 zł netto'));
        // ułamek dni to nie „dni” z pola — zostaje, jak wpisano
        $this->assertSame('2,5', OfferTermText::forLetter('lead_time', '2,5'));
        $this->assertSame('7', OfferTermText::forLetter('unknown', '7'));
    }
}
