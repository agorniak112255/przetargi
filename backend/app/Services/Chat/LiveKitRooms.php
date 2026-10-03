<?php

declare(strict_types=1);

namespace App\Services\Chat;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Serwer LiveKit: konfiguracja, tokeny klientów, weryfikacja webhooków i API pokojów (RoomService przez Twirp JSON,
 * POST {api_url}/twirp/livekit.RoomService/{Metoda}). LiveKit działa z `room.auto_create: false` — pokój zakłada
 * wyłącznie aplikacja przy rozpoczęciu rozmowy, więc token bez pokoju nie otworzy nowego.
 */
final class LiveKitRooms
{
    private const HTTP_TIMEOUT = 3;

    private const SERVER_TOKEN_TTL = 60;

    /** Rozmowy włączone ⇔ adres dla klienta, klucz i sekret są niepuste. */
    public function enabled(): bool
    {
        return $this->clientUrl() !== '' && $this->apiKey() !== '' && $this->apiSecret() !== '';
    }

    public function maxParticipants(): int
    {
        return max(2, (int) config('chat.calls.max_participants', 50));
    }

    public function clientUrl(): string
    {
        return trim((string) config('chat.calls.url'));
    }

    /**
     * Token dołączenia do pokoju (HS256). Identity = id użytkownika — drugie urządzenie tej samej osoby przejmuje
     * rozmowę. Bez canPublishData i bez roomAdmin: podniesienie ręki idzie atrybutem uczestnika.
     */
    public function clientToken(string $room, int $userId, string $name): string
    {
        $now = now()->getTimestamp();

        return JWT::encode([
            'iss' => $this->apiKey(),
            'sub' => (string) $userId,
            'name' => $name,
            'nbf' => $now,
            'exp' => $now + max(30, (int) config('chat.calls.token_ttl', 120)),
            'video' => [
                'room' => $room,
                'roomJoin' => true,
                'canPublish' => true,
                'canSubscribe' => true,
                'canUpdateOwnMetadata' => true,
                'canPublishSources' => ['camera', 'microphone', 'screen_share', 'screen_share_audio'],
            ],
        ], $this->apiSecret(), 'HS256');
    }

    /**
     * Webhook LiveKit: nagłówek Authorization (z „Bearer ” albo bez) = JWT HS256 podpisany sekretem, iss = klucz API,
     * claim sha256 = base64(sha256(surowe body)), ważny exp (luz 60 s). Zwraca false przy każdej niezgodności.
     */
    public function verifyWebhook(?string $authorization, string $body): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        $token = trim((string) $authorization);
        if (str_starts_with($token, 'Bearer ')) {
            $token = trim(substr($token, 7));
        }
        if ($token === '') {
            return false;
        }

        $leeway = JWT::$leeway;
        $timestamp = JWT::$timestamp;
        JWT::$leeway = 60;
        // zegar aplikacji (Carbon), nie time() — w testach z freezeTime/travel liczy się ten sam czas
        JWT::$timestamp = now()->getTimestamp();
        try {
            $claims = JWT::decode($token, new Key($this->apiSecret(), 'HS256'));
        } catch (Throwable) {
            return false;
        } finally {
            JWT::$leeway = $leeway;
            JWT::$timestamp = $timestamp;
        }

        if (! $claims instanceof stdClass || ! isset($claims->exp) || ($claims->iss ?? null) !== $this->apiKey()) {
            return false;
        }
        $expected = base64_encode(hash('sha256', $body, true));

        return is_string($claims->sha256 ?? null) && hash_equals($expected, $claims->sha256);
    }

    /**
     * Zakłada pokój z limitem osób. Błąd (brak odpowiedzi, odmowa) → RuntimeException — rozmowa nie powstaje.
     */
    public function createRoom(string $name): void
    {
        $response = $this->call('CreateRoom', $name, [
            'name' => $name,
            'max_participants' => $this->maxParticipants(),
            'empty_timeout' => 60,
            'departure_timeout' => 20,
        ]);
        if ($response === null || ! $response->successful()) {
            throw new RuntimeException('LiveKit CreateRoom failed: '.($response?->status() ?? 'no response'));
        }
    }

    /** Usuwa pokój (wyrzuca wszystkich, także samotnego dzwoniącego). Błąd tylko w logu. */
    public function deleteRoom(string $name): void
    {
        $response = $this->call('DeleteRoom', $name, ['room' => $name]);
        if ($response !== null && ! $response->successful() && $response->status() !== 404) {
            Log::warning('LiveKit DeleteRoom failed', ['room' => $name, 'status' => $response->status()]);
        }
    }

    /**
     * Uczestnicy pokoju: identity → sid (bez rozłączonych). Pokoju nie ma → pusta lista; błąd LiveKit → null
     * (wtedy nie uzgadniamy stanu).
     *
     * @return array<string, string>|null
     */
    public function listParticipants(string $name): ?array
    {
        $response = $this->call('ListParticipants', $name, ['room' => $name]);
        if ($response === null) {
            return null;
        }
        if ($response->status() === 404) {
            return [];
        }
        if (! $response->successful()) {
            Log::warning('LiveKit ListParticipants failed', ['room' => $name, 'status' => $response->status()]);

            return null;
        }
        $rows = $response->json('participants');
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $identity = trim((string) ($row['identity'] ?? ''));
            $state = $row['state'] ?? null;
            if ($identity === '' || $state === 'DISCONNECTED' || $state === 3) {
                continue;
            }
            $out[$identity] = (string) ($row['sid'] ?? '');
        }

        return $out;
    }

    /** @param  array<string, mixed>  $payload */
    private function call(string $method, string $room, array $payload): ?Response
    {
        if (! $this->enabled()) {
            return null;
        }
        $url = rtrim(trim((string) config('chat.calls.api_url')), '/').'/twirp/livekit.RoomService/'.$method;
        try {
            return Http::timeout(self::HTTP_TIMEOUT)
                ->connectTimeout(self::HTTP_TIMEOUT)
                ->acceptJson()
                ->withToken($this->serverToken($room))
                ->post($url, $payload);
        } catch (Throwable $e) {
            Log::warning('LiveKit '.$method.' unreachable', ['room' => $room, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** JWT serwera do API pokojów: tylko ten pokój, ważny 60 s. */
    private function serverToken(string $room): string
    {
        $now = now()->getTimestamp();

        return JWT::encode([
            'iss' => $this->apiKey(),
            'nbf' => $now,
            'exp' => $now + self::SERVER_TOKEN_TTL,
            'video' => [
                'roomCreate' => true,
                'roomList' => true,
                'roomAdmin' => true,
                'room' => $room,
            ],
        ], $this->apiSecret(), 'HS256');
    }

    private function apiKey(): string
    {
        return trim((string) config('chat.calls.api_key'));
    }

    private function apiSecret(): string
    {
        return trim((string) config('chat.calls.api_secret'));
    }
}
