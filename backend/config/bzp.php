<?php

declare(strict_types=1);

/*
 * Biuletyn Zamówień Publicznych (ezamowienia.gov.pl) — pobieranie ogłoszeń o zamówieniu i o wyniku
 * (polecenie bzp:fetch, codziennie 6:30 czasu polskiego). API bez klucza; filtr CpvCode to dokładne dopasowanie
 * kodu (bez hierarchii) do dowolnego kodu w ogłoszeniu — stąd lista dokładnych kodów, a nie same grupy.
 */
return [
    'base_url' => env('BZP_BASE_URL', 'https://ezamowienia.gov.pl/mo-board/api/v1/notice'),

    /** Rodzaje ogłoszeń pobierane codziennie (parametr NoticeType). */
    'notice_types' => ['ContractNotice', 'TenderResultNotice'],

    /**
     * Kody CPV wyrobów BHP pobierane codziennie (parametr CpvCode, osobne zapytanie na kod i rodzaj ogłoszenia —
     * API szuka dokładnie tego kodu, bez kodów podrzędnych, więc grupa i jej kody szczegółowe są wpisane osobno).
     *
     * Każdy kod i jego nazwa sprawdzone 03.10.2026 w odpowiedziach API Biuletynu (ogłoszenia o zamówieniu
     * z 01.04–02.10.2026; w nawiasie liczba ogłoszeń z tym kodem w tym okresie, „300+” = więcej niż 3 strony).
     * Świadomie pominięte: 18000000-9 (cała odzież, obuwie i bagaże — za szeroko), 35110000-8 (sprzęt gaśniczy
     * i ratowniczy — głównie gaśnice i węże), 33141420-0 (rękawice chirurgiczne), 35111100-6 (aparaty powietrzne
     * strażackie), 33141623-3 (apteczki), 18200000-1 (odzież wierzchnia — za szeroko). Kodów bez ani jednego
     * ogłoszenia w tym okresie (np. 35113100-0, 35113450-8, 35113490-0) nie dało się potwierdzić — nie ma ich tu.
     * CPV nie ma osobnych kodów ochronników słuchu ani sprzętu chroniącego przed upadkiem z wysokości —
     * zamawiający podają je pod 18143000-3 (akcesoria ochronne) albo 35113000-9 (sprzęt bezpieczeństwa).
     */
    'cpv_codes' => [
        // odzież robocza i ochronna
        '18100000-0', // Odzież branżowa, specjalna odzież robocza i dodatki (300+)
        '18110000-3', // Odzież branżowa (46)
        '18113000-4', // Odzież przemysłowa (8)
        '18114000-1', // Kombinezony (6)
        '18130000-9', // Specjalna odzież robocza (25)
        '18140000-2', // Dodatki do odzieży roboczej (10)
        '18400000-3', // Odzież specjalna i dodatki (44)
        '18410000-6', // Odzież specjalna (35)
        '18220000-7', // Odzież przeciwdeszczowa (9)
        '18221300-7', // Płaszcze przeciwdeszczowe (12)
        '35113400-3', // Odzież ochronna i zabezpieczająca (300+)
        '35113410-6', // Odzież ochrony biologicznej i chemicznej (13)
        '35113430-2', // Kamizelki bezpieczeństwa (6)
        '35113440-5', // Kamizelki odblaskowe (13)
        '35113470-4', // Ochronne koszule lub spodnie (8)
        // rękawice
        '18141000-9', // Rękawice robocze (49)
        '18424000-7', // Rękawice (57)
        '18424300-0', // Rękawice jednorazowe (300+, w większości szpitale)
        // obuwie
        '18800000-7', // Obuwie (46)
        '18830000-6', // Obuwie ochronne (58)
        '18831000-3', // Obuwie z metalowymi ochraniaczami na palce (5)
        '18832000-0', // Obuwie specjalne (18)
        '18811000-7', // Obuwie nieprzemakalne (3)
        '18812000-4', // Obuwie z częściami gumowymi lub z tworzyw sztucznych (5)
        '18812200-6', // Buty gumowe (13)
        '18816000-2', // Kalosze (3)
        // ochrona oczu
        '18142000-6', // Okulary ochronne (17)
        '33735100-2', // Gogle ochronne (3)
        '18443500-1', // Osłony oczu (2)
        // ochrona głowy
        '18444000-3', // Ochronne nakrycia głowy (18)
        '18444100-4', // Zabezpieczające nakrycia głowy (3)
        '18444110-7', // Hełmy (17)
        '18444111-4', // Hełmy ochronne (32)
        '18444200-5', // Kaski (13)
        // ochrona dróg oddechowych
        '35814000-3', // Maski przeciwgazowe (14)
        '42924790-3', // Maski pochłaniające nieprzyjemne zapachy (5; zamawiający podają pod nim półmaski)
        // sprzęt ochronny ogólnie (także ochrona słuchu i przed upadkiem)
        '18143000-3', // Akcesoria ochronne (50)
        '35113000-9', // Sprzęt bezpieczeństwa (19)
    ],

    /**
     * Rodzaj towaru w części zamówienia do raportu „Skuteczność przetargów” — z głównego kodu CPV części.
     * Dopasowanie po początku kodu (cyfry bez zer końcowych grupy), najdłuższy pasujący początek wygrywa:
     * „18141000-9” → „18141” (rękawice), choć pasuje też „181” (odzież). Kod spoza mapy = „Inny rodzaj”.
     * Nazwy grup zgodne ze słownikiem CPV: 181 odzież branżowa i robocza, 184 odzież specjalna i dodatki,
     * 1822 odzież przeciwdeszczowa, 351134 odzież ochronna, 18141/18424 rękawice, 188 obuwie, 18142/33735/184435
     * okulary, gogle i osłony oczu, 18444 ochronne nakrycia głowy, 35814/42924790 maski, 18143/35113 akcesoria
     * i sprzęt ochronny.
     */
    'cpv_categories' => [
        'clothing' => ['label' => 'Odzież robocza i ochronna', 'prefixes' => ['181', '184', '1822', '351134']],
        'gloves' => ['label' => 'Rękawice', 'prefixes' => ['18141', '18424']],
        'footwear' => ['label' => 'Obuwie', 'prefixes' => ['188']],
        'eyes' => ['label' => 'Ochrona oczu', 'prefixes' => ['18142', '33735', '184435']],
        'head' => ['label' => 'Ochrona głowy', 'prefixes' => ['18444']],
        'respiratory' => ['label' => 'Ochrona dróg oddechowych', 'prefixes' => ['35814', '42924790']],
        'equipment' => ['label' => 'Sprzęt ochronny i akcesoria', 'prefixes' => ['18143', '35113']],
    ],

    /** Ile dni wstecz (data publikacji) pobiera codzienny przebieg. */
    'days' => (int) env('BZP_DAYS', 7),

    /** Rozmiar strony API — serwer CLI ma 128 MB, a pełny HTML ogłoszenia to ~60 kB. */
    'page_size' => 50,

    /** Przerwa między zapytaniami (ms), liczba ponowień i limit czasu zapytania (s). */
    'pause_ms' => 1000,
    'retries' => 2,
    'timeout' => 30,

    /**
     * Przerwa po chwilowym ograniczeniu liczby zapytań (HTTP 403/429): × numer próby (20 s, potem 40 s).
     * Przedstawiamy się nazwą aplikacji — anonimowy klient HTTP łatwiej trafia na ograniczenia.
     */
    'throttle_backoff_ms' => 20000,
    'user_agent' => 'PrzetargiSupon/1.0 (+'.rtrim((string) env('APP_URL', 'https://przetargi.supon.rzeszow.pl'), '/').')',

    /** Pełny HTML ogłoszeń niepowiązanych z przetargiem jest czyszczony po tylu dniach (system:prune). */
    'html_retention_days' => 30,

    /**
     * Nasza firma — rozpoznanie „wygraliśmy” w ogłoszeniu o wyniku: najpierw NIP, potem nazwa (porównanie
     * kluczem App\Support\CompanyName::key). BZP_OUR_NAMES: kilka nazw rozdzielonych przecinkiem.
     */
    'our_company' => [
        // NIP PHT Supon Sp. z o.o. (813-22-83-737, suma kontrolna sprawdzona); pusta zmienna w .env = ten domyślny
        'nip' => env('BZP_OUR_NIP') ?: '8132283737',
        'names' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) (env('BZP_OUR_NAMES') ?: 'SUPON')),
        ), static fn (string $name): bool => $name !== '')),
    ],
];
