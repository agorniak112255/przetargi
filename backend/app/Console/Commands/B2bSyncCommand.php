<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use App\Services\B2b\B2bAccountSyncRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Ręczne pobranie cennika z konta B2B (Cenniki → B2B): produkty, ceny konta, opisy, zdjęcia.
 * Uruchamiane też w tle przez b2b:sync-due (--trigger=manual|schedule).
 */
final class B2bSyncCommand extends Command
{
    /** @var list<string> */
    public const TRIGGERS = [B2bSyncRun::TRIGGER_CLI, B2bSyncRun::TRIGGER_MANUAL, B2bSyncRun::TRIGGER_SCHEDULE];

    protected $signature = 'b2b:sync
        {account : ID konta B2B (widoczne na karcie w Cenniki → B2B)}
        {--limit= : Ile produktów sprawdzić (próbka)}
        {--dry-run : Tylko pobiera i pokazuje, nic nie zapisuje}
        {--no-images : Bez pobierania zdjęć}
        {--delay=150 : Przerwa między zapytaniami do dostawcy w ms}
        {--trigger=cli : Skąd przebieg ruszył: cli (ręcznie z konsoli), manual („Sprawdź teraz”), schedule (harmonogram)}';

    protected $description = 'Pobiera produkty, ceny, opisy i zdjęcia z witryny B2B dostawcy';

    public function handle(B2bAccountSyncRunner $runner): int
    {
        $trigger = (string) $this->option('trigger');
        if (! in_array($trigger, self::TRIGGERS, true)) {
            $this->error('Nieznany --trigger „'.$trigger.'”. Dozwolone: '.implode(', ', self::TRIGGERS).'.');

            return self::FAILURE;
        }

        $account = B2bAccount::query()->find((int) $this->argument('account'));
        if ($account === null) {
            $this->error('Nie ma konta B2B o ID '.$this->argument('account').'.');

            return self::FAILURE;
        }

        // Pliki tworzone w trakcie (cache, zdjęcia, pliki produktów) należą wtedy do roota i równolegle
        // działające kolejki oraz cron (jako właściciel witryny) zaczynają pomijać produkty. Po zakończeniu
        // właściciela prostuje StorageOwnership, ale w trakcie przebiegu kolizji nie da się uniknąć.
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->warn('Uruchomione jako root — lepiej: sudo -u '.(function_exists('posix_getpwuid')
                ? (string) (posix_getpwuid((int) fileowner(base_path('artisan')))['name'] ?? 'właściciel-witryny')
                : 'właściciel-witryny').' … artisan b2b:sync');
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

        // Przebieg z b2b:sync-due pisze do storage/logs/b2b-sync.log — wiersz na produkt tylko z -v (postęp jest
        // w dzienniku przebiegu w panelu), inaczej plik rósłby o tysiące wierszy co noc.
        $productVerbosity = $trigger === B2bSyncRun::TRIGGER_CLI ? null : 'v';

        if ($trigger !== B2bSyncRun::TRIGGER_CLI) {
            // fatal (np. brak pamięci) nie wykona finally poniżej — bez tego „Sprawdź teraz” czekałoby
            // na wygaśnięcie blokady uruchomienia, choć przebieg już się zakończył niepowodzeniem
            register_shutdown_function(static function () use ($account): void {
                if (B2bAccountSyncRunner::isFatalError(error_get_last())) {
                    Cache::forget(B2bSyncDueCommand::launchGuardKey((int) $account->id));
                }
            });
        }

        try {
            $result = $runner->run(
                $account,
                limit: $limit,
                dryRun: $dryRun,
                withImages: $this->option('no-images') ? false : null,
                delayMs: max(0, (int) $this->option('delay')),
                onProduct: function (string $line) use ($productVerbosity): void {
                    $this->line('  '.$line, null, $productVerbosity);
                },
                trigger: $trigger,
            );
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            if ($trigger !== B2bSyncRun::TRIGGER_CLI) {
                // konto zajęte albo przebieg zakończony — kolejne „Sprawdź teraz” może ruszyć od razu
                Cache::forget(B2bSyncDueCommand::launchGuardKey((int) $account->id));
            }
        }

        $this->newLine();
        $variants = ($result['progress_unit'] ?? null) === B2bSyncRun::UNIT_VARIANTS;
        $this->info(sprintf(
            '%s: %d · sprawdzone: %d · nowe: %d · zaktualizowane: %d · bez zmian: %d · pominięte: %d · zmiany cen: %d · nowe opisy: %d · zdjęcia: %d · pliki: %d · karty ze sklepu: %d',
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
            $result['documents'] ?? 0,
            $result['shop_fields'] ?? 0,
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
