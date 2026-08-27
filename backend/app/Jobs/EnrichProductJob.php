<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\EnrichmentCancelledException;
use App\Exceptions\ProductSourcesNotFoundException;
use App\Exceptions\TavilyQuotaExceededException;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Services\Ai\AiSettingsService;
use App\Services\Enrichment\EnrichmentSlots;
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

    // Klient AI potrafi czekać ~2 min na przeciążony model, potem dochodzi
    // wyszukiwanie i pobieranie stron — 240 s ucinało robotę w połowie.
    public int $timeout = 420;

    /** Osobna kolejka — workery LLM nie stoją w kolejce za SearXNG. */
    public const QUEUE = 'enrich';

    public function __construct(
        public readonly int $productId,
        public readonly int $batchId,
        public readonly bool $force = false,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public function handle(
        ProductEnrichmentService $enrichment,
        AiSettingsService $aiSettings,
        EnrichmentSlots $slots,
    ): void {
        $product = Product::query()->find($this->productId);
        $batch = ProductEnrichmentBatch::query()->find($this->batchId);

        if ($product === null || $batch === null) {
            return;
        }

        if ($batch->isCancelled()) {
            $this->abandonCancelled($product);
            $this->delete();

            return;
        }

        if (! $this->force && in_array($product->enrichment_status, [
            Product::ENRICHMENT_DONE,
            Product::ENRICHMENT_MANUAL,
        ], true)) {
            $enrichment->markBatchItem($batch, true);
            $this->refreshBatchProgress($batch);

            return;
        }

        $slot = $slots->acquire(
            $this->timeout + 60,
            (float) config('ai.enrichment_slot_wait_seconds', 120)
        );
        if ($slot === null) {
            // Limit z Ustawień AI obłożony — produkt wraca do kolejki bez zużycia próby.
            self::dispatch($this->productId, $this->batchId, $this->force)
                ->delay(now()->addSeconds(10));
            $this->delete();

            return;
        }

        try {
            $this->enrich($enrichment, $aiSettings, $product, $batch);
        } finally {
            $slot->release();
        }
    }

    private function enrich(
        ProductEnrichmentService $enrichment,
        AiSettingsService $aiSettings,
        Product $product,
        ProductEnrichmentBatch $batch,
    ): void {
        $claimed = Product::query()
            ->whereKey($product->id)
            ->where('enrichment_status', Product::ENRICHMENT_QUEUED)
            ->update([
                'enrichment_status' => Product::ENRICHMENT_RUNNING,
                'enrichment_error' => null,
            ]);
        if ($claimed === 0 && ! $this->force) {
            $status = (string) $product->fresh()?->enrichment_status;
            if (in_array($status, [Product::ENRICHMENT_DONE, Product::ENRICHMENT_MANUAL], true)) {
                $enrichment->markBatchItem($batch, true);
                $this->refreshBatchProgress($batch);
            } elseif ($status === Product::ENRICHMENT_FAILED) {
                $enrichment->markBatchItem($batch, false);
                $this->refreshBatchProgress($batch);
            }

            return;
        }

        $product->refresh();

        try {
            $useLargeModel = $aiSettings->enrichmentUsesLargeModel();
            $useDuckDuckGo = $aiSettings->usesFreeWebSearch();
            if (! $useLargeModel && ! $useDuckDuckGo) {
                TavilyQuotaGuard::assertAllowed();
            }
            $enrichment->assertBatchNotCancelled($this->batchId);

            $batch->update([
                'status' => ProductEnrichmentBatch::STATUS_RUNNING,
                'current_sku' => $product->sku,
                'current_name' => mb_substr($product->name, 0, 255),
                'message' => $useDuckDuckGo
                    ? 'DuckDuckGo + lokalny model…'
                    : ($useLargeModel
                        ? 'Duży model (web search + opis)…'
                        : 'Tavily + skrót AI (lub cache SKU)…'),
            ]);

            $enrichment->enrichProduct($product, $this->force, $this->batchId);

            $batch->refresh();
            if ($batch->isCancelled()) {
                $this->abandonCancelled($product);
                $this->delete();

                return;
            }

            $enrichment->markBatchItem($batch, true);
            $this->refreshBatchProgress($batch);
        } catch (ProductSourcesNotFoundException $e) {
            $enrichment->markBatchItem($batch, true);
            $this->refreshBatchProgress($batch);
        } catch (EnrichmentCancelledException $e) {
            $this->abandonCancelled($product);
            $this->delete();
        } catch (TavilyQuotaExceededException $e) {
            TavilyQuotaGuard::block($e->getMessage());
            $this->recordItemFailure($product, $batch, $e->getMessage(), 'Limit Tavily — zatrzymano batch');
            $this->delete();
        }
    }

    public function failed(?Throwable $e): void
    {
        if ($e instanceof TavilyQuotaExceededException || $e instanceof EnrichmentCancelledException) {
            return;
        }

        $product = Product::query()->find($this->productId);
        $batch = ProductEnrichmentBatch::query()->find($this->batchId);
        if ($batch !== null && $batch->isCancelled()) {
            $this->abandonCancelled($product);

            return;
        }

        $message = $e?->getMessage() ?? 'Nieznany błąd enrichmentu';
        $this->recordItemFailure(
            $product,
            $batch,
            $message,
            'Błąd: '.mb_substr($message, 0, 200),
        );
    }

    private function refreshBatchProgress(ProductEnrichmentBatch $batch): void
    {
        $batch->refresh();
        $processed = $batch->done + $batch->failed;
        $done = $processed >= $batch->total;
        $batch->update([
            'message' => "OK {$batch->done} · błędy {$batch->failed} · pozostało ".max(0, $batch->total - $processed),
            'current_sku' => $done ? null : $batch->current_sku,
            'current_name' => $done ? null : $batch->current_name,
        ]);
    }

    private function abandonCancelled(?Product $product): void
    {
        if ($product !== null && $product->enrichment_status !== Product::ENRICHMENT_DONE) {
            $product->update([
                'enrichment_status' => Product::ENRICHMENT_FAILED,
                'enrichment_error' => 'Anulowano przez użytkownika',
            ]);
        }
    }

    private function recordItemFailure(
        ?Product $product,
        ?ProductEnrichmentBatch $batch,
        string $error,
        string $batchPrefix,
    ): void {
        $keepStatus = [Product::ENRICHMENT_DONE, Product::ENRICHMENT_MANUAL];
        if ($product !== null && ! in_array($product->enrichment_status, $keepStatus, true)) {
            $product->update([
                'enrichment_status' => Product::ENRICHMENT_FAILED,
                'enrichment_error' => mb_substr($error, 0, 2000),
            ]);
        }

        if ($batch === null || $batch->isCancelled()) {
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
