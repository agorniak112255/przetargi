<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Carbon\CarbonImmutable;

/**
 * Przypomnienia z notatek o klientach (client_note_reminder): do autora notatki w dniu remind_on, od godziny
 * daily_from czasu polskiego; subjectKey client_note:{id}, okres = remind_on; po wysyłce reminded_at.
 *
 * ZAŚLEPKA (krok 0) — reguły dopisuje strumień B (karta klienta).
 */
class ClientNoteReminderPlanner implements ReminderPlanner
{
    public function due(CarbonImmutable $now): iterable
    {
        return [];
    }

    public function sent(array $reminder, array $result): void {}
}
