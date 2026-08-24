<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Exceptions\TavilyQuotaExceededException;
use App\Models\Product;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\OpenAiCompatibleClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class HybridWebSearchService
{
    private const SEARCH_CACHE_VERSION = 'v20';

    public function __construct(
        private readonly AiSettingsService $settings,
        private readonly OpenAiCompatibleClient $llm,
        private readonly DuckDuckGoHtmlSearch $duckDuckGo,
        private readonly ManufacturerDomainResolver $manufacturers,
        private readonly ProductSearchIdentity $identity,
    ) {}

    /**
     * @return array{
     *     results: list<array{url: string, title: string, snippet: string}>,
     *     images: list<string>,
     *     provider: string,
     *     raw_content: ?string
     * }
     */
    public function searchProduct(Product $product, string $phase = 'manufacturer'): array
    {
        $queries = $this->buildQueries($product, $phase);
        if ($this->settings->enrichmentUsesLargeModel()) {
            return $this->searchViaLargeModel($product, $phase, $queries);
        }
        $cfg = $this->settings->resolve();
        $profile = $this->settings->tavilySearchProfile();
        $skuQuery = $this->primarySkuQuery($product, $queries);
        $found = $this->searchSkuThenManufacturerSite(
            $product,
            $skuQuery,
            $profile,
            $profile->mode,
            $phase
        );
        $merged = $found['results'];
        $errors = $found['errors'];
        $provider = $found['provider'];

        if ($merged === [] && $cfg['web_search_enabled']) {
            try {
                $pack = $this->searchViaAiWeb($skuQuery, $product, $phase);
                $merged = $this->filterResultsByIdentity($pack['results'], $product);
                $provider = 'ai_web_search';
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        if ($merged === []) {
            $bare = $this->identity->stripBrandPrefix(
                (string) $product->sku,
                $this->identity->shortBrand((string) $product->manufacturer)
            );
            throw new RuntimeException(
                'Brak stron produktu (SKU '.$product->sku
                .($bare !== '' && $bare !== $product->sku ? ' / '.$bare : '')
                .'). '
                .($errors !== [] ? implode(' | ', array_slice($errors, 0, 2)) : '')
            );
        }

        return [
            'results' => array_slice($merged, 0, 8),
            'images' => [],
            'provider' => $provider,
            'raw_content' => null,
        ];
    }

    /**
     * @param  list<string>  $queries
     * @return array{
     *     results: list<array{url: string, title: string, snippet: string}>,
     *     images: list<string>,
     *     provider: string,
     *     raw_content: ?string
     * }
     */
    private function searchViaLargeModel(Product $product, string $phase, array $queries): array
    {
        $skuQuery = $this->primarySkuQuery($product, $queries);
        $profile = $this->settings->tavilySearchProfile();
        $found = $this->searchSkuThenManufacturerSite(
            $product,
            $skuQuery,
            $profile,
            'large_model',
            $phase
        );
        $merged = $found['results'];
        $errors = $found['errors'];
        $provider = $found['provider'];

        if ($merged === [] && ! $this->settings->usesDuckDuckGoSearch()) {
            $cacheKey = $this->searchCacheKey('large_model', $phase, $skuQuery, 'ai');
            $cached = Cache::get($cacheKey);
            if (is_array($cached) && isset($cached['results']) && is_array($cached['results'])) {
                $merged = $this->filterResultsByIdentity($cached['results'], $product);
                $provider = (string) ($cached['provider'] ?? 'ai_web_search_cache');
            } else {
                try {
                    $pack = $this->searchViaAiWeb($skuQuery, $product, $phase);
                    $merged = $this->filterResultsByIdentity($pack['results'], $product);
                    if ($merged !== []) {
                        $provider = 'ai_web_search';
                        Cache::put($cacheKey, [
                            'results' => $merged,
                            'images' => [],
                            'provider' => 'ai_web_search',
                        ], now()->addDays(7));
                    }
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }
        }

        if ($merged === []) {
            $bare = $this->identity->stripBrandPrefix(
                (string) $product->sku,
                $this->identity->shortBrand((string) $product->manufacturer)
            );
            throw new RuntimeException(
                'Brak stron produktu (duży model, SKU '.$product->sku
                .($bare !== '' && $bare !== $product->sku ? ' / '.$bare : '')
                .'). '
                .($errors !== [] ? implode(' | ', array_slice($errors, 0, 2)) : '')
            );
        }

        return [
            'results' => array_slice($merged, 0, 8),
            'images' => [],
            'provider' => $provider,
            'raw_content' => null,
        ];
    }

    public function forgetProductCache(Product $product): void
    {
        $skuQuery = $this->primarySkuQuery($product, $this->buildQueries($product, 'manufacturer'));
        foreach (TavilySearchProfile::MODES as $mode) {
            $profile = TavilySearchProfile::fromMode($mode);
            foreach (['manufacturer', 'industry'] as $phase) {
                foreach (array_slice($this->buildQueries($product, $phase), 0, $profile->maxQueries) as $query) {
                    Cache::forget($this->searchCacheKey($mode, $phase, $query));
                    foreach (['open', 'mfr'] as $step) {
                        Cache::forget($this->searchCacheKey($mode, $phase, $query, $step));
                    }
                }
                foreach (['open', 'mfr'] as $step) {
                    Cache::forget($this->searchCacheKey($mode, $phase, $skuQuery, $step));
                }
            }
        }
        foreach (['manufacturer', 'industry'] as $phase) {
            foreach (array_slice($this->buildQueries($product, $phase), 0, 1) as $query) {
                Cache::forget($this->searchCacheKey('large_model', $phase, $query));
                Cache::forget($this->searchCacheKey('large_model', $phase, $query, 'ai'));
                foreach (['open', 'mfr', 'ai'] as $step) {
                    Cache::forget($this->searchCacheKey('large_model', $phase, $skuQuery, $step));
                }
            }
        }
    }

    /**
     * @return array{
     *     results: list<array{url: string, title: string, snippet: string}>,
     *     images: list<string>,
     *     errors: list<string>
     * }
     */
    public function searchBothPhases(Product $product): array
    {
        $merged = [];
        $images = [];
        $seen = [];
        $seenImages = [];
        $errors = [];
        $profile = $this->settings->tavilySearchProfile();

        // full: obie fazy zawsze; eco/balanced: druga faza tylko gdy pierwsza nic nie dała
        foreach (['manufacturer', 'industry'] as $phase) {
            if ($phase === 'industry'
                && ($this->settings->enrichmentUsesLargeModel()
                    || (! $profile->bothPhasesAlways && $this->hasEnoughPageResults($merged, 1)))) {
                break;
            }

            try {
                $pack = $this->searchProduct($product, $phase);
            } catch (TavilyQuotaExceededException $e) {
                throw $e;
            } catch (Throwable $e) {
                $errors[] = $phase.': '.$e->getMessage();
                Log::warning('Product search phase failed', [
                    'product_id' => $product->id,
                    'phase' => $phase,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            foreach ($pack['results'] as $row) {
                $key = mb_strtolower($row['url']);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $merged[] = $row;
            }

            foreach ($this->normalizeImageList($pack['images'] ?? []) as $img) {
                $ik = mb_strtolower($img);
                if (isset($seenImages[$ik])) {
                    continue;
                }
                $seenImages[$ik] = true;
                $images[] = $img;
            }
        }

        usort($merged, function (array $a, array $b) use ($product): int {
            $scoreA = $this->resultQuality($a, $product)
                + ($this->manufacturers->isManufacturerUrl((string) ($a['url'] ?? ''), $product) ? 80 : 0);
            $scoreB = $this->resultQuality($b, $product)
                + ($this->manufacturers->isManufacturerUrl((string) ($b['url'] ?? ''), $product) ? 80 : 0);

            return $scoreB <=> $scoreA;
        });

        return [
            'results' => array_slice($merged, 0, 8),
            'images' => array_slice($images, 0, 12),
            'errors' => $errors,
        ];
    }

    /**
     * Dokument nie zastępuje karty produktu: może dostarczyć certyfikat,
     * ale nie zawiera galerii ani pełnego opisu.
     *
     * @param  list<array{url: string, title: string, snippet: string}>  $results
     */
    private function hasEnoughPageResults(array $results, int $threshold): bool
    {
        $pages = 0;
        foreach ($results as $row) {
            $url = (string) ($row['url'] ?? '');
            if ($url === '' || ProductDocumentDownloader::looksLikeDocumentUrl($url)) {
                continue;
            }

            $pages++;
            if ($pages >= max(1, $threshold)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function buildQueries(Product $product, string $phase): array
    {
        $queries = $this->identity->searchQueries($product, $phase);
        // zachowaj też stare frazy „7-003 B S1 SRC” (obuwie z normami w nazwie)
        $legacy = $this->legacySafetyShoePhrase($product);
        if ($legacy !== '') {
            $mfr = $this->identity->shortBrand((string) $product->manufacturer);
            array_unshift($queries, $this->identity->queryWithManufacturer(
                trim('"'.$legacy.'" '.$mfr.' buty ochronne'),
                $product
            ));
        }

        $queries = array_map(
            fn (string $q): string => $this->identity->queryWithManufacturer($q, $product),
            $queries
        );
        $named = $this->identity->productNameWithManufacturer($product);

        return $queries !== [] ? array_values(array_unique($queries)) : ($named !== '' ? [$named] : []);
    }

    private function searchCacheKey(string $mode, string $phase, string $query, string $step = ''): string
    {
        $payload = $this->searchProviderName().'|'.$mode.'|'.$phase.'|'.$query;
        if ($step !== '') {
            $payload .= '|'.$step;
        }

        return 'enrich_search_'.self::SEARCH_CACHE_VERSION.':'
            .hash('sha256', $payload);
    }

    /**
     * @param  list<string>  $queries
     */
    private function primarySkuQuery(Product $product, array $queries): string
    {
        $named = $this->identity->productNameWithManufacturer($product);

        return $named !== '' ? $named : ($queries[0] ?? '');
    }

    /**
     * 1) SKU w całym internecie; 2) gdy pusto — domena producenta i SKU na jej stronie.
     *
     * @return array{
     *     results: list<array{url: string, title: string, snippet: string}>,
     *     provider: string,
     *     errors: list<string>
     * }
     */
    private function searchSkuThenManufacturerSite(
        Product $product,
        string $skuQuery,
        TavilySearchProfile $profile,
        string $cacheMode,
        string $phase,
    ): array {
        $errors = [];
        if ($skuQuery === '') {
            return ['results' => [], 'provider' => $this->searchProviderName(), 'errors' => []];
        }
        if ($this->settings->usesTavilySearch()) {
            $key = $this->settings->resolve()['tavily_api_key'] ?? null;
            if (! is_string($key) || $key === '') {
                return ['results' => [], 'provider' => 'tavily', 'errors' => []];
            }
        }

        $open = $this->cachedTavilySearch(
            $product,
            $skuQuery,
            [],
            $profile,
            $cacheMode,
            $phase,
            'open',
            $errors
        );
        if ($open['results'] !== []) {
            return [
                'results' => $open['results'],
                'provider' => $open['provider'],
                'errors' => $errors,
            ];
        }

        $mfrDomains = $this->manufacturers->domainsFor($product);
        if ($mfrDomains === []) {
            try {
                $mfrDomains = $this->manufacturers->discoverOfficialDomains($product);
            } catch (TavilyQuotaExceededException $e) {
                throw $e;
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }
        if ($mfrDomains === []) {
            return ['results' => [], 'provider' => $this->searchProviderName(), 'errors' => $errors];
        }

        $mfr = $this->cachedTavilySearch(
            $product,
            $skuQuery,
            $mfrDomains,
            $profile,
            $cacheMode,
            $phase,
            'mfr',
            $errors
        );

        return [
            'results' => $mfr['results'],
            'provider' => $mfr['results'] !== []
                ? $this->searchProviderName().'_manufacturer'
                : $mfr['provider'],
            'errors' => $errors,
        ];
    }

    /**
     * @param  list<string>  $includeDomains
     * @param  list<string>  $errors
     * @return array{
     *     results: list<array{url: string, title: string, snippet: string}>,
     *     provider: string
     * }
     */
    private function cachedTavilySearch(
        Product $product,
        string $query,
        array $includeDomains,
        TavilySearchProfile $profile,
        string $cacheMode,
        string $phase,
        string $step,
        array &$errors,
    ): array {
        $cacheKey = $this->searchCacheKey($cacheMode, $phase, $query, $step);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && isset($cached['results']) && is_array($cached['results'])) {
            return [
                'results' => $this->filterResultsByIdentity($cached['results'], $product),
                'provider' => (string) ($cached['provider'] ?? $this->searchProviderName().'_cache'),
            ];
        }

        try {
            $pack = $this->searchViaConfiguredEngine($query, $includeDomains, $profile);
            $packResults = $this->filterResultsByIdentity($pack['results'], $product);
            $provider = $includeDomains !== []
                ? $this->searchProviderName().'_manufacturer'
                : $this->searchProviderName();
            if ($packResults !== []) {
                Cache::put($cacheKey, [
                    'results' => $packResults,
                    'images' => [],
                    'provider' => $provider,
                ], now()->addDays($cacheMode === 'large_model' ? 7 : $profile->cacheDays));
            }

            return ['results' => $packResults, 'provider' => $provider];
        } catch (TavilyQuotaExceededException $e) {
            throw $e;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();

            return ['results' => [], 'provider' => $this->searchProviderName()];
        }
    }

    /** Preferuj nazwę z normami („7-003 B S1 SRC”) zamiast SKU z kodem katalogowym. */
    private function legacySafetyShoePhrase(Product $product): string
    {
        $name = trim((string) $product->name);
        $sku = trim((string) $product->sku);

        if ($name !== '' && preg_match('/\d{1,2}-\d{3}/', $name)
            && preg_match('/\b(S1|S2|S3|SRC|HRO|P|FO|CI|HI)\b/i', $name)) {
            return $name;
        }
        if (preg_match('/^(\d{1,2}-\d{3}(?:\s+[A-Za-z])?(?:\s+(?:S1|S2|S3|SRC|HRO|P|FO|CI|HI))+)/iu', $sku, $m)) {
            return trim($m[1]);
        }
        if (preg_match('/^(\d{1,2}-\d{3}(?:\s+[A-Za-z])?)/u', $sku, $m)) {
            if ($name !== '' && preg_match_all('/\b(S1|S2|S3|SRC|HRO|P|FO|CI|HI)\b/i', $name, $norms)) {
                return trim($m[1].' '.implode(' ', array_unique($norms[0])));
            }

            return $m[1];
        }

        return '';
    }

    /**
     * @param  list<array{url: string, title: string, snippet: string}>  $results
     * @return list<array{url: string, title: string, snippet: string}>
     */
    private function filterResultsByIdentity(array $results, Product $product): array
    {
        $matched = [];
        foreach ($results as $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = $this->identity->preferredLocaleUrl((string) ($row['url'] ?? ''), $product);
            $hay = mb_strtolower(
                $url.' '.($row['title'] ?? '').' '.($row['snippet'] ?? '')
            );
            $title = (string) ($row['title'] ?? '');

            if (! $this->identity->hayMentionsProduct($hay, $product)) {
                continue;
            }
            // Kod/model musi być w URL lub tytule — sam snippet (blog/listing) nie wystarczy
            if (! $this->identity->coreInUrlOrTitle($url, $title, $product)) {
                continue;
            }
            if (preg_match('#(ochronki na buty|shoe[- ]?cover|folie na buty|nakladki na obuwie)#i', $hay)) {
                continue;
            }
            if ($this->isListingWithoutProduct($url, $product)) {
                continue;
            }
            $matched[] = [
                'url' => $url,
                'title' => $title !== '' ? $title : $url,
                'snippet' => (string) ($row['snippet'] ?? ''),
            ];
        }

        usort($matched, fn (array $a, array $b): int => $this->resultQuality($b, $product) <=> $this->resultQuality($a, $product));

        return $matched;
    }

    private function isListingWithoutProduct(string $url, Product $product): bool
    {
        $u = mb_strtolower($url);
        if ($this->identity->coreInUrlOrTitle($u, '', $product)) {
            return false;
        }
        foreach ([
            '/manufacturer/', '/producent/', '/brand/', '/marka/',
            '/category/', '/kategoria/', '/kategorie/', '/collection/',
            '/search', '/szukaj', '/catalog/', '/katalog/', '/blog/',
        ] as $needle) {
            if (str_contains($u, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** „7-003” nie może trafić w „97-003”. */
    private function containsArtCode(string $hay, string $core): bool
    {
        $core = mb_strtolower(trim($core));
        if ($core === '') {
            return false;
        }
        if (preg_match('/(?<![0-9])'.preg_quote($core, '/').'(?![0-9])/u', $hay) === 1) {
            return true;
        }
        $compact = str_replace('-', '', $core);

        return preg_match('/(?<![0-9])'.preg_quote($compact, '/').'(?![0-9])/u', $hay) === 1;
    }

    /**
     * SKU tekstowe: „clic up o1 fo src” ≈ URL „…clic_up…o1_fo_src…” / tytuł „CLIC UP”.
     * Wymaga wszystkich tokenów nazwy + ≥50% norm (O1/SRC/…).
     */
    private function hayMentionsSkuTokens(string $hay, string $skuNorm): bool
    {
        $parts = preg_split('/[\s\-·\/_]+/u', mb_strtolower(trim($skuNorm))) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || mb_strlen($part) < 2) {
                continue;
            }
            $tokens[] = $part;
        }
        if ($tokens === []) {
            return false;
        }

        $normSet = ['s1', 's2', 's3', 'src', 'hro', 'o1', 'o2', 'fo', 'ci', 'hi', 'wr', 'an'];
        $nameTokens = [];
        $normTokens = [];
        foreach ($tokens as $token) {
            if (in_array($token, $normSet, true)) {
                $normTokens[] = $token;
            } else {
                $nameTokens[] = $token;
            }
        }
        if ($nameTokens === []) {
            $nameTokens = $tokens;
            $normTokens = [];
        }

        $hayCompact = preg_replace('/[^a-z0-9]+/i', '', $hay) ?? $hay;
        foreach ($nameTokens as $token) {
            if (preg_match('/^\d{3,}$/', $token) === 1) {
                // 1000 ≠ 1000g
                $ok = preg_match('/(?<![0-9])'.preg_quote($token, '/').'(?![0-9a-z])/iu', $hay) === 1
                    || preg_match('/(?<![0-9])'.preg_quote($token, '/').'(?![0-9a-z])/iu', $hayCompact) === 1;
                if (! $ok) {
                    return false;
                }

                continue;
            }
            if (! str_contains($hay, $token) && ! str_contains($hayCompact, $token)) {
                return false;
            }
        }
        if ($normTokens === []) {
            return true;
        }
        $hits = 0;
        foreach ($normTokens as $token) {
            if (str_contains($hay, $token) || str_contains($hayCompact, $token)) {
                $hits++;
            }
        }

        return $hits >= max(1, (int) ceil(count($normTokens) * 0.5));
    }

    /**
     * @param  array{url: string, title?: string, snippet?: string}  $row
     */
    private function resultQuality(array $row, Product $product): int
    {
        $url = mb_strtolower($row['url'] ?? '');
        $title = mb_strtolower((string) ($row['title'] ?? ''));
        $score = 0;
        if ($this->identity->coreInUrlOrTitle($url, '', $product)) {
            $score += 100;
        }
        if ($this->identity->coreInUrlOrTitle('', $title, $product)) {
            $score += 40;
        }
        if (str_contains($url, 'produkt') || str_contains($url, 'product') || str_contains($url, '/sklep/')) {
            $score += 20;
        }
        $brand = mb_strtolower($this->identity->shortBrand((string) $product->manufacturer));
        if ($brand !== '' && (str_contains($url, $brand) || str_contains($title, $brand))) {
            $score += 25;
        }

        return $score;
    }

    /**
     * SKU „9-075 S3 SRC HRO” ≈ tytuł „9-075 S3 HRO SRC” (inna kolejność norm).
     */
    private function hayMentionsSku(string $hay, string $skuNorm): bool
    {
        if ($skuNorm === '') {
            return false;
        }
        if (str_contains($hay, $skuNorm)) {
            return true;
        }
        $skuLoose = preg_replace('/\s+/u', '', $skuNorm) ?? $skuNorm;
        $hayLoose = preg_replace('/\s+/u', '', $hay) ?? $hay;
        if ($skuLoose !== '' && str_contains($hayLoose, $skuLoose)) {
            return true;
        }
        $skuCompact = preg_replace('/[^a-z0-9]/i', '', $skuNorm) ?? $skuNorm;
        $hayCompact = preg_replace('/[^a-z0-9]/i', '', $hay) ?? $hay;
        if ($skuCompact !== '' && str_contains($hayCompact, $skuCompact)) {
            return true;
        }

        // Nazwa handlowa bez kodu art. (CLIC UP, BOLT UP, MaxiFlex…)
        if (! preg_match('/\b(\d{1,2}-\d{3})\b/', $skuNorm, $m)) {
            return $this->hayMentionsSkuTokens($hay, $skuNorm);
        }
        $core = $m[1];
        if (! $this->containsArtCode($hay, $core)) {
            return false;
        }
        $parts = preg_split('/[\s\-·\/]+/u', $skuNorm) ?: [];
        $extras = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || $part === $core) {
                continue;
            }
            // litery/normy (S3, HRO, B) — nie cyfry ze SKU (42, 844)
            if (preg_match('/^[a-z][a-z0-9]*$/i', $part) === 1) {
                $extras[] = mb_strtolower($part);
            }
        }
        if ($extras === []) {
            return true;
        }
        $hits = 0;
        foreach ($extras as $token) {
            if (str_contains($hay, $token) || str_contains($hayCompact, $token)) {
                $hits++;
            }
        }

        return $hits >= max(1, (int) ceil(count($extras) * 0.5));
    }

    /**
     * @return array{
     *     results: list<array{url: string, title: string, snippet: string}>,
     *     provider: string,
     *     raw_content: ?string
     * }
     */
    private function searchViaAiWeb(string $query, Product $product, string $phase): array
    {
        if ($this->settings->usesDuckDuckGoSearch()) {
            $hits = $this->duckDuckGo->search($query, 5);

            return [
                'results' => $hits,
                'provider' => 'duckduckgo',
                'raw_content' => null,
            ];
        }

        $prompt = <<<PROMPT
Znajdź kartę produktu BHP ze SKU {$product->sku} ({$product->manufacturer} {$product->name}).
Zapytanie: {$query}. Zwróć tylko URL stron z tym kodem produktu.
PROMPT;

        $seconds = (int) ($this->settings->resolve()['timeout_seconds'] ?? 90);
        $response = $this->llm->chatWithWebSearch($prompt, max(60, min(120, $seconds)));
        $results = [];

        foreach ($response['citations'] as $citation) {
            $results[] = [
                'url' => $citation['url'],
                'title' => $citation['title'],
                'snippet' => '',
            ];
        }

        if ($results === []) {
            foreach ($this->extractUrlsFromText($response['content']) as $url) {
                $results[] = [
                    'url' => $url,
                    'title' => $url,
                    'snippet' => '',
                ];
            }
        }

        if ($results === []) {
            throw new RuntimeException('AI web_search nie zwróciło URL-i.');
        }

        return [
            'results' => array_slice($results, 0, 5),
            'provider' => 'ai_web_search',
            'raw_content' => $response['content'],
        ];
    }

    /**
     * @param  list<string>  $includeDomains
     * @return array{
     *     results: list<array{url: string, title: string, snippet: string}>,
     *     images: list<string>,
     *     provider: string,
     *     raw_content: ?string
     * }
     */
    private function searchProviderName(): string
    {
        return $this->settings->usesDuckDuckGoSearch() ? 'duckduckgo' : 'tavily';
    }

    /**
     * @param  list<string>  $includeDomains
     * @return array{
     *     results: list<array{url: string, title: string, snippet: string}>,
     *     images: list<string>,
     *     provider: string,
     *     raw_content: ?string
     * }
     */
    private function searchViaConfiguredEngine(
        string $query,
        array $includeDomains,
        TavilySearchProfile $profile,
    ): array {
        if ($this->settings->usesDuckDuckGoSearch()) {
            $results = $this->duckDuckGo->search($query, $profile->maxResults, $includeDomains);
            if ($results === []) {
                throw new RuntimeException(
                    $includeDomains !== []
                        ? 'Brak wyników DuckDuckGo na stronie producenta.'
                        : 'DuckDuckGo nie zwróciło wyników.'
                );
            }

            return [
                'results' => $results,
                'images' => [],
                'provider' => $includeDomains !== [] ? 'duckduckgo_preferred' : 'duckduckgo',
                'raw_content' => null,
            ];
        }

        return $this->searchViaTavily($query, $includeDomains, $profile, false);
    }

    private function searchViaTavily(
        string $query,
        array $includeDomains = [],
        ?TavilySearchProfile $profile = null,
        bool $includeImages = false,
    ): array {
        TavilyQuotaGuard::assertAllowed();

        $cfg = $this->settings->resolve();
        $key = $cfg['tavily_api_key'] ?? null;
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Brak klucza Tavily. Uzupełnij go w Ustawieniach AI.');
        }

        $profile ??= $this->settings->tavilySearchProfile();
        $body = [
            'api_key' => $key,
            'query' => $query,
            'search_depth' => 'basic',
            'include_answer' => false,
            'max_results' => $profile->maxResults,
            'include_images' => $includeImages,
        ];
        if ($includeDomains !== []) {
            $body['include_domains'] = $includeDomains;
        }

        $response = $this->postTavilySearch($body);
        TavilyQuotaGuard::ensureSuccessful($response);

        $payload = $response->json();
        $rows = is_array($payload['results'] ?? null) ? $payload['results'] : [];
        $results = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = (string) ($row['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $results[] = [
                'url' => $url,
                'title' => (string) ($row['title'] ?? $url),
                'snippet' => (string) ($row['content'] ?? ''),
            ];
        }

        $images = $includeImages
            ? $this->normalizeImageList($payload['images'] ?? [])
            : [];

        if ($results === [] && $images === []) {
            throw new RuntimeException(
                $includeDomains !== []
                    ? 'Brak wyników w preferowanych sklepach BHP.'
                    : 'Tavily nie zwróciło wyników.'
            );
        }

        return [
            'results' => $results,
            'images' => $images,
            'provider' => $includeDomains !== [] ? 'tavily_preferred' : 'tavily',
            'raw_content' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function postTavilySearch(array $body): Response
    {
        $response = Http::acceptJson()
            ->timeout(12)
            ->connectTimeout(5)
            ->post('https://api.tavily.com/search', $body);

        $attempt = 0;
        while ($response->status() === 429 && $attempt < 2) {
            if (! app()->environment('testing')) {
                sleep([2, 6][$attempt] ?? 6);
            }
            $response = Http::acceptJson()
                ->timeout(12)
                ->connectTimeout(5)
                ->post('https://api.tavily.com/search', $body);
            $attempt++;
        }

        return $response;
    }

    /**
     * @return list<string>
     */
    private function normalizeImageList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            $url = null;
            if (is_string($item)) {
                $url = $item;
            } elseif (is_array($item)) {
                $url = (string) ($item['url'] ?? $item['src'] ?? '');
            }
            if (! is_string($url) || ! str_starts_with($url, 'http')) {
                continue;
            }
            if (! ProductImageDownloader::looksLikeImageUrl($url)) {
                continue;
            }
            $out[] = $url;
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private function preferredDomains(): array
    {
        return $this->normalizeDomainList(config('enrichment.preferred_domains', []));
    }

    /**
     * @return list<string>
     */
    private function retailerDomains(): array
    {
        $list = $this->normalizeDomainList(config('enrichment.retailer_domains', []));

        return $list !== [] ? $list : $this->preferredDomains();
    }

    /**
     * @return list<string>
     */
    private function normalizeDomainList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $domain) {
            if (! is_string($domain)) {
                continue;
            }
            $clean = mb_strtolower(trim(preg_replace('#^https?://#i', '', $domain) ?? $domain));
            $clean = rtrim($clean, '/');
            if ($clean !== '') {
                $out[] = $clean;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private function extractUrlsFromText(string $text): array
    {
        if (preg_match_all('#https?://[^\s<>"\']+#i', $text, $matches) < 1) {
            return [];
        }

        $urls = [];
        foreach ($matches[0] as $url) {
            $clean = rtrim((string) $url, '.,);]');
            if ($clean !== '') {
                $urls[] = $clean;
            }
        }

        return array_values(array_unique($urls));
    }
}
