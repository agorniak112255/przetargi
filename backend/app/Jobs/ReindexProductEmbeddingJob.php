<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\EmbeddingRequestException;
use App\Models\Product;
use App\Services\Vector\ProductEmbeddingIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReindexProductEmbeddingJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [20, 60];

    public int $timeout = 90;

    /** Osobna kolejka — reindeks całego katalogu nie może blokować pobierania opisów. */
    public const QUEUE = 'embeddings';

    /** Po 401/403 kolejka embeddings nie ma sensu — każdy następny job dostanie to samo. */
    public const HALT_CACHE_KEY = 'product_embeddings_halt';

    /**
     * Import cennika i enrichment potrafią zapisać ten sam produkt kilka razy pod
     * rząd — bez unikalności każdy zapis wkładałby do kolejki osobny reindeks.
     * Blokada schodzi w momencie wykonania joba, więc kolejna edycja i tak trafi.
     */
    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $productId,
        public readonly bool $force = false,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function uniqueId(): string
    {
        return (string) $this->productId;
    }

    public static function clearHalt(): void
    {
        Cache::forget(self::HALT_CACHE_KEY);
    }

    public function handle(ProductEmbeddingIndexer $indexer): void
    {
        if (! $indexer->shouldIndex()) {
            return;
        }

        if (Cache::has(self::HALT_CACHE_KEY)) {
            return;
        }

        $product = Product::query()->find($this->productId);
        if ($product === null) {
            return;
        }

        try {
            $indexer->index($product, $this->force);
        } catch (EmbeddingRequestException $e) {
            if ($e->isTerminal()) {
                $this->haltRun();
                if ($this->job !== null) {
                    $this->fail($e);

                    return;
                }
            }

            throw $e;
        }
    }

    private function haltRun(): void
    {
        Cache::put(self::HALT_CACHE_KEY, 1, 86400);
        if (! Schema::hasTable('jobs')) {
            return;
        }

        DB::table('jobs')
            ->where('queue', self::QUEUE)
            ->whereNull('reserved_at')
            ->delete();
    }
}
