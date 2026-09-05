<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\CatalogPage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Buduje lokalny indeks kart produktu z sitemap producentów i hurtowni.
 * Dzięki niemu enrichment nie musi pytać wyszukiwarki o każdy z 30 tys. produktów.
 */
final class CatalogSitemapIndexer
{
    /** Ile plików sitemap z jednego indeksu przetwarzamy. */
    private const MAX_SITEMAP_FILES = 400;

    private const CHUNK_BYTES = 262144;

    private const MAX_BUFFER_BYTES = 2097152;

    /** Limit pobrania przez curl.exe, gdy Guzzle dostaje 403 od WAF. */
    private const CURL_MAX_BYTES = 20971520;

    /** Zgadywane ścieżki nie mogą zjeść całego --seconds na jednym 404. */
    private const CANDIDATE_TIMEOUT = 15;

    /** Sklepy bez XML (IAI) — ile stron HTML zbieramy z menu. */
    private const HTML_CRAWL_PAGES = 35;

    /** Poniżej tylu adresów z sitemapy dokładamy pełzanie po ładnych URL-ach kart. */
    private const SPARSE_SITEMAP_LIMIT = 50;

    /**
     * Sklepy za WAF-em odrzucają nagłówki botów, więc przedstawiamy się jak przeglądarka.
     * Chrome/124 (w dowolnym formacie: 124.0 albo 124.0.0.0) jest na czarnej liście części
     * WAF-ów (np. ox-on.com, HTTP 403) — to odcisk masowo kopiowanego UA ze starych
     * przykładów scraperów. Inny numer wersji (128) przechodzi bez problemu.
     */
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

    private const MAX_TOKEN_LENGTH = 64;

    private const MAX_TOKENS_PER_PAGE = 24;

    private const CANDIDATE_PATHS = [
        '/sitemap.xml',
        '/sitemap.xml.gz',
        // Magento 2 — robots często nie wskazuje mapy, a /sitemap.xml to 404
        '/media/sitemap.xml',
        '/pub/media/sitemap.xml',
        '/media/sitemap/sitemap.xml',
        '/media/sitemap/sitemap_en.xml',
        '/media/sitemap/sitemap_pl.xml',
        '/media/sitemap/sitemap_de.xml',
        '/sitemap_index.xml',
        '/sitemap-index.xml',
        '/sitemapindex.xml',
        '/sitemap/sitemap.xml',
        '/sitemap/index.xml',
        // PrestaShop, WordPress/Yoast, Shoper i sklepy z prefiksem języka
        '/1_pl_0_sitemap.xml',
        '/1_index_sitemap.xml',
        '/wp-sitemap.xml',
        '/sitemap.php',
        // BigCommerce — robots często bez Sitemap:, mapa jest pod xmlsitemap.php
        '/xmlsitemap.php',
        '/pl/sitemap.xml',
        '/product-sitemap.xml',
        '/products-sitemap.xml',
        '/sitemap-products.xml',
        '/sitemap/products.xml',
        // Shoper / Shoparena
        '/console/integration/execute/name/GoogleSitemap',
        // Joomla OSMap
        '/index.php?option=com_osmap&view=xml&id=1&format=xml',
    ];

    public function __construct(
        private readonly CatalogIndexProgress $progress,
        private readonly CatalogPageManufacturer $pageManufacturer,
    ) {}

    /**
     * @return array{urls: int, saved: int, sitemaps: list<string>, off_host: int, timed_out: bool}
     */
    public function index(string $host, int $maxUrls = 250000, int $maxSeconds = 600): array
    {
        $host = $this->normalizeHost($host);
        if ($host === '') {
            throw new RuntimeException('Pusty host.');
        }

        $this->ensureProgress($host);
        $removed = $this->purgeSkippablePages($host);
        if ($removed > 0) {
            $this->note($host, 'Usunięto '.$removed.' zdjęć/plików z indeksu.');
        }
        $deadline = microtime(true) + max(30, $maxSeconds);
        $sitemaps = $this->discoverSitemaps($host, $deadline);
        $guessed = array_flip($this->candidateUrls($host));
        if ($sitemaps === []) {
            throw new RuntimeException('Nie znalazłem sitemapy dla '.$host.'.');
        }

        $seen = [];
        $rows = [];
        $saved = 0;
        $used = [];
        $offHost = 0;
        $timedOut = microtime(true) >= $deadline;

        // indeks sitemap dokłada kolejne pliki w trakcie — foreach nie zobaczyłby dopisanych
        for ($i = 0; $i < count($sitemaps) && $i < self::MAX_SITEMAP_FILES; $i++) {
            $sitemap = $sitemaps[$i];
            if (count($seen) >= $maxUrls) {
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                break;
            }

            $found = 0;
            $consume = function (string $loc) use (
                &$sitemaps, &$seen, &$rows, &$saved, &$offHost, &$timedOut, &$found,
                $host, $maxUrls, $deadline
            ): bool {
                if (microtime(true) >= $deadline) {
                    $timedOut = true;

                    return false;
                }
                if ($this->looksLikeSitemap($loc)) {
                    if (count($sitemaps) < self::MAX_SITEMAP_FILES && ! in_array($loc, $sitemaps, true)) {
                        $sitemaps[] = $loc;
                    }

                    return true;
                }
                if ($this->isSkippableUrl($loc)) {
                    return true;
                }
                $found++;
                if (isset($seen[$loc])) {
                    return true;
                }
                // sklepy potrafią trzymać sitemapę pod inną domeną niż karty produktu
                $locHost = $this->normalizeHost((string) (parse_url($loc, PHP_URL_HOST) ?? ''));
                if ($locHost === '' || $this->isNoiseHost($locHost)) {
                    return true;
                }
                if (! $this->belongsToHost($loc, $host)) {
                    $offHost++;
                }
                $seen[$loc] = true;
                if (count($seen) % 2000 === 0) {
                    $this->note($host, 'Zebrano '.count($seen).' adresów…');
                }
                $rows[] = $this->rowFor($locHost, $loc);
                if (count($rows) >= 500) {
                    $saved += $this->store($rows);
                    $rows = [];
                }

                return count($seen) < $maxUrls;
            };

            // liczymy tylko mapy, które faktycznie coś dały — inaczej raport
            // pokazuje soft-404 sklepu jako znalezioną sitemapę
            $timeout = isset($guessed[$sitemap]) ? self::CANDIDATE_TIMEOUT : 45;
            $guessing = isset($guessed[$sitemap]);
            if (! $guessing) {
                $this->note($host, 'Czytam '.$this->shortUrl($sitemap));
            }
            if ($this->streamLocations($sitemap, $consume, $deadline, $timeout) && $found > 0) {
                $used[] = $sitemap;
                $this->note($host, 'Mapa dała '.$found.' adresów (łącznie '.count($seen).'): '.$this->shortUrl($sitemap));
            } elseif (! $guessing) {
                $this->note($host, 'Mapa pusta albo nieczytelna: '.$this->shortUrl($sitemap));
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
            }
            // robots.txt bywa bez Sitemap albo wskazuje 404 — wtedy zgadujemy typowe ścieżki
            if ($i === count($sitemaps) - 1 && count($seen) === 0 && ! $timedOut) {
                $this->note($host, 'Nadal 0 kart — dokładam zgadywane ścieżki sitemapy.');
                foreach ($this->candidateUrls($host) as $extra) {
                    if (count($sitemaps) >= self::MAX_SITEMAP_FILES) {
                        break;
                    }
                    if (! in_array($extra, $sitemaps, true)) {
                        $sitemaps[] = $extra;
                    }
                }
            }
        }

        if (count($seen) < self::SPARSE_SITEMAP_LIMIT && ! $timedOut) {
            $this->note($host, 'Mało kart z XML — pełzam po stronach sklepu.');
            foreach ($this->crawlShopPages($host, $maxUrls, $deadline) as $row) {
                $url = (string) $row['url'];
                if (isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;
                $rows[] = $row;
            }
        }

        if ($rows !== []) {
            $saved += $this->store($rows);
        }

        $this->note($host, 'Koniec zbierania: '.$saved.' kart, map: '.count($used).($timedOut ? ', limit czasu' : '').'.');
        Log::info('Catalog sitemap indexed', ['host' => $host, 'urls' => count($seen), 'saved' => $saved]);

        return [
            'urls' => count($seen),
            'saved' => $saved,
            'sitemaps' => $used,
            'off_host' => $offHost,
            'timed_out' => $timedOut,
        ];
    }

    /**
     * @return list<string>
     */
    public function discoverSitemaps(string $host, float $deadline = 0.0): array
    {
        $out = [];
        $hosts = [$host];
        if (! str_starts_with($host, 'www.')) {
            $hosts[] = 'www.'.$host;
        }
        foreach ($hosts as $name) {
            if ($deadline > 0.0 && microtime(true) >= $deadline) {
                break;
            }
            $this->note($host, 'Pobieram robots.txt z '.$name);
            $robots = $this->fetch('https://'.$name.'/robots.txt', 12);
            $before = count($out);
            $this->collectRobotSitemaps($robots, $out, $name);
            if ($out === [] && ! app()->environment('testing')) {
                $this->collectRobotSitemaps($this->fetchViaCurl('https://'.$name.'/robots.txt', 12), $out, $name);
            }
            if ($robots === null) {
                $this->note($host, 'robots.txt '.$name.': brak odpowiedzi');
            } elseif ($this->looksLikeHtml($robots)) {
                $this->note($host, 'robots.txt '.$name.': HTML zamiast tekstu (WAF?)');
            } elseif (count($out) === $before) {
                $this->note($host, 'robots.txt '.$name.': bez wpisu Sitemap');
            } else {
                $n = count($out) - $before;
                $this->note($host, 'robots.txt '.$name.': '.$n.' '.($n === 1 ? 'mapa' : 'map'));
            }
            if ($out !== []) {
                break;
            }
        }

        if ($out === [] && ($deadline <= 0.0 || microtime(true) < $deadline)) {
            $this->collectHtmlSitemaps($host, $out);
            if ($out !== []) {
                $this->note($host, 'Znalazłem sitemapę w HTML strony głównej.');
            }
        }
        if ($out === []) {
            $this->note($host, 'Brak sitemapy w robots — zgaduję typowe ścieżki.');
            $out = $this->candidateUrls($host);
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    public function candidateUrls(string $host): array
    {
        $host = $this->normalizeHost($host);
        $out = [];
        foreach (self::CANDIDATE_PATHS as $path) {
            $out[] = 'https://'.$host.$path;
        }
        // www tylko dla najczęściej działających ścieżek — reszta tylko wydłuża update
        foreach ([
            '/sitemap.xml',
            '/sitemap.xml.gz',
            '/media/sitemap.xml',
            '/pub/media/sitemap.xml',
            '/xmlsitemap.php',
            '/sitemap_index.xml',
            '/media/sitemap/sitemap.xml',
            '/media/sitemap/sitemap_en.xml',
        ] as $path) {
            $out[] = 'https://www.'.$host.$path;
        }

        return array_values(array_unique($out));
    }

    /**
     * Sitemapy sklepów mają nawet setki MB — czytamy je kawałkami, żeby nie zjeść pamięci.
     *
     * @param  callable(string): bool  $onLocation  false przerywa czytanie
     */
    private function streamLocations(string $url, callable $onLocation, float $deadline = 0.0, int $timeout = 90): bool
    {
        $timeout = max(5, $timeout);
        if ($deadline > 0.0) {
            $timeout = max(5, min($timeout, (int) ceil($deadline - microtime(true))));
        }
        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/xml,text/xml,text/plain,*/*',
                'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
            ])->timeout($timeout)->connectTimeout(8)
                // read_timeout działa tylko na StreamHandlerze, więc pod cURL-em
                // zrywamy transfer wolniejszy niż 1 kB/s przez 20 s
                ->withOptions([
                    'stream' => true,
                    'read_timeout' => min(20, $timeout),
                    'curl' => [
                        CURLOPT_LOW_SPEED_LIMIT => 1024,
                        CURLOPT_LOW_SPEED_TIME => min(20, $timeout),
                    ],
                ])
                ->get($url);
        } catch (Throwable $e) {
            Log::info('Sitemap stream failed', ['url' => $url, 'error' => $e->getMessage()]);

            return $this->streamFromCurl($url, $onLocation, $timeout);
        }

        if (! $response->successful()) {
            return $this->streamFromCurl($url, $onLocation, $timeout);
        }
        // sklepy z soft-404 oddają całą stronę z kodem 200 pod każdym adresem —
        // bez tego pobralibyśmy 130 kB HTML-a dla każdej zgadywanej ścieżki
        $contentType = mb_strtolower((string) $response->header('Content-Type'));
        if (str_contains($contentType, 'image/') || str_contains($contentType, 'video/') || str_contains($contentType, 'font/')) {
            return false;
        }

        $body = $response->toPsrResponse()->getBody();
        if (! $body->isReadable()) {
            return false;
        }
        if ($body->isSeekable()) {
            $body->rewind();
        }
        $buffer = '';
        $inflate = null;
        $first = true;

        while (! $body->eof()) {
            if ($deadline > 0.0 && microtime(true) >= $deadline) {
                return true;
            }
            try {
                $chunk = $body->read(self::CHUNK_BYTES);
            } catch (Throwable $e) {
                Log::info('Sitemap read stopped', ['url' => $url, 'error' => $e->getMessage()]);

                return true;
            }
            if ($chunk === '') {
                break;
            }
            if ($first) {
                $first = false;
                // .xml.gz bywa serwowane bez nagłówka Content-Encoding
                if (str_starts_with($chunk, "\x1f\x8b")) {
                    $inflate = inflate_init(ZLIB_ENCODING_GZIP);
                }
            }
            if ($inflate !== false && $inflate !== null) {
                $chunk = (string) inflate_add($inflate, $chunk);
            }
            if ($buffer === '' && $this->looksLikeHtml($chunk)) {
                return $this->streamFromCurl($url, $onLocation, $timeout);
            }

            $buffer .= $chunk;
            $remainder = $this->drainLocations($buffer, $onLocation);
            if ($remainder === null) {
                return true;
            }
            $buffer = $remainder;
        }

        $this->drainLocations($buffer, $onLocation);

        return true;
    }

    /**
     * Cloudflare blokuje Guzzle (JA3), a systemowy curl przechodzi — np. bhp.pl.
     *
     * @param  callable(string): bool  $onLocation
     */
    private function streamFromCurl(string $url, callable $onLocation, int $timeout = 90): bool
    {
        if (app()->environment('testing')) {
            return false;
        }

        $body = $this->fetchViaCurl($url, $timeout);
        if ($body === null || $this->looksLikeHtml($body)) {
            return false;
        }
        if (str_starts_with($body, "\x1f\x8b")) {
            $decoded = @gzdecode($body);
            $body = is_string($decoded) && $decoded !== '' ? $decoded : $body;
        }

        $this->drainLocations($body, $onLocation);

        return true;
    }

    private function fetchViaCurl(string $url, int $timeout = 90): ?string
    {
        if (preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }
        $binary = $this->curlBinary();
        if ($binary === null) {
            return null;
        }
        $timeout = max(5, $timeout);

        $process = new Process([
            $binary,
            '-sL',
            '--max-time', (string) $timeout,
            '--connect-timeout', '8',
            '--compressed',
            '-A', self::USER_AGENT,
            '-H', 'Accept: application/xml,text/xml,text/plain,*/*',
            '-H', 'Accept-Language: pl-PL,pl;q=0.9,en;q=0.8',
            $url,
        ]);
        $process->setTimeout($timeout + 5);

        try {
            $process->run();
        } catch (Throwable $e) {
            Log::info('Sitemap curl failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $body = $process->getOutput();
        if ($body === '' || strlen($body) > self::CURL_MAX_BYTES) {
            return null;
        }

        return $body;
    }

    /**
     * @param  list<string>  $out
     */
    private function collectRobotSitemaps(?string $robots, array &$out, string $host = ''): void
    {
        if ($robots === null || $this->looksLikeHtml($robots)) {
            return;
        }
        if (preg_match_all('/^\s*sitemap:\s*(\S+)/mi', $robots, $m) === 0) {
            return;
        }
        foreach ($m[1] as $url) {
            $url = trim((string) $url);
            if ($url === '') {
                continue;
            }
            // specyfikacja wymaga pełnego URL-a, ale część sklepów (np. boxmetmedical.pl)
            // podaje ścieżkę względną — bez tego taki wpis jest po prostu nie do pobrania
            if (preg_match('#^https?://#i', $url) !== 1 && $host !== '') {
                $url = str_starts_with($url, '/')
                    ? 'https://'.$host.$url
                    : 'https://'.$host.'/'.$url;
            }
            if (preg_match('#^https?://#i', $url) === 1) {
                $out[] = $url;
            }
        }
    }

    /**
     * @param  list<string>  $out
     */
    private function collectHtmlSitemaps(string $host, array &$out): void
    {
        $html = $this->fetch('https://'.$host.'/');
        if ($html === null || $html === '' || ! $this->looksLikeHtml($html)) {
            return;
        }
        if (preg_match_all(
            '/rel=["\']sitemap["\'][^>]*href=["\']([^"\']+)["\']|href=["\']([^"\']*sitemap[^"\']*)["\'][^>]*rel=["\']sitemap["\']/i',
            mb_substr($html, 0, 80000),
            $m
        ) === 0) {
            return;
        }
        foreach (array_merge($m[1], $m[2]) as $href) {
            $href = trim((string) $href);
            if ($href === '') {
                continue;
            }
            if (str_starts_with($href, '//')) {
                $href = 'https:'.$href;
            } elseif (str_starts_with($href, '/')) {
                $href = 'https://'.$host.$href;
            }
            if (preg_match('#^https?://#i', $href) === 1) {
                $out[] = $href;
            }
        }
    }

    /**
     * IAI/IdoSell i podobne nie publikują XML — zbieramy karty z menu i listingów.
     *
     * @return list<array<string, mixed>>
     */
    private function crawlShopPages(string $host, int $maxUrls, float $deadline): array
    {
        $queue = ['https://'.$host.'/', 'https://www.'.$host.'/'];
        $fetched = [];
        $seen = [];
        $rows = [];
        $pages = 0;

        while ($queue !== [] && $pages < self::HTML_CRAWL_PAGES && count($rows) < $maxUrls) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $page = array_shift($queue);
            $key = mb_strtolower(rtrim($page, '/'));
            if (isset($fetched[$key])) {
                continue;
            }
            $fetched[$key] = true;
            $html = $this->fetchHtml($page);
            $pages++;
            if ($html === null) {
                continue;
            }
            foreach ($this->extractHtmlHrefs($html, $host) as $href) {
                if ($this->isSkippableUrl($href)) {
                    continue;
                }
                if ($this->looksLikeClassicProductUrl($href)) {
                    if (! isset($seen[$href])) {
                        $seen[$href] = true;
                        $rows[] = $this->rowForHref($href, $host);
                    }
                    if (count($rows) >= $maxUrls) {
                        break 2;
                    }

                    continue;
                }
                if ($this->looksLikePrettyProductUrl($href)) {
                    if (! isset($seen[$href])) {
                        $seen[$href] = true;
                        $rows[] = $this->rowForHref($href, $host);
                    }
                    if (count($rows) >= $maxUrls) {
                        break 2;
                    }
                    if (count($queue) < 80 && $this->looksLikeListingPath($href)) {
                        $queue[] = $href;
                    }

                    continue;
                }
                if (count($queue) < 80 && $this->looksLikeCategoryUrl($href)) {
                    $queue[] = $href;
                }
            }
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function extractHtmlHrefs(string $html, string $host): array
    {
        if (preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/i', $html, $m) === 0) {
            return [];
        }
        $out = [];
        foreach ($m[1] as $href) {
            $resolved = $this->resolveHref((string) $href, $host);
            if ($resolved !== null) {
                $out[] = $resolved;
            }
        }

        return $out;
    }

    private function resolveHref(string $href, string $host): ?string
    {
        $href = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($href === '' || str_starts_with($href, '#')
            || str_starts_with($href, 'mailto:')
            || str_starts_with($href, 'tel:')
            || str_starts_with($href, 'javascript:')) {
            return null;
        }
        if (str_starts_with($href, '//')) {
            $href = 'https:'.$href;
        } elseif (str_starts_with($href, '/')) {
            $href = 'https://'.$host.$href;
        } elseif (preg_match('#^https?://#i', $href) !== 1) {
            return null;
        }
        $href = explode('#', $href, 2)[0];
        if (! $this->belongsToHost($href, $host)) {
            return null;
        }

        return $href;
    }

    private function looksLikeProductUrl(string $url): bool
    {
        return $this->looksLikeClassicProductUrl($url) || $this->looksLikePrettyProductUrl($url);
    }

    private function looksLikeClassicProductUrl(string $url): bool
    {
        $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        $query = mb_strtolower((string) (parse_url($url, PHP_URL_QUERY) ?? ''));
        if (str_contains($query, 'id_product=')) {
            return true;
        }

        return preg_match('#/p\d+,#', $path) === 1
            || preg_match('#-p\d{2,}(\.html)?$#', $path) === 1
            || preg_match('#/(product|produkt)/[^/]+#', $path) === 1
            || preg_match('#/p/[^/]+/\d+#', $path) === 1;
    }

    private function looksLikePrettyProductUrl(string $url): bool
    {
        $path = mb_strtolower(trim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/'));
        if ($path === '') {
            return false;
        }
        $segments = explode('/', $path);
        $slug = preg_replace('/\.(html?|php)$/i', '', (string) end($segments)) ?? '';
        if ($slug === '' || ! str_contains($slug, '-') || mb_strlen($slug) < 8) {
            return false;
        }
        if (preg_match('/\p{L}/u', $slug) !== 1) {
            return false;
        }

        return ! $this->isInformationalSlug($slug);
    }

    private function looksLikeListingPath(string $url): bool
    {
        $path = trim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/');
        if ($path === '') {
            return false;
        }
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));

        return $segments !== [] && count($segments) <= 2;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowForHref(string $href, string $host): array
    {
        $locHost = $this->normalizeHost((string) (parse_url($href, PHP_URL_HOST) ?? $host));

        return $this->rowFor($locHost !== '' ? $locHost : $host, $href);
    }

    private function isInformationalSlug(string $slug): bool
    {
        foreach ([
            'o-nas', 'o-firmie', 'about-us', 'about', 'kontakt', 'contact-us', 'contact',
            'regulamin', 'terms', 'polityka-prywatnosci', 'privacy-policy', 'privacy',
            'polityka-cookies', 'cookies', 'dostawa-i-platnosc', 'dostawa-i-platnosci',
            'shipping', 'returns', 'reklamacje', 'rodo', 'faq', 'pomoc',
            'logowanie', 'rejestracja', 'moje-konto', 'objasnienia-kodow', 'objasnienia-kodow',
        ] as $bad) {
            if ($slug === $bad) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeCategoryUrl(string $url): bool
    {
        if ($this->looksLikeProductUrl($url) || $this->looksLikeSitemap($url)) {
            return false;
        }
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '/');
        if ($path === '/' || $path === '') {
            return false;
        }
        if (preg_match('#\.(jpe?g|png|gif|webp|css|js|woff2?|ico|pdf|svg|xml|gz)$#i', $path) === 1) {
            return false;
        }
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));

        return $segments !== [] && count($segments) <= 4;
    }

    private function isSkippableUrl(string $url): bool
    {
        $hay = mb_strtolower($url);
        foreach ([
            '/koszyk', '/cart', '/checkout', '/login', '/konto', '/account',
            '/admin', '/search', '/szukaj', '/blog/', '/gfx/', '/szablony/',
            '/cdn-cgi/', '.css', '.js', '.jpg', '.jpeg', '.png', '.gif', '.webp',
        ] as $bad) {
            if (str_contains($hay, $bad)) {
                return true;
            }
        }
        $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));

        return preg_match('#\.(jpe?g|png|gif|webp|avif|bmp|svg|css|js|woff2?|ico|pdf|xml|gz|mp4|webm|zip)$#i', $path) === 1;
    }

    /**
     * @return list<string>
     */
    private function hostAliases(string $host): array
    {
        $host = mb_strtolower(trim($host));
        $bare = preg_replace('/^www\./', '', $host) ?? $host;

        return array_values(array_unique([$bare, 'www.'.$bare]));
    }

    private function purgeSkippablePages(string $host): int
    {
        $deleted = 0;
        CatalogPage::query()
            ->whereIn('host', $this->hostAliases($host))
            ->orderBy('id')
            ->chunkById(500, function ($pages) use (&$deleted): void {
                $ids = [];
                foreach ($pages as $page) {
                    if ($this->isSkippableUrl((string) $page->url)) {
                        $ids[] = (int) $page->id;
                    }
                }
                if ($ids === []) {
                    return;
                }
                DB::table('catalog_page_tokens')->whereIn('catalog_page_id', $ids)->delete();
                $deleted += CatalogPage::query()->whereIn('id', $ids)->delete();
            });

        return $deleted;
    }

    private function fetchHtml(string $url): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
            ])->timeout(12)->connectTimeout(6)->get($url);
        } catch (Throwable $e) {
            Log::info('Catalog HTML fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
        if (! $response->successful()) {
            return null;
        }
        $contentType = mb_strtolower((string) $response->header('Content-Type'));
        if (str_contains($contentType, 'image/') || str_contains($contentType, 'xml')) {
            return null;
        }
        $body = (string) $response->body();
        if ($body === '' || ! $this->looksLikeHtml($body)) {
            return null;
        }

        return mb_substr($body, 0, 400000);
    }

    private function curlBinary(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $candidate = 'C:\\Windows\\System32\\curl.exe';

            return is_file($candidate) ? $candidate : null;
        }
        foreach (['/usr/bin/curl', '/bin/curl'] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return 'curl';
    }

    /**
     * Zwraca resztę bufora po ostatnim </loc> albo null, gdy odbiorca każe przerwać.
     *
     * @param  callable(string): bool  $onLocation
     */
    private function drainLocations(string $buffer, callable $onLocation): ?string
    {
        if (preg_match_all('#</(?:[a-z0-9]+:)?loc>#i', $buffer, $tags, PREG_OFFSET_CAPTURE) === 0) {
            // bufor bez pełnego wpisu nie może rosnąć w nieskończoność
            return strlen($buffer) > self::MAX_BUFFER_BYTES
                ? substr($buffer, -1024)
                : $buffer;
        }

        $last = $tags[0][count($tags[0]) - 1];
        $cut = (int) $last[1] + strlen((string) $last[0]);
        foreach ($this->extractLocations(substr($buffer, 0, $cut)) as $loc) {
            if ($onLocation($loc) === false) {
                return null;
            }
        }

        return substr($buffer, $cut);
    }

    /**
     * @return list<string>
     */
    public function extractLocations(string $xml): array
    {
        // część sklepów używa prefiksu przestrzeni nazw dla całego dokumentu: <sm:loc>.
        // Ale <image:loc>/<video:loc>/<news:loc> to rozszerzenia Google Sitemap wskazujące
        // na sam plik graficzny/wideo powiązany z kartą — nie na stronę produktu, więc je
        // pomijamy (inaczej indeks zapycha się adresami .jpg, z których nie ma opisu).
        if (preg_match_all(
            '#<(?!image:|video:|news:)(?:[a-z0-9]+:)?loc>\s*(?:<!\[CDATA\[)?\s*(.*?)\s*(?:\]\]>)?\s*</(?:[a-z0-9]+:)?loc>#si',
            $xml,
            $m
        ) === 0) {
            return [];
        }

        $out = [];
        foreach ($m[1] as $loc) {
            $url = trim(html_entity_decode((string) $loc, ENT_QUOTES | ENT_XML1, 'UTF-8'));
            if ($url !== '' && preg_match('#^https?://#i', $url) === 1) {
                $out[] = $url;
            }
        }

        return $out;
    }

    /**
     * Rozbija adres na tokeny, po których szukamy: segmenty slug-a, rozdzielone
     * pary litery/cyfry oraz sklejenia sąsiadów („urg-c” → „urgc”).
     *
     * @return list<string>
     */
    public function tokensFor(string $url): array
    {
        $path = mb_strtolower(Str::ascii(urldecode(
            (string) (parse_url($url, PHP_URL_PATH) ?? '').' '.(string) (parse_url($url, PHP_URL_QUERY) ?? '')
        )));
        $parts = preg_split('/[^a-z0-9]+/u', $path) ?: [];
        $parts = array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));

        $out = [];
        foreach ($parts as $i => $part) {
            if (mb_strlen($part) <= self::MAX_TOKEN_LENGTH) {
                $out[] = $part;
            }
            // „rekawice1202” → „rekawice” + „1202”
            if (preg_match('/^[a-z]+$/u', $part) !== 1 && preg_match('/^[0-9]+$/u', $part) !== 1) {
                foreach (preg_split('/(?<=[a-z])(?=[0-9])|(?<=[0-9])(?=[a-z])/u', $part) ?: [] as $piece) {
                    if ($piece !== '' && mb_strlen($piece) <= self::MAX_TOKEN_LENGTH) {
                        $out[] = $piece;
                    }
                }
            }
            // sklejamy tylko krótkie sąsiedztwa, bo to rozbite kody („urg-c”, „42-874”)
            $next = $parts[$i + 1] ?? null;
            if ($next !== null && mb_strlen($part) <= 6 && mb_strlen($next) <= 6) {
                $out[] = $part.$next;
            }
        }

        $unique = [];
        foreach ($out as $token) {
            if (mb_strlen($token) >= 2 && mb_strlen($token) <= self::MAX_TOKEN_LENGTH) {
                $unique[$token] = true;
            }
            if (count($unique) >= self::MAX_TOKENS_PER_PAGE) {
                break;
            }
        }

        return array_keys($unique);
    }

    /** „…/produkt/rekawice-urgent-1202” → „…/produkt/rekawice urgent 1202” do wyszukiwania. */
    public function haystackFor(string $url, string $title = ''): string
    {
        $decoded = mb_strtolower(urldecode($url).' '.$title);

        return trim((string) preg_replace('/\s+/u', ' ', $decoded));
    }

    /** Część serwerów podaje XML sitemapy jako text/html — rozstrzyga początek treści. */
    private function looksLikeHtml(string $chunk): bool
    {
        $head = mb_strtolower(ltrim(mb_substr($chunk, 0, 512)));
        if (str_contains($head, '<urlset') || str_contains($head, '<sitemapindex') || str_contains($head, '<loc')) {
            return false;
        }

        return str_starts_with($head, '<!doctype html')
            || str_starts_with($head, '<html')
            || str_contains($head, '<head');
    }

    private function looksLikeSitemap(string $url): bool
    {
        $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        $query = mb_strtolower((string) (parse_url($url, PHP_URL_QUERY) ?? ''));
        $hay = $path.' '.$query;
        if (str_contains($hay, 'googlesitemap') || str_contains($hay, 'osmap')) {
            return true;
        }
        if (str_ends_with($path, '.xml') || str_ends_with($path, '.xml.gz') || str_ends_with($path, '.gz')) {
            return true;
        }

        return str_contains($path, 'sitemap') && str_ends_with($path, '.php');
    }

    /** Portale społecznościowe i wyszukiwarki bywają linkowane w sitemapach — do indeksu nie wnoszą nic. */
    private function isNoiseHost(string $host): bool
    {
        foreach (['google.', 'facebook.', 'youtube.', 'instagram.', 'twitter.', 'x.com', 'linkedin.', 'pinterest.'] as $noise) {
            if (str_starts_with($host, $noise) || str_contains($host, '.'.$noise)) {
                return true;
            }
        }

        return false;
    }

    private function belongsToHost(string $url, string $host): bool
    {
        $urlHost = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $urlHost = preg_replace('/^www\./', '', $urlHost) ?? $urlHost;

        return $urlHost === $host || str_ends_with($urlHost, '.'.$host);
    }

    /**
     * @return array<string, mixed>
     */
    private function rowFor(string $host, string $url): array
    {
        $now = now();
        $manufacturer = $this->pageManufacturer->resolve($host, $url);
        $haystack = $this->haystackFor($url);
        if ($manufacturer !== null) {
            $haystack = trim($haystack.' '.$manufacturer);
        }

        return [
            'host' => $host,
            'manufacturer' => $manufacturer,
            'url_hash' => CatalogPage::hashFor($url),
            'url' => $url,
            'title' => null,
            'haystack' => mb_substr($haystack, 0, 2000),
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function store(array $rows): int
    {
        CatalogPage::query()->upsert(
            $rows,
            ['url_hash'],
            ['manufacturer', 'haystack', 'last_seen_at', 'updated_at']
        );
        $this->storeTokens(array_column($rows, 'url_hash'));

        return count($rows);
    }

    /**
     * @param  list<string>  $hashes
     */
    public function storeTokens(array $hashes): void
    {
        if ($hashes === []) {
            return;
        }

        $pages = CatalogPage::query()
            ->whereIn('url_hash', $hashes)
            ->get(['id', 'url']);

        $tokens = [];
        foreach ($pages as $page) {
            foreach ($this->tokensFor((string) $page->url) as $token) {
                $tokens[] = ['catalog_page_id' => $page->id, 'token' => $token];
            }
        }

        foreach (array_chunk($tokens, 400) as $chunk) {
            DB::table('catalog_page_tokens')->insertOrIgnore($chunk);
        }
    }

    /** Ile razy próbujemy jedno zapytanie — część serwerów bywa niestabilna tylko chwilowo. */
    private const FETCH_ATTEMPTS = 2;

    private function fetch(string $url, int $timeout = 30): ?string
    {
        $lastError = null;
        for ($attempt = 1; $attempt <= self::FETCH_ATTEMPTS; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'application/xml,text/xml,text/plain,*/*',
                ])->timeout(max(5, $timeout))->connectTimeout(8)->get($url);
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                if ($attempt < self::FETCH_ATTEMPTS) {
                    usleep(300000);
                }

                continue;
            }

            if (! $response->successful()) {
                if ($attempt < self::FETCH_ATTEMPTS) {
                    usleep(300000);

                    continue;
                }

                return null;
            }

            $body = (string) $response->body();
            if ($body === '') {
                return null;
            }
            // .xml.gz bywa serwowane bez nagłówka Content-Encoding
            if (str_starts_with($body, "\x1f\x8b")) {
                $body = (string) @gzdecode($body);
            }

            return $body !== '' ? $body : null;
        }

        if ($lastError !== null) {
            Log::info('Sitemap fetch failed', ['url' => $url, 'error' => $lastError]);
        }

        return null;
    }

    private function ensureProgress(string $host): void
    {
        $status = $this->progress->snapshot($host)['status'];
        if ($status === 'idle') {
            $this->progress->start($host, 'Start indeksowania.');
            $this->progress->markRunning($host, 'Szukam sitemapy.');

            return;
        }
        if ($status === 'queued') {
            $this->progress->markRunning($host, 'Worker startuje.');
        }
    }

    private function note(string $host, string $message): void
    {
        $this->progress->line($host, $message);
    }

    private function shortUrl(string $url): string
    {
        return mb_strlen($url) > 140 ? mb_substr($url, 0, 137).'…' : $url;
    }

    private function normalizeHost(string $host): string
    {
        $clean = mb_strtolower(trim(preg_replace('#^https?://#i', '', $host) ?? $host));
        $clean = trim(explode('/', $clean)[0] ?? $clean);

        return preg_replace('/^www\./', '', $clean) ?? $clean;
    }
}
