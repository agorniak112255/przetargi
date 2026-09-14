<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use App\Services\B2b\B2bAccountSyncRunner;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ręczne pobranie cennika z konta B2B (Cenniki → B2B): produkty, ceny konta, opisy, zdjęcia.
 */
final class B2bSyncCommand extends Command
{
    protected $signature = 'b2b:sync
        {account : ID konta B2B (widoczne na karcie w Cenniki → B2B)}
        {--limit= : Ile produktów sprawdzić (próbka)}
        {--dry-run : Tylko pobiera i pokazuje, nic nie zapisuje}
        {--no-images : Bez pobierania zdjęć}
        {--delay=150 : Przerwa między zapytaniami do dostawcy w ms}';

    protected $description = 'Pobiera produkty, ceny, opisy i zdjęcia z witryny B2B dostawcy';

    public function handle(B2bAccountSyncRunner $runner): int
    {
        $account = B2bAccount::query()->find((int) $this->argument('account'));
        if ($account === null) {
            $this->error('Nie ma konta B2B o ID '.$this->argument('account').'.');

            return self::FAILURE;
        }

        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $dryRun = (bool) $this->option('dry-run');
        $this->info(sprintf(
            'Konto #%d %s · %s%s',
            $account->id,
            $account->username,
            $limit !== null ? "próbka {$limit}" : 'wszystkie produkty',
            $dryRun ? ' · bez zapisu (--dry-run)' : '',
        ));

        try {
            $result = $runner->run(
                $account,
                limit: $limit,
                dryRun: $dryRun,
                withImages: $this->option('no-images') ? false : null,
                delayMs: max(0, (int) $this->option('delay')),
                onProduct: function (string $line): void {
                    $this->line('  '.$line);
                },
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $variants = ($result['progress_unit'] ?? null) === B2bSyncRun::UNIT_VARIANTS;
        $this->info(sprintf(
            '%s: %d · sprawdzone: %d · nowe: %d · zaktualizowane: %d · bez zmian: %d · pominięte: %d · zmiany cen: %d · nowe opisy: %d · zdjęcia: %d',
            $variants ? 'Wersji w B2B' : 'W B2B',
            $variants ? $result['progress_total'] : $result['total_remote'],
            $result['seen'],
            $result['created'],
            $result['updated'],
            $result['unchanged'],
            $result['skipped'],
            $result['prices_changed'],
            $result['descriptions'],
            $result['images'],
        ));
        foreach (array_slice($result['errors'], 0, 20) as $error) {
            $this->warn('  '.$error);
        }
        if ($result['sync_run_id'] !== null) {
            $this->info('Przebieg #'.$result['sync_run_id']);
        }

        return self::SUCCESS;
    }
}
