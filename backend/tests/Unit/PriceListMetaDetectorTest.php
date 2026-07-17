<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PriceListMetaDetector;
use PHPUnit\Framework\TestCase;

final class PriceListMetaDetectorTest extends TestCase
{
    public function test_detects_from_filenames(): void
    {
        $d = new PriceListMetaDetector;

        $ema = $d->fromFilename('EMA Body Protection Pricelist_EUR_2026.pdf');
        $this->assertSame('EMA', $ema['manufacturer']);
        $this->assertSame('2026', $ema['version']);

        $m3 = $d->fromFilename('3M_Cennik_od_01.07.2021-2.xlsx');
        $this->assertSame('3M', $m3['manufacturer']);
        $this->assertSame('2021-07', $m3['version']);

        $deb = $d->fromFilename('cennik debstoko 15.02.2020..pdf');
        $this->assertSame('Debstoko', $deb['manufacturer']);
        $this->assertSame('2020-02', $deb['version']);
    }

    public function test_resolve_prefers_ai_then_filename_over_stale_hint(): void
    {
        $d = new PriceListMetaDetector;
        $r = $d->resolve('3M', 'EMA Body Protection Pricelist_EUR_2026.pdf', 'Ansell', null, '2021-07');
        $this->assertSame('Ansell', $r['manufacturer']);
        $this->assertSame('2026', $r['version']);
    }
}
