<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Jobs\EnrichProductJob;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Models\ProductEnrichmentCache;
use App\Models\User;
use App\Services\Ai\OpenAiCompatibleClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class ProductEnrichmentService
{
    public function __construct(
        private readonly HybridWebSearchService $search,
        private readonly ProductImageDownloader $images,
        private readonly ProductPageFetcher $pages,
        private readonly OpenAiCompatibleClient $llm,
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
        $productIds = $query->pluck('id')->map(static fn ($id): int => (int) $id)->all();

        if ($productIds === []) {
            throw new RuntimeException('Brak produktów do wzbogacenia (wszystkie już mają dane).');
        }

        $batch = ProductEnrichmentBatch::query()->create([
            'scope' => $scope,
            'scope_id' => $scopeId > 0 ? $scopeId : ($user->id ?: 0),
            'total' => count($productIds),
            'done' => 0,
            'failed' => 0,
            'status' => ProductEnrichmentBatch::STATUS_QUEUED,
            'created_by' => $user->id,
            'force' => $force,
            'message' => 'W kolejce: '.count($productIds).' produktów',
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

    public function enrichProduct(Product $product, bool $force = false): void
    {
        if (! $force && $product->enrichment_status === Product::ENRICHMENT_DONE) {
            return;
        }

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
            }

            $searchPack = $this->search->searchBothPhases($product);
            $searchResults = $searchPack['results'];
            if ($searchResults === []) {
                $detail = $searchPack['errors'] !== []
                    ? implode(' | ', array_slice($searchPack['errors'], 0, 2))
                    : 'brak wyników';
                throw new RuntimeException(
                    'Nie znaleziono źródeł. '.$detail
                    .' (Ustawienia AI: dodaj klucz Tavily — to główne, tanie źródło.)'
                );
            }

            $fetched = $this->pages->fetch($searchResults, (string) $product->sku, 3);
            $pageSnippets = $fetched['pages'];
            if ($pageSnippets === []) {
                $pageSnippets = $this->fetchPageSnippets(array_slice($searchResults, 0, 3));
            }

            $extracted = $this->extractWithLlm($product, array_slice($searchResults, 0, 5), $pageSnippets);

            $description = $this->composeFullDescription($extracted);
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
                $sourceUrls = array_column(array_slice($searchResults, 0, 3), 'url');
            }

            $payload = [
                'features' => $this->stringList($extracted['features'] ?? null),
                'norms' => $this->stringList($extracted['norms'] ?? null),
                'certificates' => $this->stringList($extracted['certificates'] ?? null),
                'materials' => $this->stringList($extracted['materials'] ?? null),
                'use_cases' => $this->stringList($extracted['use_cases'] ?? null),
                'specs' => $this->stringList($extracted['specs'] ?? null),
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
            $savedImages = $this->images->downloadMany($product, $primaryImageUrls, 1);
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
                'enrichment_error' => null,
            ]);

            $this->storeSkuCache(
                $product,
                $description,
                $payload,
                $cachedImageUrls !== [] ? $cachedImageUrls : array_slice($primaryImageUrls, 0, 1),
                $sourceUrls
            );
        } catch (Throwable $e) {
            Log::warning('Product enrichment failed', [
                'product_id' => $product->id,
                'error' => $e->getMessage(),
            ]);
            $product->update([
                'enrichment_status' => Product::ENRICHMENT_FAILED,
                'enrichment_error' => mb_substr($e->getMessage(), 0, 2000),
            ]);
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

        $imageUrls = is_array($cache->image_urls) ? $cache->image_urls : [];
        $this->images->downloadMany($product, array_values(array_filter(
            $imageUrls,
            static fn ($u): bool => is_string($u) && str_starts_with($u, 'http')
        )), 1);

        $product->refresh();
        $product->update([
            'description' => mb_substr((string) $cache->description, 0, 10000),
            'enrichment_payload' => $payload,
            'enrichment_status' => Product::ENRICHMENT_DONE,
            'enriched_at' => now(),
            'enrichment_error' => null,
        ]);

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
     * Jedno zdjęcie — preferuj URL ze SKU / nazwą produktu, odrzuć śmieci.
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

        if (is_array($llmUrls)) {
            foreach ($llmUrls as $url) {
                if (is_string($url) && str_starts_with($url, 'http')) {
                    $push($url, 20);
                }
            }
        }
        foreach ($allUrls as $url) {
            if (is_string($url) && str_starts_with($url, 'http')) {
                $push($url, 10);
            }
        }

        $ranked = [];
        foreach ($scored as $url => $base) {
            if ($this->isJunkImageUrl($url)) {
                continue;
            }
            $u = mb_strtolower($url);
            $score = $base;
            if ($skuNorm !== '' && str_contains($u, $skuNorm)) {
                $score += 100;
            }
            foreach ($nameBits as $bit) {
                if (str_contains($u, $bit)) {
                    $score += 15;
                }
            }
            if (str_contains($u, 'glove') || str_contains($u, 'rekaw') || str_contains($u, 'maxi')) {
                $score += 25;
            }
            $ranked[] = ['url' => $url, 'score' => $score];
        }

        usort($ranked, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        if ($ranked === []) {
            return [];
        }

        // próg niższy: og:image z karty SKU ma ~45; śmieci i tak odrzucone wcześniej
        if ($ranked[0]['score'] < 20) {
            return [];
        }

        return [$ranked[0]['url']];
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

    private function isJunkImageUrl(string $url): bool
    {
        $u = mb_strtolower($url);
        $blocked = [
            'logo', 'icon', 'sprite', 'favicon', 'banner', 'payment',
            'dhl', 'inpost', 'poczta', 'ups', 'fedex', 'dpd', 'gls',
            'cart', 'koszyk', 'wallet', 'payu', 'przelewy', 'blik',
            'shoe', 'buty', 'ochronki', 'ochraniacz', 'bachior', 'bootie',
            'cover', 'nakladki', 'folie-na', 'placeholder', 'blank', 'pixel',
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
        if ($success) {
            $batch->increment('done');
        } else {
            $batch->increment('failed');
        }
        $batch->refresh();
        $batch->refreshStatus();
    }

    /**
     * @param  list<array{url: string, title: string, snippet: string}>  $searchResults
     * @param  list<array{url: string, text: string}>  $pageSnippets
     * @return array<string, mixed>
     */
    private function extractWithLlm(Product $product, array $searchResults, array $pageSnippets): array
    {
        $sourcesJson = json_encode($searchResults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $pagesJson = json_encode($pageSnippets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->llm->chatJson([
            [
                'role' => 'system',
                'content' => <<<'SYS'
Jesteś ekspertem BHP/PPE. Z treści kart produktu zbierz PEŁNĄ specyfikację.
Zwróć WYŁĄCZNIE JSON:
{
  "description": "pełny opis PL: 1) przeznaczenie 2) budowa/materiały 3) właściwości użytkowe 4) normy/certyfikaty 5) zastosowania — min. 6-12 zdań lub akapity",
  "features": ["cechy i korzyści"],
  "specs": ["parametr: wartość (rozmiary, długość, grubość, powłoka, klasa itd.)"],
  "norms": ["EN ...", "EN ISO ..."],
  "certificates": ["certyfikaty, kat. PPE, oznaczenia CE"],
  "materials": ["materiały / powłoki"],
  "use_cases": ["zastosowania / branże"],
  "image_urls": ["https://... JEDNO najlepsze zdjęcie produktu (nie logo, nie kurier)"],
  "source_urls": ["https://... karty produktu, nie kategorie"],
  "confidence": 0.0
}
Nie zmyślaj. Jeśli brak danych — pusta tablica.
Używaj WYŁĄCZNIE źródeł, które zawierają kod SKU produktu.
NIE pisz meta-komentarzy typu „nie znaleziono karty” / „na podstawie samej nazwy” — wtedy ustaw description="" i confidence=0.
Preferuj datasheet producenta (np. atggloves.com) i karty sklepowe z SKU.
SYS,
            ],
            [
                'role' => 'user',
                'content' => "SKU: {$product->sku}\nProducent: {$product->manufacturer}\nNazwa: {$product->name}\nEAN: ".($product->ean ?? '—')
                    ."\n\nWyniki wyszukiwania:\n{$sourcesJson}\n\nTreść stron:\n{$pagesJson}",
            ],
        ], 0.1);
    }

    /**
     * @param  array<string, mixed>  $extracted
     */
    private function composeFullDescription(array $extracted): string
    {
        $parts = [];
        $main = trim((string) ($extracted['description'] ?? ''));
        if ($main !== '') {
            $parts[] = $main;
        }

        $blocks = [
            'Specyfikacja' => $this->stringList($extracted['specs'] ?? null),
            'Cechy' => $this->stringList($extracted['features'] ?? null),
            'Materiały' => $this->stringList($extracted['materials'] ?? null),
            'Normy' => $this->stringList($extracted['norms'] ?? null),
            'Certyfikaty' => $this->stringList($extracted['certificates'] ?? null),
            'Zastosowanie' => $this->stringList($extracted['use_cases'] ?? null),
        ];

        foreach ($blocks as $title => $items) {
            if ($items === []) {
                continue;
            }
            $parts[] = $title.":\n- ".implode("\n- ", $items);
        }

        return trim(implode("\n\n", $parts));
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
