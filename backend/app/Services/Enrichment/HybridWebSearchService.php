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
    private const SEARCH_CACHE_VERSION = 'v26';

    /** Ile wyników brać z darmowej wyszukiwarki przed filtrem tożsamości produktu. */
    private const FREE_SEARCH_CANDIDATES = 20;

    /** Ile różnych fraz próbujemy w otwartym internecie, zanim pójdziemy na domenę producenta. */
    private const OPEN_QUERY_ATTEMPTS = 4;

    /** Tyle kart produktu wystarcza, żeby przerwać drabinkę fraz. */
    private const OPEN_ENOUGH_PAGES = 3;

    /** Tyle cudzych oznaczeń modeli w treści zdradza listę katalogową, nie kartę produktu. */
    private const LISTING_FOREIGN_CODES = 3;

    public function __construct(
        private readonly AiSettingsService $settings,
        private readonly OpenAiCompatibleClient $llm,
        private readonly DuckDuckGoHtmlSearch $duckDuckGo,
        private readonly ManufacturerDomainResolver $manufacturers,
        private readonly ProductSearchIdentity $identity,
        private readonly ProductPageFetcher $pages,
        private readonly CatalogIndexSearch $catalog,
    ) {}

    /**
     * Lokalny indeks sitemap — darmowy i bez limitów, więc pytamy go pierwszego.
     *
     * @return list<array{url: string, title: string, snippet: string}>
     */
    private function catalogHits(Product $product): array
    {
        try {
            // sitemapa nie niesie tytułów ani zajawek, więc filtr od wyników wyszukiwarki
            // odrzuciłby tu wszystko — tożsamości pilnuje confirmedCatalogHits
            return $this->catalog->findFor($product);
        } catch (Throwable $e) {
            Log::info('Catalog index lookup failed', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

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
        $catalogHits = $this->confirmedCatalogHits($this->catalogHits($product), $product);
        if ($this->hasEnoughPageResults($catalogHits, 1)) {
            return [
                'results' => array_slice($catalogHits, 0, 8),
                'images' => [],
                'provider' => 'catalog_index',
                'raw_content' => null,
            ];
        }

        $cfg = $this->settings->resolve();
        $profile = $this->settings->tavilySearchProfile();
        $skuQuery = $this->primarySkuQuery($product, $queries);
        $found = $this->searchSkuThenManufacturerSite(
            $product,
            $this->openSearchQueries($product, $queries),
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
        $catalogHits = $this->confirmedCatalogHits($this->catalogHits($product), $product);
        if ($this->hasEnoughPageResults($catalogHits, 1)) {
            return [
                'results' => array_slice($catalogHits, 0, 8),
                'images' => [],
                'provider' => 'catalog_index',
                'raw_content' => null,
            ];
        }

        $skuQuery = $this->primarySkuQuery($product, $queries);
        $profile = $this->settings->tavilySearchProfile();
        $found = $this->searchSkuThenManufacturerSite(
            $product,
            $this->openSearchQueries($product, $queries),
            $profile,
            'large_model',
            $phase
        );
        $merged = $found['results'];
        $errors = $found['errors'];
        $provider = $found['provider'];

        if ($merged === [] && ! $this->settings->usesFreeWebSearch()) {
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
            if ($this->identity->looksLikeInternalSku($product)
                && $this->identity->internalSkuCore($product) === '') {
                throw new RuntimeException(
                    'SKU '.$product->sku.' wygląda na kod z naszego cennika, a nie numer katalogowy '
                    .'producenta — w internecie takiego kodu nie ma. Uzupełnij kod producenta w produkcie. '
                    .($errors !== [] ? implode(' | ', array_slice($errors, 0, 2)) : '')
                );
            }
            $bare = $this->identity->stripBrandPrefix(
                (string) $product->sku,
                $this->identity->shortBrand((string) $product->manufacturer)
            );
            throw new RuntimeException(
                'Brak stron produktu (duży model, SKU '.$product->sku
                .($bare !== '' && $bare !== $product->sku ? ' / '.$bare : '')
                .'). '
                .$this->triedQueriesNote($product, $queries)
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
        foreach (['manufacturer', 'industry'] as $phase) {
            $ladder = $this->openSearchQueries($product, $this->buildQueries($product, $phase));
            foreach (array_merge(TavilySearchProfile::MODES, ['large_model']) as $mode) {
                foreach ($ladder as $query) {
                    foreach (['open', 'mfr'] as $step) {
                        Cache::forget($this->searchCacheKey($mode, $phase, $query, $step));
                    }
                }
            }
        }
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
    /**
     * Trafienie z indeksu ucina szukanie w sieci, więc musi nieść kod produktu
     * albo pełną nazwę. Sama marka wpuszczała inny model z tej samej domeny —
     * „maskpol” siedzi w adresie każdej strony producenta.
     *
     * @param  list<array{url: string, title: string, snippet: string}>  $hits
     * @return list<array{url: string, title: string, snippet: string}>
     */
    private function confirmedCatalogHits(array $hits, Product $product): array
    {
        $out = [];
        foreach ($hits as $row) {
            $url = $this->identity->preferredLocaleUrl((string) ($row['url'] ?? ''), $product);
            $title = (string) ($row['title'] ?? '');
            $hay = mb_strtolower($url.' '.$title.' '.($row['snippet'] ?? ''));
            if ($this->isListingWithoutProduct($url, $product)
                || $this->identity->pageClaimsAnotherCode($url, $title, $product)) {
                continue;
            }
            // sklepy skracają oznaczenie w adresie („maska-mt-212” zamiast MT 212/2),
            // więc wystarczy kod z rodziny naszego — obcy model nadal odpada
            if ($this->identity->hayHasProductCode($hay, $product)
                || $this->identity->urlOrTitleCarriesCodeFamily($url, $title, $product)
                || $this->identity->hayHasNamePhrase($url.' '.$title, $product)
                || $this->identity->nameTokensMatch($url.' '.$title, $product)) {
                $out[] = [
                    'url' => $url,
                    'title' => $title !== '' ? $title : $url,
                    'snippet' => (string) ($row['snippet'] ?? ''),
                ];
            }
        }

        return $out;
    }

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
     * Bez tego komunikat sugeruje, że pytaliśmy wyłącznie o SKU.
     *
     * @param  list<string>  $queries
     */
    private function triedQueriesNote(Product $product, array $queries): string
    {
        $tried = $this->openSearchQueries($product, $queries);

        return $tried === [] ? '' : 'Szukano: „'.implode('”, „', $tried).'”. ';
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
     * Frazy dla otwartego internetu — od „URG-914 Urgent” po nazwę z kodem.
     * Pierwsza, która da karty produktu, kończy szukanie.
     *
     * @param  list<string>  $queries
     * @return list<string>
     */
    private function openSearchQueries(Product $product, array $queries): array
    {
        $ladder = [];
        $legacy = $this->legacySafetyShoePhrase($product);
        if ($legacy !== '') {
            $ladder[] = $this->identity->queryWithManufacturer(
                trim('"'.$legacy.'" '.$this->identity->shortBrand((string) $product->manufacturer)),
                $product
            );
        }
        foreach ($this->identity->primaryQueries($product) as $query) {
            $ladder[] = $query;
        }
        foreach ($queries as $query) {
            $ladder[] = $query;
        }

        return array_slice(
            array_values(array_unique(array_filter(
                $ladder,
                static fn (string $q): bool => trim($q) !== ''
            ))),
            0,
            self::OPEN_QUERY_ATTEMPTS
        );
    }

    /**
     * 1) SKU w całym internecie; 2) gdy pusto — domena producenta i SKU na jej stronie.
     *
     * @param  list<string>  $skuQueries
     * @return array{
     *     results: list<array{url: string, title: string, snippet: string}>,
     *     provider: string,
     *     errors: list<string>
     * }
     */
    private function searchSkuThenManufacturerSite(
        Product $product,
        array $skuQueries,
        TavilySearchProfile $profile,
        string $cacheMode,
        string $phase,
    ): array {
        $errors = [];
        $skuQueries = array_values(array_filter($skuQueries, static fn (string $q): bool => trim($q) !== ''));
        if ($skuQueries === []) {
            return ['results' => [], 'provider' => $this->searchProviderName(), 'errors' => []];
        }
        if ($this->settings->usesTavilySearch()) {
            $key = $this->settings->resolve()['tavily_api_key'] ?? null;
            if (! is_string($key) || $key === '') {
                return ['results' => [], 'provider' => 'tavily', 'errors' => []];
            }
        }

        // Jedno trafienie to za mało na pełny opis — zbieramy karty z kolejnych fraz
        // („1202 Urgent”, potem „Rękawice 1202 kozia czerwona Urgent”), aż uzbiera się kilka.
        $openResults = [];
        $seen = [];
        $openProvider = '';
        // Tavily jest płatne — tam pierwsze trafienie kończy szukanie.
        $enoughPages = $this->settings->usesFreeWebSearch() ? self::OPEN_ENOUGH_PAGES : 1;
        foreach ($skuQueries as $query) {
            $open = $this->cachedTavilySearch(
                $product,
                $query,
                [],
                $profile,
                $cacheMode,
                $phase,
                'open',
                $errors
            );
            foreach ($open['results'] as $row) {
                $key = mb_strtolower((string) ($row['url'] ?? ''));
                if ($key === '' || isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $openResults[] = $row;
            }
            if ($openProvider === '' && $open['results'] !== []) {
                $openProvider = $open['provider'];
            }
            if ($this->hasEnoughPageResults($openResults, $enoughPages)) {
                break;
            }
        }
        if ($openResults !== []) {
            usort(
                $openResults,
                fn (array $a, array $b): int => $this->resultQuality($b, $product) <=> $this->resultQuality($a, $product)
            );

            return [
                'results' => $openResults,
                'provider' => $openProvider !== '' ? $openProvider : $this->searchProviderName(),
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

        // Na stronie producenta kod bywa zapisany inaczej niż w cenniku, więc gdy
        // zapytanie po SKU nic nie da, próbujemy drugiej frazy z drabinki (zwykle nazwy).
        $mfr = ['results' => [], 'provider' => $this->searchProviderName()];
        foreach (array_slice($skuQueries, 0, 2) as $query) {
            $mfr = $this->cachedTavilySearch(
                $product,
                $query,
                $mfrDomains,
                $profile,
                $cacheMode,
                $phase,
                'mfr',
                $errors
            );
            if ($mfr['results'] !== []) {
                break;
            }
        }

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
            if ($packResults === [] && $pack['results'] !== []) {
                $packResults = $this->keepHitsMentioningSkuOnPage($pack['results'], $product);
            }
            if ($packResults === [] && $pack['results'] !== []) {
                $errors[] = 'Odrzucono '.count($pack['results'])
                    .' stron bez SKU '.$product->sku.' w tytule/URL (sprawdzono też treść kart).';
                Log::info('Search results rejected by identity', [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'query' => $query,
                    'urls' => array_slice(array_column($pack['results'], 'url'), 0, 10),
                ]);
            }
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
            // Krótki kod (1000) — tylko URL/tytuł. ROBFM / 3-60NM może być w snippecie sklepu.
            if (! $this->identity->coreInUrlOrTitle($url, $title, $product)
                && ! $this->isDistinctiveSku($product)
                && ! $this->identity->nameTokensMatch($url.' '.$title, $product)) {
                continue;
            }
            if (preg_match('#(ochronki na buty|shoe[- ]?cover|folie na buty|nakladki na obuwie)#i', $hay)) {
                continue;
            }
            if ($this->isListingWithoutProduct($url, $product)) {
                continue;
            }
            if ($this->identity->pageClaimsAnotherCode($url, $title, $product)) {
                continue;
            }
            $matched[] = [
                'url' => $url,
                'title' => $title !== '' ? $title : $url,
                'snippet' => (string) ($row['snippet'] ?? ''),
            ];
        }

        if ($matched === []) {
            $matched = $this->fallbackDistinctiveHits($results, $product);
        }

        usort($matched, fn (array $a, array $b): int => $this->resultQuality($b, $product) <=> $this->resultQuality($a, $product));

        return $matched;
    }

    /**
     * @param  list<array{url?: string, title?: string, snippet?: string}>  $results
     * @return list<array{url: string, title: string, snippet: string}>
     */
    private function fallbackDistinctiveHits(array $results, Product $product): array
    {
        $codes = [];
        foreach ([(string) $product->sku, $this->identity->internalSkuCore($product)] as $code) {
            $code = mb_strtolower(trim($code));
            if ($code !== '' && mb_strlen($code) >= 4) {
                $codes[] = $code;
            }
        }
        if ($codes === []) {
            return [];
        }

        $out = [];
        foreach ($results as $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = (string) ($row['url'] ?? '');
            $title = (string) ($row['title'] ?? '');
            $snippet = (string) ($row['snippet'] ?? '');
            $hay = mb_strtolower($url.' '.$title.' '.$snippet);
            $hasCode = false;
            foreach ($codes as $code) {
                if ($this->identity->codeInText($hay, $code)) {
                    $hasCode = true;
                    break;
                }
            }
            if (! $hasCode) {
                continue;
            }
            // sam kod to za mało: „1202” to też alarm Apollo 11 i szerokość zdjęcia
            if (! $this->identity->hayHasBrand($hay, $product)) {
                continue;
            }
            $out[] = [
                'url' => $url,
                'title' => $title !== '' ? $title : $url,
                'snippet' => $snippet,
            ];
            if (count($out) >= 5) {
                break;
            }
        }

        return $out;
    }

    /**
     * Sklep często nie ma SKU w slugu — otwórz 5 kart i zostaw te z kodem w treści.
     *
     * @param  list<array{url?: string, title?: string, snippet?: string}>  $results
     * @return list<array{url: string, title: string, snippet: string}>
     */
    private function keepHitsMentioningSkuOnPage(array $results, Product $product): array
    {
        $fetched = $this->pages->fetch($results, (string) $product->sku, 5, []);
        $out = [];
        foreach ($fetched['pages'] as $page) {
            $url = (string) ($page['url'] ?? '');
            $text = (string) ($page['text'] ?? '');
            if ($url === '' || $text === '') {
                continue;
            }
            $title = '';
            foreach ($results as $row) {
                if (is_array($row) && mb_strtolower((string) ($row['url'] ?? '')) === mb_strtolower($url)) {
                    $title = (string) ($row['title'] ?? '');
                    break;
                }
            }
            if (! $this->pageProvesProductIdentity($url, $title, $text, $product)) {
                continue;
            }
            $out[] = [
                'url' => $url,
                'title' => $title !== '' ? $title : $url,
                'snippet' => mb_substr($text, 0, 400),
            ];
        }

        return $out;
    }

    /**
     * Kod w treści dowodzi tylko wzmianki — karta akcesorium wymienia kompatybilne modele,
     * a „Oferta” czy „Deklaracje zgodności” wymieniają cały katalog producenta. Kartą tego
     * produktu jest dopiero strona, która ma jego oznaczenie w adresie albo tytule.
     */
    private function pageProvesProductIdentity(
        string $url,
        string $title,
        string $text,
        Product $product,
    ): bool {
        if (! $this->identity->hayMentionsProduct($url.' '.$text, $product)) {
            return false;
        }
        if ($this->isListingWithoutProduct($url, $product)) {
            return false;
        }
        // tytuł z cudzym oznaczeniem („MT 213/2”) przesądza — to karta innego modelu
        if ($this->identity->pageClaimsAnotherCode($url, $title, $product)) {
            return false;
        }

        if ($this->identity->urlOrTitleCarriesCodeFamily($url, $title, $product)
            || $this->identity->hayHasNamePhrase($url.' '.$title, $product)) {
            return true;
        }

        // kod tylko w treści: sklep bywa skąpy w tytule, ale lista katalogowa zdradza się
        // dziesiątkami cudzych oznaczeń
        return $this->identity->foreignCodeCount($text, $product) < self::LISTING_FOREIGN_CODES;
    }

    private function isDistinctiveSku(Product $product): bool
    {
        $sku = trim((string) $product->sku);

        return mb_strlen($sku) >= 4 && preg_match('/[a-z]/iu', $sku) === 1;
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
            // zbiorcze strony producenta wymieniają cały asortyment, w tym nasz kod
            '/deklaracje', '/certyfikat', '/do-pobrania', '/dokumenty', '/downloads',
            '/aktualnosci', '/news',
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
        if ($this->settings->usesFreeWebSearch()) {
            $hits = $this->duckDuckGo->search($query, self::FREE_SEARCH_CANDIDATES);

            return [
                'results' => $hits,
                'provider' => $this->searchProviderName(),
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
        return $this->settings->searchEngine();
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
        if ($this->settings->usesFreeWebSearch()) {
            $engine = $this->searchProviderName();
            // Darmowe szukanie nic nie kosztuje, a SearXNG miesza trafne karty z szumem —
            // bierzemy szerszą listę i zawężamy ją dopiero filtrem tożsamości produktu.
            $results = $this->duckDuckGo->search(
                $query,
                max($profile->maxResults, self::FREE_SEARCH_CANDIDATES),
                $includeDomains
            );
            if ($results === []) {
                throw new RuntimeException(
                    $includeDomains !== []
                        ? 'Brak wyników ('.$engine.') na stronie producenta.'
                        : $engine.' nie zwrócił wyników.'
                );
            }

            return [
                'results' => $results,
                'images' => [],
                'provider' => $includeDomains !== [] ? $engine.'_preferred' : $engine,
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
