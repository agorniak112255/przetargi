<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignRecipientCustomer;
use App\Models\User;
use App\Services\Campaigns\CampaignAttribution;
use App\Services\Erp\ErpCampaignSalesSync;
use App\Support\PolishTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Raport „Wynik kampanii” (Raporty → zakładka; podstawa premii): sprzedaż odbiorcom kampanii, uwolnione pieniądze
 * z zalegającego towaru, odzysk i marża — w miesiącu według daty dokumentu. Przypisanie pozycji faktur kampaniom liczy
 * CampaignAttribution (globalnie, „ostatni mail wygrywa”), tu dopiero filtr zakresu patrzącego (CampaignReportScope)
 * i sumy. Kwoty w groszach (int) do końca, na wyjściu zł z dwoma miejscami; brak danych = null, nigdy 0.
 * Kształt odpowiedzi: CampaignsReport w frontend/src/lib/reports.ts (kontrakt zamrożony 04.10.2026).
 */
final class CampaignsReport
{
    /** Okno raportu: bieżący miesiąc i 11 poprzednich. */
    public const WINDOW_MONTHS = 12;

    /** Liczby miesiąca ostateczne po tylu dniach nocnych odczytów od jego końca (dokumenty z datą wsteczną). */
    public const FINAL_AFTER_DAYS = 7;

    private const TOP_ITEMS = 10;

    public const RULE = 'Liczymy pozycje faktur i paragonów z ERP XL (nocny odczyt) z towarami kampanii, wystawione klientom, '
        .'do których poszedł mail tej kampanii — od dnia maila do 30. dnia po starcie wysyłki, daty w czasie polskim. '
        .'Odbiorcę łączymy z klientem tak, jak zapisano przy wysyłce: klient wybrany z ERP XL albo adres e-mail z karty '
        .'kontrahenta (adres na kilku kartach — liczą się zakupy każdej z nich, raport to oznacza). Gdy klient dostał kilka '
        .'kampanii z tym samym towarem, pozycja należy tylko do tej, której mail dostał najpóźniej przed zakupem; pozostałe '
        .'widzą ją jako przejętą. Korekta liczy się w miesiącu swojej daty, przy kampanii korygowanej faktury; korekty '
        .'wystawione ponad 7 dni po końcu okna kampanii mogą nie być widoczne (nocny odczyt pobiera tylko towary kampanii '
        .'z ostatnich 37 dni). Uwolnione '
        .'pieniądze to koszt zakupu sprzedanego towaru, który w dniu wysyłki leżał bez sprzedaży co najmniej 180 dni (albo '
        .'nigdy nie był sprzedany, a najstarsza partia miała co najmniej 180 dni), najwyżej do stanu z dnia wysyłki — ten '
        .'stan zmniejsza każda sprzedaż towaru po starcie (także innym klientom i z innych kampanii), więc dwie kampanie nie '
        .'dostaną uwolnionych pieniędzy za ten sam zapas. Koszt bierzemy z ERP XL, a gdy go tam nie ma, szacujemy: ilość razy koszt '
        .'jednostki z dnia wysyłki; bez jednego i drugiego koszt i marża są nieznane. Odzysk: ile klienci zapłacili za '
        .'każde 100 zł kosztu uwolnionego towaru. Zakup po mailu nie dowodzi, że klient kupił dzięki kampanii.';

    private const CSV_HEADERS = [
        'Data', 'Dokument', 'Rodzaj', 'Klient', 'Kod towaru', 'Towar', 'Ilość', 'Jednostka', 'Netto', 'Koszt',
        'Źródło kosztu', 'Kampania', 'Autor', 'Mail do klienta (data)', 'Dopasowanie klienta', 'Towar zalegający',
        'Dni bez sprzedaży przed startem kampanii', 'Część w limicie stanu (%)', 'Uwolnione', 'Marża',
    ];

    public function __construct(private readonly CampaignAttribution $attribution) {}

    public static function currentMonth(): string
    {
        return PolishTime::today()->format('Y-m');
    }

    /**
     * Bieżący miesiąc i 11 poprzednich, od najnowszego (etykiety jak w „Celach handlowców”, bez miesiąca przyszłego).
     *
     * @return list<array{key: string, label: string}>
     */
    public static function months(): array
    {
        return array_values(array_slice(SalesTargetsReport::months(), SalesTargetsReport::FUTURE_MONTHS, self::WINDOW_MONTHS));
    }

    public static function inWindow(string $month): bool
    {
        return in_array($month, array_column(self::months(), 'key'), true);
    }

    /**
     * @param  array{mode: string, user_ids: list<int>|null}  $scope  CampaignReportScope::for
     * @return array<string, mixed> CampaignsReport (lib/reports.ts)
     */
    public function build(string $month, array $scope, User $viewer, ?int $filterUserId): array
    {
        $data = $this->compute($month, $scope, $filterUserId);
        $a = $data['attribution'];
        $today = PolishTime::today()->toDateString();
        $monthStart = $a['month_start'];
        $monthEnd = $a['month_end'];
        $names = $this->userNames([
            ...array_column($a['campaigns'], 'user_id'),
            ...array_keys($data['mails']),
            ...($scope['user_ids'] ?? []),
            (int) $viewer->id,
        ]);

        $inMonth = static fn (array $l): bool => $l['sold_at'] >= $monthStart && $l['sold_at'] <= $monthEnd;
        $itemKeysByCampaign = [];
        foreach (array_keys($a['items']) as $key) {
            $itemKeysByCampaign[(int) explode(':', $key)[0]][] = $key;
        }

        // pozycje kampanii w zakresie: miesiąc i całe okno, także per towar
        $linesByCampaign = [];
        foreach ($a['lines'] as $l) {
            if (isset($data['scoped'][$l['campaign_id']])) {
                $linesByCampaign[$l['campaign_id']][] = $l;
            }
        }

        $totals = self::emptyAcc();
        $people = [];
        $warnings = ['cost_estimated_lines' => 0, 'multi_card_lines' => 0, 'unlinked_corrections' => 0, 'over_stock_lines' => 0, 'no_snapshot_items' => 0, 'freed_unknown_lines' => 0];
        $campaigns = [];
        $topItems = [];
        $idle = ['sum' => 0, 'weight' => 0];
        $personIdle = [];

        foreach ($data['scoped'] as $campaignId => $c) {
            $lines = $linesByCampaign[$campaignId] ?? [];
            $active = $c['start'] <= $monthEnd && $c['end'] >= $monthStart;
            $hasMonthLine = false;
            foreach ($lines as $l) {
                if ($inMonth($l)) {
                    $hasMonthLine = true;
                    break;
                }
            }
            // na liście: okno nachodzi na miesiąc albo w miesiącu jest pozycja (np. korekta starszej sprzedaży)
            if (! $active && ! $hasMonthLine) {
                continue;
            }
            $userId = $c['user_id'];
            $people[$userId] ??= ['acc' => self::emptyAcc(), 'campaigns' => 0];
            if ($active) {
                $people[$userId]['campaigns']++;
            }

            // suma kampanii w miesiącu (nie nadpisywać $month — to RRRR-MM z żądania)
            $monthAcc = self::emptyAcc();
            $window = self::emptyAcc();
            $perItem = [];
            foreach ($lines as $l) {
                $key = $campaignId.':'.$l['erp_item_id'];
                $perItem[$key] ??= ['month' => self::emptyAcc(), 'window' => self::emptyAcc(), 'month_qty' => 0.0, 'qty' => 0.0,
                    'sale_qty' => 0.0, 'sale_net' => 0, 'first' => null, 'over' => 0.0, 'estimated' => false];
                $p = &$perItem[$key];
                self::add($window, $l);
                self::add($p['window'], $l);
                $p['qty'] += $l['quantity'];
                $p['over'] += $l['over_quantity'];
                $p['estimated'] = $p['estimated'] || $l['cost_source'] === CampaignAttribution::COST_ESTIMATE;
                if (! $l['is_correction']) {
                    $p['sale_qty'] += $l['quantity'];
                    $p['sale_net'] += $l['net'];
                    $p['first'] = $p['first'] === null ? $l['sold_at'] : min($p['first'], $l['sold_at']);
                }
                if ($inMonth($l)) {
                    self::add($monthAcc, $l);
                    self::add($p['month'], $l);
                    self::add($totals, $l);
                    self::add($people[$userId]['acc'], $l);
                    $p['month_qty'] += $l['quantity'];
                    if ($l['cost_source'] === CampaignAttribution::COST_ESTIMATE) {
                        $warnings['cost_estimated_lines']++;
                    }
                    if ($l['matched_by'] === CampaignRecipientCustomer::MATCHED_EMAIL && $l['cards_count'] > 1) {
                        $warnings['multi_card_lines']++;
                    }
                    if (! $l['is_correction'] && $l['over_quantity'] > 0) {
                        $warnings['over_stock_lines']++;
                    }
                    if ($l['freed_unknown']) {
                        $warnings['freed_unknown_lines']++;
                    }
                }
                unset($p);
            }

            $items = [];
            $offered = null;
            // licznik tylko z towarów policzonych w „zalegającym w ofercie” (bez towarów spoza mianownika); koszt z ERP XL może
            // różnić się od kosztu jednostki z dnia wysyłki, więc wynik nie jest twardo ograniczony do 100%
            $offeredFreed = 0;
            foreach ($itemKeysByCampaign[$campaignId] ?? [] as $key) {
                $item = $a['items'][$key];
                $p = $perItem[$key] ?? ['month' => self::emptyAcc(), 'window' => self::emptyAcc(), 'month_qty' => 0.0, 'qty' => 0.0,
                    'sale_qty' => 0.0, 'sale_net' => 0, 'first' => null, 'over' => 0.0, 'estimated' => false];
                $offeredValue = $item['stock_at_send'] !== null && $item['unit_cost'] !== null
                    ? (int) round($item['stock_at_send'] * $item['unit_cost'] * 100) : null;
                if ($item['stagnant'] === true && $offeredValue !== null) {
                    $offered = ($offered ?? 0) + $offeredValue;
                    $offeredFreed += $p['window']['freed'];
                }
                if ($item['snap_source'] === null) {
                    $warnings['no_snapshot_items']++;
                }
                $monthFreed = $p['month']['freed'];
                if ($item['idle_days'] !== null && $monthFreed > 0) {
                    $idle['sum'] += $item['idle_days'] * $monthFreed;
                    $idle['weight'] += $monthFreed;
                    $personIdle[$userId]['sum'] = ($personIdle[$userId]['sum'] ?? 0) + $item['idle_days'] * $monthFreed;
                    $personIdle[$userId]['weight'] = ($personIdle[$userId]['weight'] ?? 0) + $monthFreed;
                }
                if ($monthFreed > 0) {
                    $topItems[] = [
                        'campaign_id' => $campaignId,
                        'campaign_code' => $c['code'],
                        'user_name' => $names[$userId] ?? '',
                        'erp_item_id' => $item['erp_item_id'],
                        'code' => $item['code'],
                        'name' => $item['name'],
                        'idle_days' => $item['idle_days'],
                        'never_sold' => $item['never_sold'],
                        'lot_age_days' => $item['lot_age_days'],
                        'quantity' => round($p['month_qty'], 3),
                        'unit' => $item['unit'],
                        'sales_net' => self::money($p['month']['net']),
                        'freed_capital' => self::money($monthFreed),
                    ];
                }
                $items[] = [
                    'erp_item_id' => $item['erp_item_id'],
                    'code' => $item['code'],
                    'name' => $item['name'],
                    'unit' => $item['unit'],
                    'stagnant' => $item['stagnant'],
                    'idle_days' => $item['idle_days'],
                    'never_sold' => $item['never_sold'],
                    'lot_age_days' => $item['lot_age_days'],
                    'snap_source' => $item['snap_source'],
                    'stock_at_send' => $item['stock_at_send'],
                    'unit_cost' => $item['unit_cost'],
                    'offered_value' => $offeredValue === null ? null : self::money($offeredValue),
                    'promo_price' => $item['promo_price'] !== null ? round((float) $item['promo_price'], 2) : null,
                    'month' => [
                        'quantity' => round($p['month_qty'], 3),
                        'sales_net' => self::money($p['month']['net']),
                        'freed_capital' => self::money($monthFreed),
                        'margin' => $p['month']['margin_known'] ? self::money($p['month']['margin']) : null,
                    ],
                    'window' => [
                        'quantity' => round($p['qty'], 3),
                        'sales_net' => self::money($p['window']['net']),
                        'freed_capital' => self::money($p['window']['freed']),
                        'avg_price' => $p['sale_qty'] > 0 ? round($p['sale_net'] / 100 / $p['sale_qty'], 2) : null,
                        'buyers' => count($p['window']['buyers']),
                        'first_sale_days' => $p['first'] !== null ? CampaignAttribution::daysBetween($c['start'], $p['first']) : null,
                        'over_stock_quantity' => round($p['over'], 3),
                    ],
                    'cost_estimated' => $p['estimated'],
                ];
            }

            $taken = ['lines' => 0, 'net' => 0, 'by' => []];
            foreach ($data['taken_over'][$campaignId] ?? [] as $t) {
                $taken['lines']++;
                $taken['net'] += $t['net'];
                $winner = $a['campaigns'][$t['by']] ?? null;
                $taken['by'][$t['by']] ??= [
                    'campaign_id' => $t['by'],
                    'code' => (string) ($winner['code'] ?? ''),
                    'user_name' => $winner !== null ? ($names[$winner['user_id']] ?? '') : '',
                ];
            }
            ksort($taken['by']);

            $campaigns[] = [
                'id' => $campaignId,
                'code' => $c['code'],
                'name' => $c['name'],
                'user_id' => $userId,
                'user_name' => $names[$userId] ?? '',
                'status' => $c['status'],
                'started_at' => $c['sending_started_at'],
                'window_end' => $c['end'],
                'window_open' => $c['end'] >= $today,
                'recipients_sent' => $c['sent'],
                'month' => self::figures($monthAcc),
                'window' => [
                    ...self::figures($window),
                    'offered_stock_value' => $offered === null ? null : self::money($offered),
                    'effectiveness_percent' => $offered !== null && $offered > 0 ? round($offeredFreed / $offered * 100, 1) : null,
                ],
                'taken_over' => ['lines' => $taken['lines'], 'sales_net' => self::money($taken['net']), 'by' => array_values($taken['by'])],
                'items' => $items,
            ];
        }
        usort($campaigns, static fn (array $x, array $y): int => [$y['started_at'], $y['id']] <=> [$x['started_at'], $x['id']]);

        foreach ($a['unlinked'] as $u) {
            foreach ($u['campaign_ids'] as $campaignId) {
                if (isset($data['scoped'][$campaignId])) {
                    $warnings['unlinked_corrections']++;
                    break;
                }
            }
        }

        // osoby: z kampanią na liście albo z mailami wysłanymi w miesiącu
        foreach (array_keys($data['mails']) as $userId) {
            $people[$userId] ??= ['acc' => self::emptyAcc(), 'campaigns' => 0];
        }
        $peopleOut = [];
        $mailsTotal = 0;
        foreach ($people as $userId => $p) {
            $mails = $data['mails'][$userId] ?? 0;
            $mailsTotal += $mails;
            $w = $personIdle[$userId] ?? ['sum' => 0, 'weight' => 0];
            $peopleOut[] = [
                ...self::figures($p['acc']),
                'user_id' => $userId,
                'name' => $names[$userId] ?? '',
                'campaigns' => $p['campaigns'],
                'recipients_sent' => $mails,
                'avg_idle_days' => $w['weight'] > 0 ? (int) round($w['sum'] / $w['weight']) : null,
                '_freed' => $p['acc']['freed'],
            ];
        }
        usort($peopleOut, static fn (array $x, array $y): int => [$y['_freed'], $x['name'], $x['user_id']] <=> [$x['_freed'], $y['name'], $y['user_id']]);
        $peopleOut = array_map(static function (array $p): array {
            unset($p['_freed']);

            return $p;
        }, $peopleOut);

        usort($topItems, static fn (array $x, array $y): int => [$y['freed_capital'], $y['sales_net'], $x['campaign_id'], $x['erp_item_id']]
            <=> [$x['freed_capital'], $x['sales_net'], $y['campaign_id'], $y['erp_item_id']]);

        $activeCount = 0;
        foreach ($people as $p) {
            $activeCount += $p['campaigns'];
        }

        return [
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'month' => $month,
            'months' => self::months(),
            'scope' => $scope['mode'],
            'people_options' => $this->peopleOptions($scope, $viewer),
            'user_id' => $data['user_id'],
            'closed' => $month < self::currentMonth(),
            'final_after' => CampaignAttribution::addDays($monthEnd, self::FINAL_AFTER_DAYS),
            'data_until' => self::dataUntil(),
            'stagnant_days' => CampaignAttribution::STAGNANT_DAYS,
            'rule' => self::RULE,
            'totals' => [
                ...self::figures($totals),
                'campaigns' => $activeCount,
                'recipients_sent' => $mailsTotal,
                'avg_idle_days' => $idle['weight'] > 0 ? (int) round($idle['sum'] / $idle['weight']) : null,
                'corrections_net' => self::money($totals['corrections_net']),
            ],
            'people' => $peopleOut,
            'campaigns' => $campaigns,
            'top_items' => array_slice($topItems, 0, self::TOP_ITEMS),
            'warnings' => $warnings,
        ];
    }

    /** @return list<string> */
    public static function csvHeaders(): array
    {
        return self::CSV_HEADERS;
    }

    /**
     * Pozycje faktur, paragonów i korekt z miesiąca przypisane kampaniom z zakresu — do sprawdzenia premii.
     * Liczby z przecinkiem dziesiętnym, bez separatora tysięcy.
     *
     * @param  array{mode: string, user_ids: list<int>|null}  $scope
     * @return iterable<list<string>>
     */
    public function csvRows(string $month, array $scope, ?int $userId): iterable
    {
        $data = $this->compute($month, $scope, $userId);
        $a = $data['attribution'];
        $lines = array_values(array_filter($a['lines'], static fn (array $l): bool => isset($data['scoped'][$l['campaign_id']])
            && $l['sold_at'] >= $a['month_start'] && $l['sold_at'] <= $a['month_end']));
        usort($lines, static fn (array $x, array $y): int => [$x['sold_at'], $x['document_type'], $x['document_id'], $x['line']]
            <=> [$y['sold_at'], $y['document_type'], $y['document_id'], $y['line']]);

        $customerIds = array_values(array_unique(array_filter(array_column($lines, 'customer_id'), static fn ($id): bool => $id !== null)));
        $customers = [];
        foreach (array_chunk($customerIds, 1000) as $chunk) {
            foreach (DB::table('erp_customers')->whereIntegerInRaw('id', $chunk)->get(['id', 'acronym', 'name']) as $row) {
                $customers[(int) $row->id] = trim((string) $row->acronym) !== '' ? (string) $row->acronym : (string) $row->name;
            }
        }
        $names = $this->userNames(array_column($a['campaigns'], 'user_id'));

        foreach ($lines as $l) {
            $campaign = $a['campaigns'][$l['campaign_id']];
            $item = $a['items'][$l['campaign_id'].':'.$l['erp_item_id']];
            yield [
                $l['sold_at'],
                self::text($l['document_number']),
                $l['is_correction'] ? 'korekta' : ($l['document_type'] === 2034 ? 'paragon' : 'faktura'),
                self::text($customers[$l['customer_id']] ?? 'kontrahent ERP XL nr '.$l['customer_xl_gid']),
                self::text($item['code']),
                self::text($item['name']),
                self::decimal($l['quantity'], 3, true),
                self::text((string) ($item['unit'] ?? '')),
                self::decimal($l['net'] / 100, 2),
                $l['cost'] === null ? '' : self::decimal($l['cost'] / 100, 2),
                match ($l['cost_source']) {
                    CampaignAttribution::COST_XL => 'ERP XL',
                    CampaignAttribution::COST_ESTIMATE => 'szacunek',
                    CampaignAttribution::COST_PRICE_ONLY => 'korekta ceny — koszt bez zmian',
                    default => 'brak',
                },
                self::text($campaign['code']),
                self::text($names[$campaign['user_id']] ?? ''),
                $l['mail_day'],
                $l['matched_by'] === CampaignRecipientCustomer::MATCHED_DIRECT
                    ? 'wybrany z ERP XL'
                    : 'adres e-mail na '.$l['cards_count'].' '.($l['cards_count'] === 1 ? 'karcie' : 'kartach'),
                match ($item['stagnant']) {
                    true => 'tak',
                    false => 'nie',
                    default => 'brak danych',
                },
                $item['idle_days'] === null ? '' : (string) $item['idle_days'],
                $l['fraction'] === null ? '' : self::decimal($l['fraction'] * 100, 2, true),
                self::decimal($l['freed'] / 100, 2),
                $l['cost'] === null ? '' : self::decimal(($l['net'] - $l['cost']) / 100, 2),
            ];
        }
    }

    /**
     * Przypisanie i filtr zakresu wspólne dla JSON i CSV.
     *
     * @param  array{mode: string, user_ids: list<int>|null}  $scope
     * @return array{attribution: array<string, mixed>, scoped: array<int, array<string, mixed>>, taken_over: array<int, list<array<string, mixed>>>, mails: array<int, int>, user_id: int|null}
     */
    private function compute(string $month, array $scope, ?int $userId): array
    {
        $a = $this->attribution->forMonth($month);
        // filtr osoby zawęża zakres (kontroler sprawdził, że osoba jest w zakresie)
        $allowed = $userId !== null ? [$userId => true] : ($scope['user_ids'] === null ? null : array_fill_keys($scope['user_ids'], true));
        $scoped = [];
        foreach ($a['campaigns'] as $id => $c) {
            if ($allowed === null || isset($allowed[$c['user_id']])) {
                $scoped[$id] = $c;
            }
        }
        $taken = [];
        foreach ($a['taken_over'] as $t) {
            if (isset($scoped[$t['campaign_id']])) {
                $taken[$t['campaign_id']][] = $t;
            }
        }

        return [
            'attribution' => $a,
            'scoped' => $scoped,
            'taken_over' => $taken,
            'mails' => $this->mailsInMonth($a['month_start'], $a['month_end'], $allowed),
            'user_id' => $userId,
        ];
    }

    /**
     * Maile kampanii wysłane w miesiącu (dzień polski) per autor kampanii.
     *
     * @param  array<int, true>|null  $allowed
     * @return array<int, int>
     */
    private function mailsInMonth(string $monthStart, string $monthEnd, ?array $allowed): array
    {
        // granice miesiąca w czasie polskim jako chwile UTC (sent_at w bazie w UTC)
        $from = CarbonImmutable::parse($monthStart, PolishTime::TIMEZONE)->startOfDay()->utc();
        $to = CarbonImmutable::parse(CampaignAttribution::addDays($monthEnd, 1), PolishTime::TIMEZONE)->startOfDay()->utc();
        $byCampaign = DB::table('campaign_recipients')
            ->where('status', CampaignRecipient::STATUS_SENT)
            ->where('sent_at', '>=', $from->format('Y-m-d H:i:s'))
            ->where('sent_at', '<', $to->format('Y-m-d H:i:s'))
            ->groupBy('campaign_id')
            ->selectRaw('campaign_id, COUNT(*) AS mails')
            ->pluck('mails', 'campaign_id')
            ->all();
        if ($byCampaign === []) {
            return [];
        }
        $authors = DB::table('campaigns')->whereIntegerInRaw('id', array_map('intval', array_keys($byCampaign)))->pluck('user_id', 'id')->all();
        $out = [];
        foreach ($byCampaign as $campaignId => $mails) {
            $userId = (int) ($authors[$campaignId] ?? 0);
            if ($userId === 0 || ($allowed !== null && ! isset($allowed[$userId]))) {
                continue;
            }
            $out[$userId] = ($out[$userId] ?? 0) + (int) $mails;
        }

        return $out;
    }

    /**
     * Osoby do filtra: przy own sam patrzący, przy team członkowie zespołów, przy all autorzy kampanii po starcie.
     *
     * @param  array{mode: string, user_ids: list<int>|null}  $scope
     * @return list<array{user_id: int, name: string}>
     */
    private function peopleOptions(array $scope, User $viewer): array
    {
        $ids = $scope['user_ids'] ?? Campaign::query()->whereNotNull('sending_started_at')->distinct()->pluck('user_id')
            ->map(static fn ($id): int => (int) $id)->all();
        if ($scope['user_ids'] === null && $ids === []) {
            $ids = [(int) $viewer->id];
        }

        return User::query()->whereIn('id', $ids)->orderBy('name')->orderBy('id')->get(['id', 'name'])
            ->map(static fn (User $u): array => ['user_id' => (int) $u->id, 'name' => (string) $u->name])
            ->values()->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    private function userNames(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return $ids === [] ? [] : User::query()->whereIn('id', $ids)->pluck('name', 'id')
            ->mapWithKeys(static fn ($name, $id): array => [(int) $id => (string) $name])->all();
    }

    /** Ostatni nocny odczyt sprzedaży kampanii z ERP XL (ISO) albo null. */
    private static function dataUntil(): ?string
    {
        $value = Cache::get(ErpCampaignSalesSync::SYNCED_AT_CACHE_KEY);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, mixed> */
    private static function emptyAcc(): array
    {
        return [
            'net' => 0, 'freed' => 0, 'freed_estimated' => 0, 'freed_sales' => 0,
            'margin' => 0, 'margin_net' => 0, 'margin_known' => false,
            'below_lines' => 0, 'below_loss' => 0, 'buyers' => [], 'no_cost' => 0, 'corrections_net' => 0, 'freed_unknown' => 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $acc
     * @param  array<string, mixed>  $l  pozycja z CampaignAttribution
     */
    private static function add(array &$acc, array $l): void
    {
        $acc['net'] += $l['net'];
        $acc['freed'] += $l['freed'];
        $acc['freed_estimated'] += $l['freed_estimated'];
        $acc['freed_sales'] += $l['freed_sales'];
        if ($l['freed_unknown']) {
            $acc['freed_unknown']++;
        }
        if ($l['cost'] !== null) {
            $acc['margin'] += $l['net'] - $l['cost'];
            $acc['margin_net'] += $l['net'];
            $acc['margin_known'] = true;
            if (! $l['is_correction'] && $l['net'] < $l['cost']) {
                $acc['below_lines']++;
                $acc['below_loss'] += $l['cost'] - $l['net'];
            }
        } else {
            $acc['no_cost']++;
        }
        if ($l['is_correction']) {
            $acc['corrections_net'] += $l['net'];
        } elseif ($l['customer_id'] !== null) {
            $acc['buyers'][$l['customer_id']] = true;
        }
    }

    /**
     * @param  array<string, mixed>  $acc
     * @return array<string, mixed> CampaignsReportFigures
     */
    private static function figures(array $acc): array
    {
        return [
            'sales_net' => self::money($acc['net']),
            'freed_capital' => self::money($acc['freed']),
            'freed_estimated' => self::money($acc['freed_estimated']),
            'freed_sales_net' => self::money($acc['freed_sales']),
            'recovery_percent' => $acc['freed'] > 0 ? round($acc['freed_sales'] / $acc['freed'] * 100, 1) : null,
            'margin' => $acc['margin_known'] ? self::money($acc['margin']) : null,
            'margin_percent' => $acc['margin_known'] && $acc['margin_net'] > 0 ? round($acc['margin'] / $acc['margin_net'] * 100, 1) : null,
            'below_cost_lines' => $acc['below_lines'],
            'below_cost_loss' => self::money($acc['below_loss']),
            'buyers' => count($acc['buyers']),
            'lines_without_cost' => $acc['no_cost'],
            'freed_unknown_lines' => $acc['freed_unknown'],
        ];
    }

    /** Grosze → zł z dwoma miejscami. */
    private static function money(int $cents): float
    {
        return round($cents / 100, 2);
    }

    /** Liczba z przecinkiem dziesiętnym, bez separatora tysięcy; $trim — bez zer na końcu części ułamkowej. */
    private static function decimal(float $value, int $digits, bool $trim = false): string
    {
        $s = number_format($value, $digits, ',', '');
        if ($trim && str_contains($s, ',')) {
            $s = rtrim(rtrim($s, '0'), ',');
        }

        return $s === '-0' ? '0' : $s;
    }

    /** Tekst z ERP XL do komórki CSV — bez interpretacji jako formuła arkusza (=, +, -, @ na początku). */
    private static function text(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
