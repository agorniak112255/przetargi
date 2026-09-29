<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Erp\ErpItemSync;
use App\Services\Erp\ErpXlGateway;
use Illuminate\Console\Command;
use Throwable;

class ErpSyncCommand extends Command
{
    protected $signature = 'erp:sync
                            {--limit= : Tylko tyle pierwszych towarów (próba; bez oznaczania usuniętych)}
                            {--match : Po udanej synchronizacji przelicz powiązania z kartami (erp:match)}';

    protected $description = 'Kopiuje towary Comarch ERP XL ze stanami, dostawcami i ostatnimi zakupami (XL tylko czytany)';

    public function handle(ErpXlGateway $gateway, ErpItemSync $sync): int
    {
        if (! $gateway->configured()) {
            $this->warn('Połączenie z ERP XL jest wyłączone albo nieuzupełnione (ERPXL_*) — pomijam.');

            return self::SUCCESS;
        }
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        try {
            $stats = $sync->run($limit, function (int $done): void {
                $this->output->write("\rTowarów: ".$done);
            });
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('Synchronizacja ERP XL przerwana: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }
        $this->newLine();
        $this->info(sprintf(
            'Towarów: %d, ze stanem na magazynach HANDEL: %d, pozycji PZ: %d, usuniętych z XL: %d.',
            $stats['items'], $stats['with_trade_stock'], $stats['purchases'], $stats['removed'],
        ));

        if ($this->option('match')) {
            return $this->call('erp:match');
        }

        return self::SUCCESS;
    }
}
