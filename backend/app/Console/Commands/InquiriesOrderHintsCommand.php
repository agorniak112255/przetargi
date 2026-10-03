<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Clients\InquiryClientLinker;
use App\Services\Erp\ErpXlGateway;
use App\Services\Inquiries\OrderHintBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Co noc 05:55 czasu polskiego: powiązania zapytań z klientami (InquiryClientLinker::linkAll — zawsze, bez ERP XL),
 * potem podpowiedzi „możliwe zamówienie z oferty” z pozycji dokumentów ERP XL (OrderHintBuilder — tylko przy
 * włączonym połączeniu z XL). Wyniku zapytania polecenie nigdy nie wpisuje. Błąd którejkolwiek części kończy przebieg
 * kodem błędu (alert w „Stanie systemu”), ale nie zatrzymuje drugiej.
 */
final class InquiriesOrderHintsCommand extends Command
{
    protected $signature = 'inquiries:order-hints {--days=60 : Odpowiedzi z tylu ostatnich dni}';

    protected $description = 'Wiąże zapytania z klientami i szuka w ERP XL możliwych zamówień z wysłanych ofert';

    public function handle(InquiryClientLinker $linker, ErpXlGateway $gateway, OrderHintBuilder $hints): int
    {
        DB::disableQueryLog();
        $failed = false;
        $days = max(1, (int) $this->option('days'));

        try {
            $links = $linker->linkAll();
            $this->info(sprintf(
                'Powiązania z klientami — sprawdzone zapytania: %d, ręczne (bez zmian): %d, po adresie e-mail: %d, po NIP-ie: %d, zmienione: %d, zdjęte: %d.',
                $links['checked'], $links['manual'], $links['linked_email'], $links['linked_nip'], $links['changed'], $links['removed'],
            ));
        } catch (Throwable $e) {
            report($e);
            $this->error('Powiązania zapytań z klientami przerwane: '.$e->getMessage());
            $failed = true;
        }

        if (! (bool) config('erpxl.enabled') || ! $gateway->configured()) {
            $this->warn('Połączenie z ERP XL jest wyłączone albo nieuzupełnione (ERPXL_*) — podpowiedzi zamówień pomijam.');

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        try {
            $stats = $hints->run($days);
            $this->info(sprintf(
                'Podpowiedzi zamówień — zapytania z odpowiedzią z %d dni: %d, bez pewnego klienta z ERP XL: %d, kontrahenci: %d, dokumenty z towarami ofert: %d, podpowiedzi: %d, usunięte: %d, błędy: %d.',
                $days, $stats['inquiries'], $stats['without_client'], $stats['customers'], $stats['documents'], $stats['hints'], $stats['removed'], $stats['errors'],
            ));
            $failed = $failed || $stats['errors'] > 0;
        } catch (Throwable $e) {
            report($e);
            $this->error('Odczyt dokumentów z ERP XL przerwany: '.$e->getMessage());
            $failed = true;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
