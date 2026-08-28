<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RenexPdfParser;
use PHPUnit\Framework\TestCase;

final class RenexPdfParserTest extends TestCase
{
    public function test_parses_ce_codes_and_sections(): void
    {
        $text = <<<'TXT'
Cennik dla dystrybutorów (PL)
TKANINA 065
Nazwa Kod Cena
1. FARTUCH STANDARD CE-FARTU.065
2. KASAK CE-KASAK.065
DZIANINA 260
1. BLUZA KLASYCZNA CE-BLUZA.260.BS
TXT;
        $rows = (new RenexPdfParser)->parse($text);
        $this->assertCount(3, $rows);
        $this->assertSame('CE-FARTU.065', $rows[0]['sku']);
        $this->assertSame('FARTUCH STANDARD', $rows[0]['name']);
        $this->assertSame('TKANINA 065', $rows[0]['category']);
        $this->assertNull($rows[0]['catalog_price']);
        $this->assertSame('CE-BLUZA.260.BS', $rows[2]['sku']);
    }
}
