<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Tender;
use App\Models\TenderLot;
use App\Models\User;
use App\Services\Bzp\OurCompany;
use App\Services\Tenders\TenderResultService;
use App\Services\Tenders\TenderResultStatus;
use App\Support\PolishTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Raport „Skuteczność przetargów”: części zamówień wygrane i przegrane, powody przegranych, konkurenci i rodzaje
 * towaru — z wyników części (tender_lots) przetargów, których termin składania ofert wypada w okresie.
 *
 * - Zakres przetargów jak w raporcie „Sprzedaż i oferty”: z tenders.view_all wszystkie, inaczej własne i te,
 *   do których użytkownik jest zaproszony.
 * - Okres po dacie terminu (dzień „na zegarze” w Polsce): ostatnie 90 dni z dzisiejszym albo bieżący rok do dziś.
 * - Liczy części: rozstrzygnięte = wygrane + przegrane; unieważnione i „nie złożyliśmy oferty” osobno.
 *   Część założona automatycznie przez Biuletyn, unieważniona, bez naszej ceny i bez ręcznego wyniku, w przetargu
 *   z kilkoma częściami nie jest liczona jako unieważniona — to zwykle część, w której nie składaliśmy oferty.
 *   Przetarg unieważniony w całości bez żadnej policzonej części liczy się raz (najniższa część).
 * - Przetargi bez wyniku: termin minął, wynik przetargu pusty, status inny niż szkic i odrzucony (jak
 *   przypomnienie „Wpisz wynik”).
 * - Różnica cen: nasza cena brutto (netto × VAT) do ceny zwycięzcy z ogłoszenia (przyjętej jako brutto), tylko w złotych
 *   (TenderResultService::priceGap — ta sama co w ekranie wyniku); dodatnia = byliśmy drożsi.
 * - Rodzaj towaru z głównego kodu CPV części (config bzp.cpv_categories); pozycje przetargu nie są przypisane
 *   do części, więc rodzaju nie da się wiarygodnie wyprowadzić z pozycji.
 * - Agregacja w PHP po płaskich zapytaniach (bez GROUP BY — produkcja ma MySQL z ONLY_FULL_GROUP_BY).
 */
final class TenderEffectivenessReport
{
    public const PERIODS = ['90d', 'year'];

    public const DEFAULT_PERIOD = '90d';

    public const LOSS_REASON_LABELS = [
        'price' => 'Cena',
        'requirement' => 'Nie spełniliśmy wymagania',
        'delivery' => 'Termin dostawy',
        'formal' => 'Błąd formalny',
        'other' => 'Inny',
    ];

    public const NO_REASON_LABEL = 'Nie wpisano powodu';

    public const OUTCOME_LABELS = [
        TenderLot::OUTCOME_WON => 'wygrana',
        TenderLot::OUTCOME_LOST => 'przegrana',
        TenderLot::OUTCOME_CANCELLED => 'unieważniona',
        TenderLot::OUTCOME_NOT_SUBMITTED => 'nie złożyliśmy oferty',
    ];

    public const OTHER_CATEGORY = ['key' => 'other', 'label' => 'Inny rodzaj'];

    /** najsłabszy rodzaj towaru tylko z co najmniej tylu rozstrzygniętych części */
    public const WEAKEST_MIN_DECIDED = 3;

    private const NO_RESULT_LIMIT = 50;

    private const CANCELLED_LIMIT = 100;

    private const COMPETITORS_LIMIT = 20;

    /** przetargi, przy których nie oczekujemy wyniku */
    private const NO_RESULT_SKIPPED_STATUSES = ['draft', 'odrzucony'];

    private const NO_OWNER = 'Nieprzypisany';

    private const CHUNK = 500;

    /**
     * @param  array{period?: string|null}  $params
     * @return array<string, mixed>
     */
    public function build(User $user, array $params = []): array
    {
        $period = $this->period($params);
        [$from, $to] = $this->range($period);
        $all = $user->can('tenders.view_all');
        $tenders = $this->tenders($user, $from, $to);
        $owners = $this->ownerNames($tenders);
        $lots = $this->lots(array_keys($tenders));
        $competitors = $this->competitors($lots);
        $today = PolishTime::today()->format('Y-m-d');

        $summary = [
            'decided_lots' => 0, 'won_lots' => 0, 'lost_lots' => 0, 'win_rate' => null,
            'cancelled_lots' => 0, 'not_submitted_lots' => 0, 'no_result_tenders' => 0,
            'top_loss_reason' => null, 'avg_price_gap_percent' => null, 'price_gap_lots' => 0, 'weakest_category' => null,
        ];
        $byOwner = [];
        $reasons = [];
        $rivals = [];
        $categories = [];
        $withoutCpv = 0;
        $gaps = [];
        $cancelled = [];
        $lotsPerTender = [];
        foreach ($lots as $lot) {
            $lotsPerTender[(int) $lot->tender_id] = ($lotsPerTender[(int) $lot->tender_id] ?? 0) + 1;
        }
        // przetargi z unieważnioną częścią policzoną / najniższa pominięta unieważniona część (części po numerze)
        $cancelledCounted = [];
        $cancelledSkipped = [];

        foreach ($lots as $lot) {
            $tender = $tenders[$lot->tender_id] ?? null;
            if ($tender === null) {
                continue;
            }
            $outcome = $lot->outcome;
            if ($outcome === TenderLot::OUTCOME_CANCELLED) {
                if (! self::ourCancelledLot($lot, $lotsPerTender[(int) $lot->tender_id] ?? 1)) {
                    $cancelledSkipped[(int) $lot->tender_id] ??= $lot;

                    continue;
                }
                $cancelledCounted[(int) $lot->tender_id] = true;
                $summary['cancelled_lots']++;
                $cancelled[] = $this->lotRef($tender, $lot, $owners);

                continue;
            }
            if ($outcome === TenderLot::OUTCOME_NOT_SUBMITTED) {
                $summary['not_submitted_lots']++;

                continue;
            }
            if ($outcome !== TenderLot::OUTCOME_WON && $outcome !== TenderLot::OUTCOME_LOST) {
                continue;
            }
            $won = $outcome === TenderLot::OUTCOME_WON;
            $summary['decided_lots']++;
            $summary[$won ? 'won_lots' : 'lost_lots']++;

            $ownerKey = $tender->owner_id !== null ? (int) $tender->owner_id : 0;
            $byOwner[$ownerKey] ??= ['owner_id' => $tender->owner_id !== null ? (int) $tender->owner_id : null, 'owner_name' => $owners[$ownerKey] ?? self::NO_OWNER, 'decided' => 0, 'won' => 0];
            $byOwner[$ownerKey]['decided']++;
            $byOwner[$ownerKey]['won'] += $won ? 1 : 0;

            $category = self::categoryOf($lot->cpv_main);
            if ($category === null) {
                $withoutCpv++;
            } else {
                $categories[$category['key']] ??= [...$category, 'decided' => 0, 'won' => 0];
                $categories[$category['key']]['decided']++;
                $categories[$category['key']]['won'] += $won ? 1 : 0;
            }

            if ($won) {
                continue;
            }
            $reason = is_string($lot->loss_reason) && isset(self::LOSS_REASON_LABELS[$lot->loss_reason]) ? $lot->loss_reason : null;
            $reasons[$reason ?? ''] = ($reasons[$reason ?? ''] ?? 0) + 1;

            $gap = self::gap($lot);
            if ($gap !== null) {
                $gaps[] = $gap;
            }
            $winnerId = $lot->winner_competitor_id !== null ? (int) $lot->winner_competitor_id : null;
            if ($winnerId !== null && isset($competitors[$winnerId])) {
                $rivals[$winnerId] ??= ['count' => 0, 'gaps' => []];
                $rivals[$winnerId]['count']++;
                if ($gap !== null) {
                    $rivals[$winnerId]['gaps'][] = $gap;
                }
            }
        }

        // cały przetarg unieważniony, a żadna część nie przeszła reguły (np. wszystkie części założył Biuletyn, a naszej
        // ceny nikt nie wpisał) — startowaliśmy w nim, więc liczy się raz: najniższa część
        foreach ($cancelledSkipped as $tenderId => $lot) {
            $tender = $tenders[$tenderId];
            if (! isset($cancelledCounted[$tenderId]) && $tender->result_status === TenderResultStatus::CANCELLED) {
                $summary['cancelled_lots']++;
                $cancelled[] = $this->lotRef($tender, $lot, $owners);
            }
        }

        $summary['win_rate'] = self::rate($summary['won_lots'], $summary['decided_lots']);
        $summary['price_gap_lots'] = count($gaps);
        $summary['avg_price_gap_percent'] = $gaps === [] ? null : round(array_sum($gaps) / count($gaps), 1);

        $lossReasons = [];
        foreach ($reasons as $reason => $count) {
            $lossReasons[] = [
                'reason' => $reason === '' ? null : $reason,
                'label' => $reason === '' ? self::NO_REASON_LABEL : self::LOSS_REASON_LABELS[$reason],
                'count' => $count,
            ];
        }
        // najczęstszy powód z wpisanych (bez „nie wpisano”), przy remisie kolejność listy powodów
        usort($lossReasons, static fn (array $a, array $b): int => [$b['count'], self::reasonOrder($a['reason'])] <=> [$a['count'], self::reasonOrder($b['reason'])]);
        foreach ($lossReasons as $row) {
            if ($row['reason'] !== null) {
                $summary['top_loss_reason'] = ['reason' => $row['reason'], 'count' => $row['count']];
                break;
            }
        }

        $categoryRows = array_map(static fn (array $c): array => [
            'key' => $c['key'],
            'label' => $c['label'],
            'decided' => $c['decided'],
            'won' => $c['won'],
            'win_rate' => self::rate($c['won'], $c['decided']),
        ], array_values($categories));
        usort($categoryRows, static fn (array $a, array $b): int => [$b['decided'], $a['label']] <=> [$a['decided'], $b['label']]);
        $eligible = array_values(array_filter($categoryRows, static fn (array $c): bool => $c['key'] !== self::OTHER_CATEGORY['key'] && $c['decided'] >= self::WEAKEST_MIN_DECIDED));
        if (count($eligible) >= 2) {
            usort($eligible, static fn (array $a, array $b): int => [$a['win_rate'], $b['decided']] <=> [$b['win_rate'], $a['decided']]);
            $weakest = $eligible[0];
            $summary['weakest_category'] = ['key' => $weakest['key'], 'label' => $weakest['label'], 'win_rate' => $weakest['win_rate'], 'decided' => $weakest['decided']];
        }

        $ownerRows = array_map(static fn (array $o): array => [...$o, 'win_rate' => self::rate($o['won'], $o['decided'])], array_values($byOwner));
        usort($ownerRows, static fn (array $a, array $b): int => [$b['decided'], $b['won'], $a['owner_name']] <=> [$a['decided'], $a['won'], $b['owner_name']]);

        $competitorRows = [];
        foreach ($rivals as $id => $row) {
            $competitorRows[] = [
                'competitor_id' => $id,
                'name' => $competitors[$id]['name'],
                'nip' => $competitors[$id]['nip'],
                'won_against_us' => $row['count'],
                // średnio o tyle procent zwycięzca był tańszy od naszej ceny brutto (ujemne = droższy)
                'avg_cheaper_percent' => $row['gaps'] === [] ? null : round(array_sum($row['gaps']) / count($row['gaps']), 1),
            ];
        }
        usort($competitorRows, static fn (array $a, array $b): int => [$b['won_against_us'], $a['name']] <=> [$a['won_against_us'], $b['name']]);

        // przetargi bez wyniku: termin minął (dzień przed dzisiejszym), wynik pusty
        $noResult = [];
        $noResultByOwner = [];
        foreach ($tenders as $tender) {
            if ($tender->result_status !== null || in_array($tender->status, self::NO_RESULT_SKIPPED_STATUSES, true) || $tender->deadline_date >= $today) {
                continue;
            }
            $summary['no_result_tenders']++;
            $ownerKey = $tender->owner_id !== null ? (int) $tender->owner_id : 0;
            $noResultByOwner[$ownerKey] ??= ['owner_id' => $tender->owner_id !== null ? (int) $tender->owner_id : null, 'owner_name' => $owners[$ownerKey] ?? self::NO_OWNER, 'count' => 0];
            $noResultByOwner[$ownerKey]['count']++;
            $noResult[] = [...$this->tenderRef($tender, $owners), 'status' => (string) $tender->status];
        }
        usort($noResult, static fn (array $a, array $b): int => [$a['deadline'], $a['tender_id']] <=> [$b['deadline'], $b['tender_id']]);
        $noResultByOwner = array_values($noResultByOwner);
        usort($noResultByOwner, static fn (array $a, array $b): int => [$b['count'], $a['owner_name']] <=> [$a['count'], $b['owner_name']]);
        usort($cancelled, static fn (array $a, array $b): int => [$b['deadline'], $a['tender_id'], $a['lot_no']] <=> [$a['deadline'], $b['tender_id'], $b['lot_no']]);

        return [
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'period' => $period,
            'from' => $from,
            'to' => $to,
            'scope' => $all ? 'all' : 'own',
            'summary' => $summary,
            'by_owner' => $ownerRows,
            'loss_reasons' => $lossReasons,
            'competitors' => array_slice($competitorRows, 0, self::COMPETITORS_LIMIT),
            'categories' => $categoryRows,
            'categories_note' => $this->categoriesNote($withoutCpv, $summary['decided_lots']),
            'cancelled' => array_slice($cancelled, 0, self::CANCELLED_LIMIT),
            'no_result' => array_slice($noResult, 0, self::NO_RESULT_LIMIT),
            'no_result_by_owner' => $noResultByOwner,
        ];
    }

    /** Nagłówki CSV (wiersz na część zamówienia; przetarg bez zapisanych części — jeden wiersz bez części). */
    public function headers(): array
    {
        return [
            'Numer przetargu', 'Numer ogłoszenia', 'Tytuł', 'Zamawiający', 'Opiekun', 'Termin składania', 'Godzina',
            'Wynik przetargu', 'Część', 'Nazwa części', 'Kod CPV części', 'Rodzaj towaru', 'Wynik części', 'Powód przegranej',
            'Nasza cena netto', 'Stawka VAT %', 'Nasza cena brutto', 'Wygrała firma', 'NIP zwycięzcy',
            'Cena zwycięzcy (z ogłoszenia, przyjęta jako brutto)', 'Waluta',
            'Różnica do zwycięzcy % (nasza cena brutto do ceny zwycięzcy)', 'Liczba ofert', 'Najniższa cena', 'Najwyższa cena',
            'Ogłoszenie o wyniku', 'Notatka',
        ];
    }

    /**
     * Wiersze CSV (ten sam zakres i okres co build()); kwoty z przecinkiem dziesiętnym jak w polskim Excelu.
     *
     * @param  array{period?: string|null}  $params
     * @return iterable<int, list<string|null>>
     */
    public function rows(User $user, array $params = []): iterable
    {
        [$from, $to] = $this->range($this->period($params));
        $tenders = $this->tenders($user, $from, $to);
        $owners = $this->ownerNames($tenders);
        $clients = $this->clientNames($tenders);
        $byTender = [];
        foreach ($this->lots(array_keys($tenders)) as $lot) {
            $byTender[(int) $lot->tender_id][] = $lot;
        }
        $winnerIds = [];
        $noticeIds = [];
        foreach ($byTender as $list) {
            foreach ($list as $lot) {
                if ($lot->winner_competitor_id !== null) {
                    $winnerIds[(int) $lot->winner_competitor_id] = true;
                }
                if ($lot->bzp_notice_id !== null) {
                    $noticeIds[(int) $lot->bzp_notice_id] = true;
                }
            }
        }
        $winners = $winnerIds === [] ? [] : DB::table('competitors')->whereIn('id', array_keys($winnerIds))->get(['id', 'name', 'nip'])->keyBy('id')->all();
        $notices = $noticeIds === [] ? [] : DB::table('procurement_notices')->whereIn('id', array_keys($noticeIds))->pluck('notice_number', 'id')->all();

        $sorted = $tenders;
        uasort($sorted, static fn (object $a, object $b): int => [$a->deadline_date, $a->id] <=> [$b->deadline_date, $b->id]);
        foreach ($sorted as $tender) {
            $ownerKey = $tender->owner_id !== null ? (int) $tender->owner_id : 0;
            $base = [
                (string) $tender->number,
                $tender->notice_number,
                (string) $tender->title,
                $tender->client_id !== null ? ($clients[(int) $tender->client_id] ?? null) : null,
                $owners[$ownerKey] ?? self::NO_OWNER,
                $tender->deadline_date,
                self::time($tender->deadline_time),
                self::tenderStatusLabel($tender->result_status),
            ];
            $list = $byTender[(int) $tender->id] ?? [];
            if ($list === []) {
                yield [...$base, ...array_fill(0, 19, null)];

                continue;
            }
            foreach ($list as $lot) {
                $gross = TenderResultService::gross($lot->our_net, $lot->our_vat_rate);
                $gap = self::gap($lot);
                $winner = $lot->winner_competitor_id !== null ? ($winners[(int) $lot->winner_competitor_id] ?? null) : null;
                $category = self::categoryOf($lot->cpv_main);
                yield [
                    ...$base,
                    (string) $lot->lot_no,
                    $lot->name,
                    $lot->cpv_main,
                    $category['label'] ?? null,
                    self::OUTCOME_LABELS[$lot->outcome] ?? null,
                    $lot->outcome === TenderLot::OUTCOME_LOST && $lot->loss_reason !== null ? (self::LOSS_REASON_LABELS[$lot->loss_reason] ?? $lot->loss_reason) : null,
                    self::decimal($lot->our_net),
                    self::decimal($lot->our_vat_rate),
                    self::decimal($gross),
                    $winner->name ?? null,
                    $winner->nip ?? $lot->winner_national_id_raw,
                    self::decimal($lot->winner_price),
                    $lot->currency,
                    $gap !== null ? str_replace('.', ',', (string) $gap) : null,
                    $lot->offers_count !== null ? (string) $lot->offers_count : null,
                    self::decimal($lot->lowest_price),
                    self::decimal($lot->highest_price),
                    $lot->bzp_notice_id !== null ? ($notices[(int) $lot->bzp_notice_id] ?? null) : null,
                    $lot->note,
                ];
            }
        }
    }

    /**
     * Rodzaj towaru z kodu CPV części: najdłuższy pasujący początek kodu z config bzp.cpv_categories; kod spoza
     * mapy = „Inny rodzaj”; brak kodu = null.
     *
     * @return array{key: string, label: string}|null
     */
    public static function categoryOf(?string $cpv): ?array
    {
        $digits = substr((string) preg_replace('/\D+/', '', (string) $cpv), 0, 8);
        if (strlen($digits) < 8) {
            return null;
        }
        $best = null;
        $bestLength = 0;
        foreach ((array) config('bzp.cpv_categories', []) as $key => $category) {
            foreach ((array) ($category['prefixes'] ?? []) as $prefix) {
                $prefix = (string) $prefix;
                if ($prefix !== '' && strlen($prefix) > $bestLength && str_starts_with($digits, $prefix)) {
                    $best = ['key' => (string) $key, 'label' => (string) ($category['label'] ?? $key)];
                    $bestLength = strlen($prefix);
                }
            }
        }

        return $best ?? self::OTHER_CATEGORY;
    }

    /**
     * @param  array{period?: string|null}  $params
     */
    private function period(array $params): string
    {
        return in_array($params['period'] ?? null, self::PERIODS, true) ? (string) $params['period'] : self::DEFAULT_PERIOD;
    }

    /**
     * Granice okresu jako daty (dzień w Polsce): „90d” = 90 dni z dzisiejszym, „year” = od 1 stycznia.
     *
     * @return array{0: string, 1: string}
     */
    private function range(string $period): array
    {
        $today = PolishTime::today();
        $from = $period === 'year' ? $today->startOfYear() : $today->subDays(89);

        return [$from->format('Y-m-d'), $today->format('Y-m-d')];
    }

    /**
     * Przetargi z zakresu użytkownika z terminem w okresie (bez JOIN-ów: accessibleBy filtruje po gołym owner_id).
     *
     * @return array<int, object>
     */
    private function tenders(User $user, string $from, string $to): array
    {
        $query = $user->can('tenders.view_all') ? Tender::query() : Tender::query()->accessibleBy($user);
        $rows = [];
        foreach ($query->toBase()
            ->whereNotNull('deadline')
            ->whereDate('deadline', '>=', $from)
            ->whereDate('deadline', '<=', $to)
            ->select(['id', 'number', 'title', 'client_id', 'owner_id', 'deadline', 'deadline_time', 'status', 'result_status', 'notice_number'])
            ->lazyById(self::CHUNK, 'id') as $row) {
            $row->deadline_date = substr((string) $row->deadline, 0, 10);
            $rows[(int) $row->id] = $row;
        }

        return $rows;
    }

    /**
     * @param  list<int>  $tenderIds
     * @return list<object>
     */
    private function lots(array $tenderIds): array
    {
        $lots = [];
        foreach (array_chunk($tenderIds, self::CHUNK) as $chunk) {
            foreach (DB::table('tender_lots')
                ->whereIn('tender_id', $chunk)
                ->orderBy('tender_id')
                ->orderBy('lot_no')
                ->get(['id', 'tender_id', 'lot_no', 'name', 'cpv_main', 'our_net', 'our_vat_rate', 'outcome', 'winner_competitor_id',
                    'winner_national_id_raw', 'winner_price', 'currency', 'offers_count', 'lowest_price', 'highest_price',
                    'loss_reason', 'note', 'bzp_notice_id', 'manual_fields', 'created_by_bzp']) as $lot) {
                $lots[] = $lot;
            }
        }

        return $lots;
    }

    /**
     * Firmy, które wygrały z nami (bez naszej firmy — Biuletyn zapisuje ją jako zwycięzcę wygranych części).
     *
     * @param  list<object>  $lots
     * @return array<int, array{name: string, nip: ?string}>
     */
    private function competitors(array $lots): array
    {
        $ids = [];
        foreach ($lots as $lot) {
            if ($lot->outcome === TenderLot::OUTCOME_LOST && $lot->winner_competitor_id !== null) {
                $ids[(int) $lot->winner_competitor_id] = true;
            }
        }
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('competitors')->whereIn('id', array_keys($ids))->get(['id', 'name', 'nip']) as $row) {
            if (! OurCompany::matches((string) $row->name, $row->nip)) {
                $out[(int) $row->id] = ['name' => (string) $row->name, 'nip' => $row->nip];
            }
        }

        return $out;
    }

    /**
     * @param  array<int, object>  $tenders
     * @return array<int, string>
     */
    private function ownerNames(array $tenders): array
    {
        $ids = [];
        foreach ($tenders as $tender) {
            if ($tender->owner_id !== null) {
                $ids[(int) $tender->owner_id] = true;
            }
        }

        return $ids === [] ? [] : DB::table('users')->whereIn('id', array_keys($ids))->pluck('name', 'id')->map(static fn ($n): string => (string) $n)->all();
    }

    /**
     * @param  array<int, object>  $tenders
     * @return array<int, string>
     */
    private function clientNames(array $tenders): array
    {
        $ids = [];
        foreach ($tenders as $tender) {
            if ($tender->client_id !== null) {
                $ids[(int) $tender->client_id] = true;
            }
        }
        $names = [];
        foreach (array_chunk(array_keys($ids), self::CHUNK) as $chunk) {
            $names += DB::table('clients')->whereIn('id', $chunk)->pluck('name', 'id')->map(static fn ($n): string => (string) $n)->all();
        }

        return $names;
    }

    /**
     * @param  array<int, string>  $owners
     * @return array<string, mixed>
     */
    private function tenderRef(object $tender, array $owners): array
    {
        $ownerKey = $tender->owner_id !== null ? (int) $tender->owner_id : 0;

        return [
            'tender_id' => (int) $tender->id,
            'number' => (string) $tender->number,
            'notice_number' => $tender->notice_number,
            'title' => (string) $tender->title,
            'owner_id' => $tender->owner_id !== null ? (int) $tender->owner_id : null,
            'owner_name' => $owners[$ownerKey] ?? self::NO_OWNER,
            'deadline' => $tender->deadline_date,
            'deadline_time' => self::time($tender->deadline_time),
            'url' => '/tenders/'.(int) $tender->id.'?tab=wynik',
        ];
    }

    /**
     * @param  array<int, string>  $owners
     * @return array<string, mixed>
     */
    private function lotRef(object $tender, object $lot, array $owners): array
    {
        return [...$this->tenderRef($tender, $owners), 'lot_no' => (int) $lot->lot_no, 'lot_name' => $lot->name];
    }

    private function categoriesNote(int $withoutCpv, int $decided): ?string
    {
        $note = 'Rodzaj towaru według głównego kodu CPV części (z ogłoszenia w Biuletynie albo wpisany ręcznie).';
        if ($withoutCpv > 0) {
            $note .= ' Bez kodu: '.$withoutCpv.' z '.$decided.' rozstrzygniętych części — nie są tu liczone.';
        }

        return $decided > 0 ? $note : null;
    }

    /**
     * Unieważniona część liczona jako nasza. Pomijana tylko część założona automatycznie przez Biuletyn
     * (created_by_bzp) bez naszej ceny i bez ręcznie wpisanego wyniku w przetargu z kilkoma częściami — to część
     * ogłoszenia, w której zwykle nie składaliśmy oferty. Jedyna część przetargu i część założona ręcznie liczą się
     * zawsze.
     */
    private static function ourCancelledLot(object $lot, int $tenderLots): bool
    {
        $manual = is_string($lot->manual_fields) ? json_decode($lot->manual_fields, true) : $lot->manual_fields;
        $manualOutcome = is_array($manual) && in_array('outcome', $manual, true);

        return ! ((bool) $lot->created_by_bzp && $lot->our_net === null && ! $manualOutcome && $tenderLots > 1);
    }

    /** Różnica do zwycięzcy w procentach naszej ceny brutto (dodatnia = byliśmy drożsi); null bez obu cen. */
    private static function gap(object $lot): ?float
    {
        $gross = TenderResultService::gross($lot->our_net, $lot->our_vat_rate);
        $gap = TenderResultService::priceGap($gross, $lot->winner_price, (string) ($lot->currency ?? 'PLN'));

        return $gap !== null ? (float) $gap['percent'] : null;
    }

    private static function rate(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part * 100 / $whole, 1) : null;
    }

    private static function reasonOrder(?string $reason): int
    {
        $index = $reason === null ? false : array_search($reason, array_keys(self::LOSS_REASON_LABELS), true);

        return $index === false ? 99 : (int) $index;
    }

    private static function time(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^(\d{1,2}):(\d{2})/', $value, $m) === 1 ? sprintf('%02d:%s', (int) $m[1], $m[2]) : null;
    }

    /** Kwota z bazy („1000”, „1000.5”, „1000.50” — SQLite i MySQL zwracają różnie) jako „1000,50”. */
    private static function decimal(mixed $value): ?string
    {
        $cents = TenderResultService::cents($value);
        if ($cents === null) {
            return null;
        }
        $abs = abs($cents);

        return ($cents < 0 ? '-' : '').intdiv($abs, 100).','.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    private static function tenderStatusLabel(?string $status): string
    {
        return match ($status) {
            'won' => 'wygrany',
            'partial' => 'częściowo wygrany',
            'lost' => 'przegrany',
            'cancelled' => 'unieważniony',
            'not_submitted' => 'nie złożyliśmy oferty',
            default => 'bez wyniku',
        };
    }
}
