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
 * Polska witryna producenta obuwia ATLAS (Atlas Schuhfabrik, Dortmund) www.atlas-obuwie.pl — TYPO3 ze strefą klienta
 * hurtowego. Nieoficjalne: formularz, adresy i znaczniki odczytane ze stron zalogowanego konta #28 01.10.2026.
 *
 * Katalog (/produkt.html) i strony wyrobów są publiczne, ale cenę konta i tabelę zamówienia (rozmiary × tęgości)
 * strona wyrobu pokazuje tylko po zalogowaniu. Logowanie: formularz z nagłówka strony (user = numer klienta, pass,
 * pid „44,265”, logintype „login_atlas”) wysłany POST-em na stronę główną; witryna przekierowuje na /produkt.html,
 * sesja w ciasteczku fe_typo_user. Strona zalogowanego konta ma w nagłówku menu konta z odnośnikiem
 * href="/produkt/my-profil.html" — strona bez niego to strona gościa (brak sesji). Złe hasło nie daje komunikatu,
 * tylko stronę gościa.
 *
 * robots.txt prosi o Crawl-delay 10 — zapytania idą po kolei, z przerwą przed każdym (łącznik daje co najmniej 1 s).
 * Strony są w UTF-8. Pliki (deklaracje /eu-pdf/…) i zdjęcia (/typo3temp/…) są publiczne.
 */
final class AtlasB2bClient
{
    public const HOST = 'www.atlas-obuwie.pl';

    public const BASE = 'https://www.atlas-obuwie.pl';

    private const HOME = self::BASE.'/index.html';

    public const CATALOG = self::BASE.'/produkt.html';

    /** Odnośnik do profilu w menu konta — jest tylko na stronach zalogowanego konta. */
    private const LOGGED_IN_MARKER = 'href="/produkt/my-profil.html"';

    private const SESSION_LOST = 'Utracono sesję konta www.atlas-obuwie.pl — ceny konta niedostępne';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503 i potknięciach sieci, gdy witryna nie podała Retry-After (ms). */
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
        private readonly int $delayMs = 1000,
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
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: konto nie ma loginu (numer klienta ATLAS)');
        }

        try {
            // strona główna zakłada sesję (ciasteczka), jak w przeglądarce
            $this->send(static fn (PendingRequest $http): Response => $http->get(self::HOME));
            $response = $this->send(fn (PendingRequest $http): Response => $http->asForm()->post(self::HOME, [
                'user' => $username,
                'pass' => $this->password,
                'pid' => '44,265',
                'logintype' => 'login_atlas',
            ]));
            $confirmed = self::hasLoggedInMarker(self::bodyOf($response));
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        if (! $confirmed) {
            throw new RuntimeException(
                'Logowanie do '.self::HOST.' nieudane: witryna nie potwierdziła zalogowania (strona bez menu konta) — sprawdź numer klienta i hasło'
            );
        }

        $this->loggedIn = true;
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    /** Katalog wyrobów (/produkt.html) — po zalogowaniu z wyrobami dostępnymi tylko dla konta. */
    public function catalogPage(): string
    {
        return $this->accountPage(self::CATALOG, 'katalog');
    }

    /** Strona wyrobu (adres z katalogu, „/index.php?id=90&…&asanr=…&cHash=…”) sesją konta. */
    public function page(string $path): string
    {
        return $this->accountPage(self::urlFor($path), 'strona '.$path);
    }

    /**
     * Plik albo zdjęcie spod adresu ze strony wyrobu (publiczne — bez sesji). Strona HTML zamiast pliku (np. brak
     * pliku → strona TYPO3) = błąd: takiej treści nie zapisujemy jako pliku.
     *
     * @return array{bytes: string, mime: string}
     */
    public function fileBytes(string $url): array
    {
        if (! self::isSiteUrl($url)) {
            throw new RuntimeException('adres spoza '.self::HOST.': '.$url);
        }
        $response = $this->send(static fn (PendingRequest $http): Response => $http->accept('*/*')->get($url));
        $bytes = self::bodyOf($response);
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        if ($bytes === '' || $mime === 'text/html' || self::looksLikeHtml($bytes)) {
            throw new RuntimeException('witryna nie wydała pliku '.$url.' (pusta treść albo strona HTML)');
        }

        return ['bytes' => $bytes, 'mime' => $mime !== '' ? $mime : 'application/octet-stream'];
    }

    public static function hasLoggedInMarker(string $html): bool
    {
        return str_contains($html, self::LOGGED_IN_MARKER);
    }

    /** https na www.atlas-obuwie.pl (bez danych logowania i nietypowego portu w adresie). */
    public static function isSiteUrl(string $url): bool
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
     * Pełny adres ze ścieżki witryny („/index.php?id=90&…”) albo z pełnego adresu witryny; inny host, „..” albo pusta
     * ścieżka = wyjątek.
     */
    public static function urlFor(string $path): string
    {
        $path = trim($path);
        $url = str_starts_with($path, '/') && ! str_starts_with($path, '//') ? self::BASE.$path : $path;
        if (! self::isSiteUrl($url) || str_contains($url, '..')) {
            throw new RuntimeException('adres spoza '.self::HOST.': '.$path);
        }

        return $url;
    }

    private static function looksLikeHtml(string $bytes): bool
    {
        $head = strtolower(ltrim(substr($bytes, 0, 512)));

        return str_starts_with($head, '<!doctype html') || str_starts_with($head, '<html');
    }

    /**
     * Strona dla konta. Strona bez menu konta (wygasła sesja → strona gościa) = jedno ponowne logowanie; nadal bez
     * menu = B2bFatalException (dalsze strony nie miałyby cen konta).
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
