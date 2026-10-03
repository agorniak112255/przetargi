<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use App\Services\Notifications\AppNotificationMessage;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Wspólny e-mail powiadomienia (przypomnienia, wzmianki, gotowa analiza, odpowiedź na kampanię…): tytuł, treść
 * i przycisk do rzeczy w aplikacji. Wysyłka bez kolejki przez NotificationDispatcher, z poczty skonfigurowanej
 * w panelu (MailSettingsService).
 */
class AppNotificationMail extends Mailable
{
    public function __construct(
        public readonly AppNotificationMessage $notification,
        public readonly User $recipient,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                (string) config('mail.from.address'),
                (string) config('mail.from.name'),
            ),
            subject: $this->notification->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.app-notification',
            text: 'emails.app-notification-text',
            with: [
                'recipientName' => $this->recipient->name,
                'title' => $this->notification->title,
                'body' => $this->notification->body,
                'actionUrl' => self::absoluteUrl($this->notification->url),
                'settingsUrl' => self::absoluteUrl('/account#powiadomienia'),
            ],
        );
    }

    /** Ścieżka w aplikacji („/tenders/12?tab=wynik”) → pełny adres z config app.frontend_url. */
    public static function absoluteUrl(string $path): string
    {
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }

        return rtrim((string) config('app.frontend_url'), '/').'/'.ltrim($path, '/');
    }
}
