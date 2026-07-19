<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Product;
use App\Services\Vector\ProductEmbeddingIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReindexProductEmbeddingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [20, 60];

    public int $timeout = 90;

    public function __construct(
        public readonly int $productId,
        public readonly bool $force = false,
    ) {}

    public function handle(ProductEmbeddingIndexer $indexer): void
    {
        if (! $indexer->shouldIndex()) {
            return;
        }

        $product = Product::query()->find($this->productId);
        if ($product === null) {
            return;
        }

        $indexer->index($product, $this->force);
    }
}
