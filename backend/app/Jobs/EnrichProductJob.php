<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Services\Enrichment\ProductEnrichmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class EnrichProductJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [15, 45, 90];

    public int $timeout = 120;

    public function __construct(
        public readonly int $productId,
        public readonly int $batchId,
        public readonly bool $force = false,
    ) {}

    public function handle(ProductEnrichmentService $enrichment): void
    {
        $product = Product::query()->find($this->productId);
        $batch = ProductEnrichmentBatch::query()->find($this->batchId);

        if ($product === null || $batch === null) {
            return;
        }

        $batch->update([
            'status' => ProductEnrichmentBatch::STATUS_RUNNING,
            'current_sku' => $product->sku,
            'current_name' => mb_substr($product->name, 0, 255),
            'message' => 'Tavily + skrót AI (lub cache SKU)…',
        ]);

        $enrichment->enrichProduct($product, $this->force);
        $enrichment->markBatchItem($batch, true);

        $batch->refresh();
        $processed = $batch->done + $batch->failed;
        $batch->update([
            'message' => "OK {$batch->done} · błędy {$batch->failed} · pozostało ".max(0, $batch->total - $processed),
            'current_sku' => $processed >= $batch->total ? null : $batch->current_sku,
            'current_name' => $processed >= $batch->total ? null : $batch->current_name,
        ]);
    }

    public function failed(?Throwable $e): void
    {
        $product = Product::query()->find($this->productId);
        if ($product !== null && $product->enrichment_status !== Product::ENRICHMENT_DONE) {
            $product->update([
                'enrichment_status' => Product::ENRICHMENT_FAILED,
                'enrichment_error' => mb_substr($e?->getMessage() ?? 'Nieznany błąd enrichmentu', 0, 2000),
            ]);
        }

        $batch = ProductEnrichmentBatch::query()->find($this->batchId);
        if ($batch === null) {
            return;
        }

        $processed = $batch->done + $batch->failed;
        if ($processed < $batch->total) {
            app(ProductEnrichmentService::class)->markBatchItem($batch, false);
        }

        $batch->refresh();
        $processed = $batch->done + $batch->failed;
        $batch->update([
            'message' => 'Błąd: '.mb_substr($e?->getMessage() ?? 'nieznany', 0, 200)
                ." · OK {$batch->done}/{$batch->total}",
            'current_sku' => $processed >= $batch->total ? null : $product?->sku,
            'current_name' => $processed >= $batch->total ? null : mb_substr((string) $product?->name, 0, 255),
        ]);
    }
}
