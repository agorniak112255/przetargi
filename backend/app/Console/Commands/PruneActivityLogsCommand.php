<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ActivityLog;
use Illuminate\Console\Command;

final class PruneActivityLogsCommand extends Command
{
    protected $signature = 'activity-logs:prune
                            {--days= : Liczba dni retencji (domyślnie '.ActivityLog::RETENTION_DAYS.')}';

    protected $description = 'Usuwa wpisy dziennika aktywności starsze niż okres retencji';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?: ActivityLog::RETENTION_DAYS));
        $cutoff = now()->subDays($days);

        $deleted = ActivityLog::query()
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Usunięto {$deleted} wpisów starszych niż {$days} dni.");

        return self::SUCCESS;
    }
}
