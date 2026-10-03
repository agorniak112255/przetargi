<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Czyszczenie starych danych pomocniczych: wpisy ochrony przed powtórką powiadomień (> 180 dni), przebiegi zadań
 * (> 60 dni), pełny HTML ogłoszeń niepowiązanych z przetargiem (> 30 dni).
 * ZAŚLEPKA kroku 0 — pełną logikę dopisuje strumień D.
 */
final class SystemPruneCommand extends Command
{
    protected $signature = 'system:prune';

    protected $description = 'Usuwa stare przebiegi zadań, wpisy wysłanych powiadomień i treść starych ogłoszeń z Biuletynu';

    public function handle(): int
    {
        $this->info('Czyszczenie starych danych systemu jeszcze niegotowe.');

        return self::SUCCESS;
    }
}
