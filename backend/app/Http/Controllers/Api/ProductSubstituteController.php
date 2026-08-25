<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveProductSubstituteRequest;
use App\Http\Requests\StoreProductSubstituteRequest;
use App\Http\Requests\UpdateProductSubstituteRequest;
use App\Models\Product;
use App\Models\ProductSubstitute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductSubstituteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ProductSubstitute::query()
            ->with([
                'mainProduct:id,sku,name,manufacturer',
                'substituteProduct:id,sku,name,manufacturer,catalog_price_net',
                'approver:id,name',
            ]);

        if ($request->filled('main_product_id')) {
            $query->where('main_product_id', $request->integer('main_product_id'));
        }

        if ($request->filled('approval_status')) {
            $query->where('approval_status', $request->string('approval_status'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->string('type'));
        }

        if ($request->filled('q')) {
            $term = trim((string) $request->string('q'));
            $like = '%'.$term.'%';
            $query->where(function ($builder) use ($like): void {
                $builder->whereHas('mainProduct', function ($q) use ($like): void {
                    $q->where('sku', 'like', $like)
                        ->orWhere('name', 'like', $like)
                        ->orWhere('manufacturer', 'like', $like);
                })->orWhereHas('substituteProduct', function ($q) use ($like): void {
                    $q->where('sku', 'like', $like)
                        ->orWhere('name', 'like', $like)
                        ->orWhere('manufacturer', 'like', $like);
                });
            });
        }

        return response()->json(
            $query->orderBy('main_product_id')->orderByDesc('match_percent')->get()
        );
    }

    public function byMain(Product $product): JsonResponse
    {
        $substitutes = ProductSubstitute::query()
            ->with(['substituteProduct', 'approver:id,name'])
            ->where('main_product_id', $product->id)
            ->orderByDesc('match_percent')
            ->get();

        return response()->json([
            'main_product' => $product,
            'substitutes' => $substitutes,
        ]);
    }

    public function store(StoreProductSubstituteRequest $request): JsonResponse
    {
        $data = $request->validated();

        $substitute = ProductSubstitute::query()->create([
            ...$data,
            'norms_ok' => $data['norms_ok'] ?? true,
            'certs_ok' => $data['certs_ok'] ?? true,
            'approval_status' => 'oczekuje',
            'approved_by' => null,
        ]);

        return response()->json(
            $substitute->fresh([
                'mainProduct:id,sku,name,manufacturer',
                'substituteProduct:id,sku,name,manufacturer,catalog_price_net',
                'approver:id,name',
            ]),
            201
        );
    }

    public function update(
        UpdateProductSubstituteRequest $request,
        ProductSubstitute $productSubstitute
    ): JsonResponse {
        $data = $request->validated();

        $contentChanged = $this->contentChanged($productSubstitute, $data);

        $productSubstitute->update([
            ...$data,
            ...($contentChanged ? [
                'approval_status' => 'oczekuje',
                'approved_by' => null,
            ] : []),
        ]);

        return response()->json(
            $productSubstitute->fresh([
                'mainProduct:id,sku,name,manufacturer',
                'substituteProduct:id,sku,name,manufacturer,catalog_price_net',
                'approver:id,name',
            ])
        );
    }

    public function destroy(ProductSubstitute $productSubstitute): JsonResponse
    {
        if (! request()->user()?->can('substitutes.manage')) {
            abort(403);
        }

        $productSubstitute->delete();

        return response()->json(['ok' => true]);
    }

    public function approve(
        ApproveProductSubstituteRequest $request,
        ProductSubstitute $productSubstitute
    ): JsonResponse {
        $data = $request->validated();

        $productSubstitute->update([
            'approval_status' => $data['approval_status'],
            'approved_by' => $data['approval_status'] === 'oczekuje'
                ? null
                : $request->user()->id,
        ]);

        return response()->json(
            $productSubstitute->fresh([
                'mainProduct:id,sku,name,manufacturer',
                'substituteProduct:id,sku,name,manufacturer,catalog_price_net',
                'approver:id,name',
            ])
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function contentChanged(ProductSubstitute $row, array $data): bool
    {
        $keys = [
            'main_product_id',
            'substitute_product_id',
            'type',
            'match_percent',
            'norms_ok',
            'certs_ok',
            'reason',
        ];

        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $incoming = $data[$key];
            $current = $row->getAttribute($key);
            if ($key === 'norms_ok' || $key === 'certs_ok') {
                if ((bool) $incoming !== (bool) $current) {
                    return true;
                }

                continue;
            }
            if ((string) $incoming !== (string) $current) {
                return true;
            }
        }

        return false;
    }
}
