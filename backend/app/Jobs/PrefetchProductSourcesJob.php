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
use Illuminate\Support\Facades\Cache;
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

    /**
     * Przekroczony czas = od razu failed() i przekazanie produktu do EnrichProductJob, bez ponowień.
     * Ponowienie po limicie czasu powtarzało to samo wolne szukanie (przy force od czystej pamięci zapytań)
     * i za każdym razem zlecało opis od nowa, a po 8 próbach produkt zostawał bez EnrichProductJob.
     * Batch #491 (Ansell, 06.10.2026): 117 ze 150 prefetchy padło tak po ~1,5 h, 110 pozycji wisiało
     * w „running” bez żadnego joba w kolejce, a jedna karta dostała 8 przebiegów opisu.
     */
    public bool $failOnTimeout = true;

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
            // bez tego karta z anulowanej partii zostawała „w kolejce” na zawsze (opisu nikt już nie zleci)
            $enrichment->restoreAfterCancel((int) $batch->id, (int) $product->id, 'Anulowano przez użytkownika');
            $this->delete();

            return;
        }

        if ($this->handedOverToEnrich()) {
            Log::info('Product source prefetch skipped, product already handed over to enrich', [
                'product_id' => $product->id,
                'batch_id' => $batch->id,
                'attempts' => $this->attempts(),
            ]);

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

        $dispatchEnrich = fn () => $this->dispatchEnrichOnce();

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
            $enrichment->restoreAfterCancel((int) $batch->id, (int) $product->id, 'Anulowano przez użytkownika');
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

    /**
     * Prefetch to tylko rozgrzanie źródeł: jego porażka (limit czasu, wyczerpane próby) nie może zostawić
     * produktu bez EnrichProductJob, bo pozycja batcha wisiałaby w „running” na zawsze. Opis sam szuka
     * na żywo, gdy pakietu z prefetchu brak.
     */
    public function failed(?Throwable $e): void
    {
        $batch = ProductEnrichmentBatch::query()->find($this->batchId);
        if ($batch === null) {
            return;
        }
        if ($batch->isCancelled()) {
            // prefetch padł (limit czasu) już po anulowaniu — opisu nikt nie zleci, karta wraca do stanu sprzed kolejki
            app(ProductEnrichmentService::class)->restoreAfterCancel((int) $batch->id, $this->productId, 'Anulowano przez użytkownika');

            return;
        }

        Log::info('Product source prefetch failed, enrich will search live', [
            'product_id' => $this->productId,
            'batch_id' => $this->batchId,
            'error' => $e?->getMessage(),
        ]);
        $this->dispatchEnrichOnce();
    }

    /**
     * Ten produkt w tej partii przeszedł już do opisu: zlecony EnrichProductJob albo pozycja w stanie końcowym.
     * Kolejka bazodanowa oddaje to samo zadanie drugi raz, gdy worker nie zdoła go usunąć po skończonej pracy
     * (partia #507, 09.10.2026: zakleszczenie przy `delete from jobs`, ponowienie po retry_after 480 s, już po opisie
     * karty). Drugi przebieg ustawiał pozycję na „running”, a opisu nie zlecał (znacznik) — pozycja wisiała do końca.
     * Pozycja partii obok znacznika, bo znacznik ginie z wyczyszczoną pamięcią podręczną.
     */
    private function handedOverToEnrich(): bool
    {
        if (Cache::has(self::enrichDispatchedKey($this->batchId, $this->productId))) {
            return true;
        }
        $status = ProductEnrichmentBatchItem::query()
            ->where('batch_id', $this->batchId)
            ->where('product_id', $this->productId)
            ->value('status');

        return in_array($status, [
            ProductEnrichmentBatchItem::STATUS_DONE,
            ProductEnrichmentBatchItem::STATUS_FAILED,
            ProductEnrichmentBatchItem::STATUS_MANUAL,
            ProductEnrichmentBatchItem::STATUS_SKIPPED,
            ProductEnrichmentBatchItem::STATUS_CANCELLED,
        ], true);
    }

    /**
     * Jeden EnrichProductJob na produkt w batchu. Znacznik w cache, nie w pamięci joba: przy limicie czasu
     * worker ginie, więc failed() nie wie, czy przed przerwaniem opis był już zlecony (wyszukiwanie skończone,
     * przerwało dopiero pobieranie stron).
     */
    private function dispatchEnrichOnce(): void
    {
        if (! Cache::add(self::enrichDispatchedKey($this->batchId, $this->productId), true, now()->addDay())) {
            return;
        }
        EnrichProductJob::dispatch($this->productId, $this->batchId, $this->force);
    }

    private static function enrichDispatchedKey(int $batchId, int $productId): string
    {
        return 'enrichment:prefetch-enrich-dispatched:'.$batchId.':'.$productId;
    }
}
