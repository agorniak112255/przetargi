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
 * Platforma B2B P4S b2b.p4s.pl (aplikacja Vue „wizja”, całe API pod jednym adresem JSP). Nieoficjalne: funkcje i pola
 * odczytane ze skryptów strony i odpowiedzi zalogowanego konta 23.09.2026.
 *
 * Każde zapytanie to POST JSON {function: …, …} pod /scripts/b2bPortal.jsp; odpowiedź to JSON poprzedzony pustymi
 * liniami (JSP). Logowanie: {function: login, userEmail, password, language} → {errorMessage} przy odmowie, potem
 * checkSession potwierdza konto (loggedIn, showPrices, waluta zamówień). Gość albo wygasła sesja dostaje
 * {sessionExpired: true}, a pliki — pustą odpowiedź HTML; wtedy jedno ponowne logowanie.
 * Pliki wyrobu: GET z function=getProductDocument&productId=…&ordinalNumber=… (tylko z sesją konta); zdjęcia
 * (/productDocuments/…) są publiczne. Zapytania idą po kolei, z przerwą przed każdym.
 */
final class P4sB2bClient
{
    public const HOST = 'b2b.p4s.pl';

    public const BASE = 'https://b2b.p4s.pl';

    public const API = self::BASE.'/scripts/b2bPortal.jsp';

    /** Strona wyrobu w aplikacji (adres źródła karty). */
    public const PRODUCT_PAGE = self::BASE.'/#/product/';

    private const LANGUAGE = 'pl';

    private const SESSION_LOST = 'Utracono sesję konta b2b.p4s.pl — ceny konta niedostępne';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503 i potknięciach sieci, gdy serwer nie podał Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    private const MAX_RETRY_AFTER_MS = 120_000;

    private const TIMEOUT_SECONDS = 120;

    private CookieJar $jar;

    private bool $loggedIn = false;

    private string $currency = '';

    private int $consecutiveFailures = 0;

    /** @var Closure(int): void */
    private Closure $sleep;

    /**
     * @param  (Closure(int): void)|null  $sleep  pauza w ms (w testach bez czekania)
     */
    public function __construct(
        private readonly string $email,
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

        $email = trim($this->email);
        if ($email === '') {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: konto nie ma loginu (adres e-mail)');
        }

        try {
            $this->call(['function' => 'checkSession']);
            $result = $this->call([
                'function' => 'login',
                'userEmail' => $email,
                'password' => trim($this->password),
                'language' => self::LANGUAGE,
            ]);
            if ($result === null) {
                throw new RuntimeException('platforma nie odpowiedziała na logowanie JSON-em');
            }
            $message = self::clean((string) ($result['errorMessage'] ?? ''));
            if ($message !== '') {
                throw new RuntimeException('platforma odrzuciła logowanie: '.$message.' — sprawdź login (e-mail) i hasło');
            }

            $session = $this->call(['function' => 'checkSession']);
            if ($session === null || ($session['loggedIn'] ?? false) !== true || ($session['anonymousSession'] ?? false) === true) {
                throw new RuntimeException('platforma nie potwierdziła zalogowania (checkSession bez konta)');
            }
            if (($session['requiredRegulationsApproval'] ?? false) === true) {
                throw new RuntimeException('platforma wymaga akceptacji regulaminu — zaloguj się na '.self::HOST.' i zaakceptuj go');
            }
            if (($session['showPrices'] ?? false) !== true) {
                throw new RuntimeException('konto nie widzi cen (showPrices wyłączone)');
            }
            $currency = mb_strtoupper(self::clean((string) ($session['orderCurrency'] ?? '')));
            if ($currency === '') {
                throw new RuntimeException('sesja konta bez waluty — nie wiadomo, w czym są ceny');
            }
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        $this->currency = $currency;
        $this->loggedIn = true;
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    /** Waluta zamówień konta (np. PLN); znana po zalogowaniu. */
    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * Strona listy wyrobów: {recordsTotal, recordsFiltered, products}. $mothers = true — wyroby z rozmiarami jako jedna
     * pozycja (typ M) i wyroby pojedyncze (T); false — pojedyncze i rozmiary (R, z parentProductId).
     *
     * @param  'generalOffer'|'productGroups'  $offer  oferta ogólna albo „Moja oferta”
     * @return array<string, mixed>
     */
    public function listPage(string $offer, bool $mothers, int $start, int $length): array
    {
        return $this->accountJson([
            'function' => 'getProducts',
            'productGroupType' => $offer,
            'productGroupId' => 0,
            'onStockOnlyFilter' => false,
            'favouritesFilter' => false,
            'mothers' => $mothers ? 1 : 0,
            'search' => '',
            'length' => $length,
            'start' => $start,
            'order' => [['column' => 1, 'dir' => 'ASC']],
            'view' => 'G',
            'editCostCentres' => false,
            'showCostCentreProducts' => false,
            'brandIds' => [],
            'offerId' => 0,
        ], static fn (array $json): bool => is_int($json['recordsTotal'] ?? null) && is_array($json['products'] ?? null), 'lista '.$offer.' od '.$start);
    }

    /**
     * Karta wyrobu: {product, productNotInOffer}.
     *
     * @return array<string, mixed>
     */
    public function product(int $id): array
    {
        return $this->accountJson(
            ['function' => 'getProduct', 'productId' => $id, 'editCostCentres' => false, 'iFrame' => false],
            static fn (array $json): bool => is_array($json['product'] ?? null) || ($json['productNotInOffer'] ?? false) === true,
            'karta wyrobu '.$id,
        );
    }

    /** Adres pliku wyrobu (bez tokenów — sesja jest w ciasteczku). */
    public static function documentUrl(int $productId, int $ordinalNumber): string
    {
        return self::API.'?function=getProductDocument&productId='.$productId.'&ordinalNumber='.$ordinalNumber;
    }

    /**
     * Plik wyrobu z sesją konta. Pusta odpowiedź (tak platforma odpowiada gościowi) = sesja mogła wygasnąć → jedno
     * ponowne logowanie; nadal pusta = B2bFatalException.
     *
     * @return array{bytes: string, mime: string}
     */
    public function documentBytes(string $url): array
    {
        if (! str_starts_with($url, self::API.'?function=getProductDocument&')) {
            throw new RuntimeException('adres pliku spoza '.self::HOST.': '.$url);
        }
        if (! $this->loggedIn) {
            $this->relogin();
        }

        $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url));
        if ($response->body() === '') {
            $this->relogin();
            $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url));
            if ($response->body() === '') {
                $this->loggedIn = false;

                throw new B2bFatalException(self::SESSION_LOST.' (plik wyrobu pusty po ponownym logowaniu)');
            }
        }

        return ['bytes' => $response->body(), 'mime' => strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]))];
    }

    /**
     * @return array{bytes: string, mime: string}
     */
    public function imageBytes(string $url): array
    {
        if (! self::isImageUrl($url)) {
            throw new RuntimeException('adres zdjęcia spoza '.self::HOST.': '.$url);
        }
        $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url));

        return ['bytes' => $response->body(), 'mime' => strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]))];
    }

    /** Zdjęcie wyrobu: ścieżka względna z karty („productDocuments/124479/1_PS18SKR.jpg”) → pełny adres; inna = null. */
    public static function imageUrl(string $path): ?string
    {
        $path = trim($path);
        if (preg_match('~^productDocuments/\d+/[^?#]+$~', $path) !== 1) {
            return null;
        }

        return self::BASE.'/'.implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    public static function isImageUrl(string $url): bool
    {
        return str_starts_with($url, self::BASE.'/productDocuments/');
    }

    /**
     * JSON konta. {sessionExpired: true} albo odpowiedź nie-JSON = sesja mogła wygasnąć → jedno ponowne logowanie;
     * nadal bez danych konta = B2bFatalException. Komunikat błędu platformy (errorMessage) = RuntimeException.
     *
     * @param  array<string, mixed>  $body
     * @param  callable(array<string, mixed>): bool  $valid
     * @return array<string, mixed>
     */
    private function accountJson(array $body, callable $valid, string $label): array
    {
        if (! $this->loggedIn) {
            $this->relogin();
        }

        $json = $this->call($body);
        if ($json === null || ($json['sessionExpired'] ?? false) === true) {
            $this->relogin();
            $json = $this->call($body);
            if ($json === null || ($json['sessionExpired'] ?? false) === true) {
                $this->loggedIn = false;

                throw new B2bFatalException(self::SESSION_LOST.' ('.$label.' bez sesji konta po ponownym logowaniu)');
            }
        }
        $message = self::clean((string) ($json['errorMessage'] ?? ''));
        if ($message !== '') {
            throw new RuntimeException($label.': platforma zwróciła komunikat „'.$message.'”');
        }
        if (! $valid($json)) {
            throw new RuntimeException($label.': odpowiedź bez oczekiwanych pól — zmiana platformy?');
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    private function call(array $body): ?array
    {
        $response = $this->send(static fn (PendingRequest $http): Response => $http->asJson()->post(self::API, $body));
        // JSP poprzedza JSON pustymi liniami
        $json = json_decode(trim($response->body()), true);

        return is_array($json) ? $json : null;
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
                    'X-Requested-With' => 'XMLHttpRequest',
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

    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $value));
    }
}
