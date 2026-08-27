<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Numer workera z systemd (QUEUE_WORKER_INDEX) — widać w User-Agent
 * SearXNG i llama-swap, że pula 1/2/3 albo enrich/7/16 żyje.
 */
final class QueueWorkerIdentity
{
    public static function label(): string
    {
        $pool = trim((string) (getenv('QUEUE_WORKER_POOL') ?: ''));
        $index = (int) (getenv('QUEUE_WORKER_INDEX') ?: 0);
        $count = (int) (getenv('QUEUE_WORKER_COUNT') ?: 0);
        if ($pool === '' || $index < 1) {
            return 'web';
        }

        return $count > 0 ? $pool.'/'.$index.'/'.$count : $pool.'/'.$index;
    }

    public static function userAgent(string $product): string
    {
        return $product.' ('.self::label().')';
    }
}
