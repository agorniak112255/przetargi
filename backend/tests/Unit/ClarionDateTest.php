<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ClarionDate;
use PHPUnit\Framework\TestCase;

final class ClarionDateTest extends TestCase
{
    public function test_converts_days_since_1800_12_28(): void
    {
        // dokumenty XL z 29.09.2026 (eksport z produkcji)
        $this->assertSame('2026-09-29', ClarionDate::toDate(82455)?->toDateString());
        $this->assertSame('2006-03-06', ClarionDate::toDate('74943')?->toDateString());
        $this->assertSame('1900-01-01', ClarionDate::toDate(36163)?->toDateString());
    }

    public function test_empty_zero_and_out_of_range_are_no_date(): void
    {
        $this->assertNull(ClarionDate::toDate(null));
        $this->assertNull(ClarionDate::toDate(''));
        $this->assertNull(ClarionDate::toDate(0));
        $this->assertNull(ClarionDate::toDate('abc'));
        // TrN_Data2 = 2487740 w typach dokumentów — nie data
        $this->assertNull(ClarionDate::toDate(2487740));
    }
}
