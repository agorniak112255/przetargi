<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\XlTimestamp;
use PHPUnit\Framework\TestCase;

final class XlTimestampTest extends TestCase
{
    public function test_converts_seconds_since_1990_to_day(): void
    {
        // partie SZAT2112103 na produkcji 29.09.2026: PZ z 20.08.2026 przyjęta 21.08.2026 13:51, starsza z 2025
        $this->assertSame('2026-08-21', XlTimestamp::toDate(1156168293)?->toDateString());
        $this->assertSame('2025-10-02', XlTimestamp::toDate('1128261658')?->toDateString());
        $this->assertSame('1990-01-02', XlTimestamp::toDate(86400)?->toDateString());
    }

    public function test_empty_zero_and_out_of_range_are_no_date(): void
    {
        $this->assertNull(XlTimestamp::toDate(null));
        $this->assertNull(XlTimestamp::toDate(''));
        $this->assertNull(XlTimestamp::toDate(0));
        $this->assertNull(XlTimestamp::toDate(-5));
        $this->assertNull(XlTimestamp::toDate('abc'));
        $this->assertNull(XlTimestamp::toDate(3_471_292_800));
    }
}
