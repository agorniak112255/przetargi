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
 * pl.msasafety.com — sklep MSA Safety Polska (SAP Commerce / Hybris accstorefront). Nieoficjalne: formularze, adresy
 * i znaczniki odczytane ze stron witryny 01.10.2026 (strony gościa i logowanie konta do kroku kodu).
 *
 * Logowanie: GET /login (ukryte pole _requestConfirmationToken), POST /j_spring_security_check (j_username,
 * j_password, token), po nim przekierowanie /choose-b2b-unit → /additionalStepLogin: MSA wysyła e-mailem kod
 * weryfikacyjny, a strony sklepu do czasu wpisania kodu przekierowują na prośbę o kod. Serwer kodu nie zobaczy, więc
 * logowanie ma dwa kroki z panelu (startCodeLogin() / finishCodeLogin(), B2bCodeLoginSite, jak 3M). Kod wpisujemy
 * z zaznaczonym „Pomiń weryfikację na tym urządzeniu przez 30 dni” — ciasteczka ustawione przez tę odpowiedź
 * z terminem ważności (deviceCookies) zostają w sesji na koncie. login() w przebiegu: sesja z konta żywa → zalogowany;
 * wygasła, a ciasteczko urządzenia jeszcze ważne → logowanie e-mailem i hasłem bez kodu; inaczej B2bFatalException
 * z prośbą o „Zaloguj kodem” (bez ciasteczka urządzenia hasła w ogóle nie wysyłamy, bo każde logowanie wysyła e-mail
 * z kodem na skrzynkę konta).
 *
 * Gość widzi strony wyrobów z numerami części, ale bez cen (data-permission="-1" w #pricing-config-variables). Ceny
 * konta: POST /sap/retrieveAllProductPricing.json (unitOfMeasure, priceRequestJson, _requestConfirmationToken) jak
 * skrypt strony (msa-r22.js, retrieveBatchProductPricing). Pliki: /bynder/search/{kod produktu} (lista JSON),
 * /bynder/asset_download/{id} (podpisany adres pliku na assetlibrary.msasafety.com, ważny ograniczony czas — dlatego
 * źródłem pliku zapisywanym przy karcie jest stały adres asset_download).
 *
 * robots.txt prosi roboty o 20 s między stronami; zalogowany klient B2B to nie robot, ale tempo jest oszczędne:
 * zapytania po kolei, przerwa co najmniej MIN_DELAY_MS przed każdym (forAccount łącznika).
 */
final class MsaB2bClient
{
    public const HOST = 'pl.msasafety.com';

    public const BASE = 'https://pl.msasafety.com';

    /** Zdjęcia i pliki MSA (Bynder) — publiczne, bez sesji. */
    public const FILE_HOST = 'assetlibrary.msasafety.com';

    /** Najkrótsza przerwa przed zapytaniem w przebiegu (forAccount) — sklep prosi roboty o 20 s, klient B2B ≥ 1 s. */
    public const MIN_DELAY_MS = 1000;

    public const CODE_REQUIRED = 'MSA wymaga kodu z e-maila — w Cennikach B2B przy koncie MSA kliknij „Zaloguj kodem”, a pobieranie ruszy po zalogowaniu.';

    private const LOGIN_PAGE = self::BASE.'/login';

    private const LOGIN_POST = self::BASE.'/j_spring_security_check';

    private const OTP_VERIFY = self::BASE.'/additionalStepLogin/verify';

    /** Strona konta — po wpisaniu kodu skrypt strony przechodzi właśnie tu; bez sesji sklep przekierowuje na logowanie. */
    private const ACCOUNT_HOME = self::BASE.'/my-account/home';

    private const SITEMAP = self::BASE.'/sitemap.xml';

    private const PRICES = self::BASE.'/sap/retrieveAllProductPricing.json';

    private const DOCUMENTS = self::BASE.'/bynder/search/';

    public const ASSET_DOWNLOAD = self::BASE.'/bynder/asset_download/';

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    private const SESSION_LOST = 'Utracono sesję konta pl.msasafety.com — ceny konta niedostępne';

    /** Odnośnik wylogowania — w nagłówku stron zalogowanego konta (także na prośbie o kod, więc sam nie wystarcza). */
    private const LOGOUT_MARKER = 'href="/logout"';

    /** Formularz kodu z e-maila (/additionalStepLogin). */
    private const OTP_MARKER = 'id="otpForm"';

    /** Formularz logowania (/login). */
    private const LOGIN_MARKER = 'id="loginForm"';

    /** Ciasteczko urządzenia musi być ważne jeszcze tyle sekund, żeby przebieg próbował logowania bez kodu. */
    private const DEVICE_COOKIE_MARGIN_SECONDS = 600;

    /** Ciasteczko z odpowiedzi na kod ważne dłużej niż to (s) = zapamiętane urządzenie (MSA: 30 dni). */
    private const DEVICE_COOKIE_MIN_LIFETIME_SECONDS = 7 * 86400;

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503 i potknięciach sieci, gdy sklep nie podał Retry-After (ms). */
    private const BACKOFF_MS = [5000, 20000, 60000];

    private const MAX_RETRY_AFTER_MS = 120_000;

    private const TIMEOUT_SECONDS = 60;

    /** Największy plik (PDF, zdjęcie) — synchronizacja i tak przyjmuje dokumenty do 12 MB. */
    private const FILE_MAX_BYTES = 15_000_000;

    private CookieJar $jar;

    /** @var list<string> nazwy ciasteczek „zapamiętanego urządzenia” (z odpowiedzi na kod) */
    private array $deviceCookies;

    private bool $loggedIn = false;

    /** Udane logowania hasłem w tym przebiegu (bez wznowienia sesji z konta). */
    private int $logins = 0;

    private int $consecutiveFailures = 0;

    /** @var Closure(int): void */
    private Closure $sleep;

    /**
     * @param  array<string, mixed>  $session  sesja z konta (session()); [] = brak
     * @param  (Closure(int): void)|null  $sleep  pauza w ms (w testach bez czekania)
     */
    public function __construct(
        private readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
        array $session = [],
        private readonly int $delayMs = self::MIN_DELAY_MS,
        ?Closure $sleep = null,
    ) {
        $this->jar = self::jarFrom($session['cookies'] ?? null);
        $this->deviceCookies = array_values(array_filter(
            is_array($session['device_cookies'] ?? null) ? $session['device_cookies'] : [],
            static fn (mixed $name): bool => is_string($name) && $name !== '',
        ));
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /**
     * Sesja z konta żywa → zalogowany. Wygasła, a ciasteczko zapamiętanego urządzenia ważne → e-mail i hasło bez kodu.
     * Inaczej (brak sesji, brak ciasteczka urządzenia, MSA mimo to pyta o kod) — B2bFatalException z prośbą
     * o logowanie kodem.
     */
    public function login(): void
    {
        $this->loggedIn = false;
        if ($this->jar->count() === 0) {
            throw new B2bFatalException(self::CODE_REQUIRED);
        }

        try {
            if (self::isAccountPage($this->get(self::ACCOUNT_HOME))) {
                $this->loggedIn = true;

                return;
            }
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new B2bFatalException('Nie udało się sprawdzić sesji '.self::HOST.' ('.$e->getMessage().')', 0, $e);
        }

        if (! $this->hasValidDeviceCookie()) {
            throw new B2bFatalException('Sesja konta '.self::HOST.' wygasła. '.self::CODE_REQUIRED);
        }

        try {
            $page = $this->passwordLogin();
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new B2bFatalException('Logowanie do '.self::HOST.' nieudane ('.$e->getMessage().'). '.self::CODE_REQUIRED, 0, $e);
        }
        if (str_contains($page, self::OTP_MARKER)) {
            throw new B2bFatalException('MSA poprosił o kod mimo zapamiętanego urządzenia (kod poszedł e-mailem). '.self::CODE_REQUIRED);
        }
        if (! self::isAccountPage($page) && ! self::isAccountPage($this->get(self::ACCOUNT_HOME))) {
            throw new B2bFatalException('Sklep '.self::HOST.' nie potwierdził zalogowania. '.self::CODE_REQUIRED);
        }
        $this->loggedIn = true;
        $this->logins++;
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    public function logins(): int
    {
        return $this->logins;
    }

    /**
     * Sesja do zapisania na koncie: ciasteczka sklepu i nazwy ciasteczek zapamiętanego urządzenia.
     *
     * @return array{cookies: list<array<string, mixed>>, device_cookies: list<string>, saved_at: string}
     */
    public function session(): array
    {
        return [
            'cookies' => array_values($this->jar->toArray()),
            'device_cookies' => $this->deviceCookies,
            'saved_at' => date(DATE_ATOM),
        ];
    }

    /**
     * Krok 1 „Zaloguj kodem”: e-mail i hasło na świeżej sesji; MSA wysyła kod e-mailem. Stan bez hasła.
     * Gdy sklep nie poprosi o kod, stan niesie od razu gotową sesję.
     *
     * @return array{state: array<string, mixed>, message: string}
     */
    public function startCodeLogin(): array
    {
        $this->jar = new CookieJar;
        $this->deviceCookies = [];
        $this->loggedIn = false;

        try {
            $page = $this->passwordLogin();
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        if (str_contains($page, self::OTP_MARKER)) {
            $form = self::otpForm($page);
            if ($form['token'] === '') {
                throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: prośba o kod bez pola _requestConfirmationToken (zmiana witryny?)');
            }

            return [
                'state' => [
                    'cookies' => array_values($this->jar->toArray()),
                    'email' => $form['email'],
                    'token' => $form['token'],
                    'started_at' => date(DATE_ATOM),
                ],
                'message' => 'Kod wysłany'.($form['email'] !== '' ? ' na '.$form['email'] : ' e-mailem').'. Wpisz go poniżej.',
            ];
        }
        if (self::isAccountPage($page)) {
            $this->loggedIn = true;

            return ['state' => ['session' => $this->session()], 'message' => 'MSA zalogował bez kodu.'];
        }
        if (str_contains($page, self::LOGIN_MARKER)) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: sklep wrócił do formularza logowania — sprawdź e-mail i hasło konta');
        }

        throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: po haśle sklep nie pokazał ani prośby o kod, ani konta (zmiana witryny?)');
    }

    /**
     * Krok 2: kod z e-maila (POST /additionalStepLogin/verify jak skrypt strony, z „Pomiń weryfikację na tym urządzeniu
     * przez 30 dni”), potem potwierdzenie na stronie konta. Zwraca sesję do zapisania na koncie.
     *
     * @param  array<string, mixed>  $state  z startCodeLogin()
     * @return array{cookies: list<array<string, mixed>>, device_cookies: list<string>, saved_at: string}
     */
    public function finishCodeLogin(array $state, string $code): array
    {
        if (is_array($state['session'] ?? null)) {
            $this->jar = self::jarFrom($state['session']['cookies'] ?? null);
            $this->deviceCookies = array_values(array_filter((array) ($state['session']['device_cookies'] ?? []), 'is_string'));

            return $this->session();
        }
        $code = trim($code);
        // kod MSA ma cyfry i litery (wielkość liter bez zmian — przepisany dosłownie z e-maila)
        if (! ctype_alnum($code)) {
            throw new RuntimeException('Kod weryfikacyjny to litery i cyfry z e-maila od MSA (bez spacji i innych znaków).');
        }
        $token = $state['token'] ?? null;
        if (! is_string($token) || $token === '' || ! is_array($state['cookies'] ?? null)) {
            throw new RuntimeException('Stan logowania MSA niekompletny — kliknij „Wyślij kod” jeszcze raz.');
        }
        $this->jar = self::jarFrom($state['cookies']);
        $this->loggedIn = false;
        $before = self::cookieNames($this->jar);

        try {
            $response = $this->send(static fn (PendingRequest $http): Response => $http
                ->asForm()
                ->accept('application/json, text/javascript, */*; q=0.01')
                ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Referer' => self::BASE.'/additionalStepLogin'])
                ->post(self::OTP_VERIFY, [
                    'otpCode' => $code,
                    'email' => is_string($state['email'] ?? null) ? $state['email'] : '',
                    'rememberme' => 'true',
                    '_rememberme' => 'on',
                    '_requestConfirmationToken' => $token,
                ]));
            $json = json_decode(self::bodyOf($response), true);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.': '.$e->getMessage(), 0, $e);
        }
        if (! is_array($json)) {
            throw new RuntimeException('Logowanie do '.self::HOST.': odpowiedź na kod nie jest JSON-em (zmiana witryny?)');
        }
        $verified = (string) ($json['verified'] ?? '');
        $error = trim(strip_tags((string) ($json['errorMessage'] ?? '')));
        if ($verified === '0') {
            throw new RuntimeException('Kod nieprawidłowy albo wygasł'.($error !== '' ? ' ('.$error.')' : '').'.');
        }
        if ($verified !== '1') {
            throw new RuntimeException('MSA przerwał logowanie'.($error !== '' ? ' ('.$error.')' : '').' — kliknij „Wyślij kod” jeszcze raz.');
        }
        if (! self::isAccountPage($this->get(self::ACCOUNT_HOME))) {
            throw new RuntimeException('Logowanie do '.self::HOST.': sklep nie potwierdził zalogowania po kodzie.');
        }
        $this->deviceCookies = self::newLongLivedCookies($before, $this->jar);
        $this->loggedIn = true;

        return $this->session();
    }

    /**
     * Wyroby z mapy strony (podmapa „Product-pl”): kod produktu bazowego → adres strony (z ?locale=pl, jak w mapie).
     * Podmapy brak albo jest pusta = błąd — niepełna lista nie może udawać całej oferty.
     *
     * @return array<string, string>
     */
    public function sitemapProducts(): array
    {
        try {
            $index = self::bodyOf($this->send(static fn (PendingRequest $http): Response => $http->get(self::SITEMAP)));
            $child = null;
            foreach (self::locations($index) as $loc) {
                if (preg_match('#^https://pl\.msasafety\.com/medias/Product-pl-[^/?]*\.xml(?:\?|$)#i', $loc) === 1) {
                    $child = $loc;
                    break;
                }
            }
            if ($child === null) {
                throw new RuntimeException('indeks mapy strony bez podmapy wyrobów „Product-pl”');
            }
            $xml = self::bodyOf($this->send(static fn (PendingRequest $http): Response => $http->get($child)));
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Nie udało się pobrać mapy strony '.self::HOST.': '.$e->getMessage(), 0, $e);
        }
        if (! str_contains($xml, '<urlset')) {
            throw new RuntimeException('Mapa wyrobów '.self::HOST.' ma nieznany format');
        }

        $out = [];
        foreach (self::locations($xml) as $url) {
            if (! self::isShopUrl($url)) {
                continue;
            }
            $code = self::productCodeOf($url);
            if ($code !== null) {
                $out[$code] ??= $url;
            }
        }

        return $out;
    }

    /** Kod produktu bazowego z adresu strony („…/p/000060002100001000?locale=pl”); null = to nie strona wyrobu. */
    public static function productCodeOf(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return preg_match('#/p/([A-Za-z0-9_.-]+)$#', $path, $m) === 1 ? $m[1] : null;
    }

    /**
     * Strona wyrobu dla konta. Strona bez cen konta (data-permission < 0, przekierowanie na logowanie albo prośbę o kod)
     * = jedno ponowne logowanie; nadal bez sesji = B2bFatalException (dalsze strony nie miałyby cen konta).
     */
    public function productPage(string $url): string
    {
        if (! self::isShopUrl($url)) {
            throw new RuntimeException('adres spoza '.self::HOST.': '.$url);
        }
        if (! $this->loggedIn) {
            $this->relogin();
        }

        $body = $this->get($url);
        if (! self::hasAccountPricing($body)) {
            $this->relogin();
            $body = $this->get($url);
            if (! self::hasAccountPricing($body)) {
                $this->loggedIn = false;

                throw new B2bFatalException(self::SESSION_LOST.' (strona wyrobu bez cen konta po ponownym logowaniu)');
            }
        }

        return $body;
    }

    /**
     * Ceny konta pozycji jednej strony, jak retrieveBatchProductPricing w skrypcie strony. Odpowiedź: obiekt
     * numer części → cena (value, formattedValue, formattedListValue, currencyIso…).
     *
     * @param  list<array{productCode: string, partNumber: string, quantityRequested: int|float}>  $forms
     * @return array<string, mixed>
     */
    public function prices(array $forms, string $unitOfMeasure, string $csrf, string $referer): array
    {
        if ($forms === []) {
            return [];
        }
        $request = json_encode(['unitOfMeasure' => $unitOfMeasure, 'priceForms' => $forms], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $body = self::bodyOf($this->send(static fn (PendingRequest $http): Response => $http
            ->asForm()
            ->accept('application/json, text/javascript, */*; q=0.01')
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Referer' => $referer])
            ->post(self::PRICES, [
                'unitOfMeasure' => $unitOfMeasure,
                'priceRequestJson' => $request,
                '_requestConfirmationToken' => $csrf,
            ])));
        $json = json_decode($body, true);
        if (! is_array($json)) {
            throw new RuntimeException('odpowiedź cen '.self::HOST.' nie jest JSON-em');
        }

        return $json;
    }

    /**
     * Pliki wyrobu z Bynder (literatura i aprobaty), jak loadWebDamAssets w skrypcie strony.
     *
     * @return list<array<string, mixed>>
     */
    public function documents(string $productCode): array
    {
        $body = self::bodyOf($this->send(static fn (PendingRequest $http): Response => $http
            ->accept('application/json, text/javascript, */*; q=0.01')
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get(self::DOCUMENTS.rawurlencode($productCode))));
        $json = json_decode($body, true);
        if (! is_array($json) || ! array_is_list($json)) {
            throw new RuntimeException('lista plików '.self::HOST.' dla '.$productCode.' nie jest listą JSON');
        }

        return array_values(array_filter($json, 'is_array'));
    }

    /**
     * Plik: adres /bynder/asset_download/{id} → podpisany adres na assetlibrary.msasafety.com → bajty. Zdjęcie:
     * adres assetlibrary.msasafety.com wprost. Strona HTML zamiast pliku = błąd.
     *
     * @return array{bytes: string, mime: string}
     */
    public function fileBytes(string $url): array
    {
        if (str_starts_with($url, self::ASSET_DOWNLOAD)) {
            $signed = trim(self::bodyOf($this->send(static fn (PendingRequest $http): Response => $http->accept('text/plain, */*')->get($url))));
            if (! self::isFileUrl($signed)) {
                throw new RuntimeException('sklep nie podał adresu pliku dla '.$url);
            }
            $url = $signed;
        } elseif (! self::isFileUrl($url)) {
            throw new RuntimeException('adres pliku spoza '.self::FILE_HOST.': '.$url);
        }

        $response = $this->send(static fn (PendingRequest $http): Response => $http->accept('*/*')->get($url));
        $bytes = self::bodyOf($response);
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        if ($bytes === '' || $mime === 'text/html' || self::looksLikeHtml($bytes)) {
            throw new RuntimeException('witryna nie wydała pliku '.$url.' (pusta treść albo strona HTML)');
        }
        if (strlen($bytes) > self::FILE_MAX_BYTES) {
            throw new RuntimeException('plik '.$url.' większy niż '.intdiv(self::FILE_MAX_BYTES, 1_000_000).' MB');
        }
        if (str_starts_with($bytes, '%PDF-')) {
            $mime = 'application/pdf';
        }

        return ['bytes' => $bytes, 'mime' => $mime];
    }

    /** https na pl.msasafety.com (bez danych logowania i nietypowego portu w adresie). */
    public static function isShopUrl(string $url): bool
    {
        return self::hasHost($url, self::HOST);
    }

    /** https na assetlibrary.msasafety.com (zdjęcia, podpisane adresy plików). */
    public static function isFileUrl(string $url): bool
    {
        return self::hasHost($url, self::FILE_HOST);
    }

    /** Strona konta po zalogowaniu: odnośnik wylogowania i ani formularz logowania, ani prośba o kod. */
    public static function isAccountPage(string $html): bool
    {
        return str_contains($html, self::LOGOUT_MARKER)
            && ! str_contains($html, self::OTP_MARKER)
            && ! str_contains($html, self::LOGIN_MARKER);
    }

    /** Strona wyrobu z cenami konta: #pricing-config-variables z data-permission ≥ 0 (gość: -1). */
    public static function hasAccountPricing(string $html): bool
    {
        if (! self::isAccountPage($html)) {
            return false;
        }
        if (preg_match('/<div\b[^>]*\bid="pricing-config-variables"[^>]*>/i', $html, $m) !== 1) {
            return false;
        }

        return preg_match('/\bdata-permission="(-?\d+)"/', $m[0], $p) === 1 && (int) $p[1] >= 0;
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
     * Formularz logowania: token ze strony /login, potem e-mail i hasło. Zwraca stronę po przekierowaniach (konto,
     * prośba o kod albo znowu formularz).
     */
    private function passwordLogin(): string
    {
        $username = trim($this->username);
        if ($username === '') {
            throw new RuntimeException('konto nie ma loginu (e-mail)');
        }
        $page = $this->get(self::LOGIN_PAGE);
        $token = self::hiddenValue($page, '_requestConfirmationToken');
        if ($token === null || $token === '') {
            throw new RuntimeException('strona logowania bez pola _requestConfirmationToken (zmiana witryny?)');
        }

        return self::bodyOf($this->send(fn (PendingRequest $http): Response => $http->asForm()->withHeaders(['Referer' => self::LOGIN_PAGE])->post(self::LOGIN_POST, [
            'j_username' => $username,
            'j_password' => $this->password,
            '_requestConfirmationToken' => $token,
        ])));
    }

    private function hasValidDeviceCookie(): bool
    {
        if ($this->deviceCookies === []) {
            return false;
        }
        foreach ($this->jar->toArray() as $cookie) {
            $expires = $cookie['Expires'] ?? null;
            if (in_array($cookie['Name'] ?? null, $this->deviceCookies, true)
                && is_numeric($expires) && (int) $expires > time() + self::DEVICE_COOKIE_MARGIN_SECONDS) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pola ukryte formularza kodu (#otpForm): e-mail (zamaskowany, jak wysyła go przeglądarka) i token.
     *
     * @return array{email: string, token: string}
     */
    private static function otpForm(string $html): array
    {
        $form = preg_match('#<form\b[^>]*\bid="otpForm"[^>]*>(.*?)</form>#is', $html, $m) === 1 ? $m[1] : '';

        return [
            'email' => (string) self::hiddenValue($form, 'email'),
            'token' => (string) self::hiddenValue($form, '_requestConfirmationToken'),
        ];
    }

    /** Wartość pola formularza o danej nazwie (pierwsze value w znaczniku); null = brak pola. */
    private static function hiddenValue(string $html, string $name): ?string
    {
        if (preg_match('/<input\b[^>]*\bname="'.preg_quote($name, '/').'"[^>]*>/i', $html, $m) !== 1) {
            return null;
        }

        return preg_match('/\bvalue="([^"]*)"/i', $m[0], $v) === 1
            ? html_entity_decode($v[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : '';
    }

    /**
     * Nazwy ciasteczek w słoiku (do porównania przed i po wpisaniu kodu).
     *
     * @return array<string, true>
     */
    private static function cookieNames(CookieJar $jar): array
    {
        $out = [];
        foreach ($jar->toArray() as $cookie) {
            $out[(string) ($cookie['Name'] ?? '')] = true;
        }

        return $out;
    }

    /**
     * Ciasteczka, których przed wpisaniem kodu nie było, ważne dłużej niż DEVICE_COOKIE_MIN_LIFETIME_SECONDS — tak
     * sklep pamięta urządzenie („Pomiń weryfikację… przez 30 dni”). Tylko nowe nazwy: „wcid” (rok) sklep odnawia przy
     * każdej odpowiedzi, a o urządzeniu nic nie mówi.
     *
     * @param  array<string, true>  $before
     * @return list<string>
     */
    private static function newLongLivedCookies(array $before, CookieJar $jar): array
    {
        $out = [];
        foreach ($jar->toArray() as $cookie) {
            $name = (string) ($cookie['Name'] ?? '');
            $expires = $cookie['Expires'] ?? null;
            if ($name === '' || isset($before[$name])) {
                continue;
            }
            if (is_numeric($expires) && (int) $expires > time() + self::DEVICE_COOKIE_MIN_LIFETIME_SECONDS && ! in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    private static function hasHost(string $url, string $host): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        return strtolower($parts['host'] ?? '') === $host;
    }

    /**
     * @param  mixed  $cookies  ciasteczka z sesji konta (CookieJar::toArray)
     */
    private static function jarFrom(mixed $cookies): CookieJar
    {
        if (! is_array($cookies)) {
            return new CookieJar;
        }

        try {
            return new CookieJar(false, array_values(array_filter($cookies, static fn (mixed $c): bool => is_array($c) && is_string($c['Name'] ?? null))));
        } catch (\InvalidArgumentException) {
            // uszkodzona sesja = brak sesji (przebieg poprosi o logowanie kodem)
            return new CookieJar;
        }
    }

    private static function looksLikeHtml(string $bytes): bool
    {
        $head = strtolower(ltrim(substr($bytes, 0, 512)));

        return str_starts_with($head, '<!doctype html') || str_starts_with($head, '<html');
    }

    /**
     * @return list<string>
     */
    private static function locations(string $xml): array
    {
        preg_match_all('#<loc>\s*([^<]+?)\s*</loc>#i', $xml, $m);

        return array_map(static fn (string $loc): string => html_entity_decode($loc, ENT_QUOTES | ENT_XML1, 'UTF-8'), $m[1]);
    }

    private function get(string $url): string
    {
        return self::bodyOf($this->send(static fn (PendingRequest $http): Response => $http->get($url)));
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
                    'User-Agent' => self::USER_AGENT,
                    'Accept-Language' => 'pl-PL,pl;q=0.9',
                ])->withOptions([
                    'cookies' => $this->jar,
                    'allow_redirects' => ['max' => 6],
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
