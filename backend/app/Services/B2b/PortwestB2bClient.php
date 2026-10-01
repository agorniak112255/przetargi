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
 * Portal B2B producenta Portwest portwest.com/market (CodeIgniter, strony HTML i pliki CSV). Nieoficjalne: adresy
 * i pola odczytane z zalogowanego konta 01.10.2026.
 *
 * Logowanie: POST /main/login/ z formularzem {email: numer konta klienta albo e-mail, password}; udane przekierowuje
 * przez /main/checkLoginUser/ na /market/, sesja w ciasteczku ci_session. Nieudane zwraca stronę logowania Z HASŁEM
 * odesłanym w polu formularza — treści tej strony nigdy nie wkładamy do komunikatów ani dziennika.
 *
 * Dane katalogu to pliki z „Linków marketingowych” konta (/account/marketinglinks): cenniki konta CSV
 * (/account/exportCustomerPriceLists/{grupa}/…, ceny konta w PLN) i „Daily Data” (/account/downloadDailyData…, oferta
 * sklepu z kolorem, rozmiarem, EAN-em i stanem) wymagają sesji; opisy i normy (CDN d11ak7fd9ypfb7.cloudfront.net),
 * karty produktu i deklaracje (documents.portwest.com) oraz zdjęcia są publiczne. Wygasła sesja daje zamiast pliku
 * stronę logowania (HTTP 200) — jedno ponowne logowanie, nadal strona = B2bFatalException.
 */
final class PortwestB2bClient
{
    public const HOST = 'portwest.com';

    public const BASE = 'https://portwest.com';

    public const CDN_HOST = 'd11ak7fd9ypfb7.cloudfront.net';

    public const DOCUMENTS_HOST = 'documents.portwest.com';

    private const LINKS_PATH = '/account/marketinglinks';

    private const SESSION_LOST = 'Utracono sesję konta portwest.com — pliki konta niedostępne';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503 i potknięciach sieci, gdy portal nie podał Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    private const MAX_RETRY_AFTER_MS = 120_000;

    /** Cennik i Daily Data to pliki po kilka–kilkanaście MB, a karta produktu PDF powstaje na żądanie kilka sekund. */
    private const TIMEOUT_SECONDS = 180;

    private CookieJar $jar;

    private bool $loggedIn = false;

    /** @var array{price: array<string, string>, daily: array<string, string>}|null adresy plików konta; null = nieodczytane */
    private ?array $links = null;

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
     * Logowanie i odczyt adresów plików konta. Konto, którego strona linków nie ma cennika, to złe dane logowania
     * (portal wraca do formularza) albo zmiana portalu.
     */
    public function login(): void
    {
        // nowa sesja — stare ciasteczka mogły należeć do wygasłej
        $this->jar = new CookieJar;
        $this->loggedIn = false;
        $this->links = null;

        $username = trim($this->username);
        if ($username === '') {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: konto nie ma loginu (numer konta klienta Portwest)');
        }

        try {
            $this->send(
                fn (PendingRequest $http): Response => $http->asForm()->post(self::BASE.'/main/login/', ['email' => $username, 'password' => $this->password]),
                allowClientErrors: true,
            );
            $page = self::bodyOf($this->send(static fn (PendingRequest $http): Response => $http->get(self::BASE.self::LINKS_PATH)));
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        if (self::isLoginPage($page)) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane — portal wrócił do formularza logowania; sprawdź numer konta i hasło');
        }
        $links = self::parseLinks($page);
        if ($links['price'] === [] || $links['daily'] === []) {
            throw new B2bFatalException('Strona „Linki marketingowe” konta '.self::HOST.' nie ma linków cennika CSV i Daily Data — portal zmienił stronę; przebieg przerwany');
        }

        $this->links = $links;
        $this->loggedIn = true;
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    /**
     * Adresy cenników konta CSV: numer grupy z adresu → adres („99” = wszystkie wyroby Portwest, „12” = Base).
     *
     * @return array<string, string>
     */
    public function priceListUrls(): array
    {
        return $this->links()['price'];
    }

    /**
     * Adresy plików Daily Data: nazwa pliku („sohPL.csv”, „sohPLB.csv”) → adres.
     *
     * @return array<string, string>
     */
    public function dailyDataUrls(): array
    {
        return $this->links()['daily'];
    }

    /**
     * Wiersze pliku CSV po kolei (tablice nagłówek → wartość, BOM usunięty z każdego pola), czytane strumieniowo — plik
     * Daily Data ma kilkanaście MB, a zadania w tle 128 MB pamięci. Plik konta (portwest.com) z wygasłą sesją przychodzi
     * jako strona logowania: jedno ponowne logowanie, potem B2bFatalException. Nagłówek bez którejś z kolumn $required
     * = B2bFatalException (plik zmienił układ — bez niego przebieg nie może uznać, że wyroby zniknęły).
     *
     * @param  list<string>  $required
     * @param  callable(array<string, string>): void  $row
     * @return int liczba wierszy danych
     */
    public function eachCsvRow(string $url, array $required, callable $row): int
    {
        if (! self::isPortalUrl($url) && ! self::isPublicFileUrl($url)) {
            throw new RuntimeException('adres pliku spoza Portwest: '.$url);
        }
        $label = self::fileLabel($url);
        $stream = $this->download($url);
        try {
            if (self::isPortalUrl($url) && self::streamIsHtml($stream)) {
                fclose($stream);
                $this->relogin();
                $stream = $this->download($url);
                if (self::streamIsHtml($stream)) {
                    $this->loggedIn = false;

                    throw new B2bFatalException(self::SESSION_LOST.' (plik '.$label.' wraca jako strona logowania po ponownym logowaniu)');
                }
            }

            $header = fgetcsv($stream, null, ',', '"', '');
            if (! is_array($header)) {
                throw new B2bFatalException('Plik '.$label.' z '.self::HOST.' jest pusty — przebieg przerwany bez zapisu');
            }
            $header = array_map(static fn (?string $name): string => self::cell($name), $header);
            $missing = array_values(array_diff($required, $header));
            if ($missing !== []) {
                throw new B2bFatalException('Plik '.$label.' z '.self::HOST.' nie ma kolumn: '.implode(', ', $missing).' — portal zmienił układ pliku; przebieg przerwany bez zapisu');
            }

            $count = 0;
            $width = count($header);
            while (($cells = fgetcsv($stream, null, ',', '"', '')) !== false) {
                if ($cells === [null]) {
                    continue;
                }
                $values = [];
                foreach ($header as $i => $name) {
                    if ($name === '' || isset($values[$name])) {
                        continue;
                    }
                    $values[$name] = self::cell($cells[$i] ?? null);
                }
                // kolumny bez nazwy po ostatniej nazwanej (cechy opisu: „Features” i dalej) — pod numerami
                for ($i = $width; $i < count($cells); $i++) {
                    $values['#'.$i] = self::cell($cells[$i]);
                }
                $row($values);
                $count++;
            }

            return $count;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Plik publiczny (karta produktu, deklaracja, zdjęcie). PDF z documents.portwest.com bywa poprzedzony tekstem
     * diagnostycznym portalu przed „%PDF-” — przycinamy do nagłówka PDF; plik bez nagłówka to błąd.
     *
     * @return array{bytes: string, mime: string}
     */
    public function fileBytes(string $url): array
    {
        if (! self::isPublicFileUrl($url)) {
            throw new RuntimeException('adres pliku spoza Portwest: '.$url);
        }
        $response = $this->send(static fn (PendingRequest $http): Response => $http->accept('*/*')->get($url));
        $bytes = self::bodyOf($response);
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $head = strtolower(ltrim(substr($bytes, 0, 512)));
        if ($bytes === '' || $mime === 'text/html' || str_starts_with($head, '<!doctype html') || str_starts_with($head, '<html')) {
            throw new RuntimeException('portal nie wydał pliku '.$url.' (pusta treść albo strona HTML)');
        }
        if ($mime === 'application/pdf') {
            $start = strpos(substr($bytes, 0, 4096), '%PDF-');
            if ($start === false) {
                throw new RuntimeException('plik '.$url.' nie jest PDF-em (brak nagłówka %PDF-)');
            }
            $bytes = substr($bytes, $start);
        }

        return ['bytes' => $bytes, 'mime' => $mime];
    }

    /** https na portwest.com (bez danych logowania i nietypowego portu w adresie). */
    public static function isPortalUrl(string $url): bool
    {
        return self::hostOf($url) === self::HOST;
    }

    /** https na CDN-ie plików Portwest albo na documents.portwest.com. */
    public static function isPublicFileUrl(string $url): bool
    {
        return in_array(self::hostOf($url), [self::CDN_HOST, self::DOCUMENTS_HOST], true);
    }

    /**
     * Adresy plików z „Linków marketingowych”: cenniki CSV (exportCustomerPriceLists/{grupa}/…) i Daily Data
     * (downloadDailyData?…&file=…). Inne linki strony (opisy, normy, przewodniki) nie są tu potrzebne — ich adresy CDN
     * nie zależą od konta.
     *
     * @return array{price: array<string, string>, daily: array<string, string>}
     */
    public static function parseLinks(string $html): array
    {
        $out = ['price' => [], 'daily' => []];
        if (preg_match_all('/href\s*=\s*"([^"]+)"/i', $html, $matches) === false) {
            return $out;
        }
        foreach ($matches[1] as $href) {
            $url = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (str_starts_with($url, '/')) {
                $url = self::BASE.$url;
            }
            if (! self::isPortalUrl($url)) {
                continue;
            }
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (preg_match('~^/account/exportCustomerPriceLists/(\d+)/~', $path, $m) === 1) {
                $out['price'][$m[1]] ??= $url;

                continue;
            }
            if ($path === '/account/downloadDailyData') {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $file = is_string($query['file'] ?? null) ? trim($query['file']) : '';
                if ($file !== '') {
                    $out['daily'][$file] ??= $url;
                }
            }
        }

        return $out;
    }

    /** Strona formularza logowania portalu (po nieudanym logowaniu albo bez sesji). */
    public static function isLoginPage(string $html): bool
    {
        return preg_match('~<form[^>]+action="[^"]*/main/login/?"~i', $html) === 1
            && preg_match('~name="password"~i', $html) === 1
            && ! str_contains($html, 'exportCustomerPriceLists');
    }

    /**
     * @return array{price: array<string, string>, daily: array<string, string>}
     */
    private function links(): array
    {
        if ($this->links === null) {
            $this->relogin();
        }

        return $this->links ?? ['price' => [], 'daily' => []];
    }

    /**
     * Treść odpowiedzi skopiowana kawałkami do strumienia tymczasowego (bez trzymania pliku w jednym napisie).
     *
     * @return resource
     */
    private function download(string $url)
    {
        $response = $this->send(static fn (PendingRequest $http): Response => $http->accept('*/*')->get($url));
        $body = $response->toPsrResponse()->getBody();
        $stream = fopen('php://temp/maxmemory:'.(4 * 1024 * 1024), 'w+b');
        if ($stream === false) {
            $body->close();

            throw new RuntimeException('nie udało się otworzyć pliku tymczasowego dla '.self::fileLabel($url));
        }
        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }
            while (! $body->eof()) {
                $chunk = $body->read(65536);
                if ($chunk === '') {
                    break;
                }
                fwrite($stream, $chunk);
            }
        } finally {
            $body->close();
        }
        rewind($stream);

        return $stream;
    }

    /**
     * @param  resource  $stream
     */
    private static function streamIsHtml($stream): bool
    {
        $head = strtolower(ltrim((string) fread($stream, 512)));
        rewind($stream);

        return str_starts_with($head, '<!doctype html') || str_starts_with($head, '<html') || str_starts_with($head, '<head');
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

    /** Wartość pola CSV: bez BOM-u (pierwsza pozycja Daily Data ma go w środku pliku), nieprawidłowy UTF-8 oczyszczony. */
    private static function cell(?string $value): string
    {
        $value = str_replace("\u{FEFF}", '', (string) $value);
        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_scrub($value, 'UTF-8');
        }

        return trim($value);
    }

    /** Nazwa pliku do komunikatu: „file=…” z zapytania albo ostatni człon ścieżki — bez zapytania (bywa długie). */
    private static function fileLabel(string $url): string
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        if (is_string($query['file'] ?? null) && $query['file'] !== '') {
            return $query['file'];
        }
        $path = (string) parse_url($url, PHP_URL_PATH);

        return $path !== '' ? $path : $url;
    }

    private static function hostOf(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
            return '';
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || str_contains((string) ($parts['path'] ?? ''), '..')) {
            return '';
        }

        return strtolower($parts['host'] ?? '');
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
     * @param  bool  $allowClientErrors  każda odpowiedź 4xx wraca do wołającego (logowanie: złe hasło)
     */
    private function send(callable $call, bool $allowClientErrors = false): Response
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

            if ($response !== null && ($response->successful()
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
