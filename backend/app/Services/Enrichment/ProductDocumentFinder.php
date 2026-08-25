<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Exceptions\TavilyQuotaExceededException;
use App\Models\Product;
use App\Services\Ai\AiSettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PDF certyfikatów / deklaracji — przede wszystkim ze stron producenta.
 */
final class ProductDocumentFinder
{
    public function __construct(
        private readonly AiSettingsService $settings,
        private readonly ManufacturerDomainResolver $manufacturers,
        private readonly ProductSearchIdentity $identity = new ProductSearchIdentity,
        private readonly DuckDuckGoHtmlSearch $duckDuckGo = new DuckDuckGoHtmlSearch,
    ) {}

    /**
     * @return list<string> bezpośrednie PDF + strony z deklaracjami (HTML)
     */
    public function findDocumentUrls(Product $product): array
    {
        $domains = $this->manufacturers->domainsFor($product);
        if ($domains === []) {
            $domains = $this->manufacturers->discoverOfficialDomains($product);
        }

        $found = $this->guessKnownCdnDocuments($product);

        $queries = $this->buildQueries($product, $domains);
        $profile = $this->settings->tavilySearchProfile();

        foreach (array_slice($queries, 0, $profile->docsMaxQueries) as $query) {
            $cacheKey = 'enrich_docs_v8:'.hash('sha256', $profile->mode.'|'.$query.'|'.implode(',', $domains));
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                foreach ($cached as $url) {
                    if (is_string($url) && str_starts_with($url, 'http')) {
                        $found[] = $url;
                    }
                }
                if ($this->hasPdf($found) || $this->hasDeclarationIndex($found)) {
                    break;
                }

                continue;
            }

            try {
                $results = [];
                if ($domains !== []) {
                    $results = $this->searchTavily($query, $domains);
                }
                if ($results === [] && $profile->docsOpenWebFallback) {
                    $results = $this->searchTavily($query, []);
                }
            } catch (TavilyQuotaExceededException $e) {
                throw $e;
            } catch (Throwable $e) {
                Log::info('Document search failed', ['query' => $query, 'error' => $e->getMessage()]);

                continue;
            }

            $domains = $this->manufacturers->discoverFromResults($product, $results !== [] ? $results : $found);

            $isIndexQuery = (bool) preg_match('#\b(deklaracje|declarations? of conformity|certificate download|downloads)\b#iu', $query);
            $matched = $this->filterResults($results, $product, $domains, $isIndexQuery);
            Cache::put($cacheKey, $matched, now()->addDays($profile->cacheDays));
            foreach ($matched as $url) {
                $found[] = $url;
            }
            if ($this->hasPdf($found) || ($isIndexQuery && $this->hasDeclarationIndex($found))) {
                break;
            }
        }

        return array_values(array_unique($found));
    }

    /**
     * Bezpośrednie URL PDF u producenta (gdy sklepy nie hostują certyfikatów).
     *
     * @return list<string>
     */
    private function guessKnownCdnDocuments(Product $product): array
    {
        $sku = trim((string) $product->sku);
        if ($sku === '' || preg_match('/^\d{4,8}$/', $sku) !== 1) {
            return [];
        }

        $brand = mb_strtolower($this->shortBrand((string) $product->manufacturer));
        if ($brand === '' || (! str_contains($brand, 'uvex') && ! str_contains((string) $product->manufacturer, 'uvex'))) {
            return [];
        }

        $urls = [];
        foreach (['EN', 'DE', 'PL'] as $lang) {
            $urls[] = 'https://d3nan4w00fsv2d.cloudfront.net/DATASHEET/'.$sku.'_PDB_'.$lang.'.pdf';
        }

        return $urls;
    }

    /**
     * @param  list<string>  $urls
     */
    private function hasPdf(array $urls): bool
    {
        foreach ($urls as $url) {
            if (ProductDocumentDownloader::looksLikePdfUrl($url)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $urls
     */
    private function hasDeclarationIndex(array $urls): bool
    {
        foreach ($urls as $url) {
            if ($this->looksLikeDeclarationIndex($url, $url)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $domains
     * @return list<string>
     */
    private function buildQueries(Product $product, array $domains): array
    {
        $phrase = $this->searchPhrase($product);
        $mfr = $this->shortBrand((string) $product->manufacturer);
        $nameCore = $this->nameCore(trim((string) $product->name) !== '' ? (string) $product->name : $phrase);
        $sku = trim((string) $product->sku);
        $site = $domains !== [] ? 'site:'.preg_replace('/^www\./', '', $domains[0]) : '';

        $queries = [
            // konkretny produkt → PDF
            trim('"'.$sku.'" '.$mfr.' (declaration OR deklaracja OR certificate OR DoC OR EU) filetype:pdf '.$site),
            trim(($nameCore !== '' ? '"'.$nameCore.'"' : '"'.$phrase.'"').' '.$mfr.' declaration of conformity OR deklaracja zgodności filetype:pdf '.$site),
            trim($sku.' '.$nameCore.' '.$mfr.' datasheet OR "declaration of conformity" OR certificate '.$site),
            // indeks deklaracji producenta (HTML) — potem wyciągamy PDF po SKU
            trim($mfr.' "declaration of conformity" OR deklaracje zgodności OR "declarations of conformity" downloads '.$site),
            trim($phrase.' '.$mfr.' PDS OR TDS filetype:pdf '.$site),
        ];
        // uvex / numeryczne art.: szukaj też po samym numerze na CDN / safety
        if ($sku !== '' && preg_match('/^\d{4,}$/', $sku)) {
            array_unshift(
                $queries,
                trim($sku.' '.$mfr.' (pdf OR declaration OR deklaracja OR certificate) '.$site),
                trim($sku.' filetype:pdf '.$mfr),
            );
        }

        return array_values(array_unique(array_filter($queries)));
    }

    private function nameCore(string $phrase): string
    {
        $parts = preg_split('/\s+/u', trim($phrase)) ?: [];
        $normSet = ['s1', 's2', 's3', 'src', 'hro', 'o1', 'o2', 'fo', 'ci', 'hi', 'p', 'wr', 'en', 'iso'];
        $name = [];
        foreach ($parts as $part) {
            $low = mb_strtolower($part);
            if (in_array($low, $normSet, true) || preg_match('/^\d+:\d+/', $part)) {
                break;
            }
            if ($part !== '' && ! preg_match('/^\(?\d/', $part)) {
                $name[] = $part;
            }
            if (count($name) >= 4) {
                break;
            }
        }

        return trim(implode(' ', $name));
    }

    private function searchPhrase(Product $product): string
    {
        $name = trim((string) $product->name);
        $sku = trim((string) $product->sku);

        if ($name !== '' && preg_match('/\d{1,2}-\d{3}/', $name)
            && preg_match('/\b(S1|S2|S3|SRC|HRO|P|FO|CI|HI)\b/i', $name)) {
            return $name;
        }
        if (preg_match('/^(\d{1,2}-\d{3}(?:\s+[A-Za-z])?)/u', $sku, $m)) {
            if ($name !== '' && preg_match_all('/\b(S1|S2|S3|SRC|HRO|P|FO|CI|HI)\b/i', $name, $norms)) {
                return trim($m[1].' '.implode(' ', array_unique($norms[0])));
            }

            return $m[1];
        }

        // art. numeryczne (uvex 60028) + nazwa handlowa
        if ($sku !== '' && $name !== '' && preg_match('/^\d{4,}$/', $sku)) {
            return trim($sku.' '.$this->nameCore($name));
        }

        return $sku !== '' ? $sku : $name;
    }

    private function shortBrand(string $manufacturer): string
    {
        $m = trim($manufacturer);
        if ($m === '') {
            return '';
        }
        $first = trim(explode('/', $m)[0] ?? $m);
        $first = trim(explode('(', $first)[0] ?? $first);

        return mb_substr($first, 0, 40);
    }

    /**
     * @param  list<string>  $includeDomains
     * @return list<array{url: string, title: string, snippet: string}>
     */
    private function searchTavily(string $query, array $includeDomains = []): array
    {
        if ($this->settings->usesFreeWebSearch()) {
            return $this->duckDuckGo->search($query, 8, $includeDomains);
        }

        TavilyQuotaGuard::assertAllowed();

        $cfg = $this->settings->resolve();
        $key = (string) ($cfg['tavily_api_key'] ?? '');
        if ($key === '') {
            return [];
        }

        $profile = $this->settings->tavilySearchProfile();
        $body = [
            'query' => $query,
            'search_depth' => 'basic',
            'include_answer' => false,
            'max_results' => min(8, max(3, $profile->maxResults + 1)),
        ];
        if ($includeDomains !== []) {
            $body['include_domains'] = array_values(array_unique($includeDomains));
        }

        $response = Http::timeout(20)
            ->connectTimeout(5)
            ->withToken($key)
            ->post('https://api.tavily.com/search', $body);

        TavilyQuotaGuard::ensureSuccessful($response, 'Tavily docs');

        $results = [];
        foreach ($response->json('results') ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $results[] = [
                'url' => (string) ($row['url'] ?? ''),
                'title' => (string) ($row['title'] ?? ''),
                'snippet' => (string) ($row['content'] ?? $row['snippet'] ?? ''),
            ];
        }

        return $results;
    }

    /**
     * @param  list<array{url: string, title: string, snippet: string}>  $results
     * @param  list<string>  $manufacturerDomains
     * @return list<string>
     */
    private function filterResults(
        array $results,
        Product $product,
        array $manufacturerDomains,
        bool $allowBrandIndex = false,
    ): array {
        $out = [];
        foreach ($results as $row) {
            $url = (string) ($row['url'] ?? '');
            if ($url === '' || ! str_starts_with($url, 'http')) {
                continue;
            }
            $hay = mb_strtolower(urldecode($url).' '.($row['title'] ?? '').' '.($row['snippet'] ?? ''));
            $isPdf = ProductDocumentDownloader::looksLikePdfUrl($url);
            $isDocPage = (bool) preg_match('#(deklar|certyfik|conform|datasheet|pds|tds|zgodo|certificate|download)#iu', $hay);
            $isMfr = $this->manufacturers->isManufacturerUrl($url, $product, $manufacturerDomains);

            // PDF z SKU w nazwie (CDN/dystrybutor) — gdy strona producenta (Ansell/Imperva) nie oddaje plików
            if ($isPdf && $this->matchesProduct($hay, $product)) {
                $out[] = $url;

                continue;
            }

            if (! $isMfr) {
                continue;
            }

            // karty produktu /products/… tylko gdy widać SKU/nazwę
            if ($this->matchesProduct($hay, $product) && ($isDocPage || $this->looksLikeProductDocPage($url))) {
                $out[] = $url;

                continue;
            }
            // indeks deklaracji — NIE zwykłe karty produktów
            if ($allowBrandIndex && $this->looksLikeDeclarationIndex($url, $hay)) {
                $out[] = $url;
            }
        }

        return array_values(array_unique($out));
    }

    private function looksLikeDeclarationIndex(string $url, string $hay): bool
    {
        if (preg_match('#/(products?|product-detail|pdp)/#iu', $url)) {
            return false;
        }

        return (bool) preg_match(
            '#(declaration|deklaracj|conformity|certificates?|certyfik|download|do[ck]ument|zgodo|datasheet)#iu',
            $url.' '.$hay
        );
    }

    private function looksLikeProductDocPage(string $url): bool
    {
        return (bool) preg_match('#/(products?|product)/.+#iu', $url);
    }

    private function matchesProduct(string $hay, Product $product): bool
    {
        if ($this->identity->hayMentionsProduct($hay, $product)) {
            return true;
        }

        $sku = mb_strtolower(trim((string) $product->sku));
        $name = mb_strtolower(trim((string) $product->name));

        if ($sku !== '' && preg_match('/(?<![0-9])'.preg_quote($sku, '/').'(?![0-9])/u', $hay)) {
            return true;
        }
        foreach ($this->identity->modelAliases($product) as $alias) {
            if ($alias !== '' && str_contains($hay, $alias)) {
                return true;
            }
        }

        if (preg_match('/\b(\d{1,2}-\d{3})\b/', $sku.' '.$name, $m)) {
            $core = $m[1];
            if (preg_match('/(?<![0-9])'.preg_quote($core, '/').'(?![0-9])/u', $hay)) {
                return true;
            }
            $compact = str_replace('-', '', $core);
            if (preg_match('/(?<![0-9])'.preg_quote($compact, '/').'(?![0-9])/u', $hay)) {
                return true;
            }

            return str_contains($hay, str_replace('-', '_', $core));
        }

        // nazwa handlowa: ATHLETIC ALLROUND
        if ($name !== '' && $this->matchesNameTokens($hay, $name)) {
            return true;
        }

        return $sku !== '' && ! preg_match('/^\d{4,}$/', $sku) && $this->matchesNameTokens($hay, $sku);
    }

    private function matchesNameTokens(string $hay, string $skuNorm): bool
    {
        $parts = preg_split('/[\s\-·\/_]+/u', mb_strtolower(trim($skuNorm))) ?: [];
        $normSet = ['s1', 's2', 's3', 'src', 'hro', 'o1', 'o2', 'fo', 'ci', 'hi', 'p', 'wr', 'an', 'en', 'iso'];
        $nameTokens = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || mb_strlen($part) < 3 || in_array($part, $normSet, true) || preg_match('/^\d+$/', $part)) {
                continue;
            }
            $nameTokens[] = $part;
        }
        if ($nameTokens === []) {
            return false;
        }
        $hayCompact = preg_replace('/[^a-z0-9]+/i', '', $hay) ?? $hay;
        foreach ($nameTokens as $token) {
            if (! str_contains($hay, $token) && ! str_contains($hayCompact, $token)) {
                return false;
            }
        }

        return true;
    }
}
