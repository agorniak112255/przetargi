<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use App\Services\B2b\B2bAccountSyncRunner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Z harmonogramu: konta z ustawionym sprawdzaniem (codziennie / co tydzień) albo „Sprawdź teraz”.
 * Nie przez kolejkę — pełne pobranie trwa dłużej niż timeout workerów.
 */
final class B2bSyncDueCommand extends Command
{
    protected $signature = 'b2b:sync-due';

    protected $description = 'Synchronizuje konta B2B, którym minął termin sprawdzenia cennika';

    public function handle(B2bAccountSyncRunner $runner): int
    {
        $this->sweepStaleRuns();

        foreach (B2bAccount::query()->orderBy('id')->get() as $account) {
            // stan mógł się zmienić, gdy trwał przebieg poprzedniego konta
            $account->refresh();
            if (! $account->isSyncDue(now())) {
                continue;
            }

            // odczyt przed startem — runner czyści sync_requested_at
            $trigger = $account->sync_requested_at !== null ? B2bSyncRun::TRIGGER_MANUAL : B2bSyncRun::TRIGGER_SCHEDULE;

            $this->info("Konto #{$account->id} {$account->username}");
            try {
                $runner->run($account, trigger: $trigger);
                $this->line('  '.$account->last_sync_message);
            } catch (Throwable $e) {
                $this->warn('  '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    /**
     * Przebieg zabity w trakcie (restart serwera, OOM) zostaje „running” — bez tego okno postępu
     * wisiałoby, a konto nie byłoby sprawdzane wcale (isSyncDue i zajęcie konta w runnerze pomijają
     * konto „running” bez limitu godzin). Jedyny sygnał przerwania: brak postępu przez STALE_MINUTES.
     * Wyścig z drugim procesem kończy się wyjątkiem runnera „już trwa” — konto jest pomijane.
     */
    private function sweepStaleRuns(): void
    {
        $message = 'Przerwane — brak postępu ponad '.B2bSyncRun::STALE_MINUTES.' min (np. restart serwera)';

        $stale = B2bSyncRun::query()
            ->where('status', B2bSyncRun::STATUS_RUNNING)
            ->where('updated_at', '<', now()->subMinutes(B2bSyncRun::STALE_MINUTES))
            ->get();

        foreach ($stale as $run) {
            $log = $run->log ?? [];
            $log[] = ['at' => now()->toIso8601String(), 'level' => 'error', 'text' => $message];
            $run->forceFill([
                'status' => B2bSyncRun::STATUS_FAILED,
                'finished_at' => now(),
                'current_sku' => null,
                'message' => $message,
                'log' => array_slice($log, -B2bSyncRun::LOG_LIMIT),
            ])->save();

            $account = $run->account;
            $stillRunning = B2bSyncRun::query()
                ->where('b2b_account_id', $run->b2b_account_id)
                ->where('status', B2bSyncRun::STATUS_RUNNING)
                ->exists();
            if ($account !== null && $account->last_sync_status === 'running' && ! $stillRunning) {
                $account->forceFill([
                    'last_sync_status' => 'failed',
                    'last_sync_finished_at' => now(),
                    'last_sync_message' => $message,
                ])->save();
            }

            $this->warn("Przebieg #{$run->id} konta #{$run->b2b_account_id}: {$message}");
        }
    }
}
