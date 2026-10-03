<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\User;

/**
 * Wspólna wysyłka powiadomień: dzwonek (kanał database) i e-mail według preferencji użytkownika, raz na
 * (użytkownik, zdarzenie, rzecz, okres) — tabela notification_dispatches.
 *
 * ZAŚLEPKA kroku 0 — pełną logikę dopisuje strumień C.
 */
class NotificationDispatcher
{
    /**
     * @param  User|iterable<User>  $users
     * @param  ?string  $period  klucz okresu chroniący przed powtórką (np. „2026-10-05:7d”); null = bez ochrony
     * @return array<int, array{bell: bool, mail: ?bool}> po id użytkownika; mail null = nie chce, false = błąd wysyłki
     */
    public function send(User|iterable $users, AppNotificationMessage $message, ?string $period = null): array
    {
        return [];
    }
}
