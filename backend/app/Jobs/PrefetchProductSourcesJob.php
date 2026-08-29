<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\EnrichmentCancelledException;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductEnrichmentBatchItem;
use App\Services\Enrichment\PrefetchSlots;
use App\Services\Enrichment\ProductEnrichmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Szuka kart i grzeje cache HTML zanim EnrichProductJob weźmie slot vLLM.
 * Limit równoległości: enrichment.prefetch_concurrency (domyślnie 5).
 */
class PrefetchProductSourcesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 8;

    /** @var list<int> */
    public array $backoff = [5, 5, 10];

    public int $timeout = 180;

    /** Osobna kolejka — szukanie nie blokuje slotów vLLM. */
    public const QUEUE = 'prefetch';

    public function __construct(
        public readonly int $productId,
        public readonly int $batchId,
        public readonly bool $force = false,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(ProductEnrichmentService $enrichment, PrefetchSlots $slots): void
    {
        $product = Product::query()->find($this->productId);
        $batch = ProductEnrichmentBatch::query()->find($this->batchId);
        if ($product === null || $batch === null) {
            return;
        }

        if ($batch->isCancelled()) {
            $this->delete();

            return;
        }

        if (! $this->force && in_array($product->enrichment_status, [
            Product::ENRICHMENT_DONE,
            Product::ENRICHMENT_MANUAL,
        ], true)) {
            return;
        }

        $lock = $slots->acquire($this->timeout);
        if ($lock === null) {
            self::dispatch($this->productId, $this->batchId, $this->force)
                ->delay(now()->addSeconds(5));
            $this->delete();

            return;
        }

        $dispatched = false;
        $dispatchEnrich = function () use (&$dispatched): void {
            if ($dispatched) {
                return;
            }
            $dispatched = true;
            EnrichProductJob::dispatch($this->productId, $this->batchId, $this->force);
        };

        try {
            $batch->update([
                'status' => ProductEnrichmentBatch::STATUS_RUNNING,
                'current_sku' => $product->sku,
                'current_name' => mb_substr($product->name, 0, 255),
                'message' => 'Prefetch źródeł (wyszukiwarka)…',
            ]);
            $enrichment->recordBatchProduct(
                $batch,
                $product,
                ProductEnrichmentBatchItem::STATUS_RUNNING,
                'Prefetch źródeł (wyszukiwarka)…',
            );
            $enrichment->prefetchProductSources($product, $this->force, $this->batchId, $dispatchEnrich);
        } catch (EnrichmentCancelledException) {
            $this->delete();

            return;
        } catch (Throwable $e) {
            Log::info('Product source prefetch failed, enrich will search live', [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $lock->release();
        }

        $dispatchEnrich();
    }
}
