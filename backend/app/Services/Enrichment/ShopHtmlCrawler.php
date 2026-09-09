<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Crawler sklepu bez sitemapy — ten sam schemat co skrypt Python
 * (seedy kategorii, BFS, /productpage/, CODE/BRAND).
 */
final class ShopHtmlCrawler
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

    private const PROBE_TIMEOUT = 6;

    private const FETCH_TIMEOUT = 30;

    private const MAX_PAGES = 180;

    private const MAX_QUEUE = 250;

    private const MAX_PRODUCT_FETCH = 400;

    /**
     * @var list<string>
     */
    private const SEED_PATHS = [
        '/',
        '/products/productcategory/Footwear',
        '/products/productcategory/Workwear',
        '/products/productcategory/PPE',
        '/brands',
        '/resources',
        '/products',
        '/produkty',
    ];

    public function __construct(
        private readonly BlockedPageReader $reader,
    ) {}

    private bool $preferReader = false;

    private ?string $lockHost = null;

    /**
     * @return list<array{url: string, title: string, extra: string}>
     */
    public function crawl(string $host, int $maxUrls, float $deadline, callable $note): array
    {
        $this->preferReader = false;
        $this->lockHost = null;
        $queue = $this->seeds($host);
        $queued = array_fill_keys($queue, true);
        $fetched = [];
        $products = [];
        $pages = 0;
        $htmlOk = 0;
        $homeFails = 0;

        while ($queue !== [] && $pages < self::MAX_PAGES && count($products) < $maxUrls) {
            if (microtime(true) >= $deadline) {
                break;
            }
            $page = $this->lockUrl((string) array_shift($queue));
            $key = mb_strtolower(rtrim($page, '/'));
            if (isset($fetched[$key]) || $this->isJunkPath($page) || $this->isSkippable($page)) {
                $fetched[$key] = true;

                continue;
            }
            $fetched[$key] = true;
            $timeout = $this->preferReader ? self::FETCH_TIMEOUT : self::PROBE_TIMEOUT;
            $body = $this->fetchPage($page, $timeout);
            $pages++;
            if ($body === null) {
                if ($this->isHomepage($page)) {
                    $homeFails++;
                }
                $note(sprintf('[BRAK %d] kolejka=%d kart=%d %s', $pages, count($queue), count($products), $this->shortUrl($page)));
                if ($htmlOk === 0 && $homeFails >= 2) {
                    $note('Serwer nie pobiera HTML z tej witryny — przerywam.');
                    break;
                }

                continue;
            }
            $htmlOk++;
            if ($this->lockHost === null) {
                $this->lockHost = mb_strtolower((string) (parse_url($page, PHP_URL_HOST) ?? ''));
                if ($this->lockHost !== '') {
                    $note('Zostaję przy '.$this->lockHost.' — pomijam duplikaty www/apex i strony-śmieci.');
                }
            }
            if ($htmlOk === 1 && $this->preferReader) {
                $note('Dalej przez reader (sklep nie odpowiada bezpośrednio).');
            }
            $note(sprintf('[OK %d] kolejka=%d kart=%d %s', $pages, count($queue), count($products), $this->shortUrl($page)));

            if ($this->isClassicProduct($page)) {
                $identity = $this->identityFrom($body);
                $products[$page] = [
                    'url' => $page,
                    'title' => $identity['title'],
                    'extra' => $identity['extra'],
                ];
            }

            foreach ($this->extractLinks($body, $host, $page) as $href) {
                $href = $this->lockUrl($href);
                if ($this->isSkippable($href) || $this->isFilterPath($href) || $this->isJunkPath($href)) {
                    continue;
                }
                $classic = $this->isClassicProduct($href);
                $pretty = ! $classic && $this->isPrettyProduct($href);
                if ($classic || $pretty) {
                    if (! isset($products[$href])) {
                        $products[$href] = ['url' => $href, 'title' => '', 'extra' => ''];
                    }
                    if (count($products) >= $maxUrls) {
                        break 2;
                    }
                }
                if ($classic) {
                    continue;
                }
                if (! isset($queued[$href]) && count($queue) < self::MAX_QUEUE) {
                    $queue[] = $href;
                    $queued[$href] = true;
                }
            }
        }

        $rows = array_values($products);
        if ($rows !== []) {
            $note('Z pełzania '.$pages.' stron mam '.count($rows).' kart — czytam te bez kodu w adresie.');
            $rows = $this->enrich($rows, $deadline, $note);
            $rows = array_values(array_filter(
                $rows,
                fn (array $row): bool => ! $this->isErrorPageTitle((string) ($row['title'] ?? ''))
            ));
        } else {
            $note('Pełzanie: '.$pages.' stron, HTML OK: '.$htmlOk.', 0 kart.');
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function seeds(string $host): array
    {
        $out = ['https://www.'.$host.'/', 'https://'.$host.'/'];
        $extra = $this->isIaiShop($host)
            ? ['/polbuty/', '/sandaly/', '/trzewiki/', '/rekawice-robocze/', '/odziez-robocza/']
            : array_values(array_filter(self::SEED_PATHS, static fn (string $path): bool => $path !== '/'));
        foreach ($extra as $path) {
            $out[] = 'https://www.'.$host.$path;
            $out[] = 'https://'.$host.$path;
        }

        return $out;
    }

    private function fetchPage(string $url, int $timeout): ?string
    {
        foreach ($this->fetchCandidates($url) as $candidate) {
            if (! $this->preferReader) {
                $direct = $this->usableHtml($this->fetchDirect($candidate, $timeout));
                if ($direct !== null) {
                    return $direct;
                }
            }
            $via = $this->usableHtml($this->reader->fetchForCrawl($candidate));
            if ($via !== null) {
                $this->preferReader = true;

                return $via;
            }
        }

        return null;
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

    private function usableHtml(?string $html): ?string
    {
        if ($html === null || $this->isErrorPageHtml($html)) {
            return null;
        }

        return $html;
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

    private function fetchDirect(string $url, int $timeout): ?string
    {
        $timeout = max(5, $timeout);
        try {
            $response = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'pl-PL,pl;q=0.9,en;q=0.8',
            ])->timeout($timeout)->connectTimeout(min(5, $timeout))
                ->get($url);
        } catch (Throwable $e) {
            Log::info('Shop HTML fetch failed', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
        if (! $response->successful()) {
            return null;
        }
        $type = mb_strtolower((string) $response->header('Content-Type'));
        if (str_contains($type, 'image/') || str_contains($type, 'xml')) {
            return null;
        }
        $body = (string) $response->body();
        if ($body === '' || mb_strlen($body) < 40) {
            return null;
        }

        return mb_substr($body, 0, 400000);
    }

    /**
     * @param  list<array{url: string, title: string, extra: string}>  $rows
     * @return list<array{url: string, title: string, extra: string}>
     */
    private function enrich(array $rows, float $deadline, callable $note): array
    {
        $n = 0;
        foreach ($rows as $i => $row) {
            if ($n >= self::MAX_PRODUCT_FETCH || microtime(true) >= $deadline) {
                break;
            }
            if ($row['extra'] !== '' || $this->urlHasCode($row['url'])) {
                continue;
            }
            $body = $this->fetchPage($row['url'], self::FETCH_TIMEOUT);
            $n++;
            $note(sprintf('[KARTA %d] %s', $n, $this->shortUrl($row['url'])));
            if ($body === null) {
                continue;
            }
            $identity = $this->identityFrom($body);
            if ($identity['title'] === '' && $identity['extra'] === '') {
                continue;
            }
            $rows[$i] = [
                'url' => $row['url'],
                'title' => $identity['title'],
                'extra' => $identity['extra'],
            ];
        }

        return $rows;
    }

    /**
     * @return array{title: string, extra: string}
     */
    private function identityFrom(string $body): array
    {
        if ($this->isErrorPageHtml($body)) {
            return ['title' => '', 'extra' => ''];
        }
        $title = '';
        if (preg_match('/<h1\b[^>]*>(.*?)<\/h1>/is', $body, $m) === 1) {
            $title = $this->plain($m[1]);
        } elseif (preg_match('/^#\s+(.+)$/m', $body, $m) === 1) {
            $title = $this->plain($m[1]);
        } elseif (preg_match('/<title\b[^>]*>(.*?)<\/title>/is', $body, $m) === 1) {
            $title = $this->plain($m[1]);
        }
        $title = mb_substr($title, 0, 500);
        $text = $this->plain(mb_substr($body, 0, 120000));
        $bits = [];
        foreach ([
            '/\bCODE\s*:?\s*([A-Z0-9][A-Z0-9\/\-]{2,24})/i',
            '/\bSKU\s*:?\s*([A-Z0-9][A-Z0-9\/\-]{2,24})/i',
            '/\b(?:Kod(?:\s+(?:produktu|towaru))?|Art(?:icle|\.)?\s*(?:nr\.?|no\.?|number)?)\s*:?\s*([A-Z0-9][A-Z0-9\/\-]{2,24})/i',
            '/\bBRAND\s*:?\s*(.+?)(?=\s+(?:CODE|SKU|SIZES|COLOURS|PRICE|Kod|Producent|Marka)\s*:|$)/i',
        ] as $re) {
            if (preg_match($re, $text, $m) === 1) {
                $bit = trim((string) $m[1]);
                if ($bit !== '' && mb_strlen($bit) <= 80) {
                    $bits[] = $bit;
                }
            }
        }

        return [
            'title' => $title,
            'extra' => mb_substr(trim($title.' '.implode(' ', $bits)), 0, 500),
        ];
    }

    /**
     * @return list<string>
     */
    private function extractLinks(string $body, string $host, string $page): array
    {
        $hrefs = [];
        if (preg_match_all('/href\s*=\s*["\']([^"\']+)["\']/i', $body, $m) > 0) {
            $hrefs = $m[1];
        }
        if (preg_match_all('/\]\((https?:[^)\s]+|\/[^)\s]+)\)/i', $body, $m) > 0) {
            $hrefs = array_merge($hrefs, $m[1]);
        }
        $out = [];
        foreach ($hrefs as $href) {
            $resolved = $this->resolveHref((string) $href, $host, $page);
            if ($resolved !== null) {
                $out[] = $resolved;
            }
        }

        return $out;
    }

    private function resolveHref(string $href, string $host, string $page): ?string
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
        $urlHost = mb_strtolower((string) (parse_url($href, PHP_URL_HOST) ?? ''));
        $urlHost = preg_replace('/^www\./', '', $urlHost) ?? $urlHost;
        if (! $this->hostAllowed($urlHost, $host)) {
            return null;
        }

        return $this->normalizeUrl($href);
    }

    private function hostAllowed(string $urlHost, string $crawlHost): bool
    {
        $crawlHost = preg_replace('/^www\./', '', mb_strtolower($crawlHost)) ?? $crawlHost;
        if ($urlHost === $crawlHost || str_ends_with($urlHost, '.'.$crawlHost)) {
            return true;
        }

        return $this->iaiTwinBare($crawlHost) === $urlHost;
    }

    private function isIaiShop(string $host): bool
    {
        $host = preg_replace('/^www\./', '', mb_strtolower($host)) ?? $host;

        return in_array($host, ['gvarant.pl', 'robocze-buty.pl'], true);
    }

    private function iaiTwinBare(string $host): ?string
    {
        $host = preg_replace('/^www\./', '', mb_strtolower($host)) ?? $host;

        return ['robocze-buty.pl' => 'gvarant.pl', 'gvarant.pl' => 'robocze-buty.pl'][$host] ?? null;
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

    private function normalizeUrl(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return $url;
        }
        $path = (string) ($parts['path'] ?? '/');
        $query = (string) ($parts['query'] ?? '');
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
        $out = ((string) ($parts['scheme'] ?? 'https')).'://'.$parts['host'].($path !== '' ? $path : '/');
        if ($kept !== []) {
            $out .= '?'.http_build_query($kept);
        }

        return $out;
    }

    private function isClassicProduct(string $url): bool
    {
        $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        $query = mb_strtolower((string) (parse_url($url, PHP_URL_QUERY) ?? ''));
        if (str_contains($query, 'id_product=')) {
            return true;
        }

        return $this->isIaiProductCard($path)
            || preg_match('#-p\d{2,}(\.html)?$#', $path) === 1
            || preg_match('#/(product|produkt)/[^/]+#', $path) === 1
            || preg_match('#/productpage(/|$)#', $path) === 1
            || preg_match('#/p/[^/]+/\d+#', $path) === 1;
    }

    private function isPrettyProduct(string $url): bool
    {
        $host = preg_replace('/^www\./', '', mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''))) ?? '';
        if (in_array($host, ['gvarant.pl', 'robocze-buty.pl'], true)) {
            return false;
        }
        $path = mb_strtolower(trim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/'));
        if ($path === '') {
            return false;
        }
        $slug = preg_replace('/\.(html?|php)$/i', '', (string) basename($path)) ?? '';
        if ($slug === '' || str_contains($slug, ',') || ! str_contains($slug, '-') || mb_strlen($slug) < 8) {
            return false;
        }
        if (preg_match('/\p{L}/u', $slug) !== 1) {
            return false;
        }
        foreach (['o-nas', 'about-us', 'kontakt', 'contact', 'regulamin', 'privacy', 'cookies', 'login'] as $bad) {
            if ($slug === $bad) {
                return false;
            }
        }

        return true;
    }

    private function lockUrl(string $url): string
    {
        if ($this->lockHost === null || $this->lockHost === '') {
            return $url;
        }
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['host'])) {
            return $url;
        }
        if (mb_strtolower((string) $parts['host']) === $this->lockHost) {
            return $url;
        }
        $path = (string) ($parts['path'] ?? '/');
        if ($this->isIaiProductCard($path)) {
            $from = preg_replace('/^www\./', '', mb_strtolower((string) $parts['host'])) ?? '';
            $to = preg_replace('/^www\./', '', $this->lockHost) ?? '';
            if ($this->iaiTwinBare($from) === $to) {
                return $url;
            }
        }
        $out = ((string) ($parts['scheme'] ?? 'https')).'://'.$this->lockHost.($path !== '' ? $path : '/');
        if (isset($parts['query']) && $parts['query'] !== '') {
            $out .= '?'.$parts['query'];
        }

        return $out;
    }

    private function isJunkPath(string $url): bool
    {
        $path = mb_strtolower(trim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/'));
        $path = preg_replace('/\.(php|html?)$/i', '', $path) ?? $path;
        return in_array($path, [
            'index', 'privacypolicy', 'privacy-policy', 'privacy', 'company', 'contact',
            'about', 'about-us', 'terms', 'cookies', 'productpage',
        ], true);
    }

    private function isFilterPath(string $url): bool
    {
        $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        foreach (['/status/', '/producttype/', '/productstyle/', '/pricerange/', '/recommend/', '/colour/', '/color/'] as $bad) {
            if (str_contains($path, $bad)) {
                return true;
            }
        }
        if ($this->isIaiProductCard($path)) {
            return false;
        }

        return str_contains((string) basename(rtrim($path, '/')), ',');
    }

    private function isIaiProductCard(string $path): bool
    {
        $path = mb_strtolower(rtrim($path, '/'));

        return str_ends_with($path, '.html') && preg_match('#/p\d+,[^/]+$#', $path) === 1;
    }

    private function isSkippable(string $url): bool
    {
        $hay = mb_strtolower($url);
        foreach (['/login', '/account', '/admin', '/checkout', '/cart', '/koszyk', '/konto', '/logoimages/', '/fieldimages/'] as $bad) {
            if (str_contains($hay, $bad)) {
                return true;
            }
        }

        return preg_match('#\.(jpe?g|png|gif|webp|css|js|pdf|svg|zip)$#i', (string) (parse_url($url, PHP_URL_PATH) ?? '')) === 1;
    }

    private function isHomepage(string $url): bool
    {
        return trim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/') === '';
    }

    private function urlHasCode(string $url): bool
    {
        $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        foreach (preg_split('/[^a-z0-9]+/u', $path) ?: [] as $token) {
            if (preg_match('/^(?=.*[a-z])(?=.*[0-9])[a-z0-9]{5,}$/u', $token) === 1) {
                return true;
            }
        }

        return false;
    }

    private function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function shortUrl(string $url): string
    {
        return mb_strlen($url) > 90 ? mb_substr($url, 0, 87).'…' : $url;
    }
}
