<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Szuka karty w wyszukiwarce sklepu (Magento / Presta) albo katalogu MAPA,
 * gdy Google/DDG dają 429.
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
        $queries = array_values(array_filter([$this->query($product), $this->queryBareModel($product)]));
        if ($queries === []) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($queries as $query) {
            foreach ($endpoints as $endpoint) {
                $page = $this->fetch(str_replace('{q}', rawurlencode($query), $endpoint['template']));
                $hits = $this->productLinks($page['html'], $endpoint['host']);
                $redirect = $this->productPageFromRedirect($page['url'], $endpoint['host']);
                if ($redirect !== null) {
                    array_unshift($hits, $redirect);
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
     * @return list<array{url: string, title: string, snippet: string}>
     */
    public function productLinks(string $html, string $host): array
    {
        $out = [];
        if (preg_match_all('/<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }
        foreach ($matches as $hit) {
            $url = html_entity_decode(trim($hit[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
                || preg_match('#/(search|catalogsearch|wyszukiwanie|category|kategoria|customer|checkout|cart|login)#u', $path) === 1) {
                continue;
            }
            $title = trim(html_entity_decode(strip_tags($hit[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (mb_strlen($title) < 8) {
                $fromSlug = $this->titleFromProductPath($path);
                if ($fromSlug !== '') {
                    $title = $fromSlug;
                }
            }
            if ($title === '' || mb_strlen($title) < 8) {
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
        $known = array_merge(self::IDOSELL_ENDPOINTS, [self::MAPA_ENDPOINT, self::MAREL_ENDPOINT], self::ENDPOINTS);
        $out = [];
        foreach ($known as $row) {
            if (in_array($row['host'], $hosts, true)) {
                $out[] = $row;
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
            return self::ENDPOINTS;
        }

        return [];
    }

    /**
     * Solr MAPA przy jednym trafieniu robi 303 na /strona-produktu/….
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
        if (! str_contains($path, '/strona-produktu/')) {
            return null;
        }
        $slug = trim((string) basename($path), '/');
        $title = trim(str_replace('-', ' ', $slug));

        return [
            'url' => $url,
            'title' => $title !== '' ? $title : $url,
            'snippet' => '',
        ];
    }

    private function titleFromProductPath(string $path): string
    {
        $slug = basename($path);
        $slug = preg_replace('/\.html?$/i', '', $slug) ?? $slug;
        if (preg_match('/^p\d+[,_-](.+)$/u', $slug, $m) !== 1) {
            return '';
        }

        return trim((string) preg_replace('/[-_]+/u', ' ', $m[1]));
    }

    /**
     * @return array{html: string, url: string}
     */
    private function fetch(string $url): array
    {
        try {
            $response = Http::timeout(8)
                ->connectTimeout(4)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
                    'Accept' => 'text/html',
                ])
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
