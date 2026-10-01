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
 * Sklep B2B producenta Safety Jogger order.safetyjogger.com (aplikacja Angular na JSON-owym API „/action/…”).
 * Nieoficjalne: adresy, nagłówki i pola odczytane ze skryptu sklepu i z odpowiedzi zalogowanego konta 01.10.2026.
 *
 * Logowanie: POST /action/login z JSON {username, password, rememberMe}; odpowiedź {success: true, user: {…}} niesie
 * applicationId, authenticationToken, waluty i region magazynu konta. Każde zapytanie API idzie z nagłówkami jak
 * w sklepie (X-Language-Code, X-Application-Id, X-application-Name „SAFETY”, X-Auth-User, X-Auth-Token) i ciasteczkiem
 * sesji (JSESSIONID). Bez nagłówka aplikacji ceny odpowiadają „Application ID not specified” (HTTP 500), bez sesji
 * lista wyrobów — HTTP 401: to jedno ponowne logowanie, nadal 401 = B2bFatalException.
 *
 * Tłumaczenia sklepu (/i18n/SAFETY/{język}.json), pliki (/document/…) i zdjęcia (/picture/…) są publiczne — sklep
 * wydaje je także bez sesji. Zapytania idą po kolei, z przerwą przed każdym.
 */
final class SafetyJoggerB2bClient
{
    public const HOST = 'order.safetyjogger.com';

    public const BASE = 'https://order.safetyjogger.com';

    /** Nazwa aplikacji sklepu (environment.applicationName w skrypcie sklepu). */
    private const APPLICATION_NAME = 'SAFETY';

    private const LANGUAGE = 'pl';

    private const SESSION_LOST = 'Utracono sesję konta order.safetyjogger.com — ceny konta niedostępne';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503 i potknięciach sieci, gdy sklep nie podał Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    private const MAX_RETRY_AFTER_MS = 120_000;

    private const TIMEOUT_SECONDS = 90;

    private CookieJar $jar;

    /** @var array<string, mixed>|null użytkownik z odpowiedzi logowania; null = brak sesji */
    private ?array $user = null;

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
        $this->user = null;

        $username = trim($this->username);
        if ($username === '') {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: konto nie ma loginu (adres e-mail konta w sklepie)');
        }

        $body = (string) json_encode(['username' => $username, 'password' => $this->password, 'rememberMe' => false]);
        try {
            $response = $this->send(
                fn (PendingRequest $http): Response => $http->withBody($body, 'application/json')->post(self::BASE.'/action/login'),
                allowClientErrors: true,
            );
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        $json = self::decode(self::bodyOf($response));
        $user = is_array($json['user'] ?? null) ? $json['user'] : null;
        if (! $response->successful() || ($json['success'] ?? null) !== true || $user === null
            || trim((string) ($user['authenticationToken'] ?? '')) === '' || ! is_int($user['applicationId'] ?? null)) {
            $message = trim((string) ($json['message'] ?? $json['key'] ?? ''));

            throw new RuntimeException(
                'Logowanie do '.self::HOST.' nieudane (HTTP '.$response->status().($message !== '' ? ', '.$message : '').') — sprawdź e-mail i hasło'
            );
        }

        $this->user = $user;
    }

    public function isLoggedIn(): bool
    {
        return $this->user !== null;
    }

    /** Waluta cen konta (authorityGroup.defaultCurrency); '' = sklep jej nie podał. */
    public function currency(): string
    {
        return strtoupper(trim((string) ($this->user['authorityGroup']['defaultCurrency'] ?? '')));
    }

    /** Region magazynu konta (authorityGroup.stockRegionId) — stan pozycji liczy się tylko z tego regionu. */
    public function stockRegionId(): ?int
    {
        $id = $this->user['authorityGroup']['stockRegionId'] ?? null;

        return is_int($id) ? $id : null;
    }

    /** Czy konto widzi ceny (uprawnienie SEE_PRICES, jak w sklepie). */
    public function seesPrices(): bool
    {
        return is_array($this->user['authorities'] ?? null) && array_key_exists('SEE_PRICES', $this->user['authorities']);
    }

    /**
     * Strona listy wyrobów (/action/product): {page, itemsPerPage, numberOfItems, products, ids}.
     *
     * @return array<string, mixed>
     */
    public function listPage(int $page, int $perPage): array
    {
        return $this->apiJson('/action/product?page='.$page.'&itemsPerPage='.$perPage, 'lista wyrobów, strona '.$page);
    }

    /**
     * Pozycje koloru (rozmiary) z ceną, EAN-em i stanem — to samo, co strona wyrobu wczytuje do koszyka
     * (/action/shoppingcart/itemsForProductColor/{id koloru}).
     *
     * @return list<array<string, mixed>>
     */
    public function colourItems(int $colourId): array
    {
        return array_values($this->apiJson('/action/shoppingcart/itemsForProductColor/'.$colourId, 'rozmiary koloru '.$colourId));
    }

    /**
     * Pliki wyrobu (/action/document/{id}): karty produktu, deklaracje zgodności, certyfikaty, zdjęcia.
     *
     * @return list<array<string, mixed>>
     */
    public function documents(int $productId): array
    {
        return array_values($this->apiJson('/action/document/'.$productId, 'pliki wyrobu '.$productId));
    }

    /**
     * Tłumaczenia sklepu (klucz → tekst) w danym języku — pliki aplikacji, publiczne.
     *
     * @return array<string, string>
     */
    public function translations(string $language): array
    {
        if (preg_match('/^[a-z]{2}$/', $language) !== 1) {
            throw new RuntimeException('nieznany język tłumaczeń: '.$language);
        }
        $response = $this->send(static fn (PendingRequest $http): Response => $http->get(self::BASE.'/i18n/'.self::APPLICATION_NAME.'/'.$language.'.json'));
        $json = self::decode(self::bodyOf($response));
        $out = [];
        foreach ($json as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $out[$key] = $value;
            }
        }
        if ($out === []) {
            throw new RuntimeException('tłumaczenia sklepu ('.$language.') puste albo nieczytelne');
        }

        return $out;
    }

    /**
     * Plik albo zdjęcie sklepu (/document/…, /picture/…). Strona HTML zamiast pliku = błąd (takiej treści nie
     * zapisujemy jako pliku).
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
        $head = strtolower(ltrim(substr($bytes, 0, 512)));
        if ($bytes === '' || $mime === 'text/html' || str_starts_with($head, '<!doctype html') || str_starts_with($head, '<html')) {
            throw new RuntimeException('sklep nie wydał pliku '.$url.' (pusta treść albo strona HTML)');
        }

        return ['bytes' => $bytes, 'mime' => $mime];
    }

    /** https na order.safetyjogger.com (bez danych logowania i nietypowego portu w adresie). */
    public static function isShopUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        return strtolower($parts['host'] ?? '') === self::HOST && ! str_contains((string) ($parts['path'] ?? ''), '..');
    }

    /**
     * Pełny adres pliku ze ścieżki ze sklepu („/document/PRODUCT_SHEET/FYTS1PSL/pl/FYTS1PSL_pl.pdf”) — każdy człon
     * zakodowany (nazwy plików mają spacje: „FREEDOM S1PS LOW.pdf”); null = ścieżka pusta, względna albo z „..”.
     */
    public static function fileUrl(string $path): ?string
    {
        $path = trim($path);
        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '..')) {
            return null;
        }
        $segments = array_map('rawurlencode', array_map('rawurldecode', explode('/', ltrim($path, '/'))));

        return self::BASE.'/'.implode('/', $segments);
    }

    /**
     * JSON z API konta. Brak sesji (HTTP 401) = jedno ponowne logowanie; nadal 401 — B2bFatalException (dalsze
     * zapytania nie miałyby cen konta).
     *
     * @return array<mixed>
     */
    private function apiJson(string $path, string $label): array
    {
        if ($this->user === null) {
            $this->relogin();
        }
        $response = $this->send(fn (PendingRequest $http): Response => $this->withAuth($http)->get(self::BASE.$path), allowUnauthorized: true);
        if ($response->status() === 401) {
            $this->relogin();
            $response = $this->send(fn (PendingRequest $http): Response => $this->withAuth($http)->get(self::BASE.$path), allowUnauthorized: true);
            if ($response->status() === 401) {
                $this->user = null;

                throw new B2bFatalException(self::SESSION_LOST.' ('.$label.' bez sesji konta po ponownym logowaniu)');
            }
        }
        $body = self::bodyOf($response);
        $json = json_decode($body, true);
        if (! is_array($json)) {
            throw new RuntimeException($label.': odpowiedź sklepu to nie JSON');
        }

        return $json;
    }

    private function withAuth(PendingRequest $http): PendingRequest
    {
        return $http->withHeaders([
            'X-Application-Id' => (string) ($this->user['applicationId'] ?? 0),
            'X-Auth-User' => (string) ($this->user['username'] ?? ''),
            'X-Auth-Token' => (string) ($this->user['authenticationToken'] ?? ''),
        ]);
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
     * @return array<mixed>
     */
    private static function decode(string $body): array
    {
        $json = json_decode($body, true);

        return is_array($json) ? $json : [];
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
     * @param  bool  $allowUnauthorized  HTTP 401 wraca do wołającego (ponowne logowanie), nie jest błędem
     * @param  bool  $allowClientErrors  każda odpowiedź 4xx wraca do wołającego (logowanie: złe hasło)
     */
    private function send(callable $call, bool $allowUnauthorized = false, bool $allowClientErrors = false): Response
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
                    'Accept' => 'application/json, text/plain, */*',
                    'X-Language-Code' => self::LANGUAGE,
                    'X-application-Name' => self::APPLICATION_NAME,
                ])->withOptions([
                    'cookies' => $this->jar,
                    'allow_redirects' => ['max' => 5],
                ]));
            } catch (ConnectionException $e) {
                $error = 'brak połączenia ('.$e->getMessage().')';
            }

            if ($response !== null && ($response->successful()
                || ($allowUnauthorized && $response->status() === 401)
                || ($allowClientErrors && $response->clientError() && $response->status() !== 429))) {
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
