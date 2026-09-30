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
 * Witryna producenta Ejendals www.ejendals.com (JALAS, TEGERA, GRANINGE) ze sklepem dla firm. Nieoficjalne: adresy
 * i pola odczytane ze skryptów strony (main.js, ProductCard, AddLineItemRow) i z odpowiedzi konta #20 30.09.2026.
 *
 * Strona jest aplikacją Vue na JSON-owym API (/api/v2/…). Logowanie jak w skrypcie strony: formularz OAuth
 * (grant_type=password, client_id=web) pod /api/v2/connects/token. Witryna NIE oddaje access_token w JSON-ie
 * (tylko token_type, expires_in, refresh_token) — sesją jest ciasteczko HttpOnly EjendalsAuthToken ważne ok. 15 min
 * (expires_in 900), więc przed wygaśnięciem logujemy się od nowa. Rynek i język idą nagłówkami Ejendals-User-Market
 * i Accept-Language — bez nich API odpowiada rynkiem domyślnym po angielsku (623 zamiast 609 wyrobów). Zalogowana
 * lista wyrobów (/api/v2/search/products) ma przy każdym wyrobie ceny konta (model, articles); rozmiary z EAN i strona
 * wyrobu są publiczne. Zapytania idą po kolei, z przerwą przed każdym.
 */
final class EjendalsB2bClient
{
    public const HOST = 'ejendals.com';

    public const BASE = 'https://www.ejendals.com';

    /** Rynek i język polskiej witryny (identyfikator rynku z /api/v2/markets/get). */
    public const MARKET = 'pl';

    private const LANGUAGE = 'pl';

    private const TOKEN = self::BASE.'/api/v2/connects/token';

    private const PERMISSIONS = self::BASE.'/api/v2/membership/user/current/get/permissions';

    private const PRODUCTS = self::BASE.'/api/v2/search/products';

    private const VARIANTS = self::BASE.'/api/v2/products/get/pdp/{code}/variants';

    private const CARD = self::BASE.'/api/v2/products/get/{code}/card';

    /** Ciasteczko sesji konta ustawiane przez /api/v2/connects/token. */
    public const SESSION_COOKIE = 'EjendalsAuthToken';

    /** Uprawnienie konta, bez którego sklep nie pokazuje cen (ProductCard: Permission „CartPermissions.Prices”). */
    public const PRICE_PERMISSION = 'CartPermissions.Prices';

    /** Pliki i zdjęcia wyrobów leżą na tej samej witrynie (/…/globalassets/pim/media/…). */
    private const FILE_HOSTS = ['www.ejendals.com', 'ejendals.com'];

    private const SESSION_LOST = 'Utracono sesję konta ejendals.com — ceny konta niedostępne';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503 i potknięciach sieci, gdy witryna nie podała Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    private const MAX_RETRY_AFTER_MS = 120_000;

    /** Zalogowana strona listy po 100 wyrobów odpowiada ok. 37 s (konto #20, 30.09.2026) — z zapasem. */
    private const TIMEOUT_SECONDS = 150;

    /** Sesję odnawiamy tyle sekund przed końcem jej ważności (expires_in). */
    private const SESSION_MARGIN_SECONDS = 60;

    private CookieJar $jar;

    private bool $loggedIn = false;

    private float $sessionExpiresAt = 0.0;

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
        $this->sessionExpiresAt = 0.0;

        $username = trim($this->username);
        if ($username === '') {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: konto nie ma loginu (adres e-mail)');
        }

        try {
            $response = $this->send(fn (PendingRequest $http): Response => $http->asForm()->post(self::TOKEN, [
                'username' => $username,
                'password' => trim($this->password),
                'client_id' => 'web',
                'grant_type' => 'password',
                'scope' => 'offline_access',
            ]), acceptStatuses: [400, 401]);
            $json = self::jsonOf($response);
            if (! $response->successful() || $this->jar->getCookieByName(self::SESSION_COOKIE) === null) {
                $message = is_array($json)
                    ? trim((string) ($json['error_description'] ?? $json['message'] ?? $json['error'] ?? ''))
                    : '';

                throw new RuntimeException('witryna odrzuciła logowanie (HTTP '.$response->status().')'
                    .($message !== '' ? ': '.$message : '').' — sprawdź login (adres e-mail) i hasło');
            }
            $expires = is_array($json) && is_numeric($json['expires_in'] ?? null) ? (int) $json['expires_in'] : 0;

            $permissions = self::jsonOf($this->send(static fn (PendingRequest $http): Response => $http->get(self::PERMISSIONS)));
            if (! is_array($permissions) || ! array_is_list($permissions)) {
                throw new RuntimeException('witryna nie podała uprawnień konta po zalogowaniu');
            }
            if (! in_array(self::PRICE_PERMISSION, $permissions, true)) {
                throw new RuntimeException('konto nie ma uprawnienia do cen ('.self::PRICE_PERMISSION.') — sklep nie pokaże mu cen');
            }
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        $this->loggedIn = true;
        $this->sessionExpiresAt = $expires > 0 ? microtime(true) + $expires : 0.0;
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    /**
     * Strona listy wyrobów rynku PL (numerowana od 1): {totalMatching, totalMatchWithFilter, products} — z cenami
     * konta przy wyrobach (model, articles), bo idzie z sesją konta, odnowioną, gdy dobiega końca jej ważność.
     *
     * @return array<string, mixed>
     */
    public function listPage(int $page, int $pageSize): array
    {
        $this->ensureSession();
        $json = self::jsonOf($this->send(static fn (PendingRequest $http): Response => $http->get(self::PRODUCTS, [
            'page' => max(1, $page),
            'pageSize' => $pageSize,
        ])));
        if (! is_array($json) || ! is_array($json['products'] ?? null) || ! array_key_exists('totalMatching', $json)) {
            throw new RuntimeException('Strona '.$page.' listy '.self::HOST.' bez oczekiwanego JSON-a (products, totalMatching)');
        }

        return $json;
    }

    /**
     * Rozmiary wyrobu: [{code, specification: [{label, value}], sizes: {eu, …}}] — publiczne.
     *
     * @return list<array<string, mixed>>
     */
    public function variants(string $code): array
    {
        $json = self::jsonOf($this->send(static fn (PendingRequest $http): Response => $http->get(
            str_replace('{code}', rawurlencode($code), self::VARIANTS)
        )));
        if (! is_array($json) || ! array_is_list($json)) {
            throw new RuntimeException('rozmiary wyrobu '.$code.' bez oczekiwanego JSON-a (lista)');
        }

        return array_values(array_filter($json, 'is_array'));
    }

    /**
     * Karta wyrobu (publiczna; z sesją konta także z cenami) — łącznik bierze z niej kategorie po polsku, które lista
     * podaje po angielsku.
     *
     * @return array<string, mixed>
     */
    public function card(string $code): array
    {
        $json = self::jsonOf($this->send(static fn (PendingRequest $http): Response => $http->get(
            str_replace('{code}', rawurlencode($code), self::CARD)
        )));
        if (! is_array($json) || ! array_key_exists('code', $json)) {
            throw new RuntimeException('karta wyrobu '.$code.' bez oczekiwanego JSON-a');
        }

        return $json;
    }

    /** Strona wyrobu (HTML, polska wersja witryny); null = 404 (martwy odnośnik z listy). */
    public function productPage(string $url): ?string
    {
        if (! self::isAllowedFileUrl($url)) {
            throw new RuntimeException('strona wyrobu spoza '.self::HOST.': '.$url);
        }
        $response = $this->send(static fn (PendingRequest $http): Response => $http->accept('text/html')->get($url), acceptStatuses: [404]);

        return $response->status() === 404 ? null : self::bodyOf($response);
    }

    /**
     * @return array{bytes: string, mime: string}
     */
    public function fileBytes(string $url): array
    {
        if (! self::isAllowedFileUrl($url)) {
            throw new RuntimeException('plik spoza '.self::HOST.': '.$url);
        }
        $response = $this->send(static fn (PendingRequest $http): Response => $http->accept('*/*')->get($url));
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        return ['bytes' => self::bodyOf($response), 'mime' => $mime];
    }

    /**
     * Adres bezwzględny z pola href/url witryny („/pl/products/…” albo pełny); spoza witryny albo pusty → null.
     */
    public static function absoluteUrl(string $href): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5));
        if ($href === '') {
            return null;
        }
        if (str_starts_with($href, '//')) {
            $href = 'https:'.$href;
        } elseif (str_starts_with($href, '/')) {
            return self::BASE.$href;
        }

        return self::isAllowedFileUrl($href) ? $href : null;
    }

    /** https na witrynie producenta (bez danych logowania i nietypowego portu w adresie). */
    public static function isAllowedFileUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        return in_array(strtolower($parts['host'] ?? ''), self::FILE_HOSTS, true);
    }

    /** Sesja dobiega końca (expires_in z logowania) — logujemy się od nowa, zanim witryna odeśle listę bez cen. */
    private function ensureSession(): void
    {
        if (! $this->loggedIn
            || ($this->sessionExpiresAt > 0 && microtime(true) > $this->sessionExpiresAt - self::SESSION_MARGIN_SECONDS)) {
            $this->relogin();
        }
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
     * Treść odpowiedzi z zamknięciem strumienia. Odpowiedź klienta HTTP Laravela siedzi w cyklu obiektów i trzyma
     * treść (strona wyrobu ok. 860 KB) do najbliższego sprzątania cykli przez PHP — na serwerze 30.09.2026 przybywało
     * ok. 0,8 MB na wyrób, tinker padł na 128 MB po ~75 wyrobach, a 609 stron to blisko limitu przebiegu (512 MB).
     * Zamknięty strumień zwalnia treść od razu; odpowiedź czytamy tylko raz.
     */
    private static function bodyOf(Response $response): string
    {
        $stream = $response->toPsrResponse()->getBody();
        $body = (string) $stream;
        $stream->close();

        return $body;
    }

    /**
     * @return array<mixed>|null
     */
    private static function jsonOf(Response $response): ?array
    {
        $json = json_decode(self::bodyOf($response), true);

        return is_array($json) ? $json : null;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @param  list<int>  $acceptStatuses  statusy oddawane wołającemu zamiast błędu
     */
    private function send(callable $call, array $acceptStatuses = []): Response
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
                    'Accept' => 'application/json',
                    'Accept-Language' => self::LANGUAGE,
                    'Ejendals-User-Market' => self::MARKET,
                    'X-Requested-With' => 'XMLHttpRequest',
                ])->withOptions([
                    'cookies' => $this->jar,
                    'allow_redirects' => ['max' => 5, 'track_redirects' => true],
                ]));
            } catch (ConnectionException $e) {
                $error = 'brak połączenia ('.$e->getMessage().')';
            }

            if ($response !== null && ($response->successful() || in_array($response->status(), $acceptStatuses, true))) {
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
