<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\EnrichmentCancelledException;
use App\Http\Controllers\Controller;
use App\Jobs\EnrichProductJob;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Services\Ai\AiSettingsService;
use App\Services\Enrichment\EnrichmentSlots;
use App\Services\Enrichment\ProductEnrichmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class ProductEnrichmentController extends Controller
{
    public function __construct(
        private readonly ProductEnrichmentService $enrichment,
        private readonly AiSettingsService $aiSettings,
        private readonly EnrichmentSlots $slots,
    ) {}

    public function limits(): JsonResponse
    {
        return response()->json([
            'enrichment_batch_limit' => $this->aiSettings->enrichmentBatchLimit(),
            'match_concurrency' => $this->aiSettings->matchConcurrency(),
            'catalog_search_limit' => $this->aiSettings->catalogSearchLimit(),
        ]);
    }

    public function enrichProduct(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'force' => ['sometimes', 'boolean'],
        ]);

        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        try {
            // Jedna sztuka — synchronicznie (bez ryzyka starego queue:work)
            $batch = $this->enrichment->enrichProductSync(
                $product,
                $request->user(),
                (bool) ($data['force'] ?? false),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $product->refresh()->load([
            'images',
            'documents',
            'substitutes.substituteProduct:id,sku,name,manufacturer,catalog_price_net',
            'substitutes.approver:id,name',
        ]);

        $payload = $product->toArray();
        $payload['images'] = $product->images->map(static fn ($img): array => [
            'id' => $img->id,
            'url' => $img->url(),
            'source_url' => $img->source_url,
            'is_primary' => $img->is_primary,
            'sort_order' => $img->sort_order,
        ])->values()->all();
        $payload['documents'] = $product->documents->map(static fn ($doc): array => [
            'id' => $doc->id,
            'url' => $doc->url(),
            'source_url' => $doc->source_url,
            'title' => $doc->title,
            'kind' => $doc->kind,
            'size_bytes' => $doc->size_bytes,
            'sort_order' => $doc->sort_order,
        ])->values()->all();

        return response()->json([
            'batch' => $this->batchPayload($batch),
            'product_id' => $product->id,
            'product' => $payload,
            'images_count' => count($payload['images']),
            'documents_count' => count($payload['documents']),
        ]);
    }

    public function enrichPriceList(Request $request, PriceList $priceList): JsonResponse
    {
        $data = $request->validate([
            'force' => ['sometimes', 'boolean'],
        ]);

        try {
            $queued = $this->enrichment->enqueuePriceList(
                $priceList,
                $request->user(),
                (bool) ($data['force'] ?? false),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'batch' => $this->batchPayload($queued['batch']),
            'product_ids' => $queued['product_ids'],
            'price_list_id' => $priceList->id,
        ], 202);
    }

    public function enrichProducts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:'.$this->aiSettings->enrichmentBatchLimit()],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'force' => ['sometimes', 'boolean'],
        ]);

        try {
            $queued = $this->enrichment->enqueueProductIds(
                array_map('intval', $data['product_ids']),
                $request->user(),
                (bool) ($data['force'] ?? false),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'batch' => $this->batchPayload($queued['batch']),
            'product_ids' => $queued['product_ids'],
        ], 202);
    }

    public function activeBatches(): JsonResponse
    {
        $batches = ProductEnrichmentBatch::query()
            ->whereIn('status', [
                ProductEnrichmentBatch::STATUS_QUEUED,
                ProductEnrichmentBatch::STATUS_RUNNING,
            ])
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (ProductEnrichmentBatch $batch): ProductEnrichmentBatch => $this->enrichment->finalizeIfJobsGone($batch))
            ->filter(fn (ProductEnrichmentBatch $batch): bool => in_array($batch->status, [
                ProductEnrichmentBatch::STATUS_QUEUED,
                ProductEnrichmentBatch::STATUS_RUNNING,
            ], true));

        $ctx = $this->batchLinkContext($batches);

        $recent = ProductEnrichmentBatch::query()
            ->whereIn('status', [
                ProductEnrichmentBatch::STATUS_DONE,
                ProductEnrichmentBatch::STATUS_FAILED,
                ProductEnrichmentBatch::STATUS_CANCELLED,
            ])
            ->where('updated_at', '>=', now()->subDays(2))
            ->orderByDesc('id')
            ->limit(8)
            ->get();
        $recentCtx = $this->batchLinkContext($recent);

        return response()->json([
            'batches' => $batches
                ->map(fn (ProductEnrichmentBatch $batch): array => $this->batchPayload($batch, $ctx[(int) $batch->id] ?? null))
                ->values()
                ->all(),
            'recent' => $recent
                ->map(fn (ProductEnrichmentBatch $batch): array => $this->batchPayload($batch, $recentCtx[(int) $batch->id] ?? null))
                ->values()
                ->all(),
            ...$this->enrichment->enrichmentProductCounts(),
        ]);
    }

    public function historyBatches(Request $request): JsonResponse
    {
        $data = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'nullable', 'in:done,failed,cancelled'],
        ]);

        $query = ProductEnrichmentBatch::query()
            ->with('creator:id,name')
            ->whereIn('status', [
                ProductEnrichmentBatch::STATUS_DONE,
                ProductEnrichmentBatch::STATUS_FAILED,
                ProductEnrichmentBatch::STATUS_CANCELLED,
            ])
            ->orderByDesc('id');

        $status = isset($data['status']) && is_string($data['status']) && $data['status'] !== ''
            ? $data['status']
            : null;
        if ($status !== null) {
            $query->where('status', $status);
        }

        $paginator = $query->paginate((int) ($data['per_page'] ?? 40));
        $ctx = $this->batchLinkContext($paginator->getCollection());

        return response()->json([
            'data' => $paginator->getCollection()
                ->map(function (ProductEnrichmentBatch $batch) use ($ctx): array {
                    $payload = $this->batchPayload($batch, $ctx[(int) $batch->id] ?? null);
                    $payload['created_by_name'] = $batch->creator?->name;

                    return $payload;
                })
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function stopAll(): JsonResponse
    {
        $result = $this->enrichment->stopAllEnrichment();

        return response()->json([
            'message' => 'Zatrzymano pobieranie opisów.',
            ...$result,
            ...$this->enrichment->enrichmentProductCounts(),
        ]);
    }

    public function showBatch(ProductEnrichmentBatch $batch): JsonResponse
    {
        return response()->json($this->batchPayload($batch));
    }

    public function batchItems(Request $request, ProductEnrichmentBatch $batch): JsonResponse
    {
        $data = $request->validate([
            'sort' => ['sometimes', 'in:status,updated'],
            'status' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);
        $log = $this->enrichment->batchItemLog(
            $batch,
            (string) ($data['sort'] ?? 'status'),
            isset($data['status']) && $data['status'] !== '' ? (string) $data['status'] : null,
        );

        return response()->json([
            'batch' => $this->batchPayload($batch),
            ...$log,
        ]);
    }

    public function processBatchItem(ProductEnrichmentBatch $batch, Product $product): JsonResponse
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        try {
            $job = new EnrichProductJob($product->id, $batch->id, (bool) $batch->force);
            $job->handle($this->enrichment, $this->aiSettings, $this->slots);
        } catch (EnrichmentCancelledException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'batch' => $this->batchPayload($batch->fresh() ?? $batch),
            ], 409);
        } catch (RuntimeException|Throwable $e) {
            $fresh = $batch->fresh() ?? $batch;
            if (! $fresh->isCancelled() && ($fresh->done + $fresh->failed) < $fresh->total) {
                $this->enrichment->markBatchItem($fresh, false);
            }

            return response()->json([
                'message' => $e->getMessage(),
                'batch' => $this->batchPayload($fresh->fresh() ?? $fresh),
            ], 422);
        }

        return response()->json([
            'batch' => $this->batchPayload($batch->fresh() ?? $batch),
        ]);
    }

    public function cancelBatch(ProductEnrichmentBatch $batch): JsonResponse
    {
        try {
            $result = $this->enrichment->cancelBatch($batch);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Batch zatrzymany.',
            'batch' => $this->batchPayload($result['batch']),
            'removed_jobs' => $result['removed_jobs'],
            'marked_products' => $result['marked_products'],
        ]);
    }

    /**
     * @param  array{manufacturer: ?string, current_product_id: ?int, price_list_id: ?int}|null  $ctx
     * @return array<string, mixed>
     */
    private function batchPayload(ProductEnrichmentBatch $batch, ?array $ctx = null): array
    {
        $processed = $batch->done + $batch->failed;
        $pct = $batch->total > 0 ? round(100 * $processed / $batch->total, 1) : 0.0;
        $ctx ??= $this->batchLinkContext([$batch])[(int) $batch->id] ?? [
            'manufacturer' => null,
            'current_product_id' => null,
            'price_list_id' => null,
        ];

        return [
            'id' => $batch->id,
            'scope' => $batch->scope,
            'scope_id' => $batch->scope_id,
            'total' => $batch->total,
            'done' => $batch->done,
            'failed' => $batch->failed,
            'status' => $batch->status,
            'force' => $batch->force,
            'progress_percent' => $pct,
            'current_sku' => $batch->current_sku,
            'current_name' => $batch->current_name,
            'message' => $batch->message,
            'manufacturer' => $ctx['manufacturer'],
            'current_product_id' => $ctx['current_product_id'],
            'price_list_id' => $ctx['price_list_id'],
            'created_at' => $batch->created_at?->toIso8601String(),
            'updated_at' => $batch->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  iterable<int, ProductEnrichmentBatch>  $batches
     * @return array<int, array{manufacturer: ?string, current_product_id: ?int, price_list_id: ?int}>
     */
    private function batchLinkContext(iterable $batches): array
    {
        $list = collect($batches);
        $priceListIds = $list
            ->where('scope', ProductEnrichmentBatch::SCOPE_PRICE_LIST)
            ->pluck('scope_id')
            ->filter()
            ->unique()
            ->values();
        $priceLists = $priceListIds->isEmpty()
            ? collect()
            : PriceList::query()->whereIn('id', $priceListIds)->get(['id', 'manufacturer'])->keyBy('id');

        $skus = $list->pluck('current_sku')->filter()->unique()->values();
        $productsBySku = $skus->isEmpty()
            ? collect()
            : Product::query()->whereIn('sku', $skus)->get(['id', 'sku', 'manufacturer'])->groupBy('sku');

        $productScopeIds = $list
            ->where('scope', ProductEnrichmentBatch::SCOPE_PRODUCT)
            ->pluck('scope_id')
            ->filter()
            ->unique()
            ->values();
        $productsById = $productScopeIds->isEmpty()
            ? collect()
            : Product::query()->whereIn('id', $productScopeIds)->get(['id', 'manufacturer'])->keyBy('id');

        $out = [];
        foreach ($list as $batch) {
            $manufacturer = null;
            $priceListId = null;
            $currentProductId = null;

            if ($batch->scope === ProductEnrichmentBatch::SCOPE_PRICE_LIST) {
                $priceListId = (int) $batch->scope_id;
                $manufacturer = $priceLists->get($priceListId)?->manufacturer;
            }

            if (is_string($batch->current_sku) && $batch->current_sku !== '') {
                $candidates = $productsBySku->get($batch->current_sku, collect());
                $product = is_string($manufacturer) && $manufacturer !== ''
                    ? ($candidates->firstWhere('manufacturer', $manufacturer) ?? $candidates->first())
                    : $candidates->first();
                if ($product !== null) {
                    $currentProductId = (int) $product->id;
                    $manufacturer = $manufacturer ?: $product->manufacturer;
                }
            }

            if ($manufacturer === null && $batch->scope === ProductEnrichmentBatch::SCOPE_PRODUCT) {
                $product = $productsById->get((int) $batch->scope_id);
                if ($product !== null) {
                    $manufacturer = $product->manufacturer;
                    $currentProductId ??= (int) $product->id;
                }
            }

            $out[(int) $batch->id] = [
                'manufacturer' => is_string($manufacturer) && $manufacturer !== '' ? $manufacturer : null,
                'current_product_id' => $currentProductId,
                'price_list_id' => $priceListId,
            ];
        }

        return $out;
    }
}
