<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Exceptions\TavilyQuotaExceededException;
use App\Models\ManufacturerSite;
use App\Models\Product;
use App\Services\Ai\AiSettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Oficjalne domeny producenta (certyfikaty/PDF) vs sklepy (opisy).
 * Nowe marki: Tavily „official website” na podstawie nazwy z cennika.
 */
final class ManufacturerDomainResolver
{
    private const DOMAIN_CACHE_PREFIX = 'enrich_mfr_domains_v2:';

    public function __construct(
        private readonly AiSettingsService $settings,
        private readonly ProductSearchIdentity $identity,
        private readonly DuckDuckGoHtmlSearch $duckDuckGo = new DuckDuckGoHtmlSearch,
    ) {}

    /**
     * @return list<string> hosty bez schematu, lowercase
     */
    public function domainsFor(Product $product): array
    {
        $brand = $this->brandKey((string) $product->manufacturer);
        if ($brand === '') {
            return [];
        }

        $mapped = $this->domainsFromConfig($brand);
        if ($this->identity->looksLikeUrgentGloveSeries($product)) {
            $mapped = array_values(array_unique(array_merge(
                $mapped,
                $this->domainsFromConfig('urgent')
            )));
        }
        $mapped = array_values(array_unique(array_merge(
            $mapped,
            ManufacturerSite::hostsForBrand($brand)
        )));
        if ($mapped !== []) {
            return $mapped;
        }

        $cached = Cache::get(self::DOMAIN_CACHE_PREFIX.$brand);
        if (is_array($cached)) {
            return array_values(array_filter($cached, 'is_string'));
        }

        return [];
    }

    /**
     * Wykryj oficjalną domenę marki (gdy brak w config) — cache 30 dni.
     *
     * @return list<string>
     */
    public function discoverOfficialDomains(Product $product): array
    {
        $brand = $this->brandKey((string) $product->manufacturer);
        if ($brand === '') {
            return [];
        }

        $known = $this->domainsFor($product);
        if ($known !== []) {
            return $known;
        }

        $cacheKey = self::DOMAIN_CACHE_PREFIX.$brand;
        try {
            $mfr = trim((string) $product->manufacturer);
            $sku = trim((string) $product->sku);
            $name = trim((string) $product->name);
            $bits = array_values(array_filter([
                $mfr,
                $sku,
                ($name !== '' && mb_strtolower($name) !== mb_strtolower($sku)) ? $name : null,
            ]));
            $query = implode(' ', $bits).' official website OR strona oficjalna producent';

            if ($this->settings->usesFreeWebSearch()) {
                $rows = $this->duckDuckGo->search($query, 6);
            } else {
                TavilyQuotaGuard::assertAllowed();
                $cfg = $this->settings->resolve();
                $key = (string) ($cfg['tavily_api_key'] ?? '');
                if ($key === '') {
                    return [];
                }
                $response = Http::timeout(15)
                    ->connectTimeout(5)
                    ->withToken($key)
                    ->post('https://api.tavily.com/search', [
                        'query' => $query,
                        'search_depth' => 'basic',
                        'include_answer' => false,
                        'max_results' => 6,
                    ]);
                if (! $response->successful()) {
                    if ($response->status() === 432
                        || str_contains(mb_strtolower($response->body()), 'usage limit')) {
                        TavilyQuotaGuard::block($response->body());
                        throw new TavilyQuotaExceededException(
                            'Tavily HTTP '.$response->status().': limit planu Tavily wyczerpany.'
                        );
                    }

                    return [];
                }
                $rows = $response->json('results') ?? [];
                $rows = is_array($rows) ? $rows : [];
            }
            $skuRows = $this->rowsMentioningSku($product, $rows);
            $found = $this->discoverFromResults($product, $skuRows !== [] ? $skuRows : $rows);
            if ($found !== []) {
                Cache::put($cacheKey, $found, now()->addDays(30));
                ManufacturerSite::remember($brand, $mfr, $found, 'discovered');
            }
            Log::info('Discovered manufacturer domains', ['brand' => $brand, 'domains' => $found]);

            return $found;
        } catch (TavilyQuotaExceededException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::info('Manufacturer domain discovery failed', ['brand' => $brand, 'error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param  list<mixed>  $rows
     * @return list<array{url?: string, title?: string}>
     */
    private function rowsMentioningSku(Product $product, array $rows): array
    {
        $sku = mb_strtolower(trim((string) $product->sku));
        $compact = preg_replace('/[^a-z0-9]+/iu', '', $sku) ?? $sku;
        if ($compact === '' || mb_strlen($compact) < 3) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $hay = mb_strtolower((string) ($row['url'] ?? '').' '.(string) ($row['title'] ?? ''));
            $hayCompact = preg_replace('/[^a-z0-9]+/iu', '', $hay) ?? $hay;
            if (str_contains($hay, $sku) || str_contains($hayCompact, $compact)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function configDomainsFor(Product $product): array
    {
        $brand = $this->brandKey((string) $product->manufacturer);
        if ($brand === '') {
            return [];
        }
        $mapped = $this->domainsFromConfig($brand);
        if ($this->identity->looksLikeUrgentGloveSeries($product)) {
            $mapped = array_values(array_unique(array_merge(
                $mapped,
                $this->domainsFromConfig('urgent')
            )));
        }

        return $mapped;
    }

    /**
     * @return list<string>
     */
    private function domainsFromConfig(string $brand): array
    {
        $map = config('enrichment.manufacturer_domains', []);
        if (! is_array($map)) {
            return [];
        }

        $out = [];
        foreach ($map as $key => $domains) {
            if (! is_string($key) || ! is_array($domains)) {
                continue;
            }
            $nk = $this->normalizeKey($key);
            if ($nk === '') {
                continue;
            }
            if ($nk !== $brand
                && (mb_strlen($nk) < 4 || mb_strlen($brand) < 4
                    || (! str_contains($brand, $nk) && ! str_contains($nk, $brand)))) {
                continue;
            }
            foreach ($domains as $domain) {
                if (! is_string($domain)) {
                    continue;
                }
                $clean = $this->normalizeHost($domain);
                if ($clean !== '') {
                    $out[] = $clean;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Uzupełnij listę domen hostami z wyników wyszukiwania (host zawiera markę).
     *
     * @param  list<array{url?: string}|string>  $resultsOrUrls
     * @return list<string>
     */
    public function discoverFromResults(Product $product, array $resultsOrUrls): array
    {
        $known = $this->domainsFor($product);
        $brand = $this->brandKey((string) $product->manufacturer);
        if ($brand === '') {
            return $known;
        }

        $retailers = $this->retailerHosts();
        $found = $known;
        foreach ($resultsOrUrls as $row) {
            $url = is_string($row) ? $row : (string) ($row['url'] ?? '');
            $host = $this->hostFromUrl($url);
            if ($host === null) {
                continue;
            }
            if ($this->hostIsRetailer($host, $retailers)) {
                continue;
            }
            if (! $this->hostLooksLikeBrand($host, $brand)) {
                continue;
            }
            $found[] = $host;
            // wariant bez www
            $bare = preg_replace('/^www\./', '', $host) ?? $host;
            if ($bare !== '') {
                $found[] = $bare;
            }
        }

        return array_values(array_unique($found));
    }

    public function isManufacturerUrl(string $url, Product $product, ?array $domains = null): bool
    {
        $host = $this->hostFromUrl($url);
        if ($host === null) {
            return false;
        }
        $domains ??= $this->domainsFor($product);
        if ($domains === []) {
            $brand = $this->brandKey((string) $product->manufacturer);

            return $brand !== '' && $this->hostLooksLikeBrand($host, $brand)
                && ! $this->hostIsRetailer($host, $this->retailerHosts());
        }

        return $this->hostMatchesAny($host, $domains);
    }

    /**
     * @param  list<string>  $domains
     */
    public function hostMatchesAny(string $host, array $domains): bool
    {
        $host = mb_strtolower($host);
        foreach ($domains as $domain) {
            $d = mb_strtolower(trim($domain));
            if ($d === '') {
                continue;
            }
            if ($host === $d || str_ends_with($host, '.'.$d)) {
                return true;
            }
        }

        return false;
    }

    public function hostFromUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        return mb_strtolower($host);
    }

    public function brandKey(string $manufacturer): string
    {
        $m = trim($manufacturer);
        if ($m === '') {
            return '';
        }
        $first = trim(explode('/', $m)[0] ?? $m);
        $first = trim(explode('(', $first)[0] ?? $first);

        return $this->normalizeKey($first);
    }

    private function normalizeKey(string $value): string
    {
        $v = mb_strtolower(trim($value));
        $v = preg_replace('/[^a-z0-9]+/u', '-', $v) ?? $v;

        return trim($v, '-');
    }

    private function normalizeHost(string $domain): string
    {
        $clean = mb_strtolower(trim(preg_replace('#^https?://#i', '', $domain) ?? $domain));
        $clean = rtrim(explode('/', $clean)[0] ?? $clean, '/');

        return $clean;
    }

    private function hostLooksLikeBrand(string $host, string $brandKey): bool
    {
        $bare = preg_replace('/^www\./', '', $host) ?? $host;
        $compactBrand = str_replace('-', '', $brandKey);
        if ($compactBrand === '' || mb_strlen($compactBrand) < 3) {
            return false;
        }
        $first = explode('.', $bare)[0] ?? '';
        $firstCompact = preg_replace('/[^a-z0-9]+/u', '', $first) ?? $first;
        if ($firstCompact === $compactBrand) {
            return true;
        }
        if (str_starts_with($firstCompact, $compactBrand) && mb_strlen($compactBrand) >= 4) {
            $rest = mb_substr($firstCompact, mb_strlen($compactBrand));

            return $rest !== '' && (mb_strlen($rest) >= 4
                || in_array($rest, ['safety', 'glove', 'gloves', 'group', 'plus', 'pro'], true));
        }

        return str_starts_with($bare, $brandKey.'.')
            || str_starts_with($bare, $brandKey.'-');
    }

    /**
     * @return list<string>
     */
    private function retailerHosts(): array
    {
        $raw = config('enrichment.retailer_domains', config('enrichment.preferred_domains', []));
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $domain) {
            if (is_string($domain)) {
                $h = $this->normalizeHost($domain);
                if ($h !== '') {
                    $out[] = $h;
                }
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $retailers
     */
    private function hostIsRetailer(string $host, array $retailers): bool
    {
        return $this->hostMatchesAny($host, $retailers);
    }
}
