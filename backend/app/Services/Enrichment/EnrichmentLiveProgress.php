<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductEnrichmentBatchItem;
use Illuminate\Support\Facades\Schema;

/**
 * Bieżący krok modelu w logu batcha — widać co OpenRouter/lokalny właśnie robi i ile trwa.
 */
final class EnrichmentLiveProgress
{
    private ?int $batchId = null;

    private ?int $productId = null;

    private string $step = '';

    private string $provider = '';

    public function bind(int $batchId, Product $product): void
    {
        $this->batchId = $batchId;
        $this->productId = $product->id;
        $this->step = '';
        $this->provider = '';
    }

    public function clear(): void
    {
        $this->batchId = null;
        $this->productId = null;
        $this->step = '';
        $this->provider = '';
    }

    public function isBound(): bool
    {
        return $this->batchId !== null && $this->productId !== null;
    }

    public function step(string $label): void
    {
        $this->step = trim($label);
        if ($this->step === '') {
            return;
        }
        $this->write($this->step.'…');
    }

    public function waiting(string $model, string $provider = ''): void
    {
        if ($provider !== '') {
            $this->provider = $provider;
        }
        $this->write($this->compose($model, 'czeka…'));
    }

    public function done(
        string $model,
        float $seconds,
        ?int $promptTokens = null,
        ?int $completionTokens = null,
    ): void {
        $time = number_format(max(0, $seconds), 1, ',', '');
        $parts = [$time.' s'];
        if ($completionTokens !== null && $seconds >= 0.05) {
            $tps = (int) round($completionTokens / $seconds);
            if ($tps > 0) {
                $parts[] = $tps.' tok/s';
            }
        } elseif ($completionTokens !== null) {
            $parts[] = $completionTokens.' tok.';
        } elseif ($promptTokens !== null) {
            $parts[] = $promptTokens.' tok. in';
        }
        $this->write($this->compose($model, implode(' · ', $parts)));
    }

    private function compose(string $model, string $tail): string
    {
        $model = trim($model);
        $bits = array_values(array_filter([
            $this->provider !== '' ? $this->provider : null,
            $this->step !== '' ? $this->step : null,
            $model !== '' ? $model : null,
            $tail,
        ], static fn (?string $bit): bool => $bit !== null && $bit !== ''));

        return implode(' · ', $bits);
    }

    private function write(string $message): void
    {
        if (! $this->isBound()) {
            return;
        }
        $message = mb_substr($message, 0, 500);
        $batch = ProductEnrichmentBatch::query()->find($this->batchId);
        if ($batch === null || $batch->isCancelled()) {
            return;
        }
        $batch->update(['message' => $message]);
        if (! Schema::hasTable('product_enrichment_batch_items')) {
            return;
        }
        ProductEnrichmentBatchItem::query()
            ->where('batch_id', $this->batchId)
            ->where('product_id', $this->productId)
            ->update(['message' => $message]);
    }
}
