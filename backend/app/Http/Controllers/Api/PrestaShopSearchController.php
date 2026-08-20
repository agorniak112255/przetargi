<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Presta\PrestaCatalogApplyService;
use App\Services\Presta\PrestaCatalogGateway;
use App\Services\Presta\PrestaProductSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PrestaShopSearchController extends Controller
{
    public function __construct(
        private readonly PrestaProductSearchService $search,
        private readonly PrestaCatalogApplyService $apply,
        private readonly PrestaCatalogGateway $catalog,
    ) {}

    public function status(): JsonResponse
    {
        $ping = $this->catalog->configured()
            ? $this->catalog->ping()
            : [
                'ok' => false,
                'message' => 'Sklep Presta nie jest skonfigurowany.',
                'active_products' => 0,
                'has_image_table' => false,
            ];

        return response()->json([
            'configured' => $this->catalog->configured(),
            'ok' => $ping['ok'],
            'message' => $ping['message'],
        ]);
    }

    public function searchProduct(Product $product): JsonResponse
    {
        return response()->json($this->search->search($product));
    }

    public function searchProducts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:80'],
            'product_ids.*' => ['integer', 'exists:products,id'],
        ]);

        $items = $this->search->searchMany($data['product_ids']);

        return response()->json([
            'total' => count($items),
            'items' => $items,
        ]);
    }

    public function apply(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'presta_id' => ['required', 'integer', 'min:1'],
            'force' => ['sometimes', 'boolean'],
            'method' => ['sometimes', 'string', 'max:32'],
            'score' => ['sometimes', 'integer', 'min:0', 'max:100'],
        ]);

        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        try {
            $result = $this->apply->apply(
                $product,
                (int) $data['presta_id'],
                (bool) ($data['force'] ?? false),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (isset($data['method']) || isset($data['score'])) {
            $result['match']->fill([
                'method' => (string) ($data['method'] ?? $result['match']->method),
                'score' => (int) ($data['score'] ?? $result['match']->score),
            ])->save();
        }

        $fresh = $result['product'];
        $payload = $fresh->toArray();
        $payload['images'] = $fresh->images->map(static fn ($img): array => [
            'id' => $img->id,
            'url' => $img->url(),
            'source_url' => $img->source_url,
            'is_primary' => $img->is_primary,
            'sort_order' => $img->sort_order,
        ])->values()->all();

        return response()->json([
            'product' => $payload,
            'images_count' => $result['images'],
            'match' => [
                'presta_id' => $result['match']->presta_id,
                'status' => $result['match']->status,
                'url' => $result['match']->presta_url,
            ],
        ]);
    }
}
