<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Erp\ErpItemSync;
use App\Services\Erp\ErpXlGateway;
use Illuminate\Console\Command;
use Throwable;

class ErpStockCommand extends Command
{
    protected $signature = 'erp:stock';

    protected $description = 'Odświeża same stany towarów z Comarch ERP XL (bez zakupów i łączenia; XL tylko czytany)';

    public function handle(ErpXlGateway $gateway, ErpItemSync $sync): int
    {
        if (! $gateway->configured()) {
            $this->warn('Połączenie z ERP XL jest wyłączone albo nieuzupełnione (ERPXL_*) — pomijam.');

            return self::SUCCESS;
        }
        try {
            $stats = $sync->refreshStock(function (int $done): void {
                $this->output->write("\rTowarów: ".$done);
            });
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('Odświeżanie stanów z ERP XL przerwane: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }
        $this->newLine();
        $this->info(sprintf('Towarów: %d, ze zmienionym stanem: %d.', $stats['items'], $stats['changed']));

        return self::SUCCESS;
    }
}
