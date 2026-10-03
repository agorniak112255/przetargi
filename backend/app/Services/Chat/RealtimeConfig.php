<?php

declare(strict_types=1);

namespace App\Services\Chat;

/**
 * Czy działa czas rzeczywisty (Reverb) i jak łączą się z nim klienci. Bez BROADCAST_CONNECTION=reverb, bez klucza
 * albo bez publicznego adresu websocketu klienci zostają przy odpytywaniu API.
 */
final class RealtimeConfig
{
    /** @return array{key: string, host: string, port: int, scheme: string}|null */
    public function forClient(): ?array
    {
        if (config('broadcasting.default') !== 'reverb') {
            return null;
        }
        $key = trim((string) config('chat.realtime.key'));
        $host = trim((string) config('chat.realtime.host'));
        if ($key === '' || $host === '') {
            return null;
        }
        $scheme = config('chat.realtime.scheme') === 'http' ? 'http' : 'https';
        $port = (int) config('chat.realtime.port');

        return [
            'key' => $key,
            'host' => $host,
            'port' => $port > 0 ? $port : ($scheme === 'https' ? 443 : 80),
            'scheme' => $scheme,
        ];
    }

    public function enabled(): bool
    {
        return $this->forClient() !== null;
    }
}
