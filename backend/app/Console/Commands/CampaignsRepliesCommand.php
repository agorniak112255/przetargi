<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Campaigns\CampaignReplySync;
use Illuminate\Console\Command;

class CampaignsRepliesCommand extends Command
{
    protected $signature = 'campaigns:replies';

    protected $description = 'Czyta nagłówki skrzynek handlowców (IMAP, tylko odczyt) i zapisuje odpowiedzi klientów na kampanie';

    public function handle(CampaignReplySync $sync): int
    {
        $stats = $sync->run();
        if ($stats['accounts'] > 0) {
            $this->info(sprintf('Skrzynek: %d, nagłówków: %d, nowych odpowiedzi: %d, błędów: %d.', $stats['accounts'], $stats['messages'], $stats['replies'], $stats['errors']));
        }

        return self::SUCCESS;
    }
}
