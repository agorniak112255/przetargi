<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Sprawdzanie stanu systemu (harmonogram, konta dostawców, kolejka analiz) i alerty dla administratora.
 * ZAŚLEPKA kroku 0 — pełną logikę dopisuje strumień D.
 */
final class SystemCheckCommand extends Command
{
    protected $signature = 'system:check';

    protected $description = 'Sprawdza stan systemu i wysyła alert administratorowi, gdy zadanie nocne albo konto dostawcy przestanie działać';

    public function handle(): int
    {
        $this->info('Sprawdzanie stanu systemu jeszcze niegotowe.');

        return self::SUCCESS;
    }
}
