<?php

declare(strict_types=1);

namespace App\Services\Inspections;

use App\Models\InspectionPosition;
use App\Models\Offer;
use App\Services\Erp\WarehouseLocations;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Odczyt modułu Przeglądy: lista terminów (klient × pozycja z inspection_due), filtry, szczegóły klienta, liczby z 24
 * miesięcy do katalogu i podpowiedzi. Terminy liczy InspectionDueBuilder; tu tylko stan „na dziś” według polskiej daty
 * (zaległy / nadchodzący) — w bazie są same daty.
 *
 * Zapytania bez funkcji okna, GROUP_CONCAT i JSON w SQL (produkcja: MariaDB 10.5 z ONLY_FULL_GROUP_BY, testy: SQLite).
 * Górne granice dat jako „< następny dzień”, nie „<=”: odporne na datę zapisaną z godziną (SQLite porównuje tekst).
 */
final class InspectionQuery
{
    public const SORTS = ['due', 'customer', 'value'];

    public const STATUSES = ['all', 'overdue', 'upcoming'];

    public const DEFAULT_DAYS = 30;

    public const MAX_DAYS = 730;

    /** Zaległe dłużej niż 3 interwały pozycji — domyślnie ukryte (pewnie klient odszedł); „old” pokazuje wszystkie. */
    public const OLD_INTERVALS = 3;

    /** Historia sprzedaży w szczegółach klienta — najwyżej tyle wierszy, od najnowszych. */
    public const HISTORY_LIMIT = 300;

    /** Okno liczb „klientów i sztuk” przy katalogu i podpowiedziach. */
    public const STATS_MONTHS = 24;

    /**
     * Wiersze inspection_due po filtrach listy (bez sortowania i stron). Klucze $f:
     * days (int|null — null: bez górnej granicy terminu), status, old (bool), location, position_id, ident (operator XL
     * „moich klientów” albo null), q, with_email (bool), dismissed (bool), customer_xl_gids (list|null).
     *
     * @param  array<string, mixed>  $f
     */
    public function filtered(array $f, CarbonImmutable $today): Builder
    {
        $q = DB::table('inspection_due as d')
            ->join('inspection_positions as p', 'p.id', '=', 'd.inspection_position_id')
            ->leftJoin('erp_customers as c', 'c.xl_gid', '=', 'd.customer_xl_gid')
            // pozycja wyłączona znika przy przebudowie; do tego czasu też jej nie pokazujemy
            ->where('p.active', true);

        if (isset($f['days']) && $f['days'] !== null) {
            $q->where('d.due_on', '<', $today->addDays((int) $f['days'] + 1)->toDateString());
        }
        $status = (string) ($f['status'] ?? 'all');
        if ($status === 'overdue') {
            $q->where('d.due_on', '<', $today->toDateString());
        } elseif ($status === 'upcoming') {
            $q->where('d.due_on', '>=', $today->toDateString());
        }
        if (! ($f['old'] ?? false)) {
            // interwał jest w wierszu pozycji — próg liczony w PHP dla każdego interwału z listy (bez dat w SQL)
            $q->where(function (Builder $w) use ($today): void {
                foreach (InspectionPosition::INTERVALS as $months) {
                    $w->orWhere(fn (Builder $x) => $x->where('p.interval_months', $months)
                        ->where('d.due_on', '>=', $today->subMonthsNoOverflow(self::OLD_INTERVALS * $months)->toDateString()));
                }
                // interwał spoza listy (nie powinien się zdarzyć) — wiersz widoczny zamiast zgubiony
                $w->orWhereNotIn('p.interval_months', InspectionPosition::INTERVALS);
            });
        }
        if (isset($f['location']) && $f['location'] !== null && $f['location'] !== '') {
            $q->where('d.location', (string) $f['location']);
        }
        if (isset($f['position_id']) && $f['position_id'] !== null) {
            $q->where('d.inspection_position_id', (int) $f['position_id']);
        }
        if (isset($f['ident']) && $f['ident'] !== null) {
            $q->where('d.operator_ident', (string) $f['ident']);
        }
        if ($f['with_email'] ?? false) {
            $q->whereNotNull('c.emails');
        }
        if (! ($f['dismissed'] ?? false)) {
            $q->whereNotExists(function (Builder $x) use ($today): void {
                $x->selectRaw('1')
                    ->from('inspection_dismissals as x')
                    ->whereColumn('x.customer_xl_gid', 'd.customer_xl_gid')
                    ->where(fn (Builder $p) => $p->whereNull('x.inspection_position_id')
                        ->orWhereColumn('x.inspection_position_id', 'd.inspection_position_id'))
                    ->where(fn (Builder $u) => $u->whereNull('x.until_on')->orWhere('x.until_on', '>=', $today->toDateString()));
            });
        }
        if (isset($f['customer_xl_gids']) && is_array($f['customer_xl_gids'])) {
            $q->whereIn('d.customer_xl_gid', array_map('intval', $f['customer_xl_gids']));
        }
        $this->search($q, (string) ($f['q'] ?? ''));

        return $q;
    }

    /**
     * Strona klientów: liczba wszystkich klientów po filtrach i numery XL klientów strony w kolejności sortowania.
     * Sortowanie po agregatach (MIN / MAX / SUM) — zgodne z ONLY_FULL_GROUP_BY.
     *
     * @param  array<string, mixed>  $f
     * @return array{total: int, customers: list<int>}
     */
    public function customerPage(array $f, CarbonImmutable $today, int $page, int $perPage, string $sort): array
    {
        $total = (clone $this->filtered($f, $today))->distinct()->count('d.customer_xl_gid');
        $q = $this->filtered($f, $today)->groupBy('d.customer_xl_gid')->select('d.customer_xl_gid');
        $this->sortCustomers($q, $sort);
        $customers = $q->offset(($page - 1) * $perPage)->limit($perPage)
            ->pluck('d.customer_xl_gid')->map(static fn ($gid): int => (int) $gid)->all();

        return ['total' => $total, 'customers' => $customers];
    }

    /**
     * Wszyscy klienci po filtrach w kolejności sortowania (eksport).
     *
     * @param  array<string, mixed>  $f
     * @return list<int>
     */
    public function allCustomers(array $f, CarbonImmutable $today, string $sort): array
    {
        $q = $this->filtered($f, $today)->groupBy('d.customer_xl_gid')->select('d.customer_xl_gid');
        $this->sortCustomers($q, $sort);

        return $q->pluck('d.customer_xl_gid')->map(static fn ($gid): int => (int) $gid)->all();
    }

    private function sortCustomers(Builder $q, string $sort): void
    {
        match ($sort) {
            // klient spoza erp_customers (bez akronimu) na końcu
            'customer' => $q->orderByRaw('max(c.acronym) is null')->orderByRaw('max(c.acronym) asc'),
            // wartość netto ostatnich przeglądów pozycji na liście; bez wartości na końcu
            'value' => $q->orderByRaw('sum(d.last_net) is null')->orderByRaw('sum(d.last_net) desc')->orderByRaw('min(d.due_on) asc'),
            default => $q->orderByRaw('min(d.due_on) asc'),
        };
        $q->orderBy('d.customer_xl_gid');
    }

    /**
     * Lista: jeden wiersz na klienta, pozycje w środku (te same filtry co strona klientów).
     *
     * @param  array<string, mixed>  $f
     * @param  list<int>  $customers  kolejność wyniku
     * @return list<array<string, mixed>>
     */
    public function groups(array $f, CarbonImmutable $today, array $customers): array
    {
        if ($customers === []) {
            return [];
        }
        $rows = $this->dueRows($this->filtered([...$f, 'customer_xl_gids' => $customers], $today));
        $byCustomer = [];
        foreach ($rows as $row) {
            $byCustomer[(int) $row->customer_xl_gid][] = $row;
        }
        $info = $this->customers([...$customers, ...$this->recipientGids($rows)]);
        $dismissals = $this->activeDismissals($customers, $today);
        $offers = $this->lastOffers($customers);

        $out = [];
        foreach ($customers as $gid) {
            $positions = array_map(
                fn (object $row): array => $this->presentPosition($row, $today, $info, $dismissals[$gid]['positions'][(int) $row->inspection_position_id] ?? null),
                $byCustomer[$gid] ?? [],
            );
            if ($positions === []) {
                continue;
            }
            $due = min(array_column($positions, 'due_on'));
            $out[] = [
                'customer' => $this->presentCustomer($gid, $info[$gid] ?? null),
                ...$this->state($due, $today),
                'positions' => $positions,
                'last_offer' => $offers[$gid] ?? null,
                'dismissal' => $dismissals[$gid]['customer'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Wiersze terminów z danymi pozycji, po terminie i nazwie pozycji.
     *
     * @return list<object>
     */
    public function dueRows(Builder $filtered): array
    {
        return $filtered
            ->select([
                'd.id', 'd.customer_xl_gid', 'd.inspection_position_id', 'd.due_on', 'd.open_count', 'd.open_quantity',
                'd.last_on', 'd.last_quantity', 'd.last_net', 'd.last_documents', 'd.first_on', 'd.recipient_xl_gid',
                'd.location', 'd.operator_ident', 'd.same_nip_newer',
                'p.xl_gid', 'p.xl_type', 'p.code', 'p.name', 'p.unit', 'p.interval_months',
            ])
            ->orderBy('d.customer_xl_gid')
            ->orderBy('d.due_on')
            ->orderBy('p.name')
            ->orderBy('d.id')
            ->get()
            ->all();
    }

    /**
     * @param  array<int, object>  $customers  erp_customers po numerze XL (odbiorcy też)
     * @param  array<string, mixed>|null  $dismissal
     * @return array<string, mixed>
     */
    public function presentPosition(object $row, CarbonImmutable $today, array $customers, ?array $dismissal): array
    {
        $due = substr((string) $row->due_on, 0, 10);
        $documents = json_decode((string) $row->last_documents, true);
        $recipientGid = $row->recipient_xl_gid !== null ? (int) $row->recipient_xl_gid : null;
        $recipient = null;
        if ($recipientGid !== null && $recipientGid !== (int) $row->customer_xl_gid) {
            $c = $customers[$recipientGid] ?? null;
            $recipient = [
                'xl_gid' => $recipientGid,
                'name' => $c !== null ? (string) ($c->name ?: $c->acronym) : self::unknownName($recipientGid),
            ];
        }
        $location = $row->location !== null && $row->location !== '' ? (string) $row->location : null;

        return [
            'due_id' => (int) $row->id,
            'position_id' => (int) $row->inspection_position_id,
            'xl_gid' => (int) $row->xl_gid,
            'xl_type' => (int) $row->xl_type,
            'code' => (string) $row->code,
            'name' => (string) $row->name,
            'unit' => $row->unit !== null ? (string) $row->unit : null,
            'interval_months' => (int) $row->interval_months,
            'due_on' => $due,
            ...$this->state($due, $today),
            'open_count' => (int) $row->open_count,
            'open_quantity' => (float) $row->open_quantity,
            'last_on' => substr((string) $row->last_on, 0, 10),
            'last_quantity' => (float) $row->last_quantity,
            'last_net' => $row->last_net !== null ? (float) $row->last_net : null,
            'last_documents' => is_array($documents) ? array_values($documents) : [],
            'first_on' => substr((string) $row->first_on, 0, 10),
            'location' => $location,
            'location_name' => $location !== null ? WarehouseLocations::name($location) : null,
            'operator_ident' => $row->operator_ident !== null ? (string) $row->operator_ident : null,
            'recipient' => $recipient,
            'same_nip_newer' => (bool) $row->same_nip_newer,
            'dismissal' => $dismissal,
        ];
    }

    /**
     * Stan terminu na dziś: zaległy (termin przed dziś) albo nadchodzący.
     *
     * @return array{due_on: string, status: string, days_left: int|null, overdue_days: int|null}
     */
    public function state(string $dueOn, CarbonImmutable $today): array
    {
        $dueOn = substr($dueOn, 0, 10);
        // dni kalendarzowe liczone na datach w UTC — zmiana czasu w Polsce nie daje ułamków dnia
        $due = CarbonImmutable::createFromFormat('!Y-m-d', $dueOn, 'UTC');
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $today->toDateString(), 'UTC');
        $diff = $due instanceof CarbonImmutable && $from instanceof CarbonImmutable ? (int) round($from->diffInDays($due, false)) : 0;
        if ($diff < 0) {
            return ['due_on' => $dueOn, 'status' => 'overdue', 'days_left' => null, 'overdue_days' => -$diff];
        }

        return ['due_on' => $dueOn, 'status' => 'upcoming', 'days_left' => $diff, 'overdue_days' => null];
    }

    /**
     * Klienci z erp_customers po numerze XL (brak klucza = klienta nie ma w kopii XL).
     *
     * @param  list<int>  $gids
     * @return array<int, object>
     */
    public function customers(array $gids): array
    {
        $gids = array_values(array_unique($gids));
        $out = [];
        foreach (array_chunk($gids, 500) as $chunk) {
            $columns = [
                'xl_gid', 'acronym', 'name', 'nip', 'city', 'emails', 'archived', 'street', 'address_line2', 'postal_code',
                'voivodeship', 'phone', 'phone2', 'contacts', 'account_manager', 'account_manager_email', 'last_sale_at',
                'main_operator', 'details_synced_at',
            ];
            foreach (DB::table('erp_customers')->whereIn('xl_gid', $chunk)->get($columns) as $c) {
                $c->client_id = null;
                $out[(int) $c->xl_gid] = $c;
            }
            // karta klienta w zakładce Klienci (klienci powyżej progu sprzedaży) — link ze szczegółów
            foreach (DB::table('clients')->whereIn('xl_gid', $chunk)->get(['id', 'xl_gid']) as $client) {
                if (isset($out[(int) $client->xl_gid])) {
                    $out[(int) $client->xl_gid]->client_id = (int) $client->id;
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function presentCustomer(int $gid, ?object $c): array
    {
        if ($c === null) {
            // klient spoza kopii erp_customers — tylko numer XL, niczego nie zgadujemy
            return ['xl_gid' => $gid, 'acronym' => self::unknownName($gid), 'name' => null, 'nip' => null, 'city' => null,
                'emails' => [], 'archived' => false, 'known' => false, ...self::emptyDetails()];
        }
        $emails = $c->emails !== null ? json_decode((string) $c->emails, true) : [];
        $contacts = $c->contacts !== null ? json_decode((string) $c->contacts, true) : [];
        $text = static fn (mixed $v): ?string => $v !== null && trim((string) $v) !== '' ? trim((string) $v) : null;

        return [
            'xl_gid' => $gid,
            'acronym' => (string) $c->acronym,
            'name' => $c->name !== null ? (string) $c->name : null,
            'nip' => $c->nip !== null ? (string) $c->nip : null,
            'city' => $c->city !== null ? (string) $c->city : null,
            'emails' => is_array($emails) ? array_values(array_map('strval', $emails)) : [],
            'archived' => (bool) $c->archived,
            'known' => true,
            // z kartoteki XL dosłownie (InspectionCustomerDetails, co noc); null = brak w kartotece albo brak prawa odczytu
            'street' => $text($c->street),
            'address_line2' => $text($c->address_line2),
            'postal_code' => $text($c->postal_code),
            'voivodeship' => $text($c->voivodeship),
            'phones' => array_values(array_filter([$text($c->phone), $text($c->phone2)])),
            'contacts' => is_array($contacts) ? array_values(array_map(static fn (array $p): array => [
                'name' => $text($p['name'] ?? null),
                'position' => $text($p['position'] ?? null),
                'email' => $text($p['email'] ?? null),
                'phone' => $text($p['phone'] ?? null),
                'mobile' => $text($p['mobile'] ?? null),
            ], array_filter($contacts, 'is_array'))) : [],
            'account_manager' => $text($c->account_manager) !== null
                ? ['name' => (string) $text($c->account_manager), 'email' => $text($c->account_manager_email)]
                : null,
            'main_operator' => $text($c->main_operator),
            'last_sale_on' => $c->last_sale_at !== null ? substr((string) $c->last_sale_at, 0, 10) : null,
            'client_id' => $c->client_id ?? null,
            'details_synced_at' => $c->details_synced_at !== null ? CarbonImmutable::parse((string) $c->details_synced_at)->toIso8601String() : null,
        ];
    }

    /** @return array<string, mixed> pola kartoteki klienta spoza kopii erp_customers */
    private static function emptyDetails(): array
    {
        return [
            'street' => null, 'address_line2' => null, 'postal_code' => null, 'voivodeship' => null, 'phones' => [], 'contacts' => [],
            'account_manager' => null, 'main_operator' => null, 'last_sale_on' => null, 'client_id' => null, 'details_synced_at' => null,
        ];
    }

    public static function unknownName(int $gid): string
    {
        return 'Klient XL '.$gid;
    }

    /** „zaległy 5 dni”, „termin dziś”, „za 14 dni” — do eksportu i raportu. */
    public static function stateLabel(string $status, ?int $daysLeft, ?int $overdueDays): string
    {
        $days = static fn (int $n): string => $n.' '.($n === 1 ? 'dzień' : 'dni');
        if ($status === 'overdue') {
            return 'zaległy '.$days((int) $overdueDays);
        }

        return (int) $daysLeft === 0 ? 'termin dziś' : 'za '.$days((int) $daysLeft);
    }

    /** „co 1 miesiąc”, „co 3 miesiące”, „co 12 miesięcy”. */
    public static function intervalLabel(int $months): string
    {
        $mod10 = $months % 10;
        $mod100 = $months % 100;
        $word = match (true) {
            $months === 1 => 'miesiąc',
            $mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14) => 'miesiące',
            default => 'miesięcy',
        };

        return 'co '.$months.' '.$word;
    }

    /** Rodzaj pozycji XL słowem. */
    public static function typeLabel(int $xlType): string
    {
        return $xlType === InspectionPosition::TYPE_SERVICE ? 'usługa' : 'towar';
    }

    /** Powód pominięcia słowem (InspectionDismissal::REASONS). */
    public static function reasonLabel(string $reason): string
    {
        return match ($reason) {
            'other_company' => 'robi przeglądy u innej firmy',
            'resigned' => 'zrezygnował',
            'skip' => 'pominięty',
            default => 'inny powód',
        };
    }

    /**
     * @param  list<object>  $rows
     * @return list<int>
     */
    private function recipientGids(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if ($row->recipient_xl_gid !== null) {
                $out[] = (int) $row->recipient_xl_gid;
            }
        }

        return $out;
    }

    /**
     * Aktywne pominięcia (bez daty albo do daty ≥ dziś): całego klienta i pojedynczych pozycji. Dwa pominięcia tego
     * samego zakresu (nie powinno się zdarzyć) — wygrywa nowsze.
     *
     * @param  list<int>  $customers
     * @return array<int, array{customer?: array<string, mixed>, positions: array<int, array<string, mixed>>}>
     */
    public function activeDismissals(array $customers, CarbonImmutable $today): array
    {
        $out = [];
        foreach (array_chunk($customers, 500) as $chunk) {
            $rows = $this->dismissalQuery()
                ->whereIn('x.customer_xl_gid', $chunk)
                ->where(fn (Builder $u) => $u->whereNull('x.until_on')->orWhere('x.until_on', '>=', $today->toDateString()))
                ->orderBy('x.id')
                ->get();
            foreach ($rows as $row) {
                $gid = (int) $row->customer_xl_gid;
                $out[$gid] ??= ['positions' => []];
                if ($row->inspection_position_id === null) {
                    $out[$gid]['customer'] = $this->presentDismissal($row);
                } else {
                    $out[$gid]['positions'][(int) $row->inspection_position_id] = $this->presentDismissal($row);
                }
            }
        }

        return $out;
    }

    public function dismissalQuery(): Builder
    {
        return DB::table('inspection_dismissals as x')
            ->leftJoin('users as u', 'u.id', '=', 'x.user_id')
            ->select(['x.id', 'x.customer_xl_gid', 'x.inspection_position_id', 'x.until_on', 'x.reason', 'x.note', 'x.created_at', 'u.name as user_name']);
    }

    /** @return array<string, mixed> */
    public function presentDismissal(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'position_id' => $row->inspection_position_id !== null ? (int) $row->inspection_position_id : null,
            'until_on' => $row->until_on !== null ? substr((string) $row->until_on, 0, 10) : null,
            'reason' => (string) $row->reason,
            'note' => $row->note !== null ? (string) $row->note : null,
            'user_name' => $row->user_name !== null ? (string) $row->user_name : null,
            'created_at' => self::iso($row->created_at),
        ];
    }

    /**
     * Najnowsza wysłana oferta przeglądu do każdego klienta (dowolny autor) — tylko metadane.
     *
     * @param  list<int>  $customers
     * @return array<int, array{offer_id: int, code: string|null, sent_at: string|null, user_name: string|null}>
     */
    public function lastOffers(array $customers): array
    {
        $out = [];
        foreach (array_chunk($customers, 500) as $chunk) {
            $rows = $this->offerQuery()
                ->whereIn('o.customer_xl_gid', $chunk)
                ->whereNotNull('o.last_sent_at')
                ->orderByDesc('o.last_sent_at')
                ->orderByDesc('o.id')
                ->get();
            foreach ($rows as $row) {
                $out[(int) $row->customer_xl_gid] ??= $this->presentOffer($row);
            }
        }

        return $out;
    }

    public function offerQuery(): Builder
    {
        return DB::table('offers as o')
            ->leftJoin('users as u', 'u.id', '=', 'o.user_id')
            ->where('o.kind', Offer::KIND_INSPECTION)
            ->select(['o.id', 'o.code', 'o.customer_xl_gid', 'o.last_sent_at', 'u.name as user_name']);
    }

    /** @return array{offer_id: int, code: string|null, sent_at: string|null, user_name: string|null} */
    public function presentOffer(object $row): array
    {
        return [
            'offer_id' => (int) $row->id,
            'code' => $row->code !== null ? (string) $row->code : null,
            'sent_at' => self::iso($row->last_sent_at),
            'user_name' => $row->user_name !== null ? (string) $row->user_name : null,
        ];
    }

    /**
     * Oddziały, w których jest jakiś termin — kolejność jak WarehouseLocations::NAMES, reszta po kodzie.
     *
     * @return list<array{code: string, name: string}>
     */
    public function locations(): array
    {
        $codes = DB::table('inspection_due')->whereNotNull('location')->where('location', '<>', '')
            ->distinct()->pluck('location')->map(static fn ($l): string => (string) $l)->all();
        $order = array_map('strval', array_keys(WarehouseLocations::NAMES));
        usort($codes, static function (string $a, string $b) use ($order): int {
            $ia = array_search($a, $order, true);
            $ib = array_search($b, $order, true);

            return [$ia === false ? PHP_INT_MAX : $ia, $a] <=> [$ib === false ? PHP_INT_MAX : $ib, $b];
        });

        return array_map(static fn (string $code): array => ['code' => $code, 'name' => WarehouseLocations::name($code)], $codes);
    }

    /**
     * Klienci i sztuki z ostatnich 24 miesięcy dla pozycji katalogu XL.
     * - usługi: z inspection_sale_lines (faktury i korekty ze znakiem, wystawione w oknie);
     * - towary: z erp_customer_items (kopia FS/PA z 24 mies.; quantity to już suma z tych 24 mies., a filtr
     *   last_sale_at tylko odcina klientów, którzy w oknie nie kupili) — paragony liczą się tam jako jeden kontrahent.
     *
     * @param  list<int>  $goodsGids
     * @param  list<int>  $serviceGids
     * @return array<int, array{customers_24m: int, quantity_24m: float}>
     */
    public function stats24m(array $goodsGids, array $serviceGids, CarbonImmutable $today): array
    {
        $from = $today->subMonthsNoOverflow(self::STATS_MONTHS)->toDateString();
        $out = [];
        foreach (array_chunk(array_values(array_unique($serviceGids)), 500) as $chunk) {
            $rows = DB::table('inspection_sale_lines')
                ->whereIn('xl_item_gid', $chunk)
                ->where('issued_on', '>=', $from)
                ->groupBy('xl_item_gid')
                ->selectRaw('xl_item_gid, count(distinct customer_xl_gid) as customers, sum(quantity) as quantity')
                ->get();
            foreach ($rows as $r) {
                $out[(int) $r->xl_item_gid] = ['customers_24m' => (int) $r->customers, 'quantity_24m' => round((float) $r->quantity, 3)];
            }
        }
        foreach (array_chunk(array_values(array_unique($goodsGids)), 500) as $chunk) {
            $rows = DB::table('erp_customer_items as ci')
                ->join('erp_items as i', 'i.id', '=', 'ci.erp_item_id')
                ->whereIn('i.xl_gid', $chunk)
                ->where('ci.last_sale_at', '>=', $from)
                ->groupBy('i.xl_gid')
                ->selectRaw('i.xl_gid as xl_gid, count(distinct ci.erp_customer_id) as customers, sum(ci.quantity) as quantity')
                ->get();
            foreach ($rows as $r) {
                $out[(int) $r->xl_gid] = ['customers_24m' => (int) $r->customers, 'quantity_24m' => round((float) $r->quantity, 3)];
            }
        }

        return $out;
    }

    /**
     * Szukanie klienta: każde słowo musi pasować do akronimu, nazwy, miasta albo NIP-u (NIP także bez kresek i spacji);
     * same cyfry — również numer klienta XL.
     */
    private function search(Builder $q, string $text): void
    {
        $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        foreach (array_slice($words, 0, 8) as $word) {
            $like = '%'.addcslashes($word, '%_\\').'%';
            $q->where(function (Builder $w) use ($like, $word): void {
                $w->where('c.acronym', 'like', $like)
                    ->orWhere('c.name', 'like', $like)
                    ->orWhere('c.city', 'like', $like)
                    ->orWhere('c.nip', 'like', $like);
                $digits = (string) preg_replace('/\D+/', '', $word);
                if ($digits !== '' && $digits === str_replace('-', '', $word)) {
                    $w->orWhereRaw("replace(replace(c.nip, '-', ''), ' ', '') like ?", ['%'.$digits.'%']);
                    if (strlen($digits) <= 9) {
                        $w->orWhere('d.customer_xl_gid', (int) $digits);
                    }
                }
            });
        }
    }

    public static function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value, 'UTC')->toIso8601String();
    }
}
