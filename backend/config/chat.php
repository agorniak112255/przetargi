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

    /**
     * Rozmowy głosowe i wideo (LiveKit). Włączone, gdy url, api_key i api_secret są niepuste.
     * url — adres sygnalizacji dla przeglądarki (wss://…); api_url — adres, pod którym serwer aplikacji woła API
     * pokojów LiveKit (Twirp), na produkcji http://127.0.0.1:7880. Ten sam sekret podpisuje tokeny klientów
     * i webhooki LiveKit (POST /api/chat/livekit/webhook).
     */
    'calls' => [
        'url' => env('LIVEKIT_URL'),
        'api_url' => env('LIVEKIT_API_URL', 'http://127.0.0.1:7880'),
        'api_key' => env('LIVEKIT_API_KEY'),
        'api_secret' => env('LIVEKIT_API_SECRET'),
        'max_participants' => (int) env('LIVEKIT_MAX_PARTICIPANTS', 50),
        // dzwonek u odbiorców; po tym czasie nieodebrana rozmowa staje się nieodebranym połączeniem
        'ring_seconds' => 45,
        // ważność tokenu dołączenia (s) — klient dostaje nowy przy każdym POST /join
        'token_ttl' => 120,
    ],
];
