<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Models\SystemAlert;
use App\Models\User;
use App\Services\Notifications\AppNotificationMessage;
use App\Services\Notifications\NotificationDispatcher;
use App\Support\PolishTime;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Alerty „Stanu systemu”: jeden otwarty wiersz system_alerts na incydent (subject_key, resolved_at null).
 *
 * - failed(): pierwszy błąd zakłada incydent, kolejne tylko go aktualizują; e-mail (zdarzenie system_alert przez
 *   NotificationDispatcher, odbiorcy z uprawnieniami admin.access i admin.system.view) wychodzi raz na incydent
 *   i nie wychodzi, gdy incydent jest wyciszony albo zgłaszający prosi o incydent bez e-maila (notify: false —
 *   np. stary błąd konta dostawcy przy pierwszym sprawdzeniu po wdrożeniu).
 * - reopenWithinMinutes: błąd tuż po zamknięciu incydentu tej samej rzeczy otwiera poprzedni incydent na nowo
 *   (licznik rośnie, bez nowego e-maila) — zadania częste, które raz działają, raz nie, nie „mrugają” e-mailami.
 * - resolved(): zamyka otwarty incydent — następny błąd (poza oknem ponownego otwarcia) to nowy incydent i nowy
 *   e-mail.
 * - retryPendingMail(): e-mail incydentu, który nie wyszedł przez błąd poczty, idzie znowu (system:check co 10
 *   minut; przerwy i limit prób w NotificationDispatcher).
 * - mute()/unmute(): wyciszenie e-maila dla bieżącego incydentu.
 */
class SystemAlertService
{
    public const KIND_TASK = 'task';

    public const KIND_B2B = 'b2b';

    public const KIND_SCHEDULER = 'scheduler';

    /** Zadanie nocne nie ruszyło o czasie (system:check). */
    public const KIND_STALE = 'stale';

    public const EVENT = 'system_alert';

    public const PERMISSION = 'admin.system.view';

    /** Odbiorca musi mieć oba: ekran „Stan systemu” leży w Administracji (admin.access). */
    public const RECIPIENT_PERMISSIONS = ['admin.access', self::PERMISSION];

    public const STATUS_URL = '/admin/stan-systemu';

    public function __construct(private readonly NotificationDispatcher $dispatcher) {}

    /**
     * Błąd rzeczy `subjectKey` (np. „task:erp:clients”, „b2b:12”). `at` = chwila błędu ze źródła (np. koniec
     * nieudanego przebiegu konta) — ten sam błąd zgłoszony drugi raz (system:check co 10 minut) nie zwiększa licznika
     * (tytuł i komunikat się odświeżają). `notify` false = incydent bez e-maila teraz (kolejne zgłoszenie
     * z notify true go wyśle). `reopenWithinMinutes` = okno ponownego otwarcia świeżo zamkniętego incydentu.
     */
    public function failed(
        string $kind,
        string $subjectKey,
        string $title,
        ?string $message,
        ?DateTimeInterface $at = null,
        bool $notify = true,
        ?int $reopenWithinMinutes = null,
    ): SystemAlert {
        $moment = $at !== null ? CarbonImmutable::instance($at) : CarbonImmutable::now();
        $message = $message !== null ? mb_substr(trim($message), 0, 2000) : null;
        $message = $message === '' ? null : $message;

        $alert = SystemAlert::query()->open()->where('subject_key', $subjectKey)->orderByDesc('id')->first();
        if ($alert === null && $reopenWithinMinutes !== null && $reopenWithinMinutes > 0) {
            $alert = SystemAlert::query()
                ->where('subject_key', $subjectKey)
                ->whereNotNull('resolved_at')
                ->where('resolved_at', '>=', CarbonImmutable::now()->subMinutes($reopenWithinMinutes))
                ->orderByDesc('id')
                ->first();
            $alert?->forceFill(['resolved_at' => null])->save();
            if ($alert !== null && $alert->emailed_at === null) {
                // e-mail poddany przy zamknięciu (resolved) — incydent wrócił, więc znowu czeka na ponowienie
                $this->dispatcher->resumeAbandonedMail(self::EVENT, ['system_alert:'.$alert->id]);
            }
        }
        if ($alert === null) {
            $alert = SystemAlert::query()->create([
                'kind' => $kind,
                'subject_key' => $subjectKey,
                'title' => mb_substr($title, 0, 255),
                'first_failed_at' => $moment,
                'last_failed_at' => $moment,
                'failures' => 1,
                'last_message' => $message,
            ]);
        } elseif ($at === null || $alert->last_failed_at === null || $moment->gt($alert->last_failed_at)) {
            $alert->forceFill([
                'title' => mb_substr($title, 0, 255),
                'last_failed_at' => $moment,
                'failures' => (int) $alert->failures + 1,
                'last_message' => $message ?? $alert->last_message,
            ])->save();
        } else {
            // ten sam błąd zgłoszony znowu: bez licznika, ale z aktualnym opisem (np. lista zadań, które nie ruszyły)
            $alert->forceFill([
                'title' => mb_substr($title, 0, 255),
                'last_message' => $message ?? $alert->last_message,
            ]);
            if ($alert->isDirty()) {
                $alert->save();
            }
        }

        if ($notify && $alert->emailed_at === null && $alert->muted_at === null) {
            $this->notify($alert);
        }

        return $alert;
    }

    /**
     * Ponowienie e-maili otwartych, niewyciszonych incydentów, których e-mail nie wyszedł przez błąd poczty
     * (wpis wysyłki czeka na ponowienie; czy próba już teraz — decyduje przerwa w NotificationDispatcher). Zwraca,
     * ilu incydentów to dotyczyło.
     */
    public function retryPendingMail(): int
    {
        $ids = [];
        foreach ($this->dispatcher->pendingMailSubjects(self::EVENT) as $subject) {
            if (preg_match('/^system_alert:(\d+)$/', $subject, $m) === 1) {
                $ids[] = (int) $m[1];
            }
        }
        if ($ids === []) {
            return 0;
        }
        $alerts = SystemAlert::query()->open()->whereNull('muted_at')->whereIn('id', $ids)->orderBy('id')->get();
        foreach ($alerts as $alert) {
            $this->notify($alert);
        }

        return $alerts->count();
    }

    /**
     * Zamyka otwarty incydent; zwraca, ile zamknięto (0 = nic nie było otwarte). E-mail zamkniętego incydentu, który
     * czekał na ponowienie po błędzie poczty, już nie wyjdzie (wpis wysyłki poddany). Wyciszenie tego nie robi —
     * po cofnięciu wyciszenia e-mail ma móc wyjść.
     */
    public function resolved(string $subjectKey): int
    {
        $ids = SystemAlert::query()->open()->where('subject_key', $subjectKey)->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        if ($ids === []) {
            return 0;
        }
        $closed = SystemAlert::query()->open()->whereIn('id', $ids)->update([
            'resolved_at' => now(),
            'updated_at' => now(),
        ]);
        try {
            $this->dispatcher->abandonPendingMail(self::EVENT, array_map(static fn (int $id): string => 'system_alert:'.$id, $ids));
        } catch (Throwable $e) {
            Log::warning('Pending system alert mail not abandoned', ['alert_ids' => $ids, 'error' => $e->getMessage()]);
        }

        return $closed;
    }

    public function mute(SystemAlert $alert, ?User $by): SystemAlert
    {
        $alert->forceFill(['muted_at' => now(), 'muted_by' => $by?->id])->save();

        return $alert;
    }

    public function unmute(SystemAlert $alert): SystemAlert
    {
        $alert->forceFill(['muted_at' => null, 'muted_by' => null])->save();

        return $alert;
    }

    /** @return Collection<int, User> */
    public function recipients(): Collection
    {
        $query = User::query();
        foreach (self::RECIPIENT_PERMISSIONS as $permission) {
            $query->where(static function (Builder $q) use ($permission): void {
                $q->whereHas('permissions', static fn (Builder $p) => $p->where('name', $permission))
                    ->orWhereHas('roles.permissions', static fn (Builder $p) => $p->where('name', $permission));
            });
        }

        return $query->orderBy('id')->get();
    }

    /**
     * Dzwonek i e-mail przez wspólną wysyłkę. Okres = id incydentu, więc nawet dwa równoległe procesy nie wyślą
     * go dwa razy. emailed_at tylko, gdy choć jeden e-mail naprawdę wyszedł (błąd poczty ≠ „wysłany”).
     */
    private function notify(SystemAlert $alert): void
    {
        $users = $this->recipients();
        if ($users->isEmpty()) {
            return;
        }

        $since = $alert->first_failed_at !== null ? PolishTime::format($alert->first_failed_at) : '';
        $body = trim(($since !== '' ? 'Od '.$since.'. ' : '').($alert->last_message ?? ''));

        try {
            $results = $this->dispatcher->send($users, new AppNotificationMessage(
                event: self::EVENT,
                subjectKey: 'system_alert:'.$alert->id,
                title: $alert->title,
                body: $body !== '' ? $body : 'Szczegóły na ekranie „Stan systemu”.',
                url: self::STATUS_URL,
                data: ['alert_id' => $alert->id, 'kind' => $alert->kind],
            ), 'incident:'.$alert->id);
        } catch (Throwable $e) {
            Log::warning('System alert notification failed', ['alert_id' => $alert->id, 'error' => $e->getMessage()]);

            return;
        }

        foreach ($results as $result) {
            if (($result['mail'] ?? null) === true) {
                if ($alert->emailed_at === null) {
                    $alert->forceFill(['emailed_at' => now()])->save();
                }

                return;
            }
        }
    }
}
