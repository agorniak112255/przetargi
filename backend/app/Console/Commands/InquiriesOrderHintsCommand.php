<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Co noc 05:55 czasu polskiego: powiązania zapytań z klientami (InquiryClientLinker::linkAll — zawsze), potem
 * podpowiedzi „możliwe zamówienie z oferty” z pozycji dokumentów ERP XL (tylko przy ERPXL_ENABLED).
 *
 * ZAŚLEPKA (krok 0) — przebieg dopisuje strumień C (OrderHintBuilder).
 */
final class InquiriesOrderHintsCommand extends Command
{
    protected $signature = 'inquiries:order-hints {--days=60 : Odpowiedzi z tylu ostatnich dni}';

    protected $description = 'Wiąże zapytania z klientami i szuka w ERP XL możliwych zamówień z wysłanych ofert';

    public function handle(): int
    {
        $this->info('Jeszcze niegotowe.');

        return self::SUCCESS;
    }
}
