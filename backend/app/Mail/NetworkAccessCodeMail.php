<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Jednorazowy kod dla konta „z sieci lokalnej, spoza niej z kodem e-mailem” (NetworkAccessCodeService).
 */
class NetworkAccessCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $account,
        public readonly string $code,
        public readonly string $ip,
        public readonly int $validMinutes,
        public readonly int $grantHours,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                (string) config('mail.from.address'),
                (string) config('mail.from.name'),
            ),
            // bez kodu w temacie — temat widać w powiadomieniach na zablokowanym telefonie
            subject: 'Kod dostępu spoza sieci firmy · Przetargi Supon',
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.network-access-code',
            text: 'emails.network-access-code-text',
            with: [
                'userName' => $this->account->name,
                'code' => $this->code,
                'ip' => $this->ip,
                'validMinutes' => $this->validMinutes,
                'grantHours' => $this->grantHours,
            ],
        );
    }
}
