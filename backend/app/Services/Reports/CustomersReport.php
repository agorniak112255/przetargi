<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\ErpItemLink;
use App\Models\User;
use App\Services\Erp\ErpItemCards;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Raport R5 „Klienci”: kontrahenci z Comarch ERP XL (erp:customers, co noc) — aktywność po dacie ostatniej
 * sprzedaży, odpływ, zasięg mailowy, operatorzy, miasta, najwięksi klienci i najczęściej kupowane towary.
 *
 * Zawsze bez usuniętych z XL (removed_at); archiwalni liczeni osobno i wyłączeni z pozostałych liczb.
 * Miesiące od dziś (czas polski) po last_sale_at — DATE z XL, bez przeliczania strefy; NULL = brak sprzedaży
 * w oknie 24 mies. synchronizacji. main_operator to operator XL, który wystawił klientowi najwięcej FS/PA —
 * nie opiekun klienta.
 */
final class CustomersReport
{
    public const TIMEZONE = 'Europe/Warsaw';

    /** Koszyki „ostatni zakup N–M miesięcy temu”. */
    public const RECENCY = [[0, 3], [3, 6], [6, 9], [9, 12], [12, 18], [18, 24]];

    public const CITIES_LIMIT = 15;

    public const TOP_CUSTOMERS_LIMIT = 20;

    public const TOP_ITEMS_LIMIT = 20;

    private const NO_OPERATOR = '(brak)';

    private const CHUNK = 1000;

    /** Polskie wielkie litery → bez znaków diakrytycznych (klucz miasta liczony po mb_strtoupper). */
    private const POLISH_UPPER = ['Ą' => 'A', 'Ć' => 'C', 'Ę' => 'E', 'Ł' => 'L', 'Ń' => 'N', 'Ó' => 'O', 'Ś' => 'S', 'Ź' => 'Z', 'Ż' => 'Z'];

    /** @return array<string, mixed> */
    public function build(User $user, array $params = []): array
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $cut = static fn (int $months): string => $today->subMonthsNoOverflow($months)->toDateString();
        $c6 = $cut(6);
        $c12 = $cut(12);
        $c24 = $cut(24);

        $synced = DB::table('erp_customers')->max('updated_at');

        $totals = $this->totals($c6, $c12, $c24);
        [$reachable, $reachableByOperator, $cities] = $this->activeScan($c12);
        $totals['reachable_active_12m'] = $reachable;

        return [
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'synced_at' => $synced !== null ? CarbonImmutable::parse((string) $synced, (string) config('app.timezone', 'UTC'))->toIso8601String() : null,
            'totals' => $totals,
            'recency' => $this->recency($cut),
            'operators' => $this->operators($c6, $c12, $c24, $reachableByOperator),
            'cities' => $cities,
            'top_customers' => $this->topCustomers(),
            'top_items' => $this->topItems(),
        ];
    }

    /** Klienci liczeni w raporcie: nieusunięci i niearchiwalni. */
    private function customers(): Builder
    {
        return DB::table('erp_customers')->whereNull('removed_at')->where('archived', false);
    }

    /** @return array<string, int> */
    private function totals(string $c6, string $c12, string $c24): array
    {
        $row = $this->customers()
            ->selectRaw('COUNT(*) AS customers')
            ->selectRaw('SUM(CASE WHEN sale_documents_24m > 0 THEN 1 ELSE 0 END) AS buying_24m')
            ->selectRaw('SUM(CASE WHEN last_sale_at >= ? THEN 1 ELSE 0 END) AS active_6m', [$c6])
            ->selectRaw('SUM(CASE WHEN last_sale_at >= ? AND last_sale_at < ? THEN 1 ELSE 0 END) AS dormant_6_12', [$c12, $c6])
            ->selectRaw('SUM(CASE WHEN last_sale_at >= ? AND last_sale_at < ? THEN 1 ELSE 0 END) AS lapsing_12_24', [$c24, $c12])
            ->first();

        return [
            'customers' => (int) ($row->customers ?? 0),
            'archived' => DB::table('erp_customers')->whereNull('removed_at')->where('archived', true)->count(),
            'buying_24m' => (int) ($row->buying_24m ?? 0),
            'active_6m' => (int) ($row->active_6m ?? 0),
            'dormant_6_12' => (int) ($row->dormant_6_12 ?? 0),
            'lapsing_12_24' => (int) ($row->lapsing_12_24 ?? 0),
            'reachable_active_12m' => 0,
        ];
    }

    /**
     * @param  callable(int): string  $cut
     * @return list<array{label: string, from_months: int, to_months: int, customers: int}>
     */
    private function recency(callable $cut): array
    {
        $query = $this->customers();
        foreach (self::RECENCY as $i => [$from, $to]) {
            // od „to” miesięcy temu (włącznie) do „from” miesięcy temu (bez); 0 = do dziś bez górnej granicy
            if ($from === 0) {
                $query->selectRaw('SUM(CASE WHEN last_sale_at >= ? THEN 1 ELSE 0 END) AS b'.$i, [$cut($to)]);
            } else {
                $query->selectRaw('SUM(CASE WHEN last_sale_at >= ? AND last_sale_at < ? THEN 1 ELSE 0 END) AS b'.$i, [$cut($to), $cut($from)]);
            }
        }
        $row = $query->first();

        $out = [];
        foreach (self::RECENCY as $i => [$from, $to]) {
            $out[] = [
                'label' => $from.'–'.$to.' mies.',
                'from_months' => $from,
                'to_months' => $to,
                'customers' => (int) ($row->{'b'.$i} ?? 0),
            ];
        }

        return $out;
    }

    /**
     * Jedno przejście po aktywnych ≤ 12 mies. (tylko id, miasto, adresy, operator): zasięg mailowy (adresy JSON
     * dekodowane w PHP) i miasta. Miasta liczone w PHP, nie GROUP BY city — MySQL oddałby przypadkową pisownię
     * grupy, a potrzebna jest najczęstsza.
     *
     * @return array{0: int, 1: array<string, int>, 2: list<array{city: string, customers: int}>}
     */
    private function activeScan(string $c12): array
    {
        $prefixes = array_map(static fn ($p): string => mb_strtolower((string) $p), (array) config('campaigns.excluded_local_prefixes', []));
        $reachable = 0;
        $byOperator = [];
        $cityCounts = [];
        $spellings = [];

        $this->customers()
            ->where('last_sale_at', '>=', $c12)
            ->select(['id', 'city', 'emails', 'main_operator'])
            ->chunkById(self::CHUNK, function ($rows) use ($prefixes, &$reachable, &$byOperator, &$cityCounts, &$spellings): void {
                $emailsById = [];
                $all = [];
                foreach ($rows as $row) {
                    $decoded = $row->emails !== null ? json_decode((string) $row->emails, true) : null;
                    $emails = [];
                    foreach (is_array($decoded) ? $decoded : [] as $email) {
                        $email = mb_strtolower(trim((string) $email));
                        if ($this->usable($email, $prefixes)) {
                            $emails[] = $email;
                            $all[$email] = true;
                        }
                    }
                    $emailsById[(int) $row->id] = $emails;

                    $city = trim((string) $row->city);
                    if ($city !== '') {
                        $key = $this->cityKey($city);
                        $cityCounts[$key] = ($cityCounts[$key] ?? 0) + 1;
                        $spellings[$key][$city] = ($spellings[$key][$city] ?? 0) + 1;
                    }
                }

                // wypisani ze wszystkich kampanii (email_suppressions) — paczkami, whereIn ma limit parametrów
                $suppressed = [];
                foreach (array_chunk(array_keys($all), self::CHUNK) as $chunk) {
                    foreach (DB::table('email_suppressions')->whereIn('email', $chunk)->pluck('email') as $email) {
                        $suppressed[mb_strtolower((string) $email)] = true;
                    }
                }

                foreach ($rows as $row) {
                    $ok = false;
                    foreach ($emailsById[(int) $row->id] as $email) {
                        $ok = $ok || ! isset($suppressed[$email]);
                    }
                    if ($ok) {
                        $reachable++;
                        $operator = $this->operatorKey($row->main_operator);
                        $byOperator[$operator] = ($byOperator[$operator] ?? 0) + 1;
                    }
                }
            });

        $cities = [];
        foreach ($cityCounts as $key => $count) {
            $cities[] = ['city' => $this->displaySpelling($spellings[$key]), 'customers' => $count];
        }
        usort($cities, static fn (array $a, array $b): int => [$b['customers'], $a['city']] <=> [$a['customers'], $b['city']]);
        $cities = array_slice($cities, 0, self::CITIES_LIMIT);

        return [$reachable, $byOperator, $cities];
    }

    /**
     * Adres użyteczny jak przy wysyłce kampanii (AudienceResolver): poprawny i nie ogólny (faktury@, księgowość@ —
     * config campaigns.excluded_local_prefixes). Wypisanych sprawdza wywołujący.
     *
     * @param  list<string>  $prefixes
     */
    private function usable(string $email, array $prefixes): bool
    {
        if ($email === '' || strlen($email) > 255 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }
        $local = (string) strstr($email, '@', true);
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($local, $prefix)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Klucz scalania miasta: wielkie litery bez polskich znaków („Kraków”, „Krakow”, „KRAKÓW ” → KRAKOW).
     * Skrótów („GŁOGÓW MŁP.”) nie rozwijamy — nie zgadujemy.
     */
    private function cityKey(string $city): string
    {
        return strtr(mb_strtoupper(trim($city), 'UTF-8'), self::POLISH_UPPER);
    }

    /**
     * Wyświetlana pisownia miasta: najczęstsza; remis — wolimy zapis z polskimi znakami, potem nie w całości
     * wielkimi literami, potem alfabet.
     *
     * @param  array<string, int>  $spellings
     */
    private function displaySpelling(array $spellings): string
    {
        $best = null;
        foreach ($spellings as $spelling => $count) {
            $spelling = (string) $spelling;
            $upper = mb_strtoupper($spelling, 'UTF-8');
            $rank = [$count, strtr($upper, self::POLISH_UPPER) !== $upper ? 1 : 0, $spelling !== $upper ? 1 : 0];
            if ($best === null || $rank > $best[1] || ($rank === $best[1] && strcmp($spelling, $best[0]) < 0)) {
                $best = [$spelling, $rank];
            }
        }

        return $best[0] ?? '';
    }

    /**
     * Operatorzy XL (main_operator, znormalizowany jak w panelu operatorów: trim + wielkie litery); NULL → '(brak)'.
     *
     * @param  array<string, int>  $reachable
     * @return list<array<string, mixed>>
     */
    private function operators(string $c6, string $c12, string $c24, array $reachable): array
    {
        $out = [];
        foreach ($this->customers()
            ->select('main_operator')
            ->selectRaw('SUM(CASE WHEN last_sale_at >= ? THEN 1 ELSE 0 END) AS active_6m', [$c6])
            ->selectRaw('SUM(CASE WHEN last_sale_at >= ? AND last_sale_at < ? THEN 1 ELSE 0 END) AS dormant_6_12', [$c12, $c6])
            ->selectRaw('SUM(CASE WHEN last_sale_at >= ? AND last_sale_at < ? THEN 1 ELSE 0 END) AS lapsing_12_24', [$c24, $c12])
            ->groupBy('main_operator')
            ->get() as $row) {
            $key = $this->operatorKey($row->main_operator);
            $out[$key] ??= ['operator' => $key, 'name' => null, 'active_6m' => 0, 'dormant_6_12' => 0, 'lapsing_12_24' => 0, 'reachable_active_12m' => 0];
            $out[$key]['active_6m'] += (int) $row->active_6m;
            $out[$key]['dormant_6_12'] += (int) $row->dormant_6_12;
            $out[$key]['lapsing_12_24'] += (int) $row->lapsing_12_24;
        }
        foreach ($reachable as $key => $count) {
            if (isset($out[$key])) {
                $out[$key]['reachable_active_12m'] = $count;
            }
        }

        // operator → użytkownik (users.erp_operator_ident, ten sam zapis); kilku z tym samym — najstarsze konto
        $names = [];
        foreach (DB::table('users')->whereNotNull('erp_operator_ident')->orderBy('id')->get(['name', 'erp_operator_ident']) as $u) {
            $names[$this->operatorKey($u->erp_operator_ident)] ??= (string) $u->name;
        }

        $rows = [];
        foreach ($out as $key => $row) {
            if ($row['active_6m'] + $row['dormant_6_12'] + $row['lapsing_12_24'] === 0) {
                continue;
            }
            $row['name'] = $key !== self::NO_OPERATOR ? ($names[$key] ?? null) : null;
            $rows[] = $row;
        }
        usort($rows, static fn (array $a, array $b): int => [$b['active_6m'], $b['dormant_6_12'], $b['lapsing_12_24'], $a['operator']]
            <=> [$a['active_6m'], $a['dormant_6_12'], $a['lapsing_12_24'], $b['operator']]);

        return $rows;
    }

    private function operatorKey(mixed $ident): string
    {
        $key = mb_strtoupper(trim((string) $ident), 'UTF-8');

        return $key !== '' ? $key : self::NO_OPERATOR;
    }

    /** @return list<array<string, mixed>> */
    private function topCustomers(): array
    {
        return $this->customers()
            ->where('sale_documents_24m', '>', 0)
            ->select(['id', 'acronym', 'name', 'city', 'sale_documents_24m', 'last_sale_at'])
            ->selectSub(DB::table('erp_customer_items')->selectRaw('COUNT(*)')->whereColumn('erp_customer_items.erp_customer_id', 'erp_customers.id'), 'items_24m')
            ->orderByDesc('sale_documents_24m')
            ->orderBy('id')
            ->limit(self::TOP_CUSTOMERS_LIMIT)
            ->get()
            ->map(static fn ($r): array => [
                'id' => (int) $r->id,
                'acronym' => $r->acronym !== null && $r->acronym !== '' ? (string) $r->acronym : null,
                'name' => trim((string) $r->name) !== '' ? (string) $r->name : (string) $r->acronym,
                'city' => $r->city !== null && trim((string) $r->city) !== '' ? trim((string) $r->city) : null,
                'documents_24m' => (int) $r->sale_documents_24m,
                'items_24m' => (int) $r->items_24m,
                'last_sale_at' => $r->last_sale_at !== null ? substr((string) $r->last_sale_at, 0, 10) : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Towary kupowane przez najwięcej klientów (24 mies. z erp_customer_items), bez usuniętych towarów i bez klientów
     * usuniętych / archiwalnych. Karta: powiązanie auto|confirmed z kartą — potwierdzone przed automatycznym, potem
     * najstarsze powiązanie (jak ErpItemCards na listach Zapasów).
     *
     * @return list<array<string, mixed>>
     */
    private function topItems(): array
    {
        $rows = DB::table('erp_customer_items')
            ->join('erp_items', 'erp_items.id', '=', 'erp_customer_items.erp_item_id')
            ->join('erp_customers', 'erp_customers.id', '=', 'erp_customer_items.erp_customer_id')
            ->whereNull('erp_items.removed_at')
            ->whereNull('erp_customers.removed_at')
            ->where('erp_customers.archived', false)
            ->select(['erp_customer_items.erp_item_id', 'erp_items.code', 'erp_items.name'])
            ->selectRaw('COUNT(DISTINCT erp_customer_items.erp_customer_id) AS customers')
            ->selectRaw('SUM(erp_customer_items.documents) AS documents')
            ->groupBy('erp_customer_items.erp_item_id', 'erp_items.code', 'erp_items.name')
            ->orderByDesc('customers')
            ->orderByDesc('documents')
            ->orderBy('erp_customer_items.erp_item_id')
            ->limit(self::TOP_ITEMS_LIMIT)
            ->get();

        $itemIds = $rows->pluck('erp_item_id')->map(static fn ($id): int => (int) $id)->all();
        $cards = [];
        if ($itemIds !== []) {
            foreach (DB::table('erp_item_links')
                ->whereIn('erp_item_id', $itemIds)
                ->whereIn('status', ErpItemCards::LINKED)
                ->whereNotNull('product_id')
                ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [ErpItemLink::STATUS_CONFIRMED])
                ->orderBy('id')
                ->get(['erp_item_id', 'product_id']) as $link) {
                $cards[(int) $link->erp_item_id] ??= (int) $link->product_id;
            }
        }

        return $rows->map(static fn ($r): array => [
            'erp_item_id' => (int) $r->erp_item_id,
            'code' => (string) $r->code,
            'name' => (string) $r->name,
            'customers' => (int) $r->customers,
            'documents' => (int) $r->documents,
            'product_id' => $cards[(int) $r->erp_item_id] ?? null,
        ])->values()->all();
    }
}
