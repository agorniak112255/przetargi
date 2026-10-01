<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Erp\ErpXlGateway;
use App\Services\Erp\InventoryHistoryRebuild;
use Illuminate\Console\Command;
use Throwable;

class ErpInventoryHistoryCommand extends Command
{
    protected $signature = 'erp:inventory-history
                            {--days=365 : Ile dni wstecz od pierwszego zapisu nocnego}
                            {--dry-run : Tylko policz i pokaż kontrolę, bez zapisu}
                            {--force : Odtwórz też dni już odtworzone i zapisz mimo niezgodnej kontroli}
                            {--work=5 : Sekundy pracy zapytań XL przed odpoczynkiem}
                            {--pause=5 : Sekundy odpoczynku serwera SQL}';

    protected $description = 'Odtwarza historię zapasów wstecz z ruchów partii w ERP XL (XL tylko czytany, z przerwami dla serwera SQL)';

    public function handle(ErpXlGateway $gateway, InventoryHistoryRebuild $rebuild): int
    {
        if (! $gateway->configured()) {
            $this->warn('Połączenie z ERP XL jest wyłączone albo nieuzupełnione (ERPXL_*) — pomijam.');

            return self::SUCCESS;
        }
        $days = max(1, min(800, (int) $this->option('days')));
        $this->info(sprintf('Historia zapasów: %d dni wstecz, %s s pracy XL / %s s odpoczynku.', $days, $this->option('work'), $this->option('pause')));
        try {
            $r = $rebuild->run(
                $days,
                write: ! $this->option('dry-run'),
                force: (bool) $this->option('force'),
                say: fn (string $m) => $this->line($m),
                workSeconds: max(0.5, (float) $this->option('work')),
                pauseSeconds: max(0.0, (float) $this->option('pause')),
            );
        } catch (Throwable $e) {
            $this->error('Odtwarzanie historii przerwane: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        if ($r['status'] === 'no_live') {
            $this->warn('Brak zapisu nocnego (erp:inventory-snapshot) — nie ma z czym sprawdzić odtworzenia.');

            return self::FAILURE;
        }
        if ($r['status'] === 'nothing') {
            $this->info(sprintf('Nic do odtworzenia: %d dni już jest w historii (--force odtworzy je ponownie).', $r['skipped']));

            return self::SUCCESS;
        }
        $this->line(sprintf('Towarów: %d · zapytania XL %.1f s · odpoczynek %.0f s · partii ze stanem poniżej zera: %d',
            $r['items'], $r['sql_seconds'], $r['pause_seconds'], $r['negative_lots']));
        if ($r['unknown_types'] !== []) {
            $this->warn('Nieznane typy dokumentów (pominięte): '.json_encode($r['unknown_types']));
        }
        $this->line('Odtworzone (wszystkie oddziały, magazyny handlowe, początek miesiąca):');
        $zl = static fn (float $v): string => number_format($v, 0, ',', ' ');
        $this->table(['dzień', 'cały towar', 'bez sprzedaży pół roku', 'bez sprzedaży rok', 'leży ponad pół roku', 'leży ponad rok'], array_map(
            static fn (array $p): array => [$p['date'], $zl($p['stock']), $zl($p['no_sale_6']), $zl($p['no_sale_12']), $zl($p['lot_age_6']), $zl($p['lot_age_12'])],
            $r['preview'] ?? [],
        ));
        $this->line('Kontrola: dzień '.$r['first_live'].' odtworzony z dokumentów wobec zapisu nocnego (wszystkie oddziały):');
        $this->table(['magazyny', 'koszyk', 'zapis nocny', 'odtworzone', 'różnica'], array_map(
            static fn (array $s): array => [$s['scope'], $s['bucket'], number_format($s['live'], 2, ',', ' '), number_format($s['rebuilt'], 2, ',', ' '),
                number_format($s['diff'] * 100, 2, ',', ' ').' %'.($s['bad'] ? ' ✗' : '')],
            array_values(array_filter($r['seam'], static fn (array $s): bool => $s['location'] === '')),
        ));

        return match ($r['status']) {
            'saved' => $this->done(sprintf('Zapisano %d dni (%d wierszy), od %s do %s; pominięte (już były): %d.',
                count($r['days']), $r['rows'], $r['days'][0] ?? '-', end($r['days']) ?: '-', $r['skipped'])),
            'dry_run' => $this->done('Próba bez zapisu — kontrola zgodna; uruchom bez --dry-run, żeby zapisać.'),
            default => $this->failed('Kontrola niezgodna (✗) — nic nie zapisano. Sprawdź różnice; --force zapisze mimo to.'),
        };
    }

    private function done(string $message): int
    {
        $this->info($message);

        return self::SUCCESS;
    }

    private function failed(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
