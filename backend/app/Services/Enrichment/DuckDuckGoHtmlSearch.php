<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Darmowe szukanie po stronie PHP (nie plugin OpenRouter, nie Tavily).
 * DuckDuckGo przy wielu równoległych jobach z cennika często zwraca 403 —
 * dlatego: cache, jedna bramka, przerwa między requestami, zapas Qwant/Bing.
 */
final class DuckDuckGoHtmlSearch
{
    private const QUERY_CACHE_PREFIX = 'free_web_search_v2:';

    private const GATE_KEY = 'free_web_search_gate';

    private const LAST_AT_KEY = 'free_web_search_last_at';

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

        $cacheKey = self::QUERY_CACHE_PREFIX.hash('sha256', $query);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            $results = $includeDomains !== [] ? $this->filterByDomains($cached, $includeDomains) : $cached;

            return array_slice($results, 0, max(1, $maxResults));
        }

        $this->acquireGate();
        try {
            $cached = Cache::get($cacheKey);
            if (! is_array($cached)) {
                $cached = $this->searchUncached($query);
                if ($cached !== []) {
                    Cache::put($cacheKey, $cached, now()->addHours(6));
                }
            }
        } finally {
            $this->releaseGate();
        }

        $results = $includeDomains !== [] ? $this->filterByDomains($cached, $includeDomains) : $cached;

        return array_slice($results, 0, max(1, $maxResults));
    }

    /**
     * @return list<array{url: string, title: string, snippet: string}>
     */
    private function searchUncached(string $query): array
    {
        $errors = [];

        try {
            $this->throttle();
            $results = $this->searchGoogle($query);
            if ($results !== []) {
                return $results;
            }
            $errors[] = 'Google: brak wyników';
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        try {
            $this->throttle();
            $results = $this->searchBing($query);
            if ($results !== []) {
                return $results;
            }
            $errors[] = 'Bing: brak wyników';
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        try {
            $this->throttle();
            $html = $this->fetchHtml($query);
            $results = $this->parseHtml($html);
            if ($results !== []) {
                return $results;
            }
            $errors[] = 'DuckDuckGo: pusta strona wyników';
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        try {
            $this->throttle();
            $results = $this->searchQwant($query);
            if ($results !== []) {
                return $results;
            }
            $errors[] = 'Qwant: brak wyników';
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        throw new RuntimeException(
            $errors !== []
                ? implode(' | ', $errors)
                : 'Darmowe wyszukiwanie nie zwróciło wyników.'
        );
    }

    public function fetchHtml(string $query): string
    {
        $attempts = [
            ['https://html.duckduckgo.com/html/', ['q' => $query, 'kl' => 'pl-pl']],
            ['https://lite.duckduckgo.com/lite/', ['q' => $query, 'kl' => 'pl-pl']],
        ];
        $lastStatus = 0;
        foreach ($attempts as [$url, $params]) {
            try {
                $response = Http::withHeaders($this->browserHeaders())
                    ->timeout(12)
                    ->connectTimeout(5)
                    ->get($url, $params);
                $lastStatus = $response->status();
                if (! $response->successful()) {
                    continue;
                }
                $html = (string) $response->body();
                if ($html === '' || $this->isBlockedSearchPage($html)) {
                    continue;
                }

                return $html;
            } catch (Throwable) {
                continue;
            }
        }

        throw new RuntimeException(
            $lastStatus > 0
                ? 'DuckDuckGo HTTP '.$lastStatus.': brak wyników wyszukiwania.'
                : 'DuckDuckGo: timeout/captcha — brak wyników wyszukiwania.'
        );
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

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{url: string, title: string, snippet: string}>
     */
    public function parseQwantJson(array $payload): array
    {
        $items = data_get($payload, 'data.result.items', []);
        if (! is_array($items)) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (($item['type'] ?? '') !== '' && ($item['type'] ?? '') !== 'web') {
                $nested = $item['items'] ?? [];
                if (is_array($nested)) {
                    foreach ($nested as $row) {
                        if (is_array($row)) {
                            $this->pushHit($out, $seen, (string) ($row['url'] ?? ''), (string) ($row['title'] ?? ''), (string) ($row['desc'] ?? ''));
                        }
                    }
                }

                continue;
            }
            $this->pushHit($out, $seen, (string) ($item['url'] ?? ''), (string) ($item['title'] ?? ''), (string) ($item['desc'] ?? ''));
        }

        return $out;
    }

    /**
     * @return list<array{url: string, title: string, snippet: string}>
     */
    public function parseBingHtml(string $html): array
    {
        $out = [];
        $seen = [];
        if (preg_match_all(
            '/<li[^>]*class="[^"]*b_algo[^"]*"[^>]*>.*?<h2[^>]*>\s*<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/is',
            $html,
            $matches,
            PREG_SET_ORDER
        ) === 0) {
            return [];
        }

        foreach ($matches as $match) {
            $url = $this->decodeBingUrl((string) ($match[1] ?? ''));
            if ($url === null) {
                continue;
            }
            $snippet = '';
            $block = (string) ($match[0] ?? '');
            if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $block, $p) === 1) {
                $snippet = trim(html_entity_decode(strip_tags($p[1]), ENT_QUOTES | ENT_HTML5));
            } elseif (preg_match('/<cite[^>]*>(.*?)<\/cite>/is', $block, $cite) === 1) {
                $snippet = trim(html_entity_decode(strip_tags($cite[1]), ENT_QUOTES | ENT_HTML5));
            }
            $this->pushHit(
                $out,
                $seen,
                $url,
                html_entity_decode(strip_tags((string) ($match[2] ?? '')), ENT_QUOTES | ENT_HTML5),
                $snippet
            );
        }

        return $out;
    }

    /**
     * @return list<array{url: string, title: string, snippet: string}>
     */
    public function parseGoogleHtml(string $html): array
    {
        if ($this->isBlockedSearchPage($html)) {
            return [];
        }

        $out = [];
        $seen = [];
        if (preg_match_all('/<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        foreach ($matches as $match) {
            $href = html_entity_decode((string) ($match[1] ?? ''), ENT_QUOTES | ENT_HTML5);
            if (! str_contains($href, '/url?') && ! str_starts_with($href, 'http')) {
                continue;
            }
            $url = $this->decodeGoogleUrl($href);
            if ($url === null) {
                continue;
            }
            $title = trim(html_entity_decode(strip_tags((string) ($match[2] ?? '')), ENT_QUOTES | ENT_HTML5));
            if ($title === '' || preg_match('/^(cached|podobne|translate|tłumacz)$/iu', $title) === 1) {
                continue;
            }
            $this->pushHit($out, $seen, $url, $title, '');
        }

        return $out;
    }

    /**
     * @return list<array{url: string, title: string, snippet: string}>
     */
    private function searchQwant(string $query): array
    {
        $response = Http::withHeaders($this->browserHeaders())
            ->timeout(12)
            ->connectTimeout(6)
            ->get('https://api.qwant.com/v3/search/web', [
                'q' => $query,
                'count' => 10,
                'locale' => 'pl_PL',
                'offset' => 0,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Qwant HTTP '.$response->status().': brak wyników wyszukiwania.');
        }

        $payload = $response->json();

        return is_array($payload) ? $this->parseQwantJson($payload) : [];
    }

    /**
     * @return list<array{url: string, title: string, snippet: string}>
     */
    /**
     * @return list<array{url: string, title: string, snippet: string}>
     */
    private function searchGoogle(string $query): array
    {
        $response = Http::withHeaders($this->browserHeaders())
            ->withCookies([
                'CONSENT' => 'YES+',
                'SOCS' => 'CAISNQgDEitib3FfaWRlbnRpdHlmcm9udGVuZHVpc2VydmVyXzIwMjQwMTI5LjA4X3AxGgJlbiACGgYIgNzZsgY',
            ], '.google.com')
            ->timeout(15)
            ->connectTimeout(8)
            ->get('https://www.google.com/search', [
                'q' => $query,
                'hl' => 'pl',
                'gl' => 'pl',
                'gbv' => '1',
                'num' => 10,
                'pws' => 0,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Google HTTP '.$response->status().': brak wyników wyszukiwania.');
        }

        $html = (string) $response->body();
        if ($this->isBlockedSearchPage($html)) {
            throw new RuntimeException('Google: zgoda/captcha — brak wyników wyszukiwania.');
        }

        return $this->parseGoogleHtml($html);
    }

    private function searchBing(string $query): array
    {
        $response = Http::withHeaders($this->browserHeaders())
            ->timeout(15)
            ->connectTimeout(8)
            ->get('https://www.bing.com/search', [
                'q' => $query,
                'setlang' => 'pl-PL',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Bing HTTP '.$response->status().': brak wyników wyszukiwania.');
        }

        return $this->parseBingHtml((string) $response->body());
    }

    /**
     * @return array<string, string>
     */
    private function browserHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept' => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8',
            'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.6',
        ];
    }

    private function acquireGate(): void
    {
        $deadline = microtime(true) + (app()->environment('testing') ? 2 : 25);
        while (! Cache::add(self::GATE_KEY, 1, 20)) {
            if (microtime(true) >= $deadline) {
                break;
            }
            usleep(app()->environment('testing') ? 10000 : 200000);
        }
    }

    private function releaseGate(): void
    {
        Cache::forget(self::GATE_KEY);
    }

    private function throttle(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        $last = (float) Cache::get(self::LAST_AT_KEY, 0);
        $wait = 0.9 - (microtime(true) - $last);
        if ($wait > 0) {
            usleep((int) round($wait * 1_000_000));
        }
        Cache::put(self::LAST_AT_KEY, microtime(true), 30);
    }

    private function isBlockedSearchPage(string $html): bool
    {
        $hay = mb_strtolower($html);

        return str_contains($hay, 'anomaly-modal')
            || str_contains($hay, 'unfortunately, bots use duckduckgo')
            || str_contains($hay, 'zanim przejdziesz do wyszukiwarki')
            || str_contains($hay, 'before you continue to google')
            || str_contains($hay, 'unusual traffic')
            || str_contains($hay, 'challenge_version')
            || (str_contains($hay, 'consent.google.com') && ! str_contains($hay, '/url?q='));
    }

    private function decodeGoogleUrl(string $href): ?string
    {
        $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5);
        if (str_contains($href, '/url?') || str_contains($href, 'google.com/url')) {
            $query = (string) (parse_url($href, PHP_URL_QUERY) ?? '');
            parse_str($query, $params);
            $href = (string) ($params['q'] ?? $params['url'] ?? '');
        }

        return $this->publicHttpUrl($href);
    }

    private function decodeBingUrl(string $href): ?string
    {
        $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5);
        if (preg_match('/[?&]u=([^&]+)/', $href, $m) === 1) {
            $raw = urldecode($m[1]);
            if (str_starts_with($raw, 'a1')) {
                $raw = substr($raw, 2);
            }
            $decoded = $this->decodeBase64Url($raw);
            if ($decoded !== null) {
                return $this->publicHttpUrl($decoded);
            }
        }

        return $this->publicHttpUrl($href);
    }

    private function decodeBase64Url(string $raw): ?string
    {
        $raw = strtr($raw, '-_', '+/');
        $pad = strlen($raw) % 4;
        if ($pad !== 0) {
            $raw .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($raw, true);

        return is_string($decoded) && $decoded !== '' ? $decoded : null;
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

        return $this->publicHttpUrl($href);
    }

    /**
     * @param  list<array{url: string, title: string, snippet: string}>  $out
     * @param  array<string, true>  $seen
     */
    private function pushHit(array &$out, array &$seen, string $url, string $title, string $snippet): void
    {
        $url = $this->publicHttpUrl($url);
        if ($url === null || isset($seen[$url])) {
            return;
        }
        $seen[$url] = true;
        $title = trim($title);
        $out[] = [
            'url' => $url,
            'title' => $title !== '' ? $title : $url,
            'snippet' => trim($snippet),
        ];
    }

    private function publicHttpUrl(string $href): ?string
    {
        $href = trim($href);
        if (! str_starts_with($href, 'http://') && ! str_starts_with($href, 'https://')) {
            return null;
        }

        $host = strtolower((string) parse_url($href, PHP_URL_HOST));
        if ($host === ''
            || str_contains($host, 'duckduckgo.com')
            || str_contains($host, 'duck.com')
            || str_contains($host, 'bing.com')
            || str_contains($host, 'qwant.com')
            || preg_match('/(^|\.)google\./', $host) === 1
            || str_contains($host, 'googleusercontent.com')
            || str_contains($host, 'gstatic.com')) {
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
