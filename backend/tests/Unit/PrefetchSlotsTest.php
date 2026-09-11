<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Enrichment\PrefetchSlots;
use Tests\TestCase;

final class PrefetchSlotsTest extends TestCase
{
    public function test_limit_follows_worker_env_up_to_sixteen(): void
    {
        config(['enrichment.prefetch_concurrency' => 5]);
        $this->assertSame(5, (new PrefetchSlots)->limit());

        // workery prefetch dostają od skryptu wdrożenia tyle, ile adresów IP wyszukiwarki
        putenv('ENRICHMENT_PREFETCH_CONCURRENCY=9');
        try {
            $this->assertSame(9, (new PrefetchSlots)->limit());
            putenv('ENRICHMENT_PREFETCH_CONCURRENCY=40');
            $this->assertSame(16, (new PrefetchSlots)->limit());
        } finally {
            putenv('ENRICHMENT_PREFETCH_CONCURRENCY');
        }
    }
}
