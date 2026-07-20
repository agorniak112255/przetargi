<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\TavilyQuotaExceededException;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Enrichment\TavilyQuotaGuard;
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

        try {
            TavilyQuotaGuard::assertAllowed();

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
        } catch (TavilyQuotaExceededException $e) {
            TavilyQuotaGuard::block($e->getMessage());
            $this->recordItemFailure($product, $batch, $e->getMessage(), 'Limit Tavily — zatrzymano batch');
            // bez ponowień — kolejne joby też odpadną na assertAllowed()
            $this->delete();
        }
    }

    public function failed(?Throwable $e): void
    {
        if ($e instanceof TavilyQuotaExceededException) {
            return;
        }

        $product = Product::query()->find($this->productId);
        $batch = ProductEnrichmentBatch::query()->find($this->batchId);
        $message = $e?->getMessage() ?? 'Nieznany błąd enrichmentu';
        $this->recordItemFailure(
            $product,
            $batch,
            $message,
            'Błąd: '.mb_substr($message, 0, 200),
        );
    }

    private function recordItemFailure(
        ?Product $product,
        ?ProductEnrichmentBatch $batch,
        string $error,
        string $batchPrefix,
    ): void {
        if ($product !== null && $product->enrichment_status !== Product::ENRICHMENT_DONE) {
            $product->update([
                'enrichment_status' => Product::ENRICHMENT_FAILED,
                'enrichment_error' => mb_substr($error, 0, 2000),
            ]);
        }

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
            'message' => $batchPrefix." · OK {$batch->done}/{$batch->total}",
            'current_sku' => $processed >= $batch->total ? null : $product?->sku,
            'current_name' => $processed >= $batch->total ? null : mb_substr((string) $product?->name, 0, 255),
        ]);
    }
}
