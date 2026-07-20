<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Services\Ai\AiSettingsService;
use App\Services\Enrichment\ProductEnrichmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ProductEnrichmentController extends Controller
{
    public function __construct(
        private readonly ProductEnrichmentService $enrichment,
        private readonly AiSettingsService $aiSettings,
    ) {}

    public function limits(): JsonResponse
    {
        return response()->json([
            'enrichment_batch_limit' => $this->aiSettings->enrichmentBatchLimit(),
        ]);
    }

    public function enrichProduct(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'force' => ['sometimes', 'boolean'],
        ]);

        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
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
            $batch = $this->enrichment->enqueuePriceList(
                $priceList,
                $request->user(),
                (bool) ($data['force'] ?? false),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'batch' => $this->batchPayload($batch),
            'price_list_id' => $priceList->id,
        ], 202);
    }

    public function enrichProducts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:500'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'force' => ['sometimes', 'boolean'],
        ]);

        try {
            $batch = $this->enrichment->enqueueProductIds(
                array_map('intval', $data['product_ids']),
                $request->user(),
                (bool) ($data['force'] ?? false),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'batch' => $this->batchPayload($batch),
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
            ->get();

        return response()->json(
            $batches->map(fn (ProductEnrichmentBatch $batch): array => $this->batchPayload($batch))->values()->all()
        );
    }

    public function showBatch(ProductEnrichmentBatch $batch): JsonResponse
    {
        return response()->json($this->batchPayload($batch));
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
     * @return array<string, mixed>
     */
    private function batchPayload(ProductEnrichmentBatch $batch): array
    {
        $processed = $batch->done + $batch->failed;
        $pct = $batch->total > 0 ? round(100 * $processed / $batch->total, 1) : 0.0;

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
            'created_at' => $batch->created_at?->toIso8601String(),
            'updated_at' => $batch->updated_at?->toIso8601String(),
        ];
    }
}
