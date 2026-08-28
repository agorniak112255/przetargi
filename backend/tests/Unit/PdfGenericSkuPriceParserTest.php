<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PdfGenericSkuPriceParser;
use PHPUnit\Framework\TestCase;

final class PdfGenericSkuPriceParserTest extends TestCase
{
    public function test_parses_all_rows_without_ai(): void
    {
        $lines = ["Cennik Honeywell\nKod Nazwa Cena"];
        for ($i = 1; $i <= 120; $i++) {
            $lines[] = sprintf('HW%05d Rękawica test %d 12,%02d', $i, $i, $i % 100);
        }
        $rows = (new PdfGenericSkuPriceParser)->parse(implode("\n", $lines));
        $this->assertCount(120, $rows);
        $this->assertSame('HW00001', $rows[0]['sku']);
        $this->assertSame(12.01, $rows[0]['catalog_price']);
    }

    public function test_parses_ce_and_numeric_codes(): void
    {
        $text = <<<'TXT'
CE-FARTU.065 Fartuch standard 19,90
805152 FLEXCONOMY CA 5,15
TXT;
        $rows = (new PdfGenericSkuPriceParser)->parse($text);
        $this->assertSame('CE-FARTU.065', $rows[0]['sku']);
        $this->assertSame('805152', $rows[1]['sku']);
        $this->assertSame(5.15, $rows[1]['catalog_price']);
    }

    public function test_parses_thousands_of_rows(): void
    {
        $lines = [];
        for ($i = 1; $i <= 5000; $i++) {
            $lines[] = sprintf('HW%05d Produkt %d 9,99', $i, $i);
        }
        $rows = (new PdfGenericSkuPriceParser)->parse(implode("\n", $lines));
        $this->assertCount(5000, $rows);
        $this->assertSame('HW05000', $rows[4999]['sku']);
    }
}
