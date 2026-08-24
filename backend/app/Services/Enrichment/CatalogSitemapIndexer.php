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

    private const CANDIDATE_PATHS = [
        '/sitemap.xml',
        '/sitemap_index.xml',
        '/sitemap-index.xml',
        '/sitemap/sitemap.xml',
        '/1_pl_0_sitemap.xml',
    ];

    /**
     * @return array{urls: int, saved: int, sitemaps: list<string>}
     */
    public function index(string $host, int $maxUrls = 20000): array
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

        // indeks sitemap dokłada kolejne pliki w trakcie — foreach nie zobaczyłby dopisanych
        for ($i = 0; $i < count($sitemaps) && $i < self::MAX_SITEMAP_FILES; $i++) {
            $sitemap = $sitemaps[$i];
            if (count($seen) >= $maxUrls) {
                break;
            }
            $body = $this->fetch($sitemap);
            if ($body === null) {
                continue;
            }
            $used[] = $sitemap;

            foreach ($this->extractLocations($body) as $loc) {
                if ($this->looksLikeSitemap($loc)) {
                    if (count($sitemaps) < self::MAX_SITEMAP_FILES && ! in_array($loc, $sitemaps, true)) {
                        $sitemaps[] = $loc;
                    }

                    continue;
                }
                if (! $this->belongsToHost($loc, $host) || isset($seen[$loc])) {
                    continue;
                }
                $seen[$loc] = true;
                $rows[] = $this->rowFor($host, $loc);
                if (count($rows) >= 500) {
                    $saved += $this->store($rows);
                    $rows = [];
                }
                if (count($seen) >= $maxUrls) {
                    break;
                }
            }
        }

        if ($rows !== []) {
            $saved += $this->store($rows);
        }

        Log::info('Catalog sitemap indexed', ['host' => $host, 'urls' => count($seen), 'saved' => $saved]);

        return ['urls' => count($seen), 'saved' => $saved, 'sitemaps' => $used];
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
     * @return list<string>
     */
    public function extractLocations(string $xml): array
    {
        if (preg_match_all('#<loc>\s*(.*?)\s*</loc>#si', $xml, $m) === 0) {
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
                'User-Agent' => 'Mozilla/5.0 (compatible; PrzetargiBot/1.0; +https://przetargi.supon.rzeszow.pl)',
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
