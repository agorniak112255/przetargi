<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Erp\ErpXlGateway;
use Illuminate\Console\Command;

class ErpPingCommand extends Command
{
    protected $signature = 'erp:ping';

    protected $description = 'Sprawdza połączenie z Comarch ERP XL (tylko odczyt)';

    public function handle(ErpXlGateway $gateway): int
    {
        $result = $gateway->ping();
        $result['ok'] ? $this->info($result['message']) : $this->error($result['message']);

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
