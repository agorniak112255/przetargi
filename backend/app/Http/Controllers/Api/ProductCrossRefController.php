<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

    public function compare(Request $request): JsonResponse
    {
        $data = $request->validate([
            'a' => ['required', 'integer', 'exists:products,id'],
            'b' => ['required', 'integer', 'exists:products,id', 'different:a'],
            'requirement' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $productA = Product::query()->findOrFail($data['a']);
        $productB = Product::query()->findOrFail($data['b']);

        return response()->json(
            $this->compare->compare(
                $productA,
                $productB,
                $data['requirement'] ?? null
            )
        );
    }
}
