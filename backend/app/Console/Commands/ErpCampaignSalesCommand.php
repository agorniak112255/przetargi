<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Erp\ErpCampaignSalesSync;
use App\Services\Erp\ErpXlGateway;
use Illuminate\Console\Command;
use Throwable;

class ErpCampaignSalesCommand extends Command
{
    protected $signature = 'erp:campaign-sales';

    protected $description = 'Odczytuje z Comarch ERP XL faktury i paragony z towarami niedawno wysłanych kampanii — wynik „kupili odbiorcy” (XL tylko czytany)';

    public function handle(ErpXlGateway $gateway, ErpCampaignSalesSync $sync): int
    {
        if (! $gateway->configured()) {
            $this->warn('Połączenie z ERP XL jest wyłączone albo nieuzupełnione (ERPXL_*) — pomijam.');

            return self::SUCCESS;
        }
        try {
            $stats = $sync->run();
        } catch (Throwable $e) {
            $this->error('Odczyt sprzedaży kampanii z ERP XL przerwany: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }
        $this->info(sprintf('Kampanii: %d, towarów: %d, pozycji FS/PA/WZ i korekt: %d, usuniętych (anulowane): %d.', $stats['campaigns'], $stats['items'], $stats['lines'], $stats['removed']));

        return self::SUCCESS;
    }
}
