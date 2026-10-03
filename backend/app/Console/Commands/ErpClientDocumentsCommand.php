<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Faktury i paragony klientów z ERP XL (nagłówki FS/PA/FSE i korekty) do erp_sale_documents — co noc 05:40 czasu
 * polskiego (tylko przy ERPXL_ENABLED).
 *
 * ZAŚLEPKA (krok 0) — przebieg dopisuje strumień B (ErpClientDocumentSync).
 */
final class ErpClientDocumentsCommand extends Command
{
    protected $signature = 'erp:client-documents {--months=36 : Okno dokumentów w miesiącach} {--dry-run : Bez zapisu}';

    protected $description = 'Kopiuje z ERP XL nagłówki faktur i paragonów klientów z zakładki Klienci';

    public function handle(): int
    {
        $this->info('Jeszcze niegotowe.');

        return self::SUCCESS;
    }
}
