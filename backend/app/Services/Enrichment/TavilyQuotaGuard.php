<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Exceptions\TavilyQuotaExceededException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;

final class TavilyQuotaGuard
{
    public const CACHE_KEY = 'tavily_quota_blocked';

    public static function assertAllowed(): void
    {
        if (Cache::get(self::CACHE_KEY)) {
            throw new TavilyQuotaExceededException(
                'Limit Tavily wyczerpany — wyszukiwanie wstrzymane. Uzupełnij kredyty lub poczekaj na reset planu.'
            );
        }
    }

    public static function block(string $detail = ''): void
    {
        Cache::put(self::CACHE_KEY, true, now()->addHours(6));
    }

    public static function clear(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function ensureSuccessful(Response $response, string $prefix = 'Tavily'): void
    {
        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $body = $response->body();
        if ($status === 432 || str_contains(mb_strtolower($body), 'usage limit')) {
            self::block($body);
            throw new TavilyQuotaExceededException(
                $prefix.' HTTP '.$status.': limit planu Tavily wyczerpany. Batch zatrzymany — nie ponawiaj, dopóki nie będzie kredytów.'
            );
        }

        throw new \RuntimeException($prefix.' HTTP '.$status.': '.$body);
    }
}
