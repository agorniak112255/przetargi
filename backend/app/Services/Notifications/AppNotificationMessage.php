<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Illuminate\Mail\Mailable;

/**
 * Jedno powiadomienie do wysłania przez NotificationDispatcher.
 *
 * - event: klucz zdarzenia z config/notifications.php (np. tender_deadline)
 * - subjectKey: rzecz, której dotyczy (np. „tender:12”) — razem z okresem chroni przed powtórką
 * - title, body: tekst w dzwonku i w e-mailu (daty jawnie w czasie polskim)
 * - url: ścieżka w aplikacji, np. „/tenders/12?tab=wynik”
 * - data: dodatkowe pola do notifications.data (tender_id, inquiry_id, …)
 * - mailable: własny e-mail zamiast wspólnego AppNotificationMail (np. TenderInvitationMail)
 */
final readonly class AppNotificationMessage
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public string $event,
        public string $subjectKey,
        public string $title,
        public string $body,
        public string $url,
        public array $data = [],
        public ?Mailable $mailable = null,
    ) {}
}
