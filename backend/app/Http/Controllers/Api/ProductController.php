<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()
            ->select([
                'id',
                'sku',
                'name',
                'manufacturer',
                'category',
                'catalog_price_net',
                'discount_percent',
                'purchase_price',
                'currency',
                'pack_qty',
                'packaging',
                'stock',
            ])
            ->withCount('substitutes');

        if ($request->filled('q')) {
            $term = trim((string) $request->string('q'));
            $like = '%'.$term.'%';
            $query->where(function ($builder) use ($like, $term) {
                $builder->where('sku', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('manufacturer', 'like', $like);
                // szybka ścieżka dokładnego SKU
                if ($term !== '') {
                    $builder->orWhere('sku', $term);
                }
            });
        }

        if ($request->filled('category')) {
            $query->where('category', $request->string('category'));
        }

        $perPage = min(100, max(1, (int) $request->integer('per_page', 100)));

        return response()->json(
            $query->orderBy('name')->paginate($perPage)
        );
    }

    public function show(Product $product): JsonResponse
    {
        $product->load([
            'substitutes.substituteProduct:id,sku,name,manufacturer,catalog_price_net',
            'substitutes.approver:id,name',
        ]);

        return response()->json($product);
    }
}
