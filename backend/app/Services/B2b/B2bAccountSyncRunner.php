<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Jeden przebieg synchronizacji konta: status na koncie (running/ok/failed/cancelled), wpis w
 * b2b_sync_runs z postępem i dziennikiem (poza --dry-run) + wynik.
 */
final class B2bAccountSyncRunner
{
    public const ALREADY_RUNNING = 'Pobieranie tego konta już trwa.';

    public function __construct(
        private readonly B2bConnectorRegistry $connectors,
        private readonly B2bCatalogSync $sync,
    ) {}

    /**
     * @param  (callable(string): void)|null  $onProduct
     * @param  string  $trigger  B2bSyncRun::TRIGGER_* — skąd przebieg ruszył
     * @param  B2bConnector|null  $connector  gotowy łącznik (testy); domyślnie z rejestru wg konta
     * @return array<string, mixed> wynik B2bCatalogSync::run + sync_run_id (null przy --dry-run)
     *
     * @throws RuntimeException gdy konto ma już przebieg w toku (ALREADY_RUNNING)
     */
    public function run(
        B2bAccount $account,
        ?int $limit = null,
        bool $dryRun = false,
        ?bool $withImages = null,
        int $delayMs = 150,
        ?callable $onProduct = null,
        string $trigger = B2bSyncRun::TRIGGER_CLI,
        ?B2bConnector $connector = null,
    ): array {
        $progress = null;
        if (! $dryRun) {
            $progress = $this->claim($account, $trigger);
        }

        try {
            $result = $this->sync->run(
                $account,
                $connector ?? $this->connectors->make($account, $delayMs),
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
            $variants = ($result['progress_unit'] ?? null) === B2bSyncRun::UNIT_VARIANTS;
            $message = match (true) {
                $result['cancelled'] => sprintf(
                    'Zatrzymano ręcznie po %d z %d%s',
                    $result['processed'],
                    $result['progress_total'],
                    $variants ? ' wersji' : '',
                ),
                // limit czasu łącznika: status ok, resztę dokończy następny przebieg (znaki najdawniej sprawdzane)
                $result['partial'] => sprintf(
                    'Częściowy: %d/%d %s — kontynuacja w następnym przebiegu',
                    $result['processed'],
                    $result['progress_total'],
                    $variants ? 'wersji' : 'produktów',
                )."\n".$summary,
                default => $summary,
            };
            $message = mb_substr($message, 0, 2000);
            $account->forceFill([
                'last_sync_status' => $status,
                'last_sync_finished_at' => now(),
                'last_sync_message' => $message,
            ])->save();
            if ($result['cancelled'] || $result['partial']) {
                $progress?->log('warn', strtok($message, "\n") ?: $message);
            }
            $progress?->log('info', strtok($summary, "\n") ?: $summary);
            $progress?->finish($status, $message);
        }

        return [...$result, 'sync_run_id' => $progress?->run()->id];
    }

    /**
     * Zajęcie konta jednym warunkowym UPDATE (harmonogram, „Sprawdź teraz” i CLI nie pobierają naraz tego samego
     * konta) i wpis przebiegu w tej samej transakcji — nie zostaje konto „running” bez przebiegu, którego
     * b2b:sync-due nie umiałby uznać za przerwany.
     */
    private function claim(B2bAccount $account, string $trigger): B2bSyncProgress
    {
        return DB::transaction(function () use ($account, $trigger): B2bSyncProgress {
            $claimed = B2bAccount::query()
                ->whereKey($account->id)
                ->where(static function ($query): void {
                    $query->whereNull('last_sync_status')->orWhere('last_sync_status', '<>', 'running');
                })
                ->update([
                    'last_sync_status' => 'running',
                    'last_sync_started_at' => now(),
                    'last_sync_finished_at' => null,
                    'last_sync_message' => null,
                    'sync_requested_at' => null,
                ]);
            if ($claimed === 0) {
                throw new RuntimeException(self::ALREADY_RUNNING);
            }
            $account->refresh();

            return B2bSyncProgress::start($account, $trigger);
        });
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
        if (($result['progress_unit'] ?? null) === B2bSyncRun::UNIT_VARIANTS) {
            $text .= sprintf(
                ' · wersje: %d/%d · wycofane wersje: %d',
                $result['processed'],
                $result['progress_total'],
                $result['variants_removed'],
            );
        }
        if ($result['errors'] !== []) {
            $text .= "\nPrzykładowe problemy: ".implode('; ', array_slice($result['errors'], 0, 3));
        }

        return mb_substr($text, 0, 2000);
    }
}
