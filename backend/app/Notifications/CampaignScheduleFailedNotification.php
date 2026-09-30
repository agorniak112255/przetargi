<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/** Zaplanowana kampania nie wystartowała o swojej godzinie — wróciła do projektu (dzwonek w panelu). */
class CampaignScheduleFailedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly Campaign $campaign,
        public readonly string $reason,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'campaign_schedule_failed',
            'campaign_id' => $this->campaign->id,
            'campaign_code' => $this->campaign->code,
            'campaign_name' => $this->campaign->name,
            'reason' => $this->reason,
            'message' => sprintf('Zaplanowana kampania %s nie wystartowała: %s', $this->campaign->code, $this->reason),
        ];
    }
}
