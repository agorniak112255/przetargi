<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Models\B2bAccount;
use App\Models\B2bSyncRun;
use App\Models\ClientInquiry;
use App\Models\ScheduledTaskRun;
use App\Models\SearchEvent;
use App\Models\SystemAlert;
use App\Services\B2b\B2bConnectorRegistry;
use App\Support\PolishTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Ekran „Stan systemu” (GET /admin/system-status): kafle, alerty, zadania harmonogramu z ostatnim przebiegiem
 * i liczniki „Danych do uzupełnienia”. Same tanie zapytania: ostatni przebieg zadania przez max(id) z GROUP BY task
 * (zgodne z ONLY_FULL_GROUP_BY), agregaty bez grupowania.
 */
class SystemStatusService
{
    public function __construct(
        private readonly ScheduledTaskRecorder $recorder,
        private readonly SystemGaps $gaps,
        private readonly B2bConnectorRegistry $connectors,
    ) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $tasks = $this->tasks();
        $nightly = array_filter($tasks, static fn (array $t): bool => $t['nightly'] && $t['enabled']);
        $finishedOk = array_filter($nightly, static fn (array $t): bool => ($t['last_finished']['status'] ?? null) === ScheduledTaskRun::STATUS_OK);
        $finishedAt = array_filter(array_map(static fn (array $t): ?string => $t['last_finished']['finished_at'] ?? null, $nightly));
        rsort($finishedAt);

        $accounts = $this->activeB2bAccounts();
        $lastSeen = $this->schedulerLastSeen();

        return [
            'checked_at' => now()->toIso8601String(),
            'scheduler' => [
                'last_seen_at' => $lastSeen?->toIso8601String(),
                'ok' => $this->schedulerOk($lastSeen),
            ],
            'tiles' => [
                'tasks' => [
                    'ok' => count($finishedOk),
                    'total' => count($nightly),
                    'last_finished_at' => $finishedAt[0] ?? null,
                ],
                'b2b' => [
                    'ok' => $accounts->where('last_sync_status', B2bSyncRun::STATUS_OK)->count(),
                    'total' => $accounts->count(),
                    'failing' => $accounts->where('last_sync_status', B2bSyncRun::STATUS_FAILED)->count(),
                ],
                'inquiries_queue' => $this->inquiriesQueue(),
                'model' => $this->model(),
            ],
            'alerts' => SystemAlert::query()->open()
                ->orderByDesc('last_failed_at')
                ->orderByDesc('id')
                ->limit(50)
                ->get()
                ->map(fn (SystemAlert $a): array => $this->presentAlert($a))
                ->values()
                ->all(),
            'tasks' => array_values(array_map(static function (array $t): array {
                unset($t['last_finished']);

                return $t;
            }, $tasks)),
            'gaps' => $this->gaps->counts(),
        ];
    }

    /** @return array<string, mixed> */
    public function presentAlert(SystemAlert $alert): array
    {
        return [
            'id' => (int) $alert->id,
            'kind' => (string) $alert->kind,
            'title' => (string) $alert->title,
            'since' => $alert->first_failed_at?->toIso8601String(),
            'last_failed_at' => $alert->last_failed_at?->toIso8601String(),
            'failures' => (int) $alert->failures,
            'last_message' => $alert->last_message,
            'emailed_at' => $alert->emailed_at?->toIso8601String(),
            'muted' => $alert->muted_at !== null,
            'url' => match ($alert->kind) {
                SystemAlertService::KIND_B2B => '/price-lists/b2b',
                default => null,
            },
        ];
    }

    /**
     * Konta dostawców, które mają się synchronizować: z łącznikiem i harmonogramem (jak „off” na dashboardzie).
     *
     * @return Collection<int, B2bAccount>
     */
    public function activeB2bAccounts(): Collection
    {
        return B2bAccount::query()
            ->whereNotNull('connector')
            ->where('connector', '<>', '')
            ->whereNotNull('sync_frequency')
            ->where('sync_frequency', '<>', 'off')
            ->orderBy('id')
            ->get(['id', 'username', 'connector', 'sync_frequency', 'last_sync_status', 'last_sync_finished_at', 'last_sync_message']);
    }

    public function b2bLabel(B2bAccount $account): string
    {
        return $this->connectors->label($account->connector) ?? (string) $account->username;
    }

    public function schedulerLastSeen(): ?CarbonImmutable
    {
        $value = Cache::get(B2bSyncRun::SCHEDULER_HEARTBEAT_KEY);
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    public function schedulerOk(?CarbonImmutable $lastSeen): bool
    {
        $minutes = max(1, (int) config('system_health.scheduler_stale_minutes', 5));

        return $lastSeen !== null && $lastSeen->gte(CarbonImmutable::now()->subMinutes($minutes));
    }

    /**
     * Zadania z config/system_health.php: nocne najpierw (po godzinie startu w czasie polskim), potem częste.
     *
     * @return list<array<string, mixed>>
     */
    private function tasks(): array
    {
        $config = $this->recorder->tasks();
        try {
            $events = $this->recorder->scheduledEvents();
        } catch (Throwable) {
            $events = [];
        }
        $last = $this->latestRuns(false);
        $lastFinished = $this->latestRuns(true);

        $rows = [];
        foreach ($config as $key => $task) {
            $event = $events[$key] ?? null;
            [$schedulePl, $sortMinute] = $this->schedulePl($event, (string) ($task['schedule'] ?? ''));
            $rows[] = [
                'task' => (string) $key,
                'label' => (string) ($task['label'] ?? $key),
                'schedule_pl' => $schedulePl,
                'enabled' => $event !== null && $this->enabled($event),
                'nightly' => (bool) ($task['nightly'] ?? false),
                'only_failures' => (bool) ($task['only_failures'] ?? false),
                'last' => isset($last[$key]) ? $this->presentRun($last[$key]) : null,
                'last_finished' => isset($lastFinished[$key]) ? $this->presentRun($lastFinished[$key]) : null,
                '_sort' => [(bool) ($task['nightly'] ?? false) ? 0 : 1, $sortMinute],
            ];
        }
        $order = array_flip(array_keys($config));
        usort($rows, static fn (array $a, array $b): int => [$a['_sort'], $order[$a['task']]] <=> [$b['_sort'], $order[$b['task']]]);

        return array_map(static function (array $row): array {
            unset($row['_sort']);

            return $row;
        }, $rows);
    }

    /**
     * Ostatni przebieg każdego zadania: max(id) w grupie po `task` (ONLY_FULL_GROUP_BY: tylko kolumna grupowana
     * i agregat), potem wiersze po id.
     *
     * @return array<string, ScheduledTaskRun>
     */
    private function latestRuns(bool $finishedOnly): array
    {
        $ids = ScheduledTaskRun::query()
            ->when($finishedOnly, static fn (Builder $q) => $q->whereNotNull('finished_at'))
            ->selectRaw('max(id) as id')
            ->groupBy('task')
            ->pluck('id')
            ->all();
        if ($ids === []) {
            return [];
        }

        return ScheduledTaskRun::query()
            ->whereIn('id', $ids)
            ->get(['id', 'task', 'started_at', 'finished_at', 'duration_ms', 'status', 'output_tail'])
            ->keyBy('task')
            ->all();
    }

    /** @return array<string, mixed> */
    private function presentRun(ScheduledTaskRun $run): array
    {
        return [
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'duration_ms' => $run->duration_ms,
            'status' => (string) $run->status,
            'output_tail' => $run->output_tail,
        ];
    }

    /**
     * Kiedy zadanie rusza, w czasie polskim: zadania codzienne „codziennie 4:00” z najbliższego uruchomienia
     * (nextRunDate() w strefie zadania → Europe/Warsaw; zadania w UTC przesuwają się przy zmianie czasu), reszta
     * słowami z config. Drugi element = minuta doby do sortowania.
     *
     * @return array{0: string, 1: int}
     */
    private function schedulePl(?Event $event, string $fallback): array
    {
        if ($event !== null && preg_match('/^\d+ \d+ \* \* \*$/', (string) $event->getExpression()) === 1) {
            try {
                $next = CarbonImmutable::instance($event->nextRunDate())->setTimezone(PolishTime::TIMEZONE);

                return ['codziennie '.$next->format('G:i'), $next->hour * 60 + $next->minute];
            } catch (Throwable) {
                // niżej opis z config
            }
        }

        return [$fallback !== '' ? $fallback : 'według harmonogramu', 24 * 60];
    }

    private function enabled(Event $event): bool
    {
        try {
            return $event->filtersPass(app());
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{waiting: int, oldest_wait_seconds: ?int} */
    private function inquiriesQueue(): array
    {
        $row = ClientInquiry::query()
            ->where('analysis_status', ClientInquiry::ANALYSIS_QUEUED)
            ->selectRaw('count(*) as waiting, min(updated_at) as oldest')
            ->toBase()
            ->first();
        $waiting = (int) ($row->waiting ?? 0);
        $oldest = $waiting > 0 && $row->oldest !== null ? CarbonImmutable::parse((string) $row->oldest) : null;

        return [
            'waiting' => $waiting,
            'oldest_wait_seconds' => $oldest !== null ? max(0, (int) $oldest->diffInSeconds(CarbonImmutable::now(), true)) : null,
        ];
    }

    /** @return array{searches_24h: int, avg_seconds: ?float, last_at: ?string, unavailable_24h: int}|null */
    private function model(): ?array
    {
        $since = now()->subDay();
        $row = SearchEvent::query()
            ->whereIn('task', SearchEvent::AI_TASKS)
            ->where('created_at', '>=', $since)
            ->selectRaw("count(*) as cnt, avg(duration_ms) as avg_ms, max(created_at) as last_at, sum(case when model_state = 'unavailable' then 1 else 0 end) as unavailable")
            ->toBase()
            ->first();
        $count = (int) ($row->cnt ?? 0);
        $lastAt = $row->last_at ?? null;
        if ($count === 0) {
            $lastAt = SearchEvent::query()->whereIn('task', SearchEvent::AI_TASKS)->max('created_at');
            if ($lastAt === null) {
                return null;
            }
        }

        return [
            'searches_24h' => $count,
            'avg_seconds' => $count > 0 && $row->avg_ms !== null ? round((float) $row->avg_ms / 1000, 1) : null,
            'last_at' => $lastAt !== null ? CarbonImmutable::parse((string) $lastAt)->toIso8601String() : null,
            'unavailable_24h' => (int) ($row->unavailable ?? 0),
        ];
    }
}
