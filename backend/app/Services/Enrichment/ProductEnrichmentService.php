<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Exceptions\EnrichmentCancelledException;
use App\Jobs\EnrichProductJob;
use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductEnrichmentCache;
use App\Models\User;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Support\BhpAttributeNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class ProductEnrichmentService
{
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
    ) {}

    /**
     * @return ProductEnrichmentBatch
     */
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

        EnrichProductJob::dispatch($product->id, $batch->id, $force);

        return $batch;
    }

    public function enqueuePriceList(PriceList $priceList, User $user, bool $force = false): ProductEnrichmentBatch
    {
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
        );
    }

    /**
     * @param  list<int>  $ids
     */
    public function enqueueProductIds(
        array $ids,
        User $user,
        bool $force = false,
        string $scope = ProductEnrichmentBatch::SCOPE_PRODUCTS,
        int $scopeId = 0,
    ): ProductEnrichmentBatch {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            throw new RuntimeException('Brak produktów do wzbogacenia.');
        }

        $query = Product::query()->whereIn('id', $ids);
        if (! $force) {
            $query->where('enrichment_status', '!=', Product::ENRICHMENT_DONE);
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

        foreach ($productIds as $productId) {
            EnrichProductJob::dispatch($productId, $batch->id, $force);
        }

        return $batch;
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

        try {
            if (! $force && $this->applyFromSkuCache($product)) {
                return;
            }
            if ($force) {
                $this->forgetSkuCache($product);
                $this->clearProductImages($product);
                $this->clearProductDocuments($product);
            }

            $this->assertBatchNotCancelled($batchId);
            $searchPack = $this->search->searchBothPhases($product);
            $searchResults = $searchPack['results'];
            $searchImages = [];
            foreach ($searchPack['images'] ?? [] as $url) {
                if (is_string($url) && str_starts_with($url, 'http')) {
                    $searchImages[] = $url;
                }
            }
            if ($searchResults === []) {
                $detail = $searchPack['errors'] !== []
                    ? implode(' | ', array_slice($searchPack['errors'], 0, 2))
                    : 'brak wyników';
                throw new RuntimeException(
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
            $descResults = $this->rankResultsForDescription($searchResults, $product, $mfrDomains);
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
                $mfrPageSnippets = $mfrFetched['pages'];
            }

            $this->assertBatchNotCancelled($batchId);

            // sklep → opis PL; producent → normy/materiały (doklejone do kontekstu LLM)
            $pageSnippets = $this->sanitizePagesWithLlm($product, $pageSnippets);
            if ($mfrPageSnippets !== []) {
                $mfrClean = $this->sanitizePagesWithLlm($product, $mfrPageSnippets);
                $pageSnippets = $this->mergePageSnippets($pageSnippets, $mfrClean);
            }

            $extracted = $this->extractWithLlm(
                $product,
                array_slice($descResults, 0, 4),
                array_slice($pageSnippets, 0, 5)
            );
            $extracted = $this->enrichStructuredFieldsFromPages($extracted, $pageSnippets);

            $description = $this->composeFullDescription($extracted);
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
                foreach ($supplement['document_urls'] as $url) {
                    $fetched['document_urls'][] = $url;
                }
            }

            if ($description === '' || $this->looksLikeMissingCardMeta($description)) {
                throw new RuntimeException(
                    'Nie udało się zebrać pełnego opisu ze stron zawierających SKU '.$product->sku.'.'
                );
            }

            $imageUrls = [];
            foreach ($extracted['image_urls'] ?? [] as $url) {
                if (is_string($url) && str_starts_with($url, 'http')) {
                    $imageUrls[] = $url;
                }
            }
            foreach ($fetched['image_urls'] as $url) {
                $imageUrls[] = $url;
            }
            // Ansell/Imperva: HTML = bot-wall → zdjęcia z Tavily include_images
            foreach ($searchImages as $url) {
                $imageUrls[] = $url;
            }

            $sourceUrls = [];
            foreach ($extracted['source_urls'] ?? [] as $url) {
                if (is_string($url) && str_starts_with($url, 'http')) {
                    $sourceUrls[] = $url;
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
                $extracted['image_urls'] ?? [],
                (string) $product->sku,
                (string) $product->name,
            );
            // kilka kandydatów — LLM często zmyśla JPG (404), prawdziwe są w HTML strony
            $savedImages = $this->images->downloadMany($product, $primaryImageUrls, 1);
            if ($savedImages === [] && $sourceUrls !== []) {
                $retryPages = $this->pages->fetch(
                    array_map(
                        static fn (string $u): array => ['url' => $u, 'title' => '', 'snippet' => ''],
                        array_slice($sourceUrls, 0, 2)
                    ),
                    (string) $product->sku,
                    1,
                    []
                );
                $savedImages = $this->images->downloadMany(
                    $product,
                    $this->pickPrimaryImageUrls(
                        $retryPages['image_urls'],
                        [],
                        (string) $product->sku,
                        (string) $product->name,
                    ),
                    1
                );
            }
            if ($savedImages === [] && $searchImages !== []) {
                $savedImages = $this->images->downloadMany(
                    $product,
                    $this->pickPrimaryImageUrls(
                        $searchImages,
                        [],
                        (string) $product->sku,
                        (string) $product->name,
                    ),
                    1
                );
            }
            $documentUrls = [];
            foreach ($extracted['document_urls'] ?? [] as $url) {
                if (is_string($url) && ProductDocumentDownloader::looksLikePdfUrl($url)) {
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
                if (ProductDocumentDownloader::looksLikePdfUrl($u)) {
                    $documentUrls[] = $u;
                }
            }
            $this->assertBatchNotCancelled($batchId);

            // PDF tylko ze stron producenta (+ indeksy deklaracji)
            $docHits = $this->documentFinder->findDocumentUrls($product);
            $docPages = [];
            foreach ($docHits as $url) {
                if (ProductDocumentDownloader::looksLikePdfUrl($url)) {
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
        } catch (Throwable $e) {
            Log::warning('Product enrichment failed', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
            try {
                $product->update([
                    'enrichment_status' => Product::ENRICHMENT_FAILED,
                    'enrichment_error' => mb_substr($e->getMessage(), 0, 2000),
                ]);
            } catch (Throwable) {
                // np. padnięte MySQL — status może zostać "running"; UI pozwala odblokować
            }
            throw $e;
        }
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
        $imageUrls = array_values(array_filter(
            $rawImageUrls,
            static fn ($u): bool => is_string($u) && ProductImageDownloader::looksLikeImageUrl($u)
        ));

        // Cache ma tylko URL karty HTML zamiast pliku — pełne pobranie po zdjęcie.
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
    private function pickPrimaryImageUrls(array $allUrls, mixed $llmUrls, string $sku, string $name): array
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
            if ($this->manufacturers->isManufacturerUrl((string) ($row['url'] ?? ''), $product)) {
                continue;
            }
            $extraResults[] = $row;
            if (count($extraResults) >= 4) {
                break;
            }
        }

        $imageUrls = [];
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
            foreach ($extraFetched['document_urls'] as $url) {
                $documentUrls[] = $url;
            }

            $extraExtracted = $this->extractWithLlm(
                $product,
                array_slice($extraResults, 0, 4),
                array_slice($pageSnippets, -3)
            );
            $extraDesc = $this->composeFullDescription($extraExtracted);
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
        if ($this->looksLikeShopChromeDescription($d)) {
            return true;
        }

        return str_contains($low, 'sprawdź na')
            || str_contains($low, 'kup online')
            || str_contains($low, 'ceny i opinie')
            || (substr_count($d, '.') <= 1 && mb_strlen($d) < 280);
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
     * Natychmiastowe zatrzymanie batcha: flaga + usunięcie oczekujących jobów z kolejki.
     *
     * @return array{batch: ProductEnrichmentBatch, removed_jobs: int, marked_products: int}
     */
    public function cancelBatch(ProductEnrichmentBatch $batch): array
    {
        if (in_array($batch->status, [
            ProductEnrichmentBatch::STATUS_DONE,
            ProductEnrichmentBatch::STATUS_FAILED,
            ProductEnrichmentBatch::STATUS_CANCELLED,
        ], true)) {
            throw new RuntimeException('Ten batch jest już zakończony.');
        }

        $batch->markCancelledFlag();

        $removedJobs = 0;
        $markedProducts = 0;

        if (Schema::hasTable('jobs')) {
            $needle = 's:7:"batchId";i:'.(int) $batch->id.';';
            $rows = DB::table('jobs')
                ->where('payload', 'like', '%'.$needle.'%')
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

    public function assertBatchNotCancelled(?int $batchId): void
    {
        if ($batchId === null || $batchId <= 0) {
            return;
        }

        if (cache()->has(ProductEnrichmentBatch::cancelCacheKey($batchId))) {
            throw new EnrichmentCancelledException('Enrichment anulowany przez użytkownika.');
        }

        $status = ProductEnrichmentBatch::query()->whereKey($batchId)->value('status');
        if ($status === ProductEnrichmentBatch::STATUS_CANCELLED) {
            throw new EnrichmentCancelledException('Enrichment anulowany przez użytkownika.');
        }
    }

    private function productIdFromJobPayload(string $payload): ?int
    {
        if (preg_match('/s:9:"productId";i:(\d+);/', $payload, $m) === 1) {
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

        $compact = array_map(static function (array $p): array {
            return [
                'url' => (string) ($p['url'] ?? ''),
                'text' => mb_substr((string) ($p['text'] ?? ''), 0, 4500),
            ];
        }, array_slice($pageSnippets, 0, 4));

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

Zwróć TYLKO JSON:
{"pages":[{"url":"…","text":"oczyszczone fakty o produkcie po polsku, 2–12 zdań lub punktów"}]}
Jeśli na stronie nie ma faktów o produkcie → "text":"". Nie zmyślaj cech.
SYS,
                ],
                [
                    'role' => 'user',
                    'content' => "SKU: {$product->sku}\nProducent: {$product->manufacturer}\nNazwa: {$product->name}\n\nStrony:\n{$pagesJson}",
                ],
            ], 0.0, 1800);
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
            if ($url === '' || $text === '' || $this->looksLikeShopChromeDescription($text)) {
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
     * @param  list<array{url: string, title: string, snippet: string}>  $searchResults
     * @param  list<array{url: string, text: string}>  $pageSnippets
     * @return array<string, mixed>
     */
    private function extractWithLlm(Product $product, array $searchResults, array $pageSnippets): array
    {
        $compactPages = array_map(static function (array $p): array {
            return [
                'url' => $p['url'] ?? '',
                'text' => mb_substr((string) ($p['text'] ?? ''), 0, 3500),
            ];
        }, $pageSnippets);

        $sourcesJson = json_encode($searchResults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pagesJson = json_encode($compactPages, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->llm->chatJsonEnrichment([
            [
                'role' => 'system',
                'content' => <<<'SYS'
Jesteś ekspertem BHP/PPE. Wejście to OCZYSZCZONE fakty o produkcie (bez chrome sklepu). Zbierz PEŁNĄ specyfikację jak na karcie katalogowej.
Zwróć WYŁĄCZNIE JSON:
{
  "description": "pełny opis PL: 1) przeznaczenie 2) budowa/materiały 3) właściwości użytkowe 4) normy/certyfikaty 5) zastosowania — min. 6–12 zdań",
  "features": ["cechy i korzyści — min. 5 pozycji, gdy źródła na to pozwalają"],
  "specs": ["parametr: wartość (nr art./SKU, typ, materiał wkładki, powłoka, opakowanie, rozmiary…)"],
  "norms": ["EN … z poziomami, jeśli podane w źródłach", "EN ISO …"],
  "certificates": ["certyfikaty, kat. PPE, CE"],
  "materials": ["materiały / powłoki"],
  "use_cases": ["zastosowania / branże / warunki pracy"],
  "attributes": {
    "kategoria_bhp": "rekawice|obuwie|odziez|ochrona_glowy|inne",
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
WYPEŁNIJ tablice features/specs/norms/materials/use_cases oraz attributes, gdy fakty są w tekście — nie zostawiaj ich pustych „dla skrótu”.
attributes: używaj wyłącznie wartości ze źródeł; brak danych → null / [].
Nie zmyślaj URL ani kodów EN spoza źródeł. Brak opisu → description="" i confidence=0.
SYS,
            ],
            [
                'role' => 'user',
                'content' => "SKU: {$product->sku}\nProducent: {$product->manufacturer}\nNazwa: {$product->name}\nEAN: ".($product->ean ?? '—')
                    ."\n\nWyniki wyszukiwania:\n{$sourcesJson}\n\nStrony (po filtrze AI):\n{$pagesJson}",
            ],
        ], 0.1, 3500);
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
