<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Darmowe szukanie HTML DuckDuckGo — warstwa sieci po stronie PHP (nie plugin OpenRouter).
 */
final class DuckDuckGoHtmlSearch
{
    /**
     * @param  list<string>  $includeDomains
     * @return list<array{url: string, title: string, snippet: string}>
     */
    public function search(string $query, int $maxResults = 8, array $includeDomains = []): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $sites = [];
        foreach ($includeDomains as $domain) {
            $host = strtolower(trim((string) $domain));
            $host = preg_replace('/^www\./', '', $host) ?? $host;
            if ($host !== '') {
                $sites[] = 'site:'.$host;
            }
        }
        if ($sites !== []) {
            $query .= ' '.implode(' ', array_slice($sites, 0, 3));
        }

        $html = $this->fetchHtml($query);
        $results = $this->parseHtml($html);
        if ($includeDomains !== []) {
            $results = $this->filterByDomains($results, $includeDomains);
        }

        return array_slice($results, 0, max(1, $maxResults));
    }

    public function fetchHtml(string $query): string
    {
        $headers = [
            'User-Agent' => 'Mozilla/5.0 (compatible; SUPON-AI/1.0; +https://przetargi.supon.rzeszow.pl)',
            'Accept' => 'text/html,application/xhtml+xml',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.6',
        ];

        $response = Http::withHeaders($headers)
            ->timeout(15)
            ->connectTimeout(6)
            ->get('https://html.duckduckgo.com/html/', [
                'q' => $query,
                'kl' => 'pl-pl',
            ]);

        if (! $response->successful() || trim($response->body()) === '') {
            $response = Http::withHeaders($headers)
                ->timeout(15)
                ->connectTimeout(6)
                ->asForm()
                ->post('https://lite.duckduckgo.com/lite/', [
                    'q' => $query,
                    'kl' => 'pl-pl',
                ]);
        }

        if (! $response->successful()) {
            throw new RuntimeException('DuckDuckGo HTTP '.$response->status().': brak wyników wyszukiwania.');
        }

        return (string) $response->body();
    }

    /**
     * @return list<array{url: string, title: string, snippet: string}>
     */
    public function parseHtml(string $html): array
    {
        $out = [];
        $seen = [];

        if (preg_match_all(
            '/<a[^>]*class="[^"]*result__a[^"]*"[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is',
            $html,
            $matches,
            PREG_SET_ORDER
        ) === 0) {
            preg_match_all(
                '/<a[^>]*href="([^"]+)"[^>]*class="[^"]*result__a[^"]*"[^>]*>(.*?)<\/a>/is',
                $html,
                $matches,
                PREG_SET_ORDER
            );
        }

        if ($matches === []) {
            preg_match_all(
                '/<a[^>]*rel="nofollow"[^>]*href="(https?:[^"]+)"[^>]*>(.*?)<\/a>/is',
                $html,
                $matches,
                PREG_SET_ORDER
            );
        } else {
            preg_match_all(
                '/<a[^>]*rel="nofollow"[^>]*href="(https?:[^"]+)"[^>]*>(.*?)<\/a>/is',
                $html,
                $extra,
                PREG_SET_ORDER
            );
            $matches = array_merge($matches, $extra);
        }

        foreach ($matches as $match) {
            $url = $this->decodeDdgUrl(html_entity_decode((string) ($match[1] ?? ''), ENT_QUOTES | ENT_HTML5));
            if ($url === null || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $title = trim(html_entity_decode(strip_tags((string) ($match[2] ?? '')), ENT_QUOTES | ENT_HTML5));
            $out[] = [
                'url' => $url,
                'title' => $title !== '' ? $title : $url,
                'snippet' => '',
            ];
        }

        return $out;
    }

    private function decodeDdgUrl(string $href): ?string
    {
        $href = trim($href);
        if ($href === '') {
            return null;
        }
        if (str_starts_with($href, '//')) {
            $href = 'https:'.$href;
        }

        if (preg_match('/[?&]uddg=([^&]+)/', $href, $m) === 1) {
            $href = urldecode($m[1]);
        }

        if (! str_starts_with($href, 'http://') && ! str_starts_with($href, 'https://')) {
            return null;
        }

        $host = strtolower((string) parse_url($href, PHP_URL_HOST));
        if ($host === '' || str_contains($host, 'duckduckgo.com') || str_contains($host, 'duck.com')) {
            return null;
        }

        return $href;
    }

    /**
     * @param  list<array{url: string, title: string, snippet: string}>  $results
     * @param  list<string>  $includeDomains
     * @return list<array{url: string, title: string, snippet: string}>
     */
    private function filterByDomains(array $results, array $includeDomains): array
    {
        $needles = [];
        foreach ($includeDomains as $domain) {
            $host = strtolower(trim((string) $domain));
            $host = preg_replace('/^www\./', '', $host) ?? $host;
            if ($host !== '') {
                $needles[] = $host;
            }
        }
        if ($needles === []) {
            return $results;
        }

        $out = [];
        foreach ($results as $row) {
            $host = strtolower((string) parse_url($row['url'], PHP_URL_HOST));
            $host = preg_replace('/^www\./', '', $host) ?? $host;
            foreach ($needles as $needle) {
                if ($host === $needle || str_ends_with($host, '.'.$needle)) {
                    $out[] = $row;
                    break;
                }
            }
        }

        return $out;
    }
}
