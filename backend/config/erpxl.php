<?php

declare(strict_types=1);

/*
 * Odczyt z Comarch ERP XL (połączenie „erpxl” w config/database.php): kopia towarów ze stanami i zakupami
 * (erp:sync) i powiązania towar XL ↔ karta (erp:match).
 */
return [
    // Wyłącznik — bez niego komendy i harmonogram nic nie robią, nawet przy uzupełnionym połączeniu.
    'enabled' => (bool) env('ERPXL_ENABLED', false),

    // Stan „handlowy” = suma magazynów, których nazwa w XL zaczyna się od tego tekstu (decyzja 29.09.2026:
    // magazyny HANDEL; kontraktowe i przecen widać tylko w rozbiciu).
    'trade_warehouse_prefix' => env('ERPXL_TRADE_WAREHOUSE_PREFIX', 'Magazyn HANDEL'),

    // Oddziały do filtra Zapasów i raportu dla zarządu: magazyny łączone po cyfrach na początku kodu XL (decyzja
    // właściciela 01.10.2026: 01H, 01MTU, 01JS… = Rzeszów). Oddział spoza listy pokazuje się jako „Magazyny NN”.
    'locations' => [
        '01' => 'Rzeszów',
        '11' => 'Tarnów',
        '15' => 'Kraków',
        '13' => 'Stalowa Wola',
        '20' => 'Sanok',
        '10' => 'Łódź',
        '14' => 'Kraków – usługi',
    ],

    // Ile ostatnich pozycji PZ zapisać na towar.
    'purchases_per_item' => 3,

    // Towary w paczce (zapytania IN do XL, upsert do bazy aplikacji).
    'batch' => 500,
];
