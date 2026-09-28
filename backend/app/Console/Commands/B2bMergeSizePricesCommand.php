<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\B2b\B2bSizePriceMerger;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * Scala karty rozbite według ceny rozmiaru (etap 2 decyzji użytkownika 28.09.2026) z listy ostatniego pełnego
 * przebiegu konta (b2b_sync_runs.size_spread) — zasady i warunki w B2bSizePriceMerger. Domyślnie podgląd; --apply
 * scala wyrób po wyrobie (każdy we własnej transakcji) i przed każdym zapisuje jego wiersze do kopii JSONL
 * (storage/app/repair-backups, jedna linia na wyrób, potem linia „committed” albo „rolled_back”). Trwająca
 * synchronizacja konta przerywa całe polecenie. --limit=N — tylko N pierwszych wyrobów do scalenia (próba).
 */
final class B2bMergeSizePricesCommand extends Command
{
    protected $signature = 'b2b:merge-size-prices
        {account : ID konta B2B (widoczne na karcie w Cenniki → B2B)}
        {--apply : Scal (bez tej flagi tylko podgląd)}
        {--limit= : Najwyżej tyle wyrobów do scalenia (próba)}
        {--with-tenders : Scal także wyroby z pozycjami przetargów na droższym rozmiarze}
        {--details : Podgląd: każdy wyrób osobno (domyślnie tylko pominięte i pierwsze do scalenia)}';

    protected $description = 'Scala karty rozbite według ceny rozmiaru w jedną kartę z tabelą rozmiarów (podgląd bez --apply)';

    private const PREVIEW_LINES = 30;

    private const BACKUP_FAILED = 7302;

    public function handle(B2bSizePriceMerger $merger): int
    {
        B2bAccountSyncRunner::raiseMemoryLimit();
        $account = B2bAccount::query()->find((int) $this->argument('account'));
        if ($account === null) {
            $this->error('Nie ma konta B2B o ID '.$this->argument('account').'.');

            return self::FAILURE;
        }
        $apply = (bool) $this->option('apply');
        $withTenders = (bool) $this->option('with-tenders');
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;

        $spread = $merger->spread($account);
        if ($spread['reason'] !== null) {
            $this->error('Konto #'.$account->id.': '.$spread['reason'].'.');

            return self::FAILURE;
        }
        $run = $spread['run'];
        $this->line(sprintf(
            'Konto #%d · przebieg #%d (%s) · wyrobów na kilku kartach: %d%s · %s',
            $account->id,
            $run->id,
            $run->finished_at?->format('Y-m-d H:i') ?? '?',
            $spread['total'],
            $spread['truncated'] ? ' (lista przycięta do '.count($spread['groups']).' — po scaleniu puść synchronizację i polecenie ponownie)' : '',
            $apply ? 'SCALANIE' : 'podgląd, nic nie zapisuje',
        ));

        $handle = null;
        $path = null;
        if ($apply) {
            $dir = storage_path('app/repair-backups');
            if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
                $this->error('Kopia zapasowa nie powstanie: brak katalogu '.$dir.' — nic nie scalono.');

                return self::FAILURE;
            }
            $path = $dir.DIRECTORY_SEPARATOR.'size-prices-'.$account->id.'-'.now()->format('Ymd-His').'.jsonl';
            $handle = @fopen($path, 'ab');
            if ($handle === false) {
                $this->error('Kopia zapasowa nie powstanie: '.$path.' — nic nie scalono.');

                return self::FAILURE;
            }
        }

        $merged = 0;
        $toMerge = 0;
        $skipped = [];
        $shown = 0;
        $sizes = 0;
        $tenders = 0;
        $skuRenamed = 0;
        foreach ($spread['groups'] as $group) {
            if ($limit !== null && $toMerge >= $limit) {
                break;
            }
            try {
                $plan = $apply
                    ? $merger->apply($account, $group, $withTenders, function (array $row) use ($handle): void {
                        $this->writeLine($handle, ['status' => 'before', ...$row]);
                    })
                    : $merger->plan($account, $group, $withTenders);
            } catch (RuntimeException $e) {
                // trwający przebieg albo nieudany zapis kopii — przerwać całe polecenie (scalone wyroby zostają)
                if (in_array($e->getCode(), [B2bSizePriceMerger::SYNC_RUNNING, self::BACKUP_FAILED], true)) {
                    $this->error($e->getMessage());
                    $this->summary($apply, $merged, $toMerge, $skipped, $path);

                    return self::FAILURE;
                }
                $plan = ['merge' => false, 'reason' => 'błąd: '.$e->getMessage(), 'sku' => (string) ($group['sku'] ?? '?')];
                $this->logRollback($handle, $group);
            } catch (JsonException $e) {
                $this->error('Kopia zapasowa wyrobu '.($group['sku'] ?? '?').' nie powstała: '.$e->getMessage().' — scalanie przerwane.');
                $this->summary($apply, $merged, $toMerge, $skipped, $path);

                return self::FAILURE;
            } catch (Throwable $e) {
                $plan = ['merge' => false, 'reason' => 'błąd: '.$e->getMessage(), 'sku' => (string) ($group['sku'] ?? '?')];
                $this->logRollback($handle, $group);
            }

            if (! $plan['merge']) {
                $kind = self::reasonKind((string) $plan['reason']);
                $skipped[$kind] = ($skipped[$kind] ?? 0) + 1;
                if ($this->option('details') || $shown < self::PREVIEW_LINES) {
                    $this->line('  – '.$plan['sku'].': pominięty — '.$plan['reason']);
                    $shown++;
                }

                continue;
            }
            $toMerge++;
            $sizes += (int) $plan['sizes'];
            $tenders += (int) $plan['tenders'];
            $skuRenamed += $plan['sku_to'] !== null ? 1 : 0;
            if ($apply) {
                $merged++;
                $this->writeLine($handle, ['status' => 'committed', 'sku' => $plan['sku'], 'keep_product_id' => $plan['keep_id']]);
            }
            if ($this->option('details') || $shown < self::PREVIEW_LINES) {
                $this->line(sprintf(
                    '  %s %s: zostaje #%d (%s) ← %s · rozmiarów %d · %s–%s %s%s%s%s',
                    $apply ? '✓' : '+',
                    $plan['sku'],
                    $plan['keep_id'],
                    $plan['keep']?->sku ?? '?',
                    implode(', ', array_map(static fn (int $id): string => '#'.$id, $plan['drop_ids'])),
                    $plan['sizes'],
                    number_format((float) $plan['min'], 2, ',', ''),
                    number_format((float) $plan['max'], 2, ',', ''),
                    $plan['currency'],
                    $plan['tenders'] > 0 ? ' · przetargi '.$plan['tenders'] : '',
                    $plan['sku_to'] !== null ? ' · SKU → '.$plan['sku_to'] : '',
                    $plan['sku_note'] !== null ? ' · '.$plan['sku_note'] : '',
                ));
                $shown++;
            }
        }
        if ($handle !== null) {
            fclose($handle);
        }

        $this->line(sprintf('Rozmiarów w scalanych wyrobach: %d · pozycji przetargów przepiętych: %d · SKU zmienione: %d', $sizes, $tenders, $skuRenamed));
        $this->summary($apply, $merged, $toMerge, $skipped, $path);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $skipped
     */
    private function summary(bool $apply, int $merged, int $toMerge, array $skipped, ?string $path): void
    {
        arsort($skipped);
        $total = array_sum($skipped);
        if ($total > 0) {
            $this->line('Pominięte: '.$total);
            foreach (array_slice($skipped, 0, 15, true) as $reason => $count) {
                $this->line('  '.$count.' × '.$reason);
            }
        }
        if ($apply) {
            $this->info('Scalono wyrobów: '.$merged.($path !== null ? '. Kopia zapasowa: '.$path : ''));
        } else {
            $this->info('Do scalenia: '.$toMerge.'. Zapis: --apply (najpierw próba z --limit=10).');
        }
    }

    /**
     * Rodzaj powodu do podsumowania: bez numerów kart, kodów pozycji i wartości w nawiasach — 2557 wyrobów Mascot
     * daje wtedy kilka wierszy zamiast tysięcy jednostkowych.
     */
    private static function reasonKind(string $reason): string
    {
        return (string) preg_replace(
            ['/#\d+/u', '/\b(pozycja|pozycję|rozmiar|kod) \S+/u', '/\([^)]*\)/u', '/„[^”]*”/u', '/\s+/u'],
            ['#…', '$1 …', '(…)', '„…”', ' '],
            $reason,
        );
    }

    /**
     * @param  resource|null  $handle
     * @param  array<string, mixed>  $group
     */
    private function logRollback($handle, array $group): void
    {
        if ($handle !== null) {
            $this->writeLine($handle, ['status' => 'rolled_back', 'sku' => (string) ($group['sku'] ?? '')]);
        }
    }

    /**
     * Jedna linia JSONL, od razu na dysk — kopia wyrobu jest w pliku przed jego scaleniem.
     *
     * @param  resource|null  $handle
     * @param  array<string, mixed>  $row
     *
     * @throws JsonException
     */
    private function writeLine($handle, array $row): void
    {
        if ($handle === null) {
            return;
        }
        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (@fwrite($handle, $json."\n") === false || ! fflush($handle)) {
            throw new RuntimeException('Zapis kopii zapasowej się nie udał — scalanie przerwane.', self::BACKUP_FAILED);
        }
    }
}
