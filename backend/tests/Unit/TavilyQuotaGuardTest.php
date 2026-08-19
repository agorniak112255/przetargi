<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\TavilyQuotaExceededException;
use App\Services\Enrichment\TavilyQuotaGuard;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Tests\TestCase;

final class TavilyQuotaGuardTest extends TestCase
{
    public function test_429_is_rate_limit_not_quota_block(): void
    {
        TavilyQuotaGuard::clear();
        $response = new Response(new \GuzzleHttp\Psr7\Response(429, [], 'rate limited'));

        try {
            TavilyQuotaGuard::ensureSuccessful($response);
            $this->fail('Oczekiwano RuntimeException');
        } catch (TavilyQuotaExceededException) {
            $this->fail('429 nie powinien blokować kredytów Tavily');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('429', $e->getMessage());
            $this->assertFalse((bool) cache()->get(TavilyQuotaGuard::CACHE_KEY));
        }
    }

    public function test_432_blocks_quota(): void
    {
        TavilyQuotaGuard::clear();
        $response = new Response(new \GuzzleHttp\Psr7\Response(432, [], 'usage limit'));

        $this->expectException(TavilyQuotaExceededException::class);
        TavilyQuotaGuard::ensureSuccessful($response);
    }
}
