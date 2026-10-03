<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Chat\ChatCallService;
use Illuminate\Console\Command;

/**
 * Co minutę (harmonogram): rozmowy głosowe/wideo w toku uzgadnia z listą uczestników pokoju LiveKit i kończy
 * nieodebrane po ring_seconds, 1:1 z mniej niż dwiema osobami przez minutę, grupowe puste przez 2 min
 * i trwające ponad 12 h.
 */
class ChatCallsExpireCommand extends Command
{
    protected $signature = 'chat:calls-expire';

    protected $description = 'Kończy nieodebrane i opuszczone rozmowy głosowe/wideo w czacie (uzgadnia stan z LiveKit)';

    public function handle(ChatCallService $calls): int
    {
        $ended = $calls->expire();
        if ($ended > 0) {
            $this->info('Zakończone rozmowy: '.$ended);
        }

        return self::SUCCESS;
    }
}
