<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CatalogHost;
use App\Services\Enrichment\CatalogSitemapIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class IndexCatalogHostJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [30, 90];

    public int $timeout = 240;

    public int $uniqueFor = 3600;

    public const QUEUE = 'default';

    public function __construct(
        public readonly string $host,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return mb_strtolower(trim($this->host));
    }

    public function handle(CatalogSitemapIndexer $indexer): void
    {
        $host = mb_strtolower(trim($this->host));
        try {
            $result = $indexer->index($host, 20000, 180);
            CatalogHost::query()->updateOrCreate(
                ['host' => $host],
                [
                    'pages_count' => $result['saved'],
                    'off_host_count' => $result['off_host'],
                    'last_attempt_at' => now(),
                ]
            );
        } catch (Throwable $e) {
            CatalogHost::query()->updateOrCreate(
                ['host' => $host],
                [
                    'pages_count' => 0,
                    'off_host_count' => 0,
                    'last_attempt_at' => now(),
                ]
            );
            throw $e;
        }
    }
}
