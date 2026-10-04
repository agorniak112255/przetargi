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
 * Portal B2B Canis portal.canis.cz (czeska platforma sklepowa K2 „eshop5”, wersja polska pod /pl). Nieoficjalne:
 * formularz, adresy i znaczniki odczytane ze skryptów portalu (/standard/m2user/m2user.js) i ze stron zalogowanego
 * konta 04.10.2026.
 *
 * Logowanie: skrypt portalu wysyła POST /standard/m2user/post.php z polami l=1, usr, pwd; odpowiedź „1” = zalogowano,
 * inna liczba = komunikat błędu formularza (5, 6, 7). Sesja w ciasteczku PHPSESSID. reCAPTCHA jest w kodzie portalu,
 * ale wyłączona (pusty klucz data-k2-pkey) — gdyby ją włączono, logowanie z programu przestanie działać.
 *
 * Gość nie widzi niczego (szablon „pageClosed” z formularzem logowania), więc każda strona musi mieć znacznik konta:
 * odnośnik wylogowania. Bez niego — jedno ponowne logowanie i to samo żądanie jeszcze raz, nadal bez = B2bFatalException.
 * Ceny są w walucie sesji (zł dla konta; przełącznik ?cid=PLN|EUR|CZK|USD) — strona wyrobu z ceną w innej walucie
 * przełącza sesję na PLN i jest pobierana jeszcze raz.
 *
 * Strony mają 0,5–1,6 MB, z czego większość to menu kategorii w nagłówku — łącznik dostaje tylko treść <main>.
 * Pliki i zdjęcia (/imgserver/…) są publiczne. Zapytania idą po kolei, z przerwą przed każdym.
 */
final class CanisB2bClient
{
    public const HOST = 'portal.canis.cz';

    public const BASE = 'https://portal.canis.cz';

    /** Strona główna wersji polskiej — menu kategorii i potwierdzenie sesji. */
    public const HOME = self::BASE.'/pl';

    private const LOGIN_POST = self::BASE.'/standard/m2user/post.php';

    /** Odnośnik wylogowania — jest tylko na stronie zalogowanego konta. */
    private const LOGGED_IN_MARKER = '/pl/wylogowanie-uzytkownika';

    private const SESSION_LOST = 'Utracono sesję konta portal.canis.cz — ceny konta niedostępne';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503 i potknięciach sieci, gdy portal nie podał Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    private const MAX_RETRY_AFTER_MS = 120_000;

    private const TIMEOUT_SECONDS = 90;

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';

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
        private readonly int $delayMs = 300,
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
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: konto nie ma loginu (adres e-mail konta w portalu)');
        }

        try {
            $this->send(static fn (PendingRequest $http): Response => $http->get(self::HOME));
            $answer = trim(self::bodyOf($this->send(fn (PendingRequest $http): Response => $http->asForm()->withHeaders([
                'X-Requested-With' => 'XMLHttpRequest',
                'Referer' => self::HOME,
            ])->post(self::LOGIN_POST, ['l' => '1', 'usr' => $username, 'pwd' => $this->password]))));
            if ($answer !== '1') {
                throw new RuntimeException('portal odrzucił logowanie (odpowiedź „'.mb_substr($answer, 0, 20).'”) — sprawdź e-mail i hasło');
            }
            $confirmed = str_contains(self::bodyOf($this->send(static fn (PendingRequest $http): Response => $http->get(self::HOME))), self::LOGGED_IN_MARKER);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        if (! $confirmed) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: portal przyjął hasło, ale strona główna nadal jest stroną gościa');
        }

        $this->loggedIn = true;
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    /** Cała strona główna zalogowanego konta — menu kategorii jest w nagłówku, poza <main>. */
    public function homePage(): string
    {
        return (string) $this->accountPage(self::HOME, 'strona główna');
    }

    /**
     * Treść <main> strony listy kategorii (adres względny „/pl/…_c…”), strona numerowana od 1. Sortowanie podane wprost
     * (?s=1 — kod rosnąco, domyślne portalu, ale zapamiętywane w sesji).
     */
    public function categoryPage(string $path, int $page): string
    {
        $url = self::absolute($path).'?s=1&p='.max(1, $page);

        return self::mainOf((string) $this->accountPage($url, 'lista '.$path.' (strona '.$page.')'));
    }

    /**
     * Treść <main> strony wyrobu (adres z listy albo „/pl/x_p{id}” — portal rozpoznaje wyrób po numerze, nie po nazwie
     * w adresie). Cena w innej walucie niż złoty — sesja przełączona na PLN i jedno ponowne pobranie; nadal inna = błąd
     * tej strony (ceny w obcej walucie nie wolno zapisać jako ceny w zł). $allowMissing: HTTP 404 = null (wyrób
     * nadrzędny rękawic, którego portal nie pokazuje), bez niego 404 to błąd.
     */
    public function productPage(string $path, bool $allowMissing = false): ?string
    {
        $url = self::absolute($path);
        $page = $this->accountPage($url, 'strona wyrobu '.$path, $allowMissing);
        if ($page === null) {
            return null;
        }
        $main = self::mainOf($page);
        if (self::foreignCurrency($main)) {
            $this->accountPage(self::HOME.'?cid=PLN', 'przełączenie waluty na PLN');
            $main = self::mainOf((string) $this->accountPage($url, 'strona wyrobu '.$path));
            if (self::foreignCurrency($main)) {
                throw new RuntimeException('strona wyrobu '.$path.' podaje ceny w innej walucie niż PLN także po przełączeniu waluty');
            }
        }

        return $main;
    }

    /**
     * Plik albo zdjęcie z /imgserver/ portalu. PDF przychodzi bez Content-Type, a zdjęcia „.jpg” jako WebP — typ z treści.
     *
     * @return array{bytes: string, mime: string}
     */
    public function fileBytes(string $url): array
    {
        if (! self::isFileUrl($url)) {
            throw new RuntimeException('adres spoza '.self::HOST.'/imgserver: '.$url);
        }
        $response = $this->send(static fn (PendingRequest $http): Response => $http->accept('*/*')->get($url));
        $bytes = self::bodyOf($response);
        $head = strtolower(ltrim(substr($bytes, 0, 512)));
        if ($bytes === '' || str_starts_with($head, '<!doctype html') || str_starts_with($head, '<html')) {
            throw new RuntimeException('portal nie wydał pliku '.$url.' (pusta treść albo strona HTML)');
        }

        return ['bytes' => $bytes, 'mime' => self::mimeOf($bytes, (string) $response->header('Content-Type'))];
    }

    /** Typ pliku z pierwszych bajtów (PDF, JPEG, PNG, WebP, GIF); inaczej z nagłówka, pusty = octet-stream. */
    public static function mimeOf(string $bytes, string $header = ''): string
    {
        return match (true) {
            str_starts_with($bytes, '%PDF-') => 'application/pdf',
            str_starts_with($bytes, "\xFF\xD8\xFF") => 'image/jpeg',
            str_starts_with($bytes, "\x89PNG") => 'image/png',
            str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP' => 'image/webp',
            str_starts_with($bytes, 'GIF8') => 'image/gif',
            default => ($mime = strtolower(trim(explode(';', $header)[0]))) !== '' ? $mime : 'application/octet-stream',
        };
    }

    /** https://portal.canis.cz/imgserver/… (bez danych logowania, portu i „..” w ścieżce). */
    public static function isFileUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }
        $path = (string) ($parts['path'] ?? '');

        return strtolower($parts['host'] ?? '') === self::HOST && str_starts_with($path, '/imgserver/') && ! str_contains($path, '..');
    }

    /** Adres portalu z adresu względnego „/pl/…” albo pełnego na portal.canis.cz; inny host = błąd. */
    public static function absolute(string $path): string
    {
        $path = trim($path);
        if (str_starts_with($path, '/') && ! str_starts_with($path, '//')) {
            return self::BASE.$path;
        }
        $host = strtolower((string) parse_url($path, PHP_URL_HOST));
        if (str_starts_with(strtolower($path), 'https://') && $host === self::HOST) {
            return $path;
        }

        throw new RuntimeException('adres spoza '.self::HOST.': '.$path);
    }

    /** Treść od <main> do </main>; bez znacznika — cała strona (zmiana szablonu nie może zgubić danych). */
    public static function mainOf(string $html): string
    {
        $start = stripos($html, '<main');
        if ($start === false) {
            return $html;
        }
        $end = strripos($html, '</main>');

        return $end !== false && $end > $start ? substr($html, $start, $end + 7 - $start) : substr($html, $start);
    }

    /**
     * Cena strony w innej walucie niż złoty: schema.org priceCurrency albo data-currency wierszy wariantów. Strona bez
     * żadnej ceny (wyrób wycofany) — nie.
     */
    private static function foreignCurrency(string $html): bool
    {
        preg_match_all('/itemprop="priceCurrency"\s+content="([A-Z]{3})"/', $html, $codes);
        preg_match_all('/data-currency="([^"]*)"/', $html, $symbols);
        foreach ($codes[1] as $code) {
            if ($code !== 'PLN') {
                return true;
            }
        }
        foreach ($symbols[1] as $symbol) {
            if (trim(html_entity_decode($symbol, ENT_QUOTES | ENT_HTML5, 'UTF-8')) !== 'zł') {
                return true;
            }
        }

        return false;
    }

    /**
     * Strona HTML zalogowanego konta; bez znacznika sesji — jedno ponowne logowanie i powtórka. $allowMissing: HTTP 404
     * = null.
     */
    private function accountPage(string $url, string $label, bool $allowMissing = false): ?string
    {
        if (! $this->loggedIn) {
            $this->relogin();
        }
        $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url), $allowMissing);
        if ($response->status() === 404) {
            return null;
        }
        $body = self::bodyOf($response);
        if (str_contains($body, self::LOGGED_IN_MARKER)) {
            return $body;
        }

        $this->relogin();
        $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url), $allowMissing);
        if ($response->status() === 404) {
            return null;
        }
        $body = self::bodyOf($response);
        if (! str_contains($body, self::LOGGED_IN_MARKER)) {
            $this->loggedIn = false;

            throw new B2bFatalException(self::SESSION_LOST.' ('.$label.' bez konta po ponownym logowaniu)');
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
     * @param  bool  $allowNotFound  HTTP 404 wraca do wołającego (strona, której może nie być)
     */
    private function send(callable $call, bool $allowNotFound = false): Response
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
                    'User-Agent' => self::USER_AGENT,
                    'Accept-Language' => 'pl',
                ])->withOptions(['cookies' => $this->jar, 'allow_redirects' => ['max' => 5]]));
            } catch (ConnectionException $e) {
                $error = 'brak połączenia ('.$e->getMessage().')';
            }

            if ($response !== null && ($response->successful() || ($allowNotFound && $response->status() === 404))) {
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
