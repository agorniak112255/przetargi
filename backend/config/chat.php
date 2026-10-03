<?php

declare(strict_types=1);

return [
    /**
     * Adres websocketu (Reverb) widziany przez przeglądarkę i dodatek do Thunderbirda — GET /api/realtime.
     * Na produkcji nginx Pleska przekazuje /app i /apps na 127.0.0.1:8080, więc klient łączy się przez 443 (https),
     * a serwer aplikacji publikuje zdarzenia bezpośrednio do REVERB_HOST/REVERB_PORT (config/broadcasting.php).
     * Bez BROADCAST_CONNECTION=reverb albo bez klucza GET /api/realtime zwraca null i klienci odpytują API.
     */
    'realtime' => [
        'key' => env('REVERB_APP_KEY'),
        'host' => env('CHAT_WS_HOST'),
        'port' => (int) env('CHAT_WS_PORT', 443),
        'scheme' => env('CHAT_WS_SCHEME', 'https'),
    ],

    /** Użytkownik jest „dostępny”, gdy aplikacja albo dodatek odezwały się w ciągu tylu minut. */
    'online_minutes' => 5,

    /** Najdłuższa treść wiadomości (znaki). */
    'max_body' => 4000,

    /** Najdłuższy skrót wiadomości w zdarzeniu chat.message — zdarzenie Reverb ma limit 10 000 bajtów. */
    'preview_length' => 300,
];
