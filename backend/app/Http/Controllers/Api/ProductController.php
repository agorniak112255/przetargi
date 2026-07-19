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
                'description',
                'enrichment_status',
                'enriched_at',
                'enrichment_error',
            ])
            ->withCount(['substitutes', 'images'])
            ->with(['images' => static fn ($q) => $q->orderBy('sort_order')->orderBy('id')]);

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

        $allowedSort = [
            'sku' => 'sku',
            'name' => 'name',
            'manufacturer' => 'manufacturer',
            'catalog_price_net' => 'catalog_price_net',
            'currency' => 'currency',
            'discount_percent' => 'discount_percent',
            'description' => 'description',
            'images_count' => 'images_count',
            'enrichment_status' => 'enrichment_status',
            'stock' => 'stock',
            'substitutes_count' => 'substitutes_count',
        ];
        $sortKey = (string) $request->string('sort', 'name');
        $sortCol = $allowedSort[$sortKey] ?? 'name';
        $dir = strtolower((string) $request->string('dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        if ($sortCol === 'description') {
            // najpierw z opisem / bez, potem alfabetycznie po treści
            $query->orderByRaw(
                $dir === 'asc'
                    ? "(CASE WHEN description IS NULL OR TRIM(description) = '' THEN 1 ELSE 0 END) ASC, description ASC"
                    : "(CASE WHEN description IS NULL OR TRIM(description) = '' THEN 1 ELSE 0 END) DESC, description DESC"
            );
        } else {
            $query->orderBy($sortCol, $dir);
        }
        if ($sortCol !== 'name') {
            $query->orderBy('name', 'asc');
        }

        $perPage = min(100, max(1, (int) $request->integer('per_page', 100)));
        $page = $query->paginate($perPage);

        $page->getCollection()->transform(static function (Product $product): array {
            $row = $product->toArray();
            $row['images'] = $product->images->map(static fn ($img): array => [
                'id' => $img->id,
                'url' => $img->url(),
                'source_url' => $img->source_url,
                'is_primary' => $img->is_primary,
                'sort_order' => $img->sort_order,
            ])->values()->all();

            return $row;
        });

        return response()->json($page);
    }

    public function show(Product $product): JsonResponse
    {
        $product->load([
            'substitutes.substituteProduct:id,sku,name,manufacturer,catalog_price_net',
            'substitutes.approver:id,name',
            'images',
        ]);

        $payload = $product->toArray();
        $payload['images'] = $product->images->map(static fn ($img): array => [
            'id' => $img->id,
            'url' => $img->url(),
            'source_url' => $img->source_url,
            'is_primary' => $img->is_primary,
            'sort_order' => $img->sort_order,
        ])->values()->all();

        return response()->json($payload);
    }
}
