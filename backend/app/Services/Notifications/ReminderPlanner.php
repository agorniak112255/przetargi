<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Planer przypomnień polecenia crm:remind (co 15 minut): które przypomnienia należą się teraz. Ochronę przed powtórką
 * daje NotificationDispatcher (ten sam subjectKey i okres = jedna wysyłka, e-mail ponawiany po błędzie poczty, dopóki
 * planer zgłasza to samo przypomnienie).
 */
interface ReminderPlanner
{
    /**
     * Przypomnienia należne w chwili $now (czas polski) — każde dla jednej osoby.
     *
     * @return iterable<int, array{user: User, message: AppNotificationMessage, period: string}>
     */
    public function due(CarbonImmutable $now): iterable;

    /**
     * Po wysyłce bez wyjątku (np. zapis reminded_at notatki).
     *
     * @param  array{user: User, message: AppNotificationMessage, period: string}  $reminder
     * @param  array<int, array{bell: bool, mail: ?bool}>  $result  wynik NotificationDispatcher::send
     */
    public function sent(array $reminder, array $result): void;
}
