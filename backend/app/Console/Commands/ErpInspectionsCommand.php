<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Erp\ErpXlGateway;
use App\Services\Inspections\InspectionCustomerDetails;
use App\Services\Inspections\InspectionDueBuilder;
use App\Services\Inspections\InspectionSaleSync;
use App\Support\PolishTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Moduł Przeglądy: katalog usług i pozycje faktur i WZ z usługami i towarami pozycji z ERP XL (okno 60 dni), potem
 * przebudowa terminów i dane z kartoteki klientów z terminami (adres, telefony, osoby kontaktowe, opiekun) — co noc 05:10 czasu polskiego (tylko przy ERPXL_ENABLED). Pierwszy pełny odczyt ręcznie:
 * `erp:inspections --since=2019-01-01`. Reguły w InspectionSaleSync i InspectionDueBuilder; XL tylko czytany.
 */
final class ErpInspectionsCommand extends Command
{
    protected $signature = 'erp:inspections
        {--since= : Początek okna odczytu faktur (RRRR-MM-DD); domyślnie 60 dni wstecz}
        {--no-xl : Tylko przebudowa terminów, bez odczytu z ERP XL}';

    protected $description = 'Kopiuje z ERP XL usługi i faktury do modułu Przeglądy i przelicza terminy przeglądów';

    public function handle(ErpXlGateway $gateway, InspectionSaleSync $sync, InspectionDueBuilder $builder, InspectionCustomerDetails $details): int
    {
        if ((bool) $this->option('no-xl')) {
            return $this->rebuild($builder);
        }

        $since = null;
        $raw = $this->option('since');
        if ($raw !== null && $raw !== '') {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', (string) $raw, PolishTime::TIMEZONE);
            if (! $parsed instanceof CarbonImmutable || $parsed->format('Y-m-d') !== $raw || $parsed->greaterThan(PolishTime::today())) {
                $this->error('Podaj datę początku okna jako RRRR-MM-DD, najpóźniej dzisiejszą (np. --since=2019-01-01).');

                return self::INVALID;
            }
            $since = $parsed;
        }

        if (! $gateway->configured()) {
            $this->warn('Połączenie z ERP XL jest wyłączone albo nieuzupełnione (ERPXL_*) — pomijam.');

            return self::SUCCESS;
        }

        try {
            $stats = $sync->run($since);
        } catch (Throwable $e) {
            // nieaktualne wiersze kasowane dopiero po pełnym odczycie — zostaje stan z poprzedniego udanego odczytu
            $this->error('Odczyt faktur do Przeglądów z ERP XL przerwany: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Usługi w katalogu XL: %d, pozycje faktur zapisane: %d, usunięte nieaktualne: %d, towary z doczytaną historią: %d.',
            $stats['services'],
            $stats['lines'],
            $stats['deleted'],
            $stats['history_positions'],
        ));

        $result = $this->rebuild($builder);
        if ($result !== self::SUCCESS) {
            return $result;
        }

        try {
            $info = $details->sync();
        } catch (Throwable $e) {
            // terminy już przeliczone — brak danych kartoteki nie psuje listy, zostają dane z poprzedniej nocy
            $this->error('Odczyt kartoteki klientów do Przeglądów przerwany: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }
        $this->info(sprintf(
            'Kartoteka klientów: %d klientów, %d osób kontaktowych%s.',
            $info['customers'],
            $info['contacts'],
            $info['unavailable'] !== [] ? '; bez prawa odczytu w XL: '.implode(', ', $info['unavailable']) : '',
        ));

        return self::SUCCESS;
    }

    private function rebuild(InspectionDueBuilder $builder): int
    {
        try {
            $rows = $builder->rebuild();
        } catch (Throwable $e) {
            $this->error('Przeliczenie terminów przeglądów przerwane: '.$e->getMessage());
            report($e);

            return self::FAILURE;
        }
        $this->info(sprintf('Terminy przeglądów przeliczone: %d (klient i pozycja).', $rows));

        return self::SUCCESS;
    }
}
