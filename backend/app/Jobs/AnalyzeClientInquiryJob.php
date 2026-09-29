<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ClientInquiryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Analiza zapytania klienta w tle: czytanie maila, szukanie w katalogu, szkic listu (ClientInquiryService::
 * runQueuedAnalysis). Samo id i identyfikator przebiegu — bez SerializesModels, bo zapytanie wolno skasować
 * w trakcie analizy; wtedy przebieg po cichu nic nie zapisze.
 */
class AnalyzeClientInquiryJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Bez automatycznego ponowienia: każda próba to kilka minut pracy wspólnego modelu, a wyszukiwanie i tak
     * znosi awarię pojedynczej frazy. Po błędzie handlowiec ponawia przyciskiem na stronie zapytania.
     */
    public int $tries = 1;

    /** 50 pozycji na wspólnym modelu to ok. 5 min; zapas na kolejkę modelu. Mniej niż retry_after (1500 s). */
    public int $timeout = 1200;

    public const QUEUE = 'inquiries';

    public function __construct(
        public readonly int $inquiryId,
        public readonly string $runId,
    ) {
        $this->onConnection(config('queue.inquiries_connection'));
        $this->onQueue(self::QUEUE);
    }

    public function handle(ClientInquiryService $inquiries): void
    {
        $inquiries->runQueuedAnalysis($this->inquiryId, $this->runId);
    }

    /** Limit czasu albo worker zabity w trakcie — zapytanie nie może zostać w „Analizuję…” na zawsze. */
    public function failed(?Throwable $e): void
    {
        app(ClientInquiryService::class)->markAnalysisFailed(
            $this->inquiryId,
            $this->runId,
            'Analiza zapytania została przerwana'.($e !== null ? ' ('.mb_substr($e->getMessage(), 0, 200).')' : '').'. Uruchom ją ponownie.',
        );
    }
}
