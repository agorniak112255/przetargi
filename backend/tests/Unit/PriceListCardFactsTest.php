<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\PriceListCardFacts;
use Tests\TestCase;

/**
 * Fakty o karcie z cennika dla członka modelu (etap 2 opisów z cenników): specs i dowody `explicit`/`price_list`
 * z nazwy, atrybutów cennika i EAN — bez ceny.
 */
final class PriceListCardFactsTest extends TestCase
{
    public function test_dimensions_thickness_colour_attributes_and_ean_from_price_list_card(): void
    {
        $facts = (new PriceListCardFacts)->for(new Product([
            'sku' => 'AF060003',
            'name' => 'Orthomat Standard Szary 0.9m x 18.3m (9.5mm)',
            'manufacturer' => 'Coba',
            'ean' => '5901234123457',
            'price_list_attributes' => ['rozmiar' => '10', 'klasa_ochrony' => 'III', 'normy' => 'EN 388', 'typ_wyrobu' => 'mata', 'material' => ''],
        ]));

        $this->assertSame([
            'Wymiary: 0,9 × 18,3 m',
            'Grubość: 9,5 mm',
            'Rozmiar: 10',
            'Klasa ochrony: III',
            'Kolor: Szary',
            'EAN: 5901234123457',
        ], $facts['specs']);
        $this->assertSame('grey', $facts['colour']);
        $this->assertSame(['grey'], $facts['colours']);

        $this->assertCount(6, $facts['evidence']);
        foreach ($facts['evidence'] as $entry) {
            $this->assertSame('specs', $entry['field']);
            $this->assertSame('explicit', $entry['status']);
            $this->assertSame('price_list', $entry['source']);
            $this->assertNull($entry['source_sha256']);
            $this->assertContains($entry['value'], $facts['specs']);
        }
        $quotes = array_column($facts['evidence'], 'quote', 'value');
        $this->assertSame('0.9m x 18.3m', $quotes['Wymiary: 0,9 × 18,3 m']);
        $this->assertSame('9.5mm', $quotes['Grubość: 9,5 mm']);
        $this->assertSame('Szary', $quotes['Kolor: Szary']);
        $this->assertSame('10', $quotes['Rozmiar: 10']);
        $this->assertSame('5901234123457', $quotes['EAN: 5901234123457']);
        // ceny nie ma w żadnym wierszu
        $this->assertStringNotContainsString('zł', implode(' ', $facts['specs']));
    }

    public function test_sold_per_metre_maximum_length_and_mixed_units(): void
    {
        $perMetre = (new PriceListCardFacts)->for($this->coba('SN060007C', 'Senso Runner Szary 1m x mb. (3mm) - maks. 10m'));
        $this->assertSame(['Sprzedaż: na metry bieżące', 'Wymiary: 1 m × mb.', 'Grubość: 3 mm', 'Długość maksymalna: 10 m', 'Kolor: Szary'], $perMetre['specs']);

        $coir = (new PriceListCardFacts)->for($this->coba('CM050001C', 'Coir Natural 1m x mb. (17mm) - max. długość 6m'));
        $this->assertContains('Długość maksymalna: 6 m', $coir['specs']);
        $this->assertNull($coir['colour']);

        $grating = (new PriceListCardFacts)->for($this->coba('GRP040001G', 'COBAGRiP Krata GRP Zielony 2000mm x 1000mm x 25mm'));
        $this->assertSame(['Wymiary: 2000 × 1000 × 25 mm', 'Kolor: Zielony'], $grating['specs']);
        $this->assertSame('green', $grating['colour']);

        $edge = (new PriceListCardFacts)->for($this->coba('SS010002M', "Krawędź/narożnik 'męski' Czarny 85mm x 1m"));
        $this->assertSame(['Wymiary: 85 mm × 1 m', 'Kolor: Czarny'], $edge['specs']);

        $bare = (new PriceListCardFacts)->for($this->coba('SW020001', 'Worksafe Nitryl - Niebieski 0.9 x 1.5m (16mm)'));
        $this->assertSame(['Wymiary: 0,9 × 1,5 m', 'Grubość: 16 mm', 'Kolor: Niebieski'], $bare['specs']);

        $single = (new PriceListCardFacts)->for($this->coba('CCLIP25', 'Akcesoria Krata GRP - Uchwyt typu C - 25mm'));
        $this->assertSame(['Wymiary: 25 mm'], $single['specs'], 'wymiar poza nawiasem to nie grubość');
    }

    public function test_colour_phrase_is_verbatim_and_colours_are_the_whole_set_with_the_first_as_colour(): void
    {
        // karta dwubarwna: wiersz dosłowny, zbiór obu kolorów do doboru zdjęcia, `colour` = pierwszy (zgodność)
        $edges = (new PriceListCardFacts)->for($this->coba('SD010701', 'Deckplate Czarny/Żółte krawędzie 0.6m x 0.9m (15mm)'));
        $this->assertContains('Kolor: Czarny/Żółte', $edges['specs']);
        $this->assertSame('black', $edges['colour']);
        $this->assertSame(['black', 'yellow'], $edges['colours']);

        $wash = (new PriceListCardFacts)->for($this->coba('LM010201', 'COBAwash Czarny/Niebieski 0.6m x 0.85m'));
        $this->assertSame(['Wymiary: 0,6 × 0,85 m', 'Kolor: Czarny/Niebieski'], $wash['specs']);
        $this->assertSame(['black', 'blue'], $wash['colours']);
        $this->assertSame(['white', 'red'], (new PriceListCardFacts)->for($this->coba('TP010502', 'COBAtape Biało/Czerwona 50mm x 18.3m'))['colours']);

        $tape = (new PriceListCardFacts)->for($this->coba('GF120002', 'Gripfoot Standard Taśma 50mm x 18.3m - Clear (przezroczysty)'));
        $this->assertContains('Kolor: Clear', $tape['specs']);
        $this->assertSame('clear', $tape['colour']);
        $this->assertSame(['clear'], $tape['colours'], 'fraza koloru to pierwszy człon — „(przezroczysty)” to ten sam kolor');

        // „Krawędź/narożnik” nie jest kolorem; atrybut koloru z cennika wygrywa z nazwą i nie dubluje wiersza
        $attribute = (new PriceListCardFacts)->for(new Product([
            'sku' => 'X-1', 'name' => 'Rękawice Tale czarne', 'manufacturer' => 'Canis',
            'price_list_attributes' => ['kolor' => 'szary'],
        ]));
        $this->assertSame(['Kolor: szary'], $attribute['specs']);
        $this->assertSame('grey', $attribute['colour']);
        $this->assertSame(['grey'], $attribute['colours']);
        // atrybut dwubarwny też zbiorem; atrybut bez rozpoznanego koloru oddaje głos nazwie
        $twoColourAttribute = (new PriceListCardFacts)->for(new Product([
            'sku' => 'X-2', 'name' => 'Rękawice Tale czarne', 'manufacturer' => 'Canis',
            'price_list_attributes' => ['kolor' => 'czarno-żółty'],
        ]));
        $this->assertSame(['black', 'yellow'], $twoColourAttribute['colours']);
        $unknownAttribute = (new PriceListCardFacts)->for(new Product([
            'sku' => 'X-3', 'name' => 'Rękawice Tale czarne', 'manufacturer' => 'Canis',
            'price_list_attributes' => ['kolor' => 'melanż'],
        ]));
        $this->assertSame(['Kolor: melanż'], $unknownAttribute['specs']);
        $this->assertSame(['black'], $unknownAttribute['colours']);

        $none = (new PriceListCardFacts)->for($this->coba('WC0000-4', 'First-Step'));
        $this->assertSame(['specs' => [], 'evidence' => [], 'colour' => null, 'colours' => []], $none);
    }

    private function coba(string $sku, string $name): Product
    {
        return new Product(['sku' => $sku, 'name' => $name, 'manufacturer' => 'Coba']);
    }
}
