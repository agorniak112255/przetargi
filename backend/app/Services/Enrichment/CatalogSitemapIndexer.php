<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\CatalogPage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Buduje lokalny indeks kart produktu z sitemap producentów i hurtowni.
 * Dzięki niemu enrichment nie musi pytać wyszukiwarki o każdy z 30 tys. produktów.
 */
final class CatalogSitemapIndexer
{
    /** Ile plików sitemap z jednego indeksu przetwarzamy. */
    private const MAX_SITEMAP_FILES = 60;

    private const CHUNK_BYTES = 262144;

    private const MAX_BUFFER_BYTES = 2097152;

    private const USER_AGENT = 'Mozilla/5.0 (compatible; PrzetargiBot/1.0; +https://przetargi.supon.rzeszow.pl)';

    private const CANDIDATE_PATHS = [
        '/sitemap.xml',
        '/sitemap_index.xml',
        '/sitemap-index.xml',
        '/sitemap/sitemap.xml',
        '/1_pl_0_sitemap.xml',
    ];

    /**
     * @return array{urls: int, saved: int, sitemaps: list<string>, off_host: int, timed_out: bool}
     */
    public function index(string $host, int $maxUrls = 60000, int $maxSeconds = 240): array
    {
        $host = $this->normalizeHost($host);
        if ($host === '') {
            throw new RuntimeException('Pusty host.');
        }

        $sitemaps = $this->discoverSitemaps($host);
        if ($sitemaps === []) {
            throw new RuntimeException('Nie znalazłem sitemapy dla '.$host.'.');
        }

        $seen = [];
        $rows = [];
        $saved = 0;
        $used = [];
        $offHost = 0;
        $timedOut = false;
        $deadline = microtime(true) + max(30, $maxSeconds);

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

            $consume = function (string $loc) use (
                &$sitemaps, &$seen, &$rows, &$saved, &$offHost, &$timedOut,
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
                $rows[] = $this->rowFor($locHost, $loc);
                if (count($rows) >= 500) {
                    $saved += $this->store($rows);
                    $rows = [];
                }

                return count($seen) < $maxUrls;
            };

            if ($this->streamLocations($sitemap, $consume, $deadline)) {
                $used[] = $sitemap;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
            }
        }

        if ($rows !== []) {
            $saved += $this->store($rows);
        }

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
    public function discoverSitemaps(string $host): array
    {
        $out = [];
        $robots = $this->fetch('https://'.$host.'/robots.txt');
        if ($robots !== null && preg_match_all('/^\s*sitemap:\s*(\S+)/mi', $robots, $m) > 0) {
            foreach ($m[1] as $url) {
                $url = trim((string) $url);
                if ($url !== '') {
                    $out[] = $url;
                }
            }
        }

        if ($out === []) {
            foreach (self::CANDIDATE_PATHS as $path) {
                $out[] = 'https://'.$host.$path;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Sitemapy sklepów mają nawet setki MB — czytamy je kawałkami, żeby nie zjeść pamięci.
     *
     * @param  callable(string): bool  $onLocation  false przerywa czytanie
     */
    private function streamLocations(string $url, callable $onLocation, float $deadline = 0.0): bool
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/xml,text/xml,text/plain,*/*',
            ])->timeout(180)->connectTimeout(8)
                // read_timeout działa tylko na StreamHandlerze, więc pod cURL-em
                // zrywamy transfer wolniejszy niż 1 kB/s przez 20 s
                ->withOptions([
                    'stream' => true,
                    'read_timeout' => 20,
                    'curl' => [
                        CURLOPT_LOW_SPEED_LIMIT => 1024,
                        CURLOPT_LOW_SPEED_TIME => 20,
                    ],
                ])
                ->get($url);
        } catch (Throwable $e) {
            Log::info('Sitemap stream failed', ['url' => $url, 'error' => $e->getMessage()]);

            return false;
        }

        if (! $response->successful()) {
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
        // część sklepów używa prefiksu przestrzeni nazw: <sm:loc>, <image:loc>
        if (preg_match_all('#<(?:[a-z0-9]+:)?loc>\s*(?:<!\[CDATA\[)?\s*(.*?)\s*(?:\]\]>)?\s*</(?:[a-z0-9]+:)?loc>#si', $xml, $m) === 0) {
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

    /** „…/produkt/rekawice-urgent-1202” → „…/produkt/rekawice urgent 1202” do wyszukiwania. */
    public function haystackFor(string $url, string $title = ''): string
    {
        $decoded = mb_strtolower(urldecode($url).' '.$title);

        return trim((string) preg_replace('/\s+/u', ' ', $decoded));
    }

    private function looksLikeSitemap(string $url): bool
    {
        $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));

        return str_contains($path, 'sitemap') && (str_ends_with($path, '.xml') || str_ends_with($path, '.gz'));
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

        return [
            'host' => $host,
            'url_hash' => CatalogPage::hashFor($url),
            'url' => $url,
            'title' => null,
            'haystack' => mb_substr($this->haystackFor($url), 0, 2000),
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
        CatalogPage::query()->upsert($rows, ['url_hash'], ['haystack', 'last_seen_at', 'updated_at']);

        return count($rows);
    }

    private function fetch(string $url): ?string
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/xml,text/xml,text/plain,*/*',
            ])->timeout(30)->connectTimeout(8)->get($url);
        } catch (Throwable $e) {
            Log::info('Sitemap fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
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

    private function normalizeHost(string $host): string
    {
        $clean = mb_strtolower(trim(preg_replace('#^https?://#i', '', $host) ?? $host));
        $clean = trim(explode('/', $clean)[0] ?? $clean);

        return preg_replace('/^www\./', '', $clean) ?? $clean;
    }
}
