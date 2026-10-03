<?php

declare(strict_types=1);

/*
 * Powiadomienia: zdarzenia, kanały (dzwonek / e-mail) i wartości domyślne. Użytkownik zapisuje tylko nadpisania
 * (users.notification_preferences); wszystko, czego nie zmienił, bierze się stąd.
 * Klucze zdarzeń = NotificationEventKey we frontendzie (lib/api.ts).
 */
return [
    'events' => [
        'tender_deadline' => [
            'label' => 'Zbliża się termin składania oferty',
            'description' => 'W przetargach, które prowadzisz albo do których Cię zaproszono — w wybranych niżej momentach.',
            'default_bell' => true,
            'default_mail' => true,
        ],
        'tender_result_needed' => [
            'label' => 'Trzeba wpisać wynik przetargu',
            'description' => 'Dzień po terminie składania, potem co 3 dni, dopóki wyniku nie ma.',
            'default_bell' => true,
            'default_mail' => false,
        ],
        'tender_mention' => [
            'label' => 'Ktoś wspomniał o Tobie w komentarzu',
            'description' => 'Wpisując @ w komentarzu przetargu i wybierając Cię z listy osób.',
            'default_bell' => true,
            'default_mail' => true,
        ],
        'tender_invitation' => [
            'label' => 'Zaproszenie do przetargu',
            'description' => 'Ktoś zaprosił Cię do pracy nad przetargiem.',
            'default_bell' => true,
            'default_mail' => true,
        ],
        'inquiry_analysis_ready' => [
            'label' => 'Analiza zapytania klienta jest gotowa',
            'description' => 'Zapytanie wysłane do analizy w tle ma już dobrane pozycje z katalogu.',
            'default_bell' => true,
            'default_mail' => false,
        ],
        'campaign_reply' => [
            'label' => 'Klient odpowiedział na Twoją kampanię',
            'description' => 'Odpowiedź z Twojej skrzynki na mail kampanii, z ostatnich dwóch dni.',
            'default_bell' => true,
            'default_mail' => false,
        ],
        'client_note_reminder' => [
            'label' => 'Przypomnienie z notatki o kliencie',
            'description' => 'W dniu wybranym przy notatce na karcie klienta — tylko do autora notatki.',
            'default_bell' => true,
            'default_mail' => true,
            // notatki są na karcie klienta (clients.view)
            'permission' => ['clients.view'],
        ],
        'offer_validity_ending' => [
            'label' => 'Kończy się ważność mojej oferty, a wynik zapytania nie jest wpisany',
            'description' => 'Od ostatniego dnia roboczego przed końcem ważności oferty z Twojej odpowiedzi na zapytanie, gdy wynik zapytania nie jest wpisany. Raz na ofertę.',
            'default_bell' => true,
            'default_mail' => false,
            'permission' => ['inquiries.use'],
        ],
        'system_alert' => [
            'label' => 'Zadanie nocne albo konto dostawcy przestało działać',
            'description' => 'Jeden e-mail na każdy problem, dopóki go nie wyciszysz na ekranie „Stan systemu”.',
            'default_bell' => true,
            'default_mail' => true,
            // tylko osoby ze wszystkimi tymi uprawnieniami widzą i dostają to zdarzenie — link prowadzi do
            // /admin/stan-systemu, a cała Administracja wymaga admin.access
            'permission' => ['admin.access', 'admin.system.view'],
        ],
    ],

    /** Momenty przypomnienia o terminie składania ofert, gdy użytkownik nie wybrał własnych. */
    'deadline_offsets' => ['7d', '3d', 'last_workday'],

    /** Wszystkie dostępne momenty; „3h” wymaga godziny składania przy przetargu. */
    'deadline_offset_options' => [
        '7d' => ['label' => '7 dni przed', 'needs_time' => false],
        '3d' => ['label' => '3 dni przed', 'needs_time' => false],
        'last_workday' => ['label' => 'ostatni dzień roboczy przed', 'needs_time' => false],
        '3h' => ['label' => 'w dniu terminu, 3 godziny przed', 'needs_time' => true],
    ],

    /** Przypomnienia „dzienne” (7 dni, 3 dni, ostatni dzień roboczy, wpisz wynik) wychodzą od tej godziny czasu polskiego. */
    'daily_from' => '07:00',

    /** „Wpisz wynik”: pierwszy raz dzień po terminie, potem co tyle dni, najdłużej tyle dni po terminie. */
    'result_needed_every_days' => 3,
    'result_needed_max_days' => 60,

    /**
     * Po wdrożeniu przypomnienia dotyczą tylko terminów od tylu dni przed dniem pierwszego przebiegu tenders:remind
     * (TenderReminderPlanner::startedOn: zapis w pamięci podręcznej + najstarsza wysyłka przypomnienia w bazie).
     */
    'backfill_days' => 14,

    /**
     * Ponawianie samego e-maila po błędzie poczty (dzwonek idzie raz): tylko powiadomienia z okresem, gdy ten sam
     * nadawca zgłasza je znowu (tenders:remind co 15 minut, alerty w system:check). Przerwa przed kolejną próbą
     * rośnie dwukrotnie od first_delay_minutes (15, 30, 60, … min); po max_attempts próbach e-mail przepada.
     * stuck_minutes: wysyłka „w toku” dłużej niż tyle minut = proces padł w trakcie, wolno spróbować znowu.
     */
    'mail_retry' => [
        'max_attempts' => 7,
        'first_delay_minutes' => 15,
        'stuck_minutes' => 30,
    ],

    /** Wpisy ochrony przed powtórką starsze niż tyle dni czyści system:prune. */
    'dispatches_retention_days' => 180,
];
