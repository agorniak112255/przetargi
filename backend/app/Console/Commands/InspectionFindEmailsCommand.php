<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CustomerEmailSuggestion;
use App\Services\Inspections\CustomerEmailFinder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Przeglądy: adresy e-mail ze stron WWW dla klientów z terminami bez adresu (ani na karcie XL, ani zatwierdzonego
 * z sieci) — najbliższe terminy pierwsze, klient sprawdzony w ostatnich --recheck-days dniach pomijany. Zapisuje
 * tylko propozycje; człowiek decyduje w szczegółach klienta. Co noc (harmonogram) z limitem — strony czyta wspólna
 * kolejka czytnika, więc jeden klient to kilkanaście–kilkadziesiąt sekund.
 */
final class InspectionFindEmailsCommand extends Command
{
    protected $signature = 'inspections:find-emails
        {--limit=40 : Najwyżej tylu klientów w jednym przebiegu}
        {--recheck-days=90 : Klient sprawdzony w ostatnich tylu dniach jest pomijany}';

    protected $description = 'Szuka w sieci adresów e-mail klientów z terminami przeglądów, którzy nie mają adresu (propozycje do zatwierdzenia)';

    public function handle(CustomerEmailFinder $finder): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $since = CarbonImmutable::now()->subDays(max(0, (int) $this->option('recheck-days')));

        // klienci z terminami: najbliższy termin pierwszy (MIN po kliencie — zgodne z ONLY_FULL_GROUP_BY)
        $order = DB::table('inspection_due')
            ->selectRaw('customer_xl_gid, MIN(due_on) as next_due')
            ->groupBy('customer_xl_gid');
        $customers = DB::table('erp_customers as c')
            ->joinSub($order, 'd', 'd.customer_xl_gid', '=', 'c.xl_gid')
            ->whereNull('c.emails')
            ->whereNull('c.removed_at')
            ->where('c.archived', false)
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('customer_email_suggestions as s')
                ->whereColumn('s.customer_xl_gid', 'c.xl_gid')->where('s.status', CustomerEmailSuggestion::STATUS_ACCEPTED))
            ->whereNotExists(fn (Builder $q) => $q->selectRaw('1')->from('customer_email_lookups as l')
                ->whereColumn('l.customer_xl_gid', 'c.xl_gid')->where('l.checked_at', '>=', $since))
            ->orderBy('d.next_due')
            ->orderBy('c.xl_gid')
            ->limit($limit)
            ->get(['c.xl_gid', 'c.acronym', 'c.name', 'c.nip', 'c.city', 'c.emails']);

        $found = 0;
        $withAny = 0;
        $errors = 0;
        foreach ($customers as $customer) {
            $r = $finder->find($customer);
            $found += $r['found'];
            $withAny += $r['found'] > 0 ? 1 : 0;
            $errors += $r['error'] !== null ? 1 : 0;
        }
        $this->info(sprintf(
            'Klienci sprawdzeni: %d, z nowymi propozycjami adresu: %d, propozycji: %d, błędy wyszukiwarki: %d.',
            $customers->count(), $withAny, $found, $errors,
        ));

        // same błędy wyszukiwarki = awaria, nie „brak adresów”
        return $customers->isNotEmpty() && $errors === $customers->count() ? self::FAILURE : self::SUCCESS;
    }
}
