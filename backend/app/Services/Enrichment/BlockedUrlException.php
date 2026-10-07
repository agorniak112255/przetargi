<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use RuntimeException;

/**
 * Adres (albo cel przekierowania) poza siecią publiczną: localhost, adres prywatny, link-local, inny port niż 80/443,
 * schemat inny niż http(s) — PublicUrlFetcher go nie pobiera. Odmowa stała, nie „ponów później”.
 */
final class BlockedUrlException extends RuntimeException
{
    public const MESSAGE = 'adres poza siecią publiczną';

    public function __construct(public readonly string $url)
    {
        parent::__construct(self::MESSAGE);
    }
}
