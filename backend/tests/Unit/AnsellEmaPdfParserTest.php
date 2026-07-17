<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AnsellEmaPdfParser;
use PHPUnit\Framework\TestCase;

final class AnsellEmaPdfParserTest extends TestCase
{
    public function test_parses_ema_style_rows(): void
    {
        $text = <<<TXT
AlphaTecTM 1500 STANDARD Model 113, 138
Short Base Style Long Base Style Sizing MTS MTO Carton Qty Price (EUR)
N15S138 NV15S-00138 S-5XL S-3XL 4XL-5XL S-2XL 50 PCE / 3XL-5XL 40 PCE 2.62
O15S138 OR15S-00138 S-5XL S-2XL 50 PCE / 3XL-5XL 40 PCE 3.57
W15S138 WH15S-00138 S-6XL S-3XL 4XL-6XL S-2XL 50 PCE / 3XL-6XL 40 PCE 2.48
TXT;

        $rows = (new AnsellEmaPdfParser)->parse($text, 'Ansell');
        $this->assertGreaterThanOrEqual(3, count($rows));
        $this->assertSame('NV15S-00138', $rows[0]['sku']);
        $this->assertSame(2.62, $rows[0]['catalog_price']);
        $this->assertSame(40, $rows[0]['pack_qty']);
        $this->assertSame('EUR', $rows[0]['currency']);
        $this->assertStringContainsString('AlphaTec', $rows[0]['name']);
    }
}
