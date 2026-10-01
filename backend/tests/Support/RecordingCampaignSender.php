<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Campaign;
use App\Models\User;
use App\Models\UserMailAccount;
use App\Services\Campaigns\CampaignSender;

/**
 * Atrapa wysyłki dla testów API kampanii: zapisuje wywołania, nic nie wysyła. Osobny plik, bo Pint w klasie testu
 * zamienia nazwę metody testAccount na test_account (reguła nazw metod testów).
 */
final class RecordingCampaignSender extends CampaignSender
{
    /** @var list<array{0: string, 1: list<mixed>}> */
    public array $calls = [];

    /** @var array{ok: bool, message: string} */
    public array $accountResult = ['ok' => true, 'message' => 'Wysłano wiadomość testową.'];

    // bez zależności rodzica — atrapa ich nie używa
    public function __construct() {}

    public function sendTest(Campaign $campaign, string $to): void
    {
        $this->calls[] = ['sendTest', [$campaign->id, $to]];
    }

    public function start(Campaign $campaign, User $actor, ?string $expectedChecksum = null): Campaign
    {
        $this->calls[] = ['start', [$campaign->id, $actor->id]];
        $campaign->update(['status' => Campaign::STATUS_SENDING, 'sending_started_at' => now()]);

        return $campaign;
    }

    public function cancel(Campaign $campaign): Campaign
    {
        $this->calls[] = ['cancel', [$campaign->id]];
        $campaign->update(['status' => Campaign::STATUS_CANCELLED]);

        return $campaign;
    }

    public function testAccount(UserMailAccount $account): array
    {
        $this->calls[] = ['testAccount', [(int) $account->id]];

        return $this->accountResult;
    }
}
