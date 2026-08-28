<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Szuka karty w wyszukiwarce sklepu (Magento / Presta), gdy Google/DDG dają 429.
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

    public function __construct(private readonly ProductSearchIdentity $identity) {}

    /**
     * @return list<array{url: string, title: string, snippet: string}>
     */
    public function find(Product $product): array
    {
        if ($this->identity->ansellStyleCodes($product) === []) {
            return [];
        }
        $queries = array_values(array_filter([$this->query($product), $this->queryBareModel($product)]));
        if ($queries === []) {
            return [];
        }

        $out = [];
        $seen = [];
        foreach ($queries as $query) {
            foreach (self::ENDPOINTS as $endpoint) {
                $html = $this->download(str_replace('{q}', rawurlencode($query), $endpoint['template']));
                if ($html === '') {
                    continue;
                }
                foreach ($this->productLinks($html, $endpoint['host']) as $hit) {
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

        $sku = trim((string) $product->sku);
        if ($sku !== '' && ! $this->identity->looksLikeInternalSku($product)) {
            return trim($this->identity->shortBrand((string) $product->manufacturer).' '.$sku);
        }

        return '';
    }

    public function queryBareModel(Product $product): string
    {
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
            if (str_starts_with($url, '/')) {
                $url = 'https://'.$host.$url;
            }
            if (! str_starts_with($url, 'http')) {
                continue;
            }
            $pageHost = mb_strtolower((string) parse_url($url, PHP_URL_HOST));
            $pageHost = preg_replace('/^www\./', '', $pageHost) ?? $pageHost;
            if ($pageHost !== $host && ! str_ends_with($pageHost, '.'.$host)) {
                continue;
            }
            $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
            if ($path === '' || $path === '/'
                || preg_match('#/(search|catalogsearch|category|kategoria|customer|checkout|cart|login)#u', $path) === 1) {
                continue;
            }
            $title = trim(html_entity_decode(strip_tags($hit[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
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

    private function download(string $url): string
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
                return '';
            }

            return (string) $response->body();
        } catch (Throwable $e) {
            Log::info('Retailer on-site search failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            return '';
        }
    }
}
