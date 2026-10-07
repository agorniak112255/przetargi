<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\LocalNetwork;
use App\Models\NetworkAccessChallenge;
use App\Models\NetworkAccessGrant;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Dostęp z sieci: „z każdej sieci”, „tylko z sieci lokalnej” (adresy z local_networks) albo „z sieci lokalnej, spoza
 * niej z kodem e-mailem” — wtedy spoza sieci konto pracuje z adresu, dla którego wpisało kod (network_access_grants,
 * NetworkAccessCodeService). Ustawienie konta ma pierwszeństwo; bez niego decyduje grupa (rola Spatie), a przy kilku
 * grupach wygrywa najostrzejsza (local > local_code > any).
 *
 * Adres klienta to $request->ip(). Aplikacja nie ufa żadnemu pośrednikowi (bez trustProxies), więc to REMOTE_ADDR
 * i nagłówek X-Forwarded-For go nie podmieni. Postawienie przed serwerem Cloudflare albo innego pośrednika
 * wymaga trustProxies(at: …) z jego adresami — inaczej wszyscy będą mieli adres pośrednika.
 */
final class NetworkAccessPolicy
{
    public const ANY = 'any';

    public const LOCAL = 'local';

    public const LOCAL_CODE = 'local_code';

    public const MODES = [self::ANY, self::LOCAL, self::LOCAL_CODE];

    /** Siła trybu przy kilku grupach — wygrywa najwyższa. */
    private const STRICTNESS = [self::ANY => 0, self::LOCAL_CODE => 1, self::LOCAL => 2];

    public const DENIED_MESSAGE = 'To konto może pracować tylko z sieci lokalnej firmy. Połącz się z siecią w biurze albo poproś administratora o dostęp z każdej sieci.';

    public const CODE_DENIED_MESSAGE = 'Jesteś poza siecią firmy, a dostęp potwierdzony kodem z e-maila wygasł albo go jeszcze nie było (działa 24 godziny dla jednego miejsca). Zaloguj się ponownie i potwierdź dostęp kodem z e-maila.';

    /** Jak długo konto pracuje spoza sieci z adresu, dla którego wpisało kod. */
    public const GRANT_HOURS = 24;

    private const CACHE_KEY = 'network_access.local_networks';

    /** @var list<string>|null adresy sieci lokalnej czytane raz na żądanie */
    private ?array $addresses = null;

    public function enforced(): bool
    {
        return (bool) config('auth.network_access_enforce', true);
    }

    /**
     * @return array{mode: 'any'|'local'|'local_code', source: 'user'|'role'|'default', role: ?string}
     */
    public function effective(User $user): array
    {
        $own = $user->getAttribute('network_access');
        if (in_array($own, self::MODES, true)) {
            return ['mode' => $own, 'source' => 'user', 'role' => null];
        }

        $user->loadMissing('roles');
        $strict = null;
        $strictMode = self::ANY;
        foreach ($user->roles as $role) {
            /** @var Role $role */
            $mode = self::roleMode($role);
            if (self::STRICTNESS[$mode] > self::STRICTNESS[$strictMode]) {
                $strict = $role;
                $strictMode = $mode;
            }
        }
        if ($strict !== null) {
            return ['mode' => $strictMode, 'source' => 'role', 'role' => $strict->name];
        }

        $first = $user->roles->first();

        return $first instanceof Role
            ? ['mode' => self::ANY, 'source' => 'role', 'role' => $first->name]
            : ['mode' => self::ANY, 'source' => 'default', 'role' => null];
    }

    /** Tryb grupy; nieznana wartość w bazie = „z każdej sieci” (jak przed dodaniem kolumny). */
    public static function roleMode(Role $role): string
    {
        $mode = $role->getAttribute('network_access');

        return in_array($mode, self::MODES, true) ? $mode : self::ANY;
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

        return match ($this->effective($user)['mode']) {
            self::ANY => true,
            self::LOCAL_CODE => $this->hasGrant($user, $ip),
            default => false,
        };
    }

    /**
     * Ciało odmowy (403 przy logowaniu, 401 przy kluczu): `code_login` mówi aplikacji i dodatkowi, czy konto może
     * potwierdzić dostęp kodem z e-maila.
     *
     * @return array{message: string, reason: 'network', code_login: bool}
     */
    public function deniedBody(User $user): array
    {
        $code = $this->effective($user)['mode'] === self::LOCAL_CODE;

        return [
            'message' => $code ? self::CODE_DENIED_MESSAGE : self::DENIED_MESSAGE,
            'reason' => 'network',
            'code_login' => $code,
        ];
    }

    /**
     * Czy konto ma ważny dostęp z kodem dla tego adresu. Bez pamięci podręcznej — odebranie przez administratora
     * i zmiana hasła działają od następnego żądania; pytanie pada tylko dla żądań spoza sieci w trybie local_code.
     */
    public function hasGrant(User $user, ?string $ip): bool
    {
        $key = self::grantKey($ip);
        if ($key === null) {
            return false;
        }

        return NetworkAccessGrant::query()
            ->where('user_id', $user->getKey())
            ->where('ip', $key)
            ->where('expires_at', '>', now())
            ->exists();
    }

    /** Daje (albo przedłuża) kontu dostęp z tego adresu na GRANT_HOURS godzin. */
    public function grant(User $user, ?string $ip): void
    {
        $key = self::grantKey($ip);
        if ($key === null) {
            return;
        }
        $now = now();
        // upsert: jedna operacja zamiast „sprawdź, potem dopisz” — dwa równoczesne zapisy nie zderzą się na unique
        NetworkAccessGrant::query()->upsert(
            [[
                'user_id' => $user->getKey(),
                'ip' => $key,
                'expires_at' => $now->copy()->addHours(self::GRANT_HOURS),
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['user_id', 'ip'],
            ['expires_at', 'updated_at'],
        );
    }

    /**
     * Odbiera kontu dostępy z kodem i nieużyte logowania z kodem (zmiana hasła). `$keepIp` — adres, z którego
     * pracuje osoba zmieniająca własne hasło (właśnie podała stare hasło, więc jej dostęp zostaje).
     */
    public function revokeAll(User $user, ?string $keepIp = null): int
    {
        $now = now();
        NetworkAccessChallenge::query()
            ->where('user_id', $user->getKey())
            ->whereNull('used_at')
            ->where('expires_at', '>', $now)
            ->update(['expires_at' => $now]);

        $keep = self::grantKey($keepIp);

        return NetworkAccessGrant::query()
            ->where('user_id', $user->getKey())
            ->where('expires_at', '>', $now)
            ->when($keep !== null, static fn ($q) => $q->where('ip', '!=', $keep))
            ->update(['expires_at' => $now, 'updated_at' => $now]);
    }

    /**
     * Klucz adresu w dostępach z kodem: IPv4 wprost, IPv6 jako sieć /64 (adresy prywatności zmieniają końcówkę,
     * a przeglądarka i Thunderbird na jednym komputerze dostają różne adresy z tej samej sieci).
     */
    public static function grantKey(?string $ip): ?string
    {
        $ip = self::clientIp($ip);
        if ($ip === null) {
            return null;
        }
        if (! str_contains($ip, ':')) {
            return $ip;
        }
        $bytes = inet_pton($ip);
        if ($bytes === false) {
            return null;
        }

        return strtolower((string) inet_ntop(substr($bytes, 0, 8).str_repeat("\0", 8))).'/64';
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
     * - ktoś byłby ograniczony do sieci lokalnej (także z kodem), a lista jej adresów jest pusta (nikt by się nie
     *   zalogował albo nawet biuro wpisywałoby kod co dobę).
     * Administrator, którego zmiana przenosi na „z kodem e-mailem” poza siecią, nie jest odcinany: jest zalogowany
     * hasłem i kluczem, więc dostaje od razu dostęp z kodem na swój adres (inaczej spoza biura nie dałoby się
     * tego ustawić — kod można dostać tylko przy logowaniu).
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

                $restricted = [self::LOCAL, self::LOCAL_CODE];
                $localSet = User::query()->whereIn('network_access', $restricted)->exists()
                    || Role::query()->whereIn('network_access', $restricted)->exists();
                if ($localSet && $this->addresses() === []) {
                    throw ValidationException::withMessages([$field => [
                        'Najpierw wpisz adresy sieci lokalnej — bez nich konto albo grupa „tylko z sieci lokalnej” nie zaloguje się nigdzie, a „z kodem e-mailem” wpisywałaby kod nawet w biurze.',
                    ]]);
                }

                $fresh = User::query()->with('roles')->findOrFail($actor->getKey());
                if (! $this->allows($fresh, $ip) && $this->effective($fresh)['mode'] === self::LOCAL_CODE && self::grantKey($ip) !== null) {
                    $this->grant($fresh, $ip);
                }
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
