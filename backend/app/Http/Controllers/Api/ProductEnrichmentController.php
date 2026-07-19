<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductEnrichmentBatch;
use App\Services\Enrichment\ProductEnrichmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ProductEnrichmentController extends Controller
{
    public function __construct(
        private readonly ProductEnrichmentService $enrichment,
    ) {}

    public function enrichProduct(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'force' => ['sometimes', 'boolean'],
        ]);

        try {
            $batch = $this->enrichment->enqueueProduct(
                $product,
                $request->user(),
                (bool) ($data['force'] ?? false),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'batch' => $this->batchPayload($batch),
            'product_id' => $product->id,
        ], 202);
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

    public function showBatch(ProductEnrichmentBatch $batch): JsonResponse
    {
        return response()->json($this->batchPayload($batch));
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
