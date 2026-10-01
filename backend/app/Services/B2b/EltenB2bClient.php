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
 * Sklep B2B producenta obuwia ELTEN b2b.elten.com (Symfony, platforma „B2B OnlineShop” CPA) i publiczna witryna
 * producenta elten.com. Nieoficjalne: formularz, adresy i znaczniki odczytane ze stron zalogowanego konta #26
 * 01.10.2026.
 *
 * Cały sklep jest tylko dla zalogowanych — gość dostaje HTTP 401 (poza stroną logowania). Logowanie: strona
 * /shop-login z ukrytym polem _csrf_token, formularz POST-em pod ten sam adres: username (numer klienta), partnr
 * (numer partnera, w sklepie domyślnie 0), password; sesja w ciasteczkach. Każda strona konta ma odnośnik
 * „/shop-logout” — strona bez niego to strona logowania, czyli brak sesji.
 *
 * Sklep mówi po niemiecku, angielsku i niderlandzku (bez polskiego). Język wybiera adres z ?switch_locale=…, a trzyma
 * go sesja — dlatego przy każdym logowaniu ustawiamy angielski i sprawdzamy go (flaga języka w nagłówku strony):
 * etykiety cech karty po innym języku dałyby inną tabelkę i inny podział kart.
 *
 * elten.com (WordPress, bez logowania) ma polskie strony wyrobów (/pl/products/{nazwa}-{numer}/, mapa witryny
 * /portfolio-sitemap*.xml) i polski PDF wyrobu. robots.txt witryny zabrania pobierania /data/media/documents/ (arkusze
 * danych technicznych, certyfikaty) — tych plików nie pobieramy (decyzja do właściciela 01.10.2026); PDF wyrobu leży
 * w dozwolonej ścieżce /data/media/products/pdf/.
 *
 * Zapytania idą po kolei, z przerwą przed każdym.
 */
final class EltenB2bClient
{
    public const HOST = 'b2b.elten.com';

    public const BASE = 'https://b2b.elten.com';

    public const SITE_HOST = 'elten.com';

    public const SITE = 'https://elten.com';

    /** Mapy wyrobów witryny producenta (wszystkie języki; polskie adresy mają /pl/). */
    public const SITEMAPS = [
        self::SITE.'/portfolio-sitemap.xml',
        self::SITE.'/portfolio-sitemap2.xml',
        self::SITE.'/portfolio-sitemap3.xml',
        self::SITE.'/portfolio-sitemap4.xml',
        self::SITE.'/portfolio-sitemap5.xml',
        self::SITE.'/portfolio-sitemap6.xml',
    ];

    /** Jedyna ścieżka plików elten.com, której robots.txt nie zabrania (PDF wyrobu). */
    public const SITE_FILE_PREFIX = '/data/media/products/pdf/';

    private const LOGIN_PAGE = self::BASE.'/shop-login';

    private const LANGUAGE = 'en';

    /** Odnośnik wylogowania — jest tylko na stronach zalogowanego konta. */
    private const LOGGED_IN_MARKER = 'href="/shop-logout"';

    private const SESSION_LOST = 'Utracono sesję konta b2b.elten.com — ceny konta niedostępne';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503 i potknięciach sieci, gdy serwer nie podał Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    private const MAX_RETRY_AFTER_MS = 120_000;

    private const TIMEOUT_SECONDS = 90;

    private CookieJar $jar;

    private bool $loggedIn = false;

    private int $consecutiveFailures = 0;

    /** @var Closure(int): void */
    private Closure $sleep;

    /**
     * @param  string  $partner  numer partnera (pole „Partnernummer”); pusty = 0 jak w formularzu sklepu
     * @param  (Closure(int): void)|null  $sleep  pauza w ms (w testach bez czekania)
     */
    public function __construct(
        private readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
        private readonly string $partner = '',
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
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: konto nie ma loginu (numer klienta ELTEN)');
        }
        $partner = trim($this->partner);

        try {
            // strona logowania z wybranym językiem zakłada sesję i daje token formularza
            $page = self::bodyOf($this->send(static fn (PendingRequest $http): Response => $http->get(self::LOGIN_PAGE.'?switch_locale='.self::LANGUAGE)));
            $token = self::csrfToken($page);
            if ($token === null) {
                throw new RuntimeException('strona logowania bez pola _csrf_token (zmiana sklepu?)');
            }
            $body = self::bodyOf($this->send(fn (PendingRequest $http): Response => $http->asForm()->post(self::LOGIN_PAGE, [
                '_csrf_token' => $token,
                'username' => $username,
                'partnr' => $partner !== '' ? $partner : '0',
                'password' => $this->password,
            ])));
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        if (! self::hasLoggedInMarker($body)) {
            throw new RuntimeException(
                'Logowanie do '.self::HOST.' nieudane: sklep nie potwierdził zalogowania (nadal strona logowania) — sprawdź numer klienta, numer partnera i hasło'
            );
        }
        if (self::language($body) !== self::LANGUAGE) {
            $body = self::bodyOf($this->send(static fn (PendingRequest $http): Response => $http->get(self::BASE.'/?switch_locale='.self::LANGUAGE)));
            if (self::language($body) !== self::LANGUAGE) {
                throw new B2bFatalException('Sklep '.self::HOST.' nie przełączył się na język angielski (jest: '.(self::language($body) ?? 'nieznany').') — etykiety cech byłyby inne; przebieg przerwany');
            }
        }

        $this->loggedIn = true;
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    /** Strona listy artykułów (od 1). */
    public function listPage(int $page): string
    {
        return $this->accountPage(self::BASE.'/search?&pg='.max(1, $page), 'lista, strona '.$page);
    }

    /** Strona artykułu (id szczegółów z listy, data-matid). */
    public function detailPage(string $id): string
    {
        if (preg_match('/^\d+$/', $id) !== 1) {
            throw new RuntimeException('nieprawidłowy numer strony artykułu: '.$id);
        }

        return $this->accountPage(self::BASE.'/detail/'.$id, 'artykuł '.$id);
    }

    /**
     * Zdjęcie sklepu (/cache/bilder/…) sesją konta. Strona HTML zamiast obrazu (wygasła sesja) = jedno ponowne
     * logowanie; nadal HTML — błąd.
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
        $file = $this->fetchFile($url, true);
        if ($file === null) {
            $this->relogin();
            $file = $this->fetchFile($url, true);
            if ($file === null) {
                throw new RuntimeException('sklep nie wydał pliku '.$url.' (pusta treść albo strona HTML po ponownym logowaniu)');
            }
        }

        return $file;
    }

    /**
     * Strona albo mapa witryny elten.com (bez logowania); null = strony nie ma (HTTP 404 albo 410 — wyrób zdjęty
     * z witryny, nie awaria).
     */
    public function sitePage(string $url): ?string
    {
        if (! self::isSiteUrl($url)) {
            throw new RuntimeException('adres spoza '.self::SITE_HOST.': '.$url);
        }
        $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url), false, [404, 410]);
        $body = self::bodyOf($response);

        return in_array($response->status(), [404, 410], true) ? null : $body;
    }

    /**
     * PDF wyrobu z elten.com — tylko z dozwolonej ścieżki (SITE_FILE_PREFIX).
     *
     * @return array{bytes: string, mime: string}
     */
    public function siteFileBytes(string $url): array
    {
        if (! self::isSiteFileUrl($url)) {
            throw new RuntimeException('plik spoza dozwolonej ścieżki '.self::SITE_HOST.self::SITE_FILE_PREFIX.': '.$url);
        }
        $file = $this->fetchFile($url, false);
        if ($file === null) {
            throw new RuntimeException(self::SITE_HOST.' nie wydało pliku '.$url.' (pusta treść albo strona HTML)');
        }

        return $file;
    }

    public static function hasLoggedInMarker(string $html): bool
    {
        return str_contains($html, self::LOGGED_IN_MARKER);
    }

    /** Język strony sklepu z flagi w nagłówku („/build/images/flags/en.….png”); null = nie do odczytania. */
    public static function language(string $html): ?string
    {
        return preg_match('#class="flag-globe"\s+src="/build/images/flags/([a-z]{2})\.#', $html, $m) === 1 ? $m[1] : null;
    }

    public static function csrfToken(string $html): ?string
    {
        return preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $html, $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
    }

    /** https na b2b.elten.com (bez danych logowania i nietypowego portu w adresie). */
    public static function isShopUrl(string $url): bool
    {
        return self::hostOf($url) === self::HOST;
    }

    /** https na elten.com albo www.elten.com. */
    public static function isSiteUrl(string $url): bool
    {
        return in_array(self::hostOf($url), [self::SITE_HOST, 'www.'.self::SITE_HOST], true);
    }

    /** PDF wyrobu na elten.com w dozwolonej ścieżce (bez „..”). */
    public static function isSiteFileUrl(string $url): bool
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return self::isSiteUrl($url)
            && str_starts_with(rawurldecode($path), self::SITE_FILE_PREFIX)
            && ! str_contains(rawurldecode($path), '..');
    }

    /**
     * Adres ze strony (ścieżka „/cache/bilder/….jpeg” sklepu albo pełny adres elten.com ze spacjami i „®” w nazwie
     * pliku) jako adres do pobrania: znaki spoza ASCII i spacje zakodowane, reszta bez zmian.
     */
    public static function absoluteUrl(string $href, string $base = self::BASE): string
    {
        $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (str_starts_with($href, '/') && ! str_starts_with($href, '//')) {
            $href = $base.$href;
        }

        return (string) preg_replace_callback(
            '/[^\x21-\x7E]|%(?![0-9A-Fa-f]{2})/u',
            static fn (array $m): string => rawurlencode($m[0]),
            $href,
        );
    }

    private static function hostOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }

        return strtolower($parts['host'] ?? '');
    }

    /**
     * @return array{bytes: string, mime: string}|null null = serwer nie wydał pliku (brak sesji, strona HTML)
     */
    private function fetchFile(string $url, bool $withSession): ?array
    {
        $response = $this->send(static fn (PendingRequest $http): Response => $http->accept('*/*')->get($url), $withSession, [401]);
        $bytes = self::bodyOf($response);
        if ($response->status() === 401) {
            return null;
        }
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        if ($bytes === '' || $mime === 'text/html' || self::looksLikeHtml($bytes)) {
            return null;
        }
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
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($bytes, '%PDF-') => 'application/pdf',
            default => null,
        };
    }

    /**
     * Strona dla konta. HTTP 401 albo strona bez znacznika konta (wygasła sesja) = jedno ponowne logowanie; nadal bez
     * sesji = B2bFatalException (dalsze strony nie miałyby cen konta).
     */
    private function accountPage(string $url, string $label): string
    {
        if (! $this->loggedIn) {
            $this->relogin();
        }

        $body = $this->sessionBody($url);
        if ($body === null) {
            $this->relogin();
            $body = $this->sessionBody($url);
            if ($body === null) {
                $this->loggedIn = false;

                throw new B2bFatalException(self::SESSION_LOST.' ('.$label.' bez sesji konta po ponownym logowaniu)');
            }
        }

        return $body;
    }

    /** Treść strony konta; null = brak sesji (401 albo strona bez znacznika konta). */
    private function sessionBody(string $url): ?string
    {
        $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url), true, [401]);
        $body = self::bodyOf($response);
        if ($response->status() === 401) {
            return null;
        }

        return self::hasLoggedInMarker($body) ? $body : null;
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

    /** Treść odpowiedzi z zamknięciem strumienia (jak EjendalsB2bClient::bodyOf) — strona listy ma ok. 1,2 MB. */
    private static function bodyOf(Response $response): string
    {
        $stream = $response->toPsrResponse()->getBody();
        $body = (string) $stream;
        $stream->close();

        return $body;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @param  bool  $withSession  ciasteczka konta (sklep) albo bez nich (elten.com)
     * @param  list<int>  $accept  kody oddawane wołającemu zamiast błędu (401 = brak sesji)
     */
    private function send(callable $call, bool $withSession = true, array $accept = []): Response
    {
        $retries = 0;
        while (true) {
            if ($this->delayMs > 0) {
                ($this->sleep)($this->delayMs);
            }

            $response = null;
            $error = null;
            try {
                $options = ['allow_redirects' => ['max' => 5]];
                if ($withSession) {
                    $options['cookies'] = $this->jar;
                }
                $response = $call(Http::timeout(self::TIMEOUT_SECONDS)->withHeaders([
                    'Accept-Language' => $withSession ? 'en' : 'pl-PL,pl;q=0.9',
                ])->withOptions($options));
            } catch (ConnectionException $e) {
                $error = 'brak połączenia ('.$e->getMessage().')';
            }

            if ($response !== null && ($response->successful() || in_array($response->status(), $accept, true))) {
                if ($withSession) {
                    $this->consecutiveFailures = 0;
                }

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

            $error ??= ($withSession ? self::HOST : self::SITE_HOST).' odpowiedziało HTTP '.$response?->status();
            // elten.com (opisy) nie przerywa przebiegu cen — jego awarie liczy łącznik (EltenB2bConnector::SITE_FAILURE_LIMIT)
            if ($withSession && ++$this->consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
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
