<?php

declare(strict_types=1);

namespace App\Services\B2b;

use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sklep B2B producenta SIR Safety System b2b.sirsafety.com (aplikacja Angular/Ionic na JSON-owym API
 * app.sirsafety.com/sirapi/api/, pod spodem SAP). Nieoficjalne: adresy, nagłówki i pola odczytane ze skryptu sklepu
 * i z odpowiedzi zalogowanego konta 01.10.2026.
 *
 * Logowanie: POST login/login/ z JSON {language, remember, username, password, appCode „B2B”, loginAs}; odpowiedź
 * {net_token, uidata: {bp, username, allowedFunctions…}}. Każde zapytanie API idzie jak w sklepie z nagłówkiem
 * „Authorization: Bearer {net_token}” i „sir-b2b-bp” (numer kontrahenta SAP w base64). Brak sesji (HTTP 401/403) = jedno
 * ponowne logowanie, nadal brak = B2bFatalException. Język konta: angielski — polskiego sklep nie ma (pliki generowane
 * przez sklep — karta techniczna, deklaracja zgodności — mają własną listę języków).
 *
 * Pliki (export/getDS, export/getCD) API oddaje jako JSON {file: base64}; zdjęcia (GetImage.ashx na sirweb) są publiczne,
 * a wyrób bez zdjęcia dostaje zawsze ten sam obrazek zastępczy. Zapytania idą po kolei, z przerwą przed każdym.
 */
final class SirB2bClient
{
    public const HOST = 'b2b.sirsafety.com';

    public const SHOP = 'https://b2b.sirsafety.com';

    public const API = 'https://app.sirsafety.com/sirapi/api/';

    public const IMAGE_BASE = 'https://sirweb.sirsafety.com/PortaleBridge_PRD/GetImage.ashx';

    /** Język konta jak w languageMap sklepu (environment) — angielski. */
    public const LANGUAGE = ['code' => 'EN', 'desc' => 'English', 'languageID' => 2, 'sapRawValue' => 'E'];

    private const SESSION_LOST = 'Utracono sesję konta b2b.sirsafety.com — ceny konta niedostępne';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    /** Przerwy po 429/503 i potknięciach sieci, gdy sklep nie podał Retry-After (ms). */
    private const BACKOFF_MS = [2000, 10000, 60000];

    private const MAX_RETRY_AFTER_MS = 120_000;

    /** Karta techniczna PDF powstaje w sklepie na żądanie — 5–10 s na wyrób. */
    private const TIMEOUT_SECONDS = 120;

    /** Wyrób, którego w sklepie nie ma — jego „zdjęcie” to obrazek zastępczy. */
    private const PLACEHOLDER_PROBE_ID = 'ZZZ0000000';

    private ?string $token = null;

    private string $bp = '';

    /** @var string|false|null md5 obrazka zastępczego; null = jeszcze nie pobrany, false = nie udało się go pobrać */
    private string|false|null $placeholderHash = null;

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
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    public function login(): void
    {
        $this->token = null;
        $this->bp = '';

        // sklep wysyła login małymi literami i bez odstępów na brzegach (doLogin w skrypcie sklepu)
        $username = mb_strtolower(trim($this->username));
        if ($username === '') {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: konto nie ma loginu (numer klienta SIR)');
        }

        $body = (string) json_encode([
            'language' => self::LANGUAGE,
            'remember' => false,
            'username' => $username,
            'password' => trim($this->password),
            'appCode' => 'B2B',
            'loginAs' => '',
        ]);
        try {
            $response = $this->send(
                fn (PendingRequest $http): Response => $http->withBody($body, 'application/json')->post(self::API.'login/login/'),
                allowClientErrors: true,
            );
        } catch (B2bFatalException $e) {
            throw $e;
        } catch (RuntimeException $e) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: '.$e->getMessage(), 0, $e);
        }

        $json = self::decode(self::bodyOf($response));
        $token = is_string($json['net_token'] ?? null) ? trim($json['net_token']) : '';
        $bp = is_scalar($json['uidata']['bp'] ?? null) ? trim((string) $json['uidata']['bp']) : '';
        if (! $response->successful() || $token === '' || $bp === '') {
            $message = is_scalar($json['message'] ?? null) ? trim((string) $json['message']) : '';

            throw new RuntimeException(
                'Logowanie do '.self::HOST.' nieudane (HTTP '.$response->status().($message !== '' ? ', '.$message : '').') — sprawdź login (numer klienta) i hasło'
            );
        }

        $this->token = $token;
        $this->bp = $bp;
    }

    public function isLoggedIn(): bool
    {
        return $this->token !== null;
    }

    /**
     * Strona listy wyrobów konta — wyszukiwanie zaawansowane bez filtrów, jak w sklepie (product/searchProduct/ POST,
     * tylko wyroby w sprzedaży: IN_STOCK „S”, IN_MAT_AVAILABLE „X”): {TOTAL, OUT_DATA: [{MATNR, MAKTX, BISMT, MATKL,
     * WGHIER_D, COLOR}]}.
     *
     * @return array<string, mixed>
     */
    public function listPage(int $skip, int $take): array
    {
        return $this->apiJson('POST', 'product/searchProduct/', [
            'IN_MATNR' => '',
            'IN_BISMT' => '',
            'IN_MAKTX' => '',
            'IN_MATKL' => '',
            'IN_CLASS' => '',
            'IN_STOCK' => 'S',
            'IN_PORTAL' => 'S',
            'IN_SEARCHBAR' => '',
            'IN_LANG' => self::LANGUAGE['sapRawValue'],
            'IN_SKIP' => $skip,
            'IN_TAKE' => $take,
            'IN_APP_CODE' => 'B2B',
            'IN_BP' => $this->bp,
            'IN_MAT_AVAILABLE' => 'X',
            'IN_B2BPM_CODE' => '',
            'SO_MAT_RANGE' => [],
            'IN_STOCK_YES' => '',
            'IN_PRTPM_CODE' => '',
            'normIDList' => null,
        ], 'lista wyrobów od '.$skip);
    }

    /**
     * Wyrób z wariantami (product/product/): wiersz wyrobu (ATTYP 00/01) i wiersze wariantów kolor × rozmiar (ATTYP 02);
     * pierwszy wiersz niesie ColorList, PriceList (ceny konta wariantów) i MOQGrouped.
     *
     * @return list<array<string, mixed>>
     */
    public function product(string $fatherId): array
    {
        $json = $this->apiJson('GET', 'product/product/', [
            'filters' => self::json([['type' => '=', 'field' => 'SATNR', 'value' => $fatherId]]),
            'sorters' => self::json([['dir' => 'asc', 'field' => 'COLOR'], ['dir' => 'asc', 'field' => 'SIZE_ORDER']]),
        ], 'wyrób '.$fatherId);

        return array_values(array_filter(is_array($json['data'] ?? null) ? $json['data'] : [], 'is_array'));
    }

    /**
     * Teksty wyrobu w języku konta: {shortDescription, longDescription, warningText}.
     *
     * @return array<string, mixed>
     */
    public function texts(string $fatherId): array
    {
        return $this->apiJson('GET', 'product/productTexts/', ['fatherID' => $fatherId], 'teksty wyrobu '.$fatherId);
    }

    /**
     * Normy wyrobu z poziomami (product/productNorms, jak zakładka norm w sklepie): [{IDNorma, TestoNorma,
     * PerformanceList: [{Name, Description, PerformanceValue}]}].
     *
     * @return list<array<string, mixed>>
     */
    public function norms(string $fatherId): array
    {
        $json = $this->apiJson('GET', 'product/productNorms', [
            'filters' => self::json([['type' => '=', 'field' => 'IDProdottoPadre', 'value' => $fatherId]]),
            'fieldsToSelect' => self::json([['name' => 'DISTINCT IDProdottoPadre'], ['name' => 'IDNorma'], ['name' => 'TestoNorma']]),
        ], 'normy wyrobu '.$fatherId);

        return array_values(array_filter(is_array($json['data'] ?? null) ? $json['data'] : [], 'is_array'));
    }

    /**
     * Dostępność wariantów koloru (product/availability/): wiersze {MATNR, DAT00 „RRRRMMDD”, MNG02} — ilość możliwa do
     * wysłania na dany tydzień.
     *
     * @return list<array<string, mixed>>
     */
    public function availability(string $fatherColorId): array
    {
        $json = $this->apiJson('GET', 'product/availability/', ['fatherColorID' => $fatherColorId], 'dostępność '.$fatherColorId);

        return array_values(array_filter(is_array($json['data'] ?? null) ? $json['data'] : [], 'is_array'));
    }

    /**
     * Słownik działów (CLASS → nazwa) i grup towarowych (MATKL → nazwa) z SAP, po angielsku.
     *
     * @return array{classes: array<string, string>, groups: array<string, string>}
     */
    public function merchandiseGroups(): array
    {
        $json = $this->apiJson('GET', 'product/merchandiseGroups', [], 'grupy towarowe');
        $sets = is_array($json['data'] ?? null) ? array_values($json['data']) : [];
        $read = static function (mixed $set): array {
            $out = [];
            foreach (is_array($set['F4Set']['results'] ?? null) ? $set['F4Set']['results'] : [] as $row) {
                $code = is_array($row) && is_scalar($row['Atwrt'] ?? null) ? trim((string) $row['Atwrt']) : '';
                $name = is_array($row) && is_scalar($row['Atwtb'] ?? null) ? trim((string) $row['Atwtb']) : '';
                if ($code !== '' && $name !== '') {
                    $out[$code] = $name;
                }
            }

            return $out;
        };

        return ['classes' => $read($sets[0] ?? null), 'groups' => $read($sets[1] ?? null)];
    }

    /**
     * Plik generowany przez sklep z adresu documentUrl(): karta techniczna (export/getDS) albo deklaracja zgodności
     * (export/getCD) — PDF z pola „file” odpowiedzi.
     *
     * @return array{bytes: string, mime: string}
     */
    public function documentBytes(string $url): array
    {
        $parts = parse_url($url);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        if (! str_starts_with($url, self::API.'export/') || ! in_array($path, ['/sirapi/api/export/getDS', '/sirapi/api/export/getCD'], true)) {
            throw new RuntimeException('adres pliku spoza '.self::API.'export/: '.$url);
        }
        parse_str((string) ($parts['query'] ?? ''), $query);
        $json = $this->apiJson('GET', ltrim(substr($path, strlen('/sirapi/api/')), '/'), $query, 'plik '.basename($path));
        $bytes = base64_decode(is_string($json['file'] ?? null) ? $json['file'] : '', true);
        if (! is_string($bytes) || ! str_starts_with($bytes, '%PDF')) {
            throw new RuntimeException('sklep nie wydał pliku PDF ('.basename($path).')');
        }

        return ['bytes' => $bytes, 'mime' => 'application/pdf'];
    }

    /**
     * Zdjęcie wyrobu albo koloru (id = kod wyrobu z kodem koloru); null = sklep oddał obrazek zastępczy albo nie obraz.
     *
     * @return array{bytes: string, mime: string}|null
     */
    public function imageBytes(string $url): ?array
    {
        if (! self::isImageUrl($url)) {
            throw new RuntimeException('adres zdjęcia spoza '.self::IMAGE_BASE.': '.$url);
        }
        $file = $this->publicFile($url);
        if ($file === null) {
            return null;
        }
        $placeholder = $this->placeholderHash();
        if ($placeholder !== false && md5($file['bytes']) === $placeholder) {
            return null;
        }

        return $file;
    }

    /** Adres zdjęcia wyrobu albo koloru („MA1113B0”) — publiczny, bez sesji. */
    public static function imageUrl(string $id): string
    {
        return self::IMAGE_BASE.'?id='.rawurlencode($id);
    }

    public static function isImageUrl(string $url): bool
    {
        return str_starts_with($url, self::IMAGE_BASE.'?id=') && ! str_contains($url, '&');
    }

    /**
     * Adres pliku generowanego przez sklep — zapisywany jako źródło (bez tokenu); documentBytes() pobiera spod niego plik.
     *
     * @param  array<string, string|int>  $query
     */
    public static function documentUrl(string $endpoint, array $query): string
    {
        return self::API.'export/'.$endpoint.'?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /** Strona wyrobu w sklepie (trasa b2b/product/{kod} aplikacji). */
    public static function productUrl(string $fatherId): string
    {
        return self::SHOP.'/b2b/product/'.rawurlencode($fatherId);
    }

    private function placeholderHash(): string|false
    {
        if ($this->placeholderHash === null) {
            try {
                $file = $this->publicFile(self::imageUrl(self::PLACEHOLDER_PROBE_ID));
                $this->placeholderHash = $file !== null ? md5($file['bytes']) : false;
            } catch (B2bFatalException $e) {
                throw $e;
            } catch (RuntimeException) {
                $this->placeholderHash = false;
            }
        }

        return $this->placeholderHash;
    }

    /**
     * @return array{bytes: string, mime: string}|null
     */
    private function publicFile(string $url): ?array
    {
        $response = $this->send(static fn (PendingRequest $http): Response => $http->accept('image/*')->get($url));
        $bytes = self::bodyOf($response);
        $mime = strtolower(trim(explode(';', (string) $response->header('Content-Type'))[0]));

        return $bytes !== '' && str_starts_with($mime, 'image/') ? ['bytes' => $bytes, 'mime' => $mime] : null;
    }

    /**
     * JSON z API konta. Brak sesji (HTTP 401/403) = jedno ponowne logowanie; nadal brak — B2bFatalException (dalsze
     * zapytania nie miałyby cen konta).
     *
     * @param  array<string, mixed>  $data  zapytanie GET albo treść POST
     * @return array<mixed>
     */
    private function apiJson(string $method, string $path, array $data, string $label): array
    {
        if ($this->token === null) {
            $this->relogin();
        }
        $call = function (PendingRequest $http) use ($method, $path, $data): Response {
            $http = $this->withAuth($http);

            return $method === 'POST'
                ? $http->withBody((string) json_encode($data), 'application/json')->post(self::API.$path)
                : $http->get(self::API.$path, $data);
        };
        $response = $this->send($call, allowUnauthorized: true);
        if (in_array($response->status(), [401, 403], true)) {
            $this->relogin();
            $response = $this->send($call, allowUnauthorized: true);
            if (in_array($response->status(), [401, 403], true)) {
                $this->token = null;

                throw new B2bFatalException(self::SESSION_LOST.' ('.$label.' bez sesji konta po ponownym logowaniu)');
            }
        }
        $json = json_decode(self::bodyOf($response), true);
        if (! is_array($json)) {
            throw new RuntimeException($label.': odpowiedź sklepu to nie JSON');
        }

        return $json;
    }

    private function withAuth(PendingRequest $http): PendingRequest
    {
        return $http->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'sir-b2b-bp' => base64_encode($this->bp),
        ]);
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
     * @param  array<mixed>  $value
     */
    private static function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<mixed>
     */
    private static function decode(string $body): array
    {
        $json = json_decode($body, true);

        return is_array($json) ? $json : [];
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
     * @param  bool  $allowUnauthorized  HTTP 401/403 wraca do wołającego (ponowne logowanie), nie jest błędem
     * @param  bool  $allowClientErrors  każda odpowiedź 4xx wraca do wołającego (logowanie: złe hasło)
     */
    private function send(callable $call, bool $allowUnauthorized = false, bool $allowClientErrors = false): Response
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
                    'Accept' => 'application/json, text/plain, */*',
                    'Origin' => self::SHOP,
                    'Referer' => self::SHOP.'/',
                ])->withOptions(['allow_redirects' => ['max' => 5]]));
            } catch (ConnectionException $e) {
                $error = 'brak połączenia ('.$e->getMessage().')';
            }

            if ($response !== null && ($response->successful()
                || ($allowUnauthorized && in_array($response->status(), [401, 403], true))
                || ($allowClientErrors && $response->clientError() && $response->status() !== 429))) {
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

            $error ??= 'sklep '.self::HOST.' odpowiedział HTTP '.$response?->status();
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
