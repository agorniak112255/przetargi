<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use App\Services\B2b\B2bSyncLauncher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Z harmonogramu: konta z ustawionym sprawdzaniem (codziennie / co tydzień) albo „Sprawdź teraz”.
 * Nie przez kolejkę — pełne pobranie trwa dłużej niż timeout workerów. Każde należne konto rusza przez
 * B2bSyncLauncher (poza testami: osobny proces `b2b:sync` w tle), więc konta różnych dostawców pobierają się
 * równolegle, a polecenie kończy się w kilka sekund.
 */
final class B2bSyncDueCommand extends Command
{
    /**
     * Blokada ponownego uruchomienia konta, dopóki proces w tle nie zajmie konta (wtedy isSyncDue = false).
     * Dłuższa niż odstęp harmonogramu (5 min), żeby następne wywołanie nie uruchomiło drugiego procesu; zdejmuje
     * ją b2b:sync po zakończeniu. Gdy proces padnie przed zajęciem konta, konto ruszy ponownie po wygaśnięciu.
     * Podwójnego przebiegu i tak nie będzie — zajęcie konta w runnerze jest atomowe; blokada oszczędza procesy.
     */
    public const LAUNCH_GUARD_MINUTES = 10;

    protected $signature = 'b2b:sync-due';

    protected $description = 'Uruchamia synchronizację kont B2B, którym minął termin sprawdzenia cennika';

    public static function launchGuardKey(int $accountId): string
    {
        return 'b2b:sync-launch:'.$accountId;
    }

    public function handle(B2bSyncLauncher $launcher): int
    {
        $this->sweepStaleRuns();

        foreach (B2bAccount::query()->orderBy('id')->get() as $account) {
            // stan mógł się zmienić (przebieg poprzedniego konta w tym procesie, proces w tle, panel)
            $account->refresh();
            if (! $account->isSyncDue(now())) {
                continue;
            }

            // odczyt przed startem — runner czyści sync_requested_at
            $trigger = $account->sync_requested_at !== null ? B2bSyncRun::TRIGGER_MANUAL : B2bSyncRun::TRIGGER_SCHEDULE;

            $this->info("Konto #{$account->id} {$account->username}");

            $guard = self::launchGuardKey((int) $account->id);
            // add() zapisuje tylko, gdy klucza nie ma — atomowo w magazynie cache (database)
            if (! Cache::add($guard, now()->toIso8601String(), now()->addMinutes(self::LAUNCH_GUARD_MINUTES))) {
                $this->line('  uruchomione wcześniej — czeka na start procesu w tle');

                continue;
            }

            try {
                $finished = $launcher->launch($account, $trigger);
            } catch (Throwable $e) {
                Cache::forget($guard);
                $this->warn('  '.$e->getMessage());
                Log::warning('B2B: przebieg konta nie ruszył albo się nie udał', [
                    'b2b_account_id' => $account->id,
                    'trigger' => $trigger,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($finished) {
                Cache::forget($guard);
                $this->line('  '.$account->last_sync_message);
            } else {
                $this->line("  uruchomiono w tle (trigger {$trigger}, dziennik procesu: storage/logs/b2b-sync.log)");
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
