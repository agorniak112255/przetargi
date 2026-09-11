<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Równoległy prefetch źródeł (wyszukiwarka + HTML) — osobno od slotów vLLM.
 */
final class PrefetchSlots
{
    public const MAX = 16;

    private const KEY_PREFIX = 'enrichment_prefetch_gate:';

    public function acquire(int $ttlSeconds): ?Lock
    {
        for ($i = 0; $i < $this->limit(); $i++) {
            $lock = Cache::lock(self::KEY_PREFIX.$i, max(60, $ttlSeconds));
            if ($lock->get()) {
                return $lock;
            }
        }

        return null;
    }

    public function limit(): int
    {
        // workery prefetch dostają liczbę od skryptu wdrożenia (tyle, ile adresów IP wyszukiwarki)
        $fromWorker = (int) (getenv('ENRICHMENT_PREFETCH_CONCURRENCY') ?: 0);
        $limit = $fromWorker > 0 ? $fromWorker : (int) config('enrichment.prefetch_concurrency', 5);

        return max(1, min(self::MAX, $limit));
    }
}
