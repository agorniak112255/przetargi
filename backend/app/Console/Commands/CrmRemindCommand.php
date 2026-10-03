<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Notifications\ClientNoteReminderPlanner;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\OfferValidityPlanner;
use App\Services\Notifications\ReminderPlanner;
use App\Support\PolishTime;
use Illuminate\Console\Command;
use Throwable;

/**
 * Przypomnienia handlowe (config/notifications.php): z notatek o klientach (ClientNoteReminderPlanner) i o kończącej
 * się ważności ofert z zapytań (OfferValidityPlanner). Harmonogram co 15 minut; każde przypomnienie wychodzi raz —
 * ochronę przed powtórką daje NotificationDispatcher (okres), który w kolejnych przebiegach ponawia sam e-mail po
 * błędzie poczty. Błąd jednego planera nie zatrzymuje drugiego; nieudany e-mail albo błąd kończy przebieg kodem
 * błędu (alert w „Stanie systemu”) — jak tenders:remind.
 */
final class CrmRemindCommand extends Command
{
    protected $signature = 'crm:remind';

    protected $description = 'Wysyła przypomnienia z notatek o klientach i o kończącej się ważności ofert';

    public function handle(ClientNoteReminderPlanner $notes, OfferValidityPlanner $offers, NotificationDispatcher $dispatcher): int
    {
        $sent = 0;
        $mailErrors = 0;
        $errors = 0;

        /** @var list<ReminderPlanner> $planners */
        $planners = [$notes, $offers];
        foreach ($planners as $planner) {
            try {
                foreach ($planner->due(PolishTime::now()) as $reminder) {
                    try {
                        $result = $dispatcher->send($reminder['user'], $reminder['message'], $reminder['period']);
                        $planner->sent($reminder, $result);
                    } catch (Throwable $e) {
                        // jedna zła rzecz (np. błąd bazy przy jednej osobie) nie zatrzymuje pozostałych przypomnień
                        report($e);
                        $errors++;

                        continue;
                    }
                    foreach ($result as $channels) {
                        if ($channels['bell'] || $channels['mail'] === true) {
                            $sent++;
                        }
                        if ($channels['mail'] === false) {
                            $mailErrors++;
                        }
                    }
                }
            } catch (Throwable $e) {
                // błąd samego planera (np. zapytania) — drugi planer działa dalej
                report($e);
                $errors++;
            }
        }

        $this->info(sprintf('Wysłane przypomnienia: %d, nieudane e-maile: %d, błędy: %d.', $sent, $mailErrors, $errors));

        return $errors > 0 || $mailErrors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
