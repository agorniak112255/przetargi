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
 * Hurtownia producenta latarek Mactronic b2b.mactronic.pl (ImB2B, Investmag). Nieoficjalne: formularz, adresy
 * i znaczniki odczytane ze stron zalogowanego konta 01.10.2026.
 *
 * Gość widzi tylko formularz logowania — każda inna strona (lista, produkt, mapa strony, pliki /upload/getfile/)
 * przekierowuje na /customer/login. Publiczne są tylko zdjęcia (/upload/default/thumbs/…).
 *
 * Logowanie: GET /customer/login (żeton customer[_token_] z formularza), POST tego samego adresu (customer[mail],
 * customer[pass]); udane przekierowuje na /customer/profile. Sesja w ciasteczkach (PHPSESSID). Strony konta mają
 * w <body class> znacznik „logged-in”, strona logowania — nie.
 */
final class MactronicB2bClient
{
    public const HOST = 'b2b.mactronic.pl';

    public const BASE = 'https://b2b.mactronic.pl';

    private const LOGIN = self::BASE.'/customer/login';

    private const LIST = self::BASE.'/product/category';

    /** Najwięcej produktów na stronie listy, jakie sklep przyjmuje (24/48/60; większa liczba = 24). */
    public const PER_PAGE = 60;

    private const SESSION_LOST = 'Utracono sesję konta b2b.mactronic.pl — ceny konta niedostępne';

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
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: konto nie ma loginu (adresu e-mail)');
        }

        try {
            $form = self::bodyOf($this->send(static fn (PendingRequest $http): Response => $http->get(self::LOGIN)));
            $token = self::formToken($form);
            if ($token === null) {
                throw new RuntimeException('formularz logowania bez żetonu customer[_token_] — zmiana sklepu?');
            }
            $response = $this->send(fn (PendingRequest $http): Response => $http->asForm()->post(self::LOGIN, [
                'customer[_token_]' => $token,
                'customer[oryginal]' => 'customer/login',
                'customer[mail]' => $username,
                'customer[pass]' => $this->password,
                'customer[submit]' => 'Zaloguj się',
            ]));
            $confirmed = self::isLoggedInPage(self::bodyOf($response));
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        if (! $confirmed) {
            throw new RuntimeException(
                'Logowanie do '.self::HOST.' nieudane: sklep nie potwierdził zalogowania (wrócił formularz logowania) — sprawdź adres e-mail i hasło'
            );
        }

        $this->loggedIn = true;
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    /**
     * Strona listy wszystkich produktów (Produkty), po PER_PAGE, w kolejności indeksów — stała kolejność, żeby
     * strony się nie przesuwały między zapytaniami.
     */
    public function listPage(int $page): string
    {
        return $this->accountPage(self::LIST.'?'.http_build_query([
            'sorter' => ['sort' => 'index-lowest', 'perpage' => self::PER_PAGE, 'page' => max(1, $page)],
        ]));
    }

    /** Strona produktu dla konta. */
    public function productPage(string $url): string
    {
        if (! self::isShopUrl($url)) {
            throw new RuntimeException('adres spoza '.self::HOST.': '.$url);
        }

        return $this->accountPage($url);
    }

    /**
     * Plik (/upload/getfile/{id}, tylko z sesją) albo zdjęcie (publiczne) spod adresu ze strony produktu. Strona HTML
     * zamiast pliku = wygasła sesja (przekierowanie na logowanie): jedno ponowne logowanie, nadal HTML = błąd (takiej
     * treści nie zapisujemy).
     *
     * @return array{bytes: string, mime: string}
     */
    public function fileBytes(string $url): array
    {
        if (! self::isShopUrl($url)) {
            throw new RuntimeException('adres spoza '.self::HOST.': '.$url);
        }

        $file = $this->download($url);
        if ($file === null) {
            $this->relogin();
            $file = $this->download($url);
        }
        if ($file === null) {
            throw new RuntimeException('sklep nie wydał pliku '.$url.' (pusta treść albo strona HTML)');
        }

        return $file;
    }

    /** Strona zalogowanego konta: <body class> ze znacznikiem „logged-in”. */
    public static function isLoggedInPage(string $html): bool
    {
        return preg_match('#<body\b[^>]*\bclass="[^"]*(?<![\w-])logged-in(?![\w-])#i', $html) === 1;
    }

    /** https na b2b.mactronic.pl (bez danych logowania i nietypowego portu w adresie). */
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
     * Strona konta; bez znacznika zalogowania (wygasła sesja → formularz logowania) = jedno ponowne logowanie; nadal
     * bez niego = B2bFatalException (dalsze strony nie miałyby cen konta).
     */
    private function accountPage(string $url): string
    {
        if (! $this->loggedIn) {
            $this->relogin();
        }

        $body = self::bodyOf($this->send(static fn (PendingRequest $http): Response => $http->get($url)));
        if (! self::isLoggedInPage($body)) {
            $this->relogin();
            $body = self::bodyOf($this->send(static fn (PendingRequest $http): Response => $http->get($url)));
            if (! self::isLoggedInPage($body)) {
                $this->loggedIn = false;

                throw new B2bFatalException(self::SESSION_LOST.' (strona bez sesji konta po ponownym logowaniu)');
            }
        }

        return $body;
    }

    /**
     * @return array{bytes: string, mime: string}|null null = HTML albo pusta treść zamiast pliku
     */
    private function download(string $url): ?array
    {
        $response = $this->send(static fn (PendingRequest $http): Response => $http->accept('*/*')->get($url));
        $bytes = self::bodyOf($response);
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        if ($bytes === '' || $mime === 'text/html' || self::looksLikeHtml($bytes)) {
            return null;
        }

        return ['bytes' => $bytes, 'mime' => $mime];
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

    private static function formToken(string $html): ?string
    {
        if (preg_match('#name="customer\[_token_\]"\s+value="([^"]+)"#', $html, $m) === 1
            || preg_match('#value="([^"]+)"\s+name="customer\[_token_\]"#', $html, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private static function looksLikeHtml(string $bytes): bool
    {
        $head = strtolower(ltrim(substr($bytes, 0, 512)));

        return str_starts_with($head, '<!doctype html') || str_starts_with($head, '<html');
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
