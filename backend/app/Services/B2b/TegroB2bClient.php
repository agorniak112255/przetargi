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
 * b2b.tegro.pl — sklep na platformie SolEx B2B (ASP.NET). Adresy i pola odczytane z konta klienta 21.09.2026.
 *
 * Logowanie to zwykły formularz strony (/logowanie, pola Uzytkownik i Haslo, bez reCAPTCHA); sesja żyje
 * w ciasteczkach. Tą samą sesją odpowiada API platformy (/api3, opis w /swagger): lista produktów konta
 * z ceną po rabacie w jednym zapytaniu. Bez sesji API odpowiada HTTP 401 — po tym poznajemy jej utratę.
 *
 * Czego API nie podaje (sprawdzone na wszystkich 111 modelach 21.09.2026): adresu strony produktu (jest
 * w pliku XML oferty konta), tabelki „Parametry produktu” (pakowanie, zakres rozmiarów, kod CN, normy) i plików
 * PDF (karta katalogowa, deklaracja, instrukcja) — te są tylko na stronie produktu. Zdjęcia i PDF-y leżą pod
 * /zasoby/ i nie wymagają logowania.
 */
final class TegroB2bClient
{
    public const HOST = 'b2b.tegro.pl';

    public const BASE = 'https://b2b.tegro.pl';

    /** Pola listy produktów z API (ApiProduct w /swagger/docs/v3). */
    public const PRODUCT_FIELDS = 'Id,Name,Ean,Sku,Description,Model,Brand,Unit,Vat,InStock,RetailPriceNet,PriceAfterDiscountNet,Attributes,Categories,Photo';

    private const LOGIN_PAGE_URL = self::BASE.'/pl/logowanie';

    private const LOGIN_URL = self::BASE.'/logowanie';

    private const HOME_URL = self::BASE.'/pl/home';

    private const PRODUCTS_URL = self::BASE.'/api3/product/findProduct';

    /** Plik XML oferty konta (lista „DataFeed” w API) — jedyne miejsce z adresem strony każdego produktu. */
    private const OFFER_XML_URL = self::BASE.'/pl/xmlapi/1/3/UTF8';

    private const SESSION_LOST = 'Utracono sesję konta b2b.tegro.pl — ceny konta niedostępne';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503, gdy sklep nie podał Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    /** Górna granica Retry-After — dłuższa blokada = błąd zapytania, nie wielogodzinne czekanie. */
    private const MAX_RETRY_AFTER_MS = 900_000;

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
        private readonly int $delayMs = 150,
        ?Closure $sleep = null,
    ) {
        $this->jar = new CookieJar;
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /**
     * Formularz logowania, potem potwierdzenie na stronie głównej: sklep wpisuje stan sesji w JSON strony
     * („isLoggedIn”: true). Złe hasło sklep zgłasza tą samą stroną logowania z HTTP 200 — o wyniku decyduje
     * dopiero to potwierdzenie.
     */
    public function login(): void
    {
        // nowa sesja — stare ciasteczka mogły należeć do wygasłej
        $this->jar = new CookieJar;
        $this->loggedIn = false;

        try {
            // pierwsze wejście ustawia ciasteczka sklepu, z którymi przeglądarka wysyła formularz
            $this->send(static fn (PendingRequest $http): Response => $http->get(self::LOGIN_PAGE_URL));
            $this->send(fn (PendingRequest $http): Response => $http
                ->withHeaders(['Origin' => self::BASE, 'Referer' => self::LOGIN_PAGE_URL])
                ->asForm()
                ->post(self::LOGIN_URL, [
                    'Uzytkownik' => $this->username,
                    'Haslo' => $this->password,
                    'logowanie' => '',
                ]));
            $home = (string) $this->send(static fn (PendingRequest $http): Response => $http->get(self::HOME_URL))->body();
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        if (preg_match('/"isLoggedIn"\s*:\s*true/', $home) !== 1) {
            throw new RuntimeException(
                'Logowanie do '.self::HOST.' nieudane: sklep nie potwierdził zalogowania — sprawdź login i hasło'
            );
        }
        $this->loggedIn = true;
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    /**
     * Wszystkie produkty konta jednym zapytaniem (API nie dzieli listy na strony: PageSize = int.MaxValue).
     * Lista niepełna (HasMore albo mniej pozycji niż Count) = błąd — z połowy listy nie da się zbudować cennika.
     *
     * @return list<array<string, mixed>>
     */
    public function products(): array
    {
        $json = $this->apiJson(self::PRODUCTS_URL.'?'.http_build_query(['field' => self::PRODUCT_FIELDS]));
        $items = is_array($json['Items'] ?? null) ? array_values(array_filter($json['Items'], 'is_array')) : null;
        if ($items === null) {
            throw new RuntimeException('API '.self::HOST.' nie zwróciło listy produktów');
        }
        $count = is_numeric($json['Count'] ?? null) ? (int) $json['Count'] : count($items);
        if (($json['HasMore'] ?? false) === true || $count !== count($items)) {
            throw new RuntimeException(
                'API '.self::HOST.' zwróciło niepełną listę produktów ('.count($items).' z '.$count.')'
            );
        }

        return $items;
    }

    /**
     * Adresy stron produktów z pliku XML oferty konta: id produktu → adres. Plik, którego nie da się odczytać
     * jako XML, daje pustą mapę; błąd zapytania — wyjątek (łącznik pobiera wtedy sam cennik, bez stron produktów).
     *
     * @return array<string, string>
     */
    public function productUrls(): array
    {
        $xml = $this->authenticated(fn (): Response => $this->send(
            static fn (PendingRequest $http): Response => $http->get(self::OFFER_XML_URL),
        ));

        return self::urlsFromOfferXml((string) $xml->body());
    }

    /**
     * @return array<string, string>
     */
    public static function urlsFromOfferXml(string $xml): array
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, options: LIBXML_NOCDATA | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($document === false) {
            return [];
        }

        $urls = [];
        foreach ($document->product as $product) {
            $id = trim((string) $product->id);
            $url = trim((string) $product->url);
            if ($id !== '' && self::isOwnUrl($url)) {
                $urls[$id] = $url;
            }
        }

        return $urls;
    }

    /** Strona produktu (HTML). */
    public function page(string $url): string
    {
        return (string) $this->send(static fn (PendingRequest $http): Response => $http->get($url))->body();
    }

    /**
     * Zdjęcie albo PDF z /zasoby/.
     *
     * @return array{bytes: string, mime: string}
     */
    public function fileBytes(string $url): array
    {
        $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url));
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        return ['bytes' => (string) $response->body(), 'mime' => $mime];
    }

    /** Adres na witrynie Tegro (https) — tylko takie pobieramy i zapisujemy jako źródło. */
    public static function isOwnUrl(string $url): bool
    {
        return str_starts_with($url, self::BASE.'/');
    }

    /**
     * @return array<string, mixed>
     */
    private function apiJson(string $url): array
    {
        $response = $this->authenticated(fn (): Response => $this->send(
            static fn (PendingRequest $http): Response => $http->acceptJson()->get($url),
            [401],
        ));
        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * Zapytanie w sesji konta: bez sesji logujemy się; HTTP 401 = sesja wygasła — jedno ponowne logowanie,
     * a gdy i po nim sklep odmawia, dalsze pobieranie nie ma sensu (B2bFatalException).
     *
     * @param  callable(): Response  $call
     */
    private function authenticated(callable $call): Response
    {
        if (! $this->loggedIn) {
            $this->login();
        }
        $response = $call();
        if ($response->status() === 401) {
            $this->login();
            $response = $call();
            if ($response->status() === 401) {
                throw new B2bFatalException(self::SESSION_LOST);
            }
        }
        if (! $response->successful()) {
            throw new RuntimeException('API '.self::HOST.' odpowiedziało HTTP '.$response->status());
        }

        return $response;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @param  list<int>  $passThrough  statusy oddawane wołającemu bez wyjątku (np. 401 do ponownego logowania)
     */
    private function send(callable $call, array $passThrough = []): Response
    {
        $retries = 0;
        while (true) {
            if ($this->delayMs > 0) {
                ($this->sleep)($this->delayMs);
            }

            $response = null;
            $error = null;
            try {
                $response = $call(Http::timeout(60)->withOptions([
                    'cookies' => $this->jar,
                    'allow_redirects' => ['max' => 5],
                ]));
            } catch (ConnectionException $e) {
                $error = 'brak połączenia ('.$e->getMessage().')';
            }

            if ($response !== null && ($response->successful() || in_array($response->status(), $passThrough, true))) {
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
