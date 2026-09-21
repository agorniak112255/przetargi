<?php

declare(strict_types=1);

namespace App\Services\B2b;

use Closure;
use DOMElement;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Panel B2B Procery b2b.procera.pl (ten sam silnik „B2B 3.x” na Laravelu co panel UVEX, bez przedrostka /public).
 * Nieoficjalne: formularz, adresy i klasy odczytane ze strony zalogowanego konta (21.09.2026).
 *
 * Logowanie: kontrahent (NIP) + osoba + hasło, formularz z tokenem _token wysyłany pod /login. Sesja w ciasteczkach.
 * Gość widzi katalog bez cen, a cennik XML przekierowuje go na /login — dlatego każda strona konta jest sprawdzana
 * linkiem wylogowania, a cennik tym, że jest plikiem XML. Zapytania idą po kolei, z przerwą przed każdym.
 */
final class ProceraB2bClient
{
    public const HOST = 'b2b.procera.pl';

    public const BASE = 'https://b2b.procera.pl';

    private const LOGIN_PAGE = self::BASE.'/login';

    private const START_PAGE = self::BASE.'/start';

    /**
     * Pełnej listy nie ma w menu; wyszukiwanie „%” (LIKE) zwraca wszystkie modele, 20 na stronę. Sortowanie po
     * kodzie — ta sama kolejność przy każdym pobraniu.
     */
    private const LIST_PAGE = self::BASE.'/product?query=%25&sort=code%2Basc&page=';

    /** Cennik konta (plik XML z ceną klienta i ceną cennikową hurtową każdego modelu). */
    private const PRICE_LIST = self::BASE.'/pricelist/xml';

    /** Link wylogowania jest tylko na stronach zalogowanego konta. */
    private const LOGGED_IN_MARKER = '/logout';

    /** Pole formularza logowania — strona, która je ma, to strona logowania (sesja nieważna). */
    private const LOGIN_FORM_MARKER = 'name="contractor_code"';

    private const SESSION_LOST = 'Utracono sesję konta b2b.procera.pl — ceny konta niedostępne';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503 i potknięciach sieci, gdy sklep nie podał Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    /** Górna granica Retry-After — lista idzie przed pierwszym produktem, długie czekanie wyglądałoby na przerwę. */
    private const MAX_RETRY_AFTER_MS = 120_000;

    private CookieJar $jar;

    private bool $loggedIn = false;

    private int $consecutiveFailures = 0;

    /** @var Closure(int): void */
    private Closure $sleep;

    /**
     * @param  (Closure(int): void)|null  $sleep  pauza w ms (w testach bez czekania)
     */
    public function __construct(
        private readonly string $contractorCode,
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

        if (trim($this->contractorCode) === '') {
            throw new RuntimeException(
                'Logowanie do '.self::HOST.' nieudane: konto nie ma kodu kontrahenta (uzupełnij pole „Kod kontrahenta” w koncie B2B — NIP firmy)'
            );
        }

        try {
            $page = $this->send(static fn (PendingRequest $http): Response => $http->get(self::LOGIN_PAGE))->body();
            $token = self::loginToken($page);
            if ($token === null) {
                throw new RuntimeException('brak formularza logowania na stronie');
            }
            $response = $this->send(fn (PendingRequest $http): Response => $http->asForm()->post(self::LOGIN_PAGE, [
                '_token' => $token,
                'contractor_code' => trim($this->contractorCode),
                'name' => $this->username,
                'password' => $this->password,
            ]));
            $confirmed = self::hasLoggedInMarker($response->body());
            if (! $confirmed) {
                $confirmed = self::hasLoggedInMarker(
                    $this->send(static fn (PendingRequest $http): Response => $http->get(self::START_PAGE))->body()
                );
            }
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        if (! $confirmed) {
            throw new RuntimeException(
                'Logowanie do '.self::HOST.' nieudane: sklep nie potwierdził zalogowania (brak linku wylogowania) — sprawdź kontrahenta (NIP), osobę i hasło'
            );
        }

        $this->loggedIn = true;
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    /** Strona listy wszystkich modeli (numerowana od 1) jako HTML zalogowanego konta. */
    public function listPage(int $page): string
    {
        return $this->accountPage(self::LIST_PAGE.max(1, $page), 'strona listy '.$page);
    }

    /** Strona wyrobu (adres z listy) jako HTML zalogowanego konta. */
    public function productPage(string $url): string
    {
        if (! self::isShopUrl($url)) {
            throw new RuntimeException('adres strony wyrobu spoza '.self::HOST.': '.$url);
        }

        return $this->accountPage($url, 'strona wyrobu');
    }

    /**
     * Cennik konta w XML. Gość dostaje stronę logowania zamiast pliku — wtedy jedno ponowne logowanie, a potem
     * B2bFatalException (bez sesji nie ma też cen na stronach wyrobów).
     */
    public function priceListXml(): string
    {
        if (! $this->loggedIn) {
            $this->relogin();
        }

        $body = $this->send(static fn (PendingRequest $http): Response => $http->get(self::PRICE_LIST))->body();
        if (! self::isPriceListXml($body)) {
            $this->relogin();
            $body = $this->send(static fn (PendingRequest $http): Response => $http->get(self::PRICE_LIST))->body();
            if (! self::isPriceListXml($body)) {
                $this->loggedIn = false;

                throw new B2bFatalException(self::SESSION_LOST.' (cennik XML bez pliku XML po ponownym logowaniu)');
            }
        }

        return $body;
    }

    /**
     * @return array{bytes: string, mime: string}
     */
    public function imageBytes(string $url): array
    {
        return $this->fileBytes($url, 'zdjęcia');
    }

    /**
     * Plik ze strony wyrobu (karta produktowa, instrukcja) albo zdjęcie — adres z panelu, pobierany sesją konta.
     *
     * @return array{bytes: string, mime: string}
     */
    public function fileBytes(string $url, string $label = 'pliku'): array
    {
        if (! self::isShopUrl($url)) {
            throw new RuntimeException('adres '.$label.' spoza '.self::HOST.': '.$url);
        }
        $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url));
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        return ['bytes' => $response->body(), 'mime' => $mime];
    }

    public static function hasLoggedInMarker(string $html): bool
    {
        return str_contains($html, self::LOGGED_IN_MARKER) && ! str_contains($html, self::LOGIN_FORM_MARKER);
    }

    public static function isShopUrl(string $url): bool
    {
        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https'
            && strtolower((string) parse_url($url, PHP_URL_HOST)) === self::HOST;
    }

    private static function isPriceListXml(string $body): bool
    {
        return str_starts_with(ltrim($body, "\xEF\xBB\xBF \t\r\n"), '<?xml') && str_contains($body, '<offer');
    }

    /**
     * Strona dla konta. Brak linku wylogowania (przekierowanie na logowanie) = sesja mogła wygasnąć → jedno ponowne
     * logowanie; nadal brak = B2bFatalException (dalsze strony nie miałyby cen konta).
     */
    private function accountPage(string $url, string $label): string
    {
        if (! $this->loggedIn) {
            $this->relogin();
        }

        $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url));
        if (! self::hasLoggedInMarker($response->body())) {
            $this->relogin();
            $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url));
            if (! self::hasLoggedInMarker($response->body())) {
                $this->loggedIn = false;

                throw new B2bFatalException(self::SESSION_LOST.' ('.$label.' bez linku wylogowania po ponownym logowaniu)');
            }
        }

        return $response->body();
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

    /** Token _token z formularza logowania (formularz rozpoznany po polu kontrahenta); null = brak formularza. */
    private static function loginToken(string $html): ?string
    {
        $xpath = JspB2bClient::dom($html);
        $form = $xpath->query('//form[.//input[@name="contractor_code"]]')->item(0);
        if ($form === null) {
            return null;
        }
        $input = $xpath->query('.//input[@name="_token"]', $form)->item(0);
        $token = $input instanceof DOMElement ? trim($input->getAttribute('value')) : '';

        return $token !== '' ? $token : null;
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
