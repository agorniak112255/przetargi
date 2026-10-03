<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Mail\AppNotificationMail;
use App\Models\User;
use App\Notifications\AppNotification;
use App\Services\MailSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Wspólna wysyłka powiadomień: dzwonek (kanał database) i e-mail według preferencji użytkownika, raz na
 * (użytkownik, zdarzenie, rzecz, okres) — tabela notification_dispatches.
 *
 * E-mail idzie od razu (bez kolejki), jak TenderInvitationMail. Przed pierwszym e-mailem (najwyżej raz na minutę
 * w jednym obiekcie) ustawienia poczty są czytane z panelu na nowo (MailSettingsService::applyToConfig) — proces
 * kolejki żyje długo i bez tego wysyłałby ze starych ustawień SMTP. Błąd SMTP nie przerywa wysyłki do pozostałych
 * osób — trafia do logu (report) i do wyniku jako mail = false. W żądaniach HTTP wołaj przez defer(), żeby
 * odpowiedź nie czekała na SMTP.
 *
 * Powiadomienie z okresem: dzwonek dokładnie raz, a e-mail po błędzie poczty czeka w notification_dispatches
 * (mail_status = pending) i idzie znowu, gdy nadawca zgłosi to samo powiadomienie w kolejnym przebiegu —
 * z rosnącą przerwą, najwyżej notifications.mail_retry.max_attempts prób. Bez okresu nie ma ani ochrony przed
 * powtórką, ani ponawiania.
 */
class NotificationDispatcher
{
    public const MAIL_PENDING = 'pending';

    public const MAIL_SENDING = 'sending';

    public const MAIL_SENT = 'sent';

    public const MAIL_FAILED = 'failed';

    private const MAIL_CONFIG_REFRESH_SECONDS = 60;

    private ?float $mailConfigAppliedAt = null;

    public function __construct(
        private readonly NotificationPreferences $preferences,
        private readonly MailSettingsService $mailSettings,
    ) {}

    /**
     * @param  User|iterable<User>  $users
     * @param  ?string  $period  klucz okresu chroniący przed powtórką (np. „2026-10-05:7d”); null = bez ochrony
     * @return array<int, array{bell: bool, mail: ?bool}> po id użytkownika; mail null = nie chce, false = błąd wysyłki.
     *                                                    Ponowiony e-mail ma bell = false (dzwonek już był).
     *                                                    Osoby, do których to samo już wyszło w tym okresie (i nie
     *                                                    czeka e-mail do ponowienia), i osoby, których zdarzenie nie
     *                                                    dotyczy, nie mają wpisu.
     */
    public function send(User|iterable $users, AppNotificationMessage $message, ?string $period = null): array
    {
        $results = [];
        foreach ($users instanceof User ? [$users] : $users as $user) {
            if (! $user instanceof User || array_key_exists((int) $user->id, $results)) {
                continue;
            }
            if (! $this->preferences->available($user, $message->event)) {
                continue;
            }
            $bell = $this->preferences->wants($user, $message->event, 'bell');
            $mail = $this->preferences->wants($user, $message->event, 'mail') && trim((string) $user->email) !== '';
            if (! $bell && ! $mail) {
                $results[(int) $user->id] = ['bell' => false, 'mail' => null];

                continue;
            }

            if ($period === null) {
                $results[(int) $user->id] = [
                    'bell' => $bell && $this->bell($user, $message),
                    'mail' => $mail ? $this->mail($user, $message) : null,
                ];

                continue;
            }

            if ($this->claim($user, $message, $period, $mail)) {
                $bellSent = $bell && $this->bell($user, $message);
                $mailSent = null;
                if ($mail) {
                    $mailSent = $this->mail($user, $message);
                    $this->recordMail($user, $message, $period, $mailSent, 1);
                }
                $results[(int) $user->id] = ['bell' => $bellSent, 'mail' => $mailSent];

                continue;
            }

            // już powiadomiony w tym okresie (poprzedni przebieg albo równoległy proces) — wolno tylko ponowić
            // e-mail, który wcześniej nie wyszedł
            $attempt = $mail ? $this->claimRetry($user, $message, $period) : null;
            if ($attempt !== null) {
                $mailSent = $this->mail($user, $message);
                $this->recordMail($user, $message, $period, $mailSent, $attempt);
                $results[(int) $user->id] = ['bell' => false, 'mail' => $mailSent];
            }
        }

        return $results;
    }

    /**
     * Rzeczy (subject_key) zdarzenia, których e-mail czeka na ponowienie albo utknął w wysyłce — np. alerty systemu,
     * które system:check zgłasza ponownie po naprawie poczty.
     *
     * @return list<string>
     */
    public function pendingMailSubjects(string $event): array
    {
        return DB::table('notification_dispatches')
            ->where('event', mb_substr($event, 0, 40))
            ->whereIn('mail_status', [self::MAIL_PENDING, self::MAIL_SENDING])
            ->distinct()
            ->orderBy('subject_key')
            ->pluck('subject_key')
            ->map(static fn (mixed $key): string => (string) $key)
            ->all();
    }

    private function bell(User $user, AppNotificationMessage $message): bool
    {
        try {
            $user->notifyNow(new AppNotification($message));

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * Wpis ochrony przed powtórką; false = ten wpis już był (0 wstawionych wierszy). Chciany e-mail jest od razu
     * „w wysyłce” (pierwsza próba), żeby równoległy proces nie uznał go za czekający na ponowienie.
     */
    private function claim(User $user, AppNotificationMessage $message, string $period, bool $mail): bool
    {
        return DB::table('notification_dispatches')->insertOrIgnore([
            'user_id' => $user->id,
            'event' => mb_substr($message->event, 0, 40),
            'subject_key' => mb_substr($message->subjectKey, 0, 80),
            'period_key' => mb_substr($period, 0, 40),
            'mail_status' => $mail ? self::MAIL_SENDING : null,
            'mail_attempts' => $mail ? 1 : 0,
            'mail_attempted_at' => $mail ? now() : null,
            'created_at' => now(),
        ]) > 0;
    }

    /**
     * Zajęcie kolejnej próby e-maila, który wcześniej nie wyszedł: gdy minęła przerwa po ostatniej próbie (albo
     * wysyłka „w toku” utknęła), a limit prób jeszcze nie. Warunkowa zmiana wiersza (status i licznik jak przy
     * odczycie) — dwa równoległe procesy nie wyślą tego samego. Zwraca numer próby albo null.
     */
    private function claimRetry(User $user, AppNotificationMessage $message, string $period): ?int
    {
        $row = $this->row($user, $message, $period)->first(['id', 'mail_status', 'mail_attempts', 'mail_attempted_at']);
        if ($row === null) {
            return null;
        }
        $status = is_string($row->mail_status) ? $row->mail_status : null;
        $attempts = (int) $row->mail_attempts;
        if (! in_array($status, [self::MAIL_PENDING, self::MAIL_SENDING], true) || $attempts >= $this->maxAttempts()) {
            return null;
        }
        $last = $row->mail_attempted_at !== null ? CarbonImmutable::parse((string) $row->mail_attempted_at) : null;
        $wait = $status === self::MAIL_SENDING
            ? max(1, (int) config('notifications.mail_retry.stuck_minutes', 30))
            : $this->delayMinutes($attempts);
        if ($last !== null && $last->addMinutes($wait)->isFuture()) {
            return null;
        }

        $taken = DB::table('notification_dispatches')
            ->where('id', $row->id)
            ->where('mail_status', $status)
            ->where('mail_attempts', $attempts)
            ->update([
                'mail_status' => self::MAIL_SENDING,
                'mail_attempts' => $attempts + 1,
                'mail_attempted_at' => now(),
            ]);

        return $taken === 1 ? $attempts + 1 : null;
    }

    /** Wynik próby e-maila: wysłany, czeka na ponowienie albo — po ostatniej dozwolonej próbie — poddany. */
    private function recordMail(User $user, AppNotificationMessage $message, string $period, bool $sent, int $attempt): void
    {
        try {
            $this->row($user, $message, $period)->update([
                'mail_status' => $sent ? self::MAIL_SENT : ($attempt >= $this->maxAttempts() ? self::MAIL_FAILED : self::MAIL_PENDING),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function row(User $user, AppNotificationMessage $message, string $period): Builder
    {
        return DB::table('notification_dispatches')
            ->where('user_id', $user->id)
            ->where('event', mb_substr($message->event, 0, 40))
            ->where('subject_key', mb_substr($message->subjectKey, 0, 80))
            ->where('period_key', mb_substr($period, 0, 40));
    }

    private function maxAttempts(): int
    {
        return max(1, (int) config('notifications.mail_retry.max_attempts', 7));
    }

    /** Przerwa po `attempts` nieudanych próbach: first_delay_minutes × 2^(attempts − 1). */
    private function delayMinutes(int $attempts): int
    {
        $first = max(1, (int) config('notifications.mail_retry.first_delay_minutes', 15));

        return $first * (2 ** max(0, min($attempts - 1, 10)));
    }

    private function mail(User $user, AppNotificationMessage $message): bool
    {
        $this->refreshMailConfig();
        // własny e-mail (np. zaproszenie) klonujemy: Mail::to() dopisuje adresata do obiektu, a ten sam obiekt
        // poszedłby do kolejnej osoby z adresami poprzednich
        $mailable = $message->mailable !== null ? clone $message->mailable : new AppNotificationMail($message, $user);

        try {
            Mail::to((string) $user->email)->send($mailable);

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** Aktualne ustawienia SMTP z panelu (Administracja › Poczta) — najwyżej raz na minutę w tym obiekcie. */
    private function refreshMailConfig(): void
    {
        $now = microtime(true);
        if ($this->mailConfigAppliedAt !== null && $now - $this->mailConfigAppliedAt < self::MAIL_CONFIG_REFRESH_SECONDS) {
            return;
        }
        $this->mailConfigAppliedAt = $now;
        try {
            $this->mailSettings->applyToConfig();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
