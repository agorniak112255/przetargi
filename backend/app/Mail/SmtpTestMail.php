<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SmtpTestMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array{
     *     host: ?string,
     *     port: int,
     *     username: ?string,
     *     from_address: ?string,
     *     from_name: ?string,
     *     mailer: string,
     *     scheme: ?string
     * }  $settings
     */
    public function __construct(
        public readonly array $settings,
        public readonly string $recipient,
        public readonly string $sentAt,
    ) {}

    public function envelope(): Envelope
    {
        $fromAddress = $this->settings['from_address'] ?? null;
        $fromName = $this->settings['from_name'] ?? 'Przetargi Supon';

        if (is_string($fromAddress) && $fromAddress !== '') {
            return new Envelope(
                from: new Address($fromAddress, (string) $fromName),
                subject: 'Test SMTP — połączenie działa · Przetargi Supon',
            );
        }

        return new Envelope(
            subject: 'Test SMTP — połączenie działa · Przetargi Supon',
        );
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.smtp-test',
            text: 'emails.smtp-test-text',
            with: [
                'host' => $this->settings['host'] ?? '—',
                'port' => $this->settings['port'] ?? '—',
                'username' => $this->settings['username'] ?? '—',
                'fromAddress' => $this->settings['from_address'] ?? '—',
                'fromName' => $this->settings['from_name'] ?? 'Przetargi Supon',
                'mailer' => $this->settings['mailer'] ?? 'smtp',
                'scheme' => $this->settings['scheme'] ?: 'auto (STARTTLS)',
                'recipient' => $this->recipient,
                'sentAt' => $this->sentAt,
                'appUrl' => rtrim((string) config('app.frontend_url', config('app.url')), '/'),
            ],
        );
    }
}
