<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
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
        foreach (B2bAccount::query()->orderBy('id')->get() as $account) {
            // stan mógł się zmienić, gdy trwał przebieg poprzedniego konta
            $account->refresh();
            if (! $account->isSyncDue(now())) {
                continue;
            }

            $this->info("Konto #{$account->id} {$account->username}");
            try {
                $runner->run($account);
                $this->line('  '.$account->last_sync_message);
            } catch (Throwable $e) {
                $this->warn('  '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
