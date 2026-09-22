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
 * Portal B2B Mascot b2b.mascot.dk (ASP.NET MVC, dane w JSON). Nieoficjalne: adresy i pola odczytane ze skryptów
 * i odpowiedzi zalogowanego konta 22.09.2026.
 *
 * Logowanie jak w skrypcie strony: JSON {Username, Password} pod /Login/SignIn → {IsSuccess, Action, Message},
 * potem /Progress/PreLoad (portal ładuje dane konta do sesji) i formularz pod /Distributor/LoginDone. Język sesji
 * ustala się przy logowaniu (Accept-Language) — nagłówek na zapytaniach po zalogowaniu języka już nie zmienia.
 * Gość dostaje 302 na /Login, więc odpowiedź konta rozpoznajemy po tym, że jest oczekiwanym JSON-em.
 * Zapytania idą po kolei, z przerwą przed każdym.
 */
final class MascotB2bClient
{
    public const HOST = 'b2b.mascot.dk';

    public const BASE = 'https://b2b.mascot.dk';

    /** Serwer zdjęć wyrobów (adresy z pola Image). */
    public const IMAGE_HOST = 'productimage-1ccb8.kxcdn.com';

    private const LOGIN_PAGE = self::BASE.'/Login';

    private const SIGN_IN = self::BASE.'/Login/SignIn';

    private const PRELOAD = self::BASE.'/Progress/PreLoad';

    private const LOGIN_DONE = self::BASE.'/Distributor/LoginDone';

    private const PROFILE = self::BASE.'/Profile/GetProfile';

    private const PRODUCTS = self::BASE.'/Distributor/GetProducts';

    private const PRODUCT_DETAIL = self::BASE.'/Distributor/GetProductDetail?productNumber=';

    /** Strona wyrobu w portalu (adres źródła karty). */
    public const PRODUCT_PAGE = self::BASE.'/Distributor/ProductDetail?productNumber=';

    /** Portal odpowiada polskim tekstem tylko wtedy, gdy sesja powstała z tym językiem. */
    private const LANGUAGE = 'pl-PL,pl;q=0.9';

    /** Strona konta po polsku ładuje ten plik tłumaczeń (bez nagłówka języka: lang.iv.js). */
    private const POLISH_MARKER = '/Js/lang.pl.js';

    /** Znacznik strony zalogowanego konta (token dopisywany przez stronę do zapytań). */
    private const LOGGED_IN_MARKER = 'window.LoginToken';

    /** Portal żąda zmiany hasła przed dalszą pracą. */
    private const ACTION_CHANGE_PASSWORD = 'NTCP';

    private const SESSION_LOST = 'Utracono sesję konta b2b.mascot.dk — ceny konta niedostępne';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503 i potknięciach sieci, gdy portal nie podał Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    private const MAX_RETRY_AFTER_MS = 120_000;

    /** Portal odpowiada wolno (strona listy po 100 pozycji ok. 10–20 s, PreLoad dłużej). */
    private const TIMEOUT_SECONDS = 120;

    private const PRELOAD_TIMEOUT_SECONDS = 300;

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
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: konto nie ma loginu (numer konta Mascot)');
        }

        try {
            $this->send(static fn (PendingRequest $http): Response => $http->get(self::LOGIN_PAGE));
            $result = $this->send(fn (PendingRequest $http): Response => $http->asJson()->post(self::SIGN_IN, [
                'Username' => $username,
                'Password' => trim($this->password),
                'LoginToken' => '',
            ]))->json();
            if (! is_array($result) || ! array_key_exists('IsSuccess', $result)) {
                throw new RuntimeException('portal nie odpowiedział na logowanie oczekiwanym JSON-em');
            }
            if ($result['IsSuccess'] !== true) {
                $message = trim((string) ($result['Message'] ?? ''));

                throw new RuntimeException('portal odrzucił logowanie'.($message !== '' ? ': '.$message : '').' — sprawdź login (numer konta) i hasło');
            }
            if (($result['Action'] ?? '') === self::ACTION_CHANGE_PASSWORD) {
                throw new RuntimeException('portal wymaga zmiany hasła — zmień je na '.self::HOST.' i wpisz nowe w koncie B2B');
            }

            $this->send(static fn (PendingRequest $http): Response => $http->timeout(self::PRELOAD_TIMEOUT_SECONDS)->get(self::PRELOAD));
            $page = $this->send(fn (PendingRequest $http): Response => $http->asForm()->post(self::LOGIN_DONE, [
                'username' => $username,
                'password' => trim($this->password),
            ]))->body();
            if (! str_contains($page, self::LOGGED_IN_MARKER)) {
                throw new RuntimeException('portal nie potwierdził zalogowania (brak strony konta po /Distributor/LoginDone)');
            }
            if (! str_contains($page, self::POLISH_MARKER)) {
                throw new RuntimeException('portal nie przełączył sesji na język polski — opisy byłyby w innym języku');
            }

            $profile = $this->send(static fn (PendingRequest $http): Response => $http->get(self::PROFILE))->json();
            $currency = is_array($profile) && ($profile['IsSuccess'] ?? false) === true
                ? mb_strtoupper(trim((string) ($profile['Info']['Currency'] ?? '')))
                : '';
            if ($currency === '') {
                throw new RuntimeException('profil konta bez waluty — nie wiadomo, w czym są ceny');
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

    /** Waluta cen konta z profilu (np. PLN); znana po zalogowaniu. */
    public function currency(): string
    {
        return $this->currency;
    }

    /**
     * Strona listy wyrobów (numerowana od 1): {Results, TotalCount, Message}.
     *
     * @return array<string, mixed>
     */
    public function listPage(int $page, int $pageSize): array
    {
        return $this->accountJson(
            static fn (PendingRequest $http): Response => $http->asJson()->post(self::PRODUCTS, [
                'PageIndex' => max(1, $page),
                'PageSize' => $pageSize,
            ]),
            static fn (array $json): bool => array_key_exists('TotalCount', $json) && is_array($json['Results'] ?? null),
            'strona listy '.$page,
        );
    }

    /**
     * Szczegóły wyrobu z cenami rozmiarów: {IsSuccess, ProductDetail, Message}.
     *
     * @return array<string, mixed>
     */
    public function productDetail(string $number): array
    {
        return $this->accountJson(
            static fn (PendingRequest $http): Response => $http->get(self::PRODUCT_DETAIL.rawurlencode($number)),
            static fn (array $json): bool => array_key_exists('IsSuccess', $json),
            'szczegóły wyrobu '.$number,
        );
    }

    /**
     * @return array{bytes: string, mime: string}
     */
    public function imageBytes(string $url): array
    {
        if (! self::isImageUrl($url)) {
            throw new RuntimeException('adres zdjęcia spoza '.self::IMAGE_HOST.': '.$url);
        }
        $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url));
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        return ['bytes' => $response->body(), 'mime' => $mime];
    }

    public static function isImageUrl(string $url): bool
    {
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && strtolower((string) parse_url($url, PHP_URL_HOST)) === self::IMAGE_HOST;
    }

    /**
     * JSON konta. Inna odpowiedź (gość dostaje stronę logowania) = sesja mogła wygasnąć → jedno ponowne logowanie;
     * nadal nie ten JSON = B2bFatalException (dalsze zapytania nie miałyby cen konta).
     *
     * @param  callable(PendingRequest): Response  $call
     * @param  callable(array<string, mixed>): bool  $valid
     * @return array<string, mixed>
     */
    private function accountJson(callable $call, callable $valid, string $label): array
    {
        if (! $this->loggedIn) {
            $this->relogin();
        }

        $json = self::jsonOf($this->send($call));
        if ($json === null || ! $valid($json)) {
            $this->relogin();
            $json = self::jsonOf($this->send($call));
            if ($json === null || ! $valid($json)) {
                $this->loggedIn = false;

                throw new B2bFatalException(self::SESSION_LOST.' ('.$label.' bez danych konta po ponownym logowaniu)');
            }
        }

        return $json;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function jsonOf(Response $response): ?array
    {
        $json = json_decode($response->body(), true);

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
                    'Accept-Language' => self::LANGUAGE,
                    'X-Requested-With' => 'XMLHttpRequest',
                ])->withOptions([
                    'cookies' => $this->jar,
                    'allow_redirects' => ['max' => 5, 'track_redirects' => true],
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
