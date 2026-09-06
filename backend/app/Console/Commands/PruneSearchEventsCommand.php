<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\SearchEvent;
use Illuminate\Console\Command;

/**
 * Telemetria wyszukiwania rośnie z każdym zapytaniem. Zdarzenia z akcją
 * (klik / wybór / wstawienie do oferty) zostają — to z nich rośnie golden set;
 * reszta po okresie retencji jest już tylko balastem.
 */
final class PruneSearchEventsCommand extends Command
{
    protected $signature = 'search-events:prune
                            {--days= : Liczba dni retencji (domyślnie '.SearchEvent::RETENTION_DAYS.')}
                            {--with-actions : Usuwaj także zdarzenia, do których jest akcja użytkownika}';

    protected $description = 'Usuwa stare zdarzenia wyszukiwania AI (zachowuje te z akcją użytkownika)';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?: SearchEvent::RETENTION_DAYS));
        $cutoff = now()->subDays($days);

        $query = SearchEvent::query()->where('created_at', '<', $cutoff);
        if (! $this->option('with-actions')) {
            $query->whereDoesntHave('actions');
        }

        $deleted = 0;
        do {
            $chunk = (clone $query)->limit(1000)->pluck('id')->all();
            if ($chunk === []) {
                break;
            }
            $deleted += SearchEvent::query()->whereIn('id', $chunk)->delete();
        } while (true);

        $this->info("Usunięto {$deleted} zdarzeń wyszukiwania starszych niż {$days} dni.");

        return self::SUCCESS;
    }
}
