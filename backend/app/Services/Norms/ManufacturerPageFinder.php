<?php

declare(strict_types=1);

namespace App\Services\Norms;

use App\Models\Product;
use App\Services\Enrichment\ManufacturerDomainResolver;
use App\Services\Enrichment\ProductPageFetcher;
use App\Support\BrandKey;
use App\Support\ManufacturerNormFacts;

/**
 * Adresy, pod którymi może stać karta wyrobu u jego producenta — kandydaci dla norms:from-manufacturer-pages. Tu nic
 * nie jest potwierdzone: każdą stronę sprawdza potem bramka tożsamości (ManufacturerNormIdentity::confirm), więc
 * szukanie może być szerokie, byle nie wychodziło poza witrynę producenta.
 *
 * Kolejność: adres producenta zapisany już przy karcie (źródło opisu z WWW, normy strony), potem wyszukiwarka witryny
 * producenta z config('norms.site_search') po kodach wyrobu. Bez płatnej wyszukiwarki.
 */
final class ManufacturerPageFinder
{
    public function __construct(
        private readonly ManufacturerDomainResolver $manufacturers,
        private readonly ProductPageFetcher $pages,
    ) {}

    /**
     * @param  list<array{code: string, kind: string, short: bool}>  $codes  ManufacturerNormIdentity::codesFor
     * @return list<array{url: string, via: string}>
     */
    public function candidates(Product $product, array $codes): array
    {
        $out = [];
        $add = function (string $url, string $via) use (&$out, $product): void {
            $url = trim($url);
            if ($url === '' || preg_match('#^https?://#i', $url) !== 1
                || ! $this->manufacturers->isManufacturerUrl($url, $product)
                || in_array($url, array_column($out, 'url'), true)) {
                return;
            }
            $out[] = ['url' => $url, 'via' => $via];
        };

        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $add((string) ($payload['primary_source_url'] ?? ''), 'karta');
        foreach ((array) ($payload['source_urls'] ?? []) as $url) {
            $add((string) $url, 'karta');
        }
        $stored = $product->manufacturer_norms;
        if (is_array($stored) && ($stored['source']['connector'] ?? null) === ManufacturerNormFacts::WEB_PAGE_CONNECTOR) {
            $add((string) ($stored['source']['url'] ?? ''), 'normy-strony');
        }

        $search = config('norms.site_search.'.BrandKey::of((string) $product->manufacturer));
        if (is_array($search)) {
            $limit = max(1, (int) config('norms.max_pages_per_card', 4));
            foreach ($codes as $code) {
                if ($code['kind'] === 'ean' || count($out) >= $limit) {
                    continue;
                }
                foreach ($this->siteSearch($search, $code['code']) as $url) {
                    $add($url, 'wyszukiwarka-producenta');
                }
            }
            $out = array_slice($out, 0, $limit);
        }

        return $out;
    }

    /**
     * @param  array{host: string, template: string, links: string}  $search
     * @return list<string>
     */
    private function siteSearch(array $search, string $code): array
    {
        $raw = $this->pages->fetchRaw(str_replace('{q}', rawurlencode($code), $search['template']));
        if ($raw === null) {
            return [];
        }

        return match ($search['links']) {
            'magento' => self::magentoProductLinks($raw['html'], $search['host']),
            default => [],
        };
    }

    /**
     * Odnośniki do kart wyrobów z listy wyników Magento — tylko kafelki produktów (product-item-link / -photo), bez menu,
     * filtrów i stopki.
     *
     * @return list<string>
     */
    public static function magentoProductLinks(string $html, string $host): array
    {
        $out = [];
        if (preg_match_all('#<a\b[^>]*>#i', $html, $m) < 1) {
            return [];
        }
        foreach ($m[0] as $tag) {
            if (preg_match('#class=["\'][^"\']*product-item-(?:link|photo)#i', $tag) !== 1
                || preg_match('#href=["\']([^"\']+)["\']#i', $tag, $href) !== 1) {
                continue;
            }
            $url = html_entity_decode($href[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $urlHost = preg_replace('/^www\./', '', mb_strtolower((string) parse_url($url, PHP_URL_HOST))) ?? '';
            if ($urlHost !== $host || in_array($url, $out, true)) {
                continue;
            }
            $out[] = $url;
        }

        return $out;
    }
}
