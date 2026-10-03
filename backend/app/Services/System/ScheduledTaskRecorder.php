<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Models\ScheduledTaskRun;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;
use WeakMap;

/**
 * Zapis przebiegów zadań harmonogramu (scheduled_task_runs) dla ekranu „Stan systemu” i alertów: before /
 * onSuccess / onFailure dla zadań z config/system_health.php (storeOutput — końcówka komunikatu z pliku wyjścia).
 * Wywoływany ostatnią linią withSchedule w bootstrap/app.php.
 *
 * - Zadanie z runInBackground kończy się w innym procesie (schedule:finish), dlatego id przebiegu (albo chwila
 *   startu zadania częstego) leży w Cache pod $event->mutexName(), nie w pamięci tego obiektu.
 * - Zadania częste (only_failures: co minutę, co 10 minut) zapisują tylko błędy.
 * - Błąd → SystemAlertService::failed (e-mail raz na incydent), udany przebieg → resolved. Błąd zadania częstego
 *   w ciągu system_health.reopen_within_minutes od zamknięcia incydentu otwiera poprzedni incydent (bez nowego
 *   e-maila) — zadanie, które co kilka przebiegów raz się nie uda, nie wysyła e-maila za każdym razem. Zadanie może
 *   mieć własne okno (reopen_within_minutes w jego wpisie, np. tenders:remind 26 h — błąd raz dziennie).
 * - Zadania nocne, które w ogóle nie ruszyły, wykrywa staleNightlyTasks() (wołane przez system:check).
 * - Zapis nigdy nie psuje samego zadania: każdy błąd bazy czy pamięci podręcznej ląduje tylko w logu.
 */
class ScheduledTaskRecorder
{
    private const CACHE_PREFIX = 'system:task-run:';

    /** Chwila pierwszego sprawdzenia zadań nocnych (ISO 8601) — terminy sprzed niej nie są sprawdzane. */
    public const WATCH_SINCE_CACHE_KEY = 'system:tasks-watch-since';

    /** Dłużej niż najdłuższa blokada withoutOverlapping (erp:suggest — 240 min). */
    private const CACHE_TTL_SECONDS = 2 * 86400;

    /** Zdarzenia z już podpiętym zapisem — drugi attach() na tym samym harmonogramie nic nie dubluje. */
    private static ?WeakMap $attached = null;

    public function __construct(private readonly SystemAlertService $alerts) {}

    public function attach(Schedule $schedule): void
    {
        self::$attached ??= new WeakMap;
        foreach ($this->match($schedule->events()) as $key => $event) {
            if (isset(self::$attached[$event])) {
                continue;
            }
            self::$attached[$event] = true;
            $this->attachTo($event, $key);
        }
    }

    /**
     * Zadania z config/system_health.php dopasowane do zdarzeń harmonogramu. Harmonogram powstaje dopiero przy starcie
     * konsoli (withSchedule = Artisan::starting), więc w żądaniu HTTP najpierw uruchamiamy konsolę.
     *
     * @return array<string, Event> po kluczu zadania
     */
    public function scheduledEvents(): array
    {
        app(ConsoleKernel::class)->all();

        return $this->match(app(Schedule::class)->events());
    }

    /**
     * @param  array<int, Event>  $events
     * @return array<string, Event>
     */
    public function match(array $events): array
    {
        $tasks = $this->tasks();
        $byCommand = [];
        foreach (array_keys($tasks) as $key) {
            $byCommand[ConsoleApplication::formatCommandString($key)] = $key;
        }

        $matched = [];
        foreach ($events as $event) {
            $key = null;
            if ($event instanceof CallbackEvent) {
                $key = is_string($event->description) && isset($tasks[$event->description]) ? $event->description : null;
            } elseif (is_string($event->command)) {
                $key = $byCommand[$event->command] ?? null;
            }
            if ($key !== null && ! isset($matched[$key])) {
                $matched[$key] = $event;
            }
        }

        return $matched;
    }

    /**
     * Zadania z config/system_health.php po kluczu (tekst polecenia albo nazwa zadania-funkcji).
     *
     * @return array<string, array{label: string, schedule: string, nightly: bool, only_failures: bool, reopen_within_minutes?: int}>
     */
    public function tasks(): array
    {
        $tasks = config('system_health.tasks', []);

        return is_array($tasks) ? $tasks : [];
    }

    /**
     * Zadania nocne (nightly w config), które nie ruszyły o czasie: od ostatniego planowego terminu (wyrażenie cron
     * zadania w jego strefie) minęło więcej niż system_health.stale_grace_minutes, a w scheduled_task_runs nie ma
     * przebiegu rozpoczętego od tego terminu. Błędne przebiegi zgłasza finished() — tu tylko brak przebiegu.
     *
     * Pomijane: zadania wyłączone warunkiem when() (np. ERP XL wyłączony) i terminy sprzed początku obserwacji
     * (watchSince) — po świeżym wdrożeniu brak historii przebiegów to nie awaria.
     *
     * @return list<array{task: string, label: string, due_at: CarbonImmutable, last_started_at: ?CarbonImmutable}>
     */
    public function staleNightlyTasks(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $grace = max(0, (int) config('system_health.stale_grace_minutes', 120));
        $watchSince = $this->watchSince($now);
        $events = $this->scheduledEvents();

        $due = [];
        foreach ($this->tasks() as $key => $task) {
            $event = $events[$key] ?? null;
            if (! (bool) ($task['nightly'] ?? false) || $event === null || ! $this->filtersPass($event)) {
                continue;
            }
            $at = $this->previousDue($event, $now);
            if ($at === null || $at->lt($watchSince) || $at->addMinutes($grace)->gt($now)) {
                continue;
            }
            $due[$key] = $at;
        }
        if ($due === []) {
            return [];
        }

        // ostatni start każdego zadania: kolumna grupowana + agregat (ONLY_FULL_GROUP_BY)
        $lastStarted = ScheduledTaskRun::query()->toBase()
            ->whereIn('task', array_keys($due))
            ->whereNotNull('started_at')
            ->groupBy('task')
            ->selectRaw('task, max(started_at) as last_started_at')
            ->pluck('last_started_at', 'task')
            ->all();

        $stale = [];
        foreach ($due as $key => $at) {
            $last = isset($lastStarted[$key]) ? CarbonImmutable::parse((string) $lastStarted[$key]) : null;
            // minuta zapasu: start zapisany tuż przed pełną minutą terminu (zegar procesu) to nadal ten przebieg
            if ($last !== null && $last->gte($at->subMinute())) {
                continue;
            }
            $stale[] = [
                'task' => $key,
                'label' => (string) ($this->tasks()[$key]['label'] ?? $key),
                'due_at' => $at,
                'last_started_at' => $last,
            ];
        }

        return $stale;
    }

    /**
     * Od kiedy obserwujemy przebiegi: najwcześniejszy z zapisu w pamięci podręcznej (ustawiany przy pierwszym
     * sprawdzeniu) i najstarszego przebiegu w scheduled_task_runs (przetrwa wyczyszczenie pamięci podręcznej).
     */
    private function watchSince(CarbonImmutable $now): CarbonImmutable
    {
        $moments = [];
        $cached = Cache::get(self::WATCH_SINCE_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            try {
                $moments[] = CarbonImmutable::parse($cached);
            } catch (Throwable) {
                // zepsuty zapis — niżej liczy się baza albo „teraz”
            }
        }
        $first = ScheduledTaskRun::query()->toBase()->whereNotNull('started_at')->min('started_at');
        if ($first !== null) {
            $moments[] = CarbonImmutable::parse((string) $first);
        }
        if ($moments === []) {
            $moments[] = $now;
        }
        $since = array_reduce($moments, static fn (?CarbonImmutable $min, CarbonImmutable $m): CarbonImmutable => $min === null || $m->lt($min) ? $m : $min);
        if ($cached === null) {
            Cache::forever(self::WATCH_SINCE_CACHE_KEY, $since->toIso8601String());
        }

        return $since;
    }

    /** Ostatni planowy termin zadania nie później niż $now (strefa zadania; zadania bez strefy — strefa aplikacji). */
    private function previousDue(Event $event, CarbonImmutable $now): ?CarbonImmutable
    {
        $timezone = $event->timezone;
        $timezone = $timezone instanceof DateTimeZone ? $timezone->getName() : (is_string($timezone) && $timezone !== '' ? $timezone : (string) config('app.timezone', 'UTC'));
        try {
            $date = (new CronExpression($event->getExpression()))->getPreviousRunDate($now->toDateTimeImmutable(), 0, true, $timezone);
        } catch (Throwable) {
            return null;
        }

        return CarbonImmutable::instance($date)->utc();
    }

    private function filtersPass(Event $event): bool
    {
        try {
            return $event->filtersPass(app());
        } catch (Throwable) {
            return false;
        }
    }

    private function attachTo(Event $event, string $key): void
    {
        $event->storeOutput();
        $event->before(function () use ($event, $key): void {
            $this->started($event, $key);
        });
        $event->onSuccess(function () use ($event, $key): void {
            $this->finished($event, $key, true);
        });
        $event->onFailure(function () use ($event, $key): void {
            $this->finished($event, $key, false);
        });
    }

    private function started(Event $event, string $key): void
    {
        try {
            $now = CarbonImmutable::now();
            if ($this->onlyFailures($key)) {
                Cache::put($this->cacheKey($event), ['started_at' => $now->toIso8601String()], self::CACHE_TTL_SECONDS);

                return;
            }
            $run = ScheduledTaskRun::query()->create([
                'task' => $key,
                'started_at' => $now,
                'status' => ScheduledTaskRun::STATUS_RUNNING,
            ]);
            Cache::put($this->cacheKey($event), ['run_id' => $run->id, 'started_at' => $now->toIso8601String()], self::CACHE_TTL_SECONDS);
        } catch (Throwable $e) {
            Log::warning('Scheduled task run start not recorded', ['task' => $key, 'error' => $e->getMessage()]);
        }
    }

    private function finished(Event $event, string $key, bool $ok): void
    {
        try {
            $state = Cache::pull($this->cacheKey($event));
            $state = is_array($state) ? $state : [];
            $now = CarbonImmutable::now();
            $startedAt = isset($state['started_at']) && is_string($state['started_at']) ? CarbonImmutable::parse($state['started_at']) : null;
            $label = (string) ($this->tasks()[$key]['label'] ?? $key);

            if ($ok) {
                if (! $this->onlyFailures($key)) {
                    $this->saveRun($state, $key, $startedAt, $now, ScheduledTaskRun::STATUS_OK, (int) ($event->exitCode ?? 0), $this->outputTail($event));
                }
                $this->alerts->resolved($this->subjectKey($key));

                return;
            }

            $tail = $this->outputTail($event);
            $exception = $this->callbackException($event);
            if ($exception !== null) {
                $tail = trim(($tail ?? '')."\n".$exception);
            }
            $this->saveRun($state, $key, $startedAt, $now, ScheduledTaskRun::STATUS_FAILED, $event->exitCode, $tail !== '' ? $tail : null);
            $this->alerts->failed(
                SystemAlertService::KIND_TASK,
                $this->subjectKey($key),
                'Zadanie: '.$label,
                $this->lastLines($tail, $event->exitCode),
                reopenWithinMinutes: $this->reopenWithinMinutes($key),
            );
        } catch (Throwable $e) {
            Log::warning('Scheduled task run finish not recorded', ['task' => $key, 'error' => $e->getMessage()]);
        }
    }

    /** @param  array<string, mixed>  $state */
    private function saveRun(array $state, string $key, ?CarbonImmutable $startedAt, CarbonImmutable $now, string $status, ?int $exitCode, ?string $tail): void
    {
        $values = [
            'finished_at' => $now,
            'duration_ms' => $startedAt !== null ? max(0, (int) $startedAt->diffInMilliseconds($now, true)) : null,
            'status' => $status,
            'exit_code' => $exitCode,
            'output_tail' => $tail,
        ];
        $run = isset($state['run_id']) ? ScheduledTaskRun::query()->find((int) $state['run_id']) : null;
        if ($run !== null) {
            $run->forceFill($values)->save();

            return;
        }
        ScheduledTaskRun::query()->create(['task' => $key, 'started_at' => $startedAt, ...$values]);
    }

    /** Końcówka pliku wyjścia (storeOutput) — czytana od końca, bez wczytywania całego pliku do pamięci. */
    private function outputTail(Event $event): ?string
    {
        $path = $event->output;
        if (! is_string($path) || $path === '' || $path === $event->getDefaultOutput() || ! is_file($path)) {
            return null;
        }
        $limit = max(200, (int) config('system_health.output_tail_chars', 2000));
        $size = (int) @filesize($path);
        if ($size <= 0) {
            return null;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            // UTF-8: do 4 bajtów na znak — bierzemy zapas, przycinamy do znaków po oczyszczeniu
            $bytes = min($size, $limit * 4);
            fseek($handle, -$bytes, SEEK_END);
            $chunk = (string) fread($handle, $bytes);
        } finally {
            fclose($handle);
        }
        $text = mb_scrub($chunk, 'UTF-8');
        $text = (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $text);
        $text = trim(str_replace("\r\n", "\n", $text));
        if (mb_strlen($text) > $limit) {
            $text = mb_substr($text, -$limit);
        }

        return $text !== '' ? $text : null;
    }

    /** Wyjątek zadania-funkcji (CallbackEvent trzyma go w chronionym polu). */
    private function callbackException(Event $event): ?string
    {
        if (! $event instanceof CallbackEvent) {
            return null;
        }
        $exception = (fn () => $this->exception ?? null)->call($event);

        return $exception instanceof Throwable ? $exception::class.': '.$exception->getMessage() : null;
    }

    /** Komunikat alertu: ostatnie niepuste linie wyjścia albo sam kod wyjścia. */
    private function lastLines(?string $tail, ?int $exitCode): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", (string) $tail)), static fn (string $l): bool => $l !== ''));
        $last = implode("\n", array_slice($lines, -3));

        return $last !== '' ? $last : 'Zadanie zakończyło się błędem (kod wyjścia '.($exitCode ?? 'nieznany').').';
    }

    /**
     * Okno ponownego otwarcia incydentu zadania: własne z config zadania (reopen_within_minutes), inaczej globalne
     * system_health.reopen_within_minutes dla zadań częstych; zadania nocne bez okna. Wpis zadania czytany z tablicy
     * tasks() — klucze zadań mają spacje i „=”, więc nie przez config('system_health.tasks.<klucz>…').
     */
    private function reopenWithinMinutes(string $key): ?int
    {
        $own = $this->tasks()[$key]['reopen_within_minutes'] ?? null;
        if ($own !== null) {
            return max(0, (int) $own);
        }

        return $this->onlyFailures($key) ? max(0, (int) config('system_health.reopen_within_minutes', 360)) : null;
    }

    private function onlyFailures(string $key): bool
    {
        return (bool) ($this->tasks()[$key]['only_failures'] ?? false);
    }

    private function cacheKey(Event $event): string
    {
        return self::CACHE_PREFIX.$event->mutexName();
    }

    public function subjectKey(string $task): string
    {
        return 'task:'.$task;
    }
}
