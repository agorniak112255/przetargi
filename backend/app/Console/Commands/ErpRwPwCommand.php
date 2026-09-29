<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Erp\ErpRwPwSync;
use App\Services\Erp\ErpXlGateway;
use Illuminate\Console\Command;
use Throwable;

class ErpRwPwCommand extends Command
{
    protected $signature = 'erp:rw-pw {--months=12 : Ile miesięcy wstecz}';

    protected $description = 'Odczytuje z Comarch ERP XL pary RW → PW (ten sam towar i ilość, PW do 30 dni po RW) dla zakładki Zapasy (XL tylko czytany)';

    public function handle(ErpXlGateway $gateway, ErpRwPwSync $sync): int
    {
        if (! $gateway->configured()) {
            $this->warn('Połączenie z ERP XL jest wyłączone albo nieuzupełnione (ERPXL_*) — pomijam.');

            return self::SUCCESS;
        }
        $months = max(1, min(24, (int) $this->option('months')));
        try {
            $stats = $sync->run($months);
        } catch (Throwable $e) {
            $this->error('Odczyt RW/PW z ERP XL przerwany: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }
        $this->info(sprintf('Dokumentów RW/PW (pozycji na towar): %d, par RW → PW: %d, towarów: %d (%d mies. wstecz).', $stats['moves'], $stats['pairs'], $stats['items'], $months));

        return self::SUCCESS;
    }
}
