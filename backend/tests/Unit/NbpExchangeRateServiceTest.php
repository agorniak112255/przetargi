<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\NbpExchangeRateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class NbpExchangeRateServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::forget('nbp.table_a.rates');
    }

    public function test_converts_eur_using_nbp_mid(): void
    {
        Http::fake([
            'api.nbp.pl/*' => Http::response([[
                'effectiveDate' => '2026-08-27',
                'rates' => [
                    ['code' => 'EUR', 'mid' => 4.0],
                    ['code' => 'USD', 'mid' => 3.5],
                ],
            ]]),
        ]);

        $fx = new NbpExchangeRateService;

        $this->assertSame(40.0, $fx->toPln(10, 'EUR'));
        $this->assertSame(10.0, $fx->toPln(10, 'PLN'));
        $this->assertSame('nbp', $fx->snapshot()['source']);
        $this->assertSame('2026-08-27', $fx->snapshot()['as_of']);
    }

    public function test_falls_back_when_nbp_fails(): void
    {
        Http::fake([
            'api.nbp.pl/*' => Http::response('error', 503),
        ]);

        $fx = new NbpExchangeRateService;
        $snap = $fx->snapshot();

        $this->assertSame('fallback', $snap['source']);
        $this->assertArrayHasKey('EUR', $snap['rates']);
        $this->assertGreaterThan(1, $fx->toPln(1, 'EUR'));
    }
}
