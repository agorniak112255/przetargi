<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Szuka karty w wyszukiwarce sklepu (Magento / Presta / Shoper / Woo / Shopify),
 * gdy Google/DDG dają 429 albo sitelink nie nosi SKU.
 */
final class RetailerOnSiteSearch
{
    /** @var list<array{host: string, template: string}> */
    private const ENDPOINTS = [
        ['host' => 'bpbhp.pl', 'template' => 'https://bpbhp.pl/catalogsearch/result/?q={q}'],
        ['host' => 'optimumbhp.pl', 'template' => 'https://optimumbhp.pl/search?controller=search&s={q}'],
        ['host' => 'gvarant.pl', 'template' => 'https://gvarant.pl/module/iqitsearch/searchiqit?s={q}'],
        ['host' => 'icd.pl', 'template' => 'https://icd.pl/szukaj?controller=search&s={q}'],
    ];

    /** @var list<array{host: string, template: string}> */
    private const IDOSELL_ENDPOINTS = [
        ['host' => 'kams.com.pl', 'template' => 'https://kams.com.pl/?d=szukaj&szukaj={q}'],
        ['host' => 'behapownia.pl', 'template' => 'https://behapownia.pl/?d=szukaj&szukaj={q}'],
        ['host' => 'specto.com.pl', 'template' => 'https://specto.com.pl/?d=szukaj&szukaj={q}'],
        ['host' => 'aitbhp.pl', 'template' => 'https://aitbhp.pl/?d=szukaj&szukaj={q}'],
        ['host' => 'promocjabhp.pl', 'template' => 'https://www.promocjabhp.pl/?search={q}'],
    ];

    /** @var list<array{host: string, template: string}> */
    private const SHOPER_ENDPOINTS = [
        ['host' => 'antar.pl', 'template' => 'https://antar.pl/pl/searchquery/{q}/1'],
    ];

    /** @var list<array{host: string, template: string}> */
    private const WOO_ENDPOINTS = [
        ['host' => 'roboczystyl.pl', 'template' => 'https://roboczystyl.pl/?s={q}&post_type=product'],
        ['host' => 'regera.pl', 'template' => 'https://regera.pl/?s={q}&post_type=product'],
        ['host' => 'customguns.pl', 'template' => 'https://customguns.pl/?s={q}&post_type=product'],
        ['host' => 'sklep-system.pl', 'template' => 'https://sklep-system.pl/?s={q}&post_type=product'],
    ];

    /** @var list<array{host: string, template: string}> */
    private const SHOPIFY_ENDPOINTS = [
        ['host' => 'novarlo.com', 'template' => 'https://novarlo.com/search?q={q}'],
        ['host' => 'workweargurus.com', 'template' => 'https://workweargurus.com/search?q={q}'],
    ];

    /** @var list<array{host: string, template: string}> */
    private const BIGCOMMERCE_ENDPOINTS = [
        ['host' => 'idsblast.com', 'template' => 'https://idsblast.com/search.php?search_query={q}'],
    ];

    /** @var array{host: string, template: string} */
    private const MAPA_ENDPOINT = [
        'host' => 'mapa-pro.pl',
        'template' => 'https://www.mapa-pro.pl/wyszukiwanie-zaawansowane?tx_solr[filter][0]=type:tx_mapaproduct_domain_model_product&tx_solr[q]={q}',
    ];

    /** @var array{host: string, template: string} */
    private const MAREL_ENDPOINT = [
        'host' => 'marelplus.pl',
        'template' => 'https://marelplus.pl/szukaj?controller=search&s={q}',
    ];

    /** @var array{host: string, template: string} */
    private const MISTERWORKER_ENDPOINT = [
        'host' => 'misterworker.com',
        'template' => 'https://www.misterworker.com/en/search?q={q}',
    ];

    private const MISTERWORKER_CLERK_SEARCH = 'https://api.clerk.io/v2/search/search';

    private const MISTERWORKER_ORIGIN = 'https://www.misterworker.com';

    private const MISTERWORKER_RESOLVE_LIMIT = 3;

    private const MISTERWORKER_REDIRECT_HOPS = 3;

    private const CLERK_KEY_CACHE = 'enrichment:misterworker_clerk_key';

    public function __construct(private readonly ProductSearchIdentity $identity) {}

    /**
     * @return list<array{url: string, title: string, snippet: string}>
     */
    public function find(Product $product): array
    {
        $endpoints = $this->endpointsFor($product);
        if ($endpoints === []) {
            return [];
        }
        $queries = $this->shopQueries($product);
        if ($queries === []) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($queries as $query) {
            foreach ($endpoints as $endpoint) {
                if ($endpoint['host'] === 'misterworker.com') {
                    $hits = $this->misterworkerHits($query);
                } else {
                    $page = $this->fetch(str_replace('{q}', rawurlencode($query), $endpoint['template']));
                    $hits = $this->productLinks($page['html'], $endpoint['host']);
                    $redirect = $this->productPageFromRedirect($page['url'], $endpoint['host']);
                    if ($redirect !== null) {
                        array_unshift($hits, $redirect);
                    }
                }
                if ($hits === []) {
                    continue;
                }
                foreach ($hits as $hit) {
                    $key = mb_strtolower($hit['url']);
                    if (isset($seen[$key])) {
                        continue;
                    }
                    $hay = $hit['url'].' '.$hit['title'];
                    if (! $this->identity->hayMentionsProduct($hay, $product)
                        || $this->identity->pageClaimsAnotherCode($hit['url'], $hit['title'], $product)) {
                        continue;
                    }
                    if ($endpoint['host'] === 'misterworker.com'
                        && ! $this->identity->hayHasProductCode(mb_strtolower($hay), $product)) {
                        continue;
                    }
                    $seen[$key] = true;
                    $out[] = $hit;
                }
                if ($out !== []) {
                    return $out;
                }
            }
        }

        return $out;
    }

    public function query(Product $product): string
    {
        $early = $this->identity->ansellSearchPhrases($product, 'early');
        if ($early !== []) {
            return $early[0];
        }
        if ($this->identity->looksLikeWarehouseArticleSku($product)) {
            $article = $this->identity->catalogArticleCodes($product)[0] ?? '';
            if ($article !== '' && preg_match('/\p{L}/u', $article) === 1) {
                return $article;
            }
            $sku = trim((string) $product->sku);
            if ($sku !== '') {
                return $sku;
            }
        }
        $shop = $this->identity->firstStrongShopPhrase($product);
        if ($shop === '') {
            $shop = $this->identity->shopIdentityPhrases($product)[0] ?? '';
        }
        $coded = $this->identity->catalogSkuWithoutSize($product);
        if ($coded !== '' && preg_match('/\p{L}/u', $coded) === 1 && preg_match('/\d/u', $coded) === 1) {
            $compact = (string) preg_replace('/\s+/u', '', $coded);
            if ($shop === ''
                || preg_match('/\d/u', $shop) !== 1
                || preg_match('/^\p{L}{1,2}\s*\d{2,6}$/u', $shop) === 1) {
                return $compact;
            }
        }
        if ($shop !== '') {
            return $shop;
        }

        $sku = $this->identity->catalogSkuWithoutSize($product);
        if ($sku !== '' && ! $this->identity->looksLikeInternalSku($product)) {
            return trim($this->identity->shortBrand((string) $product->manufacturer).' '.$sku);
        }

        return '';
    }

    public function queryBareModel(Product $product): string
    {
        if ($this->identity->looksLikeWarehouseArticleSku($product)) {
            return $this->identity->shopIdentityPhrases($product)[0] ?? '';
        }
        $late = $this->identity->ansellSearchPhrases($product, 'late');

        return $late[0] ?? '';
    }

    /**
     * Sklepy często indeksują „SL-46”, a cennik ma „SL 46”.
     *
     * @return list<string>
     */
    public function shopQueries(Product $product): array
    {
        $out = [];
        foreach ([
            $this->query($product),
            $this->identity->firstStrongShopPhrase($product),
            $this->queryBareModel($product),
        ] as $query) {
            $query = trim($query);
            if ($query === '') {
                continue;
            }
            $out[] = $query;
            $hyphen = trim((string) preg_replace('/\s+/u', '-', $query));
            if ($hyphen !== '' && $hyphen !== $query) {
                $out[] = $hyphen;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<array{url: string, title: string, snippet: string}>
     */
    public function productLinks(string $html, string $host): array
    {
        $out = [];
        if (preg_match_all(
            '/<a\b([^>]*)href=["\']([^"\']+)["\']([^>]*)>(.*?)<\/a>/is',
            $html,
            $matches,
            PREG_SET_ORDER
        ) === 0) {
            return [];
        }
        foreach ($matches as $hit) {
            $url = html_entity_decode(trim($hit[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($url === '' || str_starts_with($url, '#')
                || str_starts_with($url, 'javascript:') || str_starts_with($url, 'mailto:')) {
                continue;
            }
            if (str_starts_with($url, '//')) {
                $url = 'https:'.$url;
            } elseif (preg_match('#^https?://#i', $url) !== 1) {
                $url = 'https://'.$host.'/'.ltrim($url, '/');
            }
            $pageHost = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
            $pageHost = preg_replace('/^www\./', '', $pageHost) ?? $pageHost;
            if ($pageHost !== $host && ! str_ends_with($pageHost, '.'.$host)) {
                continue;
            }
            $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
            if ($path === '' || $path === '/' || str_ends_with($path, '/index.php')
                || preg_match('#/(searchquery|search\.php|catalogsearch|wyszukiwanie)(/|$)|/(search|category|kategoria|collections|producent|manufacturer|customer|checkout|cart|login)(/|$)#u', $path) === 1) {
                continue;
            }
            $url = $this->cleanProductUrl($url);
            $title = trim(html_entity_decode(strip_tags($hit[4]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($title === '' || mb_strlen($title) < 8) {
                $labeled = $this->ariaLabelFromAttrs($hit[1].' '.$hit[3]);
                if ($labeled !== '') {
                    $title = $labeled;
                }
            }
            if ($title === '' || mb_strlen($title) < 8) {
                $fromSlug = $this->titleFromProductPath($path);
                if ($fromSlug !== '') {
                    $title = $fromSlug;
                }
            }
            if ($title === '' || (mb_strlen($title) < 8 && preg_match('/\d/u', $title) !== 1)) {
                continue;
            }
            $out[mb_strtolower($url)] = [
                'url' => $url,
                'title' => $title,
                'snippet' => '',
            ];
        }

        return array_values($out);
    }

    /**
     * @return list<array{host: string, template: string}>
     */
    private function endpointsFor(Product $product): array
    {
        if ($this->identity->ansellStyleCodes($product) !== []) {
            return self::ENDPOINTS;
        }
        $hosts = $this->identity->catalogSearchHosts($product);
        $byHost = [];
        foreach ($this->allKnownEndpoints() as $row) {
            $byHost[$row['host']] = $row;
        }
        $out = [];
        foreach ($hosts as $host) {
            if (isset($byHost[$host])) {
                $out[] = $byHost[$host];
            }
        }
        if ($out !== []) {
            return $out;
        }
        if ($this->identity->inferredCatalogHosts($product) !== []) {
            return [];
        }
        if ($this->identity->shopIdentityPhrases($product) !== []
            || $this->identity->hasDistinctiveCatalogSku($product)
            || $this->identity->looksLikeWarehouseArticleSku($product)) {
            $byHost = [];
            foreach (array_merge(self::ENDPOINTS, $this->codeIndexEndpoints()) as $row) {
                $byHost[$row['host']] = $row;
            }

            return array_values($byHost);
        }

        return [];
    }

    /**
     * @return list<array{host: string, template: string}>
     */
    private function allKnownEndpoints(): array
    {
        return array_merge(
            [self::MISTERWORKER_ENDPOINT],
            self::IDOSELL_ENDPOINTS,
            [self::MAPA_ENDPOINT, self::MAREL_ENDPOINT],
            self::ENDPOINTS,
            self::SHOPER_ENDPOINTS,
            self::WOO_ENDPOINTS,
            self::SHOPIFY_ENDPOINTS,
            self::BIGCOMMERCE_ENDPOINTS,
        );
    }

    /**
     * @return list<array{host: string, template: string}>
     */
    private function codeIndexEndpoints(): array
    {
        $want = $this->identity->codeIndexRetailerHosts();
        $out = [];
        foreach ($this->allKnownEndpoints() as $row) {
            if (in_array($row['host'], $want, true)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Solr MAPA przy jednym trafieniu robi 303 na /strona-produktu/….
     * Woo/Shopify przy jednym wyniku często 302 na kartę.
     *
     * @return array{url: string, title: string, snippet: string}|null
     */
    private function productPageFromRedirect(string $url, string $host): ?array
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }
        $pageHost = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
        $pageHost = preg_replace('/^www\./', '', $pageHost) ?? $pageHost;
        if ($pageHost !== $host && ! str_ends_with($pageHost, '.'.$host)) {
            return null;
        }
        $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        if (! $this->looksLikeProductPath($path)) {
            return null;
        }
        $url = $this->cleanProductUrl($url);
        $slug = trim((string) basename((string) (parse_url($url, PHP_URL_PATH) ?? '')), '/');
        $title = $this->titleFromProductPath($path);
        if ($title === '') {
            $title = trim(str_replace('-', ' ', $slug));
        }

        return [
            'url' => $url,
            'title' => $title !== '' ? $title : $url,
            'snippet' => '',
        ];
    }

    private function looksLikeProductPath(string $path): bool
    {
        return str_contains($path, '/strona-produktu/')
            || preg_match('#/(produkt|products?)/#u', $path) === 1
            || preg_match('#^/pl/p/#u', $path) === 1;
    }

    private function titleFromProductPath(string $path): string
    {
        $parts = array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn (string $p): bool => $p !== ''
        ));
        if ($parts === []) {
            return '';
        }
        $slug = (string) $parts[array_key_last($parts)];
        $slug = preg_replace('/\.html?$/i', '', $slug) ?? $slug;
        if (preg_match('/^\d+$/', $slug) === 1 && count($parts) >= 2) {
            $slug = (string) $parts[count($parts) - 2];
        }
        if (preg_match('/^p\d+[,_-](.+)$/u', $slug, $m) === 1) {
            return trim((string) preg_replace('/[-_]+/u', ' ', $m[1]));
        }

        $title = trim((string) preg_replace('/[-_]+/u', ' ', $slug));
        if ($title === '' || mb_strlen($title) < 3) {
            return '';
        }

        return $title;
    }

    private function ariaLabelFromAttrs(string $attrs): string
    {
        if (preg_match('/\baria-label=["\']([^"\']+)["\']/i', $attrs, $m) !== 1) {
            return '';
        }

        return trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function cleanProductUrl(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'], $parts['path'])) {
            return $url;
        }
        $query = [];
        parse_str((string) ($parts['query'] ?? ''), $query);
        unset($query['searchuuid'], $query['search_query'], $query['_pos'], $query['_sid'], $query['_ss']);
        $clean = $parts['scheme'].'://'.$parts['host'].$parts['path'];
        if ($query !== []) {
            $clean .= '?'.http_build_query($query);
        }

        return $clean;
    }

    /**
     * Clerk.io + przekierowanie PrestaShop. Klucz: cache / config / HTML /search.
     *
     * @return list<array{url: string, title: string, snippet: string}>
     */
    private function misterworkerHits(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $key = $this->resolvedClerkKey();
        if ($key === '') {
            return [];
        }
        $out = [];
        foreach (array_slice($this->clerkProductIds($key, $query), 0, self::MISTERWORKER_RESOLVE_LIMIT) as $id) {
            $hit = $this->resolveMisterworkerProduct($id);
            if ($hit !== null) {
                $out[] = $hit;
            }
        }

        return $out;
    }

    private function resolvedClerkKey(): string
    {
        $cached = Cache::get(self::CLERK_KEY_CACHE);
        if ($this->isClerkKey($cached)) {
            return (string) $cached;
        }
        $cfg = config('enrichment.misterworker_clerk_key');
        if ($this->isClerkKey($cfg)) {
            return (string) $cfg;
        }
        $page = $this->fetch(self::MISTERWORKER_ORIGIN.'/en/search?q=1');
        $fromHtml = $this->clerkKeyFromHtml($page['html']);
        if ($fromHtml !== '') {
            Cache::put(self::CLERK_KEY_CACHE, $fromHtml, now()->addDay());
        }

        return $fromHtml;
    }

    private function isClerkKey(mixed $key): bool
    {
        return is_string($key) && preg_match('/^[A-Za-z0-9]{16,64}$/', $key) === 1;
    }

    private function clerkKeyFromHtml(string $html): string
    {
        if (preg_match("/Clerk\(\s*'config'\s*,\s*\{[^}]{0,400}key:\s*'([A-Za-z0-9]{16,64})'/", $html, $m) === 1
            && $this->isClerkKey($m[1])) {
            return $m[1];
        }

        return '';
    }

    /**
     * @return list<int>
     */
    private function clerkProductIds(string $key, string $query): array
    {
        try {
            $response = Http::timeout(8)
                ->connectTimeout(4)
                ->acceptJson()
                ->get(self::MISTERWORKER_CLERK_SEARCH, [
                    'key' => $key,
                    'query' => $query,
                    'limit' => self::MISTERWORKER_RESOLVE_LIMIT,
                ]);
            if (! $response->successful()) {
                return [];
            }
            $ids = $response->json('result');
            if (! is_array($ids)) {
                return [];
            }
            $out = [];
            foreach ($ids as $id) {
                $n = (int) $id;
                if ($n > 0) {
                    $out[] = $n;
                }
            }

            return $out;
        } catch (Throwable $e) {
            Log::info('Misterworker Clerk search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @return array{url: string, title: string, snippet: string}|null
     */
    private function resolveMisterworkerProduct(int $id): ?array
    {
        $current = self::MISTERWORKER_ORIGIN.'/en/index.php?controller=product&id_product='.$id;
        try {
            for ($hop = 0; $hop < self::MISTERWORKER_REDIRECT_HOPS; $hop++) {
                $response = Http::timeout(8)
                    ->connectTimeout(4)
                    ->withOptions(['allow_redirects' => false])
                    ->withHeaders($this->browserHeaders())
                    ->get($current);
                $status = $response->status();
                if ($status >= 300 && $status < 400) {
                    $next = $this->absolutizeMisterworkerUrl($this->firstLocationHeader($response));
                    if ($next === '' || ! $this->isMisterworkerHttpUrl($next)) {
                        return null;
                    }
                    if ($this->isMisterworkerProductUrl($next)) {
                        return $this->hitFromMisterworkerUrl($next);
                    }
                    $current = $next;
                    continue;
                }
                if ($response->successful()) {
                    $final = (string) ($response->effectiveUri() ?? $current);
                    if ($this->isMisterworkerProductUrl($final)) {
                        return $this->hitFromMisterworkerUrl($final);
                    }
                    $canonical = $this->canonicalFromHtml((string) $response->body());
                    if ($this->isMisterworkerProductUrl($canonical)) {
                        return $this->hitFromMisterworkerUrl($canonical);
                    }
                }

                return $this->prettyUrlViaReader($id);
            }

            return $this->prettyUrlViaReader($id);
        } catch (Throwable $e) {
            Log::info('Misterworker product resolve failed', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->prettyUrlViaReader($id);
        }
    }

    /**
     * Cloudflare blokuje Guzzle na misterworker — Jina zwraca markdown z pretty URL.
     *
     * @return array{url: string, title: string, snippet: string}|null
     */
    private function prettyUrlViaReader(int $id): ?array
    {
        $source = self::MISTERWORKER_ORIGIN.'/en/index.php?controller=product&id_product='.$id;
        try {
            $response = Http::timeout(35)
                ->connectTimeout(8)
                ->withHeaders([
                    'Accept' => 'text/plain,text/markdown,*/*',
                    'User-Agent' => 'Mozilla/5.0 (compatible; SUPON-Enrichment/1.4)',
                ])
                ->get('https://r.jina.ai/'.$source);
            if (! $response->successful()) {
                return null;
            }
            $url = $this->prettyUrlFromMarkdown((string) $response->body(), $id);
            if ($url === '') {
                return null;
            }

            return $this->hitFromMisterworkerUrl($url);
        } catch (Throwable $e) {
            Log::info('Misterworker Jina resolve failed', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function prettyUrlFromMarkdown(string $markdown, int $id): string
    {
        if (preg_match_all('#https://(?:www\.)?misterworker\.com/[^\s<>"\')]+#i', $markdown, $hits) === 0) {
            return '';
        }
        $suffix = '/'.$id.'.html';
        foreach ($hits[0] as $raw) {
            $raw = html_entity_decode(rtrim((string) $raw, '.,);'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $path = (string) (parse_url($raw, PHP_URL_PATH) ?? '');
            if (! str_ends_with($path, $suffix)) {
                continue;
            }
            $host = (string) (parse_url($raw, PHP_URL_HOST) ?? '');
            $scheme = (string) (parse_url($raw, PHP_URL_SCHEME) ?? 'https');
            $clean = $scheme.'://'.$host.$path;
            if ($this->isMisterworkerProductUrl($clean)) {
                return $clean;
            }
        }

        return '';
    }

    /**
     * @return array{url: string, title: string, snippet: string}
     */
    private function hitFromMisterworkerUrl(string $url): array
    {
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        $title = $this->titleFromPrettySlug($path);

        return [
            'url' => $url,
            'title' => $title !== '' ? $title : $url,
            'snippet' => '',
        ];
    }

    private function firstLocationHeader(Response $response): string
    {
        $location = $response->header('Location');
        if (is_array($location)) {
            $location = $location[0] ?? '';
        }

        return is_string($location) ? trim($location) : '';
    }

    private function absolutizeMisterworkerUrl(string $location): string
    {
        $location = trim($location);
        if ($location === '') {
            return '';
        }
        if (str_starts_with($location, '//')) {
            return 'https:'.$location;
        }
        if (str_starts_with($location, '/')) {
            return self::MISTERWORKER_ORIGIN.$location;
        }
        if (preg_match('#^https?://#i', $location) !== 1) {
            return self::MISTERWORKER_ORIGIN.'/'.ltrim($location, '/');
        }

        return $location;
    }

    private function isMisterworkerHttpUrl(string $url): bool
    {
        $scheme = mb_strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }
        $host = preg_replace('/^www\./', '', mb_strtolower((string) parse_url($url, PHP_URL_HOST))) ?? '';

        return $host === 'misterworker.com';
    }

    private function isMisterworkerProductUrl(string $url): bool
    {
        if (! $this->isMisterworkerHttpUrl($url)) {
            return false;
        }
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

        return $path !== '' && $path !== '/' && ! str_contains($path, 'index.php');
    }

    private function canonicalFromHtml(string $html): string
    {
        if (preg_match('/<link\b[^>]*\brel=["\']canonical["\'][^>]*\bhref=["\']([^"\']+)["\']/i', $html, $m) !== 1
            && preg_match('/<link\b[^>]*\bhref=["\']([^"\']+)["\'][^>]*\brel=["\']canonical["\']/i', $html, $m) !== 1) {
            return '';
        }

        return $this->absolutizeMisterworkerUrl(
            html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        );
    }

    private function titleFromPrettySlug(string $path): string
    {
        $parts = array_values(array_filter(explode('/', trim($path, '/')), static fn (string $p): bool => $p !== ''));
        if ($parts === []) {
            return '';
        }
        $last = preg_replace('/\.html?$/i', '', (string) $parts[array_key_last($parts)]) ?? '';
        if (preg_match('/^\d+$/', $last) === 1 && count($parts) >= 2) {
            $last = (string) $parts[count($parts) - 2];
        }

        return trim((string) preg_replace('/[-_]+/u', ' ', $last));
    }

    /**
     * @return array<string, string>
     */
    private function browserHeaders(): array
    {
        return [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
            'Accept' => 'text/html',
        ];
    }

    /**
     * @return array{html: string, url: string}
     */
    private function fetch(string $url): array
    {
        try {
            $response = Http::timeout(8)
                ->connectTimeout(4)
                ->withHeaders($this->browserHeaders())
                ->get($url);
            if (! $response->successful()) {
                return ['html' => '', 'url' => ''];
            }
            $final = (string) ($response->effectiveUri() ?? $url);

            return [
                'html' => (string) $response->body(),
                'url' => $final !== '' ? $final : $url,
            ];
        } catch (Throwable $e) {
            Log::info('Retailer on-site search failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return ['html' => '', 'url' => ''];
        }
    }
}
