<?php

declare(strict_types=1);

namespace App\Services\B2b;

use Closure;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sklep B2B producenta obuwia VM Footwear pl.b2b.vmfootwear.cz (AB Solutions, ERP Cézar; polska wersja sklepu,
 * ceny konta w PLN). Nieoficjalne: formularz, adresy i znaczniki odczytane ze stron zalogowanego konta 01.10.2026.
 *
 * Cały sklep jest tylko dla zalogowanych — gość dostaje przekierowanie na /Login_uzytkownika/. Logowanie: formularz
 * tej strony (user = e-mail, password, login=1) wysłany POST-em pod ten sam adres, sesja w ciasteczkach (PHPSESSID,
 * __uauth). Każda strona konta ma w nagłówku odnośnik do konta użytkownika (href="/uzytkownik/") — strona bez niego
 * to strona logowania, czyli brak sesji.
 *
 * Zapytania idą po kolei, z przerwą przed każdym. Zdjęcia (/image/{hash}) sklep wydaje bez nagłówka Content-Type —
 * rodzaj pliku rozpoznajemy z treści.
 */
final class VmFootwearB2bClient
{
    public const HOST = 'pl.b2b.vmfootwear.cz';

    public const BASE = 'https://pl.b2b.vmfootwear.cz';

    private const LOGIN_PAGE = self::BASE.'/Login_uzytkownika/';

    private const HOME = self::BASE.'/';

    /** Odnośnik do konta w nagłówku strony — jest tylko na stronach zalogowanego konta. */
    private const LOGGED_IN_MARKER = 'href="/uzytkownik/"';

    private const SESSION_LOST = 'Utracono sesję konta pl.b2b.vmfootwear.cz — ceny konta niedostępne';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503 i potknięciach sieci, gdy sklep nie podał Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    private const MAX_RETRY_AFTER_MS = 120_000;

    private const TIMEOUT_SECONDS = 60;

    private CookieJar $jar;

    private bool $loggedIn = false;

    private int $consecutiveFailures = 0;

    /** @var Closure(int): void */
    private Closure $sleep;

    /**
     * @param  (Closure(int): void)|null  $sleep  pauza w ms (w testach bez czekania)
     */
    public function __construct(
        private readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
        private readonly int $delayMs = 150,
        ?Closure $sleep = null,
    ) {
        $this->jar = new CookieJar;
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    public function login(): void
    {
        // nowa sesja — stare ciasteczka mogły należeć do wygasłej
        $this->jar = new CookieJar;
        $this->loggedIn = false;

        $username = trim($this->username);
        if ($username === '') {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: konto nie ma loginu (adres e-mail konta w sklepie)');
        }

        try {
            // strona logowania zakłada sesję (ciasteczka), jak w przeglądarce
            $this->send(static fn (PendingRequest $http): Response => $http->get(self::LOGIN_PAGE));
            $response = $this->send(fn (PendingRequest $http): Response => $http->asForm()->post(self::LOGIN_PAGE, [
                'user' => $username,
                'password' => $this->password,
                'login' => '1',
            ]));
            $confirmed = self::hasLoggedInMarker(self::bodyOf($response))
                || self::hasLoggedInMarker(self::bodyOf($this->send(static fn (PendingRequest $http): Response => $http->get(self::HOME))));
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        if (! $confirmed) {
            throw new RuntimeException(
                'Logowanie do '.self::HOST.' nieudane: sklep nie potwierdził zalogowania (nadal strona logowania) — sprawdź e-mail i hasło'
            );
        }

        $this->loggedIn = true;
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    /** Strona główna konta (menu działów). */
    public function homePage(): string
    {
        return $this->accountPage(self::HOME, 'strona główna');
    }

    /** Strona listy działu („/obuv/”, kolejne strony „/obuv/2”) albo strona wyrobu — ścieżka ze sklepu. */
    public function page(string $path): string
    {
        return $this->accountPage(self::urlFor($path), 'strona '.$path);
    }

    /**
     * Plik albo zdjęcie spod adresu ze strony wyrobu, sesją konta. Strona HTML zamiast pliku (wygasła sesja →
     * strona logowania) = jedno ponowne logowanie; nadal HTML — błąd (takiej treści nie zapisujemy jako pliku).
     *
     * @return array{bytes: string, mime: string}
     */
    public function fileBytes(string $url): array
    {
        if (! self::isShopUrl($url)) {
            throw new RuntimeException('adres spoza '.self::HOST.': '.$url);
        }
        if (! $this->loggedIn) {
            $this->relogin();
        }
        $file = $this->fetchFile($url);
        if ($file === null) {
            $this->relogin();
            $file = $this->fetchFile($url);
            if ($file === null) {
                throw new RuntimeException('sklep nie wydał pliku '.$url.' (pusta treść albo strona HTML po ponownym logowaniu)');
            }
        }

        return $file;
    }

    public static function hasLoggedInMarker(string $html): bool
    {
        return str_contains($html, self::LOGGED_IN_MARKER);
    }

    /** https na pl.b2b.vmfootwear.cz (bez danych logowania i nietypowego portu w adresie). */
    public static function isShopUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        return strtolower($parts['host'] ?? '') === self::HOST;
    }

    /**
     * Pełny adres ze ścieżki sklepu („/accra-polbuty-robocze-tactical/”) albo z pełnego adresu sklepu; inny host,
     * „..” albo pusta ścieżka = wyjątek.
     */
    public static function urlFor(string $path): string
    {
        $path = trim($path);
        $url = str_starts_with($path, '/') && ! str_starts_with($path, '//') ? self::BASE.$path : $path;
        if (! self::isShopUrl($url) || str_contains($url, '..')) {
            throw new RuntimeException('adres spoza '.self::HOST.': '.$path);
        }

        return $url;
    }

    /**
     * @return array{bytes: string, mime: string}|null null = sklep nie wydał pliku (brak sesji)
     */
    private function fetchFile(string $url): ?array
    {
        $response = $this->send(static fn (PendingRequest $http): Response => $http->accept('*/*')->get($url));
        $bytes = self::bodyOf($response);
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        if ($bytes === '' || $mime === 'text/html' || self::looksLikeHtml($bytes)) {
            return null;
        }
        // zdjęcia przychodzą bez Content-Type
        if ($mime === '' || $mime === 'application/octet-stream') {
            $mime = self::sniffMime($bytes) ?? $mime;
        }

        return ['bytes' => $bytes, 'mime' => $mime];
    }

    private static function looksLikeHtml(string $bytes): bool
    {
        $head = strtolower(ltrim(substr($bytes, 0, 512)));

        return str_starts_with($head, '<!doctype html') || str_starts_with($head, '<html');
    }

    private static function sniffMime(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => 'image/png',
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => 'image/gif',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($bytes, '%PDF-') => 'application/pdf',
            default => null,
        };
    }

    /**
     * Strona dla konta. Strona bez znacznika konta (wygasła sesja → strona logowania) = jedno ponowne logowanie;
     * nadal bez znacznika = B2bFatalException (dalsze strony nie miałyby cen konta).
     */
    private function accountPage(string $url, string $label): string
    {
        if (! $this->loggedIn) {
            $this->relogin();
        }

        $body = self::bodyOf($this->send(static fn (PendingRequest $http): Response => $http->get($url)));
        if (! self::hasLoggedInMarker($body)) {
            $this->relogin();
            $body = self::bodyOf($this->send(static fn (PendingRequest $http): Response => $http->get($url)));
            if (! self::hasLoggedInMarker($body)) {
                $this->loggedIn = false;

                throw new B2bFatalException(self::SESSION_LOST.' ('.$label.' bez sesji konta po ponownym logowaniu)');
            }
        }

        return $body;
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
     */
    private function send(callable $call): Response
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
                    'Accept-Language' => 'pl-PL,pl;q=0.9',
                ])->withOptions([
                    'cookies' => $this->jar,
                    'allow_redirects' => ['max' => 5],
                ]));
            } catch (ConnectionException $e) {
                $error = 'brak połączenia ('.$e->getMessage().')';
            }

            if ($response !== null && $response->successful()) {
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
