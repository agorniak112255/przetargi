<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\JsGlovesPdfParser;
use PHPUnit\Framework\TestCase;

final class JsGlovesPdfParserTest extends TestCase
{
    public function test_parses_glued_prices(): void
    {
        $text = <<<'TXT'
Cennik hurtowy JS GLOVES - obowiązuje od 01/01/2019
-24%
JS GLOVES COMFORT Line - Wysoka odporność na przecięcie
1Rękawice PA/PES/TEXCOR® ROC5 11,008,36
2Rękawice PA/PES/TEXCOR®, PVC ROC5V 11,708,89
3Rękawice PA/PES/TEXCOR® ROC3 6,304,79
TXT;

        $rows = (new JsGlovesPdfParser)->parse($text);
        $this->assertGreaterThanOrEqual(3, count($rows));
        $this->assertSame('ROC5', $rows[0]['sku']);
        $this->assertSame(11.0, $rows[0]['catalog_price']);
        $this->assertSame(8.36, $rows[0]['purchase']);
        $this->assertSame(24.0, $rows[0]['discount']);
    }
}
