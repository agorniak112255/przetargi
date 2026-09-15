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
 * Sklep b2b.bolle-safety.com (NetSuite SuiteCommerce Advanced). Nieoficjalne: adresy, parametry i pola odczytane
 * z ruchu strony na koncie klienta (15.09.2026). Sklep nie ma oficjalnego API dla klienta, a robots.txt ma
 * „Disallow: /” z komentarzem o środowisku przedprodukcyjnym — dlatego dostęp tylko z konta użytkownika,
 * zapytania po kolei (jedno połączenie), z przerwą przed każdym.
 *
 * Sesja konta żyje w ciasteczkach (JSESSIONID, NS_VER, jsid_own, NLShopperId3, NLVisitorId…). Stan sesji
 * podaje Profile.Service.ss (isLoggedIn „T” dla konta, HTTP 401 ERR_USER_NOT_LOGGED_IN dla gościa).
 * Uwaga: /api/items odpowiada także gościowi — z ceną katalogową zamiast ceny konta — więc z samych danych
 * pozycji utraty sesji nie widać (kontrola ceny w BolleB2bConnector).
 */
final class BolleB2bClient
{
    public const HOST = 'bolle-safety.com';

    public const BASE = 'https://b2b.bolle-safety.com';

    public const COMPANY_ID = '5230881';

    public const SITE_ID = '3';

    /** Sklep odrzuca limit > 100 (HTTP 400). */
    public const PAGE_SIZE = 100;

    private const LOGIN_URL = self::BASE.'/safety/services/Account.Login.Service.ss?n='.self::SITE_ID.'&c='.self::COMPANY_ID;

    private const PROFILE_URL = self::BASE.'/safety/services/Profile.Service.ss';

    private const ENVIRONMENT_URL = self::BASE.'/safety/public/shopping.environment.shortcache.ssp?X-SC-Touchpoint=shopping';

    private const ITEMS_URL = self::BASE.'/api/items';

    /** Poddrzewo materiałów marketingowych (nie wyroby) — pomijane z decyzji użytkownika 15.09.2026. */
    private const EXCLUDED_TOP_CATEGORY_URLS = ['/marketing-items'];

    private const SESSION_LOST = 'Utracono sesję konta bolle-safety.com — ceny konta niedostępne';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503, gdy sklep nie podał Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    /** Górna granica Retry-After — dłuższa blokada = błąd zapytania, nie wielogodzinne czekanie. */
    private const MAX_RETRY_AFTER_MS = 900_000;

    private CookieJar $jar;

    private bool $loggedIn = false;

    /** Waluta cen konta z profilu (currency.code), znana po zalogowaniu. */
    private ?string $currency = null;

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

    /**
     * Logowanie = POST danych konta, potem potwierdzenie w profilu (isLoggedIn „T”) i odczyt waluty konta.
     * Profil bez waluty = błąd logowania: ceny bez waluty nie są zapisywane (żadnej waluty domyślnej).
     */
    public function login(): void
    {
        // nowa sesja — stare ciasteczka mogły należeć do wygasłej
        $this->jar = new CookieJar;
        $this->loggedIn = false;
        $this->currency = null;

        try {
            $response = $this->send(
                fn (PendingRequest $http): Response => $http->acceptJson()->asJson()->post(self::LOGIN_URL, $this->loginBody()),
                [400, 401, 403],
            );
            if (! $response->successful()) {
                $message = trim((string) ($response->json('errorMessage') ?? $response->json('errorCode') ?? ''));
                throw new RuntimeException('sklep odrzucił dane logowania (HTTP '.$response->status().($message !== '' ? ': '.$message : '').')');
            }
            $profile = $this->profile();
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        if ($profile === null || ($profile['isLoggedIn'] ?? null) !== 'T') {
            throw new RuntimeException(
                'Logowanie do '.self::HOST.' nieudane: sklep nie potwierdził zalogowania (profil konta bez isLoggedIn „T”) — sprawdź login i hasło'
            );
        }
        $currency = $profile['currency'] ?? null;
        $code = is_array($currency) && is_string($currency['code'] ?? null) ? strtoupper(trim($currency['code'])) : '';
        if ($code === '') {
            throw new RuntimeException(
                'Logowanie do '.self::HOST.' nieudane: profil konta nie podaje waluty (currency.code) — bez waluty ceny nie są zapisywane'
            );
        }

        $this->currency = $code;
        $this->loggedIn = true;
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    public function currency(): string
    {
        if ($this->currency === null) {
            throw new RuntimeException('Waluta konta '.self::HOST.' nieznana — brak zalogowania');
        }

        return $this->currency;
    }

    /** Sesja konta nadal ważna: profil HTTP 200 z isLoggedIn „T” (gość dostaje HTTP 401). */
    public function sessionAlive(): bool
    {
        $profile = $this->profile();

        return $profile !== null && ($profile['isLoggedIn'] ?? null) === 'T';
    }

    /**
     * Ponowne logowanie po utracie sesji; nieudane = B2bFatalException (dalsze ceny byłyby cenami gościa).
     */
    public function relogin(): void
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
     * Liście drzewa kategorii sklepu (SC.CATEGORIES ze skryptu środowiska), w kolejności drzewa. Ścieżka = nazwy
     * od korzenia połączone „ › ”. Bez poddrzewa MARKETING ITEMS (/marketing-items). Drzewo gościa = drzewo
     * konta (sprawdzone 15.09.2026: 42 liście, 41 bez materiałów marketingowych).
     *
     * @return list<array{url: string, path: string}>
     */
    public function leafCategories(): array
    {
        try {
            $script = $this->send(static fn (PendingRequest $http): Response => $http->get(self::ENVIRONMENT_URL))->body();
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Nie udało się pobrać drzewa kategorii '.self::HOST.': '.$e->getMessage(), 0, $e);
        }

        $json = self::assignedJsonArray($script, 'SC.CATEGORIES');
        if ($json === null) {
            throw new RuntimeException('Skrypt środowiska '.self::HOST.' nie zawiera drzewa kategorii (SC.CATEGORIES)');
        }
        $tree = json_decode($json, true);
        if (! is_array($tree)) {
            throw new RuntimeException('Drzewo kategorii '.self::HOST.' (SC.CATEGORIES) ma nieznany format');
        }

        $leaves = [];
        foreach ($tree as $node) {
            if (! is_array($node)) {
                continue;
            }
            if (in_array(rtrim(trim((string) ($node['fullurl'] ?? '')), '/'), self::EXCLUDED_TOP_CATEGORY_URLS, true)) {
                continue;
            }
            self::collectLeaves($node, [], $leaves);
        }

        if ($leaves === []) {
            throw new RuntimeException('Drzewo kategorii '.self::HOST.' nie ma żadnej kategorii z pozycjami (0 liści)');
        }

        return $leaves;
    }

    /**
     * Jedna strona pozycji kategorii (liścia). Parametry currency, language, pricelevel i sort dają w sklepie
     * HTTP 400 — nie są wysyłane; cena wynika z sesji konta.
     *
     * @return array{total: int, items: list<array<string, mixed>>}
     */
    public function itemsPage(string $categoryUrl, int $offset): array
    {
        $response = $this->send(static fn (PendingRequest $http): Response => $http->acceptJson()->get(self::ITEMS_URL, [
            'c' => self::COMPANY_ID,
            'n' => self::SITE_ID,
            'fieldset' => 'details',
            'limit' => self::PAGE_SIZE,
            'offset' => $offset,
            'commercecategoryurl' => $categoryUrl,
        ]));
        $json = $response->json();
        if (! is_array($json) || ! is_int($json['total'] ?? null) || ! is_array($json['items'] ?? null)) {
            throw new RuntimeException('Lista pozycji '.self::HOST.' ('.$categoryUrl.', offset '.$offset.') ma nieznany format');
        }

        return [
            'total' => $json['total'],
            'items' => array_values(array_filter($json['items'], 'is_array')),
        ];
    }

    /**
     * Jedna pozycja po internalid (bez kategorii) — null, gdy sklep jej nie zwraca.
     *
     * @return array<string, mixed>|null
     */
    public function item(int $internalId): ?array
    {
        $response = $this->send(static fn (PendingRequest $http): Response => $http->acceptJson()->get(self::ITEMS_URL, [
            'c' => self::COMPANY_ID,
            'n' => self::SITE_ID,
            'fieldset' => 'details',
            'id' => $internalId,
        ]));
        $first = $response->json('items.0');

        return is_array($first) ? $first : null;
    }

    /**
     * Plik zdjęcia (core/media/media.nl — pobiera się bez sesji). Tylko adresy sklepu: inny host = wyjątek,
     * żeby pole pozycji nie kierowało zapytań z ciasteczkami konta na obcy serwer.
     *
     * @return array{bytes: string, mime: string}
     */
    public function imageBytes(string $url): array
    {
        if (! str_starts_with($url, self::BASE.'/')) {
            throw new RuntimeException('adres zdjęcia spoza '.self::BASE.' odrzucony ('.$url.')');
        }
        $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url));
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        return ['bytes' => $response->body(), 'mime' => $mime];
    }

    /** Karta pozycji w sklepie: {BASE}/{urlcomponent} (sprawdzone 15.09.2026); pusty urlcomponent = null. */
    public function productUrl(string $urlcomponent): ?string
    {
        $component = trim($urlcomponent);

        return $component !== '' ? self::BASE.'/'.rawurlencode($component) : null;
    }

    /**
     * Ciało logowania — standardowy kształt SuiteCommerce Advanced (Account.Login.Service.ss). NIEZWERYFIKOWANE
     * na żywo: 15.09.2026 sprawdzono tylko, że adres przyjmuje wyłącznie POST (GET = ERR_METHOD_NOT_ALLOWED).
     * Gdyby sklep oczekiwał innych pól, zmiana tylko tutaj.
     *
     * @return array<string, string>
     */
    private function loginBody(): array
    {
        return [
            'email' => $this->username,
            'password' => $this->password,
            'redirect' => 'true',
        ];
    }

    /**
     * Profil sesji; null = gość (HTTP 401) albo odpowiedź bez JSON-a.
     *
     * @return array<string, mixed>|null
     */
    private function profile(): ?array
    {
        $response = $this->send(static fn (PendingRequest $http): Response => $http->acceptJson()->get(self::PROFILE_URL), [401]);
        if (! $response->successful()) {
            return null;
        }
        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $parents
     * @param  list<array{url: string, path: string}>  $leaves
     */
    private static function collectLeaves(array $node, array $parents, array &$leaves): void
    {
        $name = trim((string) ($node['name'] ?? ''));
        $path = $name !== '' ? [...$parents, $name] : $parents;
        $children = is_array($node['categories'] ?? null) ? array_filter($node['categories'], 'is_array') : [];

        if ($children === []) {
            $url = trim((string) ($node['fullurl'] ?? ''));
            if (! str_starts_with($url, '/')) {
                // bez adresu nie da się pobrać pozycji — pominięcie po cichu zgubiłoby całą kategorię
                throw new RuntimeException('Kategoria '.self::HOST.' „'.implode(' › ', $path).'” nie ma adresu (fullurl)');
            }
            $leaves[] = ['url' => $url, 'path' => implode(' › ', $path)];

            return;
        }

        foreach ($children as $child) {
            self::collectLeaves($child, $path, $leaves);
        }
    }

    /**
     * Tablica JSON przypisana w skrypcie („NAZWA = [ … ];”): od „[” do pasującego „]”, z pominięciem nawiasów
     * wewnątrz napisów JSON. null = brak przypisania albo niezamknięta tablica.
     */
    private static function assignedJsonArray(string $script, string $name): ?string
    {
        if (preg_match('/'.preg_quote($name, '/').'\s*=\s*\[/', $script, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }
        $start = $m[0][1] + strlen($m[0][0]) - 1;
        $depth = 0;
        $inString = false;
        $length = strlen($script);
        for ($i = $start; $i < $length; $i++) {
            $char = $script[$i];
            if ($inString) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }
            if ($char === '"') {
                $inString = true;
            } elseif ($char === '[' || $char === '{') {
                $depth++;
            } elseif ($char === ']' || $char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($script, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @param  list<int>  $expectedStatuses  statusy będące zwykłą odpowiedzią (np. 401 profilu gościa), nie błędem
     */
    private function send(callable $call, array $expectedStatuses = []): Response
    {
        $retries = 0;
        while (true) {
            if ($this->delayMs > 0) {
                ($this->sleep)($this->delayMs);
            }

            $response = null;
            $error = null;
            try {
                $response = $call(Http::timeout(30)->withOptions([
                    'cookies' => $this->jar,
                    'allow_redirects' => ['max' => 5],
                ]));
            } catch (ConnectionException $e) {
                $error = 'brak połączenia ('.$e->getMessage().')';
            }

            if ($response !== null && ($response->successful() || in_array($response->status(), $expectedStatuses, true))) {
                $this->consecutiveFailures = 0;

                return $response;
            }

            if ($response !== null && in_array($response->status(), [429, 503], true) && $retries < count(self::BACKOFF_MS)) {
                ($this->sleep)(self::retryAfterMs($response) ?? self::BACKOFF_MS[$retries]);
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
