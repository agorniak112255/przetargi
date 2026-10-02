<?php

declare(strict_types=1);

namespace App\Services\B2b;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Platforma B2B Sara Workwear b2b.saraworkwear.com (Merce / „Raccoon”: Astro na GraphQL-owym API
 * /api/graphql/frontend). Nieoficjalne: zapytania, argumenty i pola odczytane ze skryptu sklepu i z odpowiedzi API
 * 02.10.2026 (introspekcja schematu jest wyłączona — nazwy argumentów zdradzają komunikaty walidacji).
 *
 * Logowanie: mutacja createCustomerToken(login, password, fingerprint) zwraca żeton dostępu (JWT) z datą ważności;
 * dalsze zapytania idą z nagłówkiem „Authorization: Bearer …”. Błędne dane = błąd GraphQL z extensions.errors.credentials
 * („Nieprawidłowy login lub hasło.”). Odcisk przeglądarki (fingerprint) sklep liczy z płótna — tu stały skrót loginu.
 *
 * Uwaga: API wydaje katalog i ceny także bez logowania — gość dostaje ceny cennikowe, a wygasły albo zły żeton nie
 * daje żadnego błędu, tylko po cichu ceny gościa. Dlatego każde zapytanie o listę niesie w tym samym żądaniu pole
 * „customer” (wymaga zalogowania): puste = brak sesji — jedno ponowne logowanie i to samo zapytanie jeszcze raz,
 * nadal puste = B2bFatalException. Żeton odnawiany jest też zawczasu, przed upływem ważności.
 *
 * Pliki (/product/attachment/…) i zdjęcia (/picture/…) są publiczne. Zapytania idą po kolei, z przerwą przed każdym.
 */
final class SaraB2bClient
{
    public const HOST = 'b2b.saraworkwear.com';

    public const BASE = 'https://b2b.saraworkwear.com';

    private const API = self::BASE.'/api/graphql/frontend';

    private const SESSION_LOST = 'Utracono sesję konta b2b.saraworkwear.com — ceny konta niedostępne';

    /** Żeton odnawiany, gdy do końca ważności zostało mniej niż tyle sekund. */
    private const RENEW_BEFORE_SECONDS = 300;

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503 i potknięciach sieci, gdy sklep nie podał Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    private const MAX_RETRY_AFTER_MS = 120_000;

    private const TIMEOUT_SECONDS = 120;

    /**
     * Pola pozycji listy (jeden rozmiar jednego koloru), których używa łącznik. Właściwości z wartościami
     * i plikami — dosłownie ze sklepu.
     */
    private const ITEM_FIELDS = 'id name niceUrl description flags { name } unit { name interval } individualUnitInterval '
        .'categoryPath { name } pictures attachments { name url } '
        .'properties { name symbol value { ... on PropertyOtherValue { other: data } ... on PropertyYesNoValue { yes: data } } } '
        .'prices { sellPrice { nett currency } listPrice { nett currency } } '
        .'variants { id warehouseSymbol ean availability { buyable stock { amount unit } } }';

    private ?string $token = null;

    /** Koniec ważności żetonu (sekundy od 1970); null = nieznany. */
    private ?int $tokenExpiresAt = null;

    private int $consecutiveFailures = 0;

    /** @var Closure(int): void */
    private Closure $sleep;

    /** @var Closure(): int */
    private Closure $clock;

    /**
     * @param  (Closure(int): void)|null  $sleep  pauza w ms (w testach bez czekania)
     * @param  (Closure(): int)|null  $clock  bieżący czas w sekundach (w testach sterowany)
     */
    public function __construct(
        private readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
        private readonly int $delayMs = 150,
        ?Closure $sleep = null,
        ?Closure $clock = null,
    ) {
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function login(): void
    {
        $this->token = null;
        $this->tokenExpiresAt = null;

        $login = trim($this->username);
        if ($login === '') {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: konto nie ma loginu (adres e-mail konta w sklepie)');
        }

        $query = 'mutation($login: String!, $password: String!, $fingerprint: String!) { '
            .'createCustomerToken(login: $login, password: $password, fingerprint: $fingerprint) { accessToken { token expirationDate } } }';
        try {
            $json = $this->post($query, ['login' => $login, 'password' => $this->password, 'fingerprint' => self::fingerprint($login)], null);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        $access = $json['data']['createCustomerToken']['accessToken'] ?? null;
        $token = is_array($access) ? trim((string) ($access['token'] ?? '')) : '';
        if ($token === '') {
            $credentials = self::credentialsError($json);

            throw new RuntimeException(
                'Logowanie do '.self::HOST.' nieudane'.($credentials !== '' ? ' ('.$credentials.')' : '').' — sprawdź e-mail i hasło'
            );
        }

        $this->token = $token;
        $expires = strtotime((string) ($access['expirationDate'] ?? ''));
        $this->tokenExpiresAt = $expires !== false ? $expires : null;
    }

    public function isLoggedIn(): bool
    {
        return $this->token !== null;
    }

    /**
     * Kategorie główne sklepu (categoryList) — lista wyrobów idzie po nich, bo API nie ma listy „wszystkich”.
     *
     * @return list<array{id: string, name: string}>
     */
    public function categories(): array
    {
        $json = $this->post('{ categoryList { id name } }', [], $this->token);
        $out = [];
        foreach (is_array($json['data']['categoryList'] ?? null) ? $json['data']['categoryList'] : [] as $category) {
            $id = is_array($category) ? trim((string) ($category['id'] ?? '')) : '';
            if ($id !== '') {
                $out[] = ['id' => $id, 'name' => trim((string) ($category['name'] ?? ''))];
            }
        }
        if ($out === []) {
            throw new RuntimeException('Lista kategorii '.self::HOST.' pusta albo nieczytelna — zmiana sklepu?');
        }

        return $out;
    }

    /**
     * Strona wyrobów kategorii ({pagination, items}) z ceną konta. W tym samym żądaniu pole „customer” sprawdza sesję:
     * bez niej ceny byłyby cenami gościa (patrz opis klasy).
     *
     * @param  string|null  $sort  sortowanie sklepu (sortOptions: „-created_at”, „name”…); null = domyślne („-sort_scenario”)
     * @return array{pagination: array<string, mixed>, items: list<array<string, mixed>>}
     */
    public function categoryPage(string $categoryId, int $page, int $perPage, ?string $sort = null): array
    {
        $query = 'query($id: ID!, $page: Int, $limit: Int, $sort: String) { customer { user { email } } '
            .'products(id: $id, type: from_category, page: $page, limit: $limit, sort: $sort) { pagination { itemsCount lastPage currentPage } items { '
            .self::ITEM_FIELDS.' } } }';
        $variables = ['id' => $categoryId, 'page' => $page, 'limit' => $perPage, 'sort' => $sort];
        $label = 'kategoria '.$categoryId.', strona '.$page.($sort !== null ? ', sortowanie '.$sort : '');

        if ($this->token === null || $this->tokenExpiring()) {
            $this->relogin();
        }
        $json = $this->post($query, $variables, $this->token);
        if (! self::hasCustomer($json)) {
            $this->relogin();
            $json = $this->post($query, $variables, $this->token);
            if (! self::hasCustomer($json)) {
                $this->token = null;

                throw new B2bFatalException(self::SESSION_LOST.' ('.$label.' bez konta po ponownym logowaniu)');
            }
        }

        $products = $json['data']['products'] ?? null;
        if (! is_array($products) || ! is_array($products['items'] ?? null) || ! is_array($products['pagination'] ?? null)) {
            throw new RuntimeException($label.': odpowiedź bez wyrobów'.self::errorSuffix($json));
        }

        return ['pagination' => $products['pagination'], 'items' => array_values(array_filter($products['items'], 'is_array'))];
    }

    /**
     * Plik albo zdjęcie sklepu. Strona HTML zamiast pliku = błąd (takiej treści nie zapisujemy jako pliku). PDF
     * podany jako application/octet-stream — typ z treści.
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
        if (str_starts_with($bytes, '%PDF-')) {
            $mime = 'application/pdf';
        }

        return ['bytes' => $bytes, 'mime' => $mime];
    }

    /** https na b2b.saraworkwear.com (bez danych logowania i nietypowego portu w adresie). */
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

    /** Stały odcisk „przeglądarki” konta — sklep wymaga pola, a własny liczy z płótna przeglądarki. */
    public static function fingerprint(string $login): string
    {
        return substr(hash('sha256', 'przetargi-b2b|'.strtolower(trim($login))), 0, 32);
    }

    private function tokenExpiring(): bool
    {
        return $this->tokenExpiresAt !== null && $this->tokenExpiresAt - ($this->clock)() < self::RENEW_BEFORE_SECONDS;
    }

    /** Odpowiedź z kontem: pole customer.user wypełnione (gość dostaje „This action requires authentication”). */
    private static function hasCustomer(array $json): bool
    {
        return is_array($json['data']['customer']['user'] ?? null);
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

    /** Komunikat o złych danych logowania z błędu GraphQL (extensions.errors.credentials); '' = brak. */
    private static function credentialsError(array $json): string
    {
        foreach (is_array($json['errors'] ?? null) ? $json['errors'] : [] as $error) {
            $credentials = is_array($error) ? ($error['extensions']['errors']['credentials'] ?? null) : null;
            if (is_string($credentials) && trim($credentials) !== '') {
                return trim($credentials);
            }
        }

        return '';
    }

    /** „ (błąd API: …)” z pierwszego błędu GraphQL; '' = bez błędów. */
    private static function errorSuffix(array $json): string
    {
        $message = is_array($json['errors'][0] ?? null) ? trim((string) ($json['errors'][0]['message'] ?? '')) : '';

        return $message !== '' ? ' (błąd API: '.mb_substr($message, 0, 200).')' : '';
    }

    /**
     * Zapytanie GraphQL. Odpowiedź z błędami bez danych (walidacja zapytania, HTTP 400) = RuntimeException; częściowe
     * błędy (pole customer gościa) wracają do wołającego.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function post(string $query, array $variables, ?string $token): array
    {
        $body = (string) json_encode(['query' => $query, 'variables' => $variables === [] ? new \stdClass : $variables]);
        $response = $this->send(
            static function (PendingRequest $http) use ($body, $token): Response {
                if ($token !== null) {
                    $http = $http->withToken($token);
                }

                return $http->withBody($body, 'application/json')->post(self::API);
            },
            allowBadRequest: true,
        );
        $json = json_decode(self::bodyOf($response), true);
        if (! is_array($json)) {
            throw new RuntimeException('odpowiedź API '.self::HOST.' to nie JSON (HTTP '.$response->status().')');
        }
        if (! array_key_exists('data', $json) || ($response->status() === 400 && ! is_array($json['data'] ?? null))) {
            throw new RuntimeException('API '.self::HOST.' odrzuciło zapytanie (HTTP '.$response->status().')'.self::errorSuffix($json));
        }

        return $json;
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
     * @param  bool  $allowBadRequest  HTTP 400 (błąd walidacji GraphQL z treścią JSON) wraca do wołającego
     */
    private function send(callable $call, bool $allowBadRequest = false): Response
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
                    'Accept' => 'application/json, */*',
                ])->withOptions(['allow_redirects' => ['max' => 5]]));
            } catch (ConnectionException $e) {
                $error = 'brak połączenia ('.$e->getMessage().')';
            }

            if ($response !== null && ($response->successful() || ($allowBadRequest && $response->status() === 400))) {
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
