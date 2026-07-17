<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PdfPriceRowParser;
use PHPUnit\Framework\TestCase;

final class PdfPriceRowParserTest extends TestCase
{
    public function test_parses_pros_style_rows(): void
    {
        $text = "119.00 32 80.92\n159.00 30 111.30\nbogus\n179.00 30 125.30\n";
        $rows = (new PdfPriceRowParser)->parse($text, 'PROS');

        $this->assertCount(3, $rows);
        $this->assertSame(119.0, $rows[0]['catalog_price']);
        $this->assertSame(32.0, $rows[0]['discount']);
        $this->assertSame(80.92, $rows[0]['purchase']);
        $this->assertStringStartsWith('PROS-', $rows[0]['sku']);
    }
}
