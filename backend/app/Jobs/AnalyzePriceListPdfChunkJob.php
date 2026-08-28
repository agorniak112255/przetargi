<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Enrichment\EnrichmentSlots;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

class AnalyzePriceListPdfChunkJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 8;

    public int $timeout = 420;

    public const QUEUE = EnrichProductJob::QUEUE;

    public function __construct(
        public readonly string $runId,
        public readonly int $index,
        public readonly int $total,
        public readonly string $chunk,
        public readonly string $prompt,
        public readonly ?string $manufacturerHint,
    ) {
        $this->onQueue(self::QUEUE);
    }

    public static function resultKey(string $runId, int $index): string
    {
        return 'pdf_ai:'.$runId.':'.$index;
    }

    public function handle(OpenAiCompatibleClient $llm, EnrichmentSlots $slots): void
    {
        $slot = $slots->acquire(
            $this->timeout + 60,
            (float) config('ai.enrichment_slot_wait_seconds', 120)
        );
        if ($slot === null) {
            self::dispatch(
                $this->runId,
                $this->index,
                $this->total,
                $this->chunk,
                $this->prompt,
                $this->manufacturerHint,
            )->delay(now()->addSeconds(5));
            $this->delete();

            return;
        }

        try {
            $part = $this->index + 1;
            $messages = [
                [
                    'role' => 'system',
                    'content' => 'Jesteś ekspertem od cenników BHP. Zwracasz WYŁĄCZNIE JSON z tablicą products.',
                ],
                [
                    'role' => 'user',
                    'content' => $this->prompt
                        ."\n\nTo jest CZĘŚĆ {$part}/{$this->total} tekstu PDF. "
                        .'Wypisz KAŻDĄ pozycję z tej części — nie streszczaj i nie pomijaj wierszy.'
                        ."\n\nTEKST:\n".$this->chunk
                        ."\n\nWzorce: (A) 119.00 32 80.92; (B) NV15S-00138 … 50 PCE … 2.62.",
                ],
            ];
            $raw = $llm->chat($messages, null, true, ['max_tokens' => 8000], AiTask::PriceListPdf);
            Cache::put(self::resultKey($this->runId, $this->index), [
                'ok' => true,
                'content' => $raw['content'],
                'model' => $raw['model'],
            ], 3600);
        } catch (Throwable $e) {
            Cache::put(self::resultKey($this->runId, $this->index), [
                'ok' => false,
                'error' => $e->getMessage(),
            ], 3600);
        } finally {
            $slot->release();
        }
    }
}
