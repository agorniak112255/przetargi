<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Notifications\NotificationDispatcher;
use App\Services\Notifications\TenderReminderPlanner;
use App\Support\PolishTime;
use Illuminate\Console\Command;
use Throwable;

/**
 * Przypomnienia o terminie składania ofert i o wpisaniu wyniku przetargu (config/notifications.php,
 * reguły w TenderReminderPlanner). Harmonogram co 15 minut; każde przypomnienie wychodzi raz — ochronę przed
 * powtórką daje NotificationDispatcher (tabela notification_dispatches), który w kolejnych przebiegach ponawia
 * sam e-mail po błędzie poczty. Nieudany e-mail kończy przebieg kodem błędu (alert w „Stanie systemu”).
 */
final class TendersRemindCommand extends Command
{
    protected $signature = 'tenders:remind';

    protected $description = 'Wysyła przypomnienia o terminach składania ofert i o wpisaniu wyniku przetargu';

    public function handle(TenderReminderPlanner $planner, NotificationDispatcher $dispatcher): int
    {
        $sent = 0;
        $mailErrors = 0;
        $errors = 0;

        foreach ($planner->due(PolishTime::now()) as $reminder) {
            try {
                $result = $dispatcher->send($reminder['user'], $reminder['message'], $reminder['period']);
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

        $this->info(sprintf('Wysłane przypomnienia: %d, nieudane e-maile: %d, błędy: %d.', $sent, $mailErrors, $errors));

        // nieudany e-mail też jest błędem przebiegu: ScheduledTaskRecorder zakłada wtedy alert dla administratora
        // (sam e-mail czeka na ponowienie w kolejnych przebiegach — NotificationDispatcher)
        return $errors > 0 || $mailErrors > 0 ? self::FAILURE : self::SUCCESS;
    }
}
