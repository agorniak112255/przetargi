<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Przypomnienia o terminie składania ofert i o wpisaniu wyniku przetargu (config/notifications.php).
 * ZAŚLEPKA kroku 0 — pełną logikę dopisuje strumień C.
 */
final class TendersRemindCommand extends Command
{
    protected $signature = 'tenders:remind';

    protected $description = 'Wysyła przypomnienia o terminach składania ofert i o wpisaniu wyniku przetargu';

    public function handle(): int
    {
        $this->info('Przypomnienia o przetargach jeszcze niegotowe.');

        return self::SUCCESS;
    }
}
