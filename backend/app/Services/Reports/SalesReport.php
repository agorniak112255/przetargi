<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\ClientInquiry;
use App\Models\InquiryOrderHint;
use App\Models\Tender;
use App\Models\User;
use App\Services\Campaigns\CampaignSalesResult;
use App\Services\Pricing\SupplierSpecialMask;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Raport R4 „Sprzedaż i oferty”: zapytania klientów, przetargi i kampanie — każda sekcja tylko z uprawnieniem do
 * modułu (inaczej null), w zakresie, który użytkownik widzi w samym module.
 *
 * Zapytanie = grupa jednego maila: wiersz bez duplicate_of_id i jego kopie u innych handlowców (łańcuch
 * duplicate_of_id rozwijany do korzenia — findOthersInquiry wiąże z najnowszym cudzym wierszem, który sam bywa
 * kopią). Odpowiedziano = którykolwiek wiersz grupy ma replied_at; czas odpowiedzi = najwcześniejszy.
 * Dzień roboczy pomija sobotę i niedzielę (bez świąt), liczony w czasie polskim.
 */
final class SalesReport
{
    public const TIMEZONE = 'Europe/Warsaw';

    public const DAYS = [30, 90, 180];

    public const DEFAULT_DAYS = 90;

    /** Terminy przetargów w tylu najbliższych dniach (z dzisiejszym). */
    public const UPCOMING_DAYS = 14;

    /** Kolejność statusów jak frontend/src/lib/tenderStatus.ts: ścieżka, potem odrzucony i archiwum. */
    private const TENDER_STATUS_ORDER = [
        'draft', 'wycena', 'akceptacja_km', 'akceptacja_dyrektor', 'zatwierdzona', 'exported', 'odrzucony', 'archiwum',
    ];

    /** Przetargi poza pracą — bez nich lista najbliższych terminów. */
    private const TENDER_CLOSED = ['exported', 'odrzucony', 'archiwum'];

    private const NO_OWNER = 'Nieprzypisany';

    private const CHANNELS = ['thunderbird', 'web', 'file'];

    /** Wynik zapytania z zakupem (wpisany przez handlowca). */
    private const ORDERED_OUTCOMES = ['ordered', 'partial'];

    /** Kampanie po starcie wysyłki (jak lista kampanii przy campaigns.view). */
    private const CAMPAIGN_STARTED = [Campaign::STATUS_SENDING, Campaign::STATUS_SENT, Campaign::STATUS_CANCELLED];

    public function __construct(private readonly CampaignSalesResult $campaignSales) {}

    /**
     * @param  array{days?: int|null}  $params
     * @return array<string, mixed>
     */
    public function build(User $user, array $params = []): array
    {
        $days = in_array($params['days'] ?? null, self::DAYS, true) ? (int) $params['days'] : self::DEFAULT_DAYS;
        $now = CarbonImmutable::now(self::TIMEZONE);
        $from = $now->startOfDay()->subDays($days - 1);

        return [
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'days' => $days,
            'from' => $from->toDateString(),
            'inquiries' => $user->can('inquiries.use') ? $this->inquiries($user, $from, $now) : null,
            'tenders' => $user->canAny(['tenders.view_own', 'tenders.view_all']) ? $this->tenders($user, $now) : null,
            'campaigns' => $user->canAny(['campaigns.use', 'campaigns.view', 'campaigns.manage']) ? $this->campaigns($user, $from) : null,
        ];
    }

    /**
     * Termin „w 1 dzień roboczy” od chwili startu (czas polski): start w weekend liczy się od poniedziałku 00:00,
     * a doba, która kończy się w weekend, przechodzi na poniedziałek o tej samej godzinie (piątek 15:00 → pon. 15:00).
     */
    public static function oneBusinessDayAfter(CarbonImmutable $start): CarbonImmutable
    {
        $start = $start->setTimezone(self::TIMEZONE);
        if ($start->isWeekend()) {
            $start = $start->next(CarbonInterface::MONDAY);
        }
        $deadline = $start->addDay();
        while ($deadline->isWeekend()) {
            $deadline = $deadline->addDay();
        }

        return $deadline;
    }

    /** @return array<string, mixed> */
    private function inquiries(User $user, CarbonImmutable $from, CarbonImmutable $now): array
    {
        $all = $user->can('inquiries.view_all');

        // oryginały (korzenie grup) z okresu po COALESCE(source_sent_at, created_at); zakres „own” po złożeniu grup —
        // przejęta kopia cudzego maila też jest „moim” zapytaniem
        $groups = [];
        foreach (DB::table('client_inquiries')
            ->whereNull('duplicate_of_id')
            ->whereRaw('COALESCE(source_sent_at, created_at) >= ?', [$this->dbTime($from)])
            ->select(['id', 'user_id', 'source_channel', 'source_sent_at', 'created_at', 'replied_at', 'send_requested_at', 'analysis_status', 'analysis_started_at', 'outcome', 'outcome_document_number', 'outcome_net_value'])
            ->lazyById(1000) as $row) {
            $groups[(int) $row->id] = [
                // wiersze grupy (oryginał i kopie): każda osoba z własną odpowiedzią i wynikiem
                'rows' => [$this->funnelRow($row)],
                'user_id' => (int) $row->user_id,
                'channel' => (string) $row->source_channel,
                'start' => $this->parse($row->source_sent_at ?? $row->created_at),
                'replied' => $this->parse($row->replied_at),
                'queued' => $row->send_requested_at !== null,
                'failed' => (new ClientInquiry)->setRawAttributes([
                    'analysis_status' => $row->analysis_status,
                    'analysis_started_at' => $row->analysis_started_at,
                ])->effectiveAnalysisStatus() === ClientInquiry::ANALYSIS_FAILED,
                'copies' => 0,
                // osoby, które mają ten mail u siebie (oryginał i przejęte kopie)
                'members' => [(int) $row->user_id => true],
            ];
        }

        // kopie: wszystkie (mało wierszy), korzeń przez łańcuch duplicate_of_id; kopia liczy się do grupy oryginału
        $parent = [];
        $copies = [];
        foreach (DB::table('client_inquiries')
            ->whereNotNull('duplicate_of_id')
            ->select(['id', 'user_id', 'duplicate_of_id', 'replied_at', 'send_requested_at', 'outcome', 'outcome_document_number', 'outcome_net_value'])
            ->lazyById(1000) as $row) {
            $parent[(int) $row->id] = (int) $row->duplicate_of_id;
            $copies[] = $row;
        }
        foreach ($copies as $row) {
            $root = $this->root((int) $row->id, $parent);
            if ($root === null || ! isset($groups[$root])) {
                continue;
            }
            $groups[$root]['copies']++;
            $groups[$root]['members'][(int) $row->user_id] = true;
            $groups[$root]['rows'][] = $this->funnelRow($row);
            $replied = $this->parse($row->replied_at);
            if ($replied !== null && ($groups[$root]['replied'] === null || $replied->lt($groups[$root]['replied']))) {
                $groups[$root]['replied'] = $replied;
            }
            $groups[$root]['queued'] = $groups[$root]['queued'] || $row->send_requested_at !== null;
        }

        if (! $all) {
            $groups = array_filter($groups, static fn (array $group): bool => isset($group['members'][(int) $user->id]));
        }

        $totals = ['received' => 0, 'replied' => 0, 'replied_1bd' => 0, 'waiting' => 0, 'waiting_over_1bd' => 0, 'in_thunderbird' => 0, 'analysis_failed' => 0, 'duplicates' => 0];
        $weekly = $this->emptyWeeks($from, $now, ['received' => 0, 'replied' => 0]);
        $channels = array_fill_keys(self::CHANNELS, 0);
        $people = [];
        $funnel = ['received' => 0, 'replied' => 0, 'ordered_confirmed' => 0, 'possible' => 0, 'value_confirmed' => 0.0, 'ordered_without_document' => 0];
        // zapytania z podpowiedzią z ERP XL (wniosek) policzoną dla obecnego klienta zapytania
        $hinted = array_flip(InquiryOrderHint::query()->ofCurrentClient()->distinct()->pluck('client_inquiry_id')->map(static fn ($id): int => (int) $id)->all());

        foreach ($groups as $group) {
            $start = $group['start'] ?? $now;
            $deadline = self::oneBusinessDayAfter($start);
            $replied = $group['replied'];
            $inTime = $replied !== null && $replied->lte($deadline);

            $totals['received']++;
            // „own”: ten sam mail u innych osób (bez mojej własnej kopii); „all”: wszystkie kopie
            $totals['duplicates'] += $all ? $group['copies'] : count($group['members']) - 1;
            if ($replied !== null) {
                $totals['replied']++;
                $totals['replied_1bd'] += $inTime ? 1 : 0;
            } else {
                $totals['waiting']++;
                $totals['waiting_over_1bd'] += $now->gt($deadline) ? 1 : 0;
                $totals['in_thunderbird'] += $group['queued'] ? 1 : 0;
            }
            $totals['analysis_failed'] += $group['failed'] ? 1 : 0;

            $week = $start->setTimezone(self::TIMEZONE)->startOfWeek(CarbonInterface::MONDAY)->toDateString();
            if (isset($weekly[$week])) {
                $weekly[$week]['received']++;
                $weekly[$week]['replied'] += $replied !== null ? 1 : 0;
            }
            $channels[$group['channel']] = ($channels[$group['channel']] ?? 0) + 1;

            // od zapytania do sprzedaży: grupa raz; „zamówił” potwierdza handlowiec (którykolwiek wiersz grupy),
            // „możliwe” to sama podpowiedź z ERP XL przy grupie bez żadnego wpisanego wyniku — osobno
            $funnel['received']++;
            $funnel['replied'] += $replied !== null ? 1 : 0;
            $confirmed = false;
            $anyOutcome = false;
            $hint = false;
            $documents = [];
            foreach ($group['rows'] as $row) {
                $anyOutcome = $anyOutcome || $row['outcome'] !== null;
                $hint = $hint || isset($hinted[$row['id']]);
                if ($row['ordered']) {
                    $confirmed = true;
                    if ($row['document'] !== null) {
                        // ten sam dokument potwierdzony w dwóch kopiach maila liczy się raz
                        $documents[$row['document']] = $row['net'];
                    }
                }
            }
            if ($confirmed) {
                $funnel['ordered_confirmed']++;
                $funnel['value_confirmed'] += array_sum($documents);
                $funnel['ordered_without_document'] += $documents === [] ? 1 : 0;
            } elseif (! $anyOutcome && $hint && $replied !== null) {
                $funnel['possible']++;
            }

            // mail przejęty przez kilka osób liczy się każdej z nich (suma wierszy może przekroczyć liczbę zapytań)
            foreach (array_keys($group['members']) as $memberId) {
                $person = &$people[$memberId];
                $person ??= ['received' => 0, 'replied' => 0, 'replied_1bd' => 0, 'waiting' => 0, 'reply_seconds' => [], 'own_replied' => 0, 'ordered' => 0, 'order_value' => 0.0];
                $person['received']++;
                $person['replied'] += $replied !== null ? 1 : 0;
                $person['replied_1bd'] += $inTime ? 1 : 0;
                $person['waiting'] += $replied === null ? 1 : 0;
                // własna odpowiedź i własny wynik tej osoby (kopia u kolegi to jego praca)
                $own = array_values(array_filter($group['rows'], static fn (array $r): bool => $r['user_id'] === $memberId));
                $ownReplied = null;
                $ownDocuments = [];
                $ownOrdered = false;
                foreach ($own as $row) {
                    if ($row['replied'] !== null && ($ownReplied === null || $row['replied']->lt($ownReplied))) {
                        $ownReplied = $row['replied'];
                    }
                    if ($row['ordered']) {
                        $ownOrdered = true;
                        if ($row['document'] !== null) {
                            $ownDocuments[$row['document']] = $row['net'];
                        }
                    }
                }
                if ($ownReplied !== null && $ownReplied->gte($start)) {
                    $person['reply_seconds'][] = (int) $start->diffInSeconds($ownReplied, true);
                }
                // mianownik „zamówione z odpowiedzianych”: tylko grupy, w których ta osoba sama odpowiedziała
                $person['own_replied'] += $ownReplied !== null ? 1 : 0;
                $person['ordered'] += $ownOrdered ? 1 : 0;
                $person['order_value'] += array_sum($ownDocuments);
                unset($person);
            }
        }

        return [
            'scope' => $all ? 'all' : 'own',
            'totals' => $totals,
            'weekly' => array_values($weekly),
            'channels' => array_map(static fn (string $channel, int $received): array => ['channel' => $channel, 'received' => $received], array_keys($channels), array_values($channels)),
            'funnel' => [...$funnel, 'value_confirmed' => number_format($funnel['value_confirmed'], 2, '.', '')],
            'people' => $all ? $this->people($people) : null,
        ];
    }

    /**
     * Wiersz zapytania do lejka: osoba, jej odpowiedź i wynik (ordered = zamówił albo zamówił część, potwierdzone
     * przez handlowca), dokument z ERP XL skopiowany do wyniku.
     *
     * @return array{id: int, user_id: int, replied: CarbonImmutable|null, outcome: string|null, ordered: bool, document: string|null, net: float}
     */
    private function funnelRow(object $row): array
    {
        $outcome = is_string($row->outcome ?? null) && $row->outcome !== '' ? $row->outcome : null;
        $document = is_string($row->outcome_document_number ?? null) && trim($row->outcome_document_number) !== '' ? trim($row->outcome_document_number) : null;

        return [
            'id' => (int) $row->id,
            'user_id' => (int) $row->user_id,
            'replied' => $this->parse($row->replied_at),
            'outcome' => $outcome,
            'ordered' => in_array($outcome, self::ORDERED_OUTCOMES, true),
            'document' => $document,
            'net' => $document !== null ? (float) ($row->outcome_net_value ?? 0) : 0.0,
        ];
    }

    /**
     * Mediana (sekundy) — środkowa wartość, przy parzystej liczbie średnia dwóch środkowych; null bez danych.
     *
     * @param  list<int>  $values
     */
    private static function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $values[$middle] : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }

    /**
     * Korzeń grupy: idzie po duplicate_of_id, aż trafi na wiersz, który nie jest kopią (z ochroną przed pętlą).
     *
     * @param  array<int, int>  $parent
     */
    private function root(int $id, array $parent): ?int
    {
        $seen = [];
        while (isset($parent[$id])) {
            if (isset($seen[$id])) {
                return null;
            }
            $seen[$id] = true;
            $id = $parent[$id];
        }

        return $id;
    }

    /**
     * @param  array<int, array{received: int, replied: int, replied_1bd: int, waiting: int, reply_seconds: list<int>, own_replied: int, ordered: int, order_value: float}>  $people
     * @return list<array<string, mixed>>
     */
    private function people(array $people): array
    {
        $names = $people === [] ? [] : DB::table('users')->whereIn('id', array_keys($people))->pluck('name', 'id')->all();
        $rows = [];
        foreach ($people as $userId => $counts) {
            $rows[] = [
                'user_id' => (int) $userId,
                'name' => (string) ($names[$userId] ?? 'Użytkownik #'.$userId),
                'received' => $counts['received'],
                'replied' => $counts['replied'],
                'replied_1bd' => $counts['replied_1bd'],
                'waiting' => $counts['waiting'],
                // zwykle odpowiada w: mediana od przyjścia maila do własnej odpowiedzi tej osoby
                'median_reply_seconds' => self::median($counts['reply_seconds']),
                // zamówione z odpowiedzianych — wynik potwierdzony przez tę osobę (zamówił albo zamówił część) wśród
                // zapytań, na które sama odpowiedziała (odpowiedź kolegi na kopię maila to nie jej oferta)
                'ordered' => $counts['ordered'],
                'ordered_percent' => $counts['own_replied'] > 0 ? round($counts['ordered'] * 100 / $counts['own_replied'], 1) : null,
                'order_value' => number_format($counts['order_value'], 2, '.', ''),
            ];
        }
        usort($rows, static fn (array $a, array $b): int => [$b['received'], $a['name']] <=> [$a['received'], $b['name']]);

        return $rows;
    }

    /** @return array<string, mixed> */
    private function tenders(User $user, CarbonImmutable $now): array
    {
        $all = $user->can('tenders.view_all');
        // bez prices.supplier_special.view marża bliźniacza (jak ReportController::marginColumn); stała nazwa kolumny
        $margin = SupplierSpecialMask::forUser($user)->hides() ? 'margin_percent_standard' : 'margin_percent';
        $scoped = static fn (): Builder => $all ? Tender::query() : Tender::query()->accessibleBy($user);
        // średnia marża ważona wartością oferty: tylko przetargi z marżą i wartością
        $weighted = static fn (string $prefix): string => 'SUM(CASE WHEN '.$prefix.$margin.' IS NOT NULL AND '.$prefix.'offer_value_net IS NOT NULL'
            .' THEN '.$prefix.$margin.' * '.$prefix.'offer_value_net ELSE 0 END) AS margin_weighted,'
            .' SUM(CASE WHEN '.$prefix.$margin.' IS NOT NULL AND '.$prefix.'offer_value_net IS NOT NULL'
            .' THEN '.$prefix.'offer_value_net ELSE 0 END) AS margin_base';

        $byStatus = $scoped()->toBase()
            ->select('tenders.status')
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(tenders.offer_value_net), 0) AS value_net, '.$weighted('tenders.'))
            ->groupBy('tenders.status')
            ->get()
            ->map(fn ($r): array => [
                'status' => (string) $r->status,
                'count' => (int) $r->cnt,
                'offer_value_net' => round((float) $r->value_net, 2),
                'avg_margin' => $this->weightedMargin($r->margin_weighted, $r->margin_base),
            ])
            ->sortBy(static function (array $r): string {
                $index = array_search($r['status'], self::TENDER_STATUS_ORDER, true);

                return ($index === false ? '1' : '0').str_pad((string) ($index === false ? 0 : $index), 3, '0', STR_PAD_LEFT).$r['status'];
            })
            ->values()
            ->all();

        $byOwner = $scoped()->toBase()
            ->leftJoin('users', 'users.id', '=', 'tenders.owner_id')
            ->select(['tenders.owner_id', 'users.name'])
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(tenders.offer_value_net), 0) AS value_net, '.$weighted('tenders.'))
            ->groupBy('tenders.owner_id', 'users.name')
            ->get()
            ->map(fn ($r): array => [
                'owner_id' => $r->owner_id !== null ? (int) $r->owner_id : null,
                'owner_name' => $r->owner_id !== null && $r->name !== null ? (string) $r->name : self::NO_OWNER,
                'count' => (int) $r->cnt,
                'offer_value_net' => round((float) $r->value_net, 2),
                'avg_margin' => $this->weightedMargin($r->margin_weighted, $r->margin_base),
            ])
            ->sort(static fn (array $a, array $b): int => [$b['offer_value_net'], $b['count'], $a['owner_name']] <=> [$a['offer_value_net'], $a['count'], $b['owner_name']])
            ->values()
            ->all();

        // bez JOIN-ów: accessibleBy filtruje po gołym owner_id, a clients też ma kolumnę owner_id
        $today = $now->startOfDay();
        $rows = $scoped()->toBase()
            ->whereNotIn('tenders.status', self::TENDER_CLOSED)
            ->whereNotNull('tenders.deadline')
            ->whereDate('tenders.deadline', '>=', $today->toDateString())
            ->whereDate('tenders.deadline', '<=', $today->addDays(self::UPCOMING_DAYS)->toDateString())
            ->orderBy('tenders.deadline')
            ->orderBy('tenders.id')
            ->get(['tenders.id', 'tenders.number', 'tenders.title', 'tenders.client_id', 'tenders.owner_id', 'tenders.deadline', 'tenders.status', 'tenders.offer_value_net']);
        $clientIds = $rows->pluck('client_id')->filter()->unique()->values()->all();
        $clients = $clientIds === [] ? [] : DB::table('clients')->whereIn('id', $clientIds)->pluck('name', 'id')->all();
        $ownerIds = $rows->pluck('owner_id')->filter()->unique()->values()->all();
        $owners = $ownerIds === [] ? [] : DB::table('users')->whereIn('id', $ownerIds)->pluck('name', 'id')->all();
        // deadline to DATE — dni do terminu liczone na samych datach (bez strefy i zmiany czasu)
        $todayDate = CarbonImmutable::parse($today->toDateString(), 'UTC');
        $upcoming = $rows->map(static function ($r) use ($todayDate, $clients, $owners): array {
            $deadline = CarbonImmutable::parse(substr((string) $r->deadline, 0, 10), 'UTC');

            return [
                'id' => (int) $r->id,
                'number' => (string) $r->number,
                'title' => (string) $r->title,
                'client' => $r->client_id !== null && isset($clients[$r->client_id]) ? (string) $clients[$r->client_id] : null,
                'deadline' => $deadline->toDateString(),
                'days_left' => (int) round($todayDate->diffInDays($deadline, false)),
                'status' => (string) $r->status,
                'owner_name' => $r->owner_id !== null && isset($owners[$r->owner_id]) ? (string) $owners[$r->owner_id] : null,
                // brak wyceny = null, nie 0 zł
                'offer_value_net' => $r->offer_value_net !== null ? round((float) $r->offer_value_net, 2) : null,
            ];
        })->values()->all();

        return [
            'scope' => $all ? 'all' : 'own',
            'by_status' => $byStatus,
            'by_owner' => $byOwner,
            'upcoming' => $upcoming,
        ];
    }

    private function weightedMargin(mixed $weighted, mixed $base): ?float
    {
        $base = (float) $base;

        return $base > 0 ? round((float) $weighted / $base, 1) : null;
    }

    /** @return array<string, mixed> */
    private function campaigns(User $user, CarbonImmutable $from): array
    {
        $all = $user->canAny(['campaigns.manage', 'campaigns.view']);

        $campaigns = Campaign::query()
            ->when(! $all, static fn (Builder $q) => $q->where('campaigns.user_id', $user->id))
            ->whereIn('campaigns.status', self::CAMPAIGN_STARTED)
            ->whereNotNull('campaigns.sending_started_at')
            ->where('campaigns.sending_started_at', '>=', $this->dbTime($from))
            ->select(['campaigns.id', 'campaigns.code', 'campaigns.name', 'campaigns.subject', 'campaigns.status', 'campaigns.sending_started_at'])
            ->withCount([
                'recipients as sent_count' => static fn (Builder $q) => $q->where('status', CampaignRecipient::STATUS_SENT),
                // kliknięcia bez skanerów poczty (jak lista kampanii)
                'recipients as clicked_count' => static fn (Builder $q) => $q->where('clicks', '>', 0),
                // odbiorcy z odpowiedzią (CampaignReplySync ustawia replied_at dopasowanemu odbiorcy)
                'recipients as replied_count' => static fn (Builder $q) => $q->whereNotNull('replied_at'),
                'recipients as unsubscribed_count' => static fn (Builder $q) => $q->whereNotNull('unsubscribed_at'),
            ])
            ->orderByDesc('campaigns.sending_started_at')
            ->orderByDesc('campaigns.id')
            ->get();

        $sales = $campaigns->isEmpty() ? [] : $this->campaignSales->summaries($campaigns->map(static fn (Campaign $c): int => (int) $c->id)->values()->all());

        $rows = [];
        $totals = ['campaigns' => 0, 'sent' => 0, 'clicked' => 0, 'replies' => 0, 'unsubscribed' => 0];
        foreach ($campaigns as $c) {
            $summary = $sales[(int) $c->id] ?? null;
            $row = [
                'id' => (int) $c->id,
                'code' => (string) $c->code,
                'name' => trim((string) $c->name) !== '' ? (string) $c->name : (string) $c->subject,
                'status' => (string) $c->status,
                'started_at' => $c->sending_started_at?->toIso8601String(),
                'sent' => (int) $c->getAttribute('sent_count'),
                'clicked' => (int) $c->getAttribute('clicked_count'),
                'replies' => (int) $c->getAttribute('replied_count'),
                'unsubscribed' => (int) $c->getAttribute('unsubscribed_count'),
                'buyers' => $summary !== null ? (int) $summary['customers'] : null,
                'sales_net' => $summary !== null ? round((float) $summary['net_value'], 2) : null,
                // false — okres liczenia sprzedaży (30 dni od wysyłki) jeszcze trwa; null — brak wyniku sprzedaży
                'sales_complete' => $summary !== null ? (bool) $summary['complete'] : null,
            ];
            $rows[] = $row;
            $totals['campaigns']++;
            foreach (['sent', 'clicked', 'replies', 'unsubscribed'] as $key) {
                $totals[$key] += $row[$key];
            }
        }

        return [
            'scope' => $all ? 'all' : 'own',
            'rows' => $rows,
            'totals' => $totals,
        ];
    }

    /**
     * Pełna seria tygodni (od poniedziałku tygodnia `from` do bieżącego), klucz = week_start.
     *
     * @param  array<string, int>  $zero
     * @return array<string, array<string, mixed>>
     */
    private function emptyWeeks(CarbonImmutable $from, CarbonImmutable $now, array $zero): array
    {
        $weeks = [];
        $last = $now->startOfWeek(CarbonInterface::MONDAY);
        for ($week = $from->startOfWeek(CarbonInterface::MONDAY); $week->lte($last); $week = $week->addWeek()) {
            $weeks[$week->toDateString()] = ['week_start' => $week->toDateString(), ...$zero];
        }

        return $weeks;
    }

    /** Granica okresu (północ w Polsce) w strefie zapisu bazy. */
    private function dbTime(CarbonImmutable $moment): string
    {
        return $moment->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
    }

    private function parse(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value, (string) config('app.timezone', 'UTC'));
    }
}
