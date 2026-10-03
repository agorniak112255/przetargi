<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\ErpSaleDocument;
use App\Models\SalesTarget;
use App\Models\User;
use App\Services\Clients\ClientAssignment;
use App\Support\PolishTime;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cele handlowców (Raporty → „Cele handlowców”, kafelek „Mój cel” na Dashboardzie): miesięczny cel osoby i jego
 * realizacja = sprzedaż netto z faktur i paragonów ERP XL po korektach (erp_sale_documents, kopia nocna) klientów
 * przypisanych do tej osoby według dzisiejszego opiekuna (ClientAssignment: pracownik XL zmapowany na konto, potem
 * opiekun w aplikacji, potem „bez opiekuna”).
 *
 * Liczą się tylko klienci z zakładki Klienci (dokumenty z client_id) — kontrahentów XL spoza niej nie ma w kopii.
 * „Klient, który kupił” = co najmniej jedna faktura albo paragon (nie sama korekta) w miesiącu; „nowy” = taki klient
 * bez faktury i paragonu w 24 miesiącach przed początkiem miesiąca.
 *
 * Następny miesiąc jest w oknie, żeby zarząd mógł ustalić cele z wyprzedzeniem: sprzedaż 0, bez realizacji i bez
 * dni roboczych w toku (upcoming).
 *
 * Zapytania są płaskie (bez GROUP BY — MariaDB z ONLY_FULL_GROUP_BY), sumy liczone w PHP w groszach.
 */
final class SalesTargetsReport
{
    /** Okno raportu wstecz: bieżący miesiąc i 11 poprzednich. */
    public const WINDOW_MONTHS = 12;

    /** Miesiące do przodu: następny — zarząd ustala cele z wyprzedzeniem (sprzedaży jeszcze nie ma). */
    public const FUTURE_MONTHS = 1;

    /** „Nowy klient” = brak zakupu w tylu miesiącach przed miesiącem raportu. */
    public const NEW_CLIENT_MONTHS = 24;

    private const SALESPERSON_ROLE = 'handlowiec';

    private const MONTH_NAMES = [
        1 => 'Styczeń', 2 => 'Luty', 3 => 'Marzec', 4 => 'Kwiecień', 5 => 'Maj', 6 => 'Czerwiec',
        7 => 'Lipiec', 8 => 'Sierpień', 9 => 'Wrzesień', 10 => 'Październik', 11 => 'Listopad', 12 => 'Grudzień',
    ];

    public const RULE = 'Sprzedaż netto z faktur i paragonów w ERP XL, po korektach, z nocnego odczytu. Liczymy tylko klientów '
        .'z zakładki Klienci (kontrahenci ERP XL, którzy w roku kupili za co najmniej 3000 zł netto; raz dodani zostają '
        .'na liście) — sprzedaży pozostałych kontrahentów ERP XL tu nie ma. Klient należy do handlowca według dzisiejszego '
        .'opiekuna, także w minionych miesiącach: najpierw opiekun z karty w ERP XL, jeśli administrator przypisał tego '
        .'pracownika do konta w aplikacji, a gdy nie — opiekun w aplikacji. „Klient, który kupił” ma w miesiącu co najmniej '
        .'jedną fakturę albo paragon; „nowy” nie miał żadnej przez 24 miesiące wcześniej.';

    public function __construct(private readonly ClientAssignment $assignment) {}

    /** Bieżący miesiąc w Polsce jako RRRR-MM. */
    public static function currentMonth(): string
    {
        return PolishTime::today()->format('Y-m');
    }

    /**
     * Miesiące okna, od najnowszego: następny, bieżący i 11 poprzednich.
     *
     * @return list<array{key: string, label: string}>
     */
    public static function months(): array
    {
        $first = PolishTime::today()->startOfMonth()->addMonthsNoOverflow(self::FUTURE_MONTHS);
        $out = [];
        for ($i = 0; $i < self::WINDOW_MONTHS + self::FUTURE_MONTHS; $i++) {
            $m = $first->subMonthsNoOverflow($i);
            $out[] = ['key' => $m->format('Y-m'), 'label' => self::MONTH_NAMES[(int) $m->format('n')].' '.$m->format('Y')];
        }

        return $out;
    }

    public static function inWindow(string $month): bool
    {
        return in_array($month, array_column(self::months(), 'key'), true);
    }

    /**
     * Raport miesiąca (miesiąc z okna, RRRR-MM).
     *
     * @return array<string, mixed> SalesTargetsReport (lib/reports.ts)
     */
    public function build(string $month): array
    {
        [$year, $monthNo] = self::parse($month);
        $closed = $month < self::currentMonth();
        // miesiąc przyszły: cele ustalane z wyprzedzeniem, sprzedaży jeszcze nie ma (bez zapytania do kopii z ERP XL)
        $upcoming = $month > self::currentMonth();
        $stats = $upcoming
            ? ['users' => [], 'unassigned' => self::emptyBucket(), 'new' => []]
            : $this->monthStats($year, $monthNo);

        $targets = SalesTarget::query()
            ->where('year', $year)
            ->where('month', $monthNo)
            ->get(['user_id', 'amount'])
            ->mapWithKeys(static fn (SalesTarget $t): array => [(int) $t->user_id => self::cents((string) $t->amount)])
            ->all();

        // osoby w tabeli: handlowcy, przypisani pracownicy XL, osoby z celem i osoby, którym przypisano sprzedaż
        $userIds = array_values(array_unique([...array_keys($targets), ...array_keys($stats['users'])]));
        $users = User::query()
            ->where(static function (Builder $q) use ($userIds): void {
                $q->whereIn('id', $userIds === [] ? [0] : $userIds)
                    ->orWhereNotNull('erp_employee_gid')
                    ->orWhereHas('roles', static fn (Builder $r) => $r->where('name', self::SALESPERSON_ROLE));
            })
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'erp_employee_gid']);

        $rows = [];
        $totalTarget = null;
        $totalSales = 0;
        $targetedSales = 0;
        foreach ($users as $user) {
            $id = (int) $user->id;
            $s = $stats['users'][$id] ?? self::emptyBucket();
            $target = $targets[$id] ?? null;
            if ($target !== null) {
                $totalTarget = ($totalTarget ?? 0) + $target;
                $targetedSales += $s['sales'];
            }
            $totalSales += $s['sales'];
            $rows[] = [
                'user_id' => $id,
                'name' => (string) $user->name,
                'target' => $target === null ? null : self::money($target),
                'sales' => self::money($s['sales']),
                // przed początkiem miesiąca realizacji nie ma — 0% wyglądałoby jak zaległość
                'percent' => $upcoming ? null : self::percent($s['sales'], $target),
                'clients_bought' => count($s['bought']),
                'new_clients' => count(array_intersect_key($s['bought'], $stats['new'])),
                'by_source' => ['xl' => self::money($s['xl']), 'app' => self::money($s['app'])],
                'has_employee' => $user->erp_employee_gid !== null,
            ];
        }

        $u = $stats['unassigned'];

        return [
            'month' => $month,
            'months' => self::months(),
            'closed' => $closed,
            'upcoming' => $upcoming,
            'workdays' => $closed || $upcoming ? null : self::workdays($year, $monthNo),
            'data_until' => self::dataUntil(),
            'rule' => self::RULE,
            'rows' => $rows,
            'unassigned' => [
                'sales' => self::money($u['sales']),
                'clients_bought' => count($u['bought']),
                'new_clients' => count(array_intersect_key($u['bought'], $stats['new'])),
            ],
            // realizacja działu = sprzedaż osób z celem / suma celów (osoby bez celu i „bez opiekuna” nie zawyżają procentu)
            'total' => [
                'target' => $totalTarget === null ? null : self::money($totalTarget),
                'sales' => self::money($totalSales),
                'percent' => $upcoming ? null : self::percent($targetedSales, $totalTarget),
            ],
        ];
    }

    /**
     * Własny cel zalogowanej osoby w bieżącym miesiącu (GET /me/sales-target).
     *
     * @return array<string, mixed> MySalesTarget (lib/api.ts)
     */
    public function mine(User $user): array
    {
        $month = self::currentMonth();
        [$year, $monthNo] = self::parse($month);
        $amount = SalesTarget::query()
            ->where('user_id', $user->id)
            ->where('year', $year)
            ->where('month', $monthNo)
            ->value('amount');
        $target = $amount === null ? null : self::cents((string) $amount);

        // bez celu kafelka nie ma — nie liczymy sprzedaży na darmo
        $s = self::emptyBucket();
        $new = [];
        if ($target !== null) {
            $stats = $this->monthStats($year, $monthNo, (int) $user->id);
            $s = $stats['users'][(int) $user->id] ?? $s;
            $new = $stats['new'];
        }

        return [
            'month' => $month,
            'target' => $target === null ? null : self::money($target),
            'sales' => self::money($s['sales']),
            'percent' => self::percent($s['sales'], $target),
            'workdays' => self::workdays($year, $monthNo),
            'clients_bought' => count($s['bought']),
            'new_clients' => count(array_intersect_key($s['bought'], $new)),
        ];
    }

    /**
     * Zapis celów miesiąca: kwota ustawia cel, null go usuwa. Każda pozycja osobno — pozostałe cele bez zmian.
     *
     * @param  list<array{user_id: int, amount: float|int|string|null}>  $targets
     */
    public function save(string $month, array $targets, User $by): void
    {
        [$year, $monthNo] = self::parse($month);

        DB::transaction(static function () use ($targets, $year, $monthNo, $by): void {
            foreach ($targets as $t) {
                $where = ['user_id' => (int) $t['user_id'], 'year' => $year, 'month' => $monthNo];
                if ($t['amount'] === null) {
                    SalesTarget::query()->where($where)->delete();

                    continue;
                }
                SalesTarget::query()->updateOrCreate($where, [
                    'amount' => self::money(self::cents((string) $t['amount'])),
                    'set_by' => $by->id,
                ]);
            }
        });
    }

    /**
     * Sprzedaż miesiąca rozdzielona na osoby według dzisiejszego opiekuna klienta.
     *
     * @return array{
     *     users: array<int, array{sales: int, xl: int, app: int, bought: array<int, true>}>,
     *     unassigned: array{sales: int, xl: int, app: int, bought: array<int, true>},
     *     new: array<int, true>
     * }
     */
    private function monthStats(int $year, int $month, ?int $onlyUser = null): array
    {
        $from = CarbonImmutable::create($year, $month, 1);
        $next = $from->addMonthNoOverflow();

        /** @var array<int, int> $salesByClient */
        $salesByClient = [];
        /** @var array<int, true> $boughtClients */
        $boughtClients = [];
        $docs = ErpSaleDocument::query()
            ->whereNotNull('client_id')
            // przedział [od, do) — działa także, gdy data jest zapisana z godziną 00:00:00
            ->where('issued_at', '>=', $from->toDateString())
            ->where('issued_at', '<', $next->toDateString())
            ->select(['client_id', 'kind', 'net_value'])
            ->toBase()
            ->cursor();
        foreach ($docs as $doc) {
            $clientId = (int) $doc->client_id;
            $salesByClient[$clientId] = ($salesByClient[$clientId] ?? 0) + self::cents((string) $doc->net_value);
            if (in_array($doc->kind, ErpSaleDocument::SALE_KINDS, true)) {
                $boughtClients[$clientId] = true;
            }
        }

        $assignment = $salesByClient === [] ? [] : $this->assignment->forClients(array_keys($salesByClient));

        $users = [];
        $unassigned = self::emptyBucket();
        foreach ($salesByClient as $clientId => $cents) {
            $who = $assignment[$clientId] ?? ['user_id' => null, 'source' => null];
            $userId = $who['user_id'];
            if ($onlyUser !== null && $userId !== $onlyUser) {
                continue;
            }
            if ($userId === null) {
                $bucket = &$unassigned;
            } else {
                $users[$userId] ??= self::emptyBucket();
                $bucket = &$users[$userId];
            }
            $bucket['sales'] += $cents;
            if ($who['source'] === ClientAssignment::SOURCE_XL) {
                $bucket['xl'] += $cents;
            } elseif ($who['source'] === ClientAssignment::SOURCE_APP) {
                $bucket['app'] += $cents;
            }
            if (isset($boughtClients[$clientId])) {
                $bucket['bought'][$clientId] = true;
            }
            unset($bucket);
        }

        // „nowi”: kupujący w miesiącu bez faktury i paragonu w 24 miesiącach przed nim
        $buyers = [];
        foreach ([...array_values($users), $unassigned] as $b) {
            $buyers += $b['bought'];
        }
        $new = $buyers;
        $since = $from->subMonthsNoOverflow(self::NEW_CLIENT_MONTHS)->toDateString();
        foreach (array_chunk(array_keys($buyers), 500) as $chunk) {
            $earlier = ErpSaleDocument::query()
                ->whereIn('client_id', $chunk)
                ->whereIn('kind', ErpSaleDocument::SALE_KINDS)
                ->where('issued_at', '>=', $since)
                ->where('issued_at', '<', $from->toDateString())
                ->distinct()
                ->pluck('client_id');
            foreach ($earlier as $clientId) {
                unset($new[(int) $clientId]);
            }
        }

        return ['users' => $users, 'unassigned' => $unassigned, 'new' => $new];
    }

    /** @return array{sales: int, xl: int, app: int, bought: array<int, true>} */
    private static function emptyBucket(): array
    {
        return ['sales' => 0, 'xl' => 0, 'app' => 0, 'bought' => []];
    }

    /** @return array{total: int, elapsed: int} */
    private static function workdays(int $year, int $month): array
    {
        return [
            'total' => PolishTime::businessDaysInMonth($year, $month),
            'elapsed' => PolishTime::businessDaysElapsed($year, $month, PolishTime::today()),
        ];
    }

    /** Ostatni nocny odczyt dokumentów z ERP XL (najpóźniejszy synced_at) — ISO 8601 albo null. */
    private static function dataUntil(): ?string
    {
        $last = ErpSaleDocument::query()->max('synced_at');

        return $last === null ? null : CarbonImmutable::parse((string) $last)->toIso8601String();
    }

    /** @return array{int, int} rok i miesiąc z RRRR-MM */
    private static function parse(string $month): array
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m) !== 1) {
            throw new InvalidArgumentException('Miesiąc w postaci RRRR-MM: '.$month);
        }

        return [(int) $m[1], (int) $m[2]];
    }

    /** Kwota z bazy albo z żądania („1234.5”, „-12.30”) w groszach. */
    private static function cents(string $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    /** Grosze jako napis z kropką i dwoma miejscami („-1234.50”) — bez zależności od locale serwera. */
    private static function money(int $cents): string
    {
        $abs = abs($cents);

        return ($cents < 0 ? '-' : '').intdiv($abs, 100).'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function percent(int $sales, ?int $target): ?float
    {
        if ($target === null || $target <= 0) {
            return null;
        }

        return round($sales / $target * 100, 1);
    }
}
