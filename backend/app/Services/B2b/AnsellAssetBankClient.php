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
 * Portal zdjęć Ansella assetbank.ansell.com (Bright Asset Bank 2026.8) — konto dystrybutora (B2bAccount, np. #37).
 * Nieoficjalne: formularze i adresy odczytane ze stron zalogowanego konta 06.10.2026.
 *
 * Logowanie: GET action/viewLogin (token CSRF w formularzu) → POST action/login (CSRF, ssoPluginName=none, username,
 * password). Sesja w ciasteczkach; strona zalogowanego konta ma odnośnik action/logout.
 * Wyszukiwanie: POST action/search z polami formularza zaawansowanego (attribute_3 = tytuł zawiera,
 * attribute_739 = podkategoria pliku). Lista wyników podaje tytuł, podkategorię i regiony; prawa użycia tylko strona
 * pliku (viewAsset). Pobranie w rozmiarze: viewDownloadImage → POST downloadImage (JPG, szerokość) → strona podglądu
 * z ../servlet/display?file=… → plik. Formularz pobrania zaznacza akceptację warunków portalu (conditionsAccepted=1) —
 * zgoda właściciela 06.10.2026; warunki pozwalają dystrybutorom używać zdjęć do sprzedaży wyrobów Ansella bez zmian.
 */
final class AnsellAssetBankClient
{
    public const HOST = 'assetbank.ansell.com';

    public const BASE = 'https://assetbank.ansell.com/assetbank-ansell';

    /** Podkategoria packshotów w portalu (obok „Product images - detail” i „- 360”). */
    public const STATIC_PRODUCT_IMAGES = 'Product images - static';

    private const LOGGED_IN_MARKER = 'action/logout';

    private const SESSION_LOST = 'Utracono sesję konta assetbank.ansell.com';

    private const MAX_CONSECUTIVE_FAILURES = 20;

    private const BACKOFF_MS = [2000, 10000, 60000];

    /** Odstępy kolejnych prób pobrania pliku, który portal jeszcze przelicza. */
    private const CONVERSION_WAIT_MS = [3000, 10000, 30000];

    private const TIMEOUT_SECONDS = 90;

    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36';

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
        private readonly int $delayMs = 1000,
        ?Closure $sleep = null,
    ) {
        $this->jar = new CookieJar;
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    public function login(): void
    {
        $this->jar = new CookieJar;
        $this->loggedIn = false;
        if (trim($this->username) === '') {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: konto nie ma loginu');
        }

        $form = self::bodyOf($this->send(static fn (PendingRequest $http): Response => $http->get(self::BASE.'/action/viewLogin')));
        $home = self::bodyOf($this->send(fn (PendingRequest $http): Response => $http->asForm()->post(self::BASE.'/action/login', [
            'CSRF' => self::csrf($form),
            'ssoPluginName' => 'none',
            'forwardUrl' => '',
            'loggedOut' => 'false',
            'username' => trim($this->username),
            'password' => $this->password,
            'submit' => 'Login',
        ])));
        if (! str_contains($home, self::LOGGED_IN_MARKER)) {
            throw new RuntimeException('Logowanie do '.self::HOST.' nieudane: portal nie przyjął loginu i hasła');
        }
        $this->loggedIn = true;
    }

    /**
     * Pliki z podkategorii $subcategory, których tytuł zawiera $titleNeedle — z listy wyników (bez strony pliku).
     *
     * @return list<array{id: int, title: string, subcategory: string, regions: list<string>}>
     */
    public function search(string $titleNeedle, string $subcategory = self::STATIC_PRODUCT_IMAGES): array
    {
        $form = $this->accountPage(self::BASE.'/action/viewSearch', 'wyszukiwanie');
        $html = $this->accountRequest(fn (PendingRequest $http): Response => $http->asForm()->post(self::BASE.'/action/search', [
            'CSRF' => self::csrf($form),
            'advancedSearch' => '1',
            'includeUnsearchable' => 'false',
            'attribute_3' => $titleNeedle,
            'attribute_739' => $subcategory,
            'orCategories' => 'true',
            'attributeQueryConjunction' => 'AND',
        ]), 'wyniki wyszukiwania');

        return self::parseResults($html);
    }

    /**
     * Prawa użycia i tytuł ze strony pliku.
     *
     * @return array{title: string, rights: string, regions: list<string>}
     */
    public function assetDetails(int $id): array
    {
        $text = self::plainText($this->accountPage(self::BASE.'/action/viewAsset?id='.$id, 'plik '.$id));

        return [
            'title' => self::field($text, 'Title'),
            'rights' => self::field($text, 'Usage Rights'),
            'regions' => self::splitRegions(self::field($text, 'Regional Exclusivity')),
        ];
    }

    /** JPG pliku w szerokości $width (portal przelicza sam; proporcje z oryginału). */
    public function downloadJpg(int $id, int $width = 1200): string
    {
        $page = $this->accountPage(self::BASE.'/action/viewDownloadImage?id='.$id.'&advanced=true', 'pobieranie pliku '.$id);
        if (preg_match('#<form[^>]*action="\.\./action/downloadImage"[^>]*>(.*?)</form>#is', $page, $form) !== 1) {
            throw new RuntimeException('Strona pobierania pliku '.$id.' bez formularza');
        }
        $data = [];
        preg_match_all('/<input[^>]*name="([^"]+)"[^>]*value="([^"]*)"/i', $form[1], $inputs, PREG_SET_ORDER);
        foreach ($inputs as $input) {
            $data[$input[1]] ??= html_entity_decode($input[2], ENT_QUOTES | ENT_HTML5);
        }
        unset($data['b_downloadOriginal']);
        $originalWidth = (int) ($data['width'] ?? 0);
        $originalHeight = (int) ($data['height'] ?? 0);
        $targetWidth = $originalWidth > 0 ? min($width, $originalWidth) : $width;
        $data['imageFormat'] = 'jpg';
        $data['rotationAngle'] = '0';
        $data['width'] = (string) $targetWidth;
        $data['height'] = (string) ($originalWidth > 0 ? max(1, (int) round($targetWidth * $originalHeight / $originalWidth)) : 0);
        $data['conditionsAccepted'] = '1';
        $data['b_download'] = 'Download';

        $preview = $this->accountRequest(
            static fn (PendingRequest $http): Response => $http->asForm()->post(self::BASE.'/action/downloadImage', $data),
            'podgląd pliku '.$id
        );
        if (preg_match('#servlet/display\?file=([^"&\s]+)#', $preview, $file) !== 1) {
            throw new RuntimeException('Portal nie przygotował pliku '.$id.' do pobrania');
        }
        // pliki warstwowe (PSD/TIFF — formularz z wyborem warstwy) portal przelicza dłużej: pierwsze pobranie podglądu
        // daje 20 bajtów z nagłówkiem image/jpeg, to samo po chwili — pełny JPG (06.10.2026: #48272, #284781, #44207)
        foreach ([0, ...self::CONVERSION_WAIT_MS] as $wait) {
            if ($wait > 0) {
                ($this->sleep)($wait);
            }
            $response = $this->send(static fn (PendingRequest $http): Response => $http->get(self::BASE.'/servlet/display?file='.$file[1]));
            $bytes = self::bodyOf($response);
            $size = @getimagesizefromstring($bytes);
            if (str_starts_with(strtolower((string) $response->header('Content-Type')), 'image/') && is_array($size) && ($size[0] ?? 0) >= 100) {
                return $bytes;
            }
        }

        throw new RuntimeException('Plik '.$id.' nie przyszedł jako obraz');
    }

    public static function assetUrl(int $id): string
    {
        return self::BASE.'/action/viewAsset?id='.$id;
    }

    /**
     * @return list<array{id: int, title: string, subcategory: string, regions: list<string>}>
     */
    public static function parseResults(string $html): array
    {
        $out = [];
        $seen = [];
        if (preg_match_all('#<ul class="panel__attributes">(.*?)</ul>#is', $html, $blocks) < 1) {
            return [];
        }
        foreach ($blocks[1] as $block) {
            if (preg_match('#viewAsset\?id=(\d+)[^"]*"[^>]*>\s*([^<]+?)\s*</a>#i', $block, $head) !== 1) {
                continue;
            }
            $id = (int) $head[1];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            preg_match_all('#<li>\s*([^<]*?)\s*</li>#i', $block, $items);
            $rest = array_values(array_filter(array_map(
                static fn (string $v): string => trim(html_entity_decode($v, ENT_QUOTES | ENT_HTML5)),
                $items[1]
            ), static fn (string $v): bool => $v !== '' && ! str_starts_with($v, 'Document Type')));
            $out[] = [
                'id' => $id,
                'title' => trim(html_entity_decode($head[2], ENT_QUOTES | ENT_HTML5)),
                'subcategory' => $rest[0] ?? '',
                'regions' => self::splitRegions($rest[1] ?? ''),
            ];
        }

        return $out;
    }

    /** @return list<string> */
    private static function splitRegions(string $value): array
    {
        if ($value === '' || $value === '-') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn (string $v): bool => $v !== ''));
    }

    private static function plainText(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)[^>]*>.*?</\1>#is', ' ', $html);

        return html_entity_decode((string) preg_replace('#<[^>]+>#', "\n", $html), ENT_QUOTES | ENT_HTML5);
    }

    /** Wartość pola ze strony pliku („Usage Rights” → „Unlimited Use”); bez pola — pusty tekst. */
    private static function field(string $text, string $label): string
    {
        if (preg_match('/\n\s*'.preg_quote($label, '/').'\s*\n(.*?)\n\s*Show more/su', $text, $m) !== 1) {
            return '';
        }

        return trim((string) preg_replace('/\s+/u', ' ', $m[1]));
    }

    private static function csrf(string $html): string
    {
        if (preg_match('/name="CSRF" value="([^"]+)"/', $html, $m) !== 1) {
            throw new RuntimeException('Strona '.self::HOST.' bez tokenu CSRF');
        }

        return $m[1];
    }

    private function accountPage(string $url, string $label): string
    {
        return $this->accountRequest(static fn (PendingRequest $http): Response => $http->get($url), $label);
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function accountRequest(callable $call, string $label): string
    {
        if (! $this->loggedIn) {
            $this->relogin();
        }
        $body = self::bodyOf($this->send($call));
        if (str_contains($body, self::LOGGED_IN_MARKER)) {
            return $body;
        }
        $this->relogin();
        $body = self::bodyOf($this->send($call));
        if (! str_contains($body, self::LOGGED_IN_MARKER)) {
            $this->loggedIn = false;

            throw new B2bFatalException(self::SESSION_LOST.' ('.$label.' bez konta po ponownym logowaniu)');
        }

        return $body;
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
                $response = $call(Http::timeout(self::TIMEOUT_SECONDS)
                    ->withHeaders(['User-Agent' => self::USER_AGENT])
                    ->withOptions(['cookies' => $this->jar, 'allow_redirects' => ['max' => 5]]));
            } catch (ConnectionException $e) {
                $error = 'brak połączenia ('.$e->getMessage().')';
            }
            if ($response !== null && $response->successful()) {
                $this->consecutiveFailures = 0;

                return $response;
            }
            if (($response === null || in_array($response->status(), [429, 502, 503], true)) && $retries < count(self::BACKOFF_MS)) {
                ($this->sleep)(self::BACKOFF_MS[$retries]);
                $retries++;

                continue;
            }
            $error ??= self::HOST.' odpowiedziało HTTP '.$response?->status();
            $this->consecutiveFailures++;
            if ($this->consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
                throw new B2bFatalException(self::MAX_CONSECUTIVE_FAILURES.' kolejnych błędów zapytań do '.self::HOST.' (ostatni: '.$error.')');
            }

            throw new RuntimeException($error);
        }
    }
}
