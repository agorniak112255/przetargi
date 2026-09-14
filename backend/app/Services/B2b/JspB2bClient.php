<?php

declare(strict_types=1);

namespace App\Services\B2b;

use Closure;
use DOMDocument;
use DOMXPath;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sklep www.jspsafety.com (ASP.NET WebForms, silnik Netalogue). Nieoficjalne: formularz, adresy i klasy
 * odczytane ze strony www (14.09.2026). Brak API dla klienta — wszystko z HTML.
 * Sesja konta żyje w ciasteczkach (ASP.NET_SessionId, __AntiXsrfToken + ciasteczko logowania). Strona gościa
 * wygląda tak samo, tylko bez cen — dlatego logowanie i każda strona produktu są sprawdzane napisem „Logout Now”.
 * Zapytania idą po kolei (jedno połączenie), z przerwą przed każdym.
 */
final class JspB2bClient
{
    public const HOST = 'jspsafety.com';

    public const BASE = 'https://www.jspsafety.com';

    private const LOGIN_PAGE = self::BASE.'/login.aspx';

    /** robots.txt wskazuje tę mapę (zwykły XML). */
    private const SITEMAP = self::BASE.'/netalogue/sitemap.xml';

    /** Adres produktu działający dla konta: kod bez myślników, reszta ścieżki dowolna. */
    private const PRODUCT_PAGE = self::BASE.'/products/kw/a/';

    private const FORM_ID = 'aspnetForm';

    private const FIELD_USERNAME = 'ctl00$ContentPlaceHolder1$tbusername';

    private const FIELD_PASSWORD = 'ctl00$ContentPlaceHolder1$tbpassword';

    private const FIELD_SUBMIT = 'ctl00$ContentPlaceHolder1$blogin';

    /** Samo „Logout” jest też na stronie gościa (ukryty komunikat potwierdzenia) — tylko pełny napis. */
    private const LOGGED_IN_MARKER = 'Logout Now';

    private const SESSION_LOST = 'Utracono sesję konta jspsafety.com — ceny konta niedostępne';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503, gdy sklep nie podał Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    /** Górna granica Retry-After — dłuższa blokada = błąd zapytania, nie wielogodzinne czekanie. */
    private const MAX_RETRY_AFTER_MS = 900_000;

    private CookieJar $jar;

    private bool $loggedIn = false;

    private int $consecutiveFailures = 0;

    /** @var array<string, string> kod → końcówka adresu z mapy strony (tylko do czytelnego linku) */
    private array $slugs = [];

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

        try {
            $page = $this->send(static fn (PendingRequest $http): Response => $http->get(self::LOGIN_PAGE))->body();
            $fields = self::hiddenFormFields($page);
            if ($fields === null) {
                throw new RuntimeException('brak formularza logowania na stronie');
            }
            $response = $this->send(fn (PendingRequest $http): Response => $http->asForm()->post(self::LOGIN_PAGE, [
                ...$fields,
                self::FIELD_USERNAME => $this->username,
                self::FIELD_PASSWORD => $this->password,
                self::FIELD_SUBMIT => self::submitValue($page),
            ]));
            $confirmed = self::hasLoggedInMarker($response->body());
            if (! $confirmed) {
                $confirmed = self::hasLoggedInMarker(
                    $this->send(static fn (PendingRequest $http): Response => $http->get(self::BASE.'/'))->body()
                );
            }
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        if (! $confirmed) {
            throw new RuntimeException(
                'Logowanie do '.self::HOST.' nieudane: sklep nie potwierdził zalogowania (brak „Logout Now” na stronie) — sprawdź login i hasło'
            );
        }

        $this->loggedIn = true;
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    /**
     * Kody produktów standardowych z mapy strony, w kolejności z mapy, bez powtórzeń (ten sam kod bywa w kilku
     * kategoriach). Pomijane: produkty na zamówienie z logo innych firm (ścieżka /Bespoke-Products/ albo kod
     * z końcówką „-A6562”). Mapa psuje wielkość liter („1lEOCARB23S”) — kod zapisujemy wielkimi literami.
     *
     * @return list<string>
     */
    public function sitemapProductCodes(): array
    {
        try {
            $xml = $this->send(static fn (PendingRequest $http): Response => $http->get(self::SITEMAP))->body();
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Nie udało się pobrać mapy strony '.self::HOST.': '.$e->getMessage(), 0, $e);
        }
        if (! str_contains($xml, '<urlset')) {
            throw new RuntimeException('Mapa strony '.self::HOST.' ma nieznany format');
        }

        preg_match_all('#<loc>\s*([^<]+?)\s*</loc>#i', $xml, $m);
        $codes = [];
        $this->slugs = [];
        foreach ($m[1] as $loc) {
            $url = html_entity_decode($loc, ENT_QUOTES | ENT_XML1, 'UTF-8');
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (! str_starts_with($path, '/products/') || str_contains($path, '/Bespoke-Products/')) {
                continue;
            }
            $last = basename($path);
            $separator = strpos($last, '_');
            if ($separator === false || $separator === 0) {
                continue;
            }
            $code = strtoupper(rawurldecode(substr($last, 0, $separator)));
            if (preg_match('/-[A-Z]\d{4}$/', $code) === 1) {
                continue;
            }
            $key = self::normalizeCode($code);
            if ($key === '' || isset($this->slugs[$key])) {
                continue;
            }
            $this->slugs[$key] = substr($last, $separator + 1);
            $codes[] = $code;
        }

        return $codes;
    }

    /** Link do strony produktu zapisywany na karcie (bez sesji; końcówka z mapy tylko dla czytelności). */
    public function productUrl(string $code): string
    {
        $key = self::normalizeCode($code);
        $slug = $this->slugs[$key] ?? '';

        return self::PRODUCT_PAGE.rawurlencode($key).'_'.($slug !== '' ? $slug : 'x');
    }

    /**
     * Strona produktu dla konta. unavailable = sklep przekierował na stronę główną albo strona nie ma tytułu
     * produktu (kod poza katalogiem konta). Brak „Logout Now” = sesja mogła wygasnąć → jedno ponowne logowanie;
     * nadal brak = B2bFatalException (dalsze strony byłyby stronami gościa bez cen konta).
     *
     * @return array{status: 'ok'|'unavailable', html?: string, url: string}
     */
    public function productPage(string $code): array
    {
        if (! $this->loggedIn) {
            $this->relogin();
        }
        $url = $this->productUrl($code);

        $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url));
        if (! self::hasLoggedInMarker($response->body())) {
            $this->relogin();
            $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url));
            if (! self::hasLoggedInMarker($response->body())) {
                $this->loggedIn = false;

                throw new B2bFatalException(self::SESSION_LOST.' (strona produktu '.$code.' bez „Logout Now” po ponownym logowaniu)');
            }
        }

        $finalPath = (string) parse_url(self::finalUrl($response, $url), PHP_URL_PATH);
        if ($finalPath === '' || $finalPath === '/' || ! self::hasProductTitle($response->body())) {
            return ['status' => 'unavailable', 'url' => $url];
        }

        return ['status' => 'ok', 'html' => $response->body(), 'url' => $url];
    }

    /**
     * @return array{bytes: string, mime: string}
     */
    public function imageBytes(string $url): array
    {
        $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url));
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        return ['bytes' => $response->body(), 'mime' => $mime];
    }

    public static function hasLoggedInMarker(string $html): bool
    {
        return str_contains($html, self::LOGGED_IN_MARKER);
    }

    /** Kod do porównań i adresu: bez myślników i spacji, wielkie litery. */
    public static function normalizeCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[\s-]+/u', '', $code));
    }

    /**
     * HTML → DOM bez skryptów i stylów: szablony HTML w skryptach strony libxml potrafi wstawić do drzewa.
     */
    public static function dom(string $html): DOMXPath
    {
        $clean = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1\s*>#is', '', $html) ?? $html;
        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>'.$clean, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($doc);
    }

    /** XPath: element z daną klasą CSS (pełne słowo w atrybucie class). */
    public static function classPredicate(string $class): string
    {
        return "contains(concat(' ', normalize-space(@class), ' '), ' ".$class." ')";
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

    private static function hasProductTitle(string $html): bool
    {
        $node = self::dom($html)->query('//h1['.self::classPredicate('ProductTitleBar_Title').']')->item(0);

        return $node !== null && trim($node->textContent) !== '';
    }

    /**
     * Ukryte pola formularza ASP.NET (__VIEWSTATE, __EVENTVALIDATION…) do odesłania; null = brak formularza.
     *
     * @return array<string, string>|null
     */
    private static function hiddenFormFields(string $html): ?array
    {
        $xpath = self::dom($html);
        $form = $xpath->query('//form[@id="'.self::FORM_ID.'" or @name="'.self::FORM_ID.'"]')->item(0);
        if ($form === null) {
            return null;
        }
        $fields = [];
        foreach ($xpath->query('.//input[translate(@type, "HIDEN", "hiden")="hidden"][@name]', $form) ?: [] as $input) {
            if ($input instanceof \DOMElement && $input->getAttribute('name') !== '') {
                $fields[$input->getAttribute('name')] = $input->getAttribute('value');
            }
        }

        return $fields;
    }

    private static function submitValue(string $html): string
    {
        $button = self::dom($html)->query('//input[@name="'.self::FIELD_SUBMIT.'"]')->item(0);

        return $button instanceof \DOMElement ? $button->getAttribute('value') : '';
    }

    /** Adres po przekierowaniach (historia z Guzzle track_redirects); bez przekierowań — adres zapytania. */
    private static function finalUrl(Response $response, string $requested): string
    {
        $history = $response->header('X-Guzzle-Redirect-History');
        if ($history === '') {
            return $requested;
        }
        $all = $response->toPsrResponse()->getHeader('X-Guzzle-Redirect-History');

        return (string) end($all);
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
                $response = $call(Http::timeout(30)->withOptions([
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
