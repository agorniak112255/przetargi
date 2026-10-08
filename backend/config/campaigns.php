<?php

declare(strict_types=1);

return [
    /** Najwięcej pozycji w jednej kampanii — więcej klient i tak nie obejrzy. */
    'max_items' => 12,

    /** Adres, który dostał kampanię, nie dostanie kolejnej przez tyle dni (także od innego handlowca). */
    'frequency_cap_days' => (int) env('CAMPAIGNS_FREQUENCY_CAP_DAYS', 14),

    /** Domyślny limit wysyłki na godzinę dla nowej skrzynki (limit hostingu poczty). */
    'default_rate_per_hour' => 150,

    /** Tyle prób wysłania do jednego adresu; potem odbiorca ma status „failed”. */
    'max_attempts' => 3,

    /** Adresy z XL zaczynające się tak (faktury@, ksiegowosc@) nie dostają kampanii — tam nikt nie kupuje BHP. */
    'excluded_local_prefixes' => ['faktur', 'ksiegow', 'księgow', 'rachun', 'e-faktur', 'efaktur'],

    /** Publiczny adres https aplikacji: link wypisu i zdjęcia w mailu (lokalnie APP_URL to localhost). */
    'public_url' => rtrim((string) env('CAMPAIGNS_PUBLIC_URL', env('APP_URL', '')), '/'),

    /** Dozwolone porty SMTP skrzynek użytkowników. */
    'smtp_ports' => [25, 465, 587, 2525],

    /**
     * Nazwy serwerów SMTP dozwolone mimo adresu w sieci wewnętrznej (np. własny serwer poczty firmy). Domyślnie pusto:
     * skrzynka musi wskazywać publiczny serwer (App\Services\Campaigns\SmtpHostGuard).
     */
    'smtp_allowed_hosts' => [],

    'company_name' => env('CAMPAIGNS_COMPANY', 'SUPON'),

    'company_tagline' => 'Odzież robocza i sprzęt BHP',

    'footer_note' => env('CAMPAIGNS_FOOTER_NOTE', 'Administratorem danych jest SUPON, Rzeszów.'),

    /**
     * Stała część stopki maila pracownika (emails/mail-footer, App\Services\Campaigns\MailFooter): firma i adres
     * (supon.rzeszow.pl/kontakt, 08.10.2026), strona www i pasek „Sprawdź: …” (linki ze strony głównej sklepu).
     */
    'mail_footer' => [
        'company' => 'PHT SUPON Sp. z o.o.',
        'address' => 'ul. Miłocińska 17, 35-232 Rzeszów',
        'website' => 'https://www.supon.rzeszow.pl',
        'links' => [
            ['label' => 'Promocje', 'url' => 'https://www.supon.rzeszow.pl/231-promocja'],
            ['label' => 'Outlet', 'url' => 'https://www.supon.rzeszow.pl/259-outlet-bhp'],
            ['label' => 'Blog', 'url' => 'https://www.supon.rzeszow.pl/nowosci'],
        ],
    ],
];
