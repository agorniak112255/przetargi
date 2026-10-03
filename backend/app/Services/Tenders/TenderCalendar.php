<?php

declare(strict_types=1);

namespace App\Services\Tenders;

use App\Models\Tender;
use App\Models\User;
use App\Services\Notifications\TenderReminderPlanner;
use App\Support\PolishTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Terminy składania ofert jako zdarzenia kalendarza — widok miesiąca na liście przetargów (GET /tenders/calendar)
 * i plik do subskrypcji w Outlooku i Thunderbirdzie (ICS). Jedno zdarzenie na przetarg, w dniu terminu „na zegarze”
 * w Polsce, z godziną albo bez niej.
 *
 * Stan (pierwszy pasujący — kolejność ma znaczenie):
 *  1. result_needed — dzień terminu minął (w Polsce), wyniku nie ma, przetarg nie jest szkicem ani odrzucony,
 *     termin najwyżej RESULT_NEEDED_DAYS dni temu (jak przypomnienie „wpisz wynik” w TenderReminderPlanner);
 *  2. closed — oferta wysłana (exported), archiwum, odrzucony albo wynik jest wpisany;
 *  3. ready — są pozycje i każda ma produkt (kartę albo własną nazwę) i cenę;
 *  4. urgent — braki (żadnej pozycji albo pozycje bez produktu lub ceny) i termin dziś albo w ciągu URGENT_DAYS dni;
 *  5. in_progress — reszta (braki i termin dalej, albo termin już minął, a przetarg jest szkicem).
 */
final class TenderCalendar
{
    /** Najdłuższy zakres jednego żądania widoku (dni między from i to) — miesiąc z sąsiednimi tygodniami. */
    public const MAX_RANGE_DAYS = 62;

    public const RESULT_NEEDED_DAYS = 60;

    public const URGENT_DAYS = 3;

    /** Oferta już wysłana albo sprawa zakończona — szary „zamknięte”. */
    private const CLOSED_STATUSES = ['exported', 'archiwum', 'odrzucony'];

    /**
     * Przetargi, które użytkownik może oglądać w aplikacji: wszystkie z tenders.view_all, inaczej prowadzone przez
     * niego i te, do których go zaproszono.
     *
     * @return Builder<Tender>
     */
    public function visibleTo(User $user): Builder
    {
        $query = Tender::query();
        if (! $user->can('tenders.view_all')) {
            $query->accessibleBy($user);
        }

        return $query;
    }

    /**
     * Zdarzenia z terminem w przedziale [from, to] (oba dni włącznie) z przekazanego zapytania — po dniu, potem
     * z godziną przed całodniowymi, potem po godzinie. Każdy wiersz: model przetargu (do ICS: updated_at) i zdarzenie
     * w kształcie TenderCalendarEvent z lib/api.ts.
     *
     * @param  Builder<Tender>  $query
     * @return list<array{tender: Tender, event: array<string, mixed>}>
     */
    public function rows(Builder $query, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tenders = $query
            ->with('client:id,name')
            ->whereNotNull('deadline')
            ->whereDate('deadline', '>=', $from->toDateString())
            ->whereDate('deadline', '<=', $to->toDateString())
            ->orderBy('deadline')
            ->orderByRaw('deadline_time is null')
            ->orderBy('deadline_time')
            ->orderBy('id')
            ->get(['id', 'number', 'notice_number', 'title', 'client_id', 'status', 'result_status', 'deadline', 'deadline_time', 'updated_at']);
        if ($tenders->isEmpty()) {
            return [];
        }

        $missing = $this->missing($tenders->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        $today = PolishTime::today();

        $rows = [];
        foreach ($tenders as $tender) {
            /** @var Tender $tender */
            $date = $tender->deadline->format('Y-m-d');
            $gaps = $missing[(int) $tender->id] ?? ['items' => 0, 'without_product' => 0, 'without_price' => 0];
            $state = $this->state($tender, $date, $gaps, $today);
            $rows[] = [
                'tender' => $tender,
                'event' => [
                    'tender_id' => (int) $tender->id,
                    'number' => (string) $tender->number,
                    'notice_number' => $tender->notice_number,
                    'title' => (string) $tender->title,
                    'client' => $tender->client?->name,
                    'status' => (string) $tender->status,
                    'date' => $date,
                    'time' => $tender->deadline_time,
                    'state' => $state,
                    'missing' => $gaps,
                    // „do zrobienia: wpisz wynik” otwiera od razu sekcję wyniku
                    'url' => '/tenders/'.$tender->id.($state === 'result_needed' ? '?tab=wynik' : ''),
                ],
            ];
        }

        return $rows;
    }

    /**
     * @param  array{items: int, without_product: int, without_price: int}  $gaps
     */
    private function state(Tender $tender, string $date, array $gaps, CarbonImmutable $today): string
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, PolishTime::TIMEZONE);
        $daysAhead = (int) round($today->diffInDays($day, false));
        $status = (string) $tender->status;
        $hasResult = $tender->result_status !== null;

        if ($daysAhead < 0
            && $daysAhead >= -self::RESULT_NEEDED_DAYS
            && ! $hasResult
            && ! in_array($status, TenderReminderPlanner::RESULT_SKIPPED_STATUSES, true)) {
            return 'result_needed';
        }
        if ($hasResult || in_array($status, self::CLOSED_STATUSES, true)) {
            return 'closed';
        }
        $complete = $gaps['items'] > 0 && $gaps['without_product'] === 0 && $gaps['without_price'] === 0;
        if ($complete) {
            return 'ready';
        }
        if ($daysAhead >= 0 && $daysAhead <= self::URGENT_DAYS) {
            return 'urgent';
        }

        return 'in_progress';
    }

    /**
     * Pozycje i braki oferty na przetarg — jedno zapytanie, GROUP BY tylko tender_id i same agregaty (ONLY_FULL_GROUP_BY).
     * Produkt = karta albo własna nazwa niebędąca samymi spacjami (jak „Do zrobienia dziś” na Dashboardzie).
     *
     * @param  list<int>  $tenderIds
     * @return array<int, array{items: int, without_product: int, without_price: int}>
     */
    private function missing(array $tenderIds): array
    {
        $out = [];
        foreach (array_chunk($tenderIds, 500) as $chunk) {
            $rows = DB::table('tender_items')
                ->whereIn('tender_id', $chunk)
                ->groupBy('tender_id')
                ->select('tender_id')
                ->selectRaw('count(*) as items')
                ->selectRaw("sum(case when main_product_id is null and trim(coalesce(custom_name, '')) = '' then 1 else 0 end) as without_product")
                ->selectRaw('sum(case when offer_price is null then 1 else 0 end) as without_price')
                ->get();
            foreach ($rows as $row) {
                $out[(int) $row->tender_id] = [
                    'items' => (int) $row->items,
                    'without_product' => (int) $row->without_product,
                    'without_price' => (int) $row->without_price,
                ];
            }
        }

        return $out;
    }
}
