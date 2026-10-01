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
 * Platforma Comarch B2B producenta Fagum-Stomil b2b.fagum.pl (aplikacja Angular na API JSON, ERP Comarch XL).
 * Nieoficjalne: adresy i pola odczytane ze skryptów strony (main, chunk z usługami konta i towarów) i z odpowiedzi
 * zalogowanego konta 01.10.2026.
 *
 * Logowanie jak w formularzu strony: JSON {customerName (NIP kontrahenta), userName (pracownik, np. „Jan Kowalski”),
 * password, rememberMe, companyGroupId: 0, LoginConfirmation: true} pod /account/login — witryna wymaga zaznaczenia
 * regulaminu przy każdym logowaniu (/account/isloginconfirmationrequired). Złe dane = HTTP 401, za dużo prób = 406.
 * Sesja w ciasteczku HttpOnly; potwierdzenie zalogowania: GET /account/isloggedin → true. Bez sesji API odpowiada 401.
 *
 * Zapytania tylko GET (poza logowaniem i drzewem grup treeXl, które strona też wysyła POST-em bez tokenu), po kolei,
 * z przerwą przed każdym. Zdjęcia (/imagehandler.ashx) i pliki (/filehandler.ashx z hashem pliku) wydaje tylko
 * zalogowanemu.
 */
final class FagumB2bClient
{
    public const HOST = 'b2b.fagum.pl';

    public const BASE = 'https://b2b.fagum.pl';

    private const LOGIN_PAGE = self::BASE.'/login';

    private const LOGIN = self::BASE.'/account/login';

    private const IS_LOGGED_IN = self::BASE.'/account/isloggedin';

    /** Lista towarów: groupId=0 = cała oferta konta (285 modeli 01.10.2026), 50 na stronę, stała kolejność po nazwie. */
    private const LIST = self::BASE.'/api/items/articleListXl/';

    private const TREE = self::BASE.'/api/items/treeXl';

    private const GENERAL = self::BASE.'/api/items/{id}/getArticleGeneralInfoXl';

    private const ATTRIBUTES = self::BASE.'/api/items/{id}/attributesXl';

    private const DETAILS = self::BASE.'/api/items/{id}/articleDetailsXl';

    private const VARIANTS = self::BASE.'/api/items/{id}/getArticleVariantsDetailsXl';

    private const PRICE = self::BASE.'/api/items/articleFromListXl/';

    /** Język interfejsu (opisy i atrybuty po polsku) — ciasteczko, które strona ustawia sama. */
    private const CULTURE = 'pl-PL';

    private const SESSION_LOST = 'Utracono sesję konta b2b.fagum.pl — ceny konta niedostępne';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503 i potknięciach sieci, gdy witryna nie podała Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

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
        private readonly string $customerCode,
        private readonly string $username,
        #[\SensitiveParameter] private readonly string $password,
        private readonly int $delayMs = 150,
        ?Closure $sleep = null,
    ) {
        $this->jar = self::newJar();
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    public function login(): void
    {
        // nowa sesja — stare ciasteczka mogły należeć do wygasłej
        $this->jar = self::newJar();
        $this->loggedIn = false;

        if (trim($this->customerCode) === '') {
            throw new RuntimeException(
                'Logowanie do '.self::HOST.' nieudane: konto nie ma kodu kontrahenta (uzupełnij pole „Kod kontrahenta” w koncie B2B — NIP firmy)'
            );
        }
        if (trim($this->username) === '') {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: konto nie ma nazwy użytkownika (pracownik, np. „Jan Kowalski”)');
        }

        try {
            // strona logowania zakłada sesję (ciasteczka), jak w przeglądarce
            $this->send(static fn (PendingRequest $http): Response => $http->accept('text/html')->get(self::LOGIN_PAGE));
            $response = $this->send(fn (PendingRequest $http): Response => $http->asJson()->post(self::LOGIN, [
                'customerName' => trim($this->customerCode),
                'userName' => trim($this->username),
                'password' => $this->password,
                'rememberMe' => false,
                'companyGroupId' => 0,
                'LoginConfirmation' => true,
            ]), acceptStatuses: [401, 406]);
            if ($response->status() === 401) {
                throw new RuntimeException('witryna odrzuciła logowanie (HTTP 401) — sprawdź kod kontrahenta (NIP), pracownika i hasło');
            }
            if ($response->status() === 406) {
                throw new RuntimeException('witryna zablokowała logowanie po zbyt wielu nieudanych próbach (HTTP 406)');
            }
            $confirmed = $this->sessionAlive();
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        if (! $confirmed) {
            throw new RuntimeException(
                'Logowanie do '.self::HOST.' nieudane: witryna nie potwierdziła zalogowania (/account/isloggedin) — sprawdź kod kontrahenta (NIP), pracownika i hasło'
            );
        }

        $this->loggedIn = true;
    }

    public function isLoggedIn(): bool
    {
        return $this->loggedIn;
    }

    /**
     * Strona listy towarów konta (numerowana od 1): {articleList: [{article: {id, name, code, type…}, status…}],
     * paging: {currentPage, totalPages}}.
     *
     * @return array<string, mixed>
     */
    public function listPage(int $page): array
    {
        $json = $this->accountJson(self::LIST, [
            'groupId' => 0,
            'filterInGroup' => 'false',
            'pageNumber' => max(1, $page),
            'sortMode' => 'NameAsc',
            'onlyAvailable' => 'false',
            'warehouseId' => 0,
            'stockLevelFilter' => 0,
        ], 'strona listy '.$page);
        if (! is_array($json['articleList'] ?? null) || ! is_array($json['paging'] ?? null)) {
            throw new RuntimeException('Strona '.$page.' listy '.self::HOST.' bez oczekiwanego JSON-a (articleList, paging)');
        }

        return $json;
    }

    /**
     * Grupy towarów pod korzeniem drzewa: [{id, name, isExpand}].
     *
     * @return list<array<string, mixed>>
     */
    public function groups(): array
    {
        $json = $this->accountJson(self::TREE, [
            'groupId' => 0, 'parentId' => null, 'manufacturerIds' => null, 'brandIds' => null, 'filter' => null,
        ], 'drzewo grup', post: true);
        if (! is_array($json['groups'] ?? null)) {
            throw new RuntimeException('Drzewo grup '.self::HOST.' bez oczekiwanego JSON-a (groups)');
        }

        return array_values(array_filter($json['groups'], 'is_array'));
    }

    /**
     * Strona listy towarów jednej grupy (te same pola co listPage).
     *
     * @return array<string, mixed>
     */
    public function groupPage(int $groupId, int $page): array
    {
        $json = $this->accountJson(self::LIST, [
            'groupId' => $groupId,
            'filterInGroup' => 'false',
            'pageNumber' => max(1, $page),
            'sortMode' => 'NameAsc',
            'onlyAvailable' => 'false',
            'warehouseId' => 0,
            'stockLevelFilter' => 0,
        ], 'grupa '.$groupId.' strona '.$page);
        if (! is_array($json['articleList'] ?? null) || ! is_array($json['paging'] ?? null)) {
            throw new RuntimeException('Grupa '.$groupId.' listy '.self::HOST.' bez oczekiwanego JSON-a (articleList, paging)');
        }

        return $json;
    }

    /**
     * Dane ogólne towaru: {articleGeneralInfo: {article: {id, name, code, symbol}, articleBasicDetails: {description,
     * ean, manufacturer, brand, status…}}}.
     *
     * @return array<string, mixed>
     */
    public function generalInfo(int $articleId): array
    {
        $json = $this->accountJson(str_replace('{id}', (string) $articleId, self::GENERAL), ['contextGroupId' => 0], 'towar '.$articleId);
        $info = $json['articleGeneralInfo'] ?? null;
        if (! is_array($info) || ! is_array($info['article'] ?? null) || ! is_array($info['articleBasicDetails'] ?? null)) {
            throw new RuntimeException('dane towaru '.$articleId.' bez oczekiwanego JSON-a (articleGeneralInfo)');
        }

        return $info;
    }

    /**
     * Atrybuty, załączniki i zdjęcia towaru: {articleAttributes, articleAttachments, articleImages}.
     *
     * @return array<string, mixed>
     */
    public function attributes(int $articleId): array
    {
        $json = $this->accountJson(str_replace('{id}', (string) $articleId, self::ATTRIBUTES), [], 'atrybuty towaru '.$articleId);
        if (! array_key_exists('articleAttributes', $json)) {
            throw new RuntimeException('atrybuty towaru '.$articleId.' bez oczekiwanego JSON-a (articleAttributes)');
        }

        return $json;
    }

    /**
     * Rodzaj towaru: articleDetailsType „ContainsVariants” albo „NotContainVariants”. Strona pyta o warianty tylko
     * przy pierwszym — dla towaru bez wariantów getArticleVariantsDetailsXl odpowiada HTTP 500 (40 z 285 modeli
     * 01.10.2026: asortyment w kartonie, wyściółki, pojedyncze rozmiary).
     */
    public function detailsType(int $articleId): string
    {
        $json = $this->accountJson(str_replace('{id}', (string) $articleId, self::DETAILS), ['contextGroupId' => 0], 'rodzaj towaru '.$articleId);
        $type = $json['articleDetailsType'] ?? null;
        if (! is_string($type) || trim($type) === '') {
            throw new RuntimeException('rodzaj towaru '.$articleId.' bez oczekiwanego JSON-a (articleDetailsType)');
        }

        return trim($type);
    }

    /**
     * Warianty towaru (tylko „ContainsVariants”): {headerVariants, expandedVariant: {header: {translatedName}, expandedValues: [{articleId,
     * articleStatus, value: {translatedName}}]}} — expandedVariant null/puste = towar bez wariantów.
     *
     * @return array<string, mixed>
     */
    public function variants(int $articleId): array
    {
        return $this->accountJson(str_replace('{id}', (string) $articleId, self::VARIANTS), [], 'warianty towaru '.$articleId);
    }

    /**
     * Cena konta towaru: {unit: {basicUnit, auxiliaryUnit, numerator, denominator, unitLockChange}, price: {currency,
     * netPrice, baseNetPrice, unitNetPrice}, itemExistsInCurrentPriceList}.
     *
     * @return array<string, mixed>
     */
    public function price(int $articleId): array
    {
        $json = $this->accountJson(self::PRICE, ['articleId' => $articleId, 'warehouseId' => 0], 'cena towaru '.$articleId);
        if (! is_array($json['price'] ?? null) || ! is_array($json['unit'] ?? null)) {
            throw new RuntimeException('cena towaru '.$articleId.' bez oczekiwanego JSON-a (price, unit)');
        }

        return $json;
    }

    /**
     * Zdjęcie albo plik spod adresu z łącznika — wydawane tylko zalogowanemu. Bez sesji sklep nie odpowiada 401:
     * /imagehandler.ashx oddaje HTTP 200 z pustą treścią, a /filehandler.ashx przekierowuje na stronę logowania
     * (sprawdzone 01.10.2026). Pusta treść albo strona HTML zamiast pliku = jedno ponowne logowanie; nadal to samo —
     * błąd (zdjęcia i pliku nie zapisujemy).
     *
     * @return array{bytes: string, mime: string}
     */
    public function fileBytes(string $url): array
    {
        if (! self::isShopUrl($url)) {
            throw new RuntimeException('adres spoza '.self::HOST.': '.$url);
        }
        if (! $this->loggedIn) {
            $this->relogin();
        }
        $file = $this->fetchFile($url);
        if ($file === null) {
            $this->relogin();
            $file = $this->fetchFile($url);
            if ($file === null) {
                throw new RuntimeException('sklep nie wydał pliku '.$url.' (pusta treść albo strona logowania po ponownym logowaniu)');
            }
        }

        return $file;
    }

    /**
     * @return array{bytes: string, mime: string}|null null = sklep nie wydał pliku (brak sesji)
     */
    private function fetchFile(string $url): ?array
    {
        $response = $this->send(static fn (PendingRequest $http): Response => $http->accept('*/*')->get($url), acceptStatuses: [401]);
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));
        $bytes = self::bodyOf($response);
        if ($response->status() === 401 || $bytes === '' || $mime === 'text/html') {
            return null;
        }

        return ['bytes' => $bytes, 'mime' => $mime];
    }

    /** https na b2b.fagum.pl (bez danych logowania i nietypowego portu w adresie). */
    public static function isShopUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }

        return strtolower($parts['host'] ?? '') === self::HOST;
    }

    private static function newJar(): CookieJar
    {
        $jar = new CookieJar;
        $jar->setCookie(new SetCookie(['Name' => '_culture', 'Value' => self::CULTURE, 'Domain' => self::HOST, 'Path' => '/']));

        return $jar;
    }

    private function sessionAlive(): bool
    {
        $response = $this->send(static fn (PendingRequest $http): Response => $http->get(self::IS_LOGGED_IN), acceptStatuses: [401]);

        return $response->successful() && trim(self::bodyOf($response)) === 'true';
    }

    /**
     * JSON dla konta. HTTP 401 = sesja wygasła → jedno ponowne logowanie; nadal 401 = B2bFatalException (dalsze
     * zapytania nie miałyby cen konta).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function accountJson(string $url, array $params, string $label, bool $post = false): array
    {
        if (! $this->loggedIn) {
            $this->relogin();
        }
        $call = static fn (PendingRequest $http): Response => $post ? $http->asJson()->post($url, $params) : $http->get($url, $params);

        $response = $this->send($call, acceptStatuses: [401]);
        if ($response->status() === 401) {
            $this->relogin();
            $response = $this->send($call, acceptStatuses: [401]);
            if ($response->status() === 401) {
                $this->loggedIn = false;

                throw new B2bFatalException(self::SESSION_LOST.' ('.$label.': HTTP 401 po ponownym logowaniu)');
            }
        }
        $json = json_decode(self::bodyOf($response), true);
        if (! is_array($json)) {
            throw new RuntimeException($label.': odpowiedź '.self::HOST.' nie jest JSON-em');
        }

        return $json;
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

    /** Treść odpowiedzi z zamknięciem strumienia (jak EjendalsB2bClient::bodyOf — treść nie czeka na sprzątanie cykli). */
    private static function bodyOf(Response $response): string
    {
        $stream = $response->toPsrResponse()->getBody();
        $body = (string) $stream;
        $stream->close();

        return $body;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     * @param  list<int>  $acceptStatuses  statusy oddawane wołającemu zamiast błędu
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
                $response = $call(Http::timeout(60)->withHeaders([
                    'Accept' => 'application/json, text/plain, */*',
                    'Accept-Language' => 'pl-PL,pl;q=0.9',
                ])->withOptions([
                    'cookies' => $this->jar,
                    'allow_redirects' => ['max' => 5, 'track_redirects' => true],
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
