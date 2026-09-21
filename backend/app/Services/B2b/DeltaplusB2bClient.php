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
 * www.deltaplus.eu — witryna producenta Delta Plus (Liferay DXP + Commerce) z „przestrzenią partnera” dla kont
 * handlowych. Adresy i pola odczytane z konta klienta 21.09.2026.
 *
 * Logowanie to standardowy portlet Liferay (com_liferay_login_web_portlet_LoginPortlet) na /pl/espace-pro-landing:
 * adres formularza niesie jednorazowy token „p_auth”, więc przed każdym logowaniem pobieramy stronę i bierzemy
 * action oraz ukryte pola z tego samego formularza. Sesja żyje w ciasteczkach (JSESSIONID, COOKIE_SUPPORT…).
 * Zalogowana strona ma odnośnik wylogowania /c/portal/logout — tylko po nim poznajemy sesję (gość go nie ma).
 *
 * Lista oferty PL to strony kategorii /pl/dp/{kategoria}?start=N (15 wyrobów na stronę). Karta wyrobu:
 * /pl/p/{slug} — bez /pl/ i bez ciasteczek witryna podaje wersję angielską. Cena w tabeli referencji karty to cena
 * konta po rabacie; gość nie widzi kolumn ceny i dostępności. Cenę katalogową daje publiczny cennik xlsx ze strony
 * „Cenniki i promocje” (/group/delta-plus/local-sales-offer). Headless API katalogu podaje wyroby wszystkich krajów
 * z ceną 0 USD — nie używamy go.
 */
final class DeltaplusB2bClient
{
    public const HOST = 'deltaplus.eu';

    public const BASE = 'https://www.deltaplus.eu';

    public const LOGIN_URL = self::BASE.'/pl/espace-pro-landing';

    public const OFFER_URL = self::BASE.'/group/delta-plus/local-sales-offer';

    public const SIGN_OUT_PATH = '/c/portal/logout';

    /** Kategorie ŚOI oferty PL (ochrona zbiorowa i systemy stałe mają inną budowę kart — pomijamy). */
    public const CATEGORIES = ['head-protection', 'hand-protection', 'body-protection', 'foot-protection', 'fall-protection-1'];

    /** Hosty, z których pobieramy pliki (zdjęcia, karty techniczne, deklaracje). */
    private const FILE_HOSTS = ['www.deltaplus.eu', 'media.deltaplus.eu', 'delta-plus.ppe-analytics.com', 'assets.ppe-analytics.com'];

    /** Strona przestrzeni partnera — zapasowe potwierdzenie logowania, gdy odpowiedź na formularz nie ma menu konta. */
    private const ACCOUNT_URL = self::BASE.'/group/delta-plus/';

    private const LOGIN_FORM_ID = '_com_liferay_login_web_portlet_LoginPortlet_loginForm';

    private const SESSION_LOST = 'Utracono sesję konta deltaplus.eu — ceny konta niedostępne';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503, gdy witryna nie podała Retry-After (ms). */
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
     * Formularz portletu logowania z tokenem p_auth ze strony. Złe hasło Liferay zgłasza tą samą stroną z HTTP 200 —
     * o wyniku decyduje odnośnik wylogowania na stronie po wysłaniu formularza (po przekierowaniach), a gdy go tam
     * nie ma — na stronie przestrzeni partnera.
     */
    public function login(): void
    {
        // nowa sesja — stare ciasteczka mogły należeć do wygasłej
        $this->jar = new CookieJar;
        $this->loggedIn = false;

        try {
            $form = self::loginForm((string) $this->send(static fn (PendingRequest $http): Response => $http->get(self::LOGIN_URL))->body());
            if ($form === null) {
                throw new RuntimeException('na stronie logowania nie ma formularza logowania');
            }
            if (! str_starts_with($form['action'], self::BASE.'/')) {
                throw new RuntimeException('formularz logowania wysyła dane poza '.self::HOST.': '.$form['action']);
            }
            $fields = $form['fields'];
            $fields[$form['login_field']] = $this->username;
            $fields[$form['password_field']] = $this->password;
            $page = (string) $this->send(static fn (PendingRequest $http): Response => $http
                ->withHeaders(['Origin' => self::BASE, 'Referer' => self::LOGIN_URL])
                ->asForm()
                ->post($form['action'], $fields))->body();
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        if (! self::isSignedIn($page) && ! $this->accountPageSignedIn()) {
            throw new RuntimeException(
                'Logowanie do '.self::HOST.' nieudane: witryna nie potwierdziła zalogowania — sprawdź login i hasło'
            );
        }
        $this->loggedIn = true;
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    /** Strona listy kategorii oferty PL (HTML); sesja sprawdzana jak na kartach. */
    public function categoryPage(string $category, int $page): string
    {
        return (string) $this->signedInPage(self::categoryUrl($category, $page));
    }

    /** Karta wyrobu (HTML) albo null, gdy witryna odpowiada 404 (wyrób wycofany z oferty PL). */
    public function productPage(string $slug): ?string
    {
        return $this->signedInPage(self::productUrl($slug), allowNotFound: true);
    }

    /**
     * Publiczny cennik (xlsx) ze strony „Cenniki i promocje”: pierwszy odnośnik, którego ścieżka zawiera
     * „cennik-publiczny” i kończy się „xlsx” (obok leżą cenniki promocji — te pomijamy). Null, gdy odnośnika brak.
     *
     * @return array{name: string, url: string, bytes: string}|null
     */
    public function publicPriceListXlsx(): ?array
    {
        $html = (string) $this->signedInPage(self::OFFER_URL);
        preg_match_all('#\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))#i', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $url = self::absoluteUrl($m[1] !== '' ? $m[1] : (($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? '')));
            $path = $url === null ? '' : (string) parse_url($url, PHP_URL_PATH);
            if ($url === null || ! str_contains(strtolower($path), 'cennik-publiczny') || ! str_ends_with(strtolower($path), 'xlsx')) {
                continue;
            }
            $bytes = (string) $this->send(static fn (PendingRequest $http): Response => $http->get($url))->body();
            $segments = explode('/', rtrim($path, '/'));

            return ['name' => (string) end($segments), 'url' => $url, 'bytes' => $bytes];
        }

        return null;
    }

    /**
     * Zdjęcie albo dokument (PDF) z witryny lub serwerów plików producenta.
     *
     * @return array{bytes: string, mime: string}
     */
    public function fileBytes(string $url): array
    {
        if (! self::isAllowedFileUrl($url)) {
            throw new RuntimeException('plik spoza '.self::HOST.': '.$url);
        }
        $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url));
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        return ['bytes' => (string) $response->body(), 'mime' => $mime];
    }

    public static function productUrl(string $slug): string
    {
        return self::BASE.'/pl/p/'.$slug;
    }

    public static function categoryUrl(string $category, int $page): string
    {
        return self::BASE.'/pl/dp/'.$category.'?start='.$page;
    }

    /**
     * Adres bezwzględny z atrybutu href/src strony: względny „/x” na witrynie, „//host/x” jako https. Adres spoza
     * dozwolonych hostów (albo pusty) → null — takich nie pobieramy ani nie zapisujemy jako źródła.
     */
    public static function absoluteUrl(string $href): ?string
    {
        return self::resolveUrl(trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5)));
    }

    /** absoluteUrl dla adresu już zdekodowanego z encji HTML. */
    private static function resolveUrl(string $href): ?string
    {
        if ($href === '') {
            return null;
        }
        if (str_starts_with($href, '//')) {
            $href = 'https:'.$href;
        } elseif (str_starts_with($href, '/')) {
            return self::BASE.$href;
        }

        return self::isAllowedFileUrl($href) ? $href : null;
    }

    public static function isSignedIn(string $html): bool
    {
        return str_contains($html, self::SIGN_OUT_PATH);
    }

    /** https na jednym z hostów producenta (bez danych logowania i nietypowego portu w adresie). */
    public static function isAllowedFileUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        return in_array(strtolower($parts['host'] ?? ''), self::FILE_HOSTS, true);
    }

    /**
     * Formularz portletu logowania: adres (z tokenem p_auth), ukryte pola z wartościami i nazwy pól loginu i hasła.
     * Strona ma dziesiątki formularzy, w tym drugi z polem hasła (portlet danych użytkownika) — bierzemy wyłącznie
     * formularz o id „_com_liferay_login_web_portlet_LoginPortlet_loginForm”. Atrybuty pól bywają w różnej
     * kolejności, więc każdy znacznik rozbieramy na atrybuty. Pole „zapamiętaj mnie” (checkbox) nie jest wysyłane.
     *
     * @return array{action: string, fields: array<string, string>, login_field: string, password_field: string}|null
     */
    public static function loginForm(string $html): ?array
    {
        $tag = '(?:[^>"\']|"[^"]*"|\'[^\']*\')*';
        if (preg_match_all('#<form\b('.$tag.')>#i', $html, $forms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return null;
        }
        foreach ($forms as $form) {
            $attrs = self::attributes($form[1][0]);
            if (($attrs['id'] ?? '') !== self::LOGIN_FORM_ID) {
                continue;
            }
            // treść formularza do jego zamknięcia (formularze HTML nie są zagnieżdżane)
            $start = $form[0][1] + strlen($form[0][0]);
            $end = stripos($html, '</form>', $start);
            $body = substr($html, $start, $end === false ? null : $end - $start);

            $fields = [];
            $loginField = null;
            $passwordField = null;
            preg_match_all('#<input\b('.$tag.')>#i', $body, $inputs, PREG_SET_ORDER);
            foreach ($inputs as $input) {
                $field = self::attributes($input[1]);
                $name = $field['name'] ?? '';
                if ($name === '') {
                    continue;
                }
                $type = strtolower($field['type'] ?? 'text');
                if ($type === 'hidden') {
                    $fields[$name] = $field['value'] ?? '';
                } elseif ($type === 'password') {
                    $passwordField ??= $name;
                } elseif (in_array($type, ['text', 'email'], true) && str_ends_with($name, '_login')) {
                    $loginField ??= $name;
                }
            }
            if ($loginField === null || $passwordField === null) {
                return null;
            }
            // atrybuty są już po html_entity_decode; adres spoza witryny zostaje dosłownie — odrzuca go login()
            $action = trim($attrs['action'] ?? '');
            $action = $action === '' ? self::LOGIN_URL : (self::resolveUrl($action) ?? $action);

            return ['action' => $action, 'fields' => $fields, 'login_field' => $loginField, 'password_field' => $passwordField];
        }

        return null;
    }

    /**
     * Atrybuty znacznika (nazwy małymi literami, wartości po html_entity_decode; atrybut bez wartości = '').
     *
     * @return array<string, string>
     */
    private static function attributes(string $tag): array
    {
        preg_match_all('#([^\s"\'<>/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?#', $tag, $matches, PREG_SET_ORDER);
        $attrs = [];
        foreach ($matches as $m) {
            $name = strtolower($m[1]);
            if (! isset($attrs[$name])) {
                $value = ($m[2] ?? '').($m[3] ?? '').($m[4] ?? '');
                $attrs[$name] = html_entity_decode($value, ENT_QUOTES | ENT_HTML5);
            }
        }

        return $attrs;
    }

    /**
     * Strona z sesją konta. Strona bez odnośnika wylogowania = sesja wygasła (ceny konta zniknęłyby z kart) — jedno
     * ponowne logowanie i ponowne pobranie; gdy i wtedy odnośnika nie ma, dalsze pobieranie dałoby karty bez cen
     * konta (B2bFatalException). Przed pierwszą stroną — logowanie.
     */
    private function signedInPage(string $url, bool $allowNotFound = false): ?string
    {
        if (! $this->loggedIn) {
            $this->login();
        }
        $fetch = fn (): Response => $this->send(
            static fn (PendingRequest $http): Response => $http->get($url),
            $allowNotFound ? [404] : [],
        );
        $response = $fetch();
        if ($response->status() === 404) {
            return null;
        }
        $html = (string) $response->body();
        if (self::isSignedIn($html)) {
            return $html;
        }

        try {
            $this->login();
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new B2bFatalException(self::SESSION_LOST.' ('.$e->getMessage().')', 0, $e);
        }
        $response = $fetch();
        if ($response->status() === 404) {
            return null;
        }
        $html = (string) $response->body();
        if (! self::isSignedIn($html)) {
            throw new B2bFatalException(self::SESSION_LOST);
        }

        return $html;
    }

    /** Zapasowe potwierdzenie logowania: strona przestrzeni partnera z odnośnikiem wylogowania. */
    private function accountPageSignedIn(): bool
    {
        try {
            return self::isSignedIn((string) $this->send(static fn (PendingRequest $http): Response => $http->get(self::ACCOUNT_URL))->body());
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @param  list<int>  $acceptStatuses  kody poza 2xx, które są odpowiedzią, nie błędem (np. 404 karty wyrobu)
     */
    private function send(callable $call, array $acceptStatuses = []): Response
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

            if ($response !== null && ($response->successful() || in_array($response->status(), $acceptStatuses, true))) {
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
