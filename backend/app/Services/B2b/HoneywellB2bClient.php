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
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

/**
 * Sklep Honeywell myAutomation automation.honeywell.com (AEM + SAP Commerce „/shop”). Nieoficjalne: adresy i pola
 * odczytane z zalogowanego konta 30.09.2026 (PHT SUPON, sold-to 0000034372, jednostka FR50, ceny w EUR).
 *
 * Trzy źródła:
 * - wyszukiwarka katalogu (/pif/api/search/…/search) — publiczna, rodziny wyrobów z opisem, zdjęciami i plikami;
 * - lista pozycji rodziny ({strona rodziny}.pdpsearchsearvlet) — zalogowany dostaje tylko pozycje konta z kodem produktu
 *   w sklepie („PRD010~2232273-06|Polytril Air Comfort0000”), gość — pozycje bez tego kodu;
 * - sklep (/shop/honeywell/en/p/{kod produktu}) — tabela pozycji konta (rozmiar, EAN, jednostka ceny, opakowanie)
 *   i /pricecall z ceną konta (netprice) i katalogową (listPrice).
 *
 * Logowanie: PingFederate authn.honeywell.com (najpierw e-mail, potem hasło; użytkownik: bez kodu z e-maila/SMS).
 * Kroków po e-mailu NIE widziałem na żywo — przebieg idzie po formularzach, które podaje strona (ukryte pola + login
 * + hasło), a sprawdza wynik w /pif/api/session/details. Pierwszy przebieg na serwerze to potwierdzi. Przekierowania
 * i formularze wolno śledzić tylko po automation.honeywell.com i authn.honeywell.com. Zapytania idą po kolei,
 * z przerwą przed każdym.
 */
final class HoneywellB2bClient
{
    public const HOST = 'automation.honeywell.com';

    public const BASE = 'https://automation.honeywell.com';

    public const AUTH_HOST = 'authn.honeywell.com';

    /** Część adresu strony rodziny przed ścieżką z wyszukiwarki („/personal-protective-equipment/…”). */
    public const PRODUCT_PAGE_PREFIX = self::BASE.'/gb/en/products';

    public const SHOP_PRODUCT = self::BASE.'/shop/honeywell/en/p/';

    private const SEARCH = self::BASE.'/pif/api/search/v1/joule-bt-sps-meta-prod/search?appId=81';

    private const LOGIN_START = self::BASE.'/pif/cwa/oauth/request/j_security_check?appId=81&resource=';

    private const SESSION_DETAILS = self::BASE.'/pif/api/session/details?appId=81';

    private const SHOP_HOME = self::BASE.'/shop/honeywell/en/';

    /**
     * Odczyty, które strona konta wysyła po zalogowaniu (kolejność jak w przeglądarce 30.09.2026) — serwer ustawia przy
     * nich m.in. ciasteczko b2bunit (konto sold-to), bez którego lista pozycji rodziny nie zwraca danych konta.
     */
    private const ACCOUNT_BOOTSTRAP = [
        self::BASE.'/pif/api/soldto/favorite/v1/user?appId=81',
        self::BASE.'/pif/api/session/refresh?appId=81',
        self::BASE.'/pif/api/account/v1/countries/country?appId=81',
        self::BASE.'/pif/api/account/v1/status?appId=81',
    ];

    /** Dane kontaktowe konta z listą kont sold-to (numer, erpId, waluta) i językiem. */
    private const CONTACT_DETAILS = self::BASE.'/pif/api/account/v1/get-contact-details?appId=81';

    /**
     * Kontekst konta dla list pozycji: strona po zalogowaniu woła /bin/encryptedCookie z kontem sold-to, a serwer
     * ustawia zaszyfrowane ciasteczko userCookie. Bez niego lista pozycji rodziny odpowiada „Results not found” dla
     * każdej rodziny (przebieg 30.09.2026 20:25: 1750/1750; potwierdzone w przeglądarce: bez userCookie — brak,
     * po /bin/encryptedCookie — 6 pozycji CoreShield).
     */
    private const ENCRYPTED_COOKIE = self::BASE.'/bin/encryptedCookie';

    private const ACCOUNT_COOKIE = 'userCookie';

    /**
     * Jednostka sprzedaży Honeywell wybierana w oknie sklepu (decyzja użytkownika 30.09.2026: FR50 – Honeywell Safety
     * Products France; w FR50 są wszystkie rozmiary CoreShield, w FOI1 tylko część).
     */
    public const PREFERRED_SALES_ORG = 'FR50';

    /** Waluta cen, którą przyjmuje łącznik (konto 0000034372: customerUnitCurrency EUR). */
    public const CURRENCY = 'EUR';

    /**
     * Ciasteczka ustawień, które skrypt strony zapisuje w przeglądarce (kraj katalogu Polska, strona /gb/en) — wartości
     * z przeglądarki zalogowanego konta 30.09.2026.
     *
     * @var array<string, string>
     */
    private const SITE_COOKIES = ['dtm' => 'pl', 'dtmt' => 'Poland', 'usr_country' => 'gb', 'usr_lang' => 'en'];

    /** Hosty zdjęć i plików rodzin (adresy z pól assets/resources wyszukiwarki). */
    private const FILE_HOSTS = ['honeywell.scene7.com', 's7d1.scene7.com', 'preview1.assetsadobe.com', 'prod-edam.honeywell.com'];

    /** Filtr kraju listy pozycji — ten sam, co na stronie /gb/en (tak pyta przeglądarka). */
    private const SKU_COUNTRY = 'gb';

    public const SKU_PAGE_SIZE = 100;

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36';

    private const SESSION_LOST = 'Utracono sesję konta automation.honeywell.com — ceny konta niedostępne';

    private const MAX_REDIRECTS = 15;

    /** Samowysyłające się formularze w jednym logowaniu (SSO między usługami). */
    private const MAX_AUTO_FORMS = 6;

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503 i potknięciach sieci, gdy serwer nie podał Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    private const MAX_RETRY_AFTER_MS = 120_000;

    private const TIMEOUT_SECONDS = 90;

    /** Broszury bywają po kilka MB (Coreshield 8,8 MB); więcej = przerwane pobranie. */
    private const FILE_MAX_BYTES = 15_000_000;

    private CookieJar $jar;

    private bool $loggedIn = false;

    private int $consecutiveFailures = 0;

    /** @var list<float> chwile ponownych logowań (relogin) */
    private array $relogins = [];

    private const MAX_RELOGINS = 3;

    private const RELOGIN_WINDOW_SECONDS = 15 * 60;

    /** Rodziny z rzędu z „Results not found”, po których sprawdzamy ważność sesji. */
    private const SESSION_CHECK_EVERY = 50;

    /** Komunikat listy pozycji dla rodziny bez pozycji konta (success=false). */
    private const NO_RESULTS = 'results not found';

    private int $emptyInARow = 0;

    /** Jednostka sprzedaży wybrana w tej sesji sklepu (okno „Honeywell Business Entity”). */
    private bool $salesOrgSelected = false;

    /** Kod wyjątku strony produktu konta bez tabeli pozycji (stan trwały, nie utrata sesji). */
    public const NO_TABLE = 7401;

    /** Pola formularza przekazania logowania między usługami (SAML, OIDC form_post, PingFederate). */
    private const HANDOFF_FIELDS = ['samlresponse', 'samlrequest', 'relaystate', 'code', 'state', 'id_token', 'access_token', 'ref', 'resumepath'];

    /** Znacznik błędu „plik za duży” w wyjątku pobierania — bez ponawiania. */
    private const TOO_LARGE = 'plik ponad ';

    private const FOREIGN_REDIRECT = 'przekierowanie poza Honeywell';

    /** @var Closure(int): void */
    private Closure $sleep;

    /**
     * @param  (Closure(int): void)|null  $sleep  pauza w ms (w testach bez czekania)
     */
    public function __construct(
        private readonly string $email,
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
        // nowa sesja sklepu nie pamięta wybranej jednostki sprzedaży
        $this->salesOrgSelected = false;

        $email = trim($this->email);
        if ($email === '' || trim($this->password) === '') {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: konto nie ma loginu (e-mail) albo hasła');
        }

        try {
            [$url, $html] = $this->browse('GET', self::LOGIN_START.rawurlencode(self::BASE.'/gb/en'));

            $identifier = self::formWithInput($html, 'subject');
            if ($identifier === null) {
                throw new RuntimeException('strona logowania bez pola adresu e-mail (subject) — zmiana logowania Honeywell?');
            }
            $fields = $identifier['fields'];
            $fields['subject'] = $email;
            [$url, $html] = $this->browse('POST', self::resolve($url, $identifier['action']), $fields);

            if (self::asksSecondFactor($html)) {
                throw new RuntimeException('Honeywell prosi o drugi składnik (kod) — łącznik loguje się tylko e-mailem i hasłem');
            }
            $passwordForm = self::passwordForm($html);
            if ($passwordForm === null) {
                $message = self::pageMessage($html);

                throw new RuntimeException('po adresie e-mail strona nie poprosiła o hasło'.($message !== '' ? ': '.$message : '').' — sprawdź login konta');
            }
            $fields = $passwordForm['fields'];
            // pole loginu: po nazwie; bez takiej nazwy — jedyne pole tekstowe formularza (inne zostają puste)
            $loginFields = array_values(array_filter($passwordForm['text'], static fn (string $name): bool => preg_match('/user|mail|login|subject|identifier/i', $name) === 1));
            if ($loginFields === [] && count($passwordForm['text']) === 1) {
                $loginFields = $passwordForm['text'];
            }
            foreach ($passwordForm['text'] as $name) {
                $fields[$name] = in_array($name, $loginFields, true) ? $email : '';
            }
            $fields[$passwordForm['password']] = $this->password;
            if (array_key_exists('pf.ok', $fields)) {
                $fields['pf.ok'] = 'clicked';
            }
            [$url, $html] = $this->browse('POST', self::resolve($url, $passwordForm['action']), $fields);
            [$url, $html] = $this->followAutoForms($url, $html);

            if (self::asksSecondFactor($html)) {
                throw new RuntimeException('Honeywell prosi o drugi składnik (kod) — łącznik loguje się tylko e-mailem i hasłem');
            }
            if (self::passwordForm($html) !== null) {
                $message = self::pageMessage($html);

                throw new RuntimeException('Honeywell odrzucił hasło'.($message !== '' ? ': '.$message : '').' — sprawdź hasło konta');
            }

            $session = self::jsonOf($this->send(fn (PendingRequest $http): Response => $this->browser($http)->get(self::SESSION_DETAILS)));
            if (($session['session_valid'] ?? null) !== true) {
                throw new RuntimeException('Honeywell nie potwierdził sesji konta po logowaniu (/pif/api/session/details)');
            }
            if (mb_strtolower(trim((string) ($session['email'] ?? ''))) !== mb_strtolower($email)) {
                throw new RuntimeException('sesja należy do innego konta niż '.$email);
            }

            // to, co strona konta robi po zalogowaniu: ciasteczka ustawień i odczyty konta (b2bunit, sold-to)
            foreach (self::SITE_COOKIES as $name => $value) {
                $this->jar->setCookie(new SetCookie(['Name' => $name, 'Value' => $value, 'Domain' => self::HOST, 'Path' => '/', 'Secure' => true]));
            }
            foreach (self::ACCOUNT_BOOTSTRAP as $bootstrap) {
                try {
                    $this->send(fn (PendingRequest $http): Response => $this->browser($http)
                        ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Referer' => self::BASE.'/gb/en'])
                        ->get($bootstrap));
                } catch (B2bFatalException $e) {
                    throw $e;
                } catch (RuntimeException) {
                    // odczyt pomocniczy — jego błąd nie przerywa logowania; brak danych konta pokaże lista pozycji
                }
            }

            $this->accountContext();

            // wejście do sklepu przenosi logowanie do SAP Commerce (ciasteczka sklepu)
            [$url, $html] = $this->browse('GET', self::SHOP_HOME);
            $this->followAutoForms($url, $html);
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        $this->loggedIn = true;
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    /**
     * Strona wyników wyszukiwarki katalogu (publiczna): {meta: {page: {total_results}}, results: […]}.
     *
     * @param  list<array<string, string>>  $filters  np. [['sbu' => 'Personal Protective Equipment']]
     * @return array<string, mixed>
     */
    public function searchProducts(array $filters, int $page, int $size): array
    {
        $body = [
            'query' => '',
            'filters' => ['all' => [
                ['document_type' => 'Product'],
                ['language' => 'en'],
                ['availability_country' => 'pl'],
                ...$filters,
            ]],
            'page' => ['size' => $size, 'current' => max(1, $page)],
        ];
        $json = self::jsonOf($this->send(fn (PendingRequest $http): Response => $this->browser($http)->asJson()->post(self::SEARCH, $body)));
        if (! is_array($json['results'] ?? null) || ! is_int($json['meta']['page']['total_results'] ?? null)) {
            throw new RuntimeException('wyszukiwarka '.self::HOST.': odpowiedź bez wyników albo licznika (strona '.$page.')');
        }

        return $json;
    }

    /**
     * Pozycje rodziny dostępne dla konta: kod pozycji dosłownie, kod produktu w sklepie i opis pozycji. Odpowiedź
     * gościa (pozycje bez kodu sklepu) = sesja wygasła → jedno ponowne logowanie; dalej tak samo = B2bFatalException.
     *
     * @param  string  $path  ścieżka z wyszukiwarki („/personal-protective-equipment/…”)
     * @return list<array{code: string, shop_code: string, description: string}>
     */
    public function accountSkus(string $path, string $productId): array
    {
        if (preg_match('#^/[A-Za-z0-9%][A-Za-z0-9%/._~-]*$#', $path) !== 1 || preg_match('/^\d+$/', $productId) !== 1) {
            throw new RuntimeException('nieprawidłowa ścieżka albo numer rodziny: '.$path.' / '.$productId);
        }
        if (! $this->loggedIn) {
            $this->relogin();
        }

        $skus = $this->readAccountSkus($path, $productId);
        if ($skus === null) {
            $this->relogin();
            $skus = $this->readAccountSkus($path, $productId);
            if ($skus === null) {
                $this->loggedIn = false;

                throw new B2bFatalException(self::SESSION_LOST.' (lista pozycji rodziny bez danych konta po ponownym logowaniu)');
            }
        }

        // „Results not found” to odpowiedź konta dla rodziny spoza konta, ale mogłaby też ukryć wygasłą sesję —
        // w długiej serii takich odpowiedzi co SESSION_CHECK_EVERY sprawdzamy sesję (nieważna = logowanie i powtórka)
        if ($skus === []) {
            if (++$this->emptyInARow % self::SESSION_CHECK_EVERY === 0 && ! $this->sessionValid()) {
                $this->relogin();
                $skus = $this->readAccountSkus($path, $productId) ?? throw new B2bFatalException(self::SESSION_LOST.' (lista pozycji rodziny bez danych konta po ponownym logowaniu)');
            }
        }
        if ($skus !== []) {
            $this->emptyInARow = 0;
        }

        return $skus;
    }

    /**
     * Strona produktu w sklepie (tabela pozycji konta). Sesja sklepu (SAP Commerce) jest osobna od sesji /pif — strona
     * bez tabeli i bez numeru konta (strona gościa, logowania z kodem 200) = jedno ponowne logowanie; strona konta bez
     * tabeli albo nadal bez tabeli = błąd tej rodziny (RuntimeException), serię takich rodzin przerywa łącznik.
     */
    public function shopPage(string $shopCode): string
    {
        $read = fn (): string => $this->send(fn (PendingRequest $http): Response => $this->browser($http)->get(self::shopUrl($shopCode)))->body();

        $html = $read();
        if (self::hasShopTable($html)) {
            return $html;
        }
        // okno „Honeywell Business Entity” zamiast tabeli — wybór jednostki trzyma tylko sesja sklepu (przebieg
        // 30.09.2026 21:11: każda strona „bez tabeli pozycji”); raz na sesję wybieramy FR50 i czytamy stronę jeszcze raz
        $prompt = self::salesOrgPrompt($html);
        if ($prompt !== null) {
            if ($this->salesOrgSelected) {
                throw new RuntimeException('sklep ponownie prosi o wybór jednostki sprzedaży mimo wybranej '.self::PREFERRED_SALES_ORG);
            }
            $this->send(fn (PendingRequest $http): Response => $this->browser($http)->get($prompt));
            $this->salesOrgSelected = true;
            $html = $read();
            if (self::hasShopTable($html)) {
                return $html;
            }
            if (self::salesOrgPrompt($html) !== null) {
                throw new RuntimeException('sklep ponownie prosi o wybór jednostki sprzedaży po wybraniu '.self::PREFERRED_SALES_ORG);
            }
        }
        if (! self::looksSignedOut($html)) {
            throw new RuntimeException('strona produktu '.$shopCode.' w sklepie bez tabeli pozycji', self::NO_TABLE);
        }
        $this->relogin();
        $html = $read();
        if (! self::hasShopTable($html)) {
            throw new RuntimeException('strona produktu '.$shopCode.' w sklepie bez tabeli pozycji także po ponownym logowaniu');
        }

        return $html;
    }

    /**
     * Strona sklepu bez numeru konta w ukrytym polu accountId (zalogowany: value="0000034372", 30.09.2026; nagłówek
     * „My Account” dokleja skrypt, więc w HTML go nie ma).
     */
    public static function looksSignedOut(string $html): bool
    {
        return preg_match('/name="accountId"\s+value="\d+"/', $html) !== 1;
    }

    /**
     * Kontekst konta jak createCookie() strony: domyślne konto sold-to z danych kontaktowych (numer 10 cyfr, erpId,
     * waluta) i język → /bin/encryptedCookie → ciasteczko userCookie. Brak konta, inna waluta niż EUR albo brak
     * ciasteczka po wywołaniu = logowanie nieudane (przebieg bez kontekstu konta nie znalazłby żadnej pozycji).
     */
    private function accountContext(): void
    {
        $details = self::jsonOf($this->send(fn (PendingRequest $http): Response => $this->browser($http)
            ->withHeaders(['Content-Type' => 'application/json', 'propagate-errors' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
            ->get(self::CONTACT_DETAILS)));
        $accounts = is_array($details['soldToAccounts'] ?? null) ? array_values(array_filter($details['soldToAccounts'], 'is_array')) : [];
        $account = null;
        foreach ($accounts as $candidate) {
            if (in_array($candidate['isDefault'] ?? null, [true, 'true'], true)) {
                $account = $candidate;
                break;
            }
        }
        $account ??= $accounts[0] ?? null;
        $soldTo = trim((string) ($account['soldToNumber'] ?? ''));
        $erpId = trim((string) ($account['erpId'] ?? ''));
        if ($account === null || preg_match('/^\d{1,10}$/', $soldTo) !== 1 || $erpId === '') {
            throw new RuntimeException('dane konta Honeywell bez konta sold-to (numer i system ERP) — nie da się ustawić kontekstu konta');
        }
        $currency = mb_strtoupper(trim((string) ($account['customerUnitCurrency'] ?? '')));
        if ($currency !== self::CURRENCY) {
            throw new RuntimeException('konto sold-to '.$soldTo.' ma walutę „'.$currency.'”, a łącznik przyjmuje ceny w '.self::CURRENCY);
        }
        $language = is_array($details['language'] ?? null) ? trim((string) ($details['language']['isoCode'] ?? '')) : '';

        $this->send(fn (PendingRequest $http): Response => $this->browser($http)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest', 'Referer' => self::BASE.'/gb/en'])
            ->get(self::ENCRYPTED_COOKIE, [
                'soldTo' => str_pad($soldTo, 10, '0', STR_PAD_LEFT),
                'currency' => $currency,
                'language' => $language !== '' ? $language : 'en',
                'totalItems' => '0',
                'totalPrice' => '0',
                'cartId' => '',
                'erpId' => $erpId,
                'tenantPath' => '/content/sps',
            ]));
        if ($this->jar->getCookieByName(self::ACCOUNT_COOKIE) === null) {
            throw new RuntimeException('Honeywell nie ustawił kontekstu konta (brak ciasteczka '.self::ACCOUNT_COOKIE.' po /bin/encryptedCookie)');
        }
    }

    /** Sesja konta /pif ważna (odczyt /pif/api/session/details); błąd odczytu = nieważna. */
    private function sessionValid(): bool
    {
        try {
            $session = self::jsonOf($this->send(fn (PendingRequest $http): Response => $this->browser($http)->get(self::SESSION_DETAILS)));
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException) {
            return false;
        }

        return ($session['session_valid'] ?? null) === true;
    }

    /**
     * Okno wyboru jednostki sprzedaży na stronie sklepu (formularz GET salesOrgSelection → updatePreferredSalesOrg):
     * adres wysłania z polami formularza jak przeglądarka po „Proceed” — pola ukryte dosłownie, wybór jednostki
     * (salesOrgCodeSel i salesOrgCode) = opcja PREFERRED_SALES_ORG, pozostałe listy puste. null = strona bez okna.
     * Brak opcji FR50 = RuntimeException (nie wybieramy innej jednostki za użytkownika).
     */
    public static function salesOrgPrompt(string $html): ?string
    {
        if (preg_match('#<form\b[^>]*\bname\s*=\s*["\']salesOrgSelection["\'][^>]*>(.*?)</form>#is', $html, $form) !== 1) {
            return null;
        }
        $action = preg_match('#<form\b[^>]*\baction\s*=\s*["\']([^"\']+)["\']#i', $form[0], $m) === 1 ? html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5) : '';
        $url = self::secureHoneywellUrl(self::resolve(self::SHOP_HOME, $action));
        if ($url === null || ! str_contains($url, '/updatePreferredSalesOrg')) {
            throw new RuntimeException('okno wyboru jednostki sprzedaży z nieoczekiwanym adresem');
        }
        $choice = null;
        if (preg_match('#<select\b[^>]*\bname\s*=\s*["\']salesOrgCodeSel["\'][^>]*>(.*?)</select>#is', $form[1], $select) === 1) {
            // opcja ma value="FR50~PRD010_10" i data-value="FR50~PRD010_EUR_10" — liczy się value (parser atrybutów,
            // nie wyrażenie: \bvalue trafiało też w data-value)
            preg_match_all('#<option\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#i', $select[1], $options);
            foreach ($options[1] as $option) {
                $value = self::attributes($option)['value'] ?? '';
                if (str_starts_with($value, self::PREFERRED_SALES_ORG.'~')) {
                    $choice = $value;
                    break;
                }
            }
        }
        if ($choice === null) {
            throw new RuntimeException('okno wyboru jednostki sprzedaży bez jednostki '.self::PREFERRED_SALES_ORG.' — wybierz ją w sklepie albo zmień ustalenie');
        }

        // pola w kolejności formularza (nazwy się powtarzają — jak przeglądarka, bez scalania)
        $pairs = [];
        preg_match_all('#<(input|select)\b((?:[^>"\']|"[^"]*"|\'[^\']*\')*)>#i', $form[1], $fields, PREG_SET_ORDER);
        foreach ($fields as $field) {
            $attrs = self::attributes($field[2]);
            $name = $attrs['name'] ?? '';
            if ($name === '' || in_array(strtolower($attrs['type'] ?? ''), ['button', 'submit'], true)) {
                continue;
            }
            $value = strtolower($field[1]) === 'select' ? '' : ($attrs['value'] ?? '');
            if (in_array($name, ['salesOrgCodeSel', 'salesOrgCode'], true)) {
                $value = $choice;
            }
            $pairs[] = rawurlencode($name).'='.rawurlencode($value);
        }

        return $url.(str_contains($url, '?') ? '&' : '?').implode('&', $pairs);
    }

    /** Strona produktu sklepu z tabelą pozycji (formularz koszyka i tabela custom-table). */
    public static function hasShopTable(string $html): bool
    {
        return str_contains($html, 'hWAddToCartForm') && str_contains($html, 'custom-table');
    }

    /**
     * Ceny konta pozycji: ref („PRD010~T4700-08M”) => {listPrice, netprice, discount, error} dosłownie. Odpowiedź
     * nie-JSON (gość dostaje stronę logowania) = jedno ponowne logowanie; dalej = B2bFatalException.
     *
     * @param  list<string>  $codes  kody pozycji dosłownie („T4700/8M”)
     * @return array<string, array<string, mixed>>
     */
    public function prices(string $shopCode, array $codes): array
    {
        if ($codes === []) {
            return [];
        }
        $url = self::shopUrl($shopCode).'/pricecall?&displayedSkus='.implode(',', array_map('rawurlencode', $codes)).'&_='.(int) (microtime(true) * 1000);
        $read = function () use ($url): ?array {
            $json = self::jsonOf($this->send(fn (PendingRequest $http): Response => $this->browser($http)->get($url)));

            return is_array($json['sku'] ?? null) ? $json['sku'] : null;
        };

        $rows = $read();
        if ($rows === null) {
            // nieoczekiwana odpowiedź to nie dowód wylogowania — logujemy się ponownie tylko przy nieważnej sesji
            if ($this->sessionValid()) {
                throw new RuntimeException('ceny '.$shopCode.': nieczytelna odpowiedź sklepu przy ważnej sesji');
            }
            $this->relogin();
            $rows = $read();
            if ($rows === null) {
                if ($this->sessionValid()) {
                    throw new RuntimeException('ceny '.$shopCode.': nieczytelna odpowiedź sklepu po ponownym logowaniu');
                }
                $this->loggedIn = false;

                throw new B2bFatalException(self::SESSION_LOST.' (ceny bez danych konta po ponownym logowaniu)');
            }
        }

        // pary: {"ref": …} i zaraz po nim {"variants": [ … ]}
        $prices = [];
        $ref = null;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            if (is_string($row['ref'] ?? null)) {
                $ref = $row['ref'];

                continue;
            }
            if ($ref !== null && is_array($row['variants'] ?? null) && is_array($row['variants'][0] ?? null)) {
                $prices[$ref] = $row['variants'][0];
            }
            $ref = null;
        }

        return $prices;
    }

    /**
     * Zdjęcie albo plik rodziny (publiczne, bez sesji).
     *
     * @return array{bytes: string, mime: string}
     */
    public function fileBytes(string $url): array
    {
        if (! self::isFileUrl($url)) {
            throw new RuntimeException('plik spoza hostów Honeywell: '.$url);
        }
        $tooLarge = static function (int $bytes) use ($url): void {
            if ($bytes > self::FILE_MAX_BYTES) {
                throw new B2bFileTooLargeException(self::TOO_LARGE.(int) (self::FILE_MAX_BYTES / 1_000_000).' MB pominięty: '.$url);
            }
        };
        $response = $this->send(fn (PendingRequest $http): Response => $this->browser($http)->withOptions([
            'on_headers' => static function (ResponseInterface $response) use ($tooLarge): void {
                $length = $response->getHeaderLine('Content-Length');
                if (ctype_digit($length)) {
                    $tooLarge((int) $length);
                }
            },
            'progress' => static function (int $total, int $downloaded) use ($tooLarge): void {
                $tooLarge($downloaded);
            },
        ])->get($url));

        return ['bytes' => (string) $response->body(), 'mime' => strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]))];
    }

    /** https na jednym z hostów zdjęć/plików Honeywell, bez danych logowania i portu. */
    public static function isFileUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        return in_array(strtolower($parts['host'] ?? ''), self::FILE_HOSTS, true);
    }

    public static function shopUrl(string $shopCode): string
    {
        return self::SHOP_PRODUCT.rawurlencode($shopCode);
    }

    public static function productPageUrl(string $path): string
    {
        return self::PRODUCT_PAGE_PREFIX.$path;
    }

    /**
     * @return list<array{code: string, shop_code: string, description: string}>|null null = odpowiedź gościa (pozycje bez kodu sklepu)
     */
    private function readAccountSkus(string $path, string $productId): ?array
    {
        $skus = [];
        $total = null;
        for ($page = 1; $total === null || count($skus) < $total; $page++) {
            $payload = ['queries' => [[
                'query' => '',
                'filters' => ['all' => [
                    ['document_type' => 'Sku'],
                    ['availability_country' => self::SKU_COUNTRY],
                    ['is_phantom' => 'false'],
                ]],
                'sort' => [['title' => 'asc']],
                'page' => ['current' => $page, 'size' => self::SKU_PAGE_SIZE],
            ]]];
            // nagłówki jak z przeglądarki na stronie rodziny (Referer, Origin, XHR)
            $response = $this->send(fn (PendingRequest $http): Response => $this->browser($http)
                ->withHeaders([
                    'X-Requested-With' => 'XMLHttpRequest',
                    'Referer' => self::productPageUrl($path).'?pdpPageTab=pills-sku-tab',
                    'Origin' => self::BASE,
                    'Accept' => 'application/json, text/javascript, */*; q=0.01',
                ])
                ->asForm()
                ->post(self::productPageUrl($path).'.pdpsearchsearvlet', [
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    'tenantPath' => '/content/sps',
                    'dynamicProductId' => $productId,
                ]));
            $json = self::jsonOf($response);
            // rodzina bez pozycji konta: {"success":false,"message":"Results not found"} (przebieg 30.09.2026)
            if (is_array($json) && ($json['success'] ?? null) === false
                && mb_strtolower(trim((string) ($json['message'] ?? ''))) === self::NO_RESULTS) {
                return $page === 1 ? [] : array_values($skus);
            }
            if ($json === null || ($json['success'] ?? null) !== true) {
                throw new RuntimeException('lista pozycji rodziny '.$path.': nieczytelna odpowiedź ('.self::describe($response, $json).')');
            }
            $result = $json['search_results'] ?? null;
            if (is_array($result) && array_is_list($result)) {
                $result = $result[0] ?? null;
            }
            $count = $result['meta']['page']['total_results'] ?? null;
            if (! is_array($result) || ! is_int($count) || ! is_array($result['results'] ?? null)) {
                throw new RuntimeException('lista pozycji rodziny '.$path.': odpowiedź bez wyników albo licznika');
            }
            $total ??= $count;
            if ($count !== $total) {
                throw new RuntimeException('lista pozycji rodziny '.$path.': licznik zmienił się z '.$total.' na '.$count);
            }
            if ($result['results'] === []) {
                break;
            }
            $accountRows = 0;
            foreach ($result['results'] as $row) {
                $id = is_array($row) ? (string) ($row['id']['raw'] ?? '') : '';
                $code = is_array($row) ? trim((string) ($row['title']['raw'] ?? '')) : '';
                // konto: „PRD010~2232273-06|Polytril Air Comfort0000”; gość: „joule-bt-sps-epim-sku-prod|2174110-en”;
                // pojedynczy nietypowy wiersz pomijamy — gość to strona bez żadnego wiersza konta (niżej)
                if (preg_match('/^[^|~]+~[^|]+\|(.+)$/', $id, $m) !== 1) {
                    continue;
                }
                $accountRows++;
                $shopCode = trim($m[1]);
                if ($code === '' || $shopCode === '') {
                    continue;
                }
                $skus[$code] = [
                    'code' => $code,
                    'shop_code' => $shopCode,
                    'description' => trim((string) ($row['sku_description']['raw'] ?? '')),
                ];
            }
            if ($accountRows === 0) {
                return null;
            }
            if ($page * self::SKU_PAGE_SIZE >= $total) {
                break;
            }
        }

        return array_values($skus);
    }

    /**
     * Ponowne logowanie po utracie sesji — najwyżej MAX_RELOGINS razy w RELOGIN_WINDOW_SECONDS: PingFederate blokuje
     * konto po serii logowań, a seria utraconych sesji w krótkim czasie znaczy, że sklep i tak nie poda danych konta.
     * Przebieg trwa 1,5–2 h, więc zwykłe wygasanie sesji co kilkadziesiąt minut limitu nie wyczerpuje.
     */
    private function relogin(): void
    {
        $now = microtime(true);
        $this->relogins = array_values(array_filter($this->relogins, static fn (float $at): bool => $now - $at < self::RELOGIN_WINDOW_SECONDS));
        if (count($this->relogins) >= self::MAX_RELOGINS) {
            $this->loggedIn = false;

            throw new B2bFatalException(self::SESSION_LOST.' ('.self::MAX_RELOGINS.' ponowne logowania w '.(self::RELOGIN_WINDOW_SECONDS / 60).' min — przerwane, żeby nie zablokować konta)');
        }
        $this->relogins[] = $now;
        try {
            $this->login();
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new B2bFatalException(self::SESSION_LOST.' ('.$e->getMessage().')', 0, $e);
        }
    }

    /**
     * Zapytanie z ręcznym śledzeniem przekierowań — każdy adres musi być na hoście Honeywell (formularze logowania
     * nie wysyłają niczego gdzie indziej).
     *
     * @param  array<string, string>  $form
     * @return array{0: string, 1: string} [adres końcowy, treść]
     */
    private function browse(string $method, string $url, #[\SensitiveParameter] array $form = []): array
    {
        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $secure = self::secureHoneywellUrl($url);
            if ($secure === null) {
                throw new RuntimeException(self::FOREIGN_REDIRECT.': '.self::urlOrigin($url));
            }
            $url = $secure;
            $response = $this->send(
                fn (PendingRequest $http): Response => $method === 'POST'
                    ? $this->browser($http)->withOptions(['allow_redirects' => false])->asForm()->post($url, $form)
                    : $this->browser($http)->withOptions(['allow_redirects' => false])->get($url),
                [301, 302, 303, 307, 308],
            );
            if (! $response->redirect()) {
                return [$url, $response->body()];
            }
            $location = trim((string) $response->header('Location'));
            if ($location === '') {
                throw new RuntimeException('przekierowanie bez adresu');
            }
            $url = self::resolve($url, $location);
            if (! in_array($response->status(), [307, 308], true)) {
                $method = 'GET';
                $form = [];
            }
        }

        throw new RuntimeException('zbyt wiele przekierowań przy logowaniu');
    }

    /**
     * Samowysyłające się formularze (same ukryte pola — przekazanie logowania między usługami, np. SAML/OIDC form_post).
     *
     * @return array{0: string, 1: string}
     */
    private function followAutoForms(string $url, string $html): array
    {
        for ($i = 0; $i < self::MAX_AUTO_FORMS; $i++) {
            $form = self::autoPostForm($html);
            if ($form === null) {
                return [$url, $html];
            }
            $target = self::resolve($url, $form['action']);
            // tylko przekazanie logowania: strona logowania albo cel na logowaniu / powrocie OAuth — nigdy zwykły
            // formularz sklepu (koszyk, waluta, jednostka sprzedaży)
            $targetHost = strtolower((string) parse_url($target, PHP_URL_HOST));
            $targetPath = (string) parse_url($target, PHP_URL_PATH);
            if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== self::AUTH_HOST && $targetHost !== self::AUTH_HOST
                && ! str_starts_with($targetPath, '/pif/cwa/oauth/')) {
                return [$url, $html];
            }
            [$url, $html] = $this->browse('POST', $target, $form['fields']);
        }

        throw new RuntimeException('zbyt wiele formularzy przekazania logowania');
    }

    /**
     * Formularz z polem $input: adres i pola ukryte.
     *
     * @return array{action: string, fields: array<string, string>}|null
     */
    public static function formWithInput(string $html, string $input): ?array
    {
        foreach (self::forms($html) as $form) {
            foreach ($form['inputs'] as $attrs) {
                if (($attrs['name'] ?? '') === $input) {
                    return ['action' => $form['action'], 'fields' => self::hiddenFields($form['inputs'])];
                }
            }
        }

        return null;
    }

    /**
     * Formularz hasła: adres, pola ukryte, nazwy pól tekstowych/e-mail (login) i nazwa pola hasła.
     *
     * @return array{action: string, fields: array<string, string>, text: list<string>, password: string}|null
     */
    public static function passwordForm(string $html): ?array
    {
        foreach (self::forms($html) as $form) {
            $password = null;
            $text = [];
            foreach ($form['inputs'] as $attrs) {
                $type = strtolower($attrs['type'] ?? 'text');
                $name = $attrs['name'] ?? '';
                if ($name === '') {
                    continue;
                }
                if ($type === 'password') {
                    $password ??= $name;
                } elseif (in_array($type, ['text', 'email'], true)) {
                    $text[] = $name;
                }
            }
            if ($password !== null) {
                return ['action' => $form['action'], 'fields' => self::hiddenFields($form['inputs']), 'text' => $text, 'password' => $password];
            }
        }

        return null;
    }

    /**
     * Formularz przekazania logowania: POST z samymi polami ukrytymi i wyraźnym znakiem przekazania — polem SAML/OIDC
     * (HANDOFF_FIELDS) albo samowysłaniem skryptem strony (onload / .submit()). Formularze z samym CSRFToken (sklep:
     * konfigurator, waluta) się nie liczą.
     *
     * @return array{action: string, fields: array<string, string>}|null
     */
    public static function autoPostForm(string $html): ?array
    {
        $selfSubmitting = preg_match('/onload\s*=\s*["\'][^"\']*submit\s*\(|\.submit\s*\(\s*\)/i', $html) === 1;
        foreach (self::forms($html) as $form) {
            if ($form['method'] !== 'post' || $form['inputs'] === []) {
                continue;
            }
            $visible = array_filter($form['inputs'], static fn (array $a): bool => ! in_array(strtolower($a['type'] ?? 'text'), ['hidden', 'submit'], true));
            $hidden = self::hiddenFields($form['inputs']);
            if ($visible !== [] || $hidden === []) {
                continue;
            }
            $handoff = array_intersect(array_map('strtolower', array_keys($hidden)), self::HANDOFF_FIELDS) !== [];
            if ($handoff || ($selfSubmitting && ! array_key_exists('CSRFToken', $hidden))) {
                return ['action' => $form['action'], 'fields' => $hidden];
            }
        }

        return null;
    }

    /** Strona pyta o kod jednorazowy / drugi składnik (PingID, OTP). */
    public static function asksSecondFactor(string $html): bool
    {
        $mentions = preg_match('/(?<![a-z])(pingid|one-time passcode|one time passcode|verification code|otp)(?![a-z])/', mb_strtolower($html)) === 1;
        if (! $mentions) {
            return false;
        }
        // widoczne pole na kod — ukryte „code” w przekazaniu OIDC to nie pytanie o kod
        foreach (self::forms($html) as $form) {
            foreach ($form['inputs'] as $attrs) {
                if (strtolower($attrs['type'] ?? 'text') !== 'hidden' && preg_match('/otp|passcode|code/i', $attrs['name'] ?? '') === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Komunikat błędu ze strony logowania (element z klasą *error*), bez znaczników. */
    public static function pageMessage(string $html): string
    {
        if (preg_match('#<(?:div|span|p|label)\b[^>]*class\s*=\s*["\'][^"\']*\berror[^"\']*["\'][^>]*>(.*?)</(?:div|span|p|label)>#is', $html, $m) !== 1) {
            return '';
        }

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5))), 0, 200);
    }

    /**
     * @return list<array{action: string, method: string, inputs: list<array<string, string>>}>
     */
    private static function forms(string $html): array
    {
        $tag = '(?:[^>"\']|"[^"]*"|\'[^\']*\')*';
        preg_match_all('#<form\b('.$tag.')>(.*?)</form>#is', $html, $matches, PREG_SET_ORDER);
        $forms = [];
        foreach ($matches as $m) {
            $attrs = self::attributes($m[1]);
            preg_match_all('#<input\b('.$tag.')>#i', $m[2], $inputs, PREG_SET_ORDER);
            $forms[] = [
                'action' => trim($attrs['action'] ?? ''),
                'method' => strtolower(trim($attrs['method'] ?? 'get')),
                'inputs' => array_map(static fn (array $i): array => self::attributes($i[1]), $inputs),
            ];
        }

        return $forms;
    }

    /**
     * @param  list<array<string, string>>  $inputs
     * @return array<string, string>
     */
    private static function hiddenFields(array $inputs): array
    {
        $fields = [];
        foreach ($inputs as $attrs) {
            if (($attrs['name'] ?? '') !== '' && strtolower($attrs['type'] ?? 'text') === 'hidden') {
                $fields[$attrs['name']] = $attrs['value'] ?? '';
            }
        }

        return $fields;
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

    /** Adres względny formularza/przekierowania → pełny (pusty action = ta sama strona). */
    private static function resolve(string $base, string $target): string
    {
        $target = trim($target);
        if ($target === '') {
            return $base;
        }
        if (preg_match('#^https?://#i', $target) === 1) {
            return $target;
        }
        $parts = parse_url($base);
        $origin = strtolower($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        if (str_starts_with($target, '//')) {
            return strtolower($parts['scheme'] ?? 'https').':'.$target;
        }
        if (str_starts_with($target, '/')) {
            return $origin.$target;
        }
        $path = $parts['path'] ?? '/';

        return $origin.substr($path, 0, (int) strrpos($path, '/') + 1).$target;
    }

    private static function isHoneywellUrl(string $url): bool
    {
        return self::secureHoneywellUrl($url) !== null;
    }

    /**
     * Adres na hoście Honeywell (sklep, logowanie) w postaci https bez portu; null = inny host albo dane logowania
     * w adresie. Honeywell odsyła część przekierowań jako „http://” albo z portem (pierwszy przebieg 30.09.2026:
     * „przekierowanie poza Honeywell: automation.honeywell.com”) — takie adresy idą dalej po https, nigdy po http.
     */
    private static function secureHoneywellUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass']) || ! in_array($parts['port'] ?? 443, [80, 443], true)) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        if (! in_array($host, [self::HOST, self::AUTH_HOST], true)) {
            return null;
        }

        return 'https://'.$host.($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    /**
     * Krótki opis nieoczekiwanej odpowiedzi do dziennika: kod HTTP, typ treści i komunikat JSON (message/error) albo
     * początek treści bez znaczników — żeby następny przebieg pokazał, co sklep odpowiedział.
     *
     * @param  array<string, mixed>|null  $json
     */
    private static function describe(Response $response, ?array $json): string
    {
        $type = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        if ($json !== null) {
            $message = $json['message'] ?? $json['error'] ?? $json['errorMessage'] ?? null;
            $detail = 'JSON'.(array_key_exists('success', $json) ? ' success='.var_export($json['success'], true) : '')
                .(is_scalar($message) && trim((string) $message) !== '' ? ', komunikat: '.trim((string) $message) : ', klucze: '.implode(',', array_slice(array_keys($json), 0, 6)));
        } else {
            $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) preg_replace('#<(script|style)\b.*?</\1>#is', ' ', $response->body())), ENT_QUOTES | ENT_HTML5)));
            $detail = $text !== '' ? 'treść: „'.mb_substr($text, 0, 150).'”' : 'pusta treść';
        }

        return 'HTTP '.$response->status().($type !== '' ? ', '.$type : '').', '.mb_substr($detail, 0, 250);
    }

    /** Host, protokół i port adresu do komunikatu (bez ścieżki i zapytania — mogą nieść kod logowania). */
    private static function urlOrigin(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return 'nieczytelny adres';
        }

        return strtolower($parts['scheme'] ?? '?').'://'.($parts['host'] ?? '?').(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function jsonOf(Response $response): ?array
    {
        $json = json_decode($response->body(), true);

        return is_array($json) ? $json : null;
    }

    private function browser(PendingRequest $http): PendingRequest
    {
        return $http->withUserAgent(self::USER_AGENT)->withHeaders(['Accept-Language' => 'en-GB,en;q=0.9,pl;q=0.8']);
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
                    'allow_redirects' => [
                        'max' => 10,
                        // przekierowanie tylko na hosty Honeywell (sklep, logowanie, zdjęcia i pliki)
                        'on_redirect' => static function (RequestInterface $request, ResponseInterface $response, UriInterface $uri): void {
                            if (! self::isHoneywellUrl((string) $uri) && ! self::isFileUrl((string) $uri)) {
                                throw new RuntimeException(self::FOREIGN_REDIRECT.': '.self::urlOrigin((string) $uri));
                            }
                        },
                    ],
                ]));
            } catch (ConnectionException $e) {
                // przerwane pobranie za dużego pliku — komunikat jest ogólny („…during the on_headers event”), nasz
                // wyjątek leży głębiej w łańcuchu; bez ponawiania i bez liczenia do serii błędów
                $tooLarge = B2bFileTooLargeException::in($e);
                if ($tooLarge !== null) {
                    $this->consecutiveFailures = 0;

                    throw new RuntimeException($tooLarge->getMessage(), 0, $e);
                }
                // przekierowanie poza hosty Honeywell (on_redirect) — bez ponawiania
                if (preg_match('/'.preg_quote(self::FOREIGN_REDIRECT, '/').': \S+/u', $e->getMessage(), $m) === 1) {
                    throw new RuntimeException($m[0].' — zapytanie przerwane', 0, $e);
                }
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
