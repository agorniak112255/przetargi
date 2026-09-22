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
 * Sklep B2B JHK Polska jhkpolska.pl (silnik SolEx B2B). Nieoficjalne: formularz, adresy i klasy odczytane ze strony
 * zalogowanego konta 22.09.2026.
 *
 * Logowanie: zwykły formularz (pola „Uzytkownik” i „Haslo”) pod /logowanie, sesja w ciasteczkach. Strona ma
 * reCAPTCHA, ale tylko w formularzach z polem g-recaptcha (rejestracja) — formularz logowania go nie ma.
 * Każda strona konta ma w JSON-ie ustawień „isLoggedIn”: true dla konta, false dla gościa. Dlatego ten sam znacznik
 * pilnuje obu sesji: strona konta musi mieć cenę konta, a strona gościa cenę detaliczną (katalogową) — pomylenie ich
 * zapisałoby cenę katalogową jako cenę zakupu.
 *
 * Sesja gościa jest osobnym zestawem ciasteczek, bo cenę katalogową każdego rozmiaru sklep pokazuje tylko gościowi
 * (zalogowanemu podaje ją jedynie przy rozmiarze otwartej karty).
 *
 * Zapytania idą po kolei, z przerwą przed każdym.
 */
final class JhkB2bClient
{
    public const HOST = 'jhkpolska.pl';

    public const BASE = 'https://jhkpolska.pl';

    /** Adres strony wyrobu i listy jest zawsze w wersji polskiej (/pl/…) — opis i nazwy po polsku. */
    public const PREFIX = '/pl/';

    private const LOGIN_PAGE = self::BASE.'/logowanie';

    private const HOME_PAGE = self::BASE.'/pl/home';

    private const LIST_PAGE = self::BASE.'/pl/p?strona=';

    /** JSON ustawień strony (skrypt s-a-p) — jedyne miejsce, po którym widać, czyja to sesja. */
    private const LOGGED_IN_MARKER = '"isLoggedIn":true';

    private const GUEST_MARKER = '"isLoggedIn":false';

    /** Pole formularza logowania. */
    private const LOGIN_FORM_MARKER = 'name="Uzytkownik"';

    private const SESSION_LOST = 'Utracono sesję konta jhkpolska.pl — ceny konta niedostępne';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503 i potknięciach sieci, gdy sklep nie podał Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    private const MAX_RETRY_AFTER_MS = 120_000;

    private const TIMEOUT_SECONDS = 60;

    private CookieJar $jar;

    private CookieJar $guestJar;

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
        $this->guestJar = new CookieJar;
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
            $page = $this->send(static fn (PendingRequest $http): Response => $http->get(self::LOGIN_PAGE))->body();
            if (! str_contains($page, self::LOGIN_FORM_MARKER)) {
                throw new RuntimeException('brak formularza logowania na stronie');
            }
            $response = $this->send(fn (PendingRequest $http): Response => $http->asForm()->post(self::LOGIN_PAGE, [
                'Uzytkownik' => $username,
                'Haslo' => $this->password,
                'logowanie' => 'Zaloguj',
            ]));
            $confirmed = self::hasLoggedInMarker($response->body());
            if (! $confirmed) {
                $confirmed = self::hasLoggedInMarker(
                    $this->send(static fn (PendingRequest $http): Response => $http->get(self::HOME_PAGE))->body()
                );
            }
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        if (! $confirmed) {
            throw new RuntimeException(
                'Logowanie do '.self::HOST.' nieudane: sklep nie potwierdził zalogowania (strona nadal jest stroną gościa) — sprawdź e-mail i hasło'
            );
        }

        $this->loggedIn = true;
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    /** Strona listy wyrobów (numerowana od 1) jako HTML zalogowanego konta. */
    public function listPage(int $page): string
    {
        return $this->accountPage(self::LIST_PAGE.max(1, $page), 'strona listy '.$page);
    }

    /** Strona wyrobu (adres z listy) z cenami konta. */
    public function productPage(string $path): string
    {
        return $this->accountPage(self::productUrl($path), 'strona wyrobu '.$path);
    }

    /**
     * Ta sama strona wyrobu widziana bez logowania — jedyne źródło ceny katalogowej (detalicznej) każdego rozmiaru.
     * Strona konta zamiast niej = błąd (nie wolno wziąć ceny konta za katalogową).
     */
    public function guestProductPage(string $path): string
    {
        $url = self::productUrl($path);
        $body = $this->send(static fn (PendingRequest $http): Response => $http->get($url), $this->guestJar)->body();
        if (! str_contains($body, self::GUEST_MARKER)) {
            // sesja gościa mogła dostać ciasteczko konta (przekierowanie) — jedno pobranie od nowa, z czystym zestawem
            $this->guestJar = new CookieJar;
            $body = $this->send(static fn (PendingRequest $http): Response => $http->get($url), $this->guestJar)->body();
            if (! str_contains($body, self::GUEST_MARKER)) {
                throw new RuntimeException('strona wyrobu '.$path.' bez logowania nie jest stroną gościa — ceny katalogowej nie da się odczytać');
            }
        }

        return $body;
    }

    /**
     * @return array{bytes: string, mime: string}
     */
    public function imageBytes(string $url): array
    {
        return $this->fileBytes($url, 'zdjęcia');
    }

    /**
     * Plik ze strony wyrobu (karta produktu, certyfikaty) albo zdjęcie — adres ze sklepu, pobierany sesją konta.
     *
     * @return array{bytes: string, mime: string}
     */
    public function fileBytes(string $url, string $label = 'pliku'): array
    {
        if (! self::isShopUrl($url)) {
            throw new RuntimeException('adres '.$label.' spoza '.self::HOST.': '.$url);
        }
        $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url));
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        return ['bytes' => $response->body(), 'mime' => $mime];
    }

    public static function hasLoggedInMarker(string $html): bool
    {
        return str_contains($html, self::LOGGED_IN_MARKER);
    }

    public static function isShopUrl(string $url): bool
    {
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && strtolower((string) parse_url($url, PHP_URL_HOST)) === self::HOST;
    }

    /** Adres strony wyrobu z odnośnika listy („/pl/bluza-jt-swcr-bk-3xl”); inny adres = wyjątek. */
    public static function productUrl(string $path): string
    {
        $path = trim($path);
        if (! str_starts_with($path, self::PREFIX) || str_contains($path, '..')) {
            throw new RuntimeException('adres strony wyrobu spoza '.self::HOST.self::PREFIX.': '.$path);
        }

        return self::BASE.$path;
    }

    /**
     * Strona dla konta. Strona gościa (wygasła sesja) = jedno ponowne logowanie; nadal strona gościa =
     * B2bFatalException (dalsze strony nie miałyby cen konta).
     */
    private function accountPage(string $url, string $label): string
    {
        if (! $this->loggedIn) {
            $this->relogin();
        }

        $body = $this->send(static fn (PendingRequest $http): Response => $http->get($url))->body();
        if (! self::hasLoggedInMarker($body)) {
            $this->relogin();
            $body = $this->send(static fn (PendingRequest $http): Response => $http->get($url))->body();
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

    /**
     * @param  callable(PendingRequest): Response  $call
     * @param  CookieJar|null  $jar  zestaw ciasteczek zapytania; domyślnie sesja konta
     */
    private function send(callable $call, ?CookieJar $jar = null): Response
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
                    'cookies' => $jar ?? $this->jar,
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
