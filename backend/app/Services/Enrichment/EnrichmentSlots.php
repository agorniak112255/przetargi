<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Services\Ai\AiSettingsService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

/**
 * Wspólny licznik slotów enrichmentu dla wszystkich workerów i cenników.
 * Limit czytamy z Ustawień AI przy każdej próbie, więc zmiana w panelu
 * działa od razu — bez restartu workerów.
 */
final class EnrichmentSlots
{
    public const MAX = 64;

    private const KEY_PREFIX = 'enrichment_slot:';

    public function __construct(private readonly AiSettingsService $settings) {}

    /**
     * Czeka na wolny slot i go zajmuje. Null = limit obłożony przez cały czas oczekiwania.
     */
    public function acquire(int $ttlSeconds, float $maxWaitSeconds): ?Lock
    {
        $deadline = microtime(true) + $maxWaitSeconds;

        do {
            for ($i = 0; $i < $this->limit(); $i++) {
                $lock = Cache::lock(self::KEY_PREFIX.$i, max(60, $ttlSeconds));
                if ($lock->get()) {
                    return $lock;
                }
            }
            // rozrzut, żeby workery nie sięgały po ten sam slot w tej samej chwili
            usleep(random_int(200_000, 600_000));
        } while (microtime(true) < $deadline);

        return null;
    }

    /**
     * Bierze od razu tyle wolnych slotów, ile jest — bez czekania i bez wchodzenia
     * na workery już zajęte (enrichment / inna analiza).
     *
     * @return list<Lock>
     */
    public function tryAcquireMany(int $want, int $ttlSeconds): array
    {
        $want = max(0, min($want, $this->limit()));
        if ($want === 0) {
            return [];
        }

        $locks = [];
        for ($i = 0; $i < $this->limit(); $i++) {
            if (count($locks) >= $want) {
                break;
            }
            $lock = Cache::lock(self::KEY_PREFIX.$i, max(60, $ttlSeconds));
            if ($lock->get()) {
                $locks[] = $lock;
            }
        }

        return $locks;
    }

    public function limit(): int
    {
        return max(1, min(self::MAX, $this->settings->matchConcurrency()));
    }
}
