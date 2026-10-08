<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Models\ProductEnrichmentBatch;

/**
 * Komunikat postępu partii po zamknięciu pozycji — wspólny dla przebiegu karty (EnrichProductJob) i opisu wspólnego
 * modelu (ApplyModelDescriptionJob), żeby baner pokazywał te same liczby niezależnie od tego, które zadanie je zmieniło.
 */
trait RefreshesBatchProgress
{
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
}
