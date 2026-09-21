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
 * polstar.com.pl — sklep producenta (CakePHP) z kontem hurtowym. Adresy i pola odczytane z konta klienta 21.09.2026.
 *
 * Logowanie to formularz strony (/login, pola username i password, token _csrfToken z formularza, bez reCAPTCHA);
 * sesja żyje w ciasteczkach. Zalogowana strona ma odnośnik „/wyloguj” — po nim poznajemy sesję (niezalogowany
 * widzi „Zaloguj się”, a sklep na każdy adres odpowiada HTTP 200).
 *
 * Źródła (sprawdzone 21.09.2026):
 * - plik XML wszystkich produktów konta (/customers/customers/downloadProductsList/xml, zakładka „API” konta):
 *   599 produktów z nazwą, kolekcją, ceną detaliczną, normami, cechami, opisem szczegółowym, zdjęciami
 *   i wariantami (kolor × rozmiar z EAN) — bez ceny konta i bez adresu strony produktu;
 * - strony kategorii z menu (/katalog/…): kafelek każdego produktu z id, adresem strony i ceną konta
 *   („TWOJA CENA”, a bez rabatu „DETAL”); strona kategorii nie dzieli listy na strony;
 * - strona produktu (/produkt/{slug}): pliki PDF (deklaracja, instrukcja) i kategoria ochrony.
 */
final class PolstarB2bClient
{
    public const HOST = 'polstar.com.pl';

    public const BASE = 'https://polstar.com.pl';

    private const LOGIN_PAGE_URL = self::BASE.'/users/users/login?redirect=%2Fmoje_konto';

    private const LOGIN_URL = self::BASE.'/login';

    /** Strona kategorii, z której menu bierzemy listę wszystkich kategorii. */
    private const CATALOG_URL = self::BASE.'/katalog/rekawice';

    private const PRODUCTS_XML_URL = self::BASE.'/customers/customers/downloadProductsList/xml';

    private const SEARCH_URL = self::BASE.'/szukaj';

    private const SESSION_LOST = 'Utracono sesję konta polstar.com.pl — ceny konta niedostępne';

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
     * Formularz logowania z tokenem CSRF ze strony logowania. Złe hasło sklep zgłasza tą samą stroną logowania
     * z HTTP 200 — o wyniku decyduje odnośnik „/wyloguj” na stronie, na którą sklep przekierował.
     */
    public function login(): void
    {
        // nowa sesja — stare ciasteczka mogły należeć do wygasłej
        $this->jar = new CookieJar;
        $this->loggedIn = false;

        try {
            $form = (string) $this->send(static fn (PendingRequest $http): Response => $http->get(self::LOGIN_PAGE_URL))->body();
            if (preg_match('/name="_csrfToken"[^>]*value="([^"]+)"/', $form, $m) !== 1) {
                throw new RuntimeException('brak tokenu formularza logowania');
            }
            $token = $m[1];
            $page = (string) $this->send(fn (PendingRequest $http): Response => $http
                ->withHeaders(['Origin' => self::BASE, 'Referer' => self::LOGIN_PAGE_URL])
                ->asForm()
                ->post(self::LOGIN_URL, [
                    '_method' => 'POST',
                    '_csrfToken' => $token,
                    'username' => $this->username,
                    'password' => $this->password,
                ]))->body();
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        if (! self::isLoggedInPage($page)) {
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

    /** Plik XML wszystkich produktów konta, dosłownie. */
    public function productsXml(): string
    {
        return (string) $this->authenticated(
            fn (): Response => $this->send(static fn (PendingRequest $http): Response => $http->get(self::PRODUCTS_XML_URL)),
            static fn (Response $response): bool => str_contains(strtolower((string) $response->header('Content-Type')), 'xml')
                && str_contains((string) $response->body(), '<produkty'),
        )->body();
    }

    /**
     * Adresy wszystkich stron kategorii z menu sklepu (bez powtórzeń, w kolejności z menu).
     *
     * @return list<string>
     */
    public function categoryUrls(): array
    {
        $html = $this->loggedInPage(self::CATALOG_URL);
        preg_match_all('~href="(/katalog/[^"?#]+)"~', $html, $m);
        $urls = [];
        foreach ($m[1] as $path) {
            $urls[self::BASE.html_entity_decode($path)] = true;
        }

        return array_keys($urls);
    }

    /** Strona kategorii albo wyników wyszukiwania w sesji konta (HTML). */
    public function listingPage(string $url): string
    {
        return $this->loggedInPage($url);
    }

    public function searchPage(string $keyword): string
    {
        return $this->loggedInPage(self::SEARCH_URL.'?'.http_build_query(['keyword' => $keyword]));
    }

    /** Strona produktu w sesji konta (HTML). */
    public function page(string $url): string
    {
        return $this->loggedInPage($url);
    }

    /**
     * Zdjęcie albo plik PDF ze sklepu.
     *
     * @return array{bytes: string, mime: string}
     */
    public function fileBytes(string $url): array
    {
        $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url));
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        return ['bytes' => (string) $response->body(), 'mime' => $mime];
    }

    /** Adres na witrynie Polstaru (https) — tylko takie pobieramy i zapisujemy jako źródło. */
    public static function isOwnUrl(string $url): bool
    {
        return str_starts_with($url, self::BASE.'/');
    }

    public static function isLoggedInPage(string $html): bool
    {
        return str_contains($html, 'href="/wyloguj"');
    }

    private function loggedInPage(string $url): string
    {
        return (string) $this->authenticated(
            fn (): Response => $this->send(static fn (PendingRequest $http): Response => $http->get($url)),
            static fn (Response $response): bool => self::isLoggedInPage((string) $response->body()),
        )->body();
    }

    /**
     * Zapytanie w sesji konta: bez sesji logujemy się; odpowiedź bez oznak sesji = wygasła — jedno ponowne
     * logowanie, a gdy i po nim sklep jej nie uznaje, dalsze pobieranie nie ma sensu (B2bFatalException).
     *
     * @param  callable(): Response  $call
     * @param  callable(Response): bool  $inSession
     */
    private function authenticated(callable $call, callable $inSession): Response
    {
        if (! $this->loggedIn) {
            $this->login();
        }
        $response = $call();
        if (! $inSession($response)) {
            $this->login();
            $response = $call();
            if (! $inSession($response)) {
                throw new B2bFatalException(self::SESSION_LOST);
            }
        }

        return $response;
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
                $response = $call(Http::timeout(120)->withOptions([
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
