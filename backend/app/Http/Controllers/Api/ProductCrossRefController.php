<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompareProductsRequest;
use App\Models\Product;
use App\Services\ProductCompareService;
use App\Services\ProductCrossRefService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductCrossRefController extends Controller
{
    public function __construct(
        private readonly ProductCrossRefService $crossRef,
        private readonly ProductCompareService $compare,
    ) {}

    public function crossRef(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'min:2', 'max:120'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:40'],
        ]);

        return response()->json(
            $this->crossRef->findByCode(
                $data['code'],
                (int) ($data['limit'] ?? 12)
            )
        );
    }

    public function compare(CompareProductsRequest $request): JsonResponse
    {
        $ids = $request->productIds();
        $productsById = Product::query()
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');
        $products = array_map(
            static fn (int $id): Product => $productsById->get($id),
            $ids
        );
        $requirement = $request->validated('requirement');

        $payload = count($products) === 2
            ? $this->compare->compare($products[0], $products[1], $requirement)
            : $this->compare->compareMany($products, $requirement);

        return response()->json($payload);
    }
}
