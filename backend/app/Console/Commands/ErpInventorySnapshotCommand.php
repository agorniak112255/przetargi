<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Erp\InventorySnapshots;
use Illuminate\Console\Command;
use Throwable;

class ErpInventorySnapshotCommand extends Command
{
    protected $signature = 'erp:inventory-snapshot
                            {--force : Nadpisz zapis z tego dnia}';

    protected $description = 'Zapisuje dzisiejszy stan zapasów (liczby raportu dla zarządu) do historii — po pełnym odczycie stanów z XL';

    public function handle(InventorySnapshots $snapshots): int
    {
        try {
            $result = $snapshots->take((bool) $this->option('force'));
        } catch (Throwable $e) {
            $this->error('Zapis historii zapasów przerwany: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }
        match ($result['status']) {
            'saved' => $this->info(sprintf('Historia zapasów: zapisano %s (%d wierszy).', $result['date'], $result['rows'])),
            'exists' => $this->info(sprintf('Historia zapasów: dzień %s jest już zapisany (--force nadpisuje).', $result['date'])),
            'stale' => $this->warn(sprintf('Historia zapasów: stany nie są z dziś (najstarszy odczyt %s) — pomijam.', $result['date'])),
            'empty' => $this->warn('Historia zapasów: brak odczytanych stanów — pomijam.'),
        };

        return self::SUCCESS;
    }
}
