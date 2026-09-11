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
        $this->assertSame('EUR', $d->detect('Prices in EURO'));
        $this->assertSame('PLN', $d->detect('Cena netto PLN'));
        $this->assertSame('PLN', $d->detect('119,00 zł'));
        $this->assertSame('USD', $d->detect('USD 12.50'));
        $this->assertSame('GBP', $d->detect('£9.99'));
        $this->assertSame('ZAR', $d->detect('446.00R 508.44R'));
        $this->assertSame('ZAR', $d->detect('Price ZAR Excl VAT'));
        $this->assertSame('ZAR', $d->detect('EURO 11419 Dominator 319.00 R 363.66 R'));
        $this->assertNull($d->detect('Steel toecap Sole Type'));
    }

    public function test_detects_currency_from_parent_header_above_bez_vat(): void
    {
        $d = new CurrencyDetector;

        $this->assertSame('EUR', $d->detectFromColumnStack(['EUR', 'bez VAT']));
        $this->assertSame('PLN', $d->detectFromColumnStack(['PLN', '* NCD z VAT']));
        $this->assertSame('EUR', $d->detectFromColumnStack(['EUR', 'Price EUR']));
        $this->assertNull($d->detectFromColumnStack(['bez VAT']));
    }
}
