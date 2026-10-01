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
 * Portal partnerski Hultafors Group partnerportal.hultaforsgroup.pl (platforma „One Master”, ASP.NET MVC; polskie konto,
 * ceny w PLN). Nieoficjalne: formularz, adresy i znaczniki odczytane ze stron zalogowanego konta 01.10.2026.
 *
 * Cały portal jest tylko dla zalogowanych — gość dostaje przekierowanie na /User/Login. Logowanie: strona logowania
 * zakłada sesję i wydaje token formularza (__RequestVerificationToken w #loginform), POST /user/login z User.UserName
 * i User.Password, sesja w ciasteczkach. Strona konta ma w ukrytym polu nazwę zalogowanego użytkownika
 * (id="CurrentUserName" z wartością) — strona logowania ma to pole puste i formularz id="loginform". Odpowiedź bez
 * sesji (także fragment HTML z ProductUpdateList) to strona logowania po przekierowaniu.
 *
 * Listy (katalog i tabele pozycji wyrobu) przychodzą z POST /pl/Catalog/ProductUpdateList z polami nawigacji listy
 * (ukryte pola .js-productlist_navigation_data na stronie). Pliki (/Image/GetDocument/…) bez sesji to strona HTML
 * z kodem 200 — rodzaj treści trzeba sprawdzać. Zapytania idą po kolei, z przerwą przed każdym.
 */
final class HultaforsB2bClient
{
    public const HOST = 'partnerportal.hultaforsgroup.pl';

    public const BASE = 'https://partnerportal.hultaforsgroup.pl';

    private const LOGIN_PAGE = self::BASE.'/user/login?ReturnUrl=%2f';

    private const LOGIN_POST = self::BASE.'/user/login';

    private const LIST_URL = self::BASE.'/pl/Catalog/ProductUpdateList';

    /** Formularz logowania — jest tylko na stronie logowania (także po przekierowaniu wygasłej sesji). */
    private const LOGIN_FORM_MARKER = 'id="loginform"';

    private const SESSION_LOST = 'Utracono sesję konta partnerportal.hultaforsgroup.pl — ceny konta niedostępne';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503 i potknięciach sieci, gdy portal nie podał Retry-After (ms). */
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
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: konto nie ma loginu (adres e-mail konta w portalu)');
        }

        try {
            $form = self::bodyOf($this->send(static fn (PendingRequest $http): Response => $http->get(self::LOGIN_PAGE)));
            $token = self::loginToken($form);
            if ($token === null) {
                throw new RuntimeException('strona logowania bez formularza (#loginform z tokenem) — zmiana portalu?');
            }
            $page = self::bodyOf($this->send(fn (PendingRequest $http): Response => $http->asForm()->post(self::LOGIN_POST, [
                '__RequestVerificationToken' => $token,
                'ReturnUrl' => '/',
                'KeepBasket' => 'False',
                'User.UserName' => $username,
                'User.Password' => $this->password,
            ])));
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        if (! self::isAccountPage($page)) {
            throw new RuntimeException(
                'Logowanie do '.self::HOST.' nieudane: portal nie potwierdził zalogowania (nadal strona logowania) — sprawdź e-mail i hasło'
            );
        }

        $this->loggedIn = true;
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    /**
     * Strona portalu (lista działu, strona wyrobu) — ścieżka albo pełny adres portalu. $fragment = sekcja ładowana
     * przez skrypt strony (zakładki wyrobu: /pl/ProductDetail/TabsSection/…), wysyłana z nagłówkiem zapytania skryptu.
     */
    public function page(string $path, bool $fragment = false): string
    {
        $url = self::urlFor($path);
        $headers = $fragment ? ['X-Requested-With' => 'XMLHttpRequest'] : [];

        return $this->accountResponse(
            static fn (PendingRequest $http): Response => $http->withHeaders($headers)->get($url),
            'strona '.$path,
        );
    }

    /**
     * Fragment listy (katalog albo tabela pozycji wyrobu) z ProductUpdateList — pola nawigacji listy ze strony
     * plus numer i rozmiar strony.
     *
     * @param  array<string, string|int>  $fields
     */
    public function listFragment(array $fields): string
    {
        return $this->accountResponse(
            static fn (PendingRequest $http): Response => $http->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
                ->asForm()->post(self::LIST_URL, $fields),
            'lista '.($fields['listvalue'] ?? '?').' strona '.($fields['page'] ?? '?'),
        );
    }

    /**
     * Plik albo zdjęcie spod adresu portalu, sesją konta. Strona HTML zamiast pliku (wygasła sesja → strona logowania)
     * = jedno ponowne logowanie; nadal HTML — błąd (takiej treści nie zapisujemy jako pliku).
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
                throw new RuntimeException('portal nie wydał pliku '.$url.' (pusta treść albo strona HTML po ponownym logowaniu)');
            }
        }

        return $file;
    }

    /** Strona zalogowanego konta: pole CurrentUserName z wartością i bez formularza logowania. */
    public static function isAccountPage(string $html): bool
    {
        return ! str_contains($html, self::LOGIN_FORM_MARKER)
            && preg_match('/id="CurrentUserName"\s+value="[^"]+"/', $html) === 1;
    }

    /** https na partnerportal.hultaforsgroup.pl (bez danych logowania i nietypowego portu w adresie). */
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
     * Pełny adres ze ścieżki portalu („/pl/product/…--6220”) albo z pełnego adresu portalu; inny host, „..” albo pusta
     * ścieżka = wyjątek.
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

    /** Token formularza logowania (pierwsze pole __RequestVerificationToken po #loginform); null = brak formularza. */
    private static function loginToken(string $html): ?string
    {
        if (preg_match('/id="loginform".*?name="__RequestVerificationToken"[^>]*value="([^"]+)"/s', $html, $m) !== 1) {
            return null;
        }

        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Odpowiedź dla konta. Strona logowania (wygasła sesja) = jedno ponowne logowanie; nadal strona logowania =
     * B2bFatalException (dalsze strony nie miałyby cen konta). Fragmenty listy nie mają pola CurrentUserName, więc
     * brak sesji rozpoznajemy po formularzu logowania.
     *
     * @param  callable(PendingRequest): Response  $call
     */
    private function accountResponse(callable $call, string $label): string
    {
        if (! $this->loggedIn) {
            $this->relogin();
        }

        $body = self::bodyOf($this->send($call));
        if (str_contains($body, self::LOGIN_FORM_MARKER)) {
            $this->relogin();
            $body = self::bodyOf($this->send($call));
            if (str_contains($body, self::LOGIN_FORM_MARKER)) {
                $this->loggedIn = false;

                throw new B2bFatalException(self::SESSION_LOST.' ('.$label.' bez sesji konta po ponownym logowaniu)');
            }
        }

        return $body;
    }

    /**
     * @return array{bytes: string, mime: string}|null null = portal nie wydał pliku (brak sesji)
     */
    private function fetchFile(string $url): ?array
    {
        $response = $this->send(static fn (PendingRequest $http): Response => $http->accept('*/*')->get($url));
        $bytes = self::bodyOf($response);
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
        $head = strtolower(ltrim(substr($bytes, 0, 512), "\xEF\xBB\xBF \t\r\n"));

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
