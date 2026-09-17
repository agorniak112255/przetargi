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
            // liczba z jednostką techniczną to warunek doboru, nie cena bez waluty
            ['Włóknina gramatura 120,5 g/m2, c. netto 8,40 PLN/m2', ['120,5 g/m2']],
            ['Taśma ostrzegawcza 12,5 mb, cena 14,00 zł', ['12,5 mb']],
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

    public function test_price_without_currency_falls_only_at_a_commercial_unit(): void
    {
        // cudza cena z wcześniejszej oferty nie ma trafić do listu obok naszej
        $this->assertSame('Rękawice nitrylowe', InquiryQueryText::withoutPrice('Rękawice nitrylowe 12,50/szt.'));
        $this->assertSame('Buty robocze', InquiryQueryText::withoutPrice('Buty robocze 189,00/para'));

        // gramatura i długość zostają — to warunki doboru wyrobu
        $this->assertSame(
            'Włóknina gramatura 120,5 g/m2',
            InquiryQueryText::withoutPrice('Włóknina gramatura 120,5 g/m2')
        );
        $this->assertSame('Taśma 12,5 mb', InquiryQueryText::withoutPrice('Taśma 12,5 mb'));
    }

    /**
     * @return list<array{0: string, 1: list<string>}>
     */
    public static function packagingLines(): array
    {
        return [
            // liczba przy jednostce fizycznej to wielkość opakowania, nie cena
            ['Sorbent sypki mineralny, masa netto 20 kg', ['masa netto 20 kg']],
            ['Pasta BHP do mycia rąk, masa netto 500 g', ['masa netto 500 g']],
            ['Kanister z pompą, pojemność netto 5 l', ['pojemność netto 5 l']],
            // wypunktowanie kropkowane poprzedza też ilość i długość, nie tylko cenę
            ['Taśma antypoślizgowa ......... 18 m', ['18 m']],
            ['Rękawice nitrylowe jednorazowe ............... 100 szt./op.', ['100 szt']],
            // jednostki pisze się też wielką literą — wzorzec musi je znać tak samo
            ['Nauszniki przeciwhałasowe ....... 32 dB', ['32 dB']],
            ['Kabel elektroizolacyjny ....... 1 kV', ['1 kV']],
            ['Sorbent sypki ....... 20 KG', ['20 KG']],
            // „c.” w środku wyrazu nie jest słowem o cenie
            ['Rękawice powlekane, 100 par rękawic. 9 rozmiar', ['100 par rękawic']],
        ];
    }

    /**
     * Wielkość opakowania i długość to warunki doboru — ich utrata jest cichą
     * zmianą danych źródłowych, tak samo jak utrata normy.
     *
     * @param  list<string>  $mustKeep
     */
    #[DataProvider('packagingLines')]
    public function test_quantities_next_to_a_physical_unit_survive(string $line, array $mustKeep): void
    {
        foreach ([InquiryQueryText::forCatalog($line), InquiryQueryText::withoutPrice($line)] as $clean) {
            foreach ($mustKeep as $needle) {
                $this->assertStringContainsString($needle, $clean, 'zgubiony warunek: '.$needle);
            }
        }
    }

    public function test_amount_is_never_eaten_only_in_part(): void
    {
        $clean = InquiryQueryText::forCatalog('Sorbent sypki mineralny, masa netto 20 kg');

        $this->assertStringContainsString('20 kg', $clean);
        $this->assertStringNotContainsString(' 0 kg', $clean);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function foreignPriceLines(): array
    {
        return array_map(static fn (string $line): array => [$line], [
            'Rękawice robocze, cena jednostkowa 189,00',
            'Buty robocze S3, cena zakupu 18,90',
            'Mata gumowa, cena jedn. 12,50',
            'Łopata do śniegu, koszt 45,00',
            'Fartuch ochronny, wartość netto pozycji: 120,00',
            'Rękawice robocze wzmacniane 12,50 za szt.',
            // test broniący rozdzielenia list jednostek: „szt.” po kwocie to nadal cena
            'Rękawice nitrylowe, cena 24,00 szt.',
            'Rękawice nitrylowe .......... 24,00 /szt',
            // kwota z kropką tysięcy — bez niej wzorzec dopasowywał tylko część liczby
            'Mata gumowa przemysłowa, cena 1.250,00 zł',
            'Drabina aluminiowa, c. netto......1.250,00',
            'Kask ochronny, cena 24,00 zł za 1 szt.',
        ]);
    }

    /**
     * Cena z cudzej oferty nie może wrócić do klienta obok naszej.
     */
    #[DataProvider('foreignPriceLines')]
    public function test_foreign_price_never_reaches_the_letter(string $line): void
    {
        $quote = InquiryQueryText::withoutPrice($line);

        $this->assertDoesNotMatchRegularExpression('/\d[\d ]*[.,]\d{2}/u', $quote, 'cena w cytacie: '.$quote);
    }

    public function test_quote_made_of_nothing_but_a_price_collapses_to_nothing(): void
    {
        // próg „krótkie → oryginał” oddawał klientowi z powrotem samą cenę
        $this->assertSame('', InquiryQueryText::withoutPrice('cena 39,00 zł'));
        $this->assertSame('', InquiryQueryText::withoutPrice('c. netto......24,00 PLN/szt'));
        // ale cytat, który ceny nie miał, zostaje w całości
        $this->assertSame('XL', InquiryQueryText::withoutPrice('XL'));
        // po cenie nie zostaje osierocona jednostka ani podwójny przecinek
        $this->assertSame('Rękawice nitrylowe', InquiryQueryText::withoutPrice('Rękawice nitrylowe, cena 24,00 szt.'));
        $this->assertSame('Rękawice robocze, rozmiar 9', InquiryQueryText::withoutPrice('Rękawice robocze, 24,00 PLN, rozmiar 9'));
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
