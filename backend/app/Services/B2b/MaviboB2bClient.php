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
 * Sklep MAVIBO mavibo.pl (PrestaShop 1.7, szablon at_athena, moduł tabeli kombinacji tablecombz). Nieoficjalne:
 * formularz, adresy i znaczniki odczytane ze strony zalogowanego konta 23.09.2026.
 *
 * Logowanie: zwykły formularz PrestaShop (#login-form: email, password, submitLogin=1) pod /logowanie, sesja
 * w ciasteczkach. Każda strona ma w JSON-ie zmiennej „prestashop” pole klienta „is_logged”: true dla konta, false dla
 * gościa — ten sam znacznik pilnuje obu sesji. Strona konta musi mieć cenę konta (rabat grupy klienta), a strona
 * gościa cenę katalogową — pomylenie ich zapisałoby cenę katalogową jako cenę zakupu.
 *
 * Lista: kategoria „Strona główna” (id 2) obejmuje cały sklep (238 wyrobów 23.09.2026 — tyle, co suma kategorii),
 * a PrestaShop oddaje ją jako JSON przy nagłówku X-Requested-With. Lista nie ma znacznika sesji, ale łącznik bierze
 * z niej tylko adresy stron wyrobów — ceny czyta ze strony wyrobu, która znacznik ma.
 *
 * Zapytania idą po kolei, z przerwą przed każdym.
 */
final class MaviboB2bClient
{
    public const HOST = 'mavibo.pl';

    public const BASE = 'https://mavibo.pl';

    private const LOGIN_PAGE = self::BASE.'/logowanie?back=my-account';

    private const ACCOUNT_PAGE = self::BASE.'/moje-konto';

    /** Kategoria główna — cały sklep; 100 pozycji na stronę (sklep to honoruje). */
    private const LIST_PAGE = self::BASE.'/2-strona-glowna?resultsPerPage=100&page=';

    /** Pole klienta w JSON-ie „prestashop” — jedyne miejsce, po którym widać, czyja to sesja. */
    private const LOGGED_IN_MARKER = '"is_logged":true';

    private const GUEST_MARKER = '"is_logged":false';

    /** Formularz logowania PrestaShop. */
    private const LOGIN_FORM_MARKER = 'id="login-form"';

    private const SESSION_LOST = 'Utracono sesję konta mavibo.pl — ceny konta niedostępne';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503 i potknięciach sieci, gdy sklep nie podał Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    private const MAX_RETRY_AFTER_MS = 120_000;

    private const TIMEOUT_SECONDS = 90;

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
                'back' => 'my-account',
                'email' => $username,
                'password' => $this->password,
                'submitLogin' => '1',
            ]));
            $confirmed = self::hasLoggedInMarker($response->body());
            if (! $confirmed) {
                $confirmed = self::hasLoggedInMarker(
                    $this->send(static fn (PendingRequest $http): Response => $http->get(self::ACCOUNT_PAGE))->body()
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

    /**
     * Strona listy wyrobów (numerowana od 1) jako JSON PrestaShop: products (id_product, url, name…) i pagination
     * (total_items, pages_count). Odpowiedź, która nie jest takim JSON-em, to błąd — nie zgadujemy listy z HTML.
     *
     * @return array<string, mixed>
     */
    public function listPage(int $page): array
    {
        if (! $this->loggedIn) {
            $this->relogin();
        }
        $url = self::LIST_PAGE.max(1, $page);
        $body = $this->send(static fn (PendingRequest $http): Response => $http->withHeaders([
            'Accept' => 'application/json, text/javascript, */*; q=0.01',
            'X-Requested-With' => 'XMLHttpRequest',
        ])->get($url))->body();
        $json = json_decode($body, true);
        if (! is_array($json) || ! is_array($json['products'] ?? null) || ! is_array($json['pagination'] ?? null)) {
            throw new RuntimeException('Lista produktów '.self::HOST.' (strona '.$page.') nie jest listą JSON PrestaShop — zmiana sklepu?');
        }

        return $json;
    }

    /** Strona wyrobu (adres z listy) z cenami konta. */
    public function productPage(string $url): string
    {
        return $this->accountPage(self::productUrl($url), 'strona wyrobu '.$url);
    }

    /**
     * Ta sama strona wyrobu widziana bez logowania — jedyne źródło ceny katalogowej: zalogowanemu tabela kombinacji
     * podaje tylko cenę po rabacie grupy klienta. Strona konta zamiast niej = błąd (nie wolno wziąć ceny konta za
     * katalogową).
     */
    public function guestProductPage(string $url): string
    {
        $url = self::productUrl($url);
        $body = $this->send(static fn (PendingRequest $http): Response => $http->get($url), $this->guestJar)->body();
        if (! str_contains($body, self::GUEST_MARKER)) {
            // sesja gościa mogła dostać ciasteczko konta — jedno pobranie od nowa, z czystym zestawem
            $this->guestJar = new CookieJar;
            $body = $this->send(static fn (PendingRequest $http): Response => $http->get($url), $this->guestJar)->body();
            if (! str_contains($body, self::GUEST_MARKER)) {
                throw new RuntimeException('strona wyrobu '.$url.' bez logowania nie jest stroną gościa — ceny katalogowej nie da się odczytać');
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
     * Plik ze strony wyrobu (karta produktu) albo zdjęcie — adres ze sklepu, pobierany sesją konta.
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

    /**
     * Adres strony wyrobu z listy („https://mavibo.pl/kurtki/368-6077-nimbo-51005.html#/34-kolor-26/…”) bez
     * kotwicy wyboru kombinacji — tabela kombinacji jest na stronie w całości. Adres spoza sklepu = wyjątek.
     */
    public static function productUrl(string $url): string
    {
        $url = trim(explode('#', trim($url), 2)[0]);
        if (! self::isShopUrl($url) || str_contains($url, '..')) {
            throw new RuntimeException('adres strony wyrobu spoza '.self::HOST.': '.$url);
        }

        return $url;
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
