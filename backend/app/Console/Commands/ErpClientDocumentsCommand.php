<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Erp\ErpClientDocumentSync;
use App\Services\Erp\ErpXlGateway;
use Illuminate\Console\Command;
use Throwable;

/**
 * Faktury i paragony klientów z ERP XL (nagłówki FS/PA/FSE i korekty) do erp_sale_documents — co noc 05:40 czasu
 * polskiego (tylko przy ERPXL_ENABLED). Reguły w ErpClientDocumentSync; XL tylko czytany.
 */
final class ErpClientDocumentsCommand extends Command
{
    protected $signature = 'erp:client-documents {--months=36 : Okno dokumentów w miesiącach} {--dry-run : Bez zapisu}';

    protected $description = 'Kopiuje z ERP XL nagłówki faktur i paragonów klientów z zakładki Klienci';

    public function handle(ErpXlGateway $gateway, ErpClientDocumentSync $sync): int
    {
        if (! $gateway->configured()) {
            $this->warn('Połączenie z ERP XL jest wyłączone albo nieuzupełnione (ERPXL_*) — pomijam.');

            return self::SUCCESS;
        }
        $months = filter_var($this->option('months'), FILTER_VALIDATE_INT);
        if ($months === false || $months < ErpClientDocumentSync::MIN_MONTHS || $months > ErpClientDocumentSync::MAX_MONTHS) {
            $this->error(sprintf(
                'Okno dokumentów musi mieć od %d do %d miesięcy (karta klienta pokazuje 24 miesiące).',
                ErpClientDocumentSync::MIN_MONTHS,
                ErpClientDocumentSync::MAX_MONTHS,
            ));

            return self::INVALID;
        }
        $dryRun = (bool) $this->option('dry-run');

        try {
            $stats = $sync->run($months, $dryRun);
        } catch (Throwable $e) {
            // nic nie skasowane — karta zostaje z dokumentami z poprzedniego udanego odczytu
            $this->error('Odczyt faktur klientów z ERP XL przerwany: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%sKlienci z numerem XL: %d, dokumenty od %s: %d, pominięte (nieznany rodzaj, data albo kontrahent): %d, usunięte nieaktualne: %d.',
            $dryRun ? '[bez zapisu] ' : '',
            $stats['clients'],
            $stats['from'],
            $stats['documents'],
            $stats['skipped'],
            $stats['removed'],
        ));

        $quality = $stats['quality'];
        $line = sprintf(
            'Kontrola z zakładką Klienci (zakupy netto %d): sprawdzonych klientów %d, różnica u %d klientów.',
            $quality['year'],
            $quality['checked'],
            $quality['mismatched'],
        );
        if ($quality['mismatched'] === 0) {
            $this->info($line);
        } else {
            $this->warn($line);
            foreach ($quality['examples'] as $example) {
                $this->warn(sprintf(
                    '  %s (klient #%d): zakładka Klienci %s zł, dokumenty %s zł.',
                    $example['name'],
                    $example['client_id'],
                    number_format($example['clients_net'], 2, ',', ' '),
                    number_format($example['documents_net'], 2, ',', ' '),
                ));
            }
        }

        return self::SUCCESS;
    }
}
