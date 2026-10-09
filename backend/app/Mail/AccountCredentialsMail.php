<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Dane dostępu wysyłane przez administratora. Hasło w bazie jest hashowane,
 * więc wiadomość niesie hasło ustawiane razem z wysyłką, a nie dotychczasowe.
 * W załączniku idzie dodatek do Thunderbirda (ten sam plik co w Pomocy);
 * bez pliku na dysku wiadomość wychodzi bez załącznika i bez akapitu o nim.
 */
class AccountCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public const ADDON_FILE_NAME = 'supon-przetargi.xpi';

    public readonly string $addonPath;

    public function __construct(
        public readonly User $account,
        public readonly string $plainPassword,
        public readonly string $appUrl,
        public readonly string $roleLabel,
        ?string $addonPath = null,
    ) {
        $this->addonPath = $addonPath ?? public_path('dodatek/'.self::ADDON_FILE_NAME);
    }

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
                'hasAddon' => $this->hasAddon(),
                'addonFileName' => self::ADDON_FILE_NAME,
                'helpUrl' => $this->appUrl.'/help',
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        if (! $this->hasAddon()) {
            return [];
        }

        return [
            Attachment::fromPath($this->addonPath)
                ->as(self::ADDON_FILE_NAME)
                ->withMime('application/x-xpinstall'),
        ];
    }

    public function hasAddon(): bool
    {
        return is_file($this->addonPath);
    }
}
