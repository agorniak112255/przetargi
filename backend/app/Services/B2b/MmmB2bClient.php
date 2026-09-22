<?php

declare(strict_types=1);

namespace App\Services\B2b;

use Closure;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * order.3m.com — sklep 3M Polska dla klientów kontraktowych (SAP Commerce, wyszukiwarka searchapi.3m.com). Adresy,
 * pola i kroki logowania odczytane z konta klienta 22.09.2026.
 *
 * Logowanie: SAML do Azure AD B2C (ciam.iamext.3m.com, polityka B2C_1A_3MSAMLB) — e-mail, hasło, a od kwietnia 2026
 * kod weryfikacyjny wysyłany e-mailem. Serwer kodu nie zobaczy, więc logowanie ma dwa kroki z panelu
 * (startCodeLogin() / finishCodeLogin(), B2bCodeLoginSite), a przebieg korzysta z sesji (ciasteczek) zapisanej na
 * koncie. login() NIGDY nie wysyła e-maila ani hasła: sprawdza sesję, próbuje cichego SSO B2C z jej ciasteczkami,
 * a gdy B2C pyta o dane — B2bFatalException z prośbą o „Zaloguj kodem”.
 *
 * Wyszukiwarka i karta wyrobu (searchapi.3m.com) biorą token currentUserJWT ze strony katalogu (ważny ok. 1 h —
 * odświeżany z tej samej strony). Ceny konta: /bcomv2/…/productPrice z ciasteczkami sesji, za jednostkę bazową
 * (materialUnits). Pliki (zdjęcia, PDF-y) są publiczne na multimedia.3m.com.
 *
 * Każde zapytanie z nagłówkiem User-Agent przeglądarki (Akamai odrzuca klientów bez niego), jeden CookieJar dla
 * sklepu i B2C.
 */
final class MmmB2bClient
{
    public const HOST = 'order.3m.com';

    public const BASE = 'https://order.3m.com';

    public const CIAM_HOST = 'ciam.iamext.3m.com';

    public const FILE_HOST = 'multimedia.3m.com';

    /** Kategoria „Środki ochrony indywidualnej”. */
    public const CATEGORY = 'GPH10008';

    public const CATALOG_URL = self::BASE.'/store/escatalog/'.self::CATEGORY;

    public const LIST_PAGE_SIZE = 100;

    public const CODE_REQUIRED = '3M wymaga kodu z e-maila — w Cennikach B2B przy koncie 3M kliknij „Zaloguj kodem”, a pobieranie ruszy po zalogowaniu.';

    private const LOGIN_URL = self::BASE.'/store/user/login';

    private const SAML_LOGIN_PATH = '/mmmsinglesignon/saml2/authenticate/login';

    private const SEARCH_URL = 'https://searchapi.3m.com/search/bcom/v1/search?client=bcom';

    private const PDP_URL = 'https://searchapi.3m.com/search/bcom/v1/pdp?client=bcom';

    private const PRICE_URL = self::BASE.'/bcomv2/v2/bComPolandSite/users/%s/productdetails/productPrice';

    private const PDP_SECTIONS = ['media', 'details', 'product_references', 'specifications_regulatory', 'specifications_packaging_info', 'specifications_dimension_classification', 'product_uoms'];

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    private const SESSION_LOST = 'Utracono sesję konta order.3m.com — ceny konta niedostępne';

    /** Token odświeżany, gdy do wygaśnięcia zostało mniej (s). */
    private const TOKEN_MARGIN_SECONDS = 300;

    private const TIMEOUT_SECONDS = 30;

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503, gdy witryna nie podała Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    /** Górna granica Retry-After — dłuższa blokada = błąd zapytania, nie wielogodzinne czekanie. */
    private const MAX_RETRY_AFTER_MS = 900_000;

    private CookieJar $jar;

    private bool $loggedIn = false;

    private ?string $jwt = null;

    private int $jwtExpires = 0;

    private ?string $userId = null;

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
        private readonly int $delayMs = 150,
        ?Closure $sleep = null,
    ) {
        $this->jar = self::jarFrom($session['cookies'] ?? null);
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /**
     * Sesja z konta żywa → zalogowany. Inaczej ciche SSO B2C z ciasteczkami sesji (kroki 1–3); gdy B2C od razu
     * odda SAMLResponse — zalogowany (sesja odnowiona). Gdy B2C pyta o e-mail albo sesji nie ma wcale —
     * B2bFatalException z prośbą o logowanie kodem. E-mail i hasło wysyła wyłącznie startCodeLogin().
     */
    public function login(): void
    {
        $this->loggedIn = false;
        $this->jwt = null;
        if ($this->jar->count() === 0) {
            throw new B2bFatalException(self::CODE_REQUIRED);
        }
        if ($this->readCatalogToken()) {
            $this->loggedIn = true;

            return;
        }

        try {
            $page = $this->openB2c();
            $saml = self::form($page, 'SAMLResponse');
            if ($saml === null) {
                throw new B2bFatalException(self::CODE_REQUIRED);
            }
            $this->postSamlResponse($saml);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new B2bFatalException('Logowanie do '.self::HOST.' nieudane ('.$e->getMessage().'). '.self::CODE_REQUIRED, 0, $e);
        }
        if (! $this->readCatalogToken()) {
            throw new B2bFatalException('Sklep '.self::HOST.' nie przyjął sesji po SSO. '.self::CODE_REQUIRED);
        }
        $this->loggedIn = true;
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    /**
     * Sesja do zapisania na koncie: ciasteczka sklepu i B2C.
     *
     * @return array{cookies: list<array<string, mixed>>, saved_at: string}
     */
    public function session(): array
    {
        return ['cookies' => array_values($this->jar->toArray()), 'saved_at' => date(DATE_ATOM)];
    }

    /**
     * Kroki 1–7 logowania świeżą sesją: e-mail, hasło i zlecenie wysyłki kodu weryfikacyjnego. Stan bez hasła.
     * Gdy B2C nie poprosi o kod (MFA niewymagane), logowanie kończy się od razu, a stan niesie gotową sesję.
     *
     * @return array{state: array<string, mixed>, message: string}
     */
    public function startCodeLogin(): array
    {
        $this->jar = self::newJar();
        $this->loggedIn = false;
        $this->jwt = null;

        try {
            $page = $this->openB2c();
            if (($saml = self::form($page, 'SAMLResponse')) !== null) {
                return $this->finishedWithoutCode($saml);
            }
            $settings = self::settings($page);
            if (! self::hasInput($page, 'email')) {
                throw new RuntimeException('strona logowania 3M nie pyta o e-mail');
            }
            $this->selfAsserted($settings, ['email' => $this->username, 'request_type' => 'RESPONSE'], '3M odrzucił adres e-mail');

            $page = $this->confirmed($settings);
            $settings = self::settings($page);
            if (! self::hasInput($page, 'password')) {
                throw new RuntimeException('3M nie poprosił o hasło po adresie e-mail');
            }
            $this->selfAsserted($settings, ['password' => $this->password, 'request_type' => 'RESPONSE'], '3M odrzucił hasło');

            $page = $this->confirmed($settings);
            if (($saml = self::form($page, 'SAMLResponse')) !== null) {
                return $this->finishedWithoutCode($saml);
            }
            $settings = self::settings($page);
            if (! self::hasInput($page, 'readOnlyEmail')) {
                throw new RuntimeException('po haśle 3M nie pokazał ani prośby o kod, ani zalogowania');
            }
            $email = self::inputValue($page, 'readOnlyEmail') ?? $this->username;
            $this->selfAsserted(
                $settings,
                ['request_type' => 'VERIFICATION_REQUEST', 'claim_id' => 'readOnlyEmail', 'claim_value' => $email],
                '3M nie wysłał kodu',
                requireResultZero: true,
            );
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        return [
            'state' => [
                'cookies' => array_values($this->jar->toArray()),
                'csrf' => $settings['csrf'],
                'tx' => $settings['tx'],
                'tenant' => $settings['tenant'],
                'policy' => $settings['policy'],
                'api' => $settings['api'],
                'claim_value' => $email,
                'started_at' => date(DATE_ATOM),
            ],
            'message' => 'Kod wysłany na '.$email.'. Wpisz go poniżej (ważny kilka minut).',
        ];
    }

    /**
     * Kod z e-maila → sprawdzenie kodu, zatwierdzenie ekranu, SAMLResponse do sklepu i potwierdzenie na stronie
     * katalogu. Zwraca sesję do zapisania na koncie.
     *
     * @param  array<string, mixed>  $state  z startCodeLogin()
     * @return array{cookies: list<array<string, mixed>>, saved_at: string}
     */
    public function finishCodeLogin(array $state, string $code): array
    {
        if (is_array($state['session'] ?? null)) {
            // 3M zalogował bez kodu już w startCodeLogin()
            $this->jar = self::jarFrom($state['session']['cookies'] ?? null);

            return $this->session();
        }
        $code = trim($code);
        if ($code === '' || ! ctype_digit($code)) {
            throw new RuntimeException('Kod weryfikacyjny to same cyfry z e-maila od 3M.');
        }
        $settings = [];
        foreach (['csrf', 'tx', 'tenant', 'policy', 'api', 'claim_value'] as $key) {
            if (! is_string($state[$key] ?? null) || $state[$key] === '') {
                throw new RuntimeException('Stan logowania 3M niekompletny — kliknij „Wyślij kod” jeszcze raz.');
            }
            $settings[$key] = $state[$key];
        }
        if (! self::isAllowedTenant($settings['tenant'])) {
            throw new RuntimeException('Stan logowania 3M wskazuje nieznaną politykę B2C — kliknij „Wyślij kod” jeszcze raz.');
        }
        $this->jar = self::jarFrom($state['cookies'] ?? null);
        $this->loggedIn = false;
        $this->jwt = null;
        $email = $settings['claim_value'];

        try {
            $this->selfAsserted(
                $settings,
                ['request_type' => 'VALIDATION_REQUEST', 'claim_id' => 'readOnlyEmail', 'claim_value' => $email, 'user_input' => $code],
                'Kod nieprawidłowy albo wygasł',
                requireResultZero: true,
            );
            $this->selfAsserted(
                $settings,
                ['readOnlyEmail' => $email, 'readOnlyEmail_ver_input' => $code, 'request_type' => 'RESPONSE'],
                '3M nie przyjął kodu',
            );
            $page = $this->confirmed($settings);
            $saml = self::form($page, 'SAMLResponse');
            if ($saml === null) {
                throw new RuntimeException('po kodzie 3M nie zakończył logowania');
            }
            $this->postSamlResponse($saml);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.': '.$e->getMessage(), 0, $e);
        }
        if (! $this->readCatalogToken()) {
            throw new RuntimeException('Logowanie do '.self::HOST.': sklep nie potwierdził zalogowania po kodzie.');
        }
        $this->loggedIn = true;

        return $this->session();
    }

    /**
     * Strona listy wyrobów kategorii ŚOI (aktywne). Odpowiedź: items, total.
     *
     * @return array<string, mixed>
     */
    public function search(int $start, int $size = self::LIST_PAGE_SIZE): array
    {
        $body = [
            'start' => $start,
            'size' => min($size, self::LIST_PAGE_SIZE),
            'includeHighlights' => false,
            'sticky_filters' => [
                'categories_path' => [self::CATEGORY],
                'product_status' => ['values' => ['Aktywny']],
            ],
        ];

        return $this->searchApi(self::SEARCH_URL, $body, 'listy wyrobów');
    }

    /**
     * Karta wyrobu z wyszukiwarki 3M (opis, parametry, zdjęcia, dokumenty).
     *
     * @return array<string, mixed>
     */
    public function pdp(string $mmmId): array
    {
        $body = ['mmm_id' => $mmmId, 'section' => self::PDP_SECTIONS, 'cBuy' => true, 'multifetch' => false];

        return $this->searchApi(self::PDP_URL, $body, 'karty wyrobu '.$mmmId);
    }

    /**
     * Ceny konta za jednostkę bazową każdej pozycji (materialUnits w tej samej kolejności co materialIDs).
     * Sesja odrzucona (401, strona zamiast JSON) → jedno login(); dalej — B2bFatalException.
     *
     * @param  list<string>  $ids
     * @param  list<string>  $units
     * @return array<string, array<string, mixed>> numer magazynowy → „value” odpowiedzi
     */
    public function prices(array $ids, array $units): array
    {
        if (count($ids) !== count($units)) {
            throw new RuntimeException('ceny 3M: liczba jednostek różna od liczby pozycji');
        }
        if ($ids === []) {
            return [];
        }
        $this->ensureToken();

        $query = 'materialIDs='.implode(',', array_map('rawurlencode', $ids))
            .'&materialUnits='.implode(',', array_map('rawurlencode', $units));
        $fetch = function () use ($query): ?array {
            $url = sprintf(self::PRICE_URL, rawurlencode((string) $this->userId)).'?'.$query;
            $response = $this->send(
                static fn (PendingRequest $http): Response => $http->acceptJson()->get($url),
                [401, 403],
            );
            $json = $response->successful() ? json_decode((string) $response->body(), true) : null;

            return is_array($json) && is_array($json['products'] ?? null) ? $json : null;
        };

        $json = $fetch();
        if ($json === null) {
            $this->relogin();
            $json = $fetch();
            if ($json === null) {
                throw new B2bFatalException(self::SESSION_LOST);
            }
        }

        $prices = [];
        foreach ($json['products'] as $row) {
            if (is_array($row) && is_scalar($row['key'] ?? null) && is_array($row['value'] ?? null)) {
                $prices[(string) $row['key']] = $row['value'];
            }
        }

        return $prices;
    }

    /**
     * Zdjęcie albo dokument (PDF) z multimedia.3m.com (publiczne, bez sesji).
     *
     * @return array{bytes: string, mime: string}
     */
    public function fileBytes(string $url): array
    {
        if (! self::isFileUrl($url)) {
            throw new RuntimeException('plik spoza '.self::FILE_HOST.': '.$url);
        }
        $response = $this->send(static fn (PendingRequest $http): Response => $http->get($url));
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        return ['bytes' => (string) $response->body(), 'mime' => $mime];
    }

    /** https na multimedia.3m.com, bez danych logowania i nietypowego portu. */
    public static function isFileUrl(string $url): bool
    {
        return self::isHttpsOn($url, self::FILE_HOST);
    }

    public static function productUrl(string $mmmId): string
    {
        return self::BASE.'/store/escatalog/~/d/'.rawurlencode($mmmId);
    }

    /**
     * Ukryty formularz auto-POST z polem $field (SAMLRequest / SAMLResponse): adres i pola hidden.
     *
     * @return array{action: string, fields: array<string, string>}|null
     */
    public static function form(string $html, string $field): ?array
    {
        $tag = '(?:[^>"\']|"[^"]*"|\'[^\']*\')*';
        if (preg_match_all('#<form\b('.$tag.')>(.*?)</form>#is', $html, $forms, PREG_SET_ORDER) === false) {
            return null;
        }
        foreach ($forms as $form) {
            $fields = [];
            preg_match_all('#<input\b('.$tag.')>#i', $form[2], $inputs, PREG_SET_ORDER);
            foreach ($inputs as $input) {
                $attrs = self::attributes($input[1]);
                if (($attrs['name'] ?? '') !== '' && strtolower($attrs['type'] ?? 'text') === 'hidden') {
                    $fields[$attrs['name']] = $attrs['value'] ?? '';
                }
            }
            if (($fields[$field] ?? '') === '') {
                continue;
            }

            return ['action' => trim(self::attributes($form[1])['action'] ?? ''), 'fields' => $fields];
        }

        return null;
    }

    /**
     * Ustawienia strony B2C z „var SETTINGS = {...};”: csrf, transId, api, tenant i polityka.
     *
     * @return array{csrf: string, tx: string, api: string, tenant: string, policy: string}
     */
    public static function settings(string $html): array
    {
        if (preg_match('/var\s+SETTINGS\s*=/', $html) !== 1) {
            throw new RuntimeException('strona B2C bez ustawień (SETTINGS)');
        }
        $json = self::scriptJson($html, 'SETTINGS');
        if ($json === null) {
            throw new RuntimeException('nieczytelne ustawienia strony B2C');
        }
        $settings = [
            'csrf' => $json['csrf'] ?? null,
            'tx' => $json['transId'] ?? null,
            'api' => $json['api'] ?? null,
            'tenant' => $json['hosts']['tenant'] ?? null,
            'policy' => $json['hosts']['policy'] ?? null,
        ];
        foreach ($settings as $key => $value) {
            if (! is_string($value) || $value === '') {
                throw new RuntimeException('ustawienia strony B2C bez pola '.$key);
            }
        }
        if (! self::isAllowedTenant($settings['tenant']) || preg_match('/^[A-Za-z0-9_]+$/', $settings['api'].$settings['policy']) !== 1) {
            throw new RuntimeException('nieoczekiwana polityka B2C: '.$settings['tenant']);
        }

        /** @var array{csrf: string, tx: string, api: string, tenant: string, policy: string} $settings */
        return $settings;
    }

    /**
     * Token wyszukiwarki ze strony katalogu; null = strona gościa (bez tokenu albo bez odnośnika wylogowania).
     */
    public static function catalogToken(string $html): ?string
    {
        if (! str_contains($html, '/store/logout')) {
            return null;
        }

        return preg_match('/currentUserJWT\s*=\s*[\'"]([A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]+\.[A-Za-z0-9_\-]*)[\'"]/', $html, $m) === 1 ? $m[1] : null;
    }

    /**
     * Treść (payload) tokenu JWT; podpisu nie sprawdzamy — token służy tylko do przekazania wyszukiwarce.
     *
     * @return array<string, mixed>
     */
    public static function jwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);
        $json = isset($parts[1]) ? base64_decode(strtr($parts[1], '-_', '+/'), true) : false;
        $payload = is_string($json) ? json_decode($json, true) : null;

        return is_array($payload) ? $payload : [];
    }

    /**
     * Kroki 1–3: strona logowania sklepu → SAMLRequest → B2C. Wynik: strona B2C (SETTINGS) albo, przy żywej
     * sesji SSO, formularz z SAMLResponse.
     */
    private function openB2c(): string
    {
        $page = (string) $this->send(fn (PendingRequest $http): Response => $this->browser($http)->get(self::LOGIN_URL))->body();
        $url = self::BASE.self::SAML_LOGIN_PATH;
        if (($form = self::formWithAction($page, self::SAML_LOGIN_PATH)) !== null) {
            $url = $form;
        }
        $page = (string) $this->send(fn (PendingRequest $http): Response => $this->emptyPost($http, $url))->body();

        $request = self::form($page, 'SAMLRequest');
        if ($request === null) {
            throw new RuntimeException('sklep nie przekazał żądania logowania (SAMLRequest)');
        }
        $action = html_entity_decode($request['action'], ENT_QUOTES | ENT_HTML5);
        if (! self::isHttpsOn($action, self::CIAM_HOST)) {
            throw new RuntimeException('żądanie logowania kieruje poza '.self::CIAM_HOST.': '.$action);
        }

        return (string) $this->send(fn (PendingRequest $http): Response => $this->browser($http)
            ->asForm()
            ->post($action, $request['fields']))->body();
    }

    /**
     * Krok 8: SAMLResponse do sklepu (tylko order.3m.com).
     *
     * @param  array{action: string, fields: array<string, string>}  $saml
     */
    private function postSamlResponse(array $saml): void
    {
        $action = html_entity_decode($saml['action'], ENT_QUOTES | ENT_HTML5);
        if (! self::isHttpsOn($action, self::HOST)) {
            throw new RuntimeException('odpowiedź logowania kieruje poza '.self::HOST.': '.$action);
        }
        $this->send(fn (PendingRequest $http): Response => $this->browser($http)
            ->withHeaders(['Origin' => 'https://'.self::CIAM_HOST, 'Referer' => 'https://'.self::CIAM_HOST.'/'])
            ->asForm()
            ->post($action, $saml['fields']));
    }

    /**
     * @param  array{action: string, fields: array<string, string>}  $saml
     * @return array{state: array<string, mixed>, message: string}
     */
    private function finishedWithoutCode(array $saml): array
    {
        $this->postSamlResponse($saml);
        if (! $this->readCatalogToken()) {
            throw new RuntimeException('sklep nie potwierdził zalogowania');
        }
        $this->loggedIn = true;

        return [
            'state' => ['session' => $this->session(), 'started_at' => date(DATE_ATOM)],
            'message' => '3M zalogował bez kodu weryfikacyjnego — wpisz dowolne cyfry (np. 0000) i zatwierdź, żeby zapisać sesję.',
        ];
    }

    /**
     * POST na SelfAsserted B2C (formularz jak z przeglądarki). Odpowiedź JSON {"status":"200"}; inny status (albo
     * result ≠ 0 przy kodzie) → RuntimeException z komunikatem B2C.
     *
     * @param  array{csrf: string, tx: string, tenant: string, policy: string}  $settings
     * @param  array<string, string>  $fields
     */
    private function selfAsserted(array $settings, array $fields, string $failure, bool $requireResultZero = false): void
    {
        $url = 'https://'.self::CIAM_HOST.$settings['tenant'].'/SelfAsserted?tx='.rawurlencode($settings['tx']).'&p='.rawurlencode($settings['policy']);
        $response = $this->send(fn (PendingRequest $http): Response => $this->browser($http)
            ->withHeaders([
                'X-CSRF-TOKEN' => $settings['csrf'],
                'X-Requested-With' => 'XMLHttpRequest',
                'Accept' => 'application/json, text/javascript, */*; q=0.01',
                'Origin' => 'https://'.self::CIAM_HOST,
            ])
            ->asForm()
            ->post($url, $fields), [400]);
        $json = json_decode((string) $response->body(), true);
        $status = is_array($json) && is_scalar($json['status'] ?? null) ? (string) $json['status'] : '';
        $message = is_array($json) && is_string($json['message'] ?? null) ? trim($json['message']) : '';
        $resultOk = ! $requireResultZero || (is_array($json) && array_key_exists('result', $json) && (string) $json['result'] === '0');
        if ($status !== '200' || ! $resultOk || ($requireResultZero && $message !== '')) {
            throw new RuntimeException($failure.($message !== '' ? ': '.$message : ' (odpowiedź '.$status.')'));
        }
    }

    /**
     * GET …/api/{api}/confirmed — następny ekran B2C albo formularz SAMLResponse.
     *
     * @param  array{csrf: string, tx: string, api: string, tenant: string, policy: string}  $settings
     */
    private function confirmed(array $settings): string
    {
        $url = 'https://'.self::CIAM_HOST.$settings['tenant'].'/api/'.$settings['api'].'/confirmed'
            .'?rememberMe=false&csrf_token='.rawurlencode($settings['csrf'])
            .'&tx='.rawurlencode($settings['tx']).'&p='.rawurlencode($settings['policy']);

        return (string) $this->send(fn (PendingRequest $http): Response => $this->browser($http)->get($url))->body();
    }

    /** Strona katalogu → token wyszukiwarki, jego ważność i identyfikator użytkownika; false = gość. */
    private function readCatalogToken(): bool
    {
        $html = (string) $this->send(fn (PendingRequest $http): Response => $this->browser($http)->get(self::CATALOG_URL))->body();
        $jwt = self::catalogToken($html);
        if ($jwt === null) {
            return false;
        }
        $payload = self::jwtPayload($jwt);
        $entitlements = $payload['entitlementsPayload'] ?? null;
        if (is_string($entitlements)) {
            $entitlements = json_decode($entitlements, true);
        }
        $userId = is_array($entitlements) && is_scalar($entitlements['userId'] ?? null) ? trim((string) $entitlements['userId']) : '';
        if ($userId === '') {
            throw new RuntimeException('token sklepu 3M bez identyfikatora użytkownika');
        }
        $this->jwt = $jwt;
        $this->jwtExpires = is_numeric($payload['exp'] ?? null) ? (int) $payload['exp'] : time() + 600;
        $this->userId = $userId;

        return true;
    }

    /** Token ważny jeszcze co najmniej kilka minut; inaczej odświeżenie ze strony katalogu (albo ponowne login()). */
    private function ensureToken(bool $force = false): void
    {
        if (! $this->loggedIn) {
            $this->login();
        }
        if (! $force && $this->jwt !== null && $this->jwtExpires - time() > self::TOKEN_MARGIN_SECONDS) {
            return;
        }
        if ($this->readCatalogToken()) {
            return;
        }
        $this->relogin();
    }

    /** Sesja utracona w trakcie przebiegu — jedno login(); gdy wymaga kodu, login() zgłasza B2bFatalException. */
    private function relogin(): void
    {
        try {
            $this->login();
        } catch (B2bFatalException $e) {
            throw new B2bFatalException(self::SESSION_LOST.': '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Zapytanie do wyszukiwarki z tokenem; 401/403/500 → odświeżenie tokenu i jedno ponowienie.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function searchApi(string $url, array $body, string $label): array
    {
        $this->ensureToken();
        $call = fn (): Response => $this->send(fn (PendingRequest $http): Response => $this->browser($http)
            ->withToken((string) $this->jwt)
            ->acceptJson()
            ->asJson()
            ->post($url, $body), [401, 403, 500]);

        $response = $call();
        if (in_array($response->status(), [401, 403, 500], true)) {
            $this->ensureToken(force: true);
            $response = $call();
            if (! $response->successful()) {
                throw new RuntimeException('wyszukiwarka 3M odrzuciła zapytanie '.$label.' (HTTP '.$response->status().')');
            }
        }
        $json = json_decode((string) $response->body(), true);
        if (! is_array($json)) {
            throw new RuntimeException('wyszukiwarka 3M: nieczytelna odpowiedź '.$label);
        }

        return $json;
    }

    private function browser(PendingRequest $http): PendingRequest
    {
        return $http->withUserAgent(self::USER_AGENT)->withHeaders(['Accept-Language' => 'pl-PL,pl;q=0.9']);
    }

    /** POST bez ciała (Akamai odrzuca POST bez Content-Length — 411). */
    private function emptyPost(PendingRequest $http, string $url): Response
    {
        return $this->browser($http)
            ->withHeaders(['Content-Length' => '0'])
            ->withBody('', 'application/x-www-form-urlencoded')
            ->post($url);
    }

    /** Adres formularza, którego action kończy się ścieżką $path, jako pełny adres sklepu; null = brak. */
    private static function formWithAction(string $html, string $path): ?string
    {
        preg_match_all('#<form\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#i', $html, $forms, PREG_SET_ORDER);
        foreach ($forms as $form) {
            $action = trim(self::attributes($form[1])['action'] ?? '');
            if ($action === $path) {
                return self::BASE.$path;
            }
            if (self::isHttpsOn($action, self::HOST) && parse_url($action, PHP_URL_PATH) === $path) {
                return $action;
            }
        }

        return null;
    }

    /**
     * Pole ekranu B2C. Strona z serwera nie ma pól w HTML — opisuje je JSON „var SA_FIELDS = {"AttributeFields":
     * [{"ID":"email","PRE":""}, …]}”, a formularz składa skrypt w przeglądarce (sprawdzone na żywo 22.09.2026).
     * Znacznik <input> sprawdzamy tylko jako zapas.
     */
    private static function hasInput(string $html, string $id): bool
    {
        if (self::saField($html, $id) !== null) {
            return true;
        }

        return preg_match('#<input\b[^>]*\b(?:id|name)\s*=\s*["\']'.preg_quote($id, '#').'["\']#i', $html) === 1;
    }

    private static function inputValue(string $html, string $id): ?string
    {
        $field = self::saField($html, $id);
        if ($field !== null) {
            $value = is_scalar($field['PRE'] ?? null) ? trim((string) $field['PRE']) : '';

            return $value !== '' ? $value : null;
        }
        preg_match_all('#<input\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#i', $html, $inputs, PREG_SET_ORDER);
        foreach ($inputs as $input) {
            $attrs = self::attributes($input[1]);
            if (($attrs['id'] ?? '') === $id || ($attrs['name'] ?? '') === $id) {
                $value = trim($attrs['value'] ?? '');

                return $value !== '' ? $value : null;
            }
        }

        return null;
    }

    /**
     * Opis pola z SA_FIELDS.AttributeFields o danym ID; null = ekran go nie ma (albo strona bez SA_FIELDS).
     *
     * @return array<string, mixed>|null
     */
    private static function saField(string $html, string $id): ?array
    {
        $fields = self::scriptJson($html, 'SA_FIELDS')['AttributeFields'] ?? null;
        if (! is_array($fields)) {
            return null;
        }
        foreach ($fields as $field) {
            if (is_array($field) && ($field['ID'] ?? null) === $id) {
                return $field;
            }
        }

        return null;
    }

    /**
     * JSON z „var NAZWA = {...};” strony B2C — w jednej linii, zakończony „};”, za nim w tej samej linii bywa dalszy
     * skrypt; null = brak zmiennej albo nieczytelny JSON.
     *
     * @return array<string, mixed>|null
     */
    private static function scriptJson(string $html, string $name): ?array
    {
        if (preg_match('/var\s+'.preg_quote($name, '/').'\s*=\s*(\{[^\r\n]*)/', $html, $m) !== 1) {
            return null;
        }
        $line = $m[1];
        for ($end = strpos($line, '};'); $end !== false; $end = strpos($line, '};', $end + 1)) {
            $json = json_decode(substr($line, 0, $end + 1), true);
            if (is_array($json)) {
                return $json;
            }
        }

        return null;
    }

    /** Polityka B2C sklepu 3M („/mmmciam.onmicrosoft.com/B2C_1A_…”) — nic innego nie trafia do adresu. */
    private static function isAllowedTenant(string $tenant): bool
    {
        return preg_match('#^/mmmciam\.onmicrosoft\.com/[A-Za-z0-9_]+$#', $tenant) === 1;
    }

    private static function isHttpsOn(string $url, string $host): bool
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
     * @return array<string, string>
     */
    private static function attributes(string $tag): array
    {
        preg_match_all('#([^\s"\'<>/=]+)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+)))?#', $tag, $matches, PREG_SET_ORDER);
        $attrs = [];
        foreach ($matches as $m) {
            $name = strtolower($m[1]);
            if (! isset($attrs[$name])) {
                $attrs[$name] = html_entity_decode(($m[2] ?? '').($m[3] ?? '').($m[4] ?? ''), ENT_QUOTES | ENT_HTML5);
            }
        }

        return $attrs;
    }

    private static function jarFrom(mixed $cookies): CookieJar
    {
        if (! is_array($cookies)) {
            return self::newJar();
        }

        try {
            return self::newJar(array_values(array_filter($cookies, static fn (mixed $c): bool => is_array($c) && is_string($c['Name'] ?? null))));
        } catch (\InvalidArgumentException) {
            // uszkodzona sesja = brak sesji (przebieg poprosi o logowanie kodem)
            return self::newJar();
        }
    }

    /**
     * CookieJar przyjmujący dwukropek w nazwie ciasteczka. B2C nazywa ciasteczko SSO „x-ms-cpim-sso:{tenant}_0” —
     * przeglądarki je przyjmują, a Guzzle (ściśle wg RFC 6265) po cichu odrzuca, przez co ciche SSO nigdy by nie
     * zadziałało. Pozostałe zasady (domena, wartość, ścieżka) Guzzle sprawdza jak zwykle.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private static function newJar(array $rows = []): CookieJar
    {
        return new class(false, $rows) extends CookieJar
        {
            public function setCookie(SetCookie $cookie): bool
            {
                if (! str_contains((string) $cookie->getName(), ':')) {
                    return parent::setCookie($cookie);
                }

                return parent::setCookie(new class($cookie->toArray()) extends SetCookie
                {
                    public function validate()
                    {
                        $name = (string) $this->getName();
                        $this->setName(str_replace(':', '-', $name));
                        $result = parent::validate();
                        $this->setName($name);

                        return $result;
                    }
                });
            }
        };
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @param  list<int>  $acceptStatuses  kody poza 2xx, które są odpowiedzią, nie błędem
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
                $response = $call(Http::timeout(self::TIMEOUT_SECONDS)->withOptions([
                    'cookies' => $this->jar,
                    'allow_redirects' => ['max' => 10],
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
