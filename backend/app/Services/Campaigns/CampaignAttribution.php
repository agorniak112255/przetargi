<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRecipientCustomer;
use App\Models\ErpCustomer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Przypisanie pozycji faktur i paragonów z ERP XL (erp_sale_lines) kampaniom — podstawa raportu „Wynik kampanii”.
 *
 * - Pozycja sprzedaży (FS 2033 / PA 2034, ilość > 0) klienta K, towaru T, z dnia D należy do kampanii, która ma T
 *   w pozycjach i której okno dla K obejmuje D (od dnia maila do odbiorcy przypisanego K do dnia startu + 30, czas
 *   polski). Kilka pasujących — wygrywa najpóźniejszy mail do K (remis: wyższe id kampanii); pozostałe widzą pozycję
 *   jako przejętą (taken_over). Liczone globalnie, bez względu na to, kto patrzy.
 * - Korekta (FSK 2041 / PAK 2042) dziedziczy kampanię i ułamek limitu po pozycji korygowanej (ten sam dokument
 *   i towar, najniższy numer pozycji); bez takiej pozycji — „korekta bez przypisanej faktury”, poza wynikiem.
 * - Limit stanu: stan z dnia wysyłki kampanii zużywa każda sprzedaż towaru od dnia startu (wszyscy klienci, wszystkie
 *   kampanie) w kolejności (data, typ, dokument, pozycja); część pozycji ponad pozostały stan nie liczy się do
 *   uwolnionych pieniędzy.
 * - Koszt: z ERP XL (cost_value), inaczej szacunek ilość × koszt jednostki z dnia wysyłki, inaczej nieznany (null).
 * - Zalegający w dniu wysyłki: ≥ STAGNANT_DAYS dni bez sprzedaży albo nigdy niesprzedany z partią ≥ STAGNANT_DAYS
 *   dni; bez danych z chwili wysyłki — null (nie „nie”).
 *
 * Zapytania płaskie (bez GROUP BY i bez funkcji dat w SQL — MariaDB z ONLY_FULL_GROUP_BY, testy na SQLite); daty
 * porównywane jako napisy RRRR-MM-DD, kwoty w groszach (int).
 */
final class CampaignAttribution
{
    /** Faktura sprzedaży i paragon. */
    public const SALE_TYPES = [2033, 2034];

    /** Korekta faktury i korekta paragonu. */
    public const CORRECTION_TYPES = [2041, 2042];

    /** Towar „zalegający”: tyle dni bez sprzedaży (albo wiek partii nigdy niesprzedanego) w dniu startu wysyłki. */
    public const STAGNANT_DAYS = 180;

    /** Kampanie, które wysyłały maile. */
    public const STATUSES = [Campaign::STATUS_SENDING, Campaign::STATUS_SENT, Campaign::STATUS_CANCELLED];

    public const COST_XL = 'xl';

    public const COST_ESTIMATE = 'estimate';

    public const COST_NONE = 'none';

    /** Korekta samej ceny (ilość 0) bez kosztu w ERP XL — koszt się nie zmienia, liczymy 0. */
    public const COST_PRICE_ONLY = 'price_only';

    /** Ile miesięcy wstecz szukamy kampanii dla korekty starej faktury (ochrona przed wczytaniem całej historii). */
    private const CORRECTION_LOOKBACK_DAYS = 400;

    /** Najdłuższy łańcuch „korekta korekty” rozwijany do pozycji sprzedaży. */
    private const MAX_CHAIN = 5;

    private const LINE_COLUMNS = [
        'document_type', 'document_id', 'line', 'document_number', 'sold_at', 'customer_xl_gid', 'erp_customer_id',
        'erp_item_id', 'quantity', 'net_value', 'cost_value', 'corrects_document_type', 'corrects_document_id',
    ];

    /**
     * Przypisanie dla miesiąca RRRR-MM: pozycje z miesiąca oraz z całych okien kampanii, które mogą na niego zachodzić
     * (start od początku miesiąca − 30 dni), z kampaniami-rywalami potrzebnymi do rozstrzygnięcia „ostatni mail
     * wygrywa” i do limitu stanu.
     *
     * @return array{
     *     month_start: string,
     *     month_end: string,
     *     campaigns: array<int, array{id: int, user_id: int, code: string, name: string, status: string, sending_started_at: string, start: string, end: string, sent: int}>,
     *     items: array<string, array<string, mixed>>,
     *     lines: list<array<string, mixed>>,
     *     taken_over: list<array{campaign_id: int, by: int, sold_at: string, net: int}>,
     *     unlinked: list<array{sold_at: string, erp_item_id: int, customer_id: int|null, campaign_ids: list<int>}>
     * }
     */
    public function forMonth(string $month): array
    {
        $monthStart = $month.'-01';
        $monthEnd = CarbonImmutable::parse($monthStart)->endOfMonth()->toDateString();
        $to = self::addDays($monthEnd, CampaignWindow::DAYS);
        $empty = ['month_start' => $monthStart, 'month_end' => $monthEnd, 'campaigns' => [], 'items' => [], 'lines' => [], 'taken_over' => [], 'unlinked' => []];

        // pozycje korygowane przez korekty z miesiąca — mogą być starsze niż okna kampanii z miesiąca
        $targets = $this->correctionTargets($monthStart, $monthEnd);
        // zakres cofamy tylko dla sprzedaży klientów, do których kiedykolwiek wyszedł mail kampanii, i najwyżej
        // CORRECTION_LOOKBACK_DAYS wstecz — zwrot dowolnego klienta sprzed roku nie wczytuje całej historii
        $lower = $monthStart;
        $floor = self::addDays($monthStart, -self::CORRECTION_LOOKBACK_DAYS);
        $sales = array_values(array_filter($targets, static fn (array $row): bool => $row['is_sale'] && $row['customer_id'] !== null && $row['sold_at'] >= $floor));
        foreach ($this->attributableSales($sales) as $soldAt) {
            $lower = min($lower, $soldAt);
        }
        // kampanie, których przypisanie i limit stanu są potrzebne, startują od $lower − 30; ich rywale — 30 dni wcześniej
        $campaignFrom = self::addDays($lower, -(2 * CampaignWindow::DAYS + 1));

        $campaigns = $this->campaigns($campaignFrom, $to);
        if ($campaigns === []) {
            return $empty;
        }
        $startDays = array_map(static fn (array $c): string => $c['start'], $campaigns);
        $matches = $this->recipientMatches($startDays);
        foreach ($matches as $campaignId => $m) {
            $campaigns[$campaignId]['sent'] = $m['sent'];
        }
        [$items, $itemCampaigns] = $this->items($startDays);
        if ($items === []) {
            return ['campaigns' => $campaigns] + $empty;
        }

        $minStart = min(array_column($campaigns, 'start'));
        $lines = $this->lines(array_keys($itemCampaigns), $minStart, $to, $targets);

        // przypisanie pozycji sprzedaży
        $taken = [];
        $attributed = []; // indeks pozycji → [kampania, dzień maila, dopasowanie]
        foreach ($lines as $i => $line) {
            if ($line['is_correction'] || ! $line['is_sale'] || $line['customer_id'] === null) {
                continue;
            }
            $candidates = [];
            foreach ($itemCampaigns[$line['erp_item_id']] ?? [] as $campaignId) {
                $entries = $matches[$campaignId]['customers'][$line['customer_id']] ?? null;
                if ($entries === null || $line['sold_at'] > $campaigns[$campaignId]['end']) {
                    continue;
                }
                // najpóźniejszy mail przed zakupem; dopasowanie klienta z tego samego dnia maila (CSV: para kolumn)
                $rank = null;
                foreach ($entries as $e) {
                    if ($e['day'] <= $line['sold_at']) {
                        $rank = $rank === null ? $e['day'] : max($rank, $e['day']);
                    }
                }
                $best = null;
                foreach ($entries as $e) {
                    if ($e['day'] === $rank) {
                        $best = self::betterMatch($best, $e);
                    }
                }
                if ($rank !== null && $best !== null) {
                    $candidates[] = ['campaign_id' => $campaignId, 'day' => $rank, 'match' => $best];
                }
            }
            if ($candidates === []) {
                continue;
            }
            usort($candidates, static fn (array $a, array $b): int => [$b['day'], $b['campaign_id']] <=> [$a['day'], $a['campaign_id']]);
            $winner = $candidates[0];
            $attributed[$i] = $winner;
            foreach (array_slice($candidates, 1) as $lost) {
                $taken[] = ['campaign_id' => $lost['campaign_id'], 'by' => $winner['campaign_id'], 'sold_at' => $line['sold_at'], 'net' => $line['net']];
            }
        }

        // limit stanu z dnia wysyłki: stan kampanii zużywa KAŻDA sprzedaż tego towaru od dnia startu (także innym
        // klientom i z innych kampanii) — dwie kampanie nie dostaną uwolnionych pieniędzy za ten sam zapas, a towar
        // wykupiony wcześniej przez kogoś innego nie jest już zapasem z dnia wysyłki
        $saleIdx = [];
        foreach ($lines as $i => $line) {
            if ($line['is_sale']) {
                $saleIdx[$line['erp_item_id']][] = $i;
            }
        }
        $cumulative = [];
        $position = [];
        foreach ($saleIdx as $itemId => $indexes) {
            $sum = 0.0;
            foreach ($indexes as $p => $i) {
                $cumulative[$itemId][$p] = $sum;
                $position[$i] = $p;
                $sum += $lines[$i]['quantity'];
            }
        }
        $fraction = [];
        $over = [];
        foreach ($attributed as $i => $winner) {
            $itemId = $lines[$i]['erp_item_id'];
            $stock = $items[$winner['campaign_id'].':'.$itemId]['stock_at_send'];
            $quantity = $lines[$i]['quantity'];
            if ($stock === null) {
                $fraction[$i] = null;
                $over[$i] = 0.0;

                continue;
            }
            $first = self::firstSaleFrom($saleIdx[$itemId], $lines, $campaigns[$winner['campaign_id']]['start']);
            $consumed = $cumulative[$itemId][$position[$i]] - ($cumulative[$itemId][$first] ?? 0.0);
            $inLimit = min($quantity, max($stock - $consumed, 0.0));
            $fraction[$i] = $inLimit / $quantity;
            $over[$i] = round($quantity - $inLimit, 3);
        }

        // korekty: kampania i ułamek po pozycji korygowanej
        $byDocument = [];
        foreach ($lines as $i => $line) {
            $docKey = $line['document_type'].':'.$line['document_id'].':'.$line['erp_item_id'];
            if (! isset($byDocument[$docKey]) || $lines[$byDocument[$docKey]]['line'] > $line['line']) {
                $byDocument[$docKey] = $i;
            }
        }
        $unlinked = [];
        foreach ($lines as $i => $line) {
            if (! $line['is_correction']) {
                continue;
            }
            $root = $this->rootSale($i, $lines, $byDocument);
            if ($root === null) {
                // Ostrzeżenie tylko, gdy ERP XL nie wskazał dokumentu korygowanego. Wskazany, a nieobecny w kopii =
                // faktura sprzed odczytu sprzedaży kampanii (kopia zaczyna się od startu najstarszej kampanii z tym
                // towarem), więc nie mogła należeć do kampanii — bez fałszywego ostrzeżenia.
                if ($line['corrects'] === null && $line['sold_at'] >= $monthStart && $line['sold_at'] <= $monthEnd) {
                    $unlinked[] = [
                        'sold_at' => $line['sold_at'],
                        'erp_item_id' => $line['erp_item_id'],
                        'customer_id' => $line['customer_id'],
                        'campaign_ids' => $this->possibleCampaigns($line, $itemCampaigns, $matches, $campaigns),
                    ];
                }

                continue;
            }
            if (isset($attributed[$root])) {
                $attributed[$i] = $attributed[$root];
                $fraction[$i] = $fraction[$root];
                $over[$i] = 0.0;
            }
        }

        $out = [];
        foreach ($attributed as $i => $winner) {
            $line = $lines[$i];
            $item = $items[$winner['campaign_id'].':'.$line['erp_item_id']];
            [$cost, $source] = self::cost($line, $item['unit_cost']);
            $f = $fraction[$i];
            $counts = $item['stagnant'] === true && $cost !== null && $f !== null;
            $freed = $counts ? (int) round($cost * $f) : 0;
            // nie wiadomo, czy i ile uwolniła: brak danych z dnia wysyłki albo zalegający bez kosztu / bez stanu
            $freedUnknown = $item['stagnant'] === null || ($item['stagnant'] === true && ($cost === null || $f === null));
            $out[] = [
                ...$line,
                'campaign_id' => $winner['campaign_id'],
                'mail_day' => $winner['day'],
                'matched_by' => $winner['match']['matched_by'],
                'cards_count' => $winner['match']['cards'],
                'fraction' => $f,
                'over_quantity' => $over[$i],
                'cost' => $cost,
                'cost_source' => $source,
                'freed' => $freed,
                'freed_sales' => $counts ? (int) round($line['net'] * $f) : 0,
                'freed_estimated' => $source === self::COST_ESTIMATE ? $freed : 0,
                'freed_unknown' => $freedUnknown,
            ];
        }

        return [
            'month_start' => $monthStart,
            'month_end' => $monthEnd,
            'campaigns' => $campaigns,
            'items' => $items,
            'lines' => $out,
            'taken_over' => $taken,
            'unlinked' => $unlinked,
        ];
    }

    /**
     * Odbiorcy z wysłanym mailem jako klienci ERP XL: z campaign_recipient_customers (zamrożone przy wysyłce), a gdy
     * kampania nie ma tam żadnego wiersza — jak dawniej: klient XL odbiorcy albo karty z jego adresem e-mail.
     * Dzień maila to data polska, nie wcześniejsza niż dzień startu (brak chwili wysłania = dzień startu).
     *
     * @param  array<int, string>  $startDays  id kampanii → dzień startu (RRRR-MM-DD)
     * @return array<int, array{sent: int, customers: array<int, list<array{day: string, matched_by: string, cards: int, email: string}>>, emails: array<string, true>}>
     */
    public function recipientMatches(array $startDays): array
    {
        $out = [];
        foreach ($startDays as $campaignId => $day) {
            $out[(int) $campaignId] = ['sent' => 0, 'customers' => [], 'emails' => []];
        }
        if ($out === []) {
            return $out;
        }
        $ids = array_keys($out);

        $frozen = [];
        $frozenCampaigns = [];
        foreach (DB::table('campaign_recipient_customers')->whereIntegerInRaw('campaign_id', $ids)->orderBy('id')
            ->get(['campaign_id', 'campaign_recipient_id', 'erp_customer_id', 'matched_by', 'cards_count']) as $row) {
            $frozenCampaigns[(int) $row->campaign_id] = true;
            $frozen[(int) $row->campaign_recipient_id][] = [
                (int) $row->erp_customer_id,
                $row->matched_by === CampaignRecipientCustomer::MATCHED_DIRECT ? CampaignRecipientCustomer::MATCHED_DIRECT : CampaignRecipientCustomer::MATCHED_EMAIL,
                max(1, (int) $row->cards_count),
            ];
        }

        $emailCustomers = null;
        foreach (DB::table('campaign_recipients')->whereIntegerInRaw('campaign_id', $ids)->where('status', CampaignRecipient::STATUS_SENT)
            ->orderBy('id')->get(['id', 'campaign_id', 'email', 'erp_customer_id', 'sent_at']) as $r) {
            $campaignId = (int) $r->campaign_id;
            $out[$campaignId]['sent']++;
            $start = $startDays[$campaignId];
            $mail = $r->sent_at !== null ? CampaignWindow::mailDay(CarbonImmutable::parse((string) $r->sent_at))?->toDateString() : null;
            $day = max($mail ?? $start, $start);
            $email = mb_strtolower((string) $r->email);
            if (isset($frozenCampaigns[$campaignId])) {
                $links = $frozen[(int) $r->id] ?? [];
            } elseif ($r->erp_customer_id !== null) {
                $links = [[(int) $r->erp_customer_id, CampaignRecipientCustomer::MATCHED_DIRECT, 1]];
            } else {
                $emailCustomers ??= $this->emailToCustomers();
                $cards = $emailCustomers[$email] ?? [];
                $links = array_map(static fn (int $id): array => [$id, CampaignRecipientCustomer::MATCHED_EMAIL, count($cards)], $cards);
            }
            foreach ($links as [$customerId, $by, $cards]) {
                $out[$campaignId]['customers'][$customerId][] = ['day' => $day, 'matched_by' => $by, 'cards' => $cards, 'email' => $email];
                $out[$campaignId]['emails'][$email] = true;
            }
        }

        return $out;
    }

    /** Pozycja sprzedaży FS/PA (ilość > 0) — nie korekta. */
    public static function isSale(int $documentType, float $quantity): bool
    {
        return in_array($documentType, self::SALE_TYPES, true) && $quantity > 0;
    }

    public static function isCorrection(int $documentType): bool
    {
        return in_array($documentType, self::CORRECTION_TYPES, true);
    }

    /**
     * Zalegający w dniu startu: dni bez sprzedaży, nigdy niesprzedany, wiek partii.
     *
     * @return array{stagnant: bool|null, idle_days: int|null, never_sold: bool, lot_age_days: int|null}
     */
    public static function stagnation(?string $snapSource, string $startDay, ?string $lastSaleAt, ?string $oldestLotAt): array
    {
        if ($snapSource === null) {
            return ['stagnant' => null, 'idle_days' => null, 'never_sold' => false, 'lot_age_days' => null];
        }
        $lotAge = $oldestLotAt !== null ? self::daysBetween($oldestLotAt, $startDay) : null;
        if ($lastSaleAt !== null) {
            $idle = self::daysBetween($lastSaleAt, $startDay);

            return ['stagnant' => $idle >= self::STAGNANT_DAYS, 'idle_days' => $idle, 'never_sold' => false, 'lot_age_days' => $lotAge];
        }

        return ['stagnant' => $lotAge !== null ? $lotAge >= self::STAGNANT_DAYS : null, 'idle_days' => null, 'never_sold' => true, 'lot_age_days' => $lotAge];
    }

    /** Dni od $from do $to (daty RRRR-MM-DD, wynik ze znakiem). */
    public static function daysBetween(string $from, string $to): int
    {
        return intdiv((int) strtotime($to.' 00:00:00 UTC') - (int) strtotime($from.' 00:00:00 UTC'), 86400);
    }

    public static function addDays(string $day, int $days): string
    {
        return CarbonImmutable::parse($day, 'UTC')->addDays($days)->toDateString();
    }

    /** Kwota z bazy („1234.50”, „-12.3”) w groszach. */
    public static function cents(string|float|int $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    /**
     * Koszt pozycji w groszach i jego źródło. Korekta samej ceny (ilość 0) bez kosztu w ERP XL ma koszt 0 — XL nie
     * zmienia kosztu przy korekcie ceny (zero zapisujemy jako brak).
     *
     * @param  array<string, mixed>  $line
     * @return array{0: int|null, 1: string}
     */
    private static function cost(array $line, ?float $unitCost): array
    {
        if ($line['cost_value'] !== null) {
            return [$line['cost_value'], self::COST_XL];
        }
        if ($line['is_correction'] && $line['quantity'] == 0.0) {
            return [0, self::COST_PRICE_ONLY];
        }
        if ($unitCost !== null) {
            return [(int) round($line['quantity'] * $unitCost * 100), self::COST_ESTIMATE];
        }

        return [null, self::COST_NONE];
    }

    /**
     * Indeks (w liście sprzedaży towaru) pierwszej sprzedaży z dnia $day lub późniejszej — lista posortowana po dacie.
     *
     * @param  list<int>  $indexes  indeksy pozycji sprzedaży towaru w $lines
     * @param  list<array<string, mixed>>  $lines
     */
    private static function firstSaleFrom(array $indexes, array $lines, string $day): int
    {
        $lo = 0;
        $hi = count($indexes);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($lines[$indexes[$mid]]['sold_at'] < $day) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return $lo;
    }

    /**
     * Pewniejsze dopasowanie klienta: wybrany z ERP XL przed adresem e-mail, adres na mniejszej liczbie kart przed większą.
     *
     * @param  array{day: string, matched_by: string, cards: int, email: string}|null  $a
     * @param  array{day: string, matched_by: string, cards: int, email: string}  $b
     * @return array{day: string, matched_by: string, cards: int, email: string}
     */
    private static function betterMatch(?array $a, array $b): array
    {
        if ($a === null) {
            return $b;
        }
        $rank = static fn (array $m): array => [$m['matched_by'] === CampaignRecipientCustomer::MATCHED_DIRECT ? 0 : 1, $m['cards']];

        return $rank($b) < $rank($a) ? $b : $a;
    }

    /**
     * Pozycja sprzedaży, którą korekta (także korekta korekty) ostatecznie koryguje; null = brak w kopii.
     *
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, int>  $byDocument
     */
    private function rootSale(int $i, array $lines, array $byDocument): ?int
    {
        for ($depth = 0; $depth < self::MAX_CHAIN; $depth++) {
            $line = $lines[$i];
            if (! $line['is_correction']) {
                return $line['is_sale'] ? $i : null;
            }
            if ($line['corrects'] === null) {
                return null;
            }
            $next = $byDocument[$line['corrects'].':'.$line['erp_item_id']] ?? null;
            if ($next === null || $next === $i) {
                return null;
            }
            $i = $next;
        }

        return null;
    }

    /**
     * Kampanie, do których korekta bez faktury mogłaby należeć (towar w pozycjach, klient dostał mail przed jej datą;
     * klient nieznany — sam towar) — do ostrzeżenia w zakresie patrzącego.
     *
     * @param  array<string, mixed>  $line
     * @param  array<int, list<int>>  $itemCampaigns
     * @param  array<int, array{sent: int, customers: array<int, list<array{day: string, matched_by: string, cards: int, email: string}>>, emails: array<string, true>}>  $matches
     * @param  array<int, array<string, mixed>>  $campaigns
     * @return list<int>
     */
    private function possibleCampaigns(array $line, array $itemCampaigns, array $matches, array $campaigns): array
    {
        $out = [];
        foreach ($itemCampaigns[$line['erp_item_id']] ?? [] as $campaignId) {
            if ($campaigns[$campaignId]['start'] > $line['sold_at']) {
                continue;
            }
            if ($line['customer_id'] === null) {
                $out[] = $campaignId;

                continue;
            }
            foreach ($matches[$campaignId]['customers'][$line['customer_id']] ?? [] as $e) {
                if ($e['day'] <= $line['sold_at']) {
                    $out[] = $campaignId;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Kampanie po starcie wysyłki z dniem startu (czas polski) w [$from, $to].
     *
     * @return array<int, array{id: int, user_id: int, code: string, name: string, status: string, sending_started_at: string, start: string, end: string, sent: int}>
     */
    private function campaigns(string $from, string $to): array
    {
        $out = [];
        // zapas dnia w UTC na przesunięcie strefy; dokładny dzień polski sprawdzany niżej
        $rows = Campaign::query()
            ->whereIn('status', self::STATUSES)
            ->whereNotNull('sending_started_at')
            ->where('sending_started_at', '>=', self::addDays($from, -1))
            ->where('sending_started_at', '<', self::addDays($to, 2))
            ->orderBy('id')
            ->get(['id', 'user_id', 'code', 'name', 'status', 'sending_started_at']);
        foreach ($rows as $c) {
            $start = CampaignWindow::startDay($c)?->toDateString();
            if ($start === null || $start < $from || $start > $to) {
                continue;
            }
            $out[(int) $c->id] = [
                'id' => (int) $c->id,
                'user_id' => (int) $c->user_id,
                'code' => (string) $c->code,
                'name' => (string) $c->name,
                'status' => (string) $c->status,
                'sending_started_at' => CarbonImmutable::instance($c->sending_started_at)->toIso8601String(),
                'start' => $start,
                'end' => self::addDays($start, CampaignWindow::DAYS),
                'sent' => 0,
            ];
        }

        return $out;
    }

    /**
     * Pozycje kampanii z towarem XL: pierwsza pozycja danego towaru w kampanii (kolejność pozycji) niesie migawkę.
     *
     * @param  array<int, string>  $startDays  id kampanii → dzień startu
     * @return array{0: array<string, array<string, mixed>>, 1: array<int, list<int>>} klucz „kampania:towar”; towar → kampanie
     */
    private function items(array $startDays): array
    {
        $rows = DB::table('campaign_items')->whereIntegerInRaw('campaign_id', array_keys($startDays))->whereNotNull('erp_item_id')
            ->orderBy('campaign_id')->orderBy('position')->orderBy('id')
            ->get(['campaign_id', 'erp_item_id', 'promo_price_net', 'snap_name', 'snap_code', 'snap_unit', 'snap_price', 'snap_stock',
                'snap_unit_cost', 'snap_last_sale_at', 'snap_oldest_lot_at', 'snap_source']);
        $erp = DB::table('erp_items')->whereIntegerInRaw('id', $rows->pluck('erp_item_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all())
            ->get(['id', 'code', 'name', 'unit'])->keyBy('id');

        $items = [];
        $itemCampaigns = [];
        foreach ($rows as $row) {
            $campaignId = (int) $row->campaign_id;
            $itemId = (int) $row->erp_item_id;
            $key = $campaignId.':'.$itemId;
            if (isset($items[$key])) {
                continue;
            }
            $e = $erp[$itemId] ?? null;
            $snapSource = $row->snap_source !== null ? (string) $row->snap_source : null;
            $items[$key] = [
                'campaign_id' => $campaignId,
                'erp_item_id' => $itemId,
                'code' => (string) ($e->code ?? $row->snap_code ?? ''),
                'name' => (string) ($row->snap_name ?? $e->name ?? ''),
                'unit' => $e->unit ?? $row->snap_unit,
                'snap_source' => $snapSource,
                'stock_at_send' => $row->snap_stock !== null ? (float) $row->snap_stock : null,
                'unit_cost' => $row->snap_unit_cost !== null ? (float) $row->snap_unit_cost : null,
                'promo_price' => $row->snap_price ?? $row->promo_price_net,
                ...self::stagnation(
                    $snapSource,
                    $startDays[$campaignId],
                    $row->snap_last_sale_at !== null ? substr((string) $row->snap_last_sale_at, 0, 10) : null,
                    $row->snap_oldest_lot_at !== null ? substr((string) $row->snap_oldest_lot_at, 0, 10) : null,
                ),
            ];
            $itemCampaigns[$itemId][] = $campaignId;
        }

        return [$items, $itemCampaigns];
    }

    /**
     * Pozycje towarów kampanii z dni [$from, $to] razem z doczytanymi pozycjami korygowanymi, posortowane
     * (data, typ, dokument, pozycja). Klient: erp_customer_id, a bez niego karta o xl_gid = customer_xl_gid.
     *
     * @param  list<int>  $itemIds
     * @param  list<array<string, mixed>>  $extra
     * @return list<array<string, mixed>>
     */
    private function lines(array $itemIds, string $from, string $to, array $extra): array
    {
        $rows = [];
        foreach (array_chunk($itemIds, 1000) as $chunk) {
            foreach (DB::table('erp_sale_lines')->whereIntegerInRaw('erp_item_id', $chunk)
                ->where('sold_at', '>=', $from)
                // przedział [od, do + 1) — działa także dla daty zapisanej z godziną 00:00:00
                ->where('sold_at', '<', self::addDays($to, 1))
                ->get(self::LINE_COLUMNS) as $row) {
                $line = self::normalize($row);
                $rows[$line['key']] = $line;
            }
        }
        foreach ($extra as $line) {
            $rows[$line['key']] ??= $line;
        }

        $missing = [];
        foreach ($rows as $line) {
            if ($line['customer_id'] === null) {
                $missing[$line['customer_xl_gid']] = true;
            }
        }
        if ($missing !== []) {
            $byGid = [];
            foreach (array_chunk(array_keys($missing), 1000) as $chunk) {
                $byGid += DB::table('erp_customers')->whereIntegerInRaw('xl_gid', $chunk)->pluck('id', 'xl_gid')
                    ->mapWithKeys(static fn ($id, $gid): array => [(int) $gid => (int) $id])->all();
            }
            foreach ($rows as $key => $line) {
                if ($line['customer_id'] === null) {
                    $rows[$key]['customer_id'] = $byGid[$line['customer_xl_gid']] ?? null;
                }
            }
        }

        $list = array_values($rows);
        usort($list, static fn (array $a, array $b): int => [$a['sold_at'], $a['document_type'], $a['document_id'], $a['line']]
            <=> [$b['sold_at'], $b['document_type'], $b['document_id'], $b['line']]);

        return $list;
    }

    /**
     * Pozycje korygowane przez korekty z dni [$from, $to] (także łańcuch „korekta korekty”).
     *
     * @return list<array<string, mixed>>
     */
    private function correctionTargets(string $from, string $to): array
    {
        $pending = [];
        foreach (DB::table('erp_sale_lines')->whereIn('document_type', self::CORRECTION_TYPES)
            ->where('sold_at', '>=', $from)->where('sold_at', '<', self::addDays($to, 1))
            ->whereNotNull('corrects_document_id')
            ->get(['corrects_document_type', 'corrects_document_id']) as $row) {
            $pending[(int) $row->corrects_document_type.':'.(int) $row->corrects_document_id] = true;
        }
        $found = [];
        $seen = [];
        for ($depth = 0; $depth < self::MAX_CHAIN && $pending !== []; $depth++) {
            $seen += $pending;
            $types = [];
            $ids = [];
            foreach (array_keys($pending) as $pair) {
                [$type, $id] = array_map('intval', explode(':', $pair));
                $types[$type] = true;
                $ids[$id] = true;
            }
            $next = [];
            foreach (array_chunk(array_keys($ids), 1000) as $chunk) {
                foreach (DB::table('erp_sale_lines')->whereIn('document_type', array_keys($types))->whereIntegerInRaw('document_id', $chunk)
                    ->get(self::LINE_COLUMNS) as $row) {
                    $line = self::normalize($row);
                    if (! isset($pending[$line['document_type'].':'.$line['document_id']])) {
                        continue;
                    }
                    $found[$line['key']] = $line;
                    if ($line['is_correction'] && $line['corrects'] !== null && ! isset($seen[$line['corrects']])) {
                        $next[$line['corrects']] = true;
                    }
                }
            }
            $pending = $next;
        }

        return array_values($found);
    }

    /**
     * Daty pozycji sprzedaży (korygowanych w miesiącu), które mogły należeć do kampanii: klient dostał mail kampanii
     * z tym towarem (zamrożone dopasowanie albo klient XL odbiorcy), a kampania wystartowała w [data − okno, data].
     * Tylko dla nich raport cofa zakres wstecz — zwrot faktury bez związku z kampaniami nie wczytuje historii.
     *
     * @param  list<array<string, mixed>>  $sales
     * @return list<string>
     */
    private function attributableSales(array $sales): array
    {
        if ($sales === []) {
            return [];
        }
        $customers = array_values(array_unique(array_map(static fn (array $l): int => (int) $l['customer_id'], $sales)));
        $itemIds = array_values(array_unique(array_map(static fn (array $l): int => (int) $l['erp_item_id'], $sales)));
        $from = self::addDays(min(array_column($sales, 'sold_at')), -(CampaignWindow::DAYS + 2));

        // klient × towar → dni startu kampanii (data polska); dwa płaskie zapytania, bez GROUP BY
        $starts = [];
        foreach ([['campaign_recipient_customers', 'erp_customer_id'], ['campaign_recipients', 'erp_customer_id']] as [$table, $column]) {
            foreach (array_chunk($customers, 1000) as $chunk) {
                $rows = DB::table($table.' as r')
                    ->join('campaigns as c', 'c.id', '=', 'r.campaign_id')
                    ->join('campaign_items as ci', 'ci.campaign_id', '=', 'c.id')
                    ->whereIntegerInRaw('r.'.$column, $chunk)
                    ->whereIntegerInRaw('ci.erp_item_id', $itemIds)
                    ->whereIn('c.status', self::STATUSES)
                    ->where('c.sending_started_at', '>=', $from)
                    ->get(['r.'.$column.' as customer_id', 'ci.erp_item_id', 'c.sending_started_at']);
                foreach ($rows as $row) {
                    $day = CampaignWindow::mailDay(CarbonImmutable::parse((string) $row->sending_started_at))?->toDateString();
                    if ($day !== null) {
                        $starts[(int) $row->customer_id.':'.(int) $row->erp_item_id][$day] = true;
                    }
                }
            }
        }

        $out = [];
        foreach ($sales as $line) {
            foreach (array_keys($starts[$line['customer_id'].':'.$line['erp_item_id']] ?? []) as $start) {
                if ($start <= $line['sold_at'] && $line['sold_at'] <= self::addDays($start, CampaignWindow::DAYS)) {
                    $out[] = $line['sold_at'];
                    break;
                }
            }
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function normalize(object $row): array
    {
        $type = (int) $row->document_type;
        $quantity = round((float) $row->quantity, 3);
        $correctsType = $row->corrects_document_type !== null ? (int) $row->corrects_document_type : null;
        $correctsId = $row->corrects_document_id !== null ? (int) $row->corrects_document_id : null;

        return [
            'key' => $type.':'.(int) $row->document_id.':'.(int) $row->line,
            'document_type' => $type,
            'document_id' => (int) $row->document_id,
            'line' => (int) $row->line,
            'document_number' => (string) $row->document_number,
            'sold_at' => substr((string) $row->sold_at, 0, 10),
            'customer_xl_gid' => (int) $row->customer_xl_gid,
            'customer_id' => $row->erp_customer_id !== null ? (int) $row->erp_customer_id : null,
            'erp_item_id' => (int) $row->erp_item_id,
            'quantity' => $quantity,
            'net' => self::cents((string) $row->net_value),
            'cost_value' => $row->cost_value !== null ? self::cents((string) $row->cost_value) : null,
            'corrects' => $correctsType !== null && $correctsId !== null ? $correctsType.':'.$correctsId : null,
            'is_sale' => self::isSale($type, $quantity),
            'is_correction' => self::isCorrection($type),
        ];
    }

    /** @return array<string, list<int>> adres (małe litery) → karty klientów ERP XL z tym adresem (bez usuniętych) */
    private function emailToCustomers(): array
    {
        $map = [];
        foreach (ErpCustomer::query()->whereNotNull('emails')->whereNull('removed_at')->get(['id', 'emails']) as $c) {
            foreach (is_array($c->emails) ? $c->emails : [] as $email) {
                $map[mb_strtolower((string) $email)][(int) $c->id] = (int) $c->id;
            }
        }

        return array_map('array_values', $map);
    }
}
