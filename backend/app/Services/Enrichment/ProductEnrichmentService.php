<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Exceptions\EnrichmentCancelledException;
use App\Exceptions\ProductSourcesNotFoundException;
use App\Jobs\EnrichProductJob;
use App\Jobs\PrefetchProductSourcesJob;
use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductEnrichmentCache;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Support\BhpAttributeNormalizer;
use App\Support\PpeAssortment;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class ProductEnrichmentService
{
    /** Słowa, które pasują do połowy katalogu BHP — same nie potwierdzają modelu. */
    private const GENERIC_NAME_TOKENS = [
        'rekawice', 'rękawice', 'rekawiczki', 'spodnie', 'kurtka', 'bluza', 'koszulka', 'kamizelka',
        'ubranie', 'odziez', 'odzież', 'buty', 'obuwie', 'trzewiki', 'polbuty', 'półbuty', 'sandaly',
        'robocze', 'robocza', 'roboczy', 'ochronne', 'ochronna', 'ochronny', 'ochrona', 'bezpieczne',
        'damskie', 'meskie', 'męskie', 'czarne', 'czarny', 'biale', 'białe', 'zolte', 'żółte',
        'granatowe', 'szare', 'zielone', 'niebieskie', 'pomaranczowe', 'pomarańczowe', 'czerwone',
        'rozmiar', 'komplet', 'zestaw', 'para', 'sztuka', 'sztuk', 'model', 'seria', 'linia', 'wersja',
        'guma', 'gumowe', 'skora', 'skóra', 'skorzane', 'skórzane', 'lateks', 'nitryl', 'bawelna',
        'bawełna', 'poliester', 'pary',
    ];

    public function __construct(
        private readonly HybridWebSearchService $search,
        private readonly ProductImageDownloader $images,
        private readonly ProductDocumentDownloader $documents,
        private readonly ProductPageFetcher $pages,
        private readonly ProductDocumentFinder $documentFinder,
        private readonly ManufacturerDomainResolver $manufacturers,
        private readonly OpenAiCompatibleClient $llm,
        private readonly AiSettingsService $aiSettings,
        private readonly BhpAttributeNormalizer $bhpAttributes,
        private readonly ProductSearchIdentity $identity,
        private readonly ProductImageCandidateVerifier $imageVerifier,
        private readonly PpeAssortment $assortment,
    ) {}

    public function enqueueProduct(Product $product, User $user, bool $force = false): ProductEnrichmentBatch
    {
        if (! $force && $product->enrichment_status === Product::ENRICHMENT_DONE) {
            throw new RuntimeException('Produkt ma już pobrane dane. Użyj force=true, aby pobrać ponownie.');
        }

        $batch = ProductEnrichmentBatch::query()->create([
            'scope' => ProductEnrichmentBatch::SCOPE_PRODUCT,
            'scope_id' => $product->id,
            'total' => 1,
            'done' => 0,
            'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_QUEUED,
            'created_by' => $user->id,
            'force' => $force,
        ]);

        $product->update([
            'enrichment_status' => Product::ENRICHMENT_QUEUED,
            'enrichment_error' => null,
        ]);

        PrefetchProductSourcesJob::dispatch($product->id, $batch->id, $force);

        return $batch;
    }

    /**
     * @return array{batch: ProductEnrichmentBatch, product_ids: list<int>}
     */
    public function enqueuePriceList(
        PriceList $priceList,
        User $user,
        bool $force = false,
        bool $dispatchJobs = true,
    ): array {
        $ids = array_values(array_unique(array_map('intval', $priceList->product_ids ?? [])));
        if ($ids === []) {
            throw new RuntimeException('Ten cennik nie ma zapisanych produktów do wzbogacenia (stary import?).');
        }

        return $this->enqueueProductIds(
            $ids,
            $user,
            $force,
            ProductEnrichmentBatch::SCOPE_PRICE_LIST,
            (int) $priceList->id,
            $dispatchJobs,
        );
    }

    /**
     * @param  list<int>  $ids
     * @return array{batch: ProductEnrichmentBatch, product_ids: list<int>}
     */
    public function enqueueProductIds(
        array $ids,
        User $user,
        bool $force = false,
        string $scope = ProductEnrichmentBatch::SCOPE_PRODUCTS,
        int $scopeId = 0,
        bool $dispatchJobs = true,
    ): array {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            throw new RuntimeException('Brak produktów do wzbogacenia.');
        }

        $query = Product::query()->whereIn('id', $ids);
        if (! $force) {
            $query->whereNotIn('enrichment_status', [
                Product::ENRICHMENT_DONE,
                Product::ENRICHMENT_MANUAL,
            ]);
        }
        // zachowaj kolejność z $ids
        $eligible = $query->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $eligibleSet = array_fill_keys($eligible, true);
        $productIds = [];
        foreach ($ids as $id) {
            if (isset($eligibleSet[$id])) {
                $productIds[] = $id;
            }
        }

        if ($productIds === []) {
            throw new RuntimeException('Brak produktów do wzbogacenia (wszystkie już mają dane).');
        }

        $limit = $this->aiSettings->enrichmentBatchLimit();
        $requested = count($productIds);
        if ($requested > $limit) {
            $productIds = array_slice($productIds, 0, $limit);
        }

        $queued = count($productIds);
        $message = $requested > $limit
            ? "W kolejce: {$queued}/{$requested} (limit {$limit} — Ustawienia AI)"
            : 'W kolejce: '.$queued.' produktów';

        $batch = ProductEnrichmentBatch::query()->create([
            'scope' => $scope,
            'scope_id' => $scopeId > 0 ? $scopeId : ($user->id ?: 0),
            'total' => $queued,
            'done' => 0,
            'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_QUEUED,
            'created_by' => $user->id,
            'force' => $force,
            'message' => $message,
        ]);

        Product::query()->whereIn('id', $productIds)->update([
            'enrichment_status' => Product::ENRICHMENT_QUEUED,
            'enrichment_error' => null,
        ]);

        if ($dispatchJobs) {
            foreach ($productIds as $productId) {
                PrefetchProductSourcesJob::dispatch($productId, $batch->id, $force);
            }
        }

        return [
            'batch' => $batch,
            'product_ids' => $productIds,
        ];
    }

    /**
     * Szukanie + pobranie HTML do cache. Bez LLM — EnrichProductJob korzysta z ciepłego cache.
     * $onSearchReady wołane zaraz po zapisie packa, żeby model nie czekał na HTML.
     */
    public function prefetchProductSources(Product $product, bool $force = false, ?int $batchId = null, ?callable $onSearchReady = null): void
    {
        $started = microtime(true);
        $this->assertBatchNotCancelled($batchId);

        if (! $force && $this->hasSkuCacheRow($product)) {
            Log::info('Product source prefetch', [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'skipped' => 'sku_cache',
                'total_ms' => $this->elapsedMs($started),
            ]);
            $onSearchReady !== null && $onSearchReady();

            return;
        }

        $this->pages->bypassCache($force);
        try {
            if ($force) {
                $this->search->forgetProductCache($product);
            }

            $t = microtime(true);
            $searchPack = $this->search->searchBothPhases($product, true);
            $this->rememberPrefetchPack($product, $searchPack);
            $onSearchReady !== null && $onSearchReady();
            $searchMs = $this->elapsedMs($t);
            $results = $searchPack['results'] ?? [];

            $fetchMs = 0;
            if ($results !== []) {
                if ($this->manufacturers->domainsFor($product) === []) {
                    $this->manufacturers->discoverOfficialDomains($product);
                }
                $mfrDomains = $this->manufacturers->discoverFromResults(
                    $product,
                    array_column($results, 'url')
                );
                $descResults = $this->rankResultsForDescription($results, $product, $mfrDomains);
                $t = microtime(true);
                $this->pages->fetch($descResults, (string) $product->sku, 3, []);
                $mfrResults = $this->manufacturerSearchResults($results, $product, $mfrDomains);
                if ($mfrResults !== []) {
                    $this->pages->fetch($mfrResults, (string) $product->sku, 3, $mfrDomains);
                }
                $fetchMs = $this->elapsedMs($t);
            }

            Log::info('Product source prefetch', [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'search_ms' => $searchMs,
                'fetch_ms' => $fetchMs,
                'urls' => count($results),
                'total_ms' => $this->elapsedMs($started),
            ]);
        } finally {
            $this->pages->bypassCache(false);
        }
    }

    /**
     * Wyniki z prefetchu — bez drugiego round-tripu do SearXNG/Tavily.
     *
     * @return array{results: list<array<string, mixed>>, errors?: list<string>, images?: list<string>}
     */
    private function searchPackForEnrichment(Product $product): array
    {
        $pack = $this->prefetchPack($product);
        if (is_array($pack) && ($pack['results'] ?? []) !== []) {
            return $pack;
        }

        return $this->search->searchBothPhases($product);
    }

    /** @param  array<string, mixed>  $pack */
    private function rememberPrefetchPack(Product $product, array $pack): void
    {
        Cache::put($this->prefetchPackKey($product), $pack, now()->addHours(2));
    }

    /**
     * @return array{results: list<mixed>, errors?: list<string>}|null
     */
    private function prefetchPack(Product $product): ?array
    {
        $pack = Cache::get($this->prefetchPackKey($product));
        if (! is_array($pack) || ! array_key_exists('results', $pack) || ! is_array($pack['results'])) {
            return null;
        }

        return $pack;
    }

    private function prefetchPackKey(Product $product): string
    {
        return 'enrich_prefetch_pack:'.$product->id;
    }

    /**
     * Synchroniczne pobranie (1 produkt) — omija stare workery kolejki.
     */
    public function enrichProductSync(Product $product, User $user, bool $force = false): ProductEnrichmentBatch
    {
        if (! $force && $product->enrichment_status === Product::ENRICHMENT_DONE) {
            throw new RuntimeException('Produkt ma już pobrane dane. Użyj force=true, aby pobrać ponownie.');
        }

        $batch = ProductEnrichmentBatch::query()->create([
            'scope' => 'product',
            'scope_id' => $product->id,
            'total' => 1,
            'done' => 0,
            'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_RUNNING,
            'created_by' => $user->id,
            'force' => $force,
            'current_sku' => $product->sku,
            'current_name' => mb_substr($product->name, 0, 255),
            'message' => 'Pobieranie synchroniczne…',
        ]);

        Cache::forget($this->prefetchPackKey($product));

        try {
            $this->enrichProduct($product, $force);
            $this->markBatchItem($batch, true);
            $batch->refresh();
            $batch->update([
                'message' => 'Gotowe',
                'current_sku' => null,
                'current_name' => null,
            ]);
        } catch (Throwable $e) {
            $this->markBatchItem($batch, false);
            $batch->refresh();
            $batch->update([
                'message' => 'Błąd: '.mb_substr($e->getMessage(), 0, 200),
                'current_sku' => null,
                'current_name' => null,
            ]);
            throw $e;
        }

        return $batch->refresh();
    }

    public function enrichProduct(Product $product, bool $force = false, ?int $batchId = null): void
    {
        if (! $force && $product->enrichment_status === Product::ENRICHMENT_DONE) {
            return;
        }

        $this->assertBatchNotCancelled($batchId);

        $product->update([
            'enrichment_status' => Product::ENRICHMENT_RUNNING,
            'enrichment_error' => null,
        ]);

        $this->pages->bypassCache($force);
        $started = microtime(true);
        $timing = [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'from_cache' => false,
            'search_ms' => 0,
            'fetch_ms' => 0,
            'llm_sanitize_ms' => 0,
            'llm_extract_ms' => 0,
            'supplement_ms' => 0,
            'images_ms' => 0,
            'docs_ms' => 0,
        ];
        try {
            if (! $force && $this->applyFromSkuCache($product)) {
                $this->logEnrichmentTiming($timing, $started, extra: ['from_cache' => true]);

                return;
            }
            if ($force) {
                Cache::forget($this->prefetchPackKey($product));
                $this->search->forgetProductCache($product);
                $this->forgetSkuCache($product);
                $this->clearProductImages($product);
                $this->clearProductDocuments($product);
            }

            $this->assertBatchNotCancelled($batchId);
            $t = microtime(true);
            $searchPack = $this->searchPackForEnrichment($product);
            $searchResults = $searchPack['results'];
            // Tavily include_images WYŁĄCZONE — dawało piwo/LEGO/mapy zamiast produktu
            if ($searchResults === []) {
                $detail = ($searchPack['errors'] ?? []) !== []
                    ? implode(' | ', array_slice($searchPack['errors'], 0, 2))
                    : 'brak wyników';
                throw new ProductSourcesNotFoundException(
                    'Nie znaleziono stron z tym SKU w internecie. '.$detail
                );
            }

            // Opis/zdjęcia ← sklepy; PDF/certyfikaty ← producent (osobne ścieżki).
            if ($this->manufacturers->domainsFor($product) === []) {
                $this->manufacturers->discoverOfficialDomains($product);
            }
            $mfrDomains = $this->manufacturers->discoverFromResults(
                $product,
                array_column($searchResults, 'url')
            );
            $timing['search_ms'] = $this->elapsedMs($t);
            $descResults = $this->rankResultsForDescription($searchResults, $product, $mfrDomains);
            $t = microtime(true);
            $fetched = $this->pages->fetch($descResults, (string) $product->sku, 3, []);
            $pageSnippets = $fetched['pages'];
            if ($pageSnippets === []) {
                $pageSnippets = $this->fetchPageSnippets(array_slice($descResults, 0, 3));
            }

            // Certyfikaty + fakty techniczne: osobny fetch producenta (nie mieszać rankingu opisu).
            $mfrPageSnippets = [];
            $mfrResults = $this->manufacturerSearchResults($searchResults, $product, $mfrDomains);
            if ($mfrResults !== []) {
                $mfrFetched = $this->pages->fetch($mfrResults, (string) $product->sku, 3, $mfrDomains);
                foreach ($mfrFetched['document_urls'] as $url) {
                    $fetched['document_urls'][] = $url;
                }
                foreach ($mfrFetched['image_urls'] as $url) {
                    $fetched['image_urls'][] = $url;
                }
                foreach ($mfrFetched['trusted_image_urls'] as $url) {
                    $fetched['trusted_image_urls'][] = $url;
                }
                $mfrPageSnippets = $mfrFetched['pages'];
            }
            $timing['fetch_ms'] = $this->elapsedMs($t);

            $this->assertBatchNotCancelled($batchId);

            // sklep → opis PL; producent → normy/materiały — jedno sanitize, żeby nie dublować vLLM
            $t = microtime(true);
            $pageSnippets = $this->sanitizePagesWithLlm(
                $product,
                $this->mergePageSnippets($pageSnippets, $mfrPageSnippets)
            );
            $timing['llm_sanitize_ms'] = $this->elapsedMs($t);

            $t = microtime(true);
            $extracted = $this->extractWithLlm(
                $product,
                array_slice($descResults, 0, 4),
                array_slice($pageSnippets, 0, 5)
            );
            $extracted = $this->enrichStructuredFieldsFromPages($extracted, $pageSnippets);
            $timing['llm_extract_ms'] = $this->elapsedMs($t);

            $rawDescription = $this->composeFullDescription($extracted);
            $description = $rawDescription;
            if (! $this->isUsableProductDescription($description, $product)) {
                $description = '';
            }
            if ($description === '' || $this->looksLikeMissingCardMeta($description) || $this->looksLikeThinDescription($description)) {
                $fallback = $this->fallbackDescriptionFromPages($pageSnippets, (string) $product->sku);
                if ($fallback !== '' && ! $this->looksLikeThinDescription($fallback)) {
                    $description = $fallback;
                } elseif ($description === '' || $this->looksLikeMissingCardMeta($description)) {
                    $description = $fallback;
                }
            }

            // niepełny opis / puste listy → doszukaj na kolejnych sklepach
            if ($description === '' || $this->looksLikeMissingCardMeta($description) || $this->looksLikeThinDescription($description)
                || $this->looksLikeIncompleteDescription($description)
                || $this->looksLikeSparsePayload($extracted)) {
                $t = microtime(true);
                $supplement = $this->supplementDescriptionFromOtherSites(
                    $product,
                    $descResults,
                    $pageSnippets,
                    $extracted,
                    $description
                );
                $description = $supplement['description'];
                $extracted = $this->enrichStructuredFieldsFromPages(
                    $supplement['extracted'],
                    $supplement['pages']
                );
                $pageSnippets = $supplement['pages'];
                foreach ($supplement['image_urls'] as $url) {
                    $fetched['image_urls'][] = $url;
                }
                foreach ($supplement['trusted_image_urls'] as $url) {
                    $fetched['trusted_image_urls'][] = $url;
                }
                foreach ($supplement['document_urls'] as $url) {
                    $fetched['document_urls'][] = $url;
                }
                $timing['supplement_ms'] = $this->elapsedMs($t);
            }

            // Fallback ze stron i doszukiwanie omijają walidację LLM, więc tożsamość
            // sprawdzamy jeszcze raz na tekście, który realnie trafiłby do bazy.
            $confirmed = $description !== '' && $this->descriptionMentionsProduct($description, $product);

            if (! $confirmed || $this->looksLikeMissingCardMeta($description)) {
                Log::warning('Product description rejected', [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'pages' => array_slice(array_column($pageSnippets, 'url'), 0, 5),
                    'search_urls' => array_slice(array_column($descResults, 'url'), 0, 5),
                    'raw_length' => mb_strlen($rawDescription),
                    'raw_head' => mb_substr($rawDescription, 0, 300),
                    'mentions_product' => $rawDescription !== ''
                        && $this->descriptionMentionsProduct($rawDescription, $product),
                    'thin' => $rawDescription !== '' && $this->looksLikeThinDescription($rawDescription),
                    'card_meta' => $rawDescription !== '' && $this->looksLikeMissingCardMeta($rawDescription),
                    'final_head' => mb_substr($description, 0, 300),
                ]);

                throw new ProductSourcesNotFoundException(
                    'Nie znaleziono karty potwierdzającej produkt '.$product->sku
                    .' — żaden opis nie wymieniał jego kodu ani modelu. Opis wpisz ręcznie.'
                );
            }

            // Zdjęcia z kart produktu: pewne URL-e przechodzą po SKU, pozostałe ocenia AI Vision.
            // Tavily include_images pozostaje wyłączone — kandydat musi pochodzić z pobranej karty.
            $t = microtime(true);
            $imageUrls = $this->imageVerifier->select(
                $product,
                $fetched['image_urls'],
                $pageSnippets,
                3,
                $fetched['trusted_image_urls']
            );
            foreach ($extracted['image_urls'] ?? [] as $url) {
                if (is_string($url) && $this->identity->imageUrlMentionsProduct($url, $product)) {
                    $imageUrls[] = $url;
                }
            }
            $imageUrls = array_values(array_unique($imageUrls));

            $sourceUrls = [];
            foreach ($extracted['source_urls'] ?? [] as $url) {
                if (is_string($url) && str_starts_with($url, 'http')) {
                    $sourceUrls[] = $this->identity->preferredLocaleUrl($url, $product);
                }
            }
            if ($sourceUrls === []) {
                $sourceUrls = array_column(array_slice($pageSnippets, 0, 3), 'url');
            }
            if ($sourceUrls === []) {
                $sourceUrls = array_column(array_slice($descResults, 0, 3), 'url');
            }

            $extracted = $this->enrichStructuredFieldsFromPages($extracted, $pageSnippets, $description);

            $features = $this->stringList($extracted['features'] ?? null);
            $norms = $this->stringList($extracted['norms'] ?? null);
            $certificates = $this->stringList($extracted['certificates'] ?? null);
            $materials = $this->stringList($extracted['materials'] ?? null);
            $useCases = $this->stringList($extracted['use_cases'] ?? null);
            $specs = $this->stringList($extracted['specs'] ?? null);

            $attributes = $this->bhpAttributes->normalize(
                is_array($extracted['attributes'] ?? null) ? $extracted['attributes'] : null,
                [
                    'materials' => $materials,
                    'norms' => $norms,
                    'specs' => $specs,
                    'certificates' => $certificates,
                    'category' => (string) ($product->category ?? ''),
                    'sku' => (string) $product->sku,
                    'name' => (string) $product->name,
                    'norms_column' => (string) ($product->norms ?? ''),
                ]
            );

            $payload = [
                'features' => $features,
                'norms' => $norms,
                'certificates' => $certificates,
                'materials' => $materials,
                'use_cases' => $useCases,
                'specs' => $specs,
                'attributes' => $attributes,
                'source_urls' => array_values(array_unique($sourceUrls)),
                'confidence' => (float) ($extracted['confidence'] ?? 0),
                'from_cache' => false,
            ];

            // zaktualizuj też pole norms produktu, jeśli puste
            if (($product->norms === null || trim((string) $product->norms) === '') && $payload['norms'] !== []) {
                $product->norms = implode(', ', array_slice($payload['norms'], 0, 8));
                $product->save();
            }

            $primaryImageUrls = $this->pickPrimaryImageUrls(
                $imageUrls,
                [],
                (string) $product->sku,
                (string) $product->name,
                $product,
            );
            Log::info('Product image candidates prepared', [
                'product_id' => $product->id,
                'sku' => $product->sku,
                'fetched_count' => count($fetched['image_urls']),
                'verified_count' => count($imageUrls),
                'selected_count' => count($primaryImageUrls),
                'selected_urls' => $primaryImageUrls,
                'source_urls' => array_slice($sourceUrls, 0, 3),
            ]);
            $savedImages = $this->images->downloadMany($product, $primaryImageUrls, 1);
            if ($savedImages === [] && $sourceUrls !== []) {
                $retryPages = $this->pages->fetch(
                    array_map(
                        static fn (string $u): array => ['url' => $u, 'title' => '', 'snippet' => ''],
                        array_slice($sourceUrls, 0, 2)
                    ),
                    (string) $product->sku,
                    1,
                    $mfrDomains
                );
                $retryUrls = $this->imageVerifier->select(
                    $product,
                    $retryPages['image_urls'],
                    $retryPages['pages'],
                    3,
                    $retryPages['trusted_image_urls']
                );
                $savedImages = $this->images->downloadMany(
                    $product,
                    $this->pickPrimaryImageUrls(
                        $retryUrls,
                        [],
                        (string) $product->sku,
                        (string) $product->name,
                        $product,
                    ),
                    1
                );
            }
            // Ansell za Incapsulą: zrzut TYLKO gdy w ścieżce jest SKU i domena producenta
            if ($savedImages === [] && $this->needsManufacturerScreenshot($product)) {
                $shotPages = [];
                $skuNeedle = preg_replace('/\D+/', '', (string) $product->sku) ?? '';
                foreach (array_merge($sourceUrls, array_column($mfrResults, 'url')) as $u) {
                    if (! is_string($u) || ! $this->manufacturers->isManufacturerUrl($u, $product, $mfrDomains)) {
                        continue;
                    }
                    $path = mb_strtolower((string) (parse_url($u, PHP_URL_PATH) ?? ''));
                    if ($path === '' || str_contains($path, '/search') || str_contains($path, '/category')) {
                        continue;
                    }
                    if ($skuNeedle === '' || ! str_contains($path, $skuNeedle)) {
                        continue;
                    }
                    $shotPages[] = $u;
                }
                $shot = $this->images->downloadPageScreenshot($product, array_slice($shotPages, 0, 1), 0);
                if ($shot !== null) {
                    $savedImages = [$shot];
                }
            }
            if ($savedImages === []) {
                Log::warning('Product image download exhausted', [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'selected_urls' => $primaryImageUrls,
                    'search_urls' => array_slice(array_column($searchResults, 'url'), 0, 8),
                    'manufacturer_urls' => array_slice(array_column($mfrResults, 'url'), 0, 5),
                ]);
            }
            $timing['images_ms'] = $this->elapsedMs($t);
            $t = microtime(true);
            $documentUrls = [];
            foreach ($extracted['document_urls'] ?? [] as $url) {
                if (is_string($url) && ProductDocumentDownloader::looksLikeDocumentUrl($url)) {
                    $documentUrls[] = $url;
                }
            }
            foreach ($fetched['document_urls'] ?? [] as $url) {
                if (is_string($url)) {
                    $documentUrls[] = $url;
                }
            }
            foreach ($searchResults as $row) {
                $u = (string) ($row['url'] ?? '');
                if (ProductDocumentDownloader::looksLikeDocumentUrl($u)) {
                    $documentUrls[] = $u;
                }
            }
            $this->assertBatchNotCancelled($batchId);

            // PDF z karty (SKU w URL) wystarcza — nie odpalamy kolejnego SearXNG
            $docHits = [];
            $docPages = [];
            if (! $this->alreadyHasProductPdf($documentUrls, $product)) {
                $docHits = $this->documentFinder->findDocumentUrls($product);
            }
            foreach ($docHits as $url) {
                if (ProductDocumentDownloader::looksLikeDocumentUrl($url) && (
                    ProductDocumentDownloader::looksLikePdfUrl($url)
                    || preg_match('#/(pds|doc|ukdoc)(/|$)#i', $url) === 1
                    || str_ends_with(mb_strtolower((string) parse_url($url, PHP_URL_PATH)), '.ashx')
                )) {
                    $documentUrls[] = $url;
                } else {
                    $docPages[] = ['url' => $url, 'title' => '', 'snippet' => ''];
                }
            }
            if ($docPages !== []) {
                // indeksy deklaracji / karty producenta — bierz PDF ze strony
                $docFetched = $this->pages->fetch($docPages, (string) $product->sku, 3, $mfrDomains);
                foreach ($docFetched['document_urls'] ?? [] as $url) {
                    $documentUrls[] = $url;
                }
            }
            $mfrDomains = $this->manufacturers->discoverFromResults($product, array_merge(
                $documentUrls,
                array_column($docPages, 'url'),
                array_column($searchResults, 'url'),
            ));
            $preferredDocs = $this->preferManufacturerDocuments($documentUrls, $product, $mfrDomains);
            $savedDocs = $this->documents->downloadMany($product, $preferredDocs, 3);
            // Imperva na domenie producenta → PDF z CDN/dystrybutora (SKU w URL)
            if ($savedDocs === []) {
                $fallbackDocs = array_values(array_unique(array_filter(
                    $documentUrls,
                    static fn ($u): bool => is_string($u) && ProductDocumentDownloader::looksLikePdfUrl($u)
                )));
                if ($fallbackDocs !== $preferredDocs) {
                    $savedDocs = $this->documents->downloadMany($product, $fallbackDocs, 3);
                }
            }
            foreach ($savedDocs as $document) {
                if ($document->kind !== ProductDocument::KIND_CERTIFICATE) {
                    continue;
                }
                $label = str_contains(mb_strtolower((string) $document->source_url), '/doc/')
                    ? 'Deklaracja zgodności UE'
                    : 'Certyfikat producenta';
                $payload['certificates'][] = $label;
            }
            $payload['certificates'] = array_values(array_unique($payload['certificates']));
            $payload['document_urls'] = array_values(array_filter(array_map(
                static fn ($d): ?string => is_string($d->source_url) ? $d->source_url : null,
                $savedDocs
            )));

            $cachedImageUrls = array_values(array_filter(array_map(
                static fn ($img): ?string => is_string($img->source_url) ? $img->source_url : null,
                $savedImages
            )));

            $product->refresh();
            $product->update([
                'description' => mb_substr($description, 0, 10000),
                'enrichment_payload' => $payload,
                'enrichment_status' => Product::ENRICHMENT_DONE,
                'enriched_at' => now(),
                'enrichment_error' => $cachedImageUrls === []
                    ? 'Opis OK, nie udało się pobrać zdjęcia (źródła zwróciły błędne URL).'
                    : null,
            ]);

            ReindexProductEmbeddingJob::dispatch($product->id, true);

            $this->storeSkuCache(
                $product,
                $description,
                $payload,
                $cachedImageUrls, // tylko realnie pobrane — nie cache'uj 404
                $sourceUrls
            );
            $timing['docs_ms'] = $this->elapsedMs($t);
            $this->logEnrichmentTiming($timing, $started);
        } catch (Throwable $e) {
            $this->logEnrichmentTiming($timing, $started, $e->getMessage());
            Log::warning('Product enrichment failed', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
            try {
                $product->update([
                    'enrichment_status' => $this->enrichmentStatusForFailure($e),
                    'enrichment_error' => mb_substr($e->getMessage(), 0, 2000),
                ]);
            } catch (Throwable) {
                // np. padnięte MySQL — status może zostać "running"; UI pozwala odblokować
            }
            throw $e;
        } finally {
            $this->pages->bypassCache(false);
        }
    }

    private function enrichmentStatusForFailure(Throwable $e): string
    {
        if (! $e instanceof ProductSourcesNotFoundException) {
            return Product::ENRICHMENT_FAILED;
        }
        $msg = mb_strtolower($e->getMessage());
        if (str_contains($msg, 'silniki zablokowane')
            || str_contains($msg, 'too many requests')
            || str_contains($msg, 'captcha')
            || str_contains($msg, 'bez fallbacku publicznego')) {
            return Product::ENRICHMENT_FAILED;
        }

        return Product::ENRICHMENT_MANUAL;
    }

    private function elapsedMs(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /**
     * @param  array<string, mixed>  $timing
     * @param  array<string, mixed>  $extra
     */
    private function logEnrichmentTiming(array $timing, float $started, ?string $error = null, array $extra = []): void
    {
        $payload = array_merge($timing, $extra, [
            'total_ms' => $this->elapsedMs($started),
        ]);
        if ($error !== null && $error !== '') {
            $payload['error'] = mb_substr($error, 0, 200);
        }
        Log::info('Product enrichment timing', $payload);
    }

    private function hasSkuCacheRow(Product $product): bool
    {
        $key = ProductEnrichmentCache::normalizeKey(
            (string) $product->manufacturer,
            (string) $product->sku
        );

        return ProductEnrichmentCache::query()
            ->where('manufacturer', $key['manufacturer'])
            ->where('sku', $key['sku'])
            ->exists();
    }

    private function forgetSkuCache(Product $product): void
    {
        $key = ProductEnrichmentCache::normalizeKey(
            (string) $product->manufacturer,
            (string) $product->sku
        );
        ProductEnrichmentCache::query()
            ->where('manufacturer', $key['manufacturer'])
            ->where('sku', $key['sku'])
            ->delete();
    }

    private function clearProductImages(Product $product): void
    {
        $product->loadMissing('images');
        foreach ($product->images as $image) {
            try {
                Storage::disk('public')->delete($image->path);
            } catch (Throwable) {
                // ignore missing file
            }
            $image->delete();
        }
    }

    private function clearProductDocuments(Product $product): void
    {
        $product->loadMissing('documents');
        foreach ($product->documents as $doc) {
            try {
                Storage::disk('public')->delete($doc->path);
            } catch (Throwable) {
                // ignore missing file
            }
            $doc->delete();
        }
    }

    private function applyFromSkuCache(Product $product): bool
    {
        $key = ProductEnrichmentCache::normalizeKey(
            (string) $product->manufacturer,
            (string) $product->sku
        );
        $cache = ProductEnrichmentCache::query()
            ->where('manufacturer', $key['manufacturer'])
            ->where('sku', $key['sku'])
            ->first();

        if ($cache === null) {
            return false;
        }

        $payload = is_array($cache->enrichment_payload) ? $cache->enrichment_payload : [];
        $payload['from_cache'] = true;
        $payload['attributes'] = $this->bhpAttributes->normalize(
            is_array($payload['attributes'] ?? null) ? $payload['attributes'] : null,
            [
                'materials' => $this->stringList($payload['materials'] ?? null),
                'norms' => $this->stringList($payload['norms'] ?? null),
                'specs' => $this->stringList($payload['specs'] ?? null),
                'certificates' => $this->stringList($payload['certificates'] ?? null),
                'category' => (string) ($product->category ?? ''),
                'sku' => (string) $product->sku,
                'name' => (string) $product->name,
                'norms_column' => (string) ($product->norms ?? ''),
            ]
        );

        $rawImageUrls = is_array($cache->image_urls) ? $cache->image_urls : [];
        $imageUrls = [];
        foreach ($rawImageUrls as $u) {
            if (! is_string($u) || ! ProductImageDownloader::looksLikeImageUrl($u)) {
                continue;
            }
            if (! $this->identity->isTrustedPageImageUrl($u, $product)) {
                continue;
            }
            $imageUrls[] = $u;
        }

        // Cache ma tylko URL karty HTML / śmieci — pełne pobranie po zdjęcie.
        if ($rawImageUrls !== [] && $imageUrls === []) {
            return false;
        }

        if ($imageUrls !== []) {
            $this->images->downloadMany($product, $imageUrls, 1);
        }
        $docUrls = is_array($payload['document_urls'] ?? null) ? $payload['document_urls'] : [];
        if ($docUrls !== []) {
            $this->documents->downloadMany(
                $product,
                array_values(array_filter($docUrls, static fn ($u): bool => is_string($u))),
                3
            );
        }

        $product->refresh();
        $product->update([
            'description' => mb_substr((string) $cache->description, 0, 10000),
            'enrichment_payload' => $payload,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
            'enrichment_error' => null,
        ]);

        ReindexProductEmbeddingJob::dispatch($product->id, true);

        Log::info('Product enrichment from SKU cache', [
            'product_id' => $product->id,
            'sku' => $product->sku,
        ]);

        return true;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $imageUrls
     * @param  list<string>  $sourceUrls
     */
    private function storeSkuCache(
        Product $product,
        string $description,
        array $payload,
        array $imageUrls,
        array $sourceUrls,
    ): void {
        $key = ProductEnrichmentCache::normalizeKey(
            (string) $product->manufacturer,
            (string) $product->sku
        );

        ProductEnrichmentCache::query()->updateOrCreate(
            $key,
            [
                'description' => mb_substr($description, 0, 10000),
                'enrichment_payload' => $payload,
                'image_urls' => array_values(array_unique(array_slice($imageUrls, 0, 1))),
                'source_urls' => array_values(array_unique(array_slice($sourceUrls, 0, 5))),
            ]
        );
    }

    /**
     * Kandydaci na zdjęcie (kolejność: najlepsze pierwsze). downloadMany próbuje aż się uda.
     *
     * @param  list<string>  $allUrls
     * @param  mixed  $llmUrls
     * @return list<string>
     */
    private function needsManufacturerScreenshot(Product $product): bool
    {
        $brand = mb_strtolower($this->identity->shortBrand((string) $product->manufacturer));

        // tylko marki z bot-wallem na CDN mediów (Ansell/Imperva)
        return $brand === 'ansell' || str_contains($brand, 'ansell');
    }

    private function pickPrimaryImageUrls(array $allUrls, mixed $llmUrls, string $sku, string $name, ?Product $product = null): array
    {
        $scored = [];
        $skuNorm = mb_strtolower(trim($sku));
        $nameBits = array_values(array_filter(preg_split('/[\s\-®™]+/u', mb_strtolower($name)) ?: [], static fn ($w): bool => mb_strlen($w) >= 4));

        $push = static function (string $url, int $bonus) use (&$scored): void {
            $scored[$url] = max($scored[$url] ?? 0, $bonus);
        };

        // najpierw URL z HTML karty — wiarygodniejsze niż zgadywanie LLM
        foreach ($allUrls as $url) {
            if (is_string($url) && str_starts_with($url, 'http')) {
                $push($url, 40);
            }
        }
        if (is_array($llmUrls)) {
            foreach ($llmUrls as $url) {
                if (is_string($url) && str_starts_with($url, 'http')) {
                    // LLM często zmyśla obce JPG — bez śladu SKU/modelu odrzuć
                    if ($product !== null && ! $this->identity->imageUrlMentionsProduct($url, $product)) {
                        continue;
                    }
                    $push($url, 15);
                }
            }
        }

        $skuTokens = [];
        if (preg_match('/\d{1,2}-\d{3}/', $skuNorm, $m)) {
            $skuTokens[] = $m[0];
            $skuTokens[] = str_replace('-', '', $m[0]);
        }

        $ranked = [];
        foreach ($scored as $url => $base) {
            if ($this->isJunkImageUrl($url) || ! ProductImageDownloader::looksLikeImageUrl($url)) {
                continue;
            }
            // Kandydaci przeszli już ProductImageCandidateVerifier (SKU / zaufane
            // strukturalne / AI Vision) — ponowny wymóg SKU w URL odrzucałby
            // prawidłowe CDN-y dystrybutorów (np. RS z kodem Y…).
            $u = mb_strtolower($url);
            // miniatury WP (-80x80 …)
            if (preg_match('/-(\d{2,4})x(\d{2,4})\.(jpe?g|png|webp)(\?|$)/i', $u, $wm)
                && (((int) $wm[1] < 400) || ((int) $wm[2] < 400))) {
                continue;
            }
            // inny kod art. w pliku (7-003 przy SKU 9-084)
            if ($skuTokens !== [] && preg_match_all('/\b(\d{1,2}-\d{3})\b/', $u, $foundCodes)) {
                $wrong = false;
                foreach ($foundCodes[1] as $found) {
                    $ok = in_array($found, $skuTokens, true)
                        || in_array(str_replace('-', '', $found), $skuTokens, true)
                        || str_contains($skuNorm, $found);
                    if (! $ok) {
                        $wrong = true;
                        break;
                    }
                }
                if ($wrong) {
                    continue;
                }
            }
            $score = $base;
            if ($skuNorm !== '' && str_contains($u, $skuNorm)) {
                $score += 100;
            }
            foreach ($skuTokens as $token) {
                if (str_contains($u, $token)) {
                    $score += 80;
                    break;
                }
            }
            foreach ($nameBits as $bit) {
                if (str_contains($u, $bit)) {
                    $score += 15;
                }
            }
            if (str_contains($u, 'glove') || str_contains($u, 'rekaw') || str_contains($u, 'maxi') || str_contains($u, 'ringers') || str_contains($u, 'ansell')) {
                $score += 25;
            }
            // Drupal/ATG PIM / uvex shop-media — prawdziwe pliki
            if (str_contains($u, 'pim/products') || str_contains($u, 'sites/default/files') || str_contains($u, 'media/catalog/product') || str_contains($u, 'shop-media')) {
                $score += 90;
            }
            if (str_contains($u, 'menu-') || str_contains($u, 'menue-') || str_contains($u, 'favicon')) {
                continue;
            }
            // typowe zmyślone ścieżki z LLM
            if (preg_match('#/images/products/#', $u) === 1 && ! str_contains($u, 'sites/default')) {
                $score -= 80;
            }
            if ($score >= 20) {
                $ranked[] = ['url' => $url, 'score' => $score];
            }
        }

        usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_values(array_map(
            static fn (array $row): string => $row['url'],
            array_slice($ranked, 0, 4)
        ));
    }

    /**
     * Opis: sklepy / dystrybutorzy najpierw; producent na końcu (cienkie karty EN).
     *
     * @param  list<array{url: string, title?: string, snippet?: string}>  $results
     * @param  list<string>  $mfrDomains
     * @return list<array{url: string, title?: string, snippet?: string}>
     */
    private function rankResultsForDescription(array $results, Product $product, array $mfrDomains = []): array
    {
        $retailers = $this->retailerHostList();

        usort($results, function (array $a, array $b) use ($product, $mfrDomains, $retailers): int {
            return $this->descriptionSourceScore((string) ($b['url'] ?? ''), $product, $mfrDomains, $retailers)
                <=> $this->descriptionSourceScore((string) ($a['url'] ?? ''), $product, $mfrDomains, $retailers);
        });

        return $results;
    }

    /**
     * @param  list<string>  $mfrDomains
     * @param  list<string>  $retailers
     */
    private function descriptionSourceScore(string $url, Product $product, array $mfrDomains, array $retailers): int
    {
        if ($url === '') {
            return -100;
        }
        if ($this->manufacturers->isManufacturerUrl($url, $product, $mfrDomains)) {
            return 0;
        }
        $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        foreach ($retailers as $retailer) {
            if ($host === $retailer || str_ends_with($host, '.'.$retailer)) {
                return 20;
            }
        }

        return 10;
    }

    /**
     * @param  list<array{url: string, title?: string, snippet?: string}>  $results
     * @param  list<string>  $mfrDomains
     * @return list<array{url: string, title?: string, snippet?: string}>
     */
    private function manufacturerSearchResults(array $results, Product $product, array $mfrDomains): array
    {
        $out = [];
        foreach ($results as $row) {
            $url = (string) ($row['url'] ?? '');
            if ($url === '') {
                continue;
            }
            // bezpośrednie PDF z SERP — do ścieżki dokumentów
            if (ProductDocumentDownloader::looksLikePdfUrl($url)
                || $this->manufacturers->isManufacturerUrl($url, $product, $mfrDomains)) {
                $out[] = $row;
            }
            if (count($out) >= 5) {
                break;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function retailerHostList(): array
    {
        $raw = config('enrichment.retailer_domains', []);
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $domain) {
            if (! is_string($domain)) {
                continue;
            }
            $h = mb_strtolower(trim(preg_replace('#^https?://#i', '', $domain) ?? $domain));
            $h = rtrim(explode('/', $h)[0] ?? $h, '/');
            $h = preg_replace('/^www\./', '', $h) ?? $h;
            if ($h !== '') {
                $out[] = $h;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Opis z karty producenta bywa krótki — dobierz treść ze sklepów / innych źródeł.
     *
     * @param  list<array{url: string, title?: string, snippet?: string}>  $searchResults
     * @param  list<array{url: string, text: string}>  $pageSnippets
     * @param  array<string, mixed>  $extracted
     * @return array{
     *     description: string,
     *     extracted: array<string, mixed>,
     *     pages: list<array{url: string, text: string}>,
     *     image_urls: list<string>,
     *     trusted_image_urls: list<string>,
     *     document_urls: list<string>
     * }
     */
    private function supplementDescriptionFromOtherSites(
        Product $product,
        array $searchResults,
        array $pageSnippets,
        array $extracted,
        string $description,
    ): array {
        $used = [];
        foreach ($pageSnippets as $page) {
            $u = mb_strtolower((string) ($page['url'] ?? ''));
            if ($u !== '') {
                $used[$u] = true;
            }
        }

        $extraResults = [];
        foreach ($searchResults as $row) {
            $u = mb_strtolower((string) ($row['url'] ?? ''));
            if ($u === '' || isset($used[$u])) {
                continue;
            }
            // uzupełnienie opisu wyłącznie ze sklepów — nie z karty producenta
            if (ProductImageDownloader::looksLikeImageUrl((string) ($row['url'] ?? ''))
                || $this->manufacturers->isManufacturerUrl((string) ($row['url'] ?? ''), $product)) {
                continue;
            }
            $extraResults[] = $row;
            if (count($extraResults) >= 4) {
                break;
            }
        }

        $imageUrls = [];
        $trustedImageUrls = [];
        $documentUrls = [];
        if ($extraResults !== []) {
            $extraFetched = $this->pages->fetch($extraResults, (string) $product->sku, 3, []);
            $extraPages = $this->sanitizePagesWithLlm($product, $extraFetched['pages']);
            foreach ($extraPages as $page) {
                $pageSnippets[] = $page;
            }
            foreach ($extraFetched['image_urls'] as $url) {
                $imageUrls[] = $url;
            }
            foreach ($extraFetched['trusted_image_urls'] as $url) {
                $trustedImageUrls[] = $url;
            }
            foreach ($extraFetched['document_urls'] as $url) {
                $documentUrls[] = $url;
            }

            $extraExtracted = $this->extractWithLlm(
                $product,
                array_slice($extraResults, 0, 4),
                array_slice($pageSnippets, -3)
            );
            $extraDesc = $this->composeFullDescription($extraExtracted);
            if (! $this->isUsableProductDescription($extraDesc, $product)) {
                $extraDesc = '';
            }
            if ($this->isRicherDescription($extraDesc, $description)) {
                $description = $extraDesc;
                $extracted = $this->mergeExtracted($extracted, $extraExtracted);
            } else {
                $extracted = $this->mergeExtracted($extracted, $extraExtracted);
            }
        }

        if ($description === '' || $this->looksLikeThinDescription($description) || $this->looksLikeIncompleteDescription($description)) {
            $fallback = $this->fallbackDescriptionFromPages($pageSnippets, (string) $product->sku);
            if ($this->isRicherDescription($fallback, $description)) {
                $description = $fallback;
            }
        }

        return [
            'description' => $description,
            'extracted' => $extracted,
            'pages' => $pageSnippets,
            'image_urls' => $imageUrls,
            'trusted_image_urls' => $trustedImageUrls,
            'document_urls' => $documentUrls,
        ];
    }

    private function isRicherDescription(string $candidate, string $current): bool
    {
        $candidate = trim($candidate);
        if ($candidate === '' || $this->looksLikeMissingCardMeta($candidate)) {
            return false;
        }
        if ($current === '' || $this->looksLikeMissingCardMeta($current) || $this->looksLikeThinDescription($current)) {
            return ! $this->looksLikeThinDescription($candidate) || mb_strlen($candidate) > mb_strlen($current) + 40;
        }
        if ($this->looksLikeThinDescription($candidate)) {
            return false;
        }

        return mb_strlen($candidate) >= mb_strlen($current) + 60;
    }

    /** Opis bez cech technicznych / norm — warto doszukać na innych stronach. */
    private function looksLikeIncompleteDescription(string $description): bool
    {
        $d = trim($description);
        if (mb_strlen($d) < 420) {
            return true;
        }
        $low = mb_strtolower($d);
        $hasTech = (bool) preg_match(
            '#(en\s*\d|iso\s*\d|norm|skór|skor|podeszw|podnosek|nitryl|lateks|kevlar|dyneema|bamboo|ochron|wodoodpor|antypośliz|src|hro|\bs1\b|\bs3\b|\bo1\b)#iu',
            $low
        );
        $hasPurpose = (bool) preg_match(
            '#(przeznacz|zastosow|branż|montaż|przemysł|warsztat|budown|logist|spożyw|chemicz|cięcie|przecię)#iu',
            $low
        );

        return ! $hasTech || ! $hasPurpose || substr_count($d, '.') < 3;
    }

    /**
     * @param  array<string, mixed>  $extracted
     */
    private function looksLikeSparsePayload(array $extracted): bool
    {
        $norms = $this->stringList($extracted['norms'] ?? null);
        $materials = $this->stringList($extracted['materials'] ?? null);
        $useCases = $this->stringList($extracted['use_cases'] ?? null);
        $features = $this->stringList($extracted['features'] ?? null);

        $filled = 0;
        foreach ([$norms, $materials, $useCases, $features] as $list) {
            if ($list !== []) {
                $filled++;
            }
        }

        return $filled < 2;
    }

    /**
     * @param  list<array{url: string, text: string}>  $primary
     * @param  list<array{url: string, text: string}>  $extra
     * @return list<array{url: string, text: string}>
     */
    private function mergePageSnippets(array $primary, array $extra): array
    {
        $seen = [];
        $out = [];
        foreach (array_merge($primary, $extra) as $page) {
            $url = mb_strtolower(trim((string) ($page['url'] ?? '')));
            $text = trim((string) ($page['text'] ?? ''));
            if ($url === '' || $text === '' || isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;
            $out[] = ['url' => (string) $page['url'], 'text' => $text];
        }

        return $out;
    }

    /**
     * Uzupełnij puste listy z dosłownych faktów w tekście stron / opisu (bez zmyślania).
     *
     * @param  array<string, mixed>  $extracted
     * @param  list<array{url: string, text: string}>  $pages
     * @return array<string, mixed>
     */
    private function enrichStructuredFieldsFromPages(array $extracted, array $pages, string $description = ''): array
    {
        $hay = $description;
        foreach ($pages as $page) {
            $hay .= "\n".(string) ($page['text'] ?? '');
        }
        $hay = trim($hay);
        if ($hay === '') {
            return $extracted;
        }

        $norms = $this->stringList($extracted['norms'] ?? null);
        if ($norms === []) {
            $extracted['norms'] = $this->extractNormsFromText($hay);
        }

        $materials = $this->stringList($extracted['materials'] ?? null);
        if ($materials === []) {
            $extracted['materials'] = $this->extractMaterialsFromText($hay);
        }

        $useCases = $this->stringList($extracted['use_cases'] ?? null);
        if ($useCases === []) {
            $extracted['use_cases'] = $this->extractUseCasesFromText($hay);
        }

        return $extracted;
    }

    /** @return list<string> */
    private function extractNormsFromText(string $text): array
    {
        $out = [];
        if (preg_match_all(
            '/\bEN(?:\s*ISO)?\s*\d{3,5}(?::\d{4})?(?:\s*[+\-]?\s*[A-Z0-9][A-Z0-9\sXx\/\-]{0,24})?/iu',
            $text,
            $m
        )) {
            foreach ($m[0] as $raw) {
                $norm = trim(preg_replace('/\s+/u', ' ', (string) $raw) ?? (string) $raw);
                $norm = rtrim($norm, '.,;:)');
                if (mb_strlen($norm) >= 6) {
                    $out[] = $norm;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /** @return list<string> */
    private function extractMaterialsFromText(string $text): array
    {
        $low = mb_strtolower($text);
        $map = [
            'wiskoza bambusowa' => ['wiskoza bambusowa', 'bamboo viscose', 'viscose bamboo'],
            'Dyneema' => ['dyneema'],
            'szkło' => ['szkło', 'glass fibre', 'glass fiber', 'włókno szklane'],
            'poliamid' => ['poliamid', 'polyamide', 'nylon'],
            'nitryl' => ['nitryl', 'nitrile'],
            'lateks' => ['lateks', 'latex'],
            'HPPE' => ['hppe'],
            'poliuretan' => ['poliuretan', 'polyurethane', 'pu coating'],
            'wysokowydajny winyl (HPV)' => ['hpv', 'high performance vinyl', 'wysokowydajny winyl'],
            'skóra' => ['skóra licowa', 'skóra bydlęca', 'full grain'],
        ];
        $out = [];
        foreach ($map as $label => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($low, $needle)) {
                    $out[] = $label;
                    break;
                }
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function extractUseCasesFromText(string $text): array
    {
        $low = mb_strtolower($text);
        $map = [
            'montaż precyzyjny' => ['montaż', 'assembly', 'precyzyj'],
            'przemysł metalowy' => ['metalurg', 'metalow', 'metal industry', 'blachar'],
            'budownictwo' => ['budown', 'construction', 'construction site'],
            'logistyka i magazyn' => ['logist', 'magazyn', 'warehouse', 'handling'],
            'przemysł szklarski' => ['szklar', 'glass industry'],
            'prace z ryzykiem przecięcia' => ['przecię', 'cut resist', 'cięcie', 'cut protection'],
            'warunki suche' => ['warunki suche', 'dry conditions', 'dry environments'],
        ];
        $out = [];
        foreach ($map as $label => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($low, $needle)) {
                    $out[] = $label;
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function mergeExtracted(array $base, array $extra): array
    {
        foreach (['features', 'specs', 'norms', 'certificates', 'materials', 'use_cases', 'image_urls', 'document_urls', 'source_urls'] as $key) {
            $a = $this->stringList($base[$key] ?? null);
            $b = $this->stringList($extra[$key] ?? null);
            $base[$key] = array_values(array_unique(array_merge($a, $b)));
        }
        if (is_array($extra['attributes'] ?? null)) {
            $base['attributes'] = $this->bhpAttributes->normalize(
                array_merge(
                    is_array($base['attributes'] ?? null) ? $base['attributes'] : [],
                    $extra['attributes']
                ),
                [
                    'materials' => $this->stringList($base['materials'] ?? null),
                    'norms' => $this->stringList($base['norms'] ?? null),
                    'specs' => $this->stringList($base['specs'] ?? null),
                    'certificates' => $this->stringList($base['certificates'] ?? null),
                ]
            );
        }
        if ((float) ($extra['confidence'] ?? 0) > (float) ($base['confidence'] ?? 0)) {
            $base['confidence'] = $extra['confidence'];
        }

        return $base;
    }

    /**
     * PDF z domeny producenta lub CDN z kodem SKU w nazwie (np. uvex → cloudfront …/60028_….pdf).
     *
     * @param  list<string>  $urls
     * @param  list<string>  $domains
     * @return list<string>
     */
    private function preferManufacturerDocuments(array $urls, Product $product, array $domains): array
    {
        $urls = array_values(array_unique(array_filter($urls, static fn ($u): bool => is_string($u) && str_starts_with($u, 'http'))));
        $mfr = [];
        $skuHit = [];
        $otherPdf = [];
        foreach ($urls as $url) {
            if ($this->manufacturers->isManufacturerUrl($url, $product, $domains)) {
                $mfr[] = $url;
            } elseif ($this->pdfUrlMentionsProduct($url, $product)) {
                $skuHit[] = $url;
            } elseif (ProductDocumentDownloader::looksLikePdfUrl($url)) {
                $otherPdf[] = $url;
            }
        }

        // producent → PDF z SKU w URL → dopiero potem pozostałe PDF (sklep/dystrybutor)
        if ($mfr !== []) {
            return $mfr;
        }
        if ($skuHit !== []) {
            return $skuHit;
        }

        return $otherPdf;
    }

    /**
     * @param  list<string>  $documentUrls
     */
    private function alreadyHasProductPdf(array $documentUrls, Product $product): bool
    {
        foreach ($documentUrls as $url) {
            if (is_string($url) && $this->pdfUrlMentionsProduct($url, $product)) {
                return true;
            }
        }

        return false;
    }

    private function pdfUrlMentionsProduct(string $url, Product $product): bool
    {
        if (! ProductDocumentDownloader::looksLikePdfUrl($url)) {
            return false;
        }
        $hay = mb_strtolower(urldecode($url));
        $sku = mb_strtolower(trim((string) $product->sku));
        // dokładny kod albo prefiks (uvex: SKU 60549 w URL …6054907…)
        if ($sku !== '' && (
            preg_match('/(?<![0-9])'.preg_quote($sku, '/').'(?![0-9])/u', $hay)
            || (preg_match('/^\d{4,}$/', $sku) === 1 && preg_match('/(?<![0-9])'.preg_quote($sku, '/').'\d*/u', $hay))
        )) {
            return true;
        }
        // uvex / ATG: art. 60549 często jako 60549_ / 60549- w nazwie pliku
        if ($sku !== '' && preg_match('/^\d{4,}$/', $sku)
            && (str_contains($hay, $sku.'_') || str_contains($hay, $sku.'-') || str_contains($hay, '/'.$sku.'.'))) {
            return true;
        }
        $name = mb_strtolower(trim((string) $product->name));
        if ($name !== '' && preg_match('/\b(\d{1,2}-\d{3})\b/', $sku.' '.$name, $m)) {
            return (bool) preg_match('/(?<![0-9])'.preg_quote($m[1], '/').'(?![0-9])/u', $hay);
        }
        // nazwa handlowa w URL (np. c300-dry, c300dry)
        $tokens = preg_split('/\s+/u', $name) ?: [];
        $nameSlug = implode('-', array_filter(array_map(
            static fn (string $t): string => (string) preg_replace('/[^a-z0-9]+/i', '', mb_strtolower($t)),
            array_slice($tokens, 0, 3)
        )));
        if ($nameSlug !== '' && mb_strlen($nameSlug) >= 5 && str_contains($hay, $nameSlug)) {
            return true;
        }
        $compact = preg_replace('/[^a-z0-9]+/i', '', $name) ?? '';
        $hayCompact = preg_replace('/[^a-z0-9]+/i', '', $hay) ?? '';

        return $compact !== '' && mb_strlen($compact) >= 6 && str_contains($hayCompact, $compact);
    }

    private function looksLikeMissingCardMeta(string $description): bool
    {
        $d = mb_strtolower($description);

        return str_contains($d, 'nie znaleziono')
            || str_contains($d, 'nie udało się znaleźć')
            || str_contains($d, 'brak szczegółowej karty')
            || str_contains($d, 'na podstawie samej nazwy')
            || str_contains($d, 'wyniki wyszukiwania wskazują');
    }

    /** Krótki slogan sklepu / og:description zamiast pełnego opisu technicznego. */
    private function looksLikeThinDescription(string $description): bool
    {
        $d = trim($description);
        if (mb_strlen($d) < 180) {
            return true;
        }
        $low = mb_strtolower($d);
        if ($this->looksLikeShopChromeDescription($d) || $this->looksLikeOffTopicDescription($d)
            || $this->looksLikeLinkDump($d)) {
            return true;
        }

        return str_contains($low, 'sprawdź na')
            || str_contains($low, 'kup online')
            || str_contains($low, 'ceny i opinie')
            || (substr_count($d, '.') <= 1 && mb_strlen($d) < 280);
    }

    /** Menu sklepu przepisane jako lista odnośników („Popular Styles”), nie opis. */
    private function looksLikeLinkDump(string $description): bool
    {
        $links = preg_match_all('#\]\(https?://#i', $description);
        if ($links !== false && $links >= 2) {
            return true;
        }

        $urls = preg_match_all('#https?://#i', $description);

        return $urls !== false && $urls >= 3;
    }

    /** Skopiowany chrome sklepu zamiast opisu produktu. */
    private function looksLikeShopChromeDescription(string $description): bool
    {
        $low = mb_strtolower($description);
        $hits = 0;
        foreach ([
            'logowanie', 'rejestracja', 'do koszyka', 'obserwowane', 'realizuj zamówienie',
            'polityka prywatności', 'łatwy zwrot', 'jesteś tutaj', 'wyszukiwanie zaawansowane',
            'odstąpienie od umowy', 'kup za punkty', 'sprawdź status zamówienia',
        ] as $needle) {
            if (str_contains($low, $needle)) {
                $hits++;
            }
        }

        return $hits >= 2;
    }

    private function looksLikeOffTopicDescription(string $description): bool
    {
        $low = mb_strtolower($description);
        foreach ([
            'real estate', 'nieruchomoś', 'leasing opportunity', 'investment or leasing',
            'office, industrial or commercial', 'multi-family housing',
            'powierzchni biurow', 'wynajmu nieruchomości', 'cushman', 'colliers', 'cbre',
        ] as $needle) {
            if (str_contains($low, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isUsableProductDescription(string $description, Product $product): bool
    {
        $d = trim($description);
        if ($d === '' || $this->looksLikeMissingCardMeta($d) || $this->looksLikeThinDescription($d)) {
            return false;
        }

        return $this->descriptionMentionsProduct($d, $product);
    }

    /**
     * Opis wolno przypisać dopiero wtedy, gdy sam nazywa produkt po kodzie albo modelu.
     * Bez tego karta obcego produktu z tej samej branży przechodziła jako nasza.
     */
    private function descriptionMentionsProduct(string $description, Product $product): bool
    {
        $hay = mb_strtolower($description);

        if ($this->identity->hayHasProductCode($hay, $product)) {
            return true;
        }

        $tokens = $this->discriminativeNameTokens($product);
        $score = 0;
        foreach ($tokens as $token => $weight) {
            if ($this->hayHasToken($hay, (string) $token)) {
                $score += $weight;
            }
        }
        // Sama marka nie wystarcza — pod „Urgent” idzie pół katalogu — ale razem
        // ze słowem z nazwy domyka potwierdzenie.
        $brand = mb_strtolower($this->identity->shortBrand((string) $product->manufacturer));
        if ($score > 0 && $brand !== '' && $this->hayHasToken($hay, $brand)) {
            $score++;
        }
        if ($score >= 2) {
            return true;
        }

        // Nazwa z samych słów ogólnych („Rękawice robocze”) nie da się potwierdzić kodem —
        // wtedy o zgodności decyduje marka razem z rodzajem środka ochrony.
        if ($tokens === []) {
            return $this->matchesBrandAndFamily($hay, $product);
        }

        return false;
    }

    /**
     * Słowa z nazwy, które faktycznie odróżniają model: numer serii i oznaczenia z cyfrą
     * ważą podwójnie, zwykłe słowo pojedynczo, a branżowe ogólniki wcale.
     *
     * @return array<string, int>
     */
    private function discriminativeNameTokens(Product $product): array
    {
        $tokens = [];
        foreach (preg_split('/[\s\-®™\/_,.()]+/u', mb_strtolower((string) $product->name)) ?: [] as $token) {
            $token = trim($token);
            if ($token === '' || in_array($token, self::GENERIC_NAME_TOKENS, true)) {
                continue;
            }
            if (preg_match('/^\d{2,}$/u', $token) === 1
                || preg_match('/^(?=.*\d)(?=.*\p{L})[\p{L}\d]{3,}$/u', $token) === 1) {
                $tokens[$token] = 2;
            } elseif (preg_match('/^\p{L}{4,}$/u', $token) === 1) {
                $tokens[$token] = 1;
            }
        }

        return $tokens;
    }

    private function matchesBrandAndFamily(string $hay, Product $product): bool
    {
        $brand = mb_strtolower($this->identity->shortBrand((string) $product->manufacturer));
        if ($brand === '' || ! $this->hayHasToken($hay, $brand)) {
            return false;
        }

        $family = $this->assortment->family(
            trim($product->name.' '.$product->sku.' '.(string) $product->category)
        );

        return $family === null || $family === $this->assortment->family($hay);
    }

    private function hayHasToken(string $hay, string $token): bool
    {
        $token = mb_strtolower(trim($token));
        if (mb_strlen($token) < 2) {
            return false;
        }

        return preg_match('/(^|[^\p{L}\d])'.preg_quote($token, '/').'([^\p{L}\d]|$)/iu', $hay) === 1;
    }

    /**
     * @param  list<array{url: string, text: string}>  $pageSnippets
     */
    private function fallbackDescriptionFromPages(array $pageSnippets, string $sku): string
    {
        foreach ($pageSnippets as $page) {
            $text = trim((string) ($page['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            // pomiń sam og:description — szukaj dłuższego akapitu
            $parts = preg_split('/\n{2,}/u', $text) ?: [$text];
            foreach ($parts as $part) {
                $part = trim((string) $part);
                if (mb_strlen($part) >= 180 && ! $this->looksLikeMissingCardMeta($part) && ! $this->looksLikeThinDescription($part)) {
                    return mb_substr($part, 0, 2000);
                }
            }
            $flat = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
            if (mb_strlen($flat) >= 220 && ! $this->looksLikeThinDescription($flat)) {
                return mb_substr($flat, 0, 2000);
            }
        }

        return '';
    }

    private function isJunkImageUrl(string $url): bool
    {
        $u = mb_strtolower($url);
        $blocked = [
            'logo', 'icon', 'sprite', 'favicon', 'banner', 'payment',
            'dhl', 'inpost', 'poczta', 'ups', 'fedex', 'dpd', 'gls',
            'cart', 'koszyk', 'wallet', 'payu', 'przelewy', 'blik',
            'ochronki na buty', 'shoe-cover', 'shoe_cover', 'nakladki', 'folie-na',
            'placeholder', 'blank', 'pixel', 'bg_environment', 'environment_oily', '.svg',
            'loader', 'spinner', 'loading', 'preloader', 'ajax-loader', 'load.gif',
            'loading.gif', 'loader-1', 'loader-2', 'progress.gif',
            'menue-', 'menu-', '/01_menue', 'menue-pics', 'world-map', 'sitemap',
            'beer', 'fox-deluxe', 'sustainability_report', 'lego',
        ];
        foreach ($blocked as $needle) {
            if (str_contains($u, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function markBatchItem(ProductEnrichmentBatch $batch, bool $success): void
    {
        if ($batch->status === ProductEnrichmentBatch::STATUS_CANCELLED || $batch->isCancelled()) {
            return;
        }

        if ($success) {
            $batch->increment('done');
        } else {
            $batch->increment('failed');
        }
        $batch->refresh();
        $batch->refreshStatus();
    }

    /**
     * Batch zostaje na running, gdy job wrócił przy statusie manual/done bez zliczenia.
     * Zamykamy tylko stare partie bez jobów w kolejce — świeżo zlecone jeszcze nie mają wierszy.
     */
    public function finalizeIfJobsGone(ProductEnrichmentBatch $batch): ProductEnrichmentBatch
    {
        if (! in_array($batch->status, [
            ProductEnrichmentBatch::STATUS_QUEUED,
            ProductEnrichmentBatch::STATUS_RUNNING,
        ], true)) {
            return $batch;
        }

        $processed = $batch->done + $batch->failed;
        if ($processed >= $batch->total && $batch->total > 0) {
            $batch->refreshStatus();

            return $batch->refresh();
        }

        // Job enrichmentu trwa do ~7 min (timeout 420 s + slot). 90 s zamykało
        // batch w trakcie pobierania i UI gubił SKU / pasek.
        $staleAfter = now()->subMinutes(12);
        if ($batch->updated_at !== null && $batch->updated_at->gt($staleAfter)) {
            return $batch;
        }

        if ($this->batchHasPendingJobs((int) $batch->id)) {
            return $batch;
        }

        if (Product::query()->whereIn('enrichment_status', [
            Product::ENRICHMENT_RUNNING,
            Product::ENRICHMENT_QUEUED,
        ])->exists()) {
            return $batch;
        }

        $left = max(0, $batch->total - $processed);
        if ($left > 0) {
            $batch->increment('done', $left);
            $batch->refresh();
        }

        $batch->update([
            'message' => "OK {$batch->done} · pominięto już obsłużone (ręcznie/gotowe)",
            'current_sku' => null,
            'current_name' => null,
        ]);
        $batch->refreshStatus();

        return $batch->refresh();
    }

    public function batchHasPendingJobs(int $batchId): bool
    {
        if (! Schema::hasTable('jobs')) {
            return false;
        }

        return $this->jobsPayloadQuery($batchId)->exists();
    }

    /**
     * Payload w tabeli jobs to JSON — cudzysłowy w serializacji PHP są jako \".
     * Szukanie dosłownego s:7:"batchId" nic nie znajdowało i UI znikał po 90 s.
     */
    private function jobsPayloadQuery(int $batchId): Builder
    {
        $id = (string) $batchId;

        return DB::table('jobs')->where(function ($q) use ($id): void {
            $q->where('payload', 'like', '%batchId%;i:'.$id.';%')
                ->orWhere('payload', 'like', '%batchId%:'.$id.'%');
        });
    }

    /**
     * Natychmiastowe zatrzymanie batcha: flaga + usunięcie oczekujących jobów z kolejki.
     *
     * @return array{batch: ProductEnrichmentBatch, removed_jobs: int, marked_products: int}
     */
    public function cancelBatch(ProductEnrichmentBatch $batch): array
    {
        $batch->markCancelledFlag();

        $removedJobs = 0;
        $markedProducts = 0;

        if (Schema::hasTable('jobs')) {
            $rows = $this->jobsPayloadQuery((int) $batch->id)
                ->orderBy('id')
                ->get(['id', 'payload', 'reserved_at']);

            foreach ($rows as $row) {
                $productId = $this->productIdFromJobPayload((string) $row->payload);
                if ($productId !== null && $row->reserved_at === null) {
                    $updated = Product::query()
                        ->where('id', $productId)
                        ->whereIn('enrichment_status', [
                            Product::ENRICHMENT_QUEUED,
                            Product::ENRICHMENT_RUNNING,
                            Product::ENRICHMENT_FAILED,
                        ])
                        ->where('enrichment_status', '!=', Product::ENRICHMENT_DONE)
                        ->update([
                            'enrichment_status' => Product::ENRICHMENT_FAILED,
                            'enrichment_error' => 'Anulowano przez użytkownika',
                        ]);
                    $markedProducts += (int) $updated;
                }

                DB::table('jobs')->where('id', $row->id)->delete();
                $removedJobs++;
            }
        }

        $batch->refresh();
        $processed = $batch->done + $batch->failed;
        $remaining = max(0, $batch->total - $processed);
        if ($remaining > 0) {
            $batch->increment('failed', $remaining);
            $batch->refresh();
        }

        $batch->update([
            'status' => ProductEnrichmentBatch::STATUS_CANCELLED,
            'message' => 'Anulowano · OK '.$batch->done.' / usunięto z kolejki '.$removedJobs,
            'current_sku' => null,
            'current_name' => null,
        ]);

        return [
            'batch' => $batch->refresh(),
            'removed_jobs' => $removedJobs,
            'marked_products' => $markedProducts,
        ];
    }

    /**
     * Zatrzymuje WSZYSTKIE pobierania opisów — nie tylko chowa batch z listy.
     *
     * @return array{removed_jobs: int, marked_products: int, cancelled_batches: int}
     */
    public function stopAllEnrichment(): array
    {
        $removedJobs = 0;
        if (Schema::hasTable('jobs')) {
            $ids = DB::table('jobs')
                ->where(function ($q): void {
                    $q->where('payload', 'like', '%EnrichProductJob%')
                        ->orWhere('payload', 'like', '%PrefetchProductSourcesJob%');
                })
                ->pluck('id');
            foreach ($ids as $id) {
                DB::table('jobs')->where('id', $id)->delete();
                $removedJobs++;
            }
        }

        ProductEnrichmentBatch::haltAllWorkers();
        foreach (ProductEnrichmentBatch::query()
            ->where('updated_at', '>', now()->subDay())
            ->pluck('id') as $batchId) {
            Cache::put(ProductEnrichmentBatch::cancelCacheKey((int) $batchId), true, now()->addDay());
        }

        $markedProducts = Product::query()
            ->whereIn('enrichment_status', [
                Product::ENRICHMENT_QUEUED,
                Product::ENRICHMENT_RUNNING,
            ])
            ->update([
                'enrichment_status' => Product::ENRICHMENT_FAILED,
                'enrichment_error' => 'Zatrzymano wszystkie pobierania opisów',
            ]);

        $cancelledBatches = 0;
        $open = ProductEnrichmentBatch::query()
            ->whereIn('status', [
                ProductEnrichmentBatch::STATUS_QUEUED,
                ProductEnrichmentBatch::STATUS_RUNNING,
            ])
            ->get();
        foreach ($open as $batch) {
            $processed = $batch->done + $batch->failed;
            $remaining = max(0, $batch->total - $processed);
            if ($remaining > 0) {
                $batch->increment('failed', $remaining);
                $batch->refresh();
            }
            $batch->update([
                'status' => ProductEnrichmentBatch::STATUS_CANCELLED,
                'message' => 'Zatrzymano wszystko · usunięto jobów '.$removedJobs,
                'current_sku' => null,
                'current_name' => null,
            ]);
            $cancelledBatches++;
        }

        return [
            'removed_jobs' => $removedJobs,
            'marked_products' => (int) $markedProducts,
            'cancelled_batches' => $cancelledBatches,
        ];
    }

    public function enrichmentProductCounts(): array
    {
        return [
            'queued_products' => Product::query()
                ->where('enrichment_status', Product::ENRICHMENT_QUEUED)
                ->count(),
            'running_products' => Product::query()
                ->where('enrichment_status', Product::ENRICHMENT_RUNNING)
                ->count(),
        ];
    }

    public function assertBatchNotCancelled(?int $batchId): void
    {
        if ($batchId === null || $batchId <= 0) {
            return;
        }

        $batch = ProductEnrichmentBatch::query()->find($batchId);
        if ($batch !== null && $batch->isCancelled()) {
            throw new EnrichmentCancelledException('Enrichment anulowany przez użytkownika.');
        }
    }

    private function productIdFromJobPayload(string $payload): ?int
    {
        $plain = str_replace('\\', '', $payload);
        if (preg_match('/productId";i:(\d+);/', $plain, $m) === 1) {
            return (int) $m[1];
        }

        return null;
    }

    /**
     * Wstępna analiza AI: z surowej treści strony zostawia tylko fakty o produkcie.
     *
     * @param  list<array{url: string, text: string}>  $pageSnippets
     * @return list<array{url: string, text: string}>
     */
    private function sanitizePagesWithLlm(Product $product, array $pageSnippets): array
    {
        if ($pageSnippets === []) {
            return [];
        }
        $pageSnippets = $this->dropBinaryPageSnippets($pageSnippets);
        if ($pageSnippets === []) {
            return [];
        }

        $pageCount = count($pageSnippets);
        $compact = $pageCount > 4
            ? $this->fitPagesToBudget($pageSnippets, 6, 3000, 14000)
            : $this->fitPagesToBudget($pageSnippets, 4, 3800, 12000);
        if ($compact === []) {
            return $pageSnippets;
        }

        $pagesJson = json_encode($compact, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            $parsed = $this->llm->chatJsonEnrichment([
                [
                    'role' => 'system',
                    'content' => <<<'SYS'
Jesteś filtrem treści produktu BHP. Dostajesz surowy tekst ze stron sklepów.
Zadanie: WSTĘPNA ANALIZA — wyrzuć śmieci sklepowe, zostaw wyłącznie informacje o produkcie.

WYRZUĆ całkowicie: logowanie, rejestracja, konto, obserwowane, koszyk, suma, zamówienie, menu kategorii, breadcrumby („jesteś tutaj”), wyszukiwanie, telefon/e-mail sklepu, wysyłka, koszty dostawy, płatności, prowizje, regulamin, polityka prywatności, odstąpienie od umowy, zwroty 14 dni, punkty lojalnościowe, porównanie, cookies, ceny marketingowe bez kontekstu produktu.

ZOSTAW / przepisz zwięźle: nazwa modelu, producent, kod/SKU, normy (S3, SRC, EN ISO…), materiały, podnosek, podeszwa, cholewka, przeznaczenie, cechy techniczne, kolory/rozmiary jeśli produktowe.
Nie cytuj całych akapitów ze strony i nie powtarzaj tego samego faktu.

JĘZYK: źródła bywają po francusku, niemiecku, czesku czy angielsku. ZAWSZE tłumacz fakty na polski.
Nigdy nie przepisuj zdań w języku oryginału — nazwy własne modeli i oznaczenia norm zostaw bez zmian.

Zwróć TYLKO JSON — bez pola thought/reasoning. Pierwszy znak to {.
{"pages":[{"url":"…","text":"oczyszczone fakty po polsku, 4–10 zdań: parametry, materiały, normy, przeznaczenie"}]}
Jeśli na stronie nie ma faktów o produkcie → "text":"". Nie zmyślaj cech.
Jeśli nazwa to PPE (obuwie, rękawice, odzież…), a tekst dotyczy odczynnika / numeru CAS / wzoru chemicznego — "text":"".
SYS,
                ],
                [
                    'role' => 'user',
                    'content' => "SKU: {$product->sku}\nProducent: {$product->manufacturer}\nNazwa: {$product->name}\n\nStrony:\n{$pagesJson}",
                ],
            ], 0.0, 4000);
        } catch (Throwable $e) {
            Log::info('AI page sanitize failed, using heuristic text', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);

            return $pageSnippets;
        }

        $byUrl = [];
        foreach ($parsed['pages'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $url = (string) ($row['url'] ?? '');
            $text = trim((string) ($row['text'] ?? ''));
            if ($url === '' || $text === '' || $this->looksLikeShopChromeDescription($text)
                || $this->looksLikeOffTopicDescription($text)) {
                continue;
            }
            $byUrl[mb_strtolower($url)] = ['url' => $url, 'text' => mb_substr($text, 0, 3500)];
        }

        $cleaned = [];
        foreach ($compact as $orig) {
            $key = mb_strtolower((string) ($orig['url'] ?? ''));
            if ($key !== '' && isset($byUrl[$key])) {
                $cleaned[] = $byUrl[$key];
            }
        }
        if ($cleaned === [] && $byUrl !== []) {
            $cleaned = array_values($byUrl);
        }

        return $cleaned !== [] ? $cleaned : $pageSnippets;
    }

    /**
     * @param  list<array{url: string, text: string}>  $pages
     * @return list<array{url: string, text: string}>
     */
    private function dropBinaryPageSnippets(array $pages): array
    {
        $out = [];
        foreach ($pages as $page) {
            $url = (string) ($page['url'] ?? '');
            $text = (string) ($page['text'] ?? '');
            if (ProductImageDownloader::looksLikeImageUrl($url) || ProductPageFetcher::looksLikeBinaryMedia($text)) {
                continue;
            }
            $out[] = $page;
        }

        return $out;
    }

    /**
     * llama.cpp dzieli kontekst na równoległe sloty, więc jeden prompt ma do dyspozycji
     * ułamek okna modelu. Bez twardego budżetu kilka dłuższych kart daje HTTP 400.
     *
     * @param  list<array{url: string, text: string}>  $pages
     * @return list<array{url: string, text: string}>
     */
    private function fitPagesToBudget(array $pages, int $maxPages, int $perPage, int $total): array
    {
        $out = [];
        $left = $total;
        foreach (array_slice($pages, 0, $maxPages) as $page) {
            if ($left <= 0) {
                break;
            }
            $text = trim((string) ($page['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $text = mb_substr($text, 0, min($perPage, $left));
            $left -= mb_strlen($text);
            $out[] = ['url' => (string) ($page['url'] ?? ''), 'text' => $text];
        }

        return $out;
    }

    /**
     * @param  list<array{url: string, title: string, snippet: string}>  $searchResults
     * @param  list<array{url: string, text: string}>  $pageSnippets
     * @return array<string, mixed>
     */
    private function extractWithLlm(Product $product, array $searchResults, array $pageSnippets): array
    {
        $compactPages = $this->fitPagesToBudget($pageSnippets, 5, 3000, 11500);
        $compactSources = array_map(static function (array $r): array {
            return [
                'url' => mb_substr((string) ($r['url'] ?? ''), 0, 300),
                'title' => mb_substr((string) ($r['title'] ?? ''), 0, 150),
                'snippet' => mb_substr((string) ($r['snippet'] ?? ''), 0, 200),
            ];
        }, array_slice($searchResults, 0, 8));

        $sourcesJson = json_encode($compactSources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pagesJson = json_encode($compactPages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->llm->chatJsonEnrichment([
            [
                'role' => 'system',
                'content' => <<<'SYS'
Jesteś ekspertem BHP/PPE. Wejście to OCZYSZCZONE fakty o produkcie (bez chrome sklepu). Zbierz PEŁNĄ specyfikację jak na karcie katalogowej.
Zwróć WYŁĄCZNIE JSON — bez pola thought/reasoning/thinking. Zacznij od {"description":
{
  "description": "pełny opis PL: 1) przeznaczenie 2) budowa/materiały 3) właściwości użytkowe 4) normy/certyfikaty 5) zastosowania — min. 6–12 zdań",
  "features": ["cechy i korzyści — min. 5 pozycji, gdy źródła na to pozwalają"],
  "specs": ["parametr: wartość (nr art./SKU, typ, materiał wkładki, powłoka, opakowanie, rozmiary…)"],
  "norms": ["EN … z poziomami, jeśli podane w źródłach", "EN ISO …"],
  "certificates": ["certyfikaty, kat. PPE, CE"],
  "materials": ["materiały / powłoki"],
  "use_cases": ["zastosowania / branże / warunki pracy"],
  "attributes": {
    "kategoria_bhp": "rekawice|obuwie|odziez|ochrona_glowy|ochrona_twarzy|ochrona_oczu|ochrona_sluchu|drogi_oddechowe|asekuracja|ochrona_kolan|inne",
    "kod_producenta": "SKU / nr katalogowy producenta",
    "material": "główny materiał (np. nitryl)",
    "materialy": ["lista materiałów"],
    "normy_en": ["EN 388", "EN ISO 20345"],
    "klasa_ochrony": "S3 / kat. II / …",
    "rozmiar": "np. 9 lub 42-46 albo null",
    "poziomy_en388": "np. 4544C albo null"
  },
  "image_urls": ["https://… tylko realny URL zdjęcia produktu"],
  "document_urls": ["https://… tylko realny URL PDF karty/certyfikatu"],
  "source_urls": ["https://… karty produktu"],
  "confidence": 0.0
}
JĘZYK: cały tekst wyjściowy po polsku, także gdy źródła są francuskie, niemieckie, czeskie czy angielskie.
Bez zdań w języku oryginału i bez etykiet typu „Produit”, „Matériaux”, „Usage” — tłumacz je na polskie odpowiedniki.
WYPEŁNIJ tablice features/specs/norms/materials/use_cases oraz attributes, gdy fakty są w tekście — nie zostawiaj ich pustych „dla skrótu”.
Nie powtarzaj tych samych zdań w description, features i specs — description zostaje pełny (6–12 zdań).
attributes: używaj wyłącznie wartości ze źródeł; brak danych → null / [].
Nie zmyślaj URL ani kodów EN spoza źródeł. Brak opisu → description="" i confidence=0.
Pomiń reklamy, nieruchomości, leasing, biura, inwestycje i inny tekst niezwiązany z tym produktem BHP.
Jeśli źródła opisują substancję chemiczną / CAS, a nazwa produktu to PPE (obuwie, rękawice, odzież…) — description="" i confidence=0.
SYS,
            ],
            [
                'role' => 'user',
                'content' => "SKU: {$product->sku}\nProducent: {$product->manufacturer}\nNazwa: {$product->name}\nEAN: ".($product->ean ?? '—')
                    ."\n\nWyniki wyszukiwania:\n{$sourcesJson}\n\nStrony (po filtrze AI):\n{$pagesJson}",
            ],
        ], 0.1, 4500);
    }

    /**
     * Sam akapit opisowy — listy (spec/cechy/normy…) idą do enrichment_payload i UI.
     *
     * @param  array<string, mixed>  $extracted
     */
    private function composeFullDescription(array $extracted): string
    {
        $main = trim((string) ($extracted['description'] ?? ''));
        // Odciąć ewentualne listy doklejone przez LLM (duplikat z payload).
        if (preg_match('/\n\n(?:Specyfikacja|Cechy|Materiały|Normy|Certyfikaty|Zastosowanie)\s*:/u', $main, $m, PREG_OFFSET_CAPTURE)) {
            $main = trim(mb_substr($main, 0, (int) $m[0][1]));
        }

        return $main;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn ($v): bool => is_string($v) && trim($v) !== ''
        ));
    }

    /**
     * @param  list<array{url: string, title: string, snippet: string}>  $results
     * @return list<array{url: string, text: string}>
     */
    private function fetchPageSnippets(array $results): array
    {
        $out = [];
        foreach ($results as $row) {
            $url = $row['url'];
            if (ProductImageDownloader::looksLikeImageUrl($url)) {
                continue;
            }
            $snippet = trim($row['snippet'] ?? '');
            if ($snippet !== '') {
                $out[] = ['url' => $url, 'text' => mb_substr($snippet, 0, 1200)];

                continue;
            }

            // HTML tylko gdy brak snippetu Tavily — i krótko
            try {
                $response = Http::timeout(12)
                    ->withHeaders(['User-Agent' => 'SUPON-ProductEnrichment/1.0'])
                    ->get($url);
                if (! $response->successful()) {
                    continue;
                }
                $html = $response->body();
                $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
                $out[] = ['url' => $url, 'text' => mb_substr(trim($text), 0, 1200)];
            } catch (Throwable) {
                continue;
            }
        }

        return $out;
    }
}
