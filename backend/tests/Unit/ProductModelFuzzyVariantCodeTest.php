<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Support\ProductModelFuzzy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Klient pisze „ARTRA ARMEN 9007 1010 S1”, a igła modelu niesie tylko „armen9007” — wszystkie
 * warianty rodziny (6660, 9360) dostawały przez to identyczne 99% i najtańszy wchodził do oferty
 * zamiast żądanego. Oznaczenie wariantu musi się liczyć, ale nie wolno brać za nie wymiarów,
 * rozmiarów ani numerów norm.
 */
final class ProductModelFuzzyVariantCodeTest extends TestCase
{
    use RefreshDatabase;

    private const QUERY = 'buty firmy ARTRA model ARMEN 9007 1010 S1';

    private function fuzzy(): ProductModelFuzzy
    {
        return $this->app->make(ProductModelFuzzy::class);
    }

    public function test_variant_code_next_to_the_model_is_picked_up(): void
    {
        // 9007 siedzi już w igle „armen9007”, więc zostaje samo 1010.
        $this->assertSame(['1010'], $this->fuzzy()->variantCodes(self::QUERY));
    }

    public function test_card_with_other_variant_reports_the_missing_code(): void
    {
        $this->assertSame(
            ['1010'],
            $this->fuzzy()->missingVariantCodes(self::QUERY, $this->makeProduct('ARMEN 9007 6660 S1'))
        );
    }

    public function test_card_with_requested_variant_has_nothing_missing(): void
    {
        foreach (['ARMEN 9007 1010 S1', 'ARMEN 9007 1010 S1 P ESD', 'ARMEN 9007 Clip 1010 S1'] as $sku) {
            $this->assertSame(
                [],
                $this->fuzzy()->missingVariantCodes(self::QUERY, $this->makeProduct($sku)),
                $sku
            );
        }
    }

    /** Wiersz propozycji nazywa wariant karty (kolor), nie tylko brak oznaczenia z zapytania. */
    public function test_card_variant_codes_name_what_the_card_has_instead(): void
    {
        $fuzzy = $this->fuzzy();

        $this->assertSame(['6660'], $fuzzy->otherVariantCodes(self::QUERY, $this->makeProduct('ARMEN 9007 6660 S1')));
        $this->assertSame(['9360'], $fuzzy->otherVariantCodes(self::QUERY, $this->makeProduct('ARMEN 9007 9360 S1 ESD')));
        // Numer modelu z igły („9007”) i żądany wariant nie są „innym wariantem”.
        $this->assertSame([], $fuzzy->otherVariantCodes(self::QUERY, $this->makeProduct('ARMEN 9007 1010 S1 P ESD')));
        // Rok i numer normy w nazwie karty to nie wariant (recenzja 25.09.2026: „na karcie 6660, 2011”).
        $this->assertSame(['6660'], $fuzzy->otherVariantCodes(
            'Półbuty ARMEN 9007 1010 S1 SRC EN ISO 20345:2011',
            $this->makeProduct('Półbuty ARMEN 9007 6660 S1 SRC EN ISO 20345:2011 EN 1149-5')
        ));
        // Wymaganie bez oznaczenia wariantu — nie ma czego porównywać.
        $this->assertSame([], $fuzzy->otherVariantCodes('Kalosze chemoodporne antyelektrostatyczne rozmiar 43', $this->makeProduct('ARMEN 9007 6660 S1')));
    }

    public function test_norms_dimensions_and_sizes_are_not_variant_codes(): void
    {
        $fuzzy = $this->fuzzy();

        // Norma z rokiem, wymiary w centymetrach i rozmiar buta nie są oznaczeniem wariantu.
        $this->assertSame([], $fuzzy->variantCodes('Fartuch wodoochronny 120 x 75 cm EN 343'));
        $this->assertSame([], $fuzzy->variantCodes('Rękawice chemoodporne EN ISO 374-1 rozmiar 10'));
        $this->assertSame([], $fuzzy->variantCodes('Półmaska wielokrotnego użytku PN-EN 140:2004'));
        // Kod z kropką: 8543 wchodzi już w igłę modelu, więc nie staje się dodatkowym warunkiem.
        $this->assertSame([], $fuzzy->variantCodes('BUTY UVEX BUSINESS CASUAL 8543.8 S1 SRC ROZMIAR 44'));
    }

    public function test_query_without_named_model_has_no_variant_codes(): void
    {
        $this->assertSame([], $this->fuzzy()->variantCodes('Kalosze chemoodporne antyelektrostatyczne rozmiar 43'));
    }

    private function makeProduct(string $sku): Product
    {
        return new Product([
            'sku' => $sku,
            'name' => $sku,
            'manufacturer' => 'ARTRA',
        ]);
    }
}
