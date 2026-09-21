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
 * www.ardon.pl — sklep ARDON (Nette). Adresy i pola odczytane z konta klienta 21.09.2026.
 *
 * Logowanie to formularz strony (/eshop/user/sign-in: email, password i token CSRF „_token_” z tego samego
 * formularza); sesja żyje w ciasteczkach. Zalogowana strona ma odnośnik wylogowania — po nim poznajemy sesję.
 *
 * Źródło listy i cen: cennik konta w CSV (/eshop/download/price-list-csv, sekcja „ARDON® – Zdjęcia, Feedy,
 * Katalogi”) — wszystkie pozycje wszystkich marek z ceną po rabacie konta w jednym pliku. Feed XML sklep wydaje
 * dopiero po umowie licencyjnej i rejestracji IP, więc go nie używamy.
 *
 * CSV nie ma adresu strony produktu. Adres dają podpowiedzi wyszukiwarki (/full-text/query, JSON) — z sesją konta,
 * bo części wyrobów (np. ATG) sklep bez logowania nie pokazuje. Zdjęcia leżą na www.ardon.cz, pliki PDF pod
 * /eshop/download/product-attachment?id=… .
 */
final class ArdonB2bClient
{
    public const HOST = 'ardon.pl';

    public const BASE = 'https://www.ardon.pl';

    /** Serwer zdjęć sklepu (adresy z danych strukturalnych strony produktu). */
    public const IMAGE_BASE = 'https://www.ardon.cz';

    public const PRICE_LIST_HEADER = ['artykuł', 'nazwa', 'Sugerowana cena detaliczna bez VAT', 'Twój rabat', 'Twoja cena po rabacie bez VAT'];

    private const SIGN_IN_URL = self::BASE.'/eshop/user/sign-in';

    private const SIGN_OUT_PATH = '/eshop/user/sign-out';

    private const PRICE_LIST_URL = self::BASE.'/eshop/download/price-list-csv';

    private const SEARCH_URL = self::BASE.'/full-text/query';

    private const SESSION_LOST = 'Utracono sesję konta ardon.pl — cennik konta niedostępny';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503, gdy sklep nie podał Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    /** Górna granica Retry-After — dłuższa blokada = błąd zapytania, nie wielogodzinne czekanie. */
    private const MAX_RETRY_AFTER_MS = 900_000;

    private CookieJar $jar;

    private bool $loggedIn = false;

    /** Sprawdzanie sesji na stronach produktu; wyłączone, gdy ponowne logowanie nie przywróciło odnośnika wylogowania. */
    private bool $checkPageSession = true;

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
     * Formularz logowania z tokenem CSRF ze strony. Złe hasło sklep zgłasza tą samą stroną logowania z HTTP 200 —
     * o wyniku decyduje odnośnik wylogowania na stronie po wysłaniu formularza.
     */
    public function login(): void
    {
        // nowa sesja — stare ciasteczka mogły należeć do wygasłej
        $this->jar = new CookieJar;
        $this->loggedIn = false;

        try {
            $form = self::signInForm((string) $this->send(static fn (PendingRequest $http): Response => $http->get(self::SIGN_IN_URL))->body());
            if ($form === null) {
                throw new RuntimeException('na stronie logowania nie ma formularza z tokenem');
            }
            $page = (string) $this->send(fn (PendingRequest $http): Response => $http
                ->withHeaders(['Origin' => self::BASE, 'Referer' => self::SIGN_IN_URL])
                ->asForm()
                ->post($form['action'], [
                    'email' => $this->username,
                    'password' => $this->password,
                    '_token_' => $form['token'],
                    '_do' => 'userSignInForm-form-submit',
                    '_submit' => 'Zaloguj sie',
                ]))->body();
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        if (! self::isSignedIn($page)) {
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
     * Cennik konta (treść CSV). Bez sesji sklep odsyła stronę HTML zamiast pliku — wtedy jedno ponowne logowanie,
     * a gdy i po nim nie ma pliku, dalsze pobieranie nie ma sensu (B2bFatalException).
     */
    public function priceListCsv(): string
    {
        if (! $this->loggedIn) {
            $this->login();
        }
        $fetch = fn (): Response => $this->send(static fn (PendingRequest $http): Response => $http->get(self::PRICE_LIST_URL));
        $response = $fetch();
        if (! self::isCsv($response)) {
            $this->login();
            $response = $fetch();
            if (! self::isCsv($response)) {
                throw new B2bFatalException(self::SESSION_LOST);
            }
        }

        return (string) $response->body();
    }

    /**
     * Adresy stron produktów z podpowiedzi wyszukiwarki dla kodu, w kolejności sklepu. Podpowiedź to trafienie
     * pełnotekstowe, nie dopasowanie kodu — który adres jest tym wyrobem, rozstrzyga łącznik na stronie produktu.
     *
     * @return list<string>
     */
    public function searchProductUrls(string $query): array
    {
        $response = $this->send(static fn (PendingRequest $http): Response => $http
            ->acceptJson()
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get(self::SEARCH_URL, ['query' => $query]));
        $json = $response->json();
        $urls = [];
        foreach (is_array($json['products'] ?? null) ? $json['products'] : [] as $product) {
            $url = is_array($product) && is_string($product['url'] ?? null) ? trim($product['url']) : '';
            if (self::isOwnUrl($url) && ! in_array($url, $urls, true)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    /**
     * Strona produktu (HTML). Strona bez odnośnika wylogowania = sesja wygasła (pozycje widoczne tylko dla konta
     * zniknęłyby z wyników) — jedno ponowne logowanie i ponowne pobranie. Gdy i po nim odnośnika nie ma, sklep
     * zmienił wygląd strony, a nie sesję — dalsze strony nie wywołują logowania (inaczej logowanie przy każdej).
     */
    public function page(string $url): string
    {
        $fetch = fn (): string => (string) $this->send(static fn (PendingRequest $http): Response => $http->get($url))->body();
        $html = $fetch();
        if ($this->loggedIn && $this->checkPageSession && ! self::isSignedIn($html)) {
            $this->login();
            $html = $fetch();
            $this->checkPageSession = self::isSignedIn($html);
        }

        return $html;
    }

    /**
     * Zdjęcie albo PDF ze sklepu.
     *
     * @return array{bytes: string, mime: string}
     */
    public function fileBytes(string $url): array
    {
        if (! self::isOwnUrl($url) && ! self::isImageUrl($url)) {
            throw new RuntimeException('plik spoza '.self::HOST.': '.$url);
        }
        $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url));
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        return ['bytes' => (string) $response->body(), 'mime' => $mime];
    }

    /** Adres na witrynie sklepu (https) — tylko takie pobieramy i zapisujemy jako źródło. */
    public static function isOwnUrl(string $url): bool
    {
        return str_starts_with($url, self::BASE.'/');
    }

    public static function isImageUrl(string $url): bool
    {
        return str_starts_with($url, self::IMAGE_BASE.'/images/');
    }

    public static function isSignedIn(string $html): bool
    {
        return str_contains($html, self::BASE.self::SIGN_OUT_PATH) || str_contains($html, 'href="'.self::SIGN_OUT_PATH);
    }

    /**
     * Adres i token formularza logowania (formularz „frm-userSignInForm-form”; strona ma też formularz wyszukiwarki
     * z własnym tokenem).
     *
     * @return array{action: string, token: string}|null
     */
    public static function signInForm(string $html): ?array
    {
        if (preg_match('#<form\b[^>]*\bid="frm-userSignInForm-form"[^>]*>.*?</form>#s', $html, $form) !== 1) {
            return null;
        }
        if (preg_match('#name="_token_"\s+value="([^"]+)"#', $form[0], $token) !== 1) {
            return null;
        }
        $action = preg_match('#^<form\b[^>]*\baction="([^"]+)"#', $form[0], $m) === 1
            ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5)
            : self::SIGN_IN_URL;

        return ['action' => self::isOwnUrl($action) ? $action : self::SIGN_IN_URL, 'token' => $token[1]];
    }

    private static function isCsv(Response $response): bool
    {
        return str_starts_with(strtolower((string) $response->header('Content-Type')), 'text/csv');
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
                $response = $call(Http::timeout(60)->withOptions([
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
