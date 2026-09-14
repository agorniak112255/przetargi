<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use Throwable;

/**
 * Jeden przebieg synchronizacji konta: status na koncie (running/ok/failed/cancelled), wpis w
 * b2b_sync_runs z postępem i dziennikiem (poza --dry-run) + wynik.
 */
final class B2bAccountSyncRunner
{
    public function __construct(
        private readonly B2bConnectorRegistry $connectors,
        private readonly B2bCatalogSync $sync,
    ) {}

    /**
     * @param  (callable(string): void)|null  $onProduct
     * @param  string  $trigger  B2bSyncRun::TRIGGER_* — skąd przebieg ruszył
     * @return array<string, mixed> wynik B2bCatalogSync::run + sync_run_id (null przy --dry-run)
     */
    public function run(
        B2bAccount $account,
        ?int $limit = null,
        bool $dryRun = false,
        ?bool $withImages = null,
        int $delayMs = 150,
        ?callable $onProduct = null,
        string $trigger = B2bSyncRun::TRIGGER_CLI,
    ): array {
        $progress = null;
        if (! $dryRun) {
            $account->forceFill([
                'last_sync_status' => 'running',
                'last_sync_started_at' => now(),
                'last_sync_finished_at' => null,
                'last_sync_message' => null,
                'sync_requested_at' => null,
            ])->save();
            $progress = B2bSyncProgress::start($account, $trigger);
        }

        try {
            $result = $this->sync->run(
                $account,
                $this->connectors->make($account, $delayMs),
                limit: $limit,
                dryRun: $dryRun,
                withImages: $withImages ?? (bool) ($account->sync_images ?? true),
                onProduct: $onProduct,
                progress: $progress,
            );
        } catch (Throwable $e) {
            if (! $dryRun) {
                $message = mb_substr($e->getMessage(), 0, 2000);
                $account->forceFill([
                    'last_sync_status' => 'failed',
                    'last_sync_finished_at' => now(),
                    'last_sync_message' => $message,
                ])->save();
                $progress?->log('error', $message);
                $progress?->finish(B2bSyncRun::STATUS_FAILED, $message);
            }

            throw $e;
        }

        if (! $dryRun) {
            $summary = $this->summary($result);
            $status = $result['cancelled'] ? B2bSyncRun::STATUS_CANCELLED : B2bSyncRun::STATUS_OK;
            $message = $result['cancelled']
                ? sprintf('Zatrzymano ręcznie po %d z %d', $result['seen'], $progress?->run()->total ?? $result['total_remote'])
                : $summary;
            $account->forceFill([
                'last_sync_status' => $status,
                'last_sync_finished_at' => now(),
                'last_sync_message' => $message,
            ])->save();
            if ($result['cancelled']) {
                $progress?->log('warn', $message);
            }
            $progress?->log('info', strtok($summary, "\n") ?: $summary);
            $progress?->finish($status, $message);
        }

        return [...$result, 'sync_run_id' => $progress?->run()->id];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function summary(array $result): string
    {
        $text = sprintf(
            'W B2B: %d · sprawdzone: %d · nowe: %d · zaktualizowane: %d · bez zmian: %d · pominięte: %d · zmiany cen: %d · nowe opisy: %d · zdjęcia: %d',
            $result['total_remote'],
            $result['seen'],
            $result['created'],
            $result['updated'],
            $result['unchanged'],
            $result['skipped'],
            $result['prices_changed'],
            $result['descriptions'],
            $result['images'],
        );
        if ($result['errors'] !== []) {
            $text .= "\nPrzykładowe problemy: ".implode('; ', array_slice($result['errors'], 0, 3));
        }

        return mb_substr($text, 0, 2000);
    }
}
