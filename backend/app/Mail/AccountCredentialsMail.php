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
 * Dane dostępu wysyłane przez administratora. Hasło w bazie jest hashowane,
 * więc wiadomość niesie hasło ustawiane razem z wysyłką, a nie dotychczasowe.
 */
class AccountCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $account,
        public readonly string $plainPassword,
        public readonly string $appUrl,
        public readonly string $roleLabel,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                (string) config('mail.from.address'),
                (string) config('mail.from.name'),
            ),
            subject: 'Dane dostępu do systemu Przetargi Supon',
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.account-credentials',
            text: 'emails.account-credentials-text',
            with: [
                'userName' => $this->account->name,
                'login' => $this->account->email,
                'password' => $this->plainPassword,
                'appUrl' => $this->appUrl,
                'roleLabel' => $this->roleLabel,
            ],
        );
    }
}
