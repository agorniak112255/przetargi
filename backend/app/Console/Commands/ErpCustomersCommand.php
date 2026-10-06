<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Erp\ErpCustomerSync;
use App\Services\Erp\ErpXlGateway;
use Illuminate\Console\Command;
use Throwable;

class ErpCustomersCommand extends Command
{
    protected $signature = 'erp:customers';

    protected $description = 'Odczytuje z Comarch ERP XL klientów do kampanii: e-maile, operatora z największą liczbą dokumentów sprzedaży i zakupy z 24 mies. (z WZ) (XL tylko czytany)';

    public function handle(ErpXlGateway $gateway, ErpCustomerSync $sync): int
    {
        if (! $gateway->configured()) {
            $this->warn('Połączenie z ERP XL jest wyłączone albo nieuzupełnione (ERPXL_*) — pomijam.');

            return self::SUCCESS;
        }
        try {
            $stats = $sync->run();
        } catch (Throwable $e) {
            $this->error('Odczyt klientów z ERP XL przerwany: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }
        $this->info(sprintf('Kontrahentów: %d, z e-mailem: %d, pozycji zakupów (klient × towar): %d.', $stats['customers'], $stats['with_email'], $stats['items']));

        return self::SUCCESS;
    }
}
