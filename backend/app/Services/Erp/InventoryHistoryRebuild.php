<?php

declare(strict_types=1);

namespace App\Services\Erp;

use App\Models\ErpWarehouse;
use App\Support\ClarionDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Historia zapasów wstecz z dokumentów XL (decyzja właściciela 01.10.2026: rok wstecz, potem co noc). Stan na początek
 * dnia X = dzisiejszy stan partii (TwrZasoby) − ruchy partii od dnia X (TraSElem ze znakiem dokumentu). Sprawdzone na
 * 500 towarach (88 tys. partii): suma ruchów od zawsze = stan partii — 121 520/121 520 par, wartość ±33 zł na 603 tys.
 *
 * Koszyki jak kafelki (InventoryBoardTotals / InventoryQuery) na dzień X: towar ze stanem w zakresie magazynów i oddziale,
 * „bez sprzedaży” z ostatniej sprzedaży (FS/PA/WZ, magazyn nagłówka — jak nocny odczyt) przed dniem X, najstarsza partia
 * ze stanem w dniu X. Wartość = koszt księgowy partii. Podział usługowe/handlowe — dzisiejszy słownik.
 *
 * Zapis (source 'xl_history') tylko po kontroli szwu: odtworzony pierwszy dzień zapisu nocnego (live) według aktualnych
 * reguł (InventorySnapshots::RULES_VERSION) musi się zgadzać z tym zapisem (cały towar ±1%, bez sprzedaży i „leży”
 * ±3% na wszystkich oddziałach). Zapisy nocne aktualnymi regułami nie są nigdy ruszane; dni wcześniejsze (odtworzone
 * albo zapis nocny starszymi regułami) — tylko z force.
 *
 * Obciążenie XL (decyzja właściciela): po każdych `workSeconds` pracy zapytań — `pauseSeconds` odpoczynku.
 */
final class InventoryHistoryRebuild
{
    /** Znak ruchu partii na typ dokumentu (TrS_GIDTyp) — z kontroli 500 towarów 01.10.2026. */
    public const SIGNS = [
        1489 => 1, 1617 => 1, 1604 => 1, 1521 => 1, 1497 => 1, 1529 => 1,
        2001 => -1, 2033 => -1, 2034 => -1, 1616 => -1, 1603 => -1, 2037 => -1, 2041 => -1, 2042 => -1,
        2009 => -1, 1600 => -1, 2003 => -1, 2004 => -1, 2005 => -1, 2045 => -1, 1624 => -1,
    ];

    public const CHUNK = 50;

    private const BUCKETS = ['stock', 'no_sale_6', 'no_sale_12', 'no_sale_24', 'never_sold', 'stale_36', 'stale_60'];

    /** „Jak długo leży” narastająco (InventoryBoardTotals::LOT_AGES): sztuki z dostaw przyjętych co najmniej tyle miesięcy temu. */
    private const LOT_AGE_MONTHS = [6, 12, 24, 36, 48, 60];

    /** Tolerancja kontroli szwu (względna). */
    private const SEAM_STOCK = 0.01;

    private const SEAM_UNSOLD = 0.03;

    /** @var array<int, int> nieznane typy dokumentów → liczba ruchów */
    private array $unknownTypes = [];

    private int $negativeLots = 0;

    public function __construct(private readonly ErpXlGateway $xl) {}

    /**
     * @param  callable(string): void|null  $say
     * @param  callable(float): void|null  $sleep
     * @return array{status: string, first_live: ?string, days: list<string>, skipped: int, items: int, sql_seconds: float, pause_seconds: float, negative_lots: int, unknown_types: array<int, int>, seam: list<array<string, mixed>>, seam_ok: bool, rows: int, preview?: list<array{date: string, stock: float, no_sale_6: float, no_sale_12: float, lot_age_6: float, lot_age_12: float}>}
     */
    public function run(int $days, bool $write = true, bool $force = false, ?callable $say = null, float $workSeconds = 5.0, float $pauseSeconds = 5.0, ?callable $sleep = null, bool $ignoreSeam = false): array
    {
        $say ??= static function (string $m): void {};
        $sleep ??= static function (float $s): void {
            usleep((int) round($s * 1_000_000));
        };
        // szew: pierwszy zapis nocny według aktualnych reguł (wersja w totals); zapisy nocne starszymi regułami
        // (1.10.2026: bufor jako sprzedaż z dzisiaj) można z force odtworzyć od nowa — jak dni historii
        $liveVersions = [];
        foreach (DB::table(InventorySnapshots::TABLE)->where('source', 'live')->selectRaw("taken_on, json_extract(totals, '$.version') as v")->get() as $r) {
            $day = substr((string) $r->taken_on, 0, 10);
            $liveVersions[$day] = max($liveVersions[$day] ?? 0, (int) $r->v);
        }
        $current = array_keys(array_filter($liveVersions, static fn (int $v): bool => $v >= InventorySnapshots::RULES_VERSION));
        sort($current);
        $result = ['status' => 'no_live', 'first_live' => null, 'days' => [], 'skipped' => 0, 'items' => 0, 'sql_seconds' => 0.0,
            'pause_seconds' => 0.0, 'negative_lots' => 0, 'unknown_types' => [], 'seam' => [], 'seam_ok' => false, 'rows' => 0];
        if ($current === []) {
            return $result;
        }
        $seamDay = CarbonImmutable::parse($current[0]);
        $result['first_live'] = $seamDay->toDateString();

        // dni do odtworzenia: przed szwem; zapisane (historia albo nocny starszymi regułami) — pomijane, odtwarzane z force
        $existing = DB::table(InventorySnapshots::TABLE)
            ->where('taken_on', '>=', $seamDay->subDays($days)->toDateString())->where('taken_on', '<', $seamDay->toDateString())
            ->distinct()->pluck('taken_on')->mapWithKeys(static fn ($d): array => [substr((string) $d, 0, 10) => true])->all();
        $targets = [];
        for ($i = $days; $i >= 1; $i--) {
            $d = $seamDay->subDays($i)->toDateString();
            if (isset($existing[$d]) && ! $force) {
                $result['skipped']++;

                continue;
            }
            $targets[] = $d;
        }
        $result['days'] = $targets;
        if ($targets === []) {
            $result['status'] = 'nothing';

            return $result;
        }

        // etykiety od najnowszej: szew (pierwszy dzień live), potem wstecz
        $labels = [$seamDay->toDateString()];
        for ($i = 1; $i <= $days; $i++) {
            $labels[] = $seamDay->subDays($i)->toDateString();
        }
        $labelDays = array_map(self::dayNumber(...), $labels);
        $cut = [];
        foreach ($labels as $li => $label) {
            foreach ([6, 12, 24, 36, 48, 60] as $m) {
                $cut[$li][$m] = self::dayNumber(CarbonImmutable::parse($label)->subMonthsNoOverflow($m)->toDateString());
            }
        }
        $since = (end($labelDays) ?: 0) * 86400;
        $fromClarion = ClarionDate::fromDate(CarbonImmutable::parse(end($labels)));
        $clarionShift = ClarionDate::fromDate(CarbonImmutable::create(1990, 1, 1));
        $service = array_flip(ErpWarehouse::serviceCodes());

        $acc = [];
        $wacc = [];
        $work = 0.0;
        $gids = DB::table('erp_items')->whereNull('removed_at')->orderBy('xl_gid')->pluck('xl_gid')->map(static fn ($g): int => (int) $g)->all();
        $result['items'] = count($gids);
        foreach (array_chunk($gids, self::CHUNK) as $n => $chunk) {
            $t0 = microtime(true);
            // zakleszczenie z pracą w XL (nasze zapytanie ustępuje): odpoczynek i ponowienie paczki, najwyżej 3 razy
            for ($try = 1; ; $try++) {
                try {
                    $history = $this->xl->lotHistory($chunk, $since);
                    $sales = $this->xl->saleHistory($chunk, $fromClarion);
                    break;
                } catch (QueryException $e) {
                    if ($try >= 3 || ! str_contains($e->getMessage(), 'deadlock')) {
                        throw $e;
                    }
                    $say(sprintf('Konflikt z pracą w XL — odpoczynek %.0f s i ponowienie paczki.', max(5.0, $pauseSeconds)));
                    $sleep(max(5.0, $pauseSeconds));
                    $result['pause_seconds'] += max(5.0, $pauseSeconds);
                }
            }
            $spent = microtime(true) - $t0;
            $result['sql_seconds'] += $spent;
            $work += $spent;
            $this->accumulate($history, $sales, $labelDays, $cut, $service, $clarionShift, $acc, $wacc);
            if ($work >= $workSeconds) {
                $sleep($pauseSeconds);
                $result['pause_seconds'] += $pauseSeconds;
                $work = 0.0;
            }
            if (($n + 1) % 40 === 0) {
                $say(sprintf('Towary: %d/%d · zapytania XL %.1f s · odpoczynek %.0f s', min(count($gids), ($n + 1) * self::CHUNK), count($gids), $result['sql_seconds'], $result['pause_seconds']));
            }
        }
        $result['negative_lots'] = $this->negativeLots;
        ksort($this->unknownTypes);
        $result['unknown_types'] = $this->unknownTypes;

        // podgląd: wszystkie oddziały, magazyny handlowe, pierwszy dzień każdego miesiąca i szew
        foreach ($labels as $li => $label) {
            if ($li === 0 || str_ends_with($label, '-01')) {
                $a = $acc[$li]['|trade'] ?? [];
                $result['preview'][] = ['date' => $label, 'stock' => round($a['stock'][1] ?? 0.0, 2),
                    'no_sale_6' => round($a['no_sale_6'][1] ?? 0.0, 2), 'no_sale_12' => round($a['no_sale_12'][1] ?? 0.0, 2),
                    'lot_age_6' => round($a['lot_age_6'][1] ?? 0.0, 2), 'lot_age_12' => round($a['lot_age_12'][1] ?? 0.0, 2)];
            }
        }
        $result['preview'] = array_reverse($result['preview'] ?? []);
        [$result['seam'], $result['seam_ok']] = $this->seam($seamDay->toDateString(), $acc[0] ?? []);
        if (! $result['seam_ok'] && ! $ignoreSeam) {
            $result['status'] = 'seam_failed';

            return $result;
        }
        if (! $write) {
            $result['status'] = 'dry_run';

            return $result;
        }
        $result['rows'] = $this->store($targets, $labels, $acc, $wacc, array_keys($service));
        $result['status'] = 'saved';

        return $result;
    }

    /** Dni od 1.01.1990 — ta sama skala co XlTimestamp / 86400. */
    public static function dayNumber(string $date): int
    {
        return (int) CarbonImmutable::create(1990, 1, 1)->diffInDays(CarbonImmutable::parse($date)->startOfDay());
    }

    /**
     * Koszyki i magazyny jednej paczki towarów dla każdej etykiety (indeks 0 = szew, dalej wstecz).
     *
     * @param  array{lots: list<array<string, mixed>>, moves: list<array<string, mixed>>}  $history
     * @param  array{before: list<array<string, mixed>>, days: list<array<string, mixed>>}  $sales
     * @param  list<int>  $labelDays
     * @param  array<int, array<int, int>>  $cut
     * @param  array<string, int>  $service
     * @param  array<int, array<string, array<string, array{0: int, 1: float}>>>  $acc
     * @param  array<int, array<string, array{0: int, 1: float, 2: float}>>  $wacc
     */
    private function accumulate(array $history, array $sales, array $labelDays, array $cut, array $service, int $clarionShift, array &$acc, array &$wacc): void
    {
        // partie towaru: stan dziś, przyjęcie, ruchy na dzień (ze znakiem)
        $items = [];
        foreach ($history['lots'] as $l) {
            $k = $l['dst'].'|'.$l['warehouse_code'];
            $lot = &$items[$l['gid']]['lots'][$k];
            $lot['wh'] = $l['warehouse_code'];
            $lot['rec'] = $l['received_at'] !== null ? intdiv((int) $l['received_at'], 86400) : null;
            $lot['q'] = ($lot['q'] ?? 0.0) + (float) $l['quantity'];
            $lot['v'] = ($lot['v'] ?? 0.0) + (float) $l['value'];
            unset($lot);
        }
        foreach ($history['moves'] as $m) {
            $sign = self::SIGNS[(int) $m['type']] ?? null;
            if ($sign === null) {
                $this->unknownTypes[(int) $m['type']] = ($this->unknownTypes[(int) $m['type']] ?? 0) + 1;

                continue;
            }
            $k = $m['dst'].'|'.$m['warehouse_code'];
            $lot = &$items[$m['gid']]['lots'][$k];
            $lot['wh'] ??= $m['warehouse_code'];
            $lot['rec'] ??= $m['received_at'] !== null ? intdiv((int) $m['received_at'], 86400) : null;
            $lot['q'] ??= 0.0;
            $lot['v'] ??= 0.0;
            $lot['d'][(int) $m['day']][0] = ($lot['d'][(int) $m['day']][0] ?? 0.0) + $sign * (float) $m['quantity'];
            $lot['d'][(int) $m['day']][1] = ($lot['d'][(int) $m['day']][1] ?? 0.0) + $sign * (float) $m['cost'];
            unset($lot);
        }
        // sprzedaż: dni na oddział ('' = wszystkie magazyny, także dokument bez magazynu)
        foreach ([...$sales['before'], ...$sales['days']] as $s) {
            if (! isset($items[$s['gid']])) {
                continue;
            }
            $day = (int) $s['date'] - $clarionShift;
            $code = $s['warehouse_code'];
            $items[$s['gid']]['sales'][''][] = $day;
            $loc = $code !== null ? WarehouseLocations::of($code) : null;
            if ($loc !== null) {
                $items[$s['gid']]['sales'][$loc][] = $day;
            }
        }

        foreach ($items as $item) {
            $this->accumulateItem($item, $labelDays, $cut, $service, $acc, $wacc);
        }
    }

    /**
     * @param  array{lots?: array<string, array<string, mixed>>, sales?: array<string, list<int>>}  $item
     * @param  list<int>  $labelDays
     * @param  array<int, array<int, int>>  $cut
     * @param  array<string, int>  $service
     * @param  array<int, array<string, array<string, array{0: int, 1: float}>>>  $acc
     * @param  array<int, array<string, array{0: int, 1: float, 2: float}>>  $wacc
     */
    private function accumulateItem(array $item, array $labelDays, array $cut, array $service, array &$acc, array &$wacc): void
    {
        $lots = [];
        foreach ($item['lots'] ?? [] as $lot) {
            $deltas = $lot['d'] ?? [];
            if (abs($lot['q']) < 1e-9 && $deltas === []) {
                continue;
            }
            krsort($deltas);
            $lots[] = ['wh' => $lot['wh'], 'rec' => $lot['rec'], 'q' => $lot['q'], 'v' => $lot['v'], 'd' => $deltas, 'neg' => false];
        }
        if ($lots === []) {
            return;
        }
        $sales = [];
        foreach ($item['sales'] ?? [] as $loc => $daysList) {
            rsort($daysList);
            $sales[(string) $loc] = $daysList;
        }
        $salePos = array_fill_keys(array_keys($sales), 0);

        foreach ($labelDays as $li => $label) {
            // stan na początek dnia etykiety: dzisiejszy − ruchy od tego dnia (etykiety malejąco)
            $wh = [];
            foreach ($lots as &$lot) {
                foreach ($lot['d'] as $day => [$dq, $dv]) {
                    if ($day < $label) {
                        break;
                    }
                    $lot['q'] -= $dq;
                    $lot['v'] -= $dv;
                    unset($lot['d'][$day]);
                }
                if ($lot['q'] < -1e-6 && ! $lot['neg']) {
                    $lot['neg'] = true;
                    $this->negativeLots++;
                }
                if ($lot['q'] > 1e-9) {
                    $w = &$wh[$lot['wh']];
                    $w['q'] = ($w['q'] ?? 0.0) + $lot['q'];
                    $w['v'] = ($w['v'] ?? 0.0) + $lot['v'];
                    if ($lot['rec'] !== null && (! isset($w['rec']) || $lot['rec'] < $w['rec'])) {
                        $w['rec'] = $lot['rec'];
                    }
                    // jak długo leży: sztuki z dostaw przyjętych najpóźniej próg temu
                    if ($lot['rec'] !== null) {
                        foreach (self::LOT_AGE_MONTHS as $m) {
                            if ($lot['rec'] <= $cut[$li][$m]) {
                                $w['age'][$m] = ($w['age'][$m] ?? 0.0) + $lot['v'];
                            }
                        }
                    }
                    unset($w);
                }
            }
            unset($lot);
            if ($wh === []) {
                continue;
            }

            // zakresy: oddział ('' = wszystkie) × magazyny (all + handlowe albo usługowe)
            $combos = [];
            foreach ($wh as $code => $w) {
                $code = (string) $code;
                $kind = isset($service[$code]) ? 'service' : 'trade';
                $wacc[$li][$code][0] = ($wacc[$li][$code][0] ?? 0) + 1;
                $wacc[$li][$code][1] = ($wacc[$li][$code][1] ?? 0.0) + $w['q'];
                $wacc[$li][$code][2] = ($wacc[$li][$code][2] ?? 0.0) + $w['v'];
                $loc = WarehouseLocations::of($code);
                foreach (array_filter(['', $loc], static fn ($l): bool => $l !== null) as $l) {
                    foreach (['all', $kind] as $scope) {
                        $c = &$combos[$l.'|'.$scope];
                        $c['q'] = ($c['q'] ?? 0.0) + $w['q'];
                        $c['v'] = ($c['v'] ?? 0.0) + $w['v'];
                        if (isset($w['rec']) && (! isset($c['rec']) || $w['rec'] < $c['rec'])) {
                            $c['rec'] = $w['rec'];
                        }
                        foreach ($w['age'] ?? [] as $m => $v) {
                            $c['age'][$m] = ($c['age'][$m] ?? 0.0) + $v;
                        }
                        unset($c);
                    }
                }
            }

            foreach ($combos as $key => $c) {
                if ($c['q'] <= 1e-9) {
                    continue;
                }
                $loc = (string) strstr($key, '|', true);
                // ostatnia sprzedaż przed dniem etykiety (dni malejąco — wskaźnik tylko rośnie)
                $sale = null;
                if (isset($sales[$loc])) {
                    $list = $sales[$loc];
                    $p = $salePos[$loc];
                    while ($p < count($list) && $list[$p] >= $label) {
                        $p++;
                    }
                    $salePos[$loc] = $p;
                    $sale = $list[$p] ?? null;
                }
                $oldest = $c['rec'] ?? null;
                $noSale = static fn (int $m): bool => $sale !== null ? $sale < $cut[$li][$m] : ($oldest === null || $oldest <= $cut[$li][$m]);
                $flags = [
                    'stock' => true,
                    'no_sale_6' => $noSale(6),
                    'no_sale_12' => $noSale(12),
                    'no_sale_24' => $noSale(24),
                    'never_sold' => $sale === null && ($oldest === null || $oldest <= $cut[$li][6]),
                ];
                $flags['stale_36'] = $flags['no_sale_12'] && $oldest !== null && $oldest <= $cut[$li][36];
                $flags['stale_60'] = $flags['no_sale_12'] && $oldest !== null && $oldest <= $cut[$li][60];
                foreach ($flags as $bucket => $in) {
                    if ($in) {
                        $acc[$li][$key][$bucket][0] = ($acc[$li][$key][$bucket][0] ?? 0) + 1;
                        $acc[$li][$key][$bucket][1] = ($acc[$li][$key][$bucket][1] ?? 0.0) + $c['v'];
                    }
                }
                foreach ($c['age'] ?? [] as $m => $v) {
                    $acc[$li][$key]['lot_age_'.$m][0] = ($acc[$li][$key]['lot_age_'.$m][0] ?? 0) + 1;
                    $acc[$li][$key]['lot_age_'.$m][1] = ($acc[$li][$key]['lot_age_'.$m][1] ?? 0.0) + $v;
                }
            }
        }
    }

    /**
     * Odtworzony pierwszy dzień zapisu nocnego wobec tego zapisu.
     *
     * @param  array<string, array<string, array{0: int, 1: float}>>  $rebuilt
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function seam(string $day, array $rebuilt): array
    {
        $rows = [];
        $ok = true;
        $live = DB::table(InventorySnapshots::TABLE)->where('taken_on', $day)->where('source', 'live')->get(['location', 'scope', 'totals']);
        foreach ($live as $r) {
            $totals = json_decode((string) $r->totals, true);
            $buckets = $totals['buckets'] ?? [];
            $ages = InventorySnapshots::lotAgeThresholds(is_array($totals) ? $totals : []);
            $key = $r->location.'|'.$r->scope;
            foreach (['stock', 'no_sale_6', 'no_sale_12', 'lot_age_6', 'lot_age_12'] as $b) {
                if (str_starts_with($b, 'lot_age_') && ! isset($ages[(int) substr($b, 8)])) {
                    continue;
                }
                $liveValue = str_starts_with($b, 'lot_age_') ? $ages[(int) substr($b, 8)]['value'] : (float) ($buckets[$b]['value'] ?? 0);
                $rebuiltValue = (float) ($rebuilt[$key][$b][1] ?? 0.0);
                $diff = abs($rebuiltValue - $liveValue) / max(1.0, abs($liveValue));
                $limit = $b === 'stock' ? self::SEAM_STOCK : self::SEAM_UNSOLD;
                $bad = (string) $r->location === '' && $diff > $limit;
                $ok = $ok && ! $bad;
                $rows[] = ['location' => (string) $r->location, 'scope' => (string) $r->scope, 'bucket' => $b,
                    'live' => round($liveValue, 2), 'rebuilt' => round($rebuiltValue, 2), 'diff' => round($diff, 4), 'bad' => $bad];
            }
        }
        if ($live->isEmpty()) {
            $ok = false;
        }

        return [$rows, $ok];
    }

    /**
     * @param  list<string>  $targets
     * @param  list<string>  $labels
     * @param  array<int, array<string, array<string, array{0: int, 1: float}>>>  $acc
     * @param  array<int, array<string, array{0: int, 1: float, 2: float}>>  $wacc
     * @param  list<string>  $serviceCodes
     */
    private function store(array $targets, array $labels, array $acc, array $wacc, array $serviceCodes): int
    {
        $index = array_flip($labels);
        $locations = app(InventorySnapshots::class)->locations();
        $service = array_flip($serviceCodes);
        $now = now();
        $count = 0;
        foreach (array_chunk($targets, 30) as $batch) {
            $rows = [];
            $warehouses = [];
            foreach ($batch as $day) {
                $li = $index[$day];
                foreach ($locations as $location) {
                    foreach (InventoryQuery::SCOPES as $scope) {
                        $buckets = [];
                        foreach (self::BUCKETS as $b) {
                            $a = $acc[$li][$location.'|'.$scope][$b] ?? [0, 0.0];
                            $buckets[$b] = ['items' => $a[0], 'value' => round($a[1], 2), 'value_unknown' => 0];
                        }
                        // jak długo leży — w postaci InventoryBoardTotals::lotAgeSummary (bez dostaw bez daty)
                        $lotAge = ['buckets' => [], 'items' => $buckets['stock']['items'], 'value' => $buckets['stock']['value'], 'value_unknown_items' => 0];
                        foreach (self::LOT_AGE_MONTHS as $m) {
                            $a = $acc[$li][$location.'|'.$scope]['lot_age_'.$m] ?? [0, 0.0];
                            $lotAge['buckets'][] = ['key' => 'lot_age_'.$m, 'from_months' => $m, 'to_months' => null, 'items' => $a[0], 'value' => round($a[1], 2)];
                        }
                        $rows[] = [
                            'taken_on' => $day, 'location' => $location, 'scope' => $scope, 'source' => 'xl_history',
                            'totals' => json_encode(['version' => InventorySnapshots::RULES_VERSION, 'buckets' => $buckets, 'lot_age' => $lotAge,
                                'service_codes' => $serviceCodes], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                            'read_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                        ];
                    }
                }
                foreach ($wacc[$li] ?? [] as $code => [$items, $quantity, $value]) {
                    $warehouses[] = [
                        'taken_on' => $day, 'warehouse_code' => (string) $code, 'location' => WarehouseLocations::of((string) $code),
                        'is_service' => isset($service[(string) $code]), 'source' => 'xl_history', 'items' => $items,
                        'quantity' => round($quantity, 4), 'value' => round($value, 2), 'value_unknown' => 0,
                        'created_at' => $now, 'updated_at' => $now,
                    ];
                }
            }
            DB::transaction(function () use ($batch, $rows, $warehouses): void {
                // dni przed szwem: historia albo zapis nocny starszymi regułami — zastępowane w całości
                DB::table(InventorySnapshots::TABLE)->whereIn('taken_on', $batch)->delete();
                DB::table(InventorySnapshots::WAREHOUSE_TABLE)->whereIn('taken_on', $batch)->delete();
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table(InventorySnapshots::TABLE)->insert($chunk);
                }
                foreach (array_chunk($warehouses, 500) as $chunk) {
                    DB::table(InventorySnapshots::WAREHOUSE_TABLE)->insert($chunk);
                }
            });
            $count += count($rows);
        }

        return $count;
    }
}
