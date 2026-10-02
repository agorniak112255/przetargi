<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use App\Models\PriceListImport;
use App\Models\User;
use App\Services\B2b\B2bAccountPriceList;
use App\Services\B2b\B2bConnectorRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Raport R3 „Źródła danych”: świeżość kont B2B (przebiegi pobierania cennika) i cenników z plików.
 *
 * Świeżość konta liczona z MAX(finished_at) przebiegów ok (także częściowych) — nie z b2b_accounts.last_sync_*,
 * bo runner ustawia last_sync_finished_at także przy błędzie. W odpowiedzi nie ma loginu ani hasła konta;
 * komunikat przebiegu (może zawierać szczegóły konta) tylko przy b2b_accounts.view. Cache 10 min trzyma pełne
 * dane, filtr uprawnień po odczycie.
 */
final class DataSourcesReport
{
    public const CACHE_KEY = 'reports:sources:v1';

    public const CACHE_SECONDS = 600;

    public const TIMEZONE = 'Europe/Warsaw';

    /** Konto sprawdzane codziennie bez udanego przebiegu dłużej niż tyle godzin = nieświeże. */
    public const DAILY_HOURS = 48;

    /** Konto sprawdzane co tydzień bez udanego przebiegu dłużej niż tyle dni = nieświeże. */
    public const WEEKLY_DAYS = 9;

    /** Cennik z pliku starszy niż tyle dni = stary. */
    public const FILE_OLD_DAYS = 180;

    /** Seria dzienna przebiegów: tyle dni wstecz z dzisiejszym. */
    public const DAYS = 30;

    /** Odsetek powiązań widzianych przez sklep w tylu ostatnich dniach. */
    public const SEEN_DAYS = 7;

    /** Początek komunikatu przebiegu ok przerwanego limitem czasu łącznika (B2bAccountSyncRunner). */
    public const PARTIAL_PREFIX = 'Częściowy:';

    private const DAILY_STATES = ['ok', 'partial', 'failed', 'cancelled', 'interrupted'];

    public function __construct(
        private readonly B2bConnectorRegistry $connectors,
        private readonly B2bAccountPriceList $priceLists,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function build(User $user, array $params = []): array
    {
        /** @var array{generated_at: string, b2b: array<string, mixed>, files: list<array<string, mixed>>} $raw */
        $raw = Cache::remember(self::CACHE_KEY, self::CACHE_SECONDS, fn (): array => $this->compute());

        $seesAccounts = $user->can('b2b_accounts.view');
        $seesPriceLists = $user->can('price_lists.view');

        $b2b = null;
        if ($seesAccounts || $seesPriceLists) {
            $b2b = $raw['b2b'];
            if (! $seesAccounts) {
                foreach (array_keys($b2b['accounts']) as $i) {
                    $b2b['accounts'][$i]['message'] = null;
                }
            }
        }

        return [
            'generated_at' => $raw['generated_at'],
            'thresholds' => [
                'daily_hours' => self::DAILY_HOURS,
                'weekly_days' => self::WEEKLY_DAYS,
                'file_old_days' => self::FILE_OLD_DAYS,
            ],
            'b2b' => $b2b,
            'files' => $seesPriceLists ? $raw['files'] : null,
        ];
    }

    /**
     * @return array{generated_at: string, b2b: array<string, mixed>, files: list<array<string, mixed>>}
     */
    private function compute(): array
    {
        $now = CarbonImmutable::now();

        return [
            'generated_at' => $now->toIso8601String(),
            'b2b' => $this->b2b($now),
            'files' => $this->files($now),
        ];
    }

    /**
     * @return array{totals: array<string, int>, daily: list<array<string, mixed>>, accounts: list<array<string, mixed>>}
     */
    private function b2b(CarbonImmutable $now): array
    {
        $staleBefore = $now->subMinutes(B2bSyncRun::STALE_MINUTES);
        $staleBeforeSql = $staleBefore->utc()->format('Y-m-d H:i:s');

        // bez username/password/connector_session — do raportu idzie tylko etykieta łącznika
        $accounts = B2bAccount::query()
            ->orderBy('id')
            ->get(['id', 'connector', 'sites', 'sync_frequency']);

        // ostatni przebieg konta: MAX(id) w podzapytaniu (ONLY_FULL_GROUP_BY — bez kolumn spoza grupy)
        $lastRuns = DB::table('b2b_sync_runs')
            ->whereIn('id', DB::table('b2b_sync_runs')->selectRaw('MAX(id)')->groupBy('b2b_account_id'))
            ->get(['id', 'b2b_account_id', 'status', 'started_at', 'updated_at', 'message'])
            ->keyBy('b2b_account_id');

        $lastOkAt = DB::table('b2b_sync_runs')
            ->where('status', B2bSyncRun::STATUS_OK)
            ->groupBy('b2b_account_id')
            ->select('b2b_account_id')
            ->selectRaw('MAX(finished_at) AS last_ok_at')
            ->pluck('last_ok_at', 'b2b_account_id');

        $lastOkPrices = DB::table('b2b_sync_runs')
            ->whereIn('id', DB::table('b2b_sync_runs')
                ->where('status', B2bSyncRun::STATUS_OK)
                ->selectRaw('MAX(id)')
                ->groupBy('b2b_account_id'))
            ->pluck('prices_changed', 'b2b_account_id');

        // seria nieudanych od najnowszego: przebiegi po ostatnim „dobrym” (nie failed i nie przeterminowany running)
        $lastGood = DB::table('b2b_sync_runs')
            ->where(static function ($q) use ($staleBeforeSql): void {
                $q->whereNotIn('status', [B2bSyncRun::STATUS_FAILED, B2bSyncRun::STATUS_RUNNING])
                    ->orWhere(static function ($q) use ($staleBeforeSql): void {
                        $q->where('status', B2bSyncRun::STATUS_RUNNING)->where('updated_at', '>=', $staleBeforeSql);
                    });
            })
            ->groupBy('b2b_account_id')
            ->select('b2b_account_id')
            ->selectRaw('MAX(id) AS last_good_id');
        $streaks = DB::table('b2b_sync_runs AS r')
            ->leftJoinSub($lastGood, 'g', 'g.b2b_account_id', '=', 'r.b2b_account_id')
            ->whereRaw('r.id > COALESCE(g.last_good_id, 0)')
            ->groupBy('r.b2b_account_id')
            ->select('r.b2b_account_id')
            ->selectRaw('COUNT(*) AS streak')
            ->pluck('streak', 'b2b_account_id');

        $seenSince = $now->subDays(self::SEEN_DAYS)->utc()->format('Y-m-d H:i:s');
        $links = DB::table('b2b_product_links')
            ->groupBy('b2b_account_id')
            ->select('b2b_account_id')
            ->selectRaw('COUNT(DISTINCT product_id) AS product_count')
            ->selectRaw('COUNT(*) AS link_count')
            ->selectRaw('SUM(CASE WHEN last_seen_at >= ? THEN 1 ELSE 0 END) AS seen_count', [$seenSince])
            ->get()
            ->keyBy('b2b_account_id');

        // 30 dni po started_at (czas polski), stan przebiegu liczony w PHP; długi komunikat nie jest pobierany
        $firstDay = $now->setTimezone(self::TIMEZONE)->startOfDay()->subDays(self::DAYS - 1);
        $daily = [];
        for ($i = 0; $i < self::DAYS; $i++) {
            $daily[$firstDay->addDays($i)->toDateString()] = array_fill_keys(self::DAILY_STATES, 0);
        }
        $runs30 = [];
        $failed30 = [];
        $recent = DB::table('b2b_sync_runs')
            ->where('started_at', '>=', $firstDay->utc()->format('Y-m-d H:i:s'))
            ->select(['b2b_account_id', 'status', 'started_at', 'updated_at'])
            ->selectRaw('CASE WHEN message LIKE ? THEN 1 ELSE 0 END AS is_partial', [self::PARTIAL_PREFIX.'%'])
            ->get();
        foreach ($recent as $run) {
            $accountId = (int) $run->b2b_account_id;
            $state = $this->state((string) $run->status, (int) $run->is_partial === 1, $run->updated_at, $staleBefore);
            $runs30[$accountId] = ($runs30[$accountId] ?? 0) + 1;
            if ($state === 'failed' || $state === 'interrupted') {
                $failed30[$accountId] = ($failed30[$accountId] ?? 0) + 1;
            }
            $day = $this->utc($run->started_at)?->setTimezone(self::TIMEZONE)->toDateString();
            if ($day !== null && $state !== null && isset($daily[$day][$state])) {
                $daily[$day][$state]++;
            }
        }

        $labels = $this->labels($accounts->all());

        $rows = [];
        foreach ($accounts as $account) {
            $id = (int) $account->id;
            $frequency = in_array($account->sync_frequency, B2bAccount::FREQUENCIES, true) ? (string) $account->sync_frequency : 'off';
            $last = $lastRuns->get($id);
            $lastStatus = $last !== null
                ? $this->state(
                    (string) $last->status,
                    str_starts_with(ltrim((string) ($last->message ?? '')), self::PARTIAL_PREFIX),
                    $last->updated_at,
                    $staleBefore,
                )
                : null;
            $okAt = $this->utc($lastOkAt->get($id));
            $hoursSinceOk = $okAt !== null ? round(max(0, $now->getTimestamp() - $okAt->getTimestamp()) / 3600, 1) : null;
            $stale = match ($frequency) {
                'daily' => $hoursSinceOk === null || $hoursSinceOk > self::DAILY_HOURS,
                'weekly' => $hoursSinceOk === null || $hoursSinceOk > self::WEEKLY_DAYS * 24,
                default => false,
            };
            $link = $links->get($id);
            $linkCount = $link !== null ? (int) $link->link_count : 0;
            $lastPrices = $lastOkPrices->get($id);
            $message = $last !== null && $last->message !== null && trim((string) $last->message) !== ''
                ? (string) $last->message
                : null;

            $rows[] = [
                'id' => $id,
                'label' => $labels[$id],
                'connector' => $this->priceLists->connectorKey($account),
                'frequency' => $frequency,
                'last_status' => $lastStatus,
                'last_run_at' => $last !== null ? $this->utc($last->started_at)?->toIso8601String() : null,
                'last_ok_at' => $okAt?->toIso8601String(),
                'hours_since_ok' => $hoursSinceOk,
                'stale' => $stale,
                'failed_streak' => (int) ($streaks->get($id) ?? 0),
                'runs_30d' => $runs30[$id] ?? 0,
                'failed_30d' => $failed30[$id] ?? 0,
                'products' => $link !== null ? (int) $link->product_count : 0,
                'seen_7d_pct' => $linkCount > 0 ? round((int) $link->seen_count * 100 / $linkCount, 1) : null,
                'last_prices_changed' => $lastPrices !== null ? (int) $lastPrices : null,
                'message' => $message,
            ];
        }

        $totals = [
            'accounts' => count($rows),
            'scheduled' => 0,
            'fresh' => 0,
            'stale' => 0,
            'failing' => 0,
            'never_ok' => 0,
        ];
        foreach ($rows as $row) {
            $scheduled = $row['frequency'] !== 'off';
            $totals['scheduled'] += $scheduled ? 1 : 0;
            $totals['fresh'] += $scheduled && ! $row['stale'] ? 1 : 0;
            $totals['stale'] += $row['stale'] ? 1 : 0;
            $totals['failing'] += $this->failing($row) ? 1 : 0;
            $totals['never_ok'] += $row['last_ok_at'] === null ? 1 : 0;
        }

        // najpierw problemy (ostatni przebieg nieudany/przerwany albo nieświeże), potem etykieta
        usort($rows, fn (array $a, array $b): int => [
            $this->failing($a) || $a['stale'] ? 0 : 1,
            mb_strtolower($a['label']),
            $a['id'],
        ] <=> [
            $this->failing($b) || $b['stale'] ? 0 : 1,
            mb_strtolower($b['label']),
            $b['id'],
        ]);

        $series = [];
        foreach ($daily as $day => $counts) {
            $series[] = ['day' => $day, ...$counts];
        }

        return ['totals' => $totals, 'daily' => $series, 'accounts' => $rows];
    }

    /**
     * Cenniki z co najmniej jednym importem pliku; dane z najnowszego importu pliku (created_at, potem id).
     * Importów z pliku jest mało (setki) — pobierane lekkie kolumny i składane w PHP, bez GROUP BY.
     *
     * @return list<array<string, mixed>>
     */
    private function files(CarbonImmutable $now): array
    {
        $since12m = $now->subMonthsNoOverflow(12)->utc()->format('Y-m-d H:i:s');
        $imports = DB::table('price_list_imports')
            ->where('source', PriceListImport::SOURCE_FILE)
            ->orderBy('price_list_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'price_list_id', 'version', 'created_at', 'rows_total', 'prices_changed', 'rows_skipped']);
        if ($imports->isEmpty()) {
            return [];
        }

        $byList = [];
        foreach ($imports as $import) {
            $listId = (int) $import->price_list_id;
            $entry = $byList[$listId] ?? ['last' => null, 'imports_12m' => 0];
            if ($import->created_at !== null && (string) $import->created_at >= $since12m) {
                $entry['imports_12m']++;
            }
            // posortowane rosnąco — ostatni wiersz grupy to najnowszy import
            $entry['last'] = $import;
            $byList[$listId] = $entry;
        }

        $lists = DB::table('price_lists')
            ->whereIn('id', array_keys($byList))
            ->get(['id', 'manufacturer', 'suggested_prices'])
            ->keyBy('id');

        $today = CarbonImmutable::parse($now->setTimezone(self::TIMEZONE)->toDateString(), 'UTC');
        $rows = [];
        foreach ($byList as $listId => $entry) {
            $list = $lists->get($listId);
            if ($list === null) {
                continue;
            }
            $last = $entry['last'];
            $at = $this->utc($last->created_at);
            $daysAgo = null;
            if ($at !== null) {
                // dni kalendarzowe w czasie polskim (bez przesunięcia o godzinę przy zmianie czasu)
                $importDay = CarbonImmutable::parse($at->setTimezone(self::TIMEZONE)->toDateString(), 'UTC');
                $daysAgo = intdiv($today->getTimestamp() - $importDay->getTimestamp(), 86400);
            }
            $rows[] = [
                'price_list_id' => $listId,
                'manufacturer' => (string) $list->manufacturer,
                'version' => $last->version !== null && trim((string) $last->version) !== '' ? (string) $last->version : null,
                'last_import_at' => $at?->toIso8601String(),
                'days_ago' => $daysAgo,
                'imports_12m' => $entry['imports_12m'],
                'rows_total' => $last->rows_total !== null ? (int) $last->rows_total : null,
                'prices_changed' => $last->prices_changed !== null ? (int) $last->prices_changed : null,
                'rows_skipped' => $last->rows_skipped !== null ? (int) $last->rows_skipped : null,
                'suggested' => (bool) $list->suggested_prices,
                'old' => $daysAgo !== null && $daysAgo > self::FILE_OLD_DAYS,
            ];
        }

        // najstarsze na górze; brak daty na końcu
        usort($rows, static fn (array $a, array $b): int => [$b['days_ago'] ?? -1, mb_strtolower($a['manufacturer']), $a['price_list_id']]
            <=> [$a['days_ago'] ?? -1, mb_strtolower($b['manufacturer']), $b['price_list_id']]);

        return $rows;
    }

    /**
     * Stan przebiegu: ok z komunikatem „Częściowy:” → partial; running bez sygnału życia (updated_at) dłużej niż
     * B2bSyncRun::STALE_MINUTES → interrupted. Status spoza znanych → null (nie zgadujemy).
     */
    private function state(string $status, bool $partial, mixed $updatedAt, CarbonImmutable $staleBefore): ?string
    {
        return match ($status) {
            B2bSyncRun::STATUS_OK => $partial ? 'partial' : 'ok',
            B2bSyncRun::STATUS_FAILED => 'failed',
            B2bSyncRun::STATUS_CANCELLED => 'cancelled',
            B2bSyncRun::STATUS_RUNNING => ($at = $this->utc($updatedAt)) === null || $at->lessThan($staleBefore)
                ? 'interrupted'
                : 'running',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function failing(array $row): bool
    {
        return in_array($row['last_status'], ['failed', 'interrupted'], true);
    }

    /**
     * Etykieta konta: nazwa łącznika z rejestru (jak Cenniki — łącznik konta albo rozpoznany po stronach);
     * gdy kilka kont ma tę samą etykietę albo łącznika brak — dopisany numer konta. Nigdy login.
     *
     * @param  list<B2bAccount>  $accounts
     * @return array<int, string>
     */
    private function labels(array $accounts): array
    {
        $base = [];
        foreach ($accounts as $account) {
            $base[(int) $account->id] = $this->connectors->label($this->priceLists->connectorKey($account));
        }
        $counts = array_count_values(array_map(static fn (?string $label): string => (string) $label, $base));

        $out = [];
        foreach ($base as $id => $label) {
            $out[$id] = match (true) {
                $label === null || $label === '' => 'Konto #'.$id,
                $counts[$label] > 1 => $label.' · #'.$id,
                default => $label,
            };
        }

        return $out;
    }

    /** Znacznik czasu z bazy (UTC, „Y-m-d H:i:s”) jako CarbonImmutable; null dla pustego. */
    private function utc(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $value, 'UTC');
    }
}
