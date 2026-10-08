<?php

declare(strict_types=1);

namespace App\Services\B2b;

use Closure;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Throwable;

/**
 * Zdjęcia VM Footwear bez tła (PNG) z folderu producenta na SharePoincie — link „Do pobrania” ze strony głównej
 * sklepu B2B. Sprawdzone 08.10.2026: link to anonimowe udostępnienie folderu „Sortiment”
 * („https://vmfootwearcz-my.sharepoint.com/:f:/g/personal/share_vmfootwear_cz/…”). GET bez przekierowań oddaje 302
 * z ciasteczkiem FedAuth i adresem onedrive.aspx?id=/personal/share_vmfootwear_cz/Documents/Sortiment; z tym
 * ciasteczkiem działa REST SharePointu (bez niego 403).
 *
 * Układ: Sortiment/{obuv - shoes|rukavice - gloves|prislusenstvi - accessories}/{kod}_{NAZWA}/ — w folderze modelu
 * PNG bez tła (kilka ujęć), JPG z białym i czarnym tłem, PDF. Folder bywa pomylony (w „8015-O6_DERBY” leży
 * „8010-S7L LIVERPOOL_bottom.png”), a jeden plik bywa wspólny dla kilku wersji („2880_O1_S1_S3.png”) — o tym, czyj
 * jest plik, decyduje jego nazwa, nie folder.
 *
 * Zdjęcia są dodatkiem do zdjęcia ze sklepu: żaden błąd SharePointu nie jest B2bFatalException.
 */
final class VmFootwearSharePoint
{
    /** Działy folderu „Sortiment” z wyrobami (pozostałe: katalogi, normy, materiały marketingowe). */
    public const SECTIONS = ['obuv - shoes', 'rukavice - gloves', 'prislusenstvi - accessories'];

    /** Najwięcej ujęć PNG na kartę. */
    public const MAX_IMAGES = 6;

    private const TIMEOUT_SECONDS = 60;

    private const LIST_BUDGET_SECONDS = 10 * 60;

    private const FILE_MAX_BYTES = 30_000_000;

    private const MAX_CONSECUTIVE_FAILURES = 5;

    /** Przerwy po 429/503 i zerwanym połączeniu (ms). */
    private const BACKOFF_MS = [2000, 10000];

    /** Ujęcia w kolejności galerii: widok ¾ z przodu najpierw, podeszwa na końcu. */
    private const VIEW_ORDER = ['' => 0, 'image01' => 1, 'right' => 20, 'top' => 21, 'bottom' => 22];

    /**
     * Dopisek ujęcia na końcu nazwy (po małych literach): „_image_01”, „_02”, „_1”, „_right”, „_top”, „_bottom”.
     * Numer ujęcia to jedna cyfra albo „0X” — „4495_60”, „4095_25” to wersje (podnosek, wysokość), nie ujęcia.
     */
    private const VIEW_SUFFIX = '/[ _-](image[ _-]?01|right|top|bottom|0?\d)$/';

    private CookieJar $jar;

    /** Origin udostępnienia („https://vmfootwearcz-my.sharepoint.com”). */
    private string $origin = '';

    /** Witryna („/personal/share_vmfootwear_cz”). */
    private string $site = '';

    /** Folder udostępnienia („/personal/share_vmfootwear_cz/Documents/Sortiment”). */
    private string $root = '';

    /** Link udostępnienia — do ponownego otwarcia po wygaśnięciu ciasteczka. Nie trafia do logów ani podsumowań. */
    private string $shareUrl = '';

    private int $consecutiveFailures = 0;

    /** @var Closure(int): void */
    private Closure $sleep;

    /**
     * @param  (Closure(int): void)|null  $sleep  pauza w ms (w testach bez czekania)
     */
    public function __construct(private readonly int $delayMs = 150, ?Closure $sleep = null)
    {
        $this->jar = new CookieJar;
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
    }

    /**
     * Link udostępnienia folderu ze strony sklepu: `<a>` na https://*.sharepoint.com/:f:/… (przycisk „Do pobrania”).
     */
    public static function shareLinkFrom(string $html): ?string
    {
        if (preg_match_all('/<a\b[^>]*\bhref\s*=\s*"([^"]+)"/i', $html, $m) < 1) {
            return null;
        }
        foreach ($m[1] as $href) {
            $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $parts = parse_url($href);
            if (is_array($parts) && strtolower($parts['scheme'] ?? '') === 'https'
                && self::isSharePointHost((string) ($parts['host'] ?? ''))
                && ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['port'])
                && str_starts_with((string) ($parts['path'] ?? ''), '/:f:/')) {
                return $href;
            }
        }

        return null;
    }

    /**
     * Otwiera udostępnienie: ciasteczko dostępu i folder główny z przekierowania linku.
     */
    public function open(string $shareUrl): void
    {
        $host = strtolower((string) parse_url($shareUrl, PHP_URL_HOST));
        if (! self::isSharePointHost($host)) {
            throw new RuntimeException('link „Do pobrania” spoza SharePointu ('.$host.')');
        }
        $this->shareUrl = $shareUrl;
        $this->jar = new CookieJar;
        $response = $this->send(static fn (PendingRequest $http): Response => $http->withOptions(['allow_redirects' => false])->get($shareUrl), okRedirect: true);
        $location = (string) $response->header('Location');
        if ($response->status() < 300 || $response->status() >= 400 || $location === '') {
            throw new RuntimeException('link udostępnienia nie przekierował do folderu (HTTP '.$response->status().') — wygasł albo zmienił się');
        }
        if (strtolower((string) parse_url($location, PHP_URL_HOST)) !== $host) {
            throw new RuntimeException('link udostępnienia przekierował poza '.$host);
        }
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $root = rtrim(is_string($query['id'] ?? null) ? $query['id'] : '', '/');
        if (preg_match('#^(/(?:personal|sites|teams)/[^/]+)/.+#', $root, $m) !== 1 || str_contains($root, '..')) {
            throw new RuntimeException('przekierowanie udostępnienia bez folderu (id): '.$location);
        }

        $this->origin = 'https://'.$host;
        $this->site = $m[1];
        $this->root = $root;
    }

    /**
     * PNG z folderów modeli wszystkich działów (bez podfolderów typu „images_360”).
     *
     * @return list<array{folder: string, name: string, path: string, size: int}>
     */
    public function pngIndex(): array
    {
        if ($this->root === '') {
            throw new RuntimeException('udostępnienie nieotwarte');
        }
        $started = microtime(true);
        $out = [];
        foreach (self::SECTIONS as $section) {
            foreach ($this->listing($this->root.'/'.$section, 'Folders') as $folder) {
                if (microtime(true) - $started > self::LIST_BUDGET_SECONDS) {
                    throw new RuntimeException('lista folderów SharePointu trwa ponad '.(self::LIST_BUDGET_SECONDS / 60).' min');
                }
                $name = (string) ($folder['Name'] ?? '');
                if ($name === '') {
                    continue;
                }
                foreach ($this->listing($this->root.'/'.$section.'/'.$name, 'Files') as $file) {
                    $fileName = (string) ($file['Name'] ?? '');
                    $path = (string) ($file['ServerRelativeUrl'] ?? '');
                    $size = (int) ($file['Length'] ?? 0);
                    if (! str_ends_with(strtolower($fileName), '.png') || ! str_starts_with($path, $this->root.'/') || $size > self::FILE_MAX_BYTES) {
                        continue;
                    }
                    $out[] = ['folder' => $name, 'name' => $fileName, 'path' => $path, 'size' => $size];
                }
            }
        }

        return $out;
    }

    /**
     * Bajty pliku spod adresu zapisanego przez fileUrl().
     *
     * @return array{bytes: string, mime: string}
     */
    public function fileBytes(string $url): array
    {
        $path = $this->pathFromUrl($url);
        // limit w trakcie pobierania (nagłówek albo pobrane bajty), nie po wczytaniu całości do pamięci
        $tooLarge = static function (int $bytes) use ($url): void {
            if ($bytes > self::FILE_MAX_BYTES) {
                throw new B2bFileTooLargeException('plik ponad '.intdiv(self::FILE_MAX_BYTES, 1_000_000).' MB pominięty: '.$url);
            }
        };
        $response = $this->send(fn (PendingRequest $http): Response => $http->accept('*/*')->withOptions([
            'on_headers' => static function (ResponseInterface $response) use ($tooLarge): void {
                $length = $response->getHeaderLine('Content-Length');
                if (ctype_digit($length)) {
                    $tooLarge((int) $length);
                }
            },
            'progress' => static function (int $total, int $downloaded) use ($tooLarge): void {
                $tooLarge($downloaded);
            },
        ])->get(
            $this->origin.$this->site."/_api/web/GetFileByServerRelativeUrl('".self::odataPath($path)."')/\$value"
        ));
        $bytes = $response->body();
        $tooLarge(strlen($bytes));
        if (! str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            throw new RuntimeException('SharePoint nie wydał PNG: '.$url);
        }

        return ['bytes' => $bytes, 'mime' => 'image/png'];
    }

    /** Stały adres pliku do zapisu przy zdjęciu karty (bez ciasteczka i tokenu). */
    public function fileUrl(string $path): string
    {
        return $this->origin.implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    public function isFileUrl(string $url): bool
    {
        return $this->origin !== '' && str_starts_with($url, $this->origin.'/');
    }

    /**
     * PNG dla kodu karty: najpierw pliki z dokładnym kodem w nazwie („6580_O2.png” dla 6580-O2, „2880_O1_S1_S3.png”
     * dla 2880-S3), bez nich — zestaw ujęć innej wersji tego modelu o nazwie najbliższej kodowi karty (karta 6655-O6,
     * zdjęcia „6655-O2 ACCRA_01.png”; decyzja właściciela 08.10.2026). Plik innego modelu w folderze („8010-S7L
     * LIVERPOOL” w folderze 8015-O6) nie należy do karty; zestaw bez ujęcia z przodu nie wchodzi wcale.
     *
     * @param  list<array{folder: string, name: string, path: string, size: int}>  $index
     * @return array{paths: list<string>, exact: bool}
     */
    public static function imagesFor(string $code, array $index): array
    {
        [$number, $version] = self::splitCode($code);
        if ($number === '') {
            return ['paths' => [], 'exact' => false];
        }
        $own = array_values(array_filter($index, static fn (array $f): bool => self::fileModel($f['name']) === $number));
        $exact = array_values(array_filter($own, static function (array $f) use ($number, $version): bool {
            if ($version === '') {
                return true;
            }
            $stem = self::stem($f['name']);
            // wersja jako osobny człon („2880_O1_S1_S3”) albo zaraz po numerze, także porozdzielana („2295_S3_ESD_BOA”
            // dla S3ESD) — do granicy członu: „2880_S3W” to nie S3
            $spread = implode('[^A-Z0-9]*', array_map(static fn (string $c): string => preg_quote($c, '/'), str_split($number.$version)));

            return in_array($version, self::tokens($stem), true) || preg_match('/^'.$spread.'(?![A-Z0-9])/', strtoupper($stem)) === 1;
        }));
        if ($exact !== [] && self::hasFrontView($exact)) {
            return ['paths' => self::ordered($exact), 'exact' => true];
        }

        // Inna wersja: zestaw ujęć (nazwa pliku bez dopisku ujęcia) o najdłuższym wspólnym początku z kodem karty
        // (6655-O6 → „6655-O2 ACCRA”); remis — mniejsza odległość edycyjna (2295-S3LBOA → „2295_S3_ESD_BOA”, nie
        // „2295_S3ESD”), więcej ujęć, alfabetycznie. Zestaw bez ujęcia z przodu (sama podeszwa, góra, bok) nie
        // wchodzi: zdjęciem głównym karty byłaby podeszwa.
        $target = $number.$version;
        $sets = [];
        foreach ($own as $file) {
            $sets[self::normal(self::setName($file['name']))][] = $file;
        }
        $rank = static fn (string $key): array => [-self::commonPrefix($key, $target), levenshtein($key, $target), -count($sets[$key]), $key];
        $keys = array_keys($sets);
        usort($keys, static fn (string $a, string $b): int => $rank($a) <=> $rank($b));
        foreach ($keys as $key) {
            if (self::hasFrontView($sets[$key])) {
                return ['paths' => self::ordered($sets[$key]), 'exact' => false];
            }
        }

        return ['paths' => [], 'exact' => false];
    }

    /**
     * Kod karty → numer modelu i wersja: „6655-O6” → [6655, O6], „E1/15LI” → [E1, 15LI], „1020R” → [1020R, ''].
     *
     * @return array{0: string, 1: string}
     */
    public static function splitCode(string $code): array
    {
        $code = strtoupper(trim($code));
        if (preg_match('/^([A-Z0-9]+)(?:[^A-Z0-9]+(.*))?$/', $code, $m) !== 1) {
            return ['', ''];
        }

        return [$m[1], self::normal($m[2] ?? '')];
    }

    /** Numer modelu z nazwy pliku: pierwszy blok nazwy („6655-O2 ACCRA_01.png” → 6655, „8010-S7L LIVERPOOL” → 8010). */
    private static function fileModel(string $name): string
    {
        return self::tokens(self::stem($name))[0] ?? '';
    }

    /**
     * @param  list<array{folder: string, name: string, path: string, size: int}>  $files
     * @return list<string>
     */
    private static function ordered(array $files): array
    {
        usort($files, static fn (array $a, array $b): int => [self::viewRank($a['name']), strtolower($a['name'])] <=> [self::viewRank($b['name']), strtolower($b['name'])]);
        // ten sam plik pod dwiema nazwami („X.png” i „X_image_01.png”, ta sama wielkość) — raz: inaczej zapis zdjęć
        // scala je po sumie kontrolnej i przepisuje adres, a każdy przebieg pobierałby drugi plik od nowa
        $seen = [];
        $out = [];
        foreach ($files as $file) {
            $key = strtolower($file['name']);
            $sizeKey = 'size:'.$file['size'];
            if (isset($seen[$key]) || ($file['size'] > 0 && isset($seen[$sizeKey]))) {
                continue;
            }
            $seen[$key] = $seen[$sizeKey] = true;
            $out[] = $file['path'];
        }

        return array_slice($out, 0, self::MAX_IMAGES);
    }

    /**
     * Zestaw ma ujęcie wyrobu (bez dopisku, image_01, 01…) — nie tylko bok, górę albo podeszwę.
     *
     * @param  list<array{folder: string, name: string, path: string, size: int}>  $files
     */
    private static function hasFrontView(array $files): bool
    {
        foreach ($files as $file) {
            if (self::viewRank($file['name']) < self::VIEW_ORDER['right']) {
                return true;
            }
        }

        return false;
    }

    /** Nazwa pliku bez dopisku ujęcia: „6655-O2 ACCRA_image_01.png” → „6655-O2 ACCRA”. */
    private static function setName(string $name): string
    {
        return (string) preg_replace(self::VIEW_SUFFIX, '', strtolower(self::stem($name)));
    }

    private static function commonPrefix(string $a, string $b): int
    {
        $length = min(strlen($a), strlen($b));
        $i = 0;
        while ($i < $length && $a[$i] === $b[$i]) {
            $i++;
        }

        return $i;
    }

    /** Miejsce ujęcia w galerii z dopisku po kodzie: brak / image_01 / 01, 02… / right / top / bottom / reszta. */
    private static function viewRank(string $name): int
    {
        if (preg_match(self::VIEW_SUFFIX, strtolower(self::stem($name)), $m) !== 1) {
            return 0;
        }
        $view = str_replace([' ', '_', '-'], '', $m[1]);
        if (ctype_digit($view)) {
            return 1 + (int) $view;
        }

        return self::VIEW_ORDER[$view] ?? 30;
    }

    private static function stem(string $name): string
    {
        return (string) preg_replace('/\.png$/i', '', $name);
    }

    /** @return list<string> */
    private static function tokens(string $text): array
    {
        return array_values(array_filter(preg_split('/[^A-Z0-9]+/', strtoupper($text)) ?: [], static fn (string $t): bool => $t !== ''));
    }

    private static function normal(string $text): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($text));
    }

    private static function isSharePointHost(string $host): bool
    {
        return preg_match('/^[a-z0-9-]+(?:\.[a-z0-9-]+)*\.sharepoint\.com$/', strtolower($host)) === 1;
    }

    private function pathFromUrl(string $url): string
    {
        if (! $this->isFileUrl($url)) {
            throw new RuntimeException('adres spoza udostępnienia SharePointu: '.$url);
        }
        $path = rawurldecode((string) parse_url($url, PHP_URL_PATH));
        if (! str_starts_with($path, $this->root.'/') || str_contains($path, '..')) {
            throw new RuntimeException('plik spoza folderu udostępnienia: '.$url);
        }

        return $path;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listing(string $path, string $kind): array
    {
        $response = $this->send(fn (PendingRequest $http): Response => $http->accept('application/json;odata=nometadata')->get(
            $this->origin.$this->site."/_api/web/GetFolderByServerRelativeUrl('".self::odataPath($path)."')/".$kind
        ));
        $value = $response->json('value');
        if (! is_array($value)) {
            throw new RuntimeException('SharePoint bez listy '.$kind.' dla '.$path);
        }

        return array_values(array_filter($value, 'is_array'));
    }

    /** Ścieżka w literale OData: apostrof podwojony, potem kodowanie adresu. */
    private static function odataPath(string $path): string
    {
        return rawurlencode(str_replace("'", "''", $path));
    }

    /**
     * Zapytanie z pauzą. 429/503 i zerwane połączenie — ponowienie po przerwie; 401/403 w trakcie przebiegu (wygasłe
     * ciasteczko FedAuth) — jedno ponowne otwarcie udostępnienia. Po MAX_CONSECUTIVE_FAILURES błędach z rzędu
     * SharePoint jest wyłączony do końca przebiegu (każda karta czekałaby inaczej na kolejne limity czasu).
     *
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call, bool $okRedirect = false, bool $reopened = false): Response
    {
        if ($this->consecutiveFailures >= self::MAX_CONSECUTIVE_FAILURES) {
            throw new RuntimeException('SharePoint VM wyłączony do końca przebiegu po '.self::MAX_CONSECUTIVE_FAILURES.' błędach z rzędu');
        }
        $retries = 0;
        while (true) {
            if ($this->delayMs > 0) {
                ($this->sleep)($this->delayMs);
            }
            $response = null;
            $error = null;
            try {
                // bez przekierowań: API SharePointu odpowiada wprost; 3xx = błąd, nie wycieczka na inny host
                $response = $call(Http::timeout(self::TIMEOUT_SECONDS)->withOptions(['cookies' => $this->jar, 'allow_redirects' => false]));
            } catch (Throwable $e) {
                // przerwane pobranie za dużego pliku (on_headers/progress) — bez ponawiania i bez liczenia do serii
                // błędów; Guzzle opakowuje nasz wyjątek, więc szukamy go w łańcuchu
                $tooLarge = B2bFileTooLargeException::in($e);
                if ($tooLarge !== null) {
                    throw new RuntimeException($tooLarge->getMessage(), 0, $e);
                }
                if (! $e instanceof ConnectionException) {
                    throw $e;
                }
                $error = 'brak połączenia ('.$e->getMessage().')';
            }
            if ($response !== null && ($response->successful() || ($okRedirect && $response->redirect()))) {
                $this->consecutiveFailures = 0;

                return $response;
            }
            if (($response === null || in_array($response->status(), [429, 503], true)) && $retries < count(self::BACKOFF_MS)) {
                ($this->sleep)(self::BACKOFF_MS[$retries]);
                $retries++;

                continue;
            }
            if ($response !== null && in_array($response->status(), [401, 403], true) && ! $reopened && ! $okRedirect && $this->shareUrl !== '') {
                $this->open($this->shareUrl);

                return $this->send($call, $okRedirect, true);
            }
            $this->consecutiveFailures++;

            throw new RuntimeException('SharePoint VM: '.($error ?? 'HTTP '.$response?->status()));
        }
    }
}
