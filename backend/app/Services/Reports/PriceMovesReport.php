<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\User;
use App\Services\Pricing\SupplierSpecialMask;
use App\Support\ProductPriceChangeResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Raport „Ruchy cen”: zmiany cen dostawców w ostatnich 7/30/90 dniach z historii cen (ProductPriceChangeResolver).
 *
 * Każdy ruch trafia do jednej kategorii:
 *  - dodanie (pierwszy wiersz grupy źródła na karcie) — nowa cena źródła, nie zmiana;
 *  - zmiana rabatu (source = price_list_discount) — nasza decyzja w oknie cennika, nie ruch dostawcy;
 *  - podejrzana zmiana — nowa/stara cena (podstawa pct) ≥ 4 albo ≤ 0,25: zwykle błąd odczytu, jednostki
 *    (para/karton) albo inna waluta zapisana jako PLN; poza medianą, czołówkami i licznikami zmian;
 *  - zmiana — reszta; zmiana o mniej niż 1% (MINOR_PCT) jest dodatkowo liczona jako drobna (zaokrąglenia cen konta,
 *    przeliczenia kursu) — wliczona do zmian i mediany, ale wykres tygodni oraz podwyżki/obniżki w wierszach źródeł
 *    i producentów liczą tylko zmiany istotne (kolejność wierszy też wg istotnych).
 * Mediana i kwartyle tylko z procentów od ceny zakupu (pct_basis = purchase) — procent od katalogu to inna miara.
 * Okres i tygodnie w czasie polskim; created_at w bazie w strefie aplikacji (UTC).
 */
final class PriceMovesReport
{
    public const TIMEZONE = 'Europe/Warsaw';

    public const DAYS = [7, 30, 90];

    public const DEFAULT_DAYS = 30;

    /** Stosunek nowa/stara cena, od którego (albo od odwrotności w dół) zmiana jest podejrzana. */
    public const SUSPICIOUS_RATIO = 4.0;

    /** Zmiana o mniej niż tyle procent (co do wartości bezwzględnej) jest drobna. */
    public const MINOR_PCT = 1.0;

    private const CACHE_MINUTES = 10;

    private const TOP_SOURCES = 20;

    private const TOP_MANUFACTURERS = 15;

    private const TOP_MOVES = 10;

    private const TOP_SUSPICIOUS = 20;

    private const IDS_PER_QUERY = 1000;

    private const NO_MANUFACTURER = '(brak producenta)';

    public function __construct(private readonly ProductPriceChangeResolver $resolver) {}

    /**
     * @param  array{days?: int|string|null}  $params
     * @return array<string, mixed>
     */
    public function build(User $user, array $params = []): array
    {
        $days = (int) ($params['days'] ?? self::DEFAULT_DAYS);
        if (! in_array($days, self::DAYS, true)) {
            $days = self::DEFAULT_DAYS;
        }
        $mask = SupplierSpecialMask::forUser($user);

        // wynik zależy od użytkownika tylko przez maskę (ukrywa albo nie) — klucz bez id użytkownika
        $key = 'reports:prices:v4:'.$days.':'.($mask->hides() ? 'masked' : 'full');

        return Cache::remember($key, now()->addMinutes(self::CACHE_MINUTES), fn (): array => $this->compute($days, $mask));
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(int $days, SupplierSpecialMask $mask): array
    {
        $today = Carbon::now(self::TIMEZONE)->startOfDay();
        // okres = $days dni kalendarzowych łącznie z dzisiejszym, od północy czasu polskiego
        $from = $today->copy()->subDays($days - 1);

        $additions = 0;
        $discounts = 0;
        /** @var list<array<string, mixed>> $changes */
        $changes = [];
        /** @var list<array<string, mixed>> $suspicious */
        $suspicious = [];
        // strumień z resolvera: dodania i rabaty tylko liczone, zapamiętane wyłącznie zmiany i podejrzane
        foreach ($this->resolver->changesSince($from, $mask) as $move) {
            if ($move['kind'] === 'addition') {
                $additions++;

                continue;
            }
            if (trim((string) $move['source']) === 'price_list_discount') {
                $discounts++;

                continue;
            }
            $ratio = $this->ratio($move);
            if ($ratio !== null && ($ratio >= self::SUSPICIOUS_RATIO || $ratio <= 1 / self::SUSPICIOUS_RATIO)) {
                $move['ratio'] = $ratio;
                $suspicious[] = $move;

                continue;
            }
            $changes[] = $move;
        }

        $products = $this->products(array_merge(
            array_column($changes, 'product_id'),
            array_column($suspicious, 'product_id'),
        ));

        $increases = 0;
        $decreases = 0;
        $minor = 0;
        /** @var array<string, array{0: int, 1: int, 2: int}> $weekly week_start => [istotne podwyżki, istotne obniżki, drobne] */
        $weekly = $this->emptyWeeks($from, $today);
        // klucze z prefiksem — etykieta albo producent z samych cyfr nie może stać się kluczem int
        /** @var array<string, array{label: string, changes: int, increases: int, decreases: int, minor: int, purchase: list<float>, max: float|null}> $sources */
        $sources = [];
        /** @var array<string, array{label: string, changes: int, increases: int, decreases: int, minor: int, purchase: list<float>}> $manufacturers */
        $manufacturers = [];
        /** @var list<float> $purchasePcts */
        $purchasePcts = [];
        $changedProducts = [];
        foreach ($changes as $move) {
            $pct = $move['pct'];
            $sign = $pct === null ? 0 : ($pct > 0 ? 1 : ($pct < 0 ? -1 : 0));
            $isMinor = $pct !== null && abs((float) $pct) < self::MINOR_PCT ? 1 : 0;
            $increases += $sign > 0 ? 1 : 0;
            $decreases += $sign < 0 ? 1 : 0;
            $minor += $isMinor;
            $changedProducts[$move['product_id']] = true;

            $week = $this->weekStart(Carbon::parse((string) $move['at'])->setTimezone(self::TIMEZONE));
            if (isset($weekly[$week])) {
                $weekly[$week][0] += $sign > 0 && ! $isMinor ? 1 : 0;
                $weekly[$week][1] += $sign < 0 && ! $isMinor ? 1 : 0;
                $weekly[$week][2] += $isMinor;
            }

            $purchasePct = $move['pct_basis'] === 'purchase' && $pct !== null ? (float) $pct : null;
            if ($purchasePct !== null) {
                $purchasePcts[] = $purchasePct;
            }

            $label = 's:'.$move['source_label'];
            $sources[$label] ??= ['label' => (string) $move['source_label'], 'changes' => 0, 'increases' => 0, 'decreases' => 0, 'minor' => 0, 'purchase' => [], 'max' => null];
            $sources[$label]['changes']++;
            $sources[$label]['minor'] += $isMinor;
            $sources[$label]['increases'] += $sign > 0 && ! $isMinor ? 1 : 0;
            $sources[$label]['decreases'] += $sign < 0 && ! $isMinor ? 1 : 0;
            if ($purchasePct !== null) {
                $sources[$label]['purchase'][] = $purchasePct;
            }
            if ($pct !== null && ($sources[$label]['max'] === null || $pct > $sources[$label]['max'])) {
                $sources[$label]['max'] = (float) $pct;
            }

            $name = $products[$move['product_id']]['manufacturer'] ?? self::NO_MANUFACTURER;
            $manufacturer = 'm:'.$name;
            $manufacturers[$manufacturer] ??= ['label' => $name, 'changes' => 0, 'increases' => 0, 'decreases' => 0, 'minor' => 0, 'purchase' => []];
            $manufacturers[$manufacturer]['changes']++;
            $manufacturers[$manufacturer]['minor'] += $isMinor;
            $manufacturers[$manufacturer]['increases'] += $sign > 0 && ! $isMinor ? 1 : 0;
            $manufacturers[$manufacturer]['decreases'] += $sign < 0 && ! $isMinor ? 1 : 0;
            if ($purchasePct !== null) {
                $manufacturers[$manufacturer]['purchase'][] = $purchasePct;
            }
        }

        $priced = array_values(array_filter($changes, static fn (array $m): bool => $m['pct'] !== null && $m['pct_basis'] !== null));
        $up = array_values(array_filter($priced, static fn (array $m): bool => $m['pct'] > 0));
        usort($up, static fn (array $a, array $b): int => [$b['pct'], $b['at'], $a['product_id']] <=> [$a['pct'], $a['at'], $b['product_id']]);
        $down = array_values(array_filter($priced, static fn (array $m): bool => $m['pct'] < 0));
        usort($down, static fn (array $a, array $b): int => [$a['pct'], $b['at'], $a['product_id']] <=> [$b['pct'], $a['at'], $b['product_id']]);
        usort($suspicious, static fn (array $a, array $b): int => [abs(log($b['ratio'])), $b['at']] <=> [abs(log($a['ratio'])), $a['at']]);

        // najpierw wiersze z największą liczbą zmian istotnych, potem wszystkich
        $bySignificance = static fn (array $a, array $b): int => [$b['increases'] + $b['decreases'], $b['changes'], $a['label']]
            <=> [$a['increases'] + $a['decreases'], $a['changes'], $b['label']];
        $sources = array_values($sources);
        usort($sources, $bySignificance);
        $manufacturers = array_values($manufacturers);
        usort($manufacturers, $bySignificance);

        $historySince = DB::table('product_price_history')->min('created_at');

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'days' => $days,
            'from' => $from->toDateString(),
            'history_since' => $historySince !== null
                ? Carbon::parse((string) $historySince, (string) config('app.timezone', 'UTC'))->setTimezone(self::TIMEZONE)->toDateString()
                : null,
            'masked' => $mask->hides(),
            'totals' => [
                'changes' => count($changes),
                'increases' => $increases,
                'decreases' => $decreases,
                'minor_changes' => $minor,
                'products' => count($changedProducts),
                // różne źródła jak w tabeli „sources” (etykieta): grupa „file” resolvera łączy wszystkie cenniki z plików
                'sources' => count($sources),
                'additions' => $additions,
                'discount_changes' => $discounts,
                'suspicious' => count($suspicious),
                ...$this->quartiles($purchasePcts),
            ],
            'weekly' => array_map(
                static fn (string $week, array $counts): array => ['week_start' => $week, 'increases' => $counts[0], 'decreases' => $counts[1], 'minor' => $counts[2]],
                array_keys($weekly),
                array_values($weekly),
            ),
            'sources' => array_map(
                fn (array $s): array => [
                    'source_label' => $s['label'],
                    'changes' => $s['changes'],
                    'increases' => $s['increases'],
                    'decreases' => $s['decreases'],
                    'minor' => $s['minor'],
                    'median_pct' => $this->quartiles($s['purchase'])['median_pct'],
                    'max_pct' => $this->round1($s['max']),
                ],
                array_slice($sources, 0, self::TOP_SOURCES),
            ),
            'manufacturers' => array_map(
                fn (array $m): array => [
                    'manufacturer' => $m['label'],
                    'changes' => $m['changes'],
                    'increases' => $m['increases'],
                    'decreases' => $m['decreases'],
                    'minor' => $m['minor'],
                    'median_pct' => $this->quartiles($m['purchase'])['median_pct'],
                ],
                array_slice($manufacturers, 0, self::TOP_MANUFACTURERS),
            ),
            'top_increases' => array_map(fn (array $m): array => $this->move($m, $products), array_slice($up, 0, self::TOP_MOVES)),
            'top_decreases' => array_map(fn (array $m): array => $this->move($m, $products), array_slice($down, 0, self::TOP_MOVES)),
            'suspicious' => array_map(fn (array $m): array => $this->move($m, $products), array_slice($suspicious, 0, self::TOP_SUSPICIOUS)),
        ];
    }

    /**
     * Stosunek nowa/stara cena na podstawie procentu (zakup albo katalog); null, gdy procentu nie ma.
     *
     * @param  array<string, mixed>  $move
     */
    private function ratio(array $move): ?float
    {
        [$old, $new] = $this->basisPrices($move);
        if ($old === null || $new === null || $old <= 0.0) {
            return null;
        }

        return $new / $old;
    }

    /**
     * @param  array<string, mixed>  $move
     * @return array{0: float|null, 1: float|null} stara i nowa cena podstawy procentu
     */
    private function basisPrices(array $move): array
    {
        return match ($move['pct_basis']) {
            'purchase' => [$move['purchase_old'], $move['purchase_new']],
            'catalog' => [$move['catalog_old'], $move['catalog_new']],
            default => [null, null],
        };
    }

    /**
     * @param  array<string, mixed>  $move
     * @param  array<int, array{sku: string, name: string, manufacturer: string|null}>  $products
     * @return array<string, mixed>
     */
    private function move(array $move, array $products): array
    {
        [$old, $new] = $this->basisPrices($move);
        $product = $products[$move['product_id']] ?? null;

        return [
            'product_id' => (int) $move['product_id'],
            'sku' => $product['sku'] ?? '',
            'name' => $product['name'] ?? '',
            'manufacturer' => $product['manufacturer'] ?? null,
            'source_label' => (string) $move['source_label'],
            'currency' => $move['currency'],
            'old' => round((float) $old, 2),
            'new' => round((float) $new, 2),
            'pct' => round((float) $move['pct'], 1),
            'basis' => $move['pct_basis'],
            'at' => Carbon::parse((string) $move['at'])->toIso8601String(),
        ];
    }

    /**
     * SKU, nazwa i producent kart — jedno zapytanie na porcję id, bez modeli Eloquent.
     *
     * @param  list<int>  $ids
     * @return array<int, array{sku: string, name: string, manufacturer: string|null}>
     */
    private function products(array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), self::IDS_PER_QUERY) as $chunk) {
            foreach (DB::table('products')->select(['id', 'sku', 'name', 'manufacturer'])->whereIn('id', $chunk)->get() as $row) {
                $manufacturer = trim((string) $row->manufacturer);
                $out[(int) $row->id] = [
                    'sku' => (string) $row->sku,
                    'name' => (string) $row->name,
                    'manufacturer' => $manufacturer === '' ? null : $manufacturer,
                ];
            }
        }

        return $out;
    }

    /**
     * Pełna seria tygodni (poniedziałek czasu polskiego) od tygodnia $from do bieżącego.
     *
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    private function emptyWeeks(Carbon $from, Carbon $today): array
    {
        $weeks = [];
        $last = $this->weekStart($today);
        for ($week = $from->copy()->startOfWeek(Carbon::MONDAY); $week->toDateString() <= $last; $week->addWeek()) {
            $weeks[$week->toDateString()] = [0, 0, 0];
        }

        return $weeks;
    }

    private function weekStart(Carbon $at): string
    {
        return $at->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    /**
     * Mediana i kwartyle metodą liniowej interpolacji (jak Excel KWARTYL.PRZEDZ.ZAMK / numpy „linear”):
     * pozycja h = (n − 1) · p w posortowanej liście (od 0), wynik = x[⌊h⌋] + (h − ⌊h⌋) · (x[⌊h⌋+1] − x[⌊h⌋]).
     * Jedna wartość → wszystkie trzy równe jej; pusta lista → null.
     *
     * @param  list<float>  $values
     * @return array{median_pct: float|null, q1_pct: float|null, q3_pct: float|null}
     */
    private function quartiles(array $values): array
    {
        if ($values === []) {
            return ['median_pct' => null, 'q1_pct' => null, 'q3_pct' => null];
        }
        sort($values);
        $at = static function (float $p) use ($values): float {
            $h = (count($values) - 1) * $p;
            $low = (int) floor($h);
            $high = min($low + 1, count($values) - 1);

            return $values[$low] + ($h - $low) * ($values[$high] - $values[$low]);
        };

        return [
            'median_pct' => round($at(0.5), 1),
            'q1_pct' => round($at(0.25), 1),
            'q3_pct' => round($at(0.75), 1),
        ];
    }

    private function round1(?float $value): ?float
    {
        return $value === null ? null : round($value, 1);
    }
}
