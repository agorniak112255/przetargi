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

    /** Jedna mapa produktów bywa kilka MB — 45 s ucinało ją w połowie (~1100 z 3000+). */
    private const SITEMAP_FILE_TIMEOUT = 180;

    /** Zgadywane ścieżki nie mogą zjeść całego --seconds na jednym 404. */
    private const CANDIDATE_TIMEOUT = 8;

    /** Łączny czas na zgadywane sitemap.xml — potem pełzamy po HTML. */
    private const CANDIDATE_GUESS_BUDGET = 40;

    /** Ile sekund zostawiamy na crawl, gdy XML nic nie dał. */
    private const HTML_CRAWL_RESERVE = 180;

    /** Sklepy bez XML (IAI) — ile stron HTML zbieramy z menu i listingów. */
    private const HTML_CRAWL_PAGES = 180;

    /** Ile adresów kategorii/list trzymamy w kolejce pełzania. */
    private const HTML_CRAWL_QUEUE = 250;

    /** Karty bez kodu w adresie — ile stron produktu czytamy pod SKU/nazwę. */
    private const HTML_PRODUCT_FETCH_MAX = 400;

    /** Krótki probe zanim wydłużymy timeout — serwer często w ogóle nie widzi hosta. */
    private const HTML_PROBE_TIMEOUT = 6;

    private const HTML_FETCH_TIMEOUT = 30;

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
        private readonly ShopHtmlCrawler $shopCrawler,
        private readonly ShopCatalogUrl $catalogUrl,
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
        $budget = max(30, $maxSeconds);
        $deadline = microtime(true) + $budget;
        $sitemapDeadline = $deadline - min(self::HTML_CRAWL_RESERVE, (int) floor($budget * 0.4));
        $sitemaps = $this->discoverSitemaps($host, $sitemapDeadline);
        $guessed = array_flip($this->candidateUrls($host));
        if ($sitemaps === []) {
            $this->note($host, 'Brak sitemapy — pełzam po HTML jak skrypt sklepu.');
        }

        $seen = [];
        $rows = [];
        $saved = 0;
        $used = [];
        $offHost = 0;
        $timedOut = microtime(true) >= $deadline;
        $guessStartedAt = null;
        $shopListings = false;
        $listingSeeds = [];

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

            $guessing = isset($guessed[$sitemap]);
            if ($guessing) {
                if ($guessStartedAt === null) {
                    $guessStartedAt = microtime(true);
                }
                if ((microtime(true) - $guessStartedAt) >= self::CANDIDATE_GUESS_BUDGET
                    || microtime(true) >= $sitemapDeadline) {
                    if ($this->hasPendingSitemap($sitemaps, $i + 1, $guessed)) {
                        continue;
                    }
                    $this->note($host, 'Zgadywanie sitemap nic nie dało — pełzam po HTML.');
                    break;
                }
            } elseif (count($seen) === 0 && microtime(true) >= $sitemapDeadline) {
                $this->note($host, 'Zostawiam czas na pełzanie HTML.');
                break;
            }

            $found = 0;
            $consume = function (string $loc) use (
                &$sitemaps, &$seen, &$rows, &$saved, &$offHost, &$timedOut, &$found,
                &$shopListings, &$listingSeeds, $host, $maxUrls, $deadline
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
                    if ($this->catalogUrl->isSoteShopListing($loc)) {
                        $shopListings = true;
                    }
                    if ($this->catalogUrl->isSoteShopCategory($loc) && count($listingSeeds) < 400) {
                        $https = preg_replace('#^http://#i', 'https://', $loc) ?? $loc;
                        $listingSeeds[$https] = $https;
                    }

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
            $timeout = $guessing
                ? self::CANDIDATE_TIMEOUT
                : $this->sitemapFileTimeout($deadline);
            if (! $guessing) {
                $this->note($host, 'Czytam '.$this->shortUrl($sitemap));
            }
            $mapsBefore = count($sitemaps);
            $streamDeadline = $guessing ? min($deadline, $guessStartedAt + self::CANDIDATE_GUESS_BUDGET) : $deadline;
            if ($this->streamLocations($sitemap, $consume, $streamDeadline, $timeout, ! $guessing) && $found > 0) {
                $used[] = $sitemap;
                $this->note($host, 'Mapa dała '.$found.' adresów (łącznie '.count($seen).'): '.$this->shortUrl($sitemap));
            } elseif (! $guessing) {
                $childMaps = count($sitemaps) - $mapsBefore;
                $this->note($host, $childMaps > 0
                    ? 'Indeks map: '.$childMaps.' plików z '.$this->shortUrl($sitemap)
                    : 'Mapa pusta albo nieczytelna: '.$this->shortUrl($sitemap));
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
            }
            // robots.txt bywa bez Sitemap albo wskazuje 404 — wtedy zgadujemy typowe ścieżki
            if ($i === count($sitemaps) - 1 && count($seen) === 0 && ! $timedOut) {
                $this->note($host, 'Nadal 0 kart — dokładam zgadywane ścieżki sitemapy.');
                foreach (array_merge($this->wordpressSitemapFallbacks($host), $this->candidateUrls($host)) as $extra) {
                    if (count($sitemaps) >= self::MAX_SITEMAP_FILES) {
                        break;
                    }
                    if (! in_array($extra, $sitemaps, true)) {
                        $sitemaps[] = $extra;
                    }
                }
            }
        }

        if (count($seen) < self::SPARSE_SITEMAP_LIMIT || $shopListings) {
            $this->note($host, $shopListings && count($seen) >= self::SPARSE_SITEMAP_LIMIT
                ? 'Sitemapa bez pełnego katalogu — pełzam po kategoriach sklepu.'
                : ($timedOut
                    ? 'Limit czasu na XML — pełzam po stronach sklepu.'
                    : 'Mało kart z XML — pełzam po stronach sklepu.'));
            foreach ($this->crawlShopPages($host, $maxUrls, $deadline, array_values($listingSeeds)) as $row) {
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
        $reachedHost = false;
        $hosts = [$host];
        if (! str_starts_with($host, 'www.')) {
            $hosts[] = 'www.'.$host;
        }
        foreach ($hosts as $name) {
            if ($deadline > 0.0 && microtime(true) >= $deadline) {
                break;
            }
            $this->note($host, 'Pobieram robots.txt z '.$name);
            $robots = $this->fetch('https://'.$name.'/robots.txt', 8, 1);
            if ($robots !== null) {
                $reachedHost = true;
            }
            $before = count($out);
            $this->collectRobotSitemaps($robots, $out, $name);
            if ($out === [] && ! app()->environment('testing')) {
                $viaCurl = $this->fetchViaCurl('https://'.$name.'/robots.txt', 12);
                if ($viaCurl !== null) {
                    $reachedHost = true;
                    $this->collectRobotSitemaps($viaCurl, $out, $name);
                }
            }
            if ($robots === null) {
                $this->note($host, 'robots.txt '.$name.': brak odpowiedzi');
            } elseif ($robots === '') {
                $this->note($host, 'robots.txt '.$name.': błąd HTTP');
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
        if ($out === [] && $reachedHost && ($deadline <= 0.0 || microtime(true) < $deadline)) {
            $this->collectHtmlSitemaps($host, $out);
            if ($out !== []) {
                $this->note($host, 'Znalazłem sitemapę w HTML strony głównej.');
            }
        }
        if ($out === [] && $reachedHost) {
            $this->note($host, 'Brak sitemapy w robots — zgaduję typowe ścieżki.');
            $out = $this->candidateUrls($host);
        } elseif ($out === []) {
            $this->note($host, 'Host nie odpowiada na robots — pomijam zgadywanie sitemap, pełzam po HTML.');
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
            '/wp-sitemap.xml',
            '/media/sitemap/sitemap.xml',
            '/media/sitemap/sitemap_en.xml',
        ] as $path) {
            $out[] = 'https://www.'.$host.$path;
        }

        return array_values(array_unique($out));
    }

    /**
     * Yoast w robots bywa 404 (WPML), a żywa mapa to /wp-sitemap.xml.
     *
     * @return list<string>
     */
    private function wordpressSitemapFallbacks(string $host): array
    {
        $host = $this->normalizeHost($host);
        $out = [];
        foreach ([$host, 'www.'.$host] as $name) {
            $out[] = 'https://'.$name.'/wp-sitemap.xml';
            $out[] = 'https://'.$name.'/wp-sitemap-posts-product-1.xml';
        }

        return $out;
    }

    /**
     * @param  list<string>  $sitemaps
     * @param  array<string, int>  $guessed
     */
    private function hasPendingSitemap(array $sitemaps, int $from, array $guessed): bool
    {
        for ($i = max(0, $from); $i < count($sitemaps); $i++) {
            if (! isset($guessed[$sitemaps[$i]])) {
                return true;
            }
        }

        return false;
    }

    private function sitemapFileTimeout(float $deadline): int
    {
        $left = $deadline > 0.0 ? (int) ceil($deadline - microtime(true)) : self::SITEMAP_FILE_TIMEOUT;

        return max(45, min(self::SITEMAP_FILE_TIMEOUT, $left));
    }

    /**
     * Sitemapy sklepów mają nawet setki MB — czytamy je kawałkami, żeby nie zjeść pamięci.
     *
     * @param  callable(string): bool  $onLocation  false przerywa czytanie
     */
    private function streamLocations(string $url, callable $onLocation, float $deadline = 0.0, int $timeout = 90, bool $allowCurl = true): bool
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
                    'curl' => $this->curlResolveV4() + [
                        CURLOPT_LOW_SPEED_LIMIT => 1024,
                        CURLOPT_LOW_SPEED_TIME => min(20, $timeout),
                    ],
                ])
                ->get($url);
        } catch (Throwable $e) {
            Log::info('Sitemap stream failed', ['url' => $url, 'error' => $e->getMessage()]);

            return $allowCurl && $this->streamFromCurl($url, $onLocation, $timeout);
        }

        if (! $response->successful()) {
            return $allowCurl && $this->streamFromCurl($url, $onLocation, $timeout);
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

                return $allowCurl && $this->streamFromCurl($url, $onLocation, $timeout);
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
                return $allowCurl && $this->streamFromCurl($url, $onLocation, $timeout);
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

    private function fetchViaCurl(string $url, int $timeout = 90, string $accept = 'Accept: application/xml,text/xml,text/plain,*/*'): ?string
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
            '--ipv4',
            '--max-time', (string) $timeout,
            '--connect-timeout', (string) max(2, min(5, $timeout)),
            '--compressed',
            '-A', self::USER_AGENT,
            '-H', $accept,
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
        if ($robots === null || $robots === '' || $this->looksLikeHtml($robots)) {
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
            if (preg_match('#^https?://#i', $url) !== 1) {
                continue;
            }
            $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
            if (str_ends_with($path, '.txt') && ! str_contains($path, 'sitemap')) {
                continue;
            }
            $out[] = $url;
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
     * IAI/IdoSell i sklepy bez XML — crawler jak skrypt Python (seedy, BFS, CODE).
     *
     * @param  list<string>  $extraSeeds
     * @return list<array<string, mixed>>
     */
    private function crawlShopPages(string $host, int $maxUrls, float $deadline, array $extraSeeds = []): array
    {
        $found = $this->shopCrawler->crawl($host, $maxUrls, $deadline, function (string $text) use ($host): void {
            $this->note($host, $text);
        }, $extraSeeds);
        $rows = [];
        foreach ($found as $item) {
            $rows[] = $this->rowForHref($item['url'], $host, $item['title'], $item['extra']);
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function crawlSeeds(string $host): array
    {
        $out = [
            'https://www.'.$host.'/',
            'https://'.$host.'/',
        ];
        foreach ([
            '/products/productcategory/Footwear',
            '/products/productcategory/Workwear',
            '/products/productcategory/PPE',
            '/brands',
            '/resources',
            '/products',
            '/produkty',
        ] as $path) {
            $out[] = 'https://www.'.$host.$path;
            $out[] = 'https://'.$host.$path;
        }

        return $out;
    }

    private function isHomepageUrl(string $url): bool
    {
        $path = trim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/');

        return $path === '';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function enrichCrawledProductRows(array $rows, string $host, float $deadline): array
    {
        $fetched = 0;
        foreach ($rows as $i => $row) {
            if ($fetched >= self::HTML_PRODUCT_FETCH_MAX || microtime(true) >= $deadline) {
                break;
            }
            $url = (string) ($row['url'] ?? '');
            if ($url === '' || $this->urlHasIdentityToken($url)) {
                continue;
            }
            $html = $this->fetchHtml($url);
            $fetched++;
            if ($html === null) {
                continue;
            }
            $identity = $this->identityFromHtml($html);
            if ($identity['title'] === '' && $identity['extra'] === '') {
                continue;
            }
            $rows[$i] = $this->rowForHref($url, $host, $identity['title'], $identity['extra']);
        }

        return $rows;
    }

    /** Adres już niesie SKU (litery+cyfry) — nie ma po co czytać karty pod kod. */
    private function urlHasIdentityToken(string $url): bool
    {
        foreach ($this->tokensFor($url) as $token) {
            if (preg_match('/^(?=.*[a-z])(?=.*[0-9])[a-z0-9]{5,}$/u', $token) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{title: string, extra: string}
     */
    private function identityFromHtml(string $html): array
    {
        if ($this->isErrorPageHtml($html)) {
            return ['title' => '', 'extra' => ''];
        }
        $title = '';
        if (preg_match('/<h1\b[^>]*>(.*?)<\/h1>/is', $html, $m) === 1) {
            $title = $this->plainText((string) $m[1]);
        } elseif (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $html, $m) === 1) {
            $title = $this->plainText((string) $m[1]);
        }
        $title = mb_substr($title, 0, 500);

        $text = $this->plainText(mb_substr($html, 0, 120000));
        $bits = [];
        foreach ([
            '/\bCODE\s*:?\s*([A-Z0-9][A-Z0-9\/\-]{2,24})/i',
            '/\bSKU\s*:?\s*([A-Z0-9][A-Z0-9\/\-]{2,24})/i',
            '/\b(?:Kod(?:\s+(?:produktu|towaru))?|Art(?:icle|\.)?\s*(?:nr\.?|no\.?|number)?)\s*:?\s*([A-Z0-9][A-Z0-9\/\-]{2,24})/i',
            '/\bBRAND\s*:?\s*(.+?)(?=\s+(?:CODE|SKU|SIZES|COLOURS|PRICE|Kod|Producent|Marka)\s*:|$)/i',
            '/\b(?:Producent|Marka)\s*:?\s*(.+?)(?=\s+(?:CODE|SKU|Kod|BRAND|Art)\s*:|$)/i',
        ] as $re) {
            if (preg_match($re, $text, $m) === 1) {
                $bit = trim((string) $m[1]);
                if ($bit !== '' && mb_strlen($bit) <= 80) {
                    $bits[] = $bit;
                }
            }
        }

        $extra = trim($title.' '.implode(' ', $bits));

        return [
            'title' => $title,
            'extra' => mb_substr($extra, 0, 500),
        ];
    }

    private function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @return list<string>
     */
    private function extractHtmlHrefs(string $html, string $host, string $page = ''): array
    {
        if (preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/i', $html, $m) === 0) {
            return [];
        }
        $out = [];
        foreach ($m[1] as $href) {
            $resolved = $this->resolveHref((string) $href, $host, $page);
            if ($resolved !== null) {
                $out[] = $resolved;
            }
        }

        return $out;
    }

    private function resolveHref(string $href, string $host, string $page = ''): ?string
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
            $pageHost = (string) (parse_url($page, PHP_URL_HOST) ?: $host);
            $href = 'https://'.$pageHost.$href;
        } elseif (preg_match('#^https?://#i', $href) !== 1) {
            $href = $this->urlJoin($page !== '' ? $page : 'https://'.$host.'/', $href);
        }
        $href = explode('#', $href, 2)[0];
        if (! $this->belongsToHost($href, $host)) {
            return null;
        }

        return $this->normalizeCrawlUrl($href);
    }

    private function urlJoin(string $base, string $rel): string
    {
        $parts = parse_url($base);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $name = (string) ($parts['host'] ?? '');
        $path = (string) ($parts['path'] ?? '/');
        if (str_starts_with($rel, '?')) {
            return $scheme.'://'.$name.$path.$rel;
        }
        $dir = preg_replace('#/[^/]*$#', '/', $path !== '' ? $path : '/') ?? '/';
        if ($dir === '') {
            $dir = '/';
        }
        $joined = $dir.$rel;
        $segments = [];
        foreach (explode('/', $joined) as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $seg;
        }

        return $scheme.'://'.$name.'/'.implode('/', $segments);
    }

    /** Zostawiamy paginację, odrzucamy kombinacje filtrów — inaczej kolejka puchnie. */
    private function normalizeCrawlUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return $url;
        }
        $path = (string) ($parts['path'] ?? '/');
        $query = (string) ($parts['query'] ?? '');
        if ($query !== '' && $this->catalogUrl->keepsShopQuery($query)) {
            $scheme = (string) ($parts['scheme'] ?? 'https');

            return $scheme.'://'.$parts['host'].($path !== '' ? $path : '/').'?'.$query;
        }
        $kept = [];
        if ($query !== '') {
            parse_str($query, $params);
            foreach ($params as $key => $value) {
                $name = mb_strtolower((string) $key);
                if (in_array($name, ['page', 'p', 'pgc', 'pagenumber', 'strona', 'paged'], true)) {
                    $kept[(string) $key] = $value;
                }
            }
        }
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $out = $scheme.'://'.$parts['host'].($path !== '' ? $path : '/');
        if ($kept !== []) {
            $out .= '?'.http_build_query($kept);
        }

        return $out;
    }

    private function looksLikeProductUrl(string $url): bool
    {
        return $this->catalogUrl->isProductCard($url);
    }

    private function looksLikeClassicProductUrl(string $url): bool
    {
        return $this->catalogUrl->isClassicProduct($url);
    }

    private function looksLikePrettyProductUrl(string $url): bool
    {
        return $this->catalogUrl->isPrettyProduct($url);
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
    private function rowForHref(string $href, string $host, string $title = '', string $extra = ''): array
    {
        $locHost = $this->normalizeHost((string) (parse_url($href, PHP_URL_HOST) ?? $host));

        return $this->rowFor($locHost !== '' ? $locHost : $host, $href, $title, $extra);
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
        if ($this->catalogUrl->isIndexListing($url)) {
            return true;
        }
        if ($this->catalogUrl->isIaiShop($url) && ! $this->catalogUrl->isIaiProductCard($path)) {
            return trim($path, '/') !== '';
        }

        return preg_match('#\.(jpe?g|png|gif|webp|avif|bmp|svg|css|js|woff2?|ico|pdf|xml|gz|mp4|webm|zip)$#i', $path) === 1;
    }

    /**
     * @return list<string>
     */
    private function fetchCandidates(string $url): array
    {
        $out = [$url];
        $twin = $this->iaiTwinUrl($url);
        if ($twin !== null) {
            $out[] = $twin;
        }

        return $out;
    }

    private function iaiTwinUrl(string $url): ?string
    {
        $parts = parse_url($url);
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return null;
        }
        $www = str_starts_with($host, 'www.');
        $bare = preg_replace('/^www\./', '', $host) ?? $host;
        $map = ['robocze-buty.pl' => 'gvarant.pl', 'gvarant.pl' => 'robocze-buty.pl'];
        $twinBare = $map[$bare] ?? null;
        if ($twinBare === null) {
            return null;
        }
        $twinHost = $www ? 'www.'.$twinBare : $twinBare;
        $path = (string) ($parts['path'] ?? '/');
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?'.$parts['query'] : '';

        return ((string) ($parts['scheme'] ?? 'https')).'://'.$twinHost.$path.$query;
    }

    private function isErrorPageHtml(string $html): bool
    {
        $head = mb_strtolower(mb_substr($html, 0, 2500));

        return str_contains($head, '403 forbidden')
            || (bool) preg_match('#<title>\s*403\b#', $head);
    }

    private function isErrorPageTitle(string $title): bool
    {
        $title = mb_strtolower(trim($title));

        return $title !== '' && (str_contains($title, '403 forbidden') || preg_match('/^403\b/', $title) === 1);
    }

    private function isIaiShopUrl(string $url): bool
    {
        return $this->catalogUrl->isIaiShop($url);
    }

    /** Karta IAI: /p117,sandaly-urgent-302-s1-gray.html — bez .html to grupa/filtr. */
    private function isIaiProductCard(string $path): bool
    {
        return $this->catalogUrl->isIaiProductCard($path);
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
                    if ($this->isSkippableUrl((string) $page->url)
                        || $this->isErrorPageTitle((string) ($page->title ?? ''))) {
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

    private function fetchHtml(string $url, int $timeout = self::HTML_FETCH_TIMEOUT): ?string
    {
        $timeout = max(5, $timeout);
        if (! app()->environment('testing')) {
            foreach ($this->fetchCandidates($url) as $candidate) {
                $viaCurl = $this->fetchViaCurl(
                    $candidate,
                    $timeout,
                    'Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.8'
                );
                if ($viaCurl !== null && $this->looksLikeHtml($viaCurl) && ! $this->isErrorPageHtml($viaCurl)) {
                    return mb_substr($viaCurl, 0, 400000);
                }
            }

            return null;
        }
        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
            ])->timeout($timeout)->connectTimeout(min(5, $timeout))
                ->withOptions(['curl' => $this->curlResolveV4()])
                ->get($url);
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

    /**
     * @return array<int, int>
     */
    private function curlResolveV4(): array
    {
        return defined('CURL_IPRESOLVE_V4')
            ? [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]
            : [];
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
    public function tokensFor(string $url, string $extra = ''): array
    {
        $path = mb_strtolower(Str::ascii(urldecode(trim(
            $extra.' '.(string) (parse_url($url, PHP_URL_PATH) ?? '').' '.(string) (parse_url($url, PHP_URL_QUERY) ?? '')
        ))));
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
                // klucz numeryczny PHP zrzuca do int — wartość zostaje stringiem
                $unique[$token] = $token;
            }
            if (count($unique) >= self::MAX_TOKENS_PER_PAGE) {
                break;
            }
        }

        return array_values($unique);
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
    private function rowFor(string $host, string $url, string $title = '', string $extra = ''): array
    {
        $now = now();
        $manufacturer = $this->pageManufacturer->resolve($host, $url);
        if ($this->isErrorPageTitle($title) || $this->isErrorPageTitle($extra)) {
            $title = '';
            $extra = '';
        }
        $label = trim($title.' '.$extra);
        $haystack = $this->haystackFor($url, $label);
        if ($manufacturer !== null) {
            $haystack = trim($haystack.' '.$manufacturer);
        }

        return [
            'host' => $host,
            'manufacturer' => $manufacturer,
            'url_hash' => CatalogPage::hashFor($url),
            'url' => $url,
            'title' => $label !== '' ? mb_substr($label, 0, 500) : null,
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
        $titled = [];
        foreach ($rows as $row) {
            if (($row['title'] ?? '') !== '' && $row['title'] !== null) {
                $titled[] = $row;
            }
        }
        if ($titled !== []) {
            CatalogPage::query()->upsert(
                $titled,
                ['url_hash'],
                ['title', 'haystack', 'updated_at']
            );
        }
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
            ->get(['id', 'url', 'title']);

        $tokens = [];
        foreach ($pages as $page) {
            foreach ($this->tokensFor((string) $page->url, (string) ($page->title ?? '')) as $token) {
                $tokens[] = ['catalog_page_id' => $page->id, 'token' => $token];
            }
        }

        foreach (array_chunk($tokens, 400) as $chunk) {
            DB::table('catalog_page_tokens')->insertOrIgnore($chunk);
        }
    }

    /** Ile razy próbujemy jedno zapytanie — część serwerów bywa niestabilna tylko chwilowo. */
    private const FETCH_ATTEMPTS = 2;

    private function fetch(string $url, int $timeout = 30, int $attempts = self::FETCH_ATTEMPTS): ?string
    {
        $lastError = null;
        $tries = max(1, $attempts);
        for ($attempt = 1; $attempt <= $tries; $attempt++) {
            try {
                $response = Http::withHeaders([
                    'User-Agent' => self::USER_AGENT,
                    'Accept' => 'application/xml,text/xml,text/plain,*/*',
                ])->timeout(max(5, $timeout))->connectTimeout(8)
                    ->withOptions(['curl' => $this->curlResolveV4()])
                    ->get($url);
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                if ($attempt < $tries) {
                    usleep(300000);
                }

                continue;
            }

            if (! $response->successful()) {
                return '';
            }

            $body = (string) $response->body();
            if ($body === '') {
                return '';
            }
            // .xml.gz bywa serwowane bez nagłówka Content-Encoding
            if (str_starts_with($body, "\x1f\x8b")) {
                $body = (string) @gzdecode($body);
            }

            return $body !== '' ? $body : '';
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
