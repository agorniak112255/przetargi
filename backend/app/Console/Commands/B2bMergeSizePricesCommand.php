<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bSizePriceMerger;
use Illuminate\Console\Command;

/**
 * Scala karty rozbite według ceny rozmiaru (etap 2 decyzji użytkownika 28.09.2026) z listy ostatniego pełnego
 * przebiegu konta (b2b_sync_runs.size_spread) — zasady i warunki w B2bSizePriceMerger; ta sama pętla co przycisk
 * „Scal rozmiary” w panelu (zadanie w tle porcjami). Domyślnie podgląd; --apply scala wyrób po wyrobie (każdy we
 * własnej transakcji) i przed każdym zapisuje jego wiersze do kopii JSONL (storage/app/repair-backups). Trwająca
 * synchronizacja konta przerywa całe polecenie. --limit=N — tylko N pierwszych wyrobów do scalenia (próba).
 */
final class B2bMergeSizePricesCommand extends Command
{
    protected $signature = 'b2b:merge-size-prices
        {account : ID konta B2B (widoczne na karcie w Cenniki → B2B)}
        {--apply : Scal (bez tej flagi tylko podgląd)}
        {--limit= : Najwyżej tyle wyrobów do scalenia (próba)}
        {--with-tenders : Scal także wyroby z pozycjami przetargów na droższym rozmiarze}
        {--details : Każdy wyrób osobno (domyślnie pierwsze wiersze i podsumowanie)}';

    protected $description = 'Scala karty rozbite według ceny rozmiaru w jedną kartę z tabelą rozmiarów (podgląd bez --apply)';

    private const PREVIEW_LINES = 30;

    public function handle(B2bSizePriceMerger $merger): int
    {
        B2bAccountSyncRunner::raiseMemoryLimit();
        $account = B2bAccount::query()->find((int) $this->argument('account'));
        if ($account === null) {
            $this->error('Nie ma konta B2B o ID '.$this->argument('account').'.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        $spread = $merger->spread($account);
        if ($spread['reason'] !== null) {
            $this->error('Konto #'.$account->id.': '.$spread['reason'].'.');

            return self::FAILURE;
        }
        $this->line(sprintf(
            'Konto #%d · przebieg #%d (%s) · wyrobów na kilku kartach: %d%s · %s',
            $account->id,
            $spread['run']->id,
            $spread['run']->finished_at?->format('Y-m-d H:i') ?? '?',
            $spread['total'],
            $spread['truncated'] ? ' (lista przycięta do '.count($spread['groups']).' — po scaleniu puść synchronizację i polecenie ponownie)' : '',
            $apply ? 'SCALANIE' : 'podgląd, nic nie zapisuje',
        ));

        $path = $apply ? B2bSizePriceMerger::newBackupPath($account) : null;
        $result = $merger->process($account, $spread['groups'], 0, $apply, (bool) $this->option('with-tenders'), $limit, 0, $path, null);

        $lines = $this->option('details') ? $result['lines'] : array_slice($result['lines'], 0, self::PREVIEW_LINES);
        foreach ($lines as $line) {
            $this->line('  '.$line);
        }
        $this->line(sprintf(
            'Rozmiarów w scalanych wyrobach: %d · pozycji przetargów przepiętych: %d · SKU zmienione: %d',
            $result['sizes'],
            $result['tenders'],
            $result['sku_renamed'],
        ));
        $skipped = $result['skipped'];
        arsort($skipped);
        if ($skipped !== []) {
            $this->line('Pominięte: '.array_sum($skipped));
            foreach (array_slice($skipped, 0, 15, true) as $reason => $count) {
                $this->line('  '.$count.' × '.$reason);
            }
        }
        if ($result['stop'] !== null) {
            $this->error($result['stop']);
        }
        if ($apply) {
            $this->info('Scalono wyrobów: '.$result['merged'].($path !== null ? '. Kopia zapasowa: '.$path : ''));
        } else {
            $this->info('Do scalenia: '.$result['to_merge'].'. Zapis: --apply (najpierw próba z --limit=10).');
        }

        return $result['stop'] === null ? self::SUCCESS : self::FAILURE;
    }
}
