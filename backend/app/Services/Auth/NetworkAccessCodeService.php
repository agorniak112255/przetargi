<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Mail\NetworkAccessCodeMail;
use App\Models\NetworkAccessChallenge;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

/**
 * Logowanie spoza sieci lokalnej z kodem e-mailem (tryb `local_code`, NetworkAccessPolicy):
 * 1. dobre hasło spoza sieci → start(): challenge (losowy klucz, w bazie sha256), ważny 15 minut, tylko z tego adresu;
 * 2. send(): 6 cyfr na e-mail konta, ważne 10 minut; kolejny kod najwcześniej po 60 s i najwyżej 5 maili na godzinę na konto;
 * 3. verify(): 5 prób na jedno logowanie i 10 błędnych kodów na godzinę na konto; dobry kod → dostęp konta z tego
 *    adresu na 24 godziny (NetworkAccessPolicy::grant), klucz wydaje AuthController.
 *
 * Liczniki zmieniają warunkowe UPDATE-y z liczbą zmienionych wierszy (nie „odczyt, potem zapis”) — równoległe
 * żądania nie ominą limitu prób, a jeden kod nie wyda dwóch kluczy. Kod w bazie to HMAC z kluczem aplikacji
 * i numerem challenge, porównywany w stałym czasie.
 */
final class NetworkAccessCodeService
{
    public const CHALLENGE_MINUTES = 15;

    public const CODE_MINUTES = 10;

    public const RESEND_SECONDS = 60;

    public const MAX_ATTEMPTS = 5;

    public const MAILS_PER_HOUR = 5;

    public const FAILS_PER_HOUR = 10;

    private const INVALID_MESSAGE = 'To logowanie wygasło albo zostało już użyte. Zaloguj się ponownie.';

    public function __construct(
        private readonly NetworkAccessPolicy $policy,
        private readonly ActivityLogger $activityLogger,
    ) {}

    /** Nowe logowanie z kodem po dobrym haśle; zwraca klucz challenge (pokazywany tylko raz). */
    public function start(User $user, ?string $ip): string
    {
        $token = Str::random(40);
        NetworkAccessChallenge::query()->create([
            'user_id' => $user->getKey(),
            'token_hash' => hash('sha256', $token),
            'ip' => (string) NetworkAccessPolicy::clientIp($ip),
            'expires_at' => now()->addMinutes(self::CHALLENGE_MINUTES),
        ]);

        return $token;
    }

    /**
     * @return array{ok: true, email_hint: string, resend_after: int, expires_in: int}
     *
     * @throws NetworkAccessCodeException
     */
    public function send(string $token, Request $request): array
    {
        $challenge = $this->find($token, $request->ip());
        $user = $challenge->user;

        $previousSentAt = $challenge->last_sent_at;
        if ($previousSentAt !== null) {
            $wait = self::RESEND_SECONDS - (int) $previousSentAt->diffInSeconds(now());
            if ($wait > 0) {
                throw NetworkAccessCodeException::tooMany('Nowy kod można wysłać za '.$wait.' s.', $wait);
            }
        }
        $mailKey = 'network-code-mail:'.$user->getKey();
        if (RateLimiter::tooManyAttempts($mailKey, self::MAILS_PER_HOUR)) {
            $wait = RateLimiter::availableIn($mailKey);

            throw NetworkAccessCodeException::tooMany('Wysłano już '.self::MAILS_PER_HOUR.' kodów w ciągu godziny. Spróbuj za '.self::minutes($wait).'.', $wait);
        }

        $code = str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT);
        $now = now();
        // rezerwacja wysyłki: tylko jedno z równoczesnych żądań przestawi last_sent_at ze starej wartości
        $claimed = NetworkAccessChallenge::query()
            ->whereKey($challenge->getKey())
            ->whereNull('used_at')
            ->where(static fn ($q) => $previousSentAt === null
                ? $q->whereNull('last_sent_at')
                : $q->where('last_sent_at', $previousSentAt))
            ->update([
                'code_hash' => $this->codeHash($challenge, $code),
                'last_sent_at' => $now,
                'updated_at' => $now,
            ]);
        if ($claimed === 0) {
            throw NetworkAccessCodeException::tooMany('Kod właśnie wysłano. Sprawdź pocztę.', self::RESEND_SECONDS);
        }

        try {
            Mail::to($user->email)->send(new NetworkAccessCodeMail(
                $user,
                $code,
                (string) NetworkAccessPolicy::clientIp($request->ip()),
                self::CODE_MINUTES,
                NetworkAccessPolicy::GRANT_HOURS,
            ));
        } catch (Throwable $e) {
            report($e);
            // nowy kod nie wyszedł — wraca poprzedni (jeśli był) i można od razu spróbować ponownie
            NetworkAccessChallenge::query()->whereKey($challenge->getKey())->update([
                'code_hash' => $challenge->code_hash,
                'last_sent_at' => $previousSentAt,
            ]);

            throw NetworkAccessCodeException::mailFailed();
        }
        RateLimiter::hit($mailKey, 3600);

        $this->activityLogger->log(
            action: 'network_code_sent',
            user: $user,
            meta: ['label' => 'Kod dostępu spoza sieci wysłany e-mailem'],
            request: $request,
        );

        return [
            'ok' => true,
            'email_hint' => self::emailHint((string) $user->email),
            'resend_after' => self::RESEND_SECONDS,
            'expires_in' => self::CODE_MINUTES * 60,
        ];
    }

    /**
     * Dobry kod: dostęp konta z tego adresu na 24 godziny; zwraca konto, któremu AuthController wyda klucz.
     *
     * @throws NetworkAccessCodeException
     */
    public function verify(string $token, string $code, Request $request): User
    {
        $challenge = $this->find($token, $request->ip());
        $user = $challenge->user;

        $failKey = 'network-code-fail:'.$user->getKey();
        if (RateLimiter::tooManyAttempts($failKey, self::FAILS_PER_HOUR)) {
            $wait = RateLimiter::availableIn($failKey);

            throw NetworkAccessCodeException::tooMany('Za dużo błędnych kodów. Spróbuj za '.self::minutes($wait).'.', $wait);
        }
        if ($challenge->code_hash === null || $challenge->last_sent_at === null) {
            throw NetworkAccessCodeException::wrongCode('Najpierw wyślij kod na e-mail.');
        }
        if ($challenge->last_sent_at->copy()->addMinutes(self::CODE_MINUTES)->isPast()) {
            throw NetworkAccessCodeException::wrongCode('Kod wygasł — wyślij nowy.');
        }

        // każda próba zużywa jedną z MAX_ATTEMPTS, zanim kod zostanie porównany
        $counted = NetworkAccessChallenge::query()
            ->whereKey($challenge->getKey())
            ->whereNull('used_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->increment('attempts');
        if ($counted === 0) {
            throw NetworkAccessCodeException::invalid(self::INVALID_MESSAGE);
        }

        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (! hash_equals((string) $challenge->code_hash, $this->codeHash($challenge, $code))) {
            RateLimiter::hit($failKey, 3600);
            $this->activityLogger->log(
                action: 'network_code_failed',
                user: $user,
                meta: ['label' => 'Błędny kod dostępu spoza sieci'],
                request: $request,
            );
            $left = self::MAX_ATTEMPTS - (int) NetworkAccessChallenge::query()->whereKey($challenge->getKey())->value('attempts');
            if ($left <= 0) {
                throw NetworkAccessCodeException::invalid('Pięć błędnych kodów — zaloguj się ponownie.');
            }

            throw NetworkAccessCodeException::wrongCode('Nieprawidłowy kod. '.($left === 1 ? 'Została 1 próba.' : 'Zostały '.$left.' próby.'));
        }

        $used = NetworkAccessChallenge::query()
            ->whereKey($challenge->getKey())
            ->whereNull('used_at')
            ->update(['used_at' => now(), 'updated_at' => now()]);
        if ($used === 0) {
            throw NetworkAccessCodeException::invalid(self::INVALID_MESSAGE);
        }

        $this->policy->grant($user, $request->ip());
        $this->activityLogger->log(
            action: 'network_code_verified',
            user: $user,
            meta: [
                'label' => 'Dostęp spoza sieci potwierdzony kodem (24 godziny)',
                'grant_ip' => NetworkAccessPolicy::grantKey($request->ip()),
            ],
            request: $request,
        );

        return $user;
    }

    /**
     * Challenge ważny dla tego adresu, a konto nadal wymaga kodu (administrator mógł w międzyczasie zmienić tryb).
     *
     * @throws NetworkAccessCodeException
     */
    private function find(string $token, ?string $ip): NetworkAccessChallenge
    {
        $challenge = $token === '' ? null : NetworkAccessChallenge::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (
            $challenge === null
            || $challenge->used_at !== null
            || $challenge->expires_at === null
            || $challenge->expires_at->isPast()
            || $challenge->attempts >= self::MAX_ATTEMPTS
            || ! $challenge->user instanceof User
        ) {
            throw NetworkAccessCodeException::invalid(self::INVALID_MESSAGE);
        }
        // ten sam klucz co w dostępie: IPv4 dokładnie, IPv6 ta sama sieć /64 (adres prywatności zmienia końcówkę)
        if (! hash_equals((string) NetworkAccessPolicy::grantKey($challenge->ip), (string) NetworkAccessPolicy::grantKey($ip))) {
            throw NetworkAccessCodeException::invalid('Twój adres w Internecie zmienił się w trakcie logowania. Zaloguj się ponownie.');
        }
        $user = $challenge->user;
        if ($this->policy->effective($user)['mode'] !== NetworkAccessPolicy::LOCAL_CODE || $this->policy->allows($user, $ip)) {
            throw NetworkAccessCodeException::invalid('Zasady dostępu tego konta zmieniły się. Zaloguj się ponownie.');
        }

        return $challenge;
    }

    private function codeHash(NetworkAccessChallenge $challenge, string $code): string
    {
        return hash_hmac('sha256', $challenge->getKey().'|'.$code, (string) config('app.key'));
    }

    /** „a***@supon.pl” — tyle, żeby rozpoznać skrzynkę, bez podawania całego adresu. */
    public static function emailHint(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        return mb_substr($email, 0, 1).'***'.substr($email, $at);
    }

    private static function minutes(int $seconds): string
    {
        $minutes = max(1, (int) ceil($seconds / 60));

        return $minutes.' min';
    }
}
