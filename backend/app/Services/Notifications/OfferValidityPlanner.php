<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Carbon\CarbonImmutable;

/**
 * „Kończy się ważność mojej oferty, a klient nie zamówił” (offer_validity_ending): do autora zapytania od ostatniego
 * dnia roboczego przed końcem ważności (OfferValidity::until) do końca ważności, gdy wynik jest pusty; odpowiedzi
 * z ostatnich 120 dni; subjectKey inquiry:{id}, okres validity:{data}.
 *
 * ZAŚLEPKA (krok 0) — reguły dopisuje strumień C (wynik zapytania).
 */
class OfferValidityPlanner implements ReminderPlanner
{
    public function due(CarbonImmutable $now): iterable
    {
        return [];
    }

    public function sent(array $reminder, array $result): void {}
}
