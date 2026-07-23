<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Tender;
use App\Models\TenderInvitation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class TenderInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly TenderInvitation $invitation,
        public readonly Tender $tender,
        public readonly User $inviter,
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
            'type' => 'tender_invitation',
            'invitation_id' => $this->invitation->id,
            'tender_id' => $this->tender->id,
            'tender_number' => $this->tender->number,
            'tender_title' => $this->tender->title,
            'inviter_id' => $this->inviter->id,
            'inviter_name' => $this->inviter->name,
            'note' => $this->invitation->note,
            'message' => sprintf(
                '%s zaprosił(a) Cię do przetargu %s',
                $this->inviter->name,
                $this->tender->number
            ),
        ];
    }
}
