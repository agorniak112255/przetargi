<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CatalogHost;
use App\Services\Enrichment\CatalogIndexProgress;
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

    public int $timeout = 720;

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

    public function handle(CatalogSitemapIndexer $indexer, CatalogIndexProgress $progress): void
    {
        $host = mb_strtolower(trim($this->host));
        $progress->markRunning($host, 'Worker startuje indeksowanie.');
        try {
            $result = $indexer->index($host, 250000, 600);
            CatalogHost::query()->updateOrCreate(
                ['host' => $host],
                [
                    'pages_count' => $result['saved'],
                    'off_host_count' => $result['off_host'],
                    'last_attempt_at' => now(),
                    'last_error' => $this->emptyError($result),
                ]
            );
            $progress->finish($host, $this->doneMessage($result), true);
        } catch (Throwable $e) {
            CatalogHost::query()->updateOrCreate(
                ['host' => $host],
                [
                    'pages_count' => 0,
                    'off_host_count' => 0,
                    'last_attempt_at' => now(),
                    'last_error' => mb_substr($e->getMessage(), 0, 500),
                ]
            );
            $progress->finish($host, $e->getMessage(), false);
            throw $e;
        }
    }

    /**
     * @param  array{saved: int, sitemaps: list<string>, timed_out: bool}  $result
     */
    private function emptyError(array $result): ?string
    {
        if ($result['saved'] > 0) {
            return null;
        }
        if ($result['timed_out']) {
            return 'Przerwane limitem czasu, 0 kart.';
        }
        if ($result['sitemaps'] === []) {
            return 'Nie znaleziono sitemapy.';
        }

        return 'Sitemap bez kart produktu.';
    }

    /**
     * @param  array{saved: int, sitemaps: list<string>, off_host: int, timed_out: bool}  $result
     */
    private function doneMessage(array $result): string
    {
        $parts = [$result['saved'].' kart', count($result['sitemaps']).' map'];
        if ($result['off_host'] > 0) {
            $parts[] = $result['off_host'].' z innej domeny';
        }
        if ($result['timed_out']) {
            $parts[] = 'przerwane limitem czasu';
        }

        return 'Gotowe — '.implode(', ', $parts).'.';
    }
}
