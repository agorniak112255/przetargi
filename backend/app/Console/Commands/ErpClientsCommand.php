<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Erp\ErpClientImport;
use App\Services\Erp\ErpXlGateway;
use Illuminate\Console\Command;
use Throwable;

class ErpClientsCommand extends Command
{
    protected $signature = 'erp:clients
        {--year= : rok zakupów (domyślnie bieżący)}
        {--min=100 : próg zakupów netto w zł (FS + PA + FSE + WZ bez faktury minus korekty)}
        {--dry-run : tylko policz, bez zapisu}';

    protected $description = 'Zakładka Klienci: kontrahenci ERP XL z zakupami w roku od progu — pełna karta, osoby kontaktowe, opiekun (XL tylko czytany)';

    public function handle(ErpXlGateway $gateway, ErpClientImport $import): int
    {
        if (! $gateway->configured()) {
            $this->warn('Połączenie z ERP XL jest wyłączone albo nieuzupełnione (ERPXL_*) — pomijam.');

            return self::SUCCESS;
        }
        $year = $this->option('year') !== null ? (int) $this->option('year') : (int) now()->year;
        $min = (float) str_replace(',', '.', (string) $this->option('min'));
        if ($year < 2000 || $year > 2100 || $min < 0) {
            $this->error('Nieprawidłowy rok albo próg.');

            return self::INVALID;
        }

        try {
            $stats = $import->run($year, $min, (bool) $this->option('dry-run'));
        } catch (Throwable $e) {
            $this->error('Odczyt klientów z ERP XL przerwany: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%sRok %d, próg %s zł: kontrahentów od progu %d; nowych %d, odświeżonych %d, powiązanych po NIP %d, bez karty w XL %d.',
            $this->option('dry-run') ? '[bez zapisu] ' : '',
            $year,
            number_format($min, 2, ',', ' '),
            $stats['qualifying'],
            $stats['created'],
            $stats['updated'],
            $stats['linked_by_nip'],
            $stats['without_card'],
        ));
        if ($stats['unavailable'] !== []) {
            $this->warn('Login XL nie ma prawa odczytu pól: '.implode(', ', $stats['unavailable']).' — zostają puste (albo z poprzedniego odczytu).');
        }

        return self::SUCCESS;
    }
}
