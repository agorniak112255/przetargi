<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Tender;
use App\Models\TenderInvitation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TenderInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly TenderInvitation $invitation,
        public readonly Tender $tender,
        public readonly User $invitee,
        public readonly User $inviter,
        public readonly string $tenderUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                (string) config('mail.from.address'),
                (string) config('mail.from.name'),
            ),
            subject: sprintf(
                'Zaproszenie do przetargu %s — %s',
                $this->tender->number,
                $this->tender->title
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.tender-invitation',
            text: 'emails.tender-invitation-text',
            with: [
                'inviteeName' => $this->invitee->name,
                'inviterName' => $this->inviter->name,
                'tenderNumber' => $this->tender->number,
                'tenderTitle' => $this->tender->title,
                'clientName' => $this->tender->client?->name,
                'deadline' => $this->tender->deadline?->format('d.m.Y'),
                'note' => $this->invitation->note,
                'tenderUrl' => $this->tenderUrl,
            ],
        );
    }
}
