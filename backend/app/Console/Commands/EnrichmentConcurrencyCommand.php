<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Enrichment\EnrichmentSlots;
use Illuminate\Console\Command;

/**
 * Wypisuje sam limit równoległych produktów — czyta go skrypt deployu,
 * żeby uruchomić tyle workerów, ile ustawiono w panelu.
 */
final class EnrichmentConcurrencyCommand extends Command
{
    protected $signature = 'enrichment:concurrency';

    protected $description = 'Limit równoległych produktów enrichmentu z Ustawień AI';

    public function handle(EnrichmentSlots $slots): int
    {
        $this->getOutput()->write((string) $slots->limit());

        return self::SUCCESS;
    }
}
