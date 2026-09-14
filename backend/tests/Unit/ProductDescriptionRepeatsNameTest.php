<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use PHPUnit\Framework\TestCase;

/**
 * Karta „ma opis”, gdy tekst mówi coś ponad nazwę. Import cennika 3M 2026 zapisał długie nazwy produktów jako opis, a zasada
 * „bez opisu nie ma propozycji” i kolejka pobierania opisów brały taką kartę za opisaną. Zwykły krótki opis, który zaczyna
 * się od nazwy („Rękawice robocze wzmacniane, dzianina bawełniana…”), dalej jest opisem.
 */
final class ProductDescriptionRepeatsNameTest extends TestCase
{
    public function test_description_repeating_price_list_name_is_not_a_description(): void
    {
        $name = 'Osłona przed rozkurzem maski wielokrotnego użytku 3M™ do bezobsługowej półmaski wielokrotnego użytku 3M™ z serii 4000+, 400+';

        $same = $this->card($name, $name, Product::ENRICHMENT_NONE);
        $this->assertFalse($same->hasDescriptionText(), 'opis = nazwa');
        $this->assertFalse($same->hasUsableDescription());

        $titleOnly = $this->card(mb_substr($name, 0, 80), $name, Product::ENRICHMENT_NONE);
        $this->assertFalse($titleOnly->hasDescriptionText(), 'nazwa ucięta do 80 znaków, opis to reszta tej samej nazwy');

        $cutDescription = $this->card($name, mb_substr($name, 0, 60), Product::ENRICHMENT_NONE);
        $this->assertFalse($cutDescription->hasDescriptionText(), 'opis to początek nazwy');

        $this->assertTrue($this->card($name, $name, Product::ENRICHMENT_DONE)->hasUsableDescription(), 'status „done” jak dotąd wystarcza do „użytecznego”');
    }

    public function test_short_description_starting_with_short_name_is_still_a_description(): void
    {
        $this->assertTrue($this->card(
            'Rękawice robocze',
            'Rękawice robocze wzmacniane, dzianina bawełniana, rozmiary 7-11.',
            Product::ENRICHMENT_NONE,
        )->hasDescriptionText());
        $this->assertTrue($this->card(
            'HyFlex 11202 SIZE 19\'\'/47,5 cm',
            'Rękaw ochronny Ansell HyFlex 11-202 chroni przedramię przed przecięciem.',
            Product::ENRICHMENT_DONE,
        )->hasDescriptionText());
        $this->assertFalse($this->card('Kask', 'krótki opis', Product::ENRICHMENT_NONE)->hasDescriptionText(), 'poniżej 24 znaków');
    }

    private function card(string $name, string $description, string $status): Product
    {
        $product = new Product;
        $product->forceFill(['name' => $name, 'description' => $description, 'enrichment_status' => $status]);

        return $product;
    }
}
