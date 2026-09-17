<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\InquiryQueryText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Linie z prawdziwego zapytania #6/#7 („OFERTA Skalmierzyce”) oraz przypadki
 * odwrotne: warunki doboru, których czyszczenie nie ma prawa ruszyć.
 */
final class InquiryQueryTextTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function realLines(): array
    {
        return [
            ['**(poz6)Wycieraczka gumowa:rozm: 40x60cm, c. netto......24,00 PLN/szt', 'Wycieraczka gumowa:rozm: 40x60cm'],
            ['50x100cm, c. netto...... 39,00 PLN/szt.', '50x100cm'],
            ['80x120cm c. netto......89,00 PLN/szt.', '80x120cm'],
            ['(poz9). Łopata do śniegu,c. netto......97,00 PLN/szt.', 'Łopata do śniegu'],
            ['(poz23)Uchwyt bezpiecznikowy z rękawem GPSHE/AI, c. netto....162,00 PLN', 'Uchwyt bezpiecznikowy z rękawem GPSHE/AI'],
        ];
    }

    #[DataProvider('realLines')]
    public function test_price_and_position_markers_are_removed(string $line, string $expected): void
    {
        $this->assertSame($expected, InquiryQueryText::forCatalog($line));
    }

    public function test_manufacturer_code_survives_even_with_thousand_separator_price(): void
    {
        $clean = InquiryQueryText::forCatalog(
            '( poz10)Drabina elektroizolacyjna rozstawna  KRAUSE 815446, c. netto....4 497,00PLN/szt.'
        );

        $this->assertStringContainsString('Drabina elektroizolacyjna rozstawna', $clean);
        $this->assertStringContainsString('KRAUSE 815446', $clean);
        $this->assertStringNotContainsString('4 497', $clean);
        $this->assertStringNotContainsString('netto', $clean);
    }

    /**
     * @return list<array{0: string, 1: list<string>}>
     */
    public static function constraintLines(): array
    {
        return [
            ['Rękawice EN ISO 374-1 typ B, c. netto 12,50 PLN/szt.', ['EN ISO 374-1', 'typ B']],
            ['Rękawice odporne na kwas siarkowy 96%, cena 18,00 zł', ['kwas siarkowy 96%']],
            ['Buty S3 SRC, rozmiar 44, c. netto....189,00 PLN', ['S3 SRC', '44']],
            ['Ochronniki słuchu tłumienie 30,5 dB, netto 55,00 PLN', ['30,5 dB']],
            ['Mata gumowa 0,6 m x 0,9 m, c. netto......104,95 PLN/szt', ['0,6 m x 0,9 m']],
            // kropka na końcu linii odpada razem z interpunkcją — treść warunku zostaje
            ['Rękawice nitrylowe op. 100 szt., c. netto.....24,00 PLN/opak', ['op. 100 szt']],
            ['Drążek izolacyjny 110kV, UDI-110-B L=2200, c. netto.....937,00 PLN', ['110kV', 'UDI-110-B', 'L=2200']],
        ];
    }

    /**
     * Warunek doboru musi przeżyć czyszczenie — jego utrata to cicha zmiana
     * znaczenia danych źródłowych, czego projekt zabrania.
     *
     * @param  list<string>  $mustKeep
     */
    #[DataProvider('constraintLines')]
    public function test_selection_constraints_are_never_removed(string $line, array $mustKeep): void
    {
        $clean = InquiryQueryText::forCatalog($line);

        foreach ($mustKeep as $needle) {
            $this->assertStringContainsString($needle, $clean, 'zgubiony warunek: '.$needle);
        }
        $this->assertStringNotContainsString('PLN', $clean);
        $this->assertStringNotContainsString('netto', $clean);
    }

    public function test_recognises_lines_without_any_product_name(): void
    {
        $this->assertFalse(InquiryQueryText::hasProductWord('50x100cm'));
        $this->assertFalse(InquiryQueryText::hasProductWord('rozm. 44'));
        $this->assertFalse(InquiryQueryText::hasProductWord('100x150cm'));

        $this->assertTrue(InquiryQueryText::hasProductWord('Wycieraczka gumowa 50x100cm'));
        $this->assertTrue(InquiryQueryText::hasProductWord('Łopata do śniegu'));
    }

    public function test_empty_and_price_only_lines_collapse_to_nothing(): void
    {
        $this->assertSame('', InquiryQueryText::forCatalog(''));
        $this->assertSame('', InquiryQueryText::forCatalog('c. netto......24,00 PLN/szt'));
    }
}
