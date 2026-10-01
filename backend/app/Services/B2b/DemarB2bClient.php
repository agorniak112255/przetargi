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
 * Hurtownia producenta obuwia Demar hurt.demar24.pl (IdoSell, jak signproject.pl). Nieoficjalne: formularz, adresy
 * i znaczniki odczytane ze stron zalogowanego konta 01.10.2026.
 *
 * Gość widzi tylko stronę powitalną z formularzem logowania (strony produktów przekierowują na nią), ale
 * ajax/projector.php odpowiada także gościowi — z cenami detalicznymi zamiast cen konta (01.10.2026: 220,33 zł
 * netto zamiast 166,00 zł). Dlatego sesję sprawdza strona produktu (odnośnik „Wyloguj się” =
 * /login.php?operation=logout, jest tylko na stronach konta), a łącznik pobiera dane rozmiarów ponownie, gdy przy
 * stronie produktu trzeba było logować się jeszcze raz (logins()).
 *
 * Logowanie: POST /signin.php (operation=login, login, password), sesja w ciasteczkach. Zapytania po kolei, z przerwą
 * przed każdym (sklep IdoSell szereguje zapytania jednej sesji — SignProjectB2bClient).
 */
final class DemarB2bClient
{
    public const HOST = 'hurt.demar24.pl';

    public const BASE = 'https://hurt.demar24.pl';

    private const SIGNIN = self::BASE.'/signin.php';

    private const SITEMAP_INDEX = self::BASE.'/sitemap.xml.gz';

    private const PROJECTOR = self::BASE.'/ajax/projector.php';

    private const PROJECTOR_GET = 'sizes,sizeprices';

    /** Odnośnik wylogowania — jest tylko na stronach zalogowanego konta. */
    private const LOGGED_IN_MARKER = 'operation=logout';

    private const SESSION_LOST = 'Utracono sesję konta hurt.demar24.pl — ceny konta niedostępne';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503 i potknięciach sieci, gdy sklep nie podał Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    private const MAX_RETRY_AFTER_MS = 120_000;

    private const TIMEOUT_SECONDS = 60;

    private CookieJar $jar;

    private bool $loggedIn = false;

    /** Udane logowania w tym przebiegu — łącznik porównuje je przed i po stronie produktu. */
    private int $logins = 0;

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
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: konto nie ma loginu');
        }

        try {
            // strona powitalna zakłada sesję (ciasteczka), jak w przeglądarce
            $this->send(static fn (PendingRequest $http): Response => $http->get(self::BASE.'/'));
            $response = $this->send(fn (PendingRequest $http): Response => $http->asForm()->post(self::SIGNIN, [
                'operation' => 'login',
                'login' => $username,
                'password' => $this->password,
            ]));
            $confirmed = self::hasLoggedInMarker(self::bodyOf($response))
                || self::hasLoggedInMarker(self::bodyOf($this->send(static fn (PendingRequest $http): Response => $http->get(self::BASE.'/'))));
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        if (! $confirmed) {
            throw new RuntimeException(
                'Logowanie do '.self::HOST.' nieudane: sklep nie potwierdził zalogowania (brak „Wyloguj się” na stronie) — sprawdź login i hasło'
            );
        }

        $this->loggedIn = true;
        $this->logins++;
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    public function logins(): int
    {
        return $this->logins;
    }

    /**
     * Produkty z mapy strony (/product-pol-{id}-{nazwa}.html): id → adres, w kolejności mapy. Podmapa, której nie
     * da się pobrać albo odczytać, przerywa listę — niepełna lista nie może udawać całej oferty.
     *
     * @return array<int, string>
     */
    public function sitemapProducts(): array
    {
        try {
            $index = self::decodeSitemap(self::bodyOf($this->send(static fn (PendingRequest $http): Response => $http->get(self::SITEMAP_INDEX))));
            $children = str_contains($index, '<sitemapindex') ? self::locations($index) : [];
            if (str_contains($index, '<sitemapindex') && $children === []) {
                throw new RuntimeException('indeks mapy strony bez podmap');
            }
            $documents = $children === [] ? [$index] : [];
            foreach ($children as $child) {
                if (! self::isShopUrl($child)) {
                    throw new RuntimeException('podmapa spoza '.self::HOST.': '.$child);
                }
                $documents[] = self::decodeSitemap(self::bodyOf($this->send(static fn (PendingRequest $http): Response => $http->get($child))));
            }
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Nie udało się pobrać mapy strony '.self::HOST.': '.$e->getMessage(), 0, $e);
        }

        $out = [];
        foreach ($documents as $xml) {
            if (! str_contains($xml, '<urlset')) {
                throw new RuntimeException('Mapa strony '.self::HOST.' ma nieznany format');
            }
            foreach (self::locations($xml) as $url) {
                if (preg_match('#^https://hurt\.demar24\.pl/product-pol-(\d+)-[^/?\#]*\.html$#i', $url, $m) === 1) {
                    $out[(int) $m[1]] ??= $url;
                }
            }
        }

        return $out;
    }

    /**
     * Dane produktu z ajax/projector.php (rozmiary z cenami konta i stanem, ceny przed rabatem). Odpowiedź bez sesji
     * ma ceny detaliczne — łącznik sprawdza sesję stroną produktu.
     *
     * @return array<string, mixed>
     */
    public function projector(int $id): array
    {
        if (! $this->loggedIn) {
            $this->relogin();
        }
        $body = self::bodyOf($this->send(
            static fn (PendingRequest $http): Response => $http->acceptJson()->get(self::PROJECTOR, ['product' => $id, 'get' => self::PROJECTOR_GET]),
        ));
        $json = json_decode($body, true);
        if (! is_array($json)) {
            throw new RuntimeException('odpowiedź '.self::HOST.' dla produktu '.$id.' nie jest poprawnym JSON');
        }

        return $json;
    }

    /**
     * Strona produktu dla konta. Strona bez odnośnika wylogowania (wygasła sesja → strona powitalna) = jedno ponowne
     * logowanie; nadal bez niego = B2bFatalException (dalsze strony nie miałyby cen konta).
     */
    public function productPage(string $url): string
    {
        if (! self::isShopUrl($url)) {
            throw new RuntimeException('adres spoza '.self::HOST.': '.$url);
        }
        if (! $this->loggedIn) {
            $this->relogin();
        }

        $body = self::bodyOf($this->send(static fn (PendingRequest $http): Response => $http->get($url)));
        if (! self::hasLoggedInMarker($body)) {
            $this->relogin();
            $body = self::bodyOf($this->send(static fn (PendingRequest $http): Response => $http->get($url)));
            if (! self::hasLoggedInMarker($body)) {
                $this->loggedIn = false;

                throw new B2bFatalException(self::SESSION_LOST.' (strona produktu bez sesji konta po ponownym logowaniu)');
            }
        }

        return $body;
    }

    /**
     * Zdjęcie spod adresu ze strony produktu. Strona HTML zamiast obrazu = błąd (takiej treści nie zapisujemy).
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
        if ($bytes === '' || $mime === 'text/html' || self::looksLikeHtml($bytes)) {
            throw new RuntimeException('sklep nie wydał pliku '.$url.' (pusta treść albo strona HTML)');
        }

        return ['bytes' => $bytes, 'mime' => $mime];
    }

    public static function hasLoggedInMarker(string $html): bool
    {
        return str_contains($html, self::LOGGED_IN_MARKER);
    }

    /** https na hurt.demar24.pl (bez danych logowania i nietypowego portu w adresie). */
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

    private static function looksLikeHtml(string $bytes): bool
    {
        $head = strtolower(ltrim(substr($bytes, 0, 512)));

        return str_starts_with($head, '<!doctype html') || str_starts_with($head, '<html');
    }

    /** Pliki .xml.gz bywają podawane już rozpakowane. */
    private static function decodeSitemap(string $bytes): string
    {
        if (str_starts_with($bytes, "\x1f\x8b")) {
            $decoded = @gzdecode($bytes);
            if ($decoded !== false) {
                return $decoded;
            }
        }

        return $bytes;
    }

    /**
     * @return list<string>
     */
    private static function locations(string $xml): array
    {
        preg_match_all('#<loc>\s*([^<]+?)\s*</loc>#i', $xml, $m);

        return array_map(static fn (string $loc): string => html_entity_decode($loc, ENT_QUOTES | ENT_XML1, 'UTF-8'), $m[1]);
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
