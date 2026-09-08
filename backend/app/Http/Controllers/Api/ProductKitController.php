<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttachProductKitRequest;
use App\Http\Requests\DetachProductKitRequest;
use App\Models\Product;
use App\Services\ProductKitService;
use App\Services\ProductKitSuggestionService;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

class ProductKitController extends Controller
{
    public function __construct(
        private readonly ProductKitSuggestionService $suggestions,
        private readonly ProductKitService $kit,
    ) {}

    public function suggest(Product $product): JsonResponse
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        try {
            $payload = $this->suggestions->suggest($product);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Nie udało się dobrać zestawu: '.$e->getMessage()], 422);
        }

        return response()->json($payload);
    }

    public function attach(AttachProductKitRequest $request, Product $product): JsonResponse
    {
        $ids = array_map(static fn (mixed $id): int => (int) $id, $request->validated('related_product_ids'));

        return response()->json([
            'accessories' => $this->kit->attach($product, $ids),
        ]);
    }

    public function destroy(DetachProductKitRequest $request, Product $product): JsonResponse
    {
        $ids = array_map(
            static fn (mixed $id): int => (int) $id,
            $request->validated('accessory_ids') ?? []
        );

        return response()->json([
            'accessories' => $this->kit->detach($product, $ids, $request->boolean('all')),
        ]);
    }
}
