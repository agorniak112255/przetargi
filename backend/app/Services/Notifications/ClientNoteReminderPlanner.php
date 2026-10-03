<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\ClientNote;
use App\Models\User;
use App\Support\PolishTime;
use Carbon\CarbonImmutable;

/**
 * Przypomnienia z notatek o klientach (client_note_reminder): do autora notatki w dniu remind_on, od godziny
 * daily_from czasu polskiego; subjectKey client_note:{id}, okres = remind_on; po wysyłce reminded_at.
 *
 * Dzień, w którym przypomnienie nie wyszło (np. serwer stał), jest nadrabiany najwyżej CATCH_UP_DAYS dni później —
 * notatka ma jeden dzień przypomnienia, więc nie ma czego zalewać. Nieudany e-mail nie ustawia reminded_at:
 * planer zgłasza przypomnienie dalej, a NotificationDispatcher ponawia sam e-mail (dzwonek był już raz).
 * Notatka bez autora (konto usunięte) nie ma komu przypomnieć.
 */
class ClientNoteReminderPlanner implements ReminderPlanner
{
    public const CATCH_UP_DAYS = 7;

    private const BODY_PREVIEW = 600;

    public function due(CarbonImmutable $now): iterable
    {
        $now = $now->setTimezone(PolishTime::TIMEZONE);
        $today = $now->startOfDay();
        if ($now->lessThan($this->dailyFrom($today))) {
            return;
        }

        $query = ClientNote::query()
            ->with(['author', 'client:id,name'])
            ->whereNotNull('user_id')
            ->whereNull('reminded_at')
            ->whereNotNull('remind_on')
            ->whereDate('remind_on', '<=', $today->format('Y-m-d'))
            ->whereDate('remind_on', '>=', $today->subDays(self::CATCH_UP_DAYS)->format('Y-m-d'));

        foreach ($query->lazyById(100) as $note) {
            /** @var ClientNote $note */
            $author = $note->author;
            if (! $author instanceof User || $note->remind_on === null) {
                continue;
            }
            $day = $note->remind_on->format('Y-m-d');
            yield [
                'user' => $author,
                'message' => $this->message($note, $day),
                'period' => $day,
            ];
        }
    }

    public function sent(array $reminder, array $result): void
    {
        foreach ($result as $channels) {
            if ($channels['mail'] === false) {
                // e-mail czeka na ponowienie — notatka zostaje „do przypomnienia”
                return;
            }
        }
        $noteId = (int) ($reminder['message']->data['client_note_id'] ?? 0);
        if ($noteId <= 0) {
            return;
        }
        // tylko gdy dzień przypomnienia się w międzyczasie nie zmienił (zmiana zeruje reminded_at i ma wyjść znowu)
        ClientNote::query()
            ->whereKey($noteId)
            ->whereNull('reminded_at')
            ->whereDate('remind_on', $reminder['period'])
            ->update(['reminded_at' => CarbonImmutable::now()]);
    }

    private function message(ClientNote $note, string $day): AppNotificationMessage
    {
        $clientName = $note->client !== null ? (string) $note->client->name : 'klient #'.$note->client_id;
        $body = trim((string) $note->body);
        if (mb_strlen($body) > self::BODY_PREVIEW) {
            $body = rtrim(mb_substr($body, 0, self::BODY_PREVIEW)).'…';
        }
        $written = $note->created_at !== null ? PolishTime::format($note->created_at, false) : null;

        return new AppNotificationMessage(
            event: 'client_note_reminder',
            subjectKey: 'client_note:'.$note->id,
            title: 'Przypomnienie: '.$clientName,
            body: implode("\n", array_filter([
                $body,
                $written !== null ? 'Twoja notatka na karcie klienta z '.$written.'.' : 'Twoja notatka na karcie klienta.',
            ])),
            url: '/clients/'.$note->client_id,
            data: [
                'client_note_id' => (int) $note->id,
                'client_id' => (int) $note->client_id,
                'client_name' => $clientName,
                'remind_on' => $day,
            ],
        );
    }

    private function dailyFrom(CarbonImmutable $today): CarbonImmutable
    {
        $from = (string) config('notifications.daily_from', '07:00');
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $from, $m) !== 1) {
            return $today->setTime(7, 0);
        }

        return $today->setTime((int) $m[1], (int) $m[2]);
    }
}
