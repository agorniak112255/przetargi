<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Host (albo jego subdomena) wykluczony jako źródło opisu: `enrichment.blocked_source_hosts` i host naszego sklepu
 * z `prestashop.shop_url` — eksport wysyła tam nasze własne opisy, więc strona sklepu niczego o wyrobie nie potwierdza
 * (karta CEDERROTH 490710 miała źródło z supon.rzeszow.pl, 07.10.2026). Wspólne dla wzbogacania
 * (ProductEnrichmentService) i werdyktu tożsamości (SourceIdentity).
 */
final class BlockedSourceHost
{
    public static function matches(string $url): bool
    {
        $host = preg_replace('/^www\./', '', mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''))) ?? '';
        if ($host === '') {
            return false;
        }
        $ownShopHost = parse_url(trim((string) config('prestashop.shop_url', '')), PHP_URL_HOST);
        foreach ([...(array) config('enrichment.blocked_source_hosts', []), is_string($ownShopHost) ? $ownShopHost : ''] as $needle) {
            $needle = is_string($needle) ? preg_replace('/^www\./', '', mb_strtolower(trim($needle))) ?? '' : '';
            if ($needle !== '' && ($host === $needle || str_ends_with($host, '.'.$needle))) {
                return true;
            }
        }

        return false;
    }
}
