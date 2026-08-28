<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PriceListPdfTextExtractor;
use App\Services\SungbooPdfParser;
use PHPUnit\Framework\TestCase;

final class SungbooPdfParserTest extends TestCase
{
    public function test_parses_cards_with_grosze_prices(): void
    {
        $text = <<<'TXT'
CENNIK DLA ODBIORCÓW HURTOWYCH
RKAWICE ANTYPRZECICIOWE
SPECIAL    926 7, 8, 9, 10
   CUT                                         4X42B  6/120       Rkawice antyprzeciciowe z wlókna HPPE
            Cena netto  Rozmiar         EN388
                                        4X42C
            1054        6, 7, 8, 9,
EXTRA                   10, 11, 12                    12/120      Rkawice antyprzeciciowe z wlókna HPPE
 CUT
            Cena netto  Rozmiar         EN388
            354
11N-N08                                               10/240      Rkawice poliestrowe w kolorze bialym
YELLOW
NITRILE
            Cena netto  Rozmiar      EN388
             720
FOOD-500                                      6/120       Rkawice poliamidowe ze spandeksem
            Cena netto  Rozmiar      EN388
                                      4141X
             249         6, 7, 8,
11N-PU08 C                 9, 10              10/240      Rkawice poliestrowe w kolorze czarnym
            Cena netto  Rozmiar      EN388
                       8, 9, 10       1141  opakowanie
           293
SOFT GRIP                                   12/144      Rkawice poliamidowo-bawelniane
SWG-PSD
TXT;

        $parser = new SungbooPdfParser;
        $this->assertTrue($parser->looksLike($text));
        $rows = $parser->parse($text);
        $bySku = [];
        foreach ($rows as $row) {
            $bySku[$row['sku']] = $row;
        }

        $this->assertArrayHasKey('SPECIAL-CUT', $bySku);
        $this->assertSame(9.26, $bySku['SPECIAL-CUT']['catalog_price']);
        $this->assertSame('6/120', $bySku['SPECIAL-CUT']['packaging']);
        $this->assertArrayHasKey('EXTRA-CUT', $bySku);
        $this->assertSame(10.54, $bySku['EXTRA-CUT']['catalog_price']);
        $this->assertArrayHasKey('11N-N08', $bySku);
        $this->assertSame('YELLOW NITRILE', $bySku['11N-N08']['name']);
        $this->assertSame(3.54, $bySku['11N-N08']['catalog_price']);
        $this->assertArrayHasKey('FOOD-500', $bySku);
        $this->assertSame(7.20, $bySku['FOOD-500']['catalog_price']);
        $this->assertArrayHasKey('11N-PU08-C', $bySku);
        $this->assertSame(2.49, $bySku['11N-PU08-C']['catalog_price']);
        $this->assertArrayHasKey('SWG-PSD', $bySku);
        $this->assertSame(2.93, $bySku['SWG-PSD']['catalog_price']);
        $this->assertSame('SOFT GRIP', $bySku['SWG-PSD']['name']);
    }

    public function test_parses_local_sungboo_wholesale_pdf(): void
    {
        $matches = glob('c:/xampp/htdocs/Przetargi/Cenniki/SUNGBOO/*.pdf') ?: [];
        if ($matches === []) {
            $this->markTestSkipped('Brak lokalnego cennika SUNGBOO');
        }
        $layout = (new PriceListPdfTextExtractor)->extractLayout($matches[0]);
        if ($layout === null) {
            $this->markTestSkipped('Brak pdftotext -layout');
        }
        $rows = (new SungbooPdfParser)->parse($layout);
        $this->assertGreaterThanOrEqual(35, count($rows));
        $prices = array_column($rows, 'catalog_price');
        $this->assertContains(9.26, $prices);
        $this->assertContains(16.28, $prices);
        $this->assertContains(19.93, $prices);
    }
}
