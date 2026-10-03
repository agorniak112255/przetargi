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
     * Kody CPV wyrobów BHP pobierane codziennie (parametr CpvCode, osobne zapytanie na kod i rodzaj ogłoszenia).
     * Kody i nazwy sprawdzone w odpowiedziach API Biuletynu (próbki z 03.10.2026).
     *
     * Do sprawdzenia przez strumień B (nie zostały potwierdzone w próbkach, więc ich tu nie ma):
     * 18113000-4 (odzież przemysłowa?), 18114000-1 (kombinezony?), 18140000-2 (akcesoria do odzieży roboczej?),
     * 33735100-2 (gogle?), 18400000-3 (Odzież specjalna i dodatki — szeroka grupa), 18816000-2 (Kalosze),
     * 33141623-3 (Zestawy pierwszej pomocy).
     */
    'cpv_codes' => [
        '18100000-0', // Odzież branżowa, specjalna odzież robocza i dodatki
        '18110000-3', // Odzież branżowa
        '18130000-9', // Specjalna odzież robocza
        '18141000-9', // Rękawice robocze
        '18142000-6', // Okulary ochronne
        '18143000-3', // Akcesoria ochronne
        '18410000-6', // Odzież specjalna
        '18424000-7', // Rękawice
        '18424300-0', // Rękawice jednorazowe
        '18444000-3', // Ochronne nakrycia głowy
        '18444110-7', // Hełmy
        '18444111-4', // Hełmy ochronne
        '18444200-5', // Kaski
        '18800000-7', // Obuwie
        '18830000-6', // Obuwie ochronne
        '35113000-9', // Sprzęt bezpieczeństwa
        '35113400-3', // Odzież ochronna i zabezpieczająca
        '35113470-4', // Ochronne koszule lub spodnie
        '35814000-3', // Maski przeciwgazowe
    ],

    /**
     * Mapa kod CPV → rodzaj towaru do raportu „Skuteczność przetargów” (zgodna ze słownikiem CPV).
     * Wypełnia strumień B.
     */
    'cpv_categories' => [],

    /** Ile dni wstecz (data publikacji) pobiera codzienny przebieg. */
    'days' => (int) env('BZP_DAYS', 7),

    /** Rozmiar strony API — serwer CLI ma 128 MB, a pełny HTML ogłoszenia to ~60 kB. */
    'page_size' => 50,

    /** Przerwa między zapytaniami (ms), liczba ponowień i limit czasu zapytania (s). */
    'pause_ms' => 1000,
    'retries' => 2,
    'timeout' => 30,

    /** Pełny HTML ogłoszeń niepowiązanych z przetargiem jest czyszczony po tylu dniach (system:prune). */
    'html_retention_days' => 30,

    /**
     * Nasza firma — rozpoznanie „wygraliśmy” w ogłoszeniu o wyniku: najpierw NIP, potem nazwa (porównanie
     * kluczem App\Support\CompanyName::key). BZP_OUR_NAMES: kilka nazw rozdzielonych przecinkiem.
     */
    'our_company' => [
        'nip' => env('BZP_OUR_NIP'),
        'names' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('BZP_OUR_NAMES', 'SUPON')),
        ), static fn (string $name): bool => $name !== '')),
    ],
];
