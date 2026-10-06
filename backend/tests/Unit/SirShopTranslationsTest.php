<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\B2b\SirShopTranslations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Słownik sklepu SIR (06.10.2026): kategorie i tabelka cech po polsku, wartość spoza słownika dosłownie.
 */
final class SirShopTranslationsTest extends TestCase
{
    #[Test]
    public function category_path_is_polish_and_keeps_series_names(): void
    {
        $this->assertSame('Rękawice > Rękawice chroniące przed przecięciem', SirShopTranslations::categoryPath('GLOVES', 'CUT PROTECTION GLOVES'));
        // podwójna spacja ze sklepu, literówka „SHIELS”, nazwa serii bez zmian
        $this->assertSame('Ochrona oczu i twarzy > Osłony twarzy', SirShopTranslations::categoryPath('SPECTACLES AND FACE SHIELS', 'FACE  SHIELDS'));
        $this->assertSame('Obuwie > Obuwie – seria ALL TERRAIN', SirShopTranslations::categoryPath('FOOTWEAR', 'FOOTWEAR ALL TERRAIN SERIES'));
        // dział równy grupie raz
        $this->assertSame('Różne', SirShopTranslations::categoryPath('VARIOUS', 'VARIOUS'));
    }

    #[Test]
    public function mixed_accessories_group_has_no_clothing_department(): void
    {
        // torby, wózek, nóż i skarpety z „CLOTHING ACCESSORIES” nie mogą dostać rodziny „odzież” z kategorii
        $this->assertSame('Akcesoria różne', SirShopTranslations::categoryPath('CLOTHING', 'CLOTHING  ACCESSORIES'));
    }

    #[Test]
    public function unknown_values_stay_as_in_the_shop(): void
    {
        $this->assertSame('Rękawice > GLOVES LEATHER', SirShopTranslations::categoryPath('GLOVES', 'GLOVES LEATHER'));
        $this->assertSame('PURPLE (X1)', SirShopTranslations::colour('PURPLE (X1)'));
        $this->assertSame('Box', SirShopTranslations::unit('Box'));
        $this->assertSame('XX', SirShopTranslations::country('XX'));
        $this->assertSame('Resistenza nuova', SirShopTranslations::levelLabel('Resistenza nuova'));
    }

    #[Test]
    public function colours_are_translated_by_parts_and_keep_the_code(): void
    {
        $this->assertSame('szary (B0)', SirShopTranslations::colour('GREY (B0)'));
        $this->assertSame('niebieski/żółty fluorescencyjny (P7)', SirShopTranslations::colour('BLUE/HI-VIS YELLOW (P7)'));
        $this->assertSame('kobaltowy/żółty fluorescencyjny', SirShopTranslations::colour('ROYAL/HI-VISYELLOW'));
        $this->assertSame('szary melanż', SirShopTranslations::colour('MELANGE GREY'));
        // część spoza słownika zostaje, reszta po polsku
        $this->assertSame('czarny/PURPLE', SirShopTranslations::colour('BLACK/PURPLE'));
    }

    #[Test]
    public function table_values_and_italian_level_names(): void
    {
        $this->assertSame('para', SirShopTranslations::unit('Pair'));
        $this->assertSame('szt.', SirShopTranslations::unit('Piece'));
        $this->assertSame('nie dotyczy (wyrób niebędący ŚOI)', SirShopTranslations::ppeCategory('NO'));
        $this->assertSame('III', SirShopTranslations::ppeCategory('III'));
        $this->assertSame('Chiny', SirShopTranslations::country('CN'));
        $this->assertSame('Wytrzymałość mechaniczna', SirShopTranslations::levelLabel("Resistenza all'impatto"));
        $this->assertSame('Wodorotlenek sodu 40%', SirShopTranslations::levelLabel('Idrossido di sodio 40%'));
        // skróty z norm zostają
        $this->assertSame('ATPV', SirShopTranslations::levelLabel('ATPV'));
        $this->assertSame('ATPV (tkanina)', SirShopTranslations::levelLabel('ATPV (Tessuto)'));
    }
}
