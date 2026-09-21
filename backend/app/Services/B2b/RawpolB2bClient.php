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
 * web.rawpol.com — serwis RAW-POL (aplikacja JS). Adresy i pola odczytane z kodu serwisu i z konta klienta 21.09.2026.
 *
 * Katalog jest publiczny, w plikach danych puli publikacji (/rtm_dat/pool/{pula}/, pula z publishInfo.js):
 * def_produkty_core_list_pl.js (wyroby z wersjami), słowniki marek, linii, kolorów, norm i kategorii CE, a treść
 * wyrobu w resources/asset/product/info_pl/{kod w base64url}.js. Pliki to czysty JSON (tablice pozycyjne).
 *
 * Ceny konta daje tylko API (api.rawpol.com/service.aspx?req=…): zapytanie POST z polem formularza „body” (JSON),
 * sesja w ciasteczku. Logowanie: req=LogujKlienta {login, haslo: "", hasloSHA1: SHA1 hasła w UTF-16LE, hex wielkimi
 * literami} — logonStatus 1 = zalogowany. Ceny: req=GetProduktyDynamicData {symbole: kody wyrobów, tryb: 0} —
 * serwis wysyła do 500 wersji w jednym zapytaniu. Koperta odpowiedzi ma „logged” — false = sesja wygasła.
 *
 * Zdjęcia i PDF-y leżą na media.rawpol.com/photo/files/ i wymagają nagłówka Referer serwisu (bez niego HTTP 403).
 */
final class RawpolB2bClient
{
    public const HOST = 'rawpol.com';

    public const WEB = 'https://web.rawpol.com';

    public const MEDIA = 'https://media.rawpol.com/photo/files/';

    private const API = 'https://api.rawpol.com/service.aspx';

    private const PUBLISH_INFO = self::WEB.'/rtm_dat/pool/publishInfo.js';

    private const SERVER_DICT = self::WEB.'/rtm_server/serverdict.js';

    /** Tyle wersji serwis wysyła w jednym zapytaniu o ceny (tak samo robi jego aplikacja). */
    public const PRICE_BATCH_VERSIONS = 500;

    private const SESSION_LOST = 'Utracono sesję konta rawpol.com — ceny konta niedostępne';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503, gdy serwis nie podał Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    /** Górna granica Retry-After — dłuższa blokada = błąd zapytania, nie wielogodzinne czekanie. */
    private const MAX_RETRY_AFTER_MS = 900_000;

    /** Kody logonStatus z kodu serwisu (okno logowania). */
    private const LOGON_ERRORS = [
        -1 => 'zły login lub hasło',
        -2 => 'zły login lub hasło',
        -3 => 'konto zablokowane',
    ];

    private CookieJar $jar;

    private bool $loggedIn = false;

    private ?string $pool = null;

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
        // nowa sesja — stare ciasteczko mogło należeć do wygasłej
        $this->jar = new CookieJar;
        $this->loggedIn = false;

        try {
            $envelope = $this->api('LogujKlienta', [
                'login' => $this->username,
                'haslo' => '',
                'hasloSHA1' => self::passwordHash($this->password),
            ]);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        $status = $envelope['body']['logonStatus'] ?? null;
        if ($status !== 1 || ($envelope['logged'] ?? false) !== true) {
            $reason = is_int($status) && isset(self::LOGON_ERRORS[$status])
                ? self::LOGON_ERRORS[$status].' — sprawdź login i hasło'
                : 'serwis nie potwierdził zalogowania (logonStatus '.json_encode($status).')';

            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$reason);
        }
        $this->loggedIn = true;
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    /** Skrót hasła tak, jak liczy go serwis: SHA1 z tekstu w UTF-16LE, hex wielkimi literami. */
    public static function passwordHash(string $password): string
    {
        return strtoupper(sha1(mb_convert_encoding($password, 'UTF-16LE', 'UTF-8')));
    }

    /**
     * Plik danych puli publikacji (np. „def_produkty_core_list_pl”) jako tablica.
     *
     * @return array<mixed>
     */
    public function dataFile(string $name): array
    {
        return $this->json($this->poolUrl().$name.'.js', 'plik danych '.$name);
    }

    /**
     * Treść wyrobu (info_pl). null = serwis nie ma pliku tego wyrobu.
     *
     * @return array<mixed>|null
     */
    public function productInfo(string $encodedId): ?array
    {
        $url = $this->poolUrl().'resources/asset/product/info_pl/'.rawurlencode($encodedId).'.js';
        $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url), [404]);
        if ($response->status() === 404) {
            return null;
        }
        $json = json_decode((string) $response->body(), true);
        if (! is_array($json)) {
            throw new RuntimeException('treść wyrobu z '.self::HOST.' nie jest poprawnym JSON: '.$encodedId);
        }

        return $json;
    }

    /**
     * Słownik komunikatów serwisu (np. „srv_produkt_dostepny” → „Produkt dostępny”) — tylko wersja polska.
     *
     * @return array<string, string>
     */
    public function serverMessages(): array
    {
        $dict = [];
        foreach ($this->json(self::SERVER_DICT, 'słownik komunikatów') as $key => $texts) {
            if (is_string($key) && is_array($texts) && is_string($texts['pl'] ?? null)) {
                $dict[$key] = $texts['pl'];
            }
        }

        return $dict;
    }

    /**
     * Dane zmienne wyrobów konta (ceny, dostępność) wg kodu wyrobu. Koperta bez sesji = jedno ponowne logowanie,
     * a gdy i po nim serwis nie widzi konta, dalsze pobieranie nie ma sensu (B2bFatalException).
     *
     * @param  list<string>  $productIds  kody wyrobów z listy („rnitrio”, „3m-mas-6000”)
     * @return array<string, array<string, mixed>>
     */
    public function dynamicData(array $productIds): array
    {
        if (! $this->loggedIn) {
            $this->login();
        }
        $body = ['symbole' => array_values($productIds), 'tryb' => 0];
        $envelope = $this->api('GetProduktyDynamicData', $body);
        if (($envelope['logged'] ?? false) !== true) {
            $this->login();
            $envelope = $this->api('GetProduktyDynamicData', $body);
            if (($envelope['logged'] ?? false) !== true) {
                throw new B2bFatalException(self::SESSION_LOST);
            }
        }
        $products = $envelope['body']['produkty'] ?? null;
        if (! is_array($products)) {
            throw new RuntimeException(self::HOST.' nie podało danych wyrobów (brak body.produkty)');
        }

        return $products;
    }

    /**
     * Zdjęcie albo PDF z serwera plików (ścieżka z danych wyrobu, np. „5616/0025375616.pdf”).
     *
     * @return array{bytes: string, mime: string}
     */
    public function fileBytes(string $url): array
    {
        if (! self::isMediaUrl($url)) {
            throw new RuntimeException('plik spoza '.self::MEDIA.': '.$url);
        }
        $response = $this->send(static fn (PendingRequest $http): Response => $http
            ->withHeaders(['Referer' => self::WEB.'/'])
            ->get($url));
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        return ['bytes' => (string) $response->body(), 'mime' => $mime];
    }

    public static function mediaUrl(string $path): string
    {
        return self::MEDIA.ltrim($path, '/');
    }

    public static function isMediaUrl(string $url): bool
    {
        return str_starts_with($url, self::MEDIA) && strlen($url) > strlen(self::MEDIA);
    }

    /** Adres strony wyrobu w serwisie (karty są pod ?v=KOD). */
    public static function productUrl(string $productId): string
    {
        return self::WEB.'/?v='.rawurlencode(mb_strtoupper($productId)).'&lang=pl';
    }

    private function poolUrl(): string
    {
        if ($this->pool === null) {
            $info = $this->json(self::PUBLISH_INFO, 'informacja o publikacji');
            $pool = is_string($info['publishPoolId'] ?? null) ? trim($info['publishPoolId']) : '';
            if (preg_match('/^[A-Za-z0-9_-]+$/', $pool) !== 1) {
                throw new RuntimeException(self::HOST.' nie podało puli danych katalogu (publishPoolId)');
            }
            $this->pool = $pool;
        }

        return self::WEB.'/rtm_dat/pool/'.$this->pool.'/';
    }

    /**
     * @return array<mixed>
     */
    private function json(string $url, string $what): array
    {
        $json = json_decode((string) $this->send(static fn (PendingRequest $http): Response => $http->get($url))->body(), true);
        if (! is_array($json)) {
            throw new RuntimeException($what.' z '.self::HOST.' nie jest poprawnym JSON');
        }

        return $json;
    }

    /**
     * Zapytanie API z treścią w polu formularza „body”. Koperta ze statusem innym niż 0 = błąd serwisu.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function api(string $request, array $body): array
    {
        $url = self::API.'?'.http_build_query(['dve' => 0, 'req' => $request, 'lang' => 'pl', 'rnd' => mt_rand() / mt_getrandmax()]);
        // serwis oczekuje obiektu JSON — pusta tablica PHP to „[]”, a na nią odpowiada statusem -2
        $payload = json_encode((object) $body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $response = $this->send(static fn (PendingRequest $http): Response => $http
            ->withHeaders(['Origin' => self::WEB, 'Referer' => self::WEB.'/'])
            ->asMultipart()
            ->post($url, [['name' => 'body', 'contents' => $payload]]));
        $envelope = json_decode((string) $response->body(), true);
        if (! is_array($envelope)) {
            throw new RuntimeException(self::HOST.' odpowiedziało na '.$request.' pustą lub niepoprawną treścią');
        }
        if (($envelope['status'] ?? null) !== 0) {
            $messages = array_filter(array_map(
                static fn (mixed $m): string => is_array($m) && is_string($m['text'] ?? null) ? $m['text'] : '',
                is_array($envelope['msg'] ?? null) ? $envelope['msg'] : [],
            ));
            throw new RuntimeException(self::HOST.' odrzuciło '.$request.' (status '.json_encode($envelope['status'] ?? null).')'
                .($messages !== [] ? ': '.implode('; ', $messages) : ''));
        }

        return $envelope;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @param  list<int>  $accepted  kody HTTP traktowane jak odpowiedź (np. 404 pliku wyrobu), nie jak błąd
     */
    private function send(callable $call, array $accepted = []): Response
    {
        $retries = 0;
        while (true) {
            if ($this->delayMs > 0) {
                ($this->sleep)($this->delayMs);
            }

            $response = null;
            $error = null;
            try {
                $response = $call(Http::timeout(60)
                    ->withHeaders(['Accept-Encoding' => 'gzip'])
                    ->withOptions(['cookies' => $this->jar, 'allow_redirects' => ['max' => 5]]));
            } catch (ConnectionException $e) {
                $error = 'brak połączenia ('.$e->getMessage().')';
            }

            if ($response !== null && ($response->successful() || in_array($response->status(), $accepted, true))) {
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
