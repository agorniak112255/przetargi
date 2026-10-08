<?php

declare(strict_types=1);

namespace App\Services\B2b;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Platforma B2B Profix partners.profix.com.pl (aplikacja Vue na JSON-owym API „/api/…”). Nieoficjalne: adresy, pola
 * i zachowanie odczytane ze skryptu portalu i z odpowiedzi zalogowanego konta 08.10.2026.
 *
 * Logowanie: POST /api/auth/login z JSON {username, password} → {token, refresh_token, refresh_token_expiration}
 * (token to JWT z datą ważności; ten sam token portal zwraca też w nagłówku Authorization). Dalsze zapytania idą
 * z nagłówkiem „Authorization: Bearer …”. Bez tokenu albo ze złym API odpowiada HTTP 401 („JWT Token not found”,
 * „Invalid JWT Token”) — cen gościa nie ma, więc 401 = jedno ponowne logowanie, nadal 401 = B2bFatalException.
 * Token odnawiany jest też zawczasu, przed upływem ważności.
 *
 * Zdjęcia (/photo/…, /media/cache/…) i pliki (/documents/…) są publiczne. Zapytania idą po kolei, z przerwą przed
 * każdym.
 */
final class ProfixB2bClient
{
    public const HOST = 'partners.profix.com.pl';

    public const BASE = 'https://partners.profix.com.pl';

    private const API = self::BASE.'/api';

    private const SESSION_LOST = 'Utracono sesję konta partners.profix.com.pl — ceny konta niedostępne';

    /** Token odnawiany, gdy do końca ważności zostało mniej niż tyle sekund. */
    private const RENEW_BEFORE_SECONDS = 300;

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503 i potknięciach sieci, gdy portal nie podał Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    private const MAX_RETRY_AFTER_MS = 120_000;

    private const TIMEOUT_SECONDS = 120;

    private ?string $token = null;

    /** Koniec ważności tokenu (sekundy od 1970); null = nieznany. */
    private ?int $tokenExpiresAt = null;

    private int $consecutiveFailures = 0;

    /** @var Closure(int): void */
    private Closure $sleep;

    /** @var Closure(): int */
    private Closure $clock;

    /**
     * @param  (Closure(int): void)|null  $sleep  pauza w ms (w testach bez czekania)
     * @param  (Closure(): int)|null  $clock  bieżący czas w sekundach (w testach sterowany)
     */
    public function __construct(
        private readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
        private readonly int $delayMs = 150,
        ?Closure $sleep = null,
        ?Closure $clock = null,
    ) {
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function login(): void
    {
        $this->token = null;
        $this->tokenExpiresAt = null;

        $username = trim($this->username);
        if ($username === '') {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: konto nie ma loginu (adres e-mail konta w portalu)');
        }

        $body = (string) json_encode(['username' => $username, 'password' => $this->password]);
        try {
            $response = $this->send(
                static fn (PendingRequest $http): Response => $http->withBody($body, 'application/json')->post(self::API.'/auth/login'),
                allowClientErrors: true,
            );
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        $json = self::decode(self::bodyOf($response));
        $token = trim((string) ($json['token'] ?? ''));
        if (! $response->successful() || $token === '') {
            $message = trim((string) ($json['error'] ?? $json['message'] ?? ''));

            throw new RuntimeException(
                'Logowanie do '.self::HOST.' nieudane (HTTP '.$response->status().($message !== '' ? ', '.$message : '').') — sprawdź e-mail i hasło'
            );
        }

        $this->token = $token;
        $this->tokenExpiresAt = self::expiresAt($token);
    }

    public function isLoggedIn(): bool
    {
        return $this->token !== null;
    }

    /**
     * Strona katalogu (/api/catalog/grid): {total, pages, data, categories, sub_categories, params, …}. Filtry parametrów
     * (param_{id}) portal stosuje tylko razem z cats=true — wołający przekazuje go sam.
     *
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    public function grid(array $query, string $label): array
    {
        return $this->apiJson('/catalog/grid?'.http_build_query($query), $label);
    }

    /**
     * Szczegóły pozycji (/api/catalog/product/{symbol}): opis HTML, parametry (Rozmiar, Marka…), galeria, pliki.
     *
     * @return array<string, mixed>
     */
    public function product(string $symbol): array
    {
        $symbol = trim($symbol);
        if ($symbol === '' || preg_match('/^[A-Za-z0-9._-]+$/', $symbol) !== 1) {
            throw new RuntimeException('nieprawidłowy symbol pozycji: '.$symbol);
        }

        return $this->apiJson('/catalog/product/'.rawurlencode($symbol), 'pozycja '.$symbol);
    }

    /**
     * Plik albo zdjęcie portalu (publiczne). Strona HTML zamiast pliku = błąd (takiej treści nie zapisujemy jako pliku).
     *
     * @return array{bytes: string, mime: string}
     */
    public function fileBytes(string $url): array
    {
        if (! self::isShopUrl($url)) {
            throw new RuntimeException('adres spoza '.self::HOST.': '.$url);
        }
        $response = $this->send(static fn (PendingRequest $http): Response => $http->accept('*/*')->get($url));
        $bytes = self::bodyOf($response);
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $head = strtolower(ltrim(substr($bytes, 0, 512)));
        if ($bytes === '' || $mime === 'text/html' || str_starts_with($head, '<!doctype html') || str_starts_with($head, '<html')) {
            throw new RuntimeException('portal nie wydał pliku '.$url.' (pusta treść albo strona HTML)');
        }

        return ['bytes' => $bytes, 'mime' => $mime];
    }

    /** https na partners.profix.com.pl (bez danych logowania, nietypowego portu i „..” w adresie). */
    public static function isShopUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        return strtolower($parts['host'] ?? '') === self::HOST && ! str_contains((string) ($parts['path'] ?? ''), '..');
    }

    /**
     * Pełny adres pliku ze ścieżki z API („/documents/pim/kp/l3042939.pdf”) — każdy człon zakodowany; null = ścieżka
     * pusta, względna albo z „..”.
     */
    public static function fileUrl(string $path): ?string
    {
        $path = trim($path);
        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '..')) {
            return null;
        }
        $segments = array_map('rawurlencode', array_map('rawurldecode', explode('/', ltrim($path, '/'))));

        return self::BASE.'/'.implode('/', $segments);
    }

    /**
     * JSON z API konta. Brak sesji (HTTP 401) = jedno ponowne logowanie; nadal 401 — B2bFatalException (dalsze
     * zapytania nie miałyby cen konta).
     *
     * @return array<mixed>
     */
    private function apiJson(string $path, string $label): array
    {
        if ($this->token === null || $this->tokenExpiring()) {
            $this->relogin();
        }
        $response = $this->send(fn (PendingRequest $http): Response => $http->withToken((string) $this->token)->get(self::API.$path), allowUnauthorized: true);
        if ($response->status() === 401) {
            $this->relogin();
            $response = $this->send(fn (PendingRequest $http): Response => $http->withToken((string) $this->token)->get(self::API.$path), allowUnauthorized: true);
            if ($response->status() === 401) {
                $this->token = null;

                throw new B2bFatalException(self::SESSION_LOST.' ('.$label.' bez sesji konta po ponownym logowaniu)');
            }
        }
        $json = json_decode(self::bodyOf($response), true);
        if (! is_array($json)) {
            throw new RuntimeException($label.': odpowiedź portalu to nie JSON');
        }

        return $json;
    }

    private function tokenExpiring(): bool
    {
        return $this->tokenExpiresAt !== null && $this->tokenExpiresAt - ($this->clock)() < self::RENEW_BEFORE_SECONDS;
    }

    private function relogin(): void
    {
        try {
            $this->login();
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new B2bFatalException(self::SESSION_LOST.' ('.$e->getMessage().')', 0, $e);
        }
    }

    /** Koniec ważności z pola exp tokenu JWT; null = token nie jest JWT albo nie ma exp. */
    private static function expiresAt(string $token): ?int
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/'), true), true);
        $exp = is_array($payload) ? ($payload['exp'] ?? null) : null;

        return is_int($exp) ? $exp : null;
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $body): array
    {
        $json = json_decode($body, true);

        return is_array($json) ? $json : [];
    }

    /** Treść odpowiedzi z zamknięciem strumienia (jak EjendalsB2bClient::bodyOf). */
    private static function bodyOf(Response $response): string
    {
        $stream = $response->toPsrResponse()->getBody();
        $body = (string) $stream;
        $stream->close();

        return $body;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @param  bool  $allowUnauthorized  HTTP 401 wraca do wołającego (ponowne logowanie), nie jest błędem
     * @param  bool  $allowClientErrors  każda odpowiedź 4xx wraca do wołającego (logowanie: złe hasło)
     */
    private function send(callable $call, bool $allowUnauthorized = false, bool $allowClientErrors = false): Response
    {
        $retries = 0;
        while (true) {
            if ($this->delayMs > 0) {
                ($this->sleep)($this->delayMs);
            }

            $response = null;
            $error = null;
            try {
                $response = $call(Http::timeout(self::TIMEOUT_SECONDS)->withHeaders([
                    'Accept' => 'application/json, text/plain, */*',
                ])->withOptions(['allow_redirects' => ['max' => 5]]));
            } catch (ConnectionException $e) {
                $error = 'brak połączenia ('.$e->getMessage().')';
            }

            if ($response !== null && ($response->successful()
                || ($allowUnauthorized && $response->status() === 401)
                || ($allowClientErrors && $response->clientError() && $response->status() !== 429))) {
                $this->consecutiveFailures = 0;

                return $response;
            }

            if ($response !== null && in_array($response->status(), [429, 503], true) && $retries < count(self::BACKOFF_MS)) {
                ($this->sleep)(self::retryAfterMs($response) ?? self::BACKOFF_MS[$retries]);
                $retries++;

                continue;
            }

            if ($response === null && $retries < count(self::BACKOFF_MS)) {
                ($this->sleep)(self::BACKOFF_MS[$retries]);
                $retries++;

                continue;
            }

            $error ??= self::HOST.' odpowiedziało HTTP '.$response?->status();
            $this->consecutiveFailures++;
            if ($this->consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
                throw new B2bFatalException(
                    self::MAX_CONSECUTIVE_FAILURES.' kolejnych błędów zapytań do '.self::HOST.' (ostatni: '.$error.') — pobieranie przerwane'
                );
            }

            throw new RuntimeException($error);
        }
    }

    private static function retryAfterMs(Response $response): ?int
    {
        $value = trim((string) $response->header('Retry-After'));
        if ($value === '') {
            return null;
        }
        if (ctype_digit($value)) {
            $ms = (int) $value * 1000;
        } else {
            $at = strtotime($value);
            if ($at === false) {
                return null;
            }
            $ms = max(0, ($at - time()) * 1000);
        }

        return min($ms, self::MAX_RETRY_AFTER_MS);
    }
}
