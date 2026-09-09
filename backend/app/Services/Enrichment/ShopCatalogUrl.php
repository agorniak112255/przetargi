<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

/**
 * Karta produktu vs lista/kategoria — wspólne wzorce sklepów.
 *
 * IAI/IdoSell: /p123,slug.html
 * Presta: id_product= albo /123-rewrite.html
 * Shoper/Woo: /produkt/… /product/…
 * Magento (Deporvillage): pretty slug; facety z „:”
 */
final class ShopCatalogUrl
{
    private const IAI_HOSTS = ['gvarant.pl', 'robocze-buty.pl'];

    /**
     * @var list<string>
     */
    private const CATEGORY_SEGMENTS = [
        'product-category',
        'kategoria-produktu',
        'productcategory',
        'collections',
        'kategoria',
        'kategorie',
        'category',
        'categories',
    ];

    public function isProductCard(string $url): bool
    {
        return $this->isClassicProduct($url) || $this->isPrettyProduct($url);
    }

    public function isClassicProduct(string $url): bool
    {
        $path = $this->path($url);
        $query = $this->query($url);
        if (str_contains($query, 'id_product=') || str_contains($query, 'controller=product')) {
            return true;
        }
        if ($this->isIaiProductCard($path)) {
            return true;
        }
        if (preg_match('#-p\d{2,}(\.html)?$#', $path) === 1) {
            return true;
        }
        if (preg_match('#/(product|produkt)/[^/]+#', $path) === 1) {
            return true;
        }
        if (preg_match('#/products/[^/]+$#', $path) === 1 && ! str_contains($path, 'productcategory')) {
            return true;
        }
        if (preg_match('#/productpage(/|$)#', $path) === 1) {
            return true;
        }
        if (preg_match('#/p/[^/]+/\d+#', $path) === 1) {
            return true;
        }
        if (preg_match('#/p/\d+(/|$)#', $path) === 1) {
            return true;
        }
        if (preg_match('#/\d{2,}-[a-z0-9-]+\.html$#', $path) === 1) {
            return true;
        }

        return str_contains($path, '/catalog/product/view');
    }

    public function isPrettyProduct(string $url): bool
    {
        if ($this->isIaiShop($url) || $this->isFacetListing($url) || $this->hasCategoryPrefix($url)
            || $this->isNumericPrefixCategory($url)) {
            return false;
        }
        $slug = $this->leafSlug($url);
        if ($slug === '' || str_contains($slug, ',') || ! str_contains($slug, '-') || mb_strlen($slug) < 8) {
            return false;
        }
        if (preg_match('/\p{L}/u', $slug) !== 1) {
            return false;
        }

        return ! $this->isInformationalSlug($slug) && ! $this->isListingSlug($slug);
    }

    /** Lista/filtr do pominięcia w indeksie (sitemap + purge). Kolejka crawla tego nie używa. */
    public function isIndexListing(string $url): bool
    {
        if ($this->isClassicProduct($url)) {
            return false;
        }
        if ($this->isFacetListing($url) || $this->hasCategoryPrefix($url) || $this->isNumericPrefixCategory($url)) {
            return true;
        }
        $segments = $this->segments($url);
        if (count($segments) !== 1) {
            return false;
        }
        $slug = $this->leafSlug($url);

        return $slug !== '' && $this->isListingSlug($slug);
    }

    public function isFacetListing(string $url): bool
    {
        $path = $this->path($url);
        if ($this->isIaiProductCard($path)) {
            return false;
        }
        if (str_contains($path, ':')) {
            return true;
        }
        $slug = (string) basename(rtrim($path, '/'));

        return str_contains($slug, ',');
    }

    /** Krótki slug bez cyfry: marka/kategoria, nie karta (namioty-kemping, on-running). */
    public function isListingSlug(string $slug): bool
    {
        $slug = preg_replace('/\.(html?|php)$/i', '', $slug) ?? $slug;
        if ($slug === '' || ! str_contains($slug, '-') || preg_match('/\d/', $slug) === 1) {
            return false;
        }

        return substr_count($slug, '-') <= 2;
    }

    public function isIaiProductCard(string $path): bool
    {
        $path = mb_strtolower(rtrim($path, '/'));

        return str_ends_with($path, '.html') && preg_match('#/p\d+,[^/]+$#', $path) === 1;
    }

    public function isIaiShop(string $hostOrUrl): bool
    {
        return in_array($this->bareHost($hostOrUrl), self::IAI_HOSTS, true);
    }

    public function isInformationalSlug(string $slug): bool
    {
        foreach ([
            'o-nas', 'o-firmie', 'about-us', 'about', 'kontakt', 'contact-us', 'contact',
            'regulamin', 'terms', 'polityka-prywatnosci', 'privacy-policy', 'privacy',
            'polityka-cookies', 'cookies', 'dostawa-i-platnosc', 'dostawa-i-platnosci',
            'shipping', 'returns', 'reklamacje', 'rodo', 'faq', 'pomoc',
            'logowanie', 'rejestracja', 'moje-konto', 'login',
        ] as $bad) {
            if ($slug === $bad) {
                return true;
            }
        }

        return false;
    }

    /** Presta/Shoper: /12-buty-robocze to kategoria; karta ma .html albo id_product. */
    private function isNumericPrefixCategory(string $url): bool
    {
        $path = rtrim($this->path($url), '/');
        if (str_ends_with($path, '.html') || str_ends_with($path, '.htm')) {
            return false;
        }
        $slug = $this->leafSlug($url);

        return preg_match('/^\d+-[a-z0-9-]+$/', $slug) === 1;
    }

    private function hasCategoryPrefix(string $url): bool
    {
        $segments = $this->segments($url);
        if ($segments === []) {
            return false;
        }
        if (($segments[0] ?? '') === 'c') {
            return true;
        }
        if (($segments[0] ?? '') === 'catalog' && ($segments[1] ?? '') === 'category') {
            return true;
        }
        foreach ($segments as $segment) {
            if (in_array($segment, self::CATEGORY_SEGMENTS, true)) {
                return true;
            }
        }

        return false;
    }

    private function leafSlug(string $url): string
    {
        $segments = $this->segments($url);
        if ($segments === []) {
            return '';
        }
        $slug = (string) end($segments);

        return preg_replace('/\.(html?|php)$/i', '', $slug) ?? $slug;
    }

    /**
     * @return list<string>
     */
    private function segments(string $url): array
    {
        $path = trim($this->path($url), '/');
        if ($path === '') {
            return [];
        }

        return array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
    }

    private function path(string $url): string
    {
        return mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
    }

    private function query(string $url): string
    {
        return mb_strtolower((string) (parse_url($url, PHP_URL_QUERY) ?? ''));
    }

    private function bareHost(string $hostOrUrl): string
    {
        $host = str_contains($hostOrUrl, '://')
            ? (string) (parse_url($hostOrUrl, PHP_URL_HOST) ?? '')
            : $hostOrUrl;
        $host = preg_replace('/^www\./', '', mb_strtolower($host)) ?? '';

        return $host;
    }
}
