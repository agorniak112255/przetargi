<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bSyncRun;
use App\Models\SystemAlert;
use App\Services\System\ScheduledTaskRecorder;
use App\Services\System\SystemAlertService;
use App\Services\System\SystemStatusService;
use App\Support\PolishTime;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sprawdzanie stanu systemu co 10 minut i alerty dla administratora (e-mail raz na incydent):
 * - konto dostawcy z harmonogramem, którego ostatni przebieg się nie udał → alert; udany przebieg, wyłączenie konta
 *   albo jego usunięcie → incydent zamknięty. Błąd starszy niż system_health.b2b_mail_max_age_hours (np. zastany
 *   przy pierwszym sprawdzeniu po wdrożeniu) zakłada incydent bez e-maila;
 * - brak sygnału harmonogramu (b2b-scheduler-heartbeat) dłużej niż system_health.scheduler_stale_minutes → alert;
 * - zadania nocne, które nie ruszyły o czasie (ScheduledTaskRecorder::staleNightlyTasks) → jeden wspólny alert,
 *   sprawdzane tylko przy działającym harmonogramie (martwy harmonogram ma własny alert, bez lawiny e-maili);
 * - e-maile alertów, które nie wyszły przez błąd poczty, idą znowu (SystemAlertService::retryPendingMail).
 * Błędy zadań nocnych zgłasza sam ScheduledTaskRecorder w chwili błędu. Martwego crona (schedule:run) nie da się
 * wykryć od środka — to polecenie też by wtedy nie ruszyło.
 */
final class SystemCheckCommand extends Command
{
    protected $signature = 'system:check';

    protected $description = 'Sprawdza stan systemu i wysyła alert administratorowi, gdy zadanie nocne albo konto dostawcy przestanie działać';

    private const SCHEDULER_KEY = 'scheduler';

    /** Jeden incydent na wszystkie zadania nocne, które nie ruszyły (po awarii crona — jeden e-mail, nie kilkanaście). */
    public const STALE_KEY = 'stale-tasks';

    public function handle(SystemStatusService $status, SystemAlertService $alerts, ScheduledTaskRecorder $recorder): int
    {
        $failing = 0;
        $failingKeys = [];
        $accounts = $status->activeB2bAccounts();
        $mailMaxAge = CarbonImmutable::now()->subHours(max(1, (int) config('system_health.b2b_mail_max_age_hours', 48)));
        foreach ($accounts as $account) {
            $key = 'b2b:'.$account->id;
            if ($account->last_sync_status === B2bSyncRun::STATUS_FAILED) {
                $failingKeys[] = $key;
                $failing++;
                $failedAt = $account->last_sync_finished_at;
                $alerts->failed(
                    SystemAlertService::KIND_B2B,
                    $key,
                    'Konto '.$status->b2bLabel($account).' · pobieranie cen',
                    $account->last_sync_message !== null ? (string) $account->last_sync_message : 'Ostatnie pobieranie z konta dostawcy się nie udało.',
                    $failedAt,
                    notify: $failedAt === null || CarbonImmutable::instance($failedAt)->gte($mailMaxAge),
                );
            }
        }
        // konto znowu działa, wyłączone albo usunięte — incydent zamknięty (przebieg w toku zostawia go otwartym)
        $resolved = 0;
        $running = $accounts
            ->where('last_sync_status', B2bSyncRun::STATUS_RUNNING)
            ->map(static fn ($a): string => 'b2b:'.$a->id)
            ->all();
        $open = SystemAlert::query()->open()->where('kind', SystemAlertService::KIND_B2B)->pluck('subject_key')->unique();
        foreach ($open as $key) {
            if (! in_array($key, $failingKeys, true) && ! in_array($key, $running, true)) {
                $resolved += $alerts->resolved((string) $key);
            }
        }

        $lastSeen = $status->schedulerLastSeen();
        $schedulerOk = $status->schedulerOk($lastSeen);
        $stale = null;
        if ($schedulerOk) {
            $resolved += $alerts->resolved(self::SCHEDULER_KEY);
            $stale = $this->checkStaleTasks($recorder, $alerts, $resolved);
        } else {
            $alerts->failed(
                SystemAlertService::KIND_SCHEDULER,
                self::SCHEDULER_KEY,
                'Harmonogram zadań nie działa',
                $lastSeen !== null
                    ? 'Ostatni sygnał harmonogramu: '.PolishTime::format($lastSeen).'. Sprawdź zadanie cron „schedule:run” na serwerze.'
                    : 'Brak sygnału harmonogramu. Sprawdź zadanie cron „schedule:run” na serwerze.',
            );
        }

        $retried = $alerts->retryPendingMail();

        $this->info(sprintf(
            'Konta dostawców z błędem: %d. Zadania nocne, które nie ruszyły: %s. Zamknięte incydenty: %d. Alerty z e-mailem czekającym na ponowienie: %d. Harmonogram: %s.',
            $failing,
            $stale === null ? 'nie sprawdzano' : (string) $stale,
            $resolved,
            $retried,
            $schedulerOk ? 'działa' : 'brak sygnału',
        ));

        return self::SUCCESS;
    }

    /** Liczba zadań nocnych, które nie ruszyły; null = sprawdzenie się nie udało (incydent zostaje, jak był). */
    private function checkStaleTasks(ScheduledTaskRecorder $recorder, SystemAlertService $alerts, int &$resolved): ?int
    {
        try {
            $stale = $recorder->staleNightlyTasks();
        } catch (Throwable $e) {
            report($e);

            return null;
        }
        if ($stale === []) {
            $resolved += $alerts->resolved(self::STALE_KEY);

            return 0;
        }

        $lines = [];
        $latestDue = null;
        foreach ($stale as $task) {
            $lines[] = sprintf(
                '%s — planowo %s, ostatni przebieg: %s.',
                $task['label'],
                PolishTime::format($task['due_at']),
                $task['last_started_at'] !== null ? PolishTime::format($task['last_started_at']) : 'brak zapisanego',
            );
            $latestDue = $latestDue === null || $task['due_at']->gt($latestDue) ? $task['due_at'] : $latestDue;
        }
        $lines[] = 'Możliwe przyczyny: poprzedni przebieg wciąż trwa albo został przerwany (blokada przed nakładaniem się), zadanie zostało wyłączone albo serwer w tym czasie nie działał.';

        $alerts->failed(
            SystemAlertService::KIND_STALE,
            self::STALE_KEY,
            count($stale) === 1 ? 'Zadanie nocne nie ruszyło o czasie: '.$stale[0]['label'] : 'Zadania nocne nie ruszyły o czasie ('.count($stale).')',
            implode("\n", $lines),
            // chwila = najpóźniejszy pominięty termin: to samo zgłoszenie co 10 minut nie zwiększa licznika
            $latestDue,
        );

        return count($stale);
    }
}
