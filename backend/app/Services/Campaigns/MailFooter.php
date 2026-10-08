<?php

declare(strict_types=1);

namespace App\Services\Campaigns;

use App\Models\User;

/**
 * Stopka maila pracownika („Moje konto → Stopka maila”, users.mail_footer): imię i nazwisko, stanowisko, telefony
 * komórkowy i stacjonarny, e-mail — plus stała część z config campaigns.mail_footer (firma, adres, strona www, pasek
 * „Sprawdź: …”). Zastępuje zwykły podpis pod ofertą i kampanią wysyłaną z aplikacji (CampaignRenderer::compose);
 * czytana na bieżąco jak podpis. Szablony: emails/mail-footer (HTML) i emails/mail-footer-text.
 */
final class MailFooter
{
    /** Pola zapisywane przez użytkownika (kontrakt GET/PUT /me/mail-footer). */
    public const FIELDS = ['name', 'position', 'mobile', 'phone', 'email'];

    /** Grafiki stopki w public/ (frontend/public/campaign → backend/public/campaign przy buildzie); OfferPdf je osadza. */
    public const IMAGE_PATHS = [
        'logo' => 'campaign/footer-logo.png',
        'phone' => 'campaign/footer-phone.png',
        'mail' => 'campaign/footer-mail.png',
        'web' => 'campaign/footer-web.png',
    ];

    /**
     * Zapisana stopka użytkownika; null = brak (albo bez imienia i nazwiska) — wtedy w mailu zwykły podpis.
     *
     * @return array{name: string, position: string|null, mobile: string|null, phone: string|null, email: string|null}|null
     */
    public static function of(?User $user): ?array
    {
        $footer = self::fields($user?->mail_footer);

        return $footer['name'] !== null ? $footer : null;
    }

    /**
     * Pola stopki z dowolnej wartości (zapis w bazie, żądanie): przycięte, białe znaki w jedną spację, puste = null.
     *
     * @return array{name: string|null, position: string|null, mobile: string|null, phone: string|null, email: string|null}
     */
    public static function fields(mixed $raw): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            $value = is_array($raw) && is_string($raw[$field] ?? null) ? trim((string) preg_replace('/\s+/u', ' ', $raw[$field])) : '';
            $out[$field] = $value !== '' ? $value : null;
        }

        /** @var array{name: string|null, position: string|null, mobile: string|null, phone: string|null, email: string|null} $out */
        return $out;
    }

    /**
     * Dane szablonu emails/mail-footer(-text). Bez publicznego adresu aplikacji obrazki by nie doszły — wtedy bez logo,
     * a zamiast ikon tekst „tel.”, „e-mail”, „www”.
     *
     * @param  array<string, string|null>  $footer  pola FIELDS
     * @return array<string, mixed>
     */
    public static function viewData(array $footer): array
    {
        $publicUrl = rtrim((string) config('campaigns.public_url'), '/');
        $config = (array) config('campaigns.mail_footer', []);
        $image = static fn (string $key): ?string => $publicUrl !== '' ? $publicUrl.'/'.self::IMAGE_PATHS[$key] : null;

        $phones = [];
        foreach (['mobile', 'phone'] as $field) {
            $number = trim((string) ($footer[$field] ?? ''));
            if ($number !== '') {
                $digits = (string) preg_replace('/\D+/', '', $number);
                // tel: z cyframi jak wpisane (bez dopisywania kierunkowego), plus tylko na początku
                $phones[] = ['text' => $number, 'href' => $digits !== '' ? 'tel:'.(str_starts_with($number, '+') ? '+' : '').$digits : null];
            }
        }
        $email = trim((string) ($footer['email'] ?? ''));
        $website = trim((string) ($config['website'] ?? ''));
        $links = [];
        foreach ((array) ($config['links'] ?? []) as $link) {
            if (is_array($link) && is_string($link['label'] ?? null) && is_string($link['url'] ?? null) && $link['label'] !== '' && $link['url'] !== '') {
                $links[] = ['label' => $link['label'], 'url' => $link['url']];
            }
        }

        return [
            'name' => trim((string) ($footer['name'] ?? '')),
            'position' => ($footer['position'] ?? null) !== null && trim((string) $footer['position']) !== '' ? trim((string) $footer['position']) : null,
            'phones' => $phones,
            'email' => $email !== '' ? $email : null,
            'email_href' => $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? 'mailto:'.$email : null,
            'website_url' => $website !== '' ? $website : null,
            'website_text' => $website !== '' ? (string) preg_replace('#^https?://#i', '', rtrim($website, '/')) : null,
            'company' => trim((string) ($config['company'] ?? '')),
            'address' => trim((string) ($config['address'] ?? '')),
            'links' => $links,
            'logo_url' => $image('logo'),
            'icon_phone' => $image('phone'),
            'icon_mail' => $image('mail'),
            'icon_web' => $image('web'),
        ];
    }

    /**
     * Podgląd w „Moje konto”: sam fragment stopki w minimalnym dokumencie HTML (nie cały mail).
     *
     * @param  array<string, string|null>  $footer  pola FIELDS
     */
    public static function previewHtml(array $footer): string
    {
        return '<!DOCTYPE html><html lang="pl"><head><meta charset="utf-8"><title>Stopka maila</title></head>'
            .'<body style="margin:0;padding:16px;background:#ffffff;">'
            .view('emails.mail-footer', ['mailFooter' => self::viewData($footer)])->render()
            .'</body></html>';
    }
}
