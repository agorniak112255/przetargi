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
 * Sklep B2B BIG Arbeitsschutz GmbH (Buchholz, marki teXXor®, 4PROTECT®, RUNNEX®) www.big-arbeitsschutz.de — DSISoft
 * na SOG ERP, tylko po niemiecku. Nieoficjalne: formularz, adresy i znaczniki odczytane ze stron zalogowanego konta #34
 * 01.10.2026.
 *
 * Logowanie: formularz z nagłówka strony (performAction=processLogin, personlogin = numer klienta, personpwd) wysłany
 * POST-em na /account.html; sesja w ciasteczku JSESSIONID. Strona zalogowanego konta ma w menu odnośnik wylogowania
 * „/logout-performAction-processLogout.html” („Abmelden”) — strona bez niego to strona gościa (brak sesji).
 *
 * Cennik konta: /kundenDokumente.html („Ihre Preislisten”) wymienia pliki konta (PDF i XLSX „Preisliste_RRRRMMDD”)
 * pod /kundendokumente/{numer}.xlsx. Strona wyrobu: /item-1-{numer artykułu}.html (działa bez nazwy w ścieżce;
 * artykuł bez strony w sklepie = 404). Pliki wyrobu: /artikeldokumente/….pdf; zdjęcia: www.big-arbeitsschutz-static.de
 * (publiczne). Zapytania po kolei, z przerwą przed każdym (łącznik daje co najmniej 1 s).
 */
final class BigB2bClient
{
    public const HOST = 'www.big-arbeitsschutz.de';

    public const STATIC_HOST = 'www.big-arbeitsschutz-static.de';

    public const BASE = 'https://www.big-arbeitsschutz.de';

    private const LOGIN = self::BASE.'/account.html';

    private const DOCUMENTS = self::BASE.'/kundenDokumente.html';

    /** Odnośnik wylogowania w menu konta — jest tylko na stronach zalogowanego konta. */
    private const LOGGED_IN_MARKER = '/logout-performAction-processLogout.html';

    private const SESSION_LOST = 'Utracono sesję konta www.big-arbeitsschutz.de — cennik i strony konta niedostępne';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503 i potknięciach sieci, gdy witryna nie podała Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    private const MAX_RETRY_AFTER_MS = 120_000;

    private const TIMEOUT_SECONDS = 120;

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
        private readonly int $delayMs = 1000,
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
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: konto nie ma loginu (numer klienta BIG)');
        }

        try {
            // strona główna zakłada sesję (JSESSIONID), jak w przeglądarce
            $this->send(static fn (PendingRequest $http): Response => $http->get(self::BASE.'/'));
            $response = $this->send(fn (PendingRequest $http): Response => $http->asForm()->post(self::LOGIN, [
                'performAction' => 'processLogin',
                'personlogin' => $username,
                'personpwd' => $this->password,
            ]));
            $confirmed = self::hasLoggedInMarker(self::bodyOf($response));
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        if (! $confirmed) {
            throw new RuntimeException(
                'Logowanie do '.self::HOST.' nieudane: witryna nie potwierdziła zalogowania (strona bez „Abmelden”) — sprawdź numer klienta i hasło'
            );
        }

        $this->loggedIn = true;
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    /**
     * Najnowszy cennik konta w XLSX z „Ihre Preislisten”: nazwa dokumentu, data ze strony i bajty pliku (archiwum ZIP).
     *
     * @return array{name: string, date: string, url: string, bytes: string}
     */
    public function priceListXlsx(): array
    {
        $files = self::parsePriceListLinks($this->accountPage(self::DOCUMENTS, 'Ihre Preislisten'));
        if ($files === []) {
            throw new B2bFatalException('Strona „Ihre Preislisten” konta '.self::HOST.' bez cennika XLSX — zmiana strony albo konto bez cennika');
        }
        $file = $files[0];
        $bytes = self::bodyOf($this->send(static fn (PendingRequest $http): Response => $http->accept('*/*')->get($file['url'])));
        if (! str_starts_with($bytes, "PK\x03\x04")) {
            if (self::hasLoggedInMarker($bytes) || str_contains(strtolower(substr($bytes, 0, 512)), '<html')) {
                throw new B2bFatalException(self::SESSION_LOST.' (strona HTML zamiast cennika '.$file['name'].')');
            }

            throw new B2bFatalException('Cennik '.$file['name'].' z '.self::HOST.' to nie plik XLSX');
        }

        return $file + ['bytes' => $bytes];
    }

    /**
     * Odnośniki do plików XLSX ze strony „Ihre Preislisten”, najnowszy pierwszy (po dacie ze strony, potem po nazwie).
     *
     * @return list<array{name: string, date: string, url: string}>
     */
    public static function parsePriceListLinks(string $html): array
    {
        $out = [];
        // wiersz tabeli: nazwa dokumentu, data „01.10.26”, odnośnik „XLSX”
        if (preg_match_all('#<tr\b[^>]*>(.*?)</tr>#is', $html, $rows) < 1) {
            return [];
        }
        foreach ($rows[1] as $row) {
            if (preg_match('#href="(https://www\.big-arbeitsschutz\.de/kundendokumente/[A-Za-z0-9_\-]+\.xlsx)"#i', $row, $link) !== 1) {
                continue;
            }
            $cells = [];
            if (preg_match_all('#<td\b[^>]*>(.*?)</td>#is', $row, $tds) > 0) {
                $cells = array_map(static fn (string $cell): string => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($cell), ENT_QUOTES | ENT_HTML5, 'UTF-8'))), $tds[1]);
            }
            $date = '';
            $name = '';
            foreach ($cells as $cell) {
                if ($date === '' && preg_match('/^\d{2}\.\d{2}\.\d{2,4}$/', $cell) === 1) {
                    $date = $cell;
                } elseif ($name === '' && $cell !== '' && stripos($cell, 'xlsx') === false) {
                    $name = $cell;
                }
            }
            $out[] = ['name' => $name !== '' ? $name : basename($link[1]), 'date' => $date, 'url' => $link[1]];
        }
        usort($out, static fn (array $a, array $b): int => [self::sortableDate($b['date']), $b['name']] <=> [self::sortableDate($a['date']), $a['name']]);

        return $out;
    }

    /**
     * Strona wyrobu sesją konta; null = sklep nie ma strony tego artykułu (404). 404 nie jest błędem przebiegu ani
     * potknięciem witryny — część artykułów cennika nie ma strony w sklepie.
     */
    public function itemPage(string $number): ?string
    {
        if (preg_match('/^\d{1,8}$/', $number) !== 1) {
            throw new RuntimeException('nieprawidłowy numer artykułu BIG: '.$number);
        }
        $url = self::BASE.'/item-1-'.$number.'.html';

        return $this->accountPage($url, 'strona artykułu '.$number, notFoundIsNull: true);
    }

    /**
     * Plik wyrobu (/artikeldokumente/…) albo zdjęcie (www.big-arbeitsschutz-static.de). Strona HTML zamiast pliku = błąd:
     * takiej treści nie zapisujemy jako pliku.
     *
     * @return array{bytes: string, mime: string}
     */
    public function fileBytes(string $url): array
    {
        $file = $this->fileBytesOrNull($url);
        if ($file === null) {
            throw new RuntimeException('brak pliku pod '.$url.' (404)');
        }

        return $file;
    }

    /**
     * Jak fileBytes(), ale brak pliku (404) = null, nie błąd. Cennik wymienia zdjęcia, których serwer nie ma (01.10.2026
     * próbka: 16 z 60 dodatkowych zdjęć, np. „1108_Innenseite_s.jpg”; zdjęcie główne zawsze jest) — 404 nie liczy się do
     * kolejnych błędów zapytań, które przerywają przebieg.
     *
     * @return array{bytes: string, mime: string}|null
     */
    public function fileBytesOrNull(string $url): ?array
    {
        if (! self::isSiteUrl($url)) {
            throw new RuntimeException('adres spoza '.self::HOST.': '.$url);
        }
        $response = $this->send(static fn (PendingRequest $http): Response => $http->accept('*/*')->get($url), notFoundIsResult: true);
        if ($response->status() === 404) {
            return null;
        }
        $bytes = self::bodyOf($response);
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        if ($bytes === '' || $mime === 'text/html' || self::looksLikeHtml($bytes)) {
            throw new RuntimeException('witryna nie wydała pliku '.$url.' (pusta treść albo strona HTML)');
        }

        return ['bytes' => $bytes, 'mime' => $mime !== '' ? $mime : 'application/octet-stream'];
    }

    public static function hasLoggedInMarker(string $html): bool
    {
        return str_contains($html, self::LOGGED_IN_MARKER);
    }

    /** https na www.big-arbeitsschutz.de albo www.big-arbeitsschutz-static.de (bez danych logowania i portu w adresie). */
    public static function isSiteUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        return in_array(strtolower($parts['host'] ?? ''), [self::HOST, self::STATIC_HOST], true);
    }

    /** „01.10.26” → „20261001” (porównywalne); bez daty — ''. */
    private static function sortableDate(string $date): string
    {
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{2,4})$/', $date, $m) !== 1) {
            return '';
        }
        $year = strlen($m[3]) === 2 ? '20'.$m[3] : $m[3];

        return $year.$m[2].$m[1];
    }

    private static function looksLikeHtml(string $bytes): bool
    {
        $head = strtolower(ltrim(substr($bytes, 0, 512)));

        return str_starts_with($head, '<!doctype html') || str_starts_with($head, '<html');
    }

    /**
     * Strona dla konta. Strona bez odnośnika wylogowania (wygasła sesja → strona gościa) = jedno ponowne logowanie;
     * nadal bez niego = B2bFatalException (dalsze strony nie byłyby stronami konta).
     */
    private function accountPage(string $url, string $label, bool $notFoundIsNull = false): ?string
    {
        if (! $this->loggedIn) {
            $this->relogin();
        }

        // strona 404 zalogowanego konta też ma menu z „Abmelden” (sprawdzone 01.10.2026, artykuł 1113) — 404 bez
        // niego to wygasła sesja, a nie brak strony: inaczej artykuł zapisałby się z uciętym opisem z cennika
        [$status, $body] = $this->get($url, $notFoundIsNull);
        if (! self::hasLoggedInMarker($body)) {
            $this->relogin();
            [$status, $body] = $this->get($url, $notFoundIsNull);
            if (! self::hasLoggedInMarker($body)) {
                $this->loggedIn = false;

                throw new B2bFatalException(self::SESSION_LOST.' ('.$label.' bez sesji konta po ponownym logowaniu)');
            }
        }

        return $status === 404 ? null : $body;
    }

    /**
     * @return array{0: int, 1: string} kod HTTP i treść (404 tylko przy $notFoundIsResult)
     */
    private function get(string $url, bool $notFoundIsResult): array
    {
        $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url), $notFoundIsResult);

        return [$response->status(), self::bodyOf($response)];
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
     * @param  bool  $notFoundIsResult  404 to odpowiedź (strony artykułu nie ma), nie błąd
     */
    private function send(callable $call, bool $notFoundIsResult = false): Response
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
                    'Accept-Language' => 'de-DE,de;q=0.9',
                ])->withOptions([
                    'cookies' => $this->jar,
                    'allow_redirects' => ['max' => 5],
                ]));
            } catch (ConnectionException $e) {
                $error = 'brak połączenia ('.$e->getMessage().')';
            }

            if ($response !== null && ($response->successful() || ($notFoundIsResult && $response->status() === 404))) {
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
