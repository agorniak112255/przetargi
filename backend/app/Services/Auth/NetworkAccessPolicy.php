<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\LocalNetwork;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Dostęp z sieci: „z każdej sieci” albo „tylko z sieci lokalnej” (adresy z local_networks).
 * Ustawienie konta ma pierwszeństwo; bez niego decyduje grupa (rola Spatie), a przy kilku grupach wygrywa ostrzejsza —
 * jedna grupa „tylko sieć lokalna” wystarcza, żeby konto bez własnego ustawienia było ograniczone.
 *
 * Adres klienta to $request->ip(). Aplikacja nie ufa żadnemu pośrednikowi (bez trustProxies), więc to REMOTE_ADDR
 * i nagłówek X-Forwarded-For go nie podmieni. Postawienie przed serwerem Cloudflare albo innego pośrednika
 * wymaga trustProxies(at: …) z jego adresami — inaczej wszyscy będą mieli adres pośrednika.
 */
final class NetworkAccessPolicy
{
    public const ANY = 'any';

    public const LOCAL = 'local';

    public const MODES = [self::ANY, self::LOCAL];

    public const DENIED_MESSAGE = 'To konto może pracować tylko z sieci lokalnej firmy. Połącz się z siecią w biurze albo poproś administratora o dostęp z każdej sieci.';

    private const CACHE_KEY = 'network_access.local_networks';

    /** @var list<string>|null adresy sieci lokalnej czytane raz na żądanie */
    private ?array $addresses = null;

    public function enforced(): bool
    {
        return (bool) config('auth.network_access_enforce', true);
    }

    /**
     * @return array{mode: 'any'|'local', source: 'user'|'role'|'default', role: ?string}
     */
    public function effective(User $user): array
    {
        $own = $user->getAttribute('network_access');
        if (in_array($own, self::MODES, true)) {
            return ['mode' => $own, 'source' => 'user', 'role' => null];
        }

        $user->loadMissing('roles');
        /** @var Role|null $strict */
        $strict = $user->roles->first(static fn (Role $role): bool => $role->getAttribute('network_access') === self::LOCAL);
        if ($strict !== null) {
            return ['mode' => self::LOCAL, 'source' => 'role', 'role' => $strict->name];
        }

        $first = $user->roles->first();

        return $first instanceof Role
            ? ['mode' => self::ANY, 'source' => 'role', 'role' => $first->name]
            : ['mode' => self::ANY, 'source' => 'default', 'role' => null];
    }

    public function allows(User $user, ?string $ip): bool
    {
        if (! $this->enforced()) {
            return true;
        }
        // adres z biura przechodzi bez czytania ról — to większość ruchu (dodatek Thunderbirda pyta co kilka sekund)
        if ($this->isLocal($ip)) {
            return true;
        }

        return $this->effective($user)['mode'] === self::ANY;
    }

    public function isLocal(?string $ip): bool
    {
        $ip = self::clientIp($ip);
        if ($ip === null) {
            return false;
        }
        $addresses = $this->addresses();

        return $addresses !== [] && IpUtils::checkIp($ip, $addresses);
    }

    /**
     * @return list<string>
     */
    public function addresses(): array
    {
        return $this->addresses ??= Cache::rememberForever(
            self::CACHE_KEY,
            static fn (): array => LocalNetwork::query()->orderBy('id')->pluck('address')->all(),
        );
    }

    /** Po zmianie listy: pamięć podręczna i odczyt w bieżącym żądaniu. */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->addresses = null;
    }

    /**
     * Zmiana ustawień dostępu z sieci (konto, grupa, lista adresów) w transakcji, cofana, gdy:
     * - administrator, który ją robi, straciłby dostęp z adresu, z którego pracuje;
     * - ktoś byłby ograniczony do sieci lokalnej, a lista jej adresów jest pusta (nikt by się nie zalogował).
     *
     * @template T
     *
     * @param  \Closure(): T  $change
     * @return T
     *
     * @throws ValidationException
     */
    public function applyGuarded(User $actor, ?string $ip, string $field, \Closure $change): mixed
    {
        try {
            return DB::transaction(function () use ($actor, $ip, $field, $change): mixed {
                $result = $change();
                $this->forget();

                $localSet = User::query()->where('network_access', self::LOCAL)->exists()
                    || Role::query()->where('network_access', self::LOCAL)->exists();
                if ($localSet && $this->addresses() === []) {
                    throw ValidationException::withMessages([$field => [
                        'Najpierw wpisz adresy sieci lokalnej — bez nich konto albo grupa „tylko z sieci lokalnej” nie zaloguje się nigdzie.',
                    ]]);
                }

                $fresh = User::query()->with('roles')->findOrFail($actor->getKey());
                if (! $this->allows($fresh, $ip)) {
                    throw ValidationException::withMessages([$field => [
                        'Ta zmiana odcięłaby Twój dostęp: pracujesz z adresu '.($ip ?? 'nieznanego').', którego nie ma na liście sieci lokalnej.',
                    ]]);
                }

                return $result;
            });
        } finally {
            // lista mogła trafić do pamięci podręcznej w cofniętej transakcji
            $this->forget();
        }
    }

    /** Adres klienta do porównania; IPv4 zapisany jako IPv6 (::ffff:1.2.3.4) staje się zwykłym IPv4. */
    public static function clientIp(?string $ip): ?string
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        if (preg_match('/^::ffff:(\d{1,3}(?:\.\d{1,3}){3})$/i', $ip, $m) === 1) {
            return $m[1];
        }

        return $ip;
    }

    /**
     * Pojedynczy adres IPv4/IPv6 albo zakres CIDR w postaci, którą rozumie IpUtils::checkIp.
     * Null, gdy wpis nie jest adresem albo obejmuje cały Internet (/0).
     */
    public static function normalizeAddress(string $raw): ?string
    {
        $value = trim($raw);
        $parts = explode('/', $value);
        if ($value === '' || count($parts) > 2) {
            return null;
        }
        $ip = self::clientIp($parts[0]);
        if ($ip === null) {
            return null;
        }
        $isV6 = str_contains($ip, ':');
        if ($isV6) {
            $ip = strtolower((string) inet_ntop((string) inet_pton($ip)));
        }
        if (count($parts) === 1) {
            return $ip;
        }
        if (preg_match('/^\d{1,3}$/', $parts[1]) !== 1) {
            return null;
        }
        $bits = (int) $parts[1];
        if ($bits < 1 || $bits > ($isV6 ? 128 : 32)) {
            return null;
        }

        return $ip.'/'.$bits;
    }
}
