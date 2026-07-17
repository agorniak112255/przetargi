<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CurrencyDetector;
use PHPUnit\Framework\TestCase;

final class CurrencyDetectorTest extends TestCase
{
    public function test_detects_common_currencies(): void
    {
        $d = new CurrencyDetector;

        $this->assertSame('EUR', $d->detect('Price (EUR)'));
        $this->assertSame('EUR', $d->detect('2.62 €'));
        $this->assertSame('PLN', $d->detect('Cena netto PLN'));
        $this->assertSame('PLN', $d->detect('119,00 zł'));
        $this->assertSame('USD', $d->detect('USD 12.50'));
        $this->assertSame('GBP', $d->detect('£9.99'));
    }
}
