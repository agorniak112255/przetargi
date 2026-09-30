<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ApproveProductSubstituteRequest;
use App\Http\Requests\StoreProductSubstituteRequest;
use App\Http\Requests\UpdateProductSubstituteRequest;
use App\Models\Product;
use App\Models\ProductSubstitute;
use App\Services\Pricing\SupplierSpecialMask;
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

        $rows = $query->orderBy('main_product_id')->orderByDesc('match_percent')->get();
        $mask = SupplierSpecialMask::forUser($request->user());
        // tylko karty zamienne mają ceny (catalog_price_net) — hurtem, lista jest bez stronicowania
        $mask->preload($rows->pluck('substitute_product_id')->filter()->all());

        return response()->json($rows->map(fn (ProductSubstitute $row): ?ProductSubstitute => $this->masked($row, $mask)));
    }

    public function byMain(Request $request, Product $product): JsonResponse
    {
        $substitutes = ProductSubstitute::query()
            ->with(['substituteProduct', 'approver:id,name'])
            ->where('main_product_id', $product->id)
            ->orderByDesc('match_percent')
            ->get();
        $mask = SupplierSpecialMask::forUser($request->user());
        $mask->preload([(int) $product->id, ...$substitutes->pluck('substitute_product_id')->filter()->all()]);

        return response()->json([
            'main_product' => $mask->maskProduct($product),
            'substitutes' => $substitutes->map(fn (ProductSubstitute $row): ?ProductSubstitute => $this->masked($row, $mask)),
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
            $this->masked($substitute->fresh([
                'mainProduct:id,sku,name,manufacturer',
                'substituteProduct:id,sku,name,manufacturer,catalog_price_net',
                'approver:id,name',
            ]), SupplierSpecialMask::forUser($request->user())),
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
            $this->masked($productSubstitute->fresh([
                'mainProduct:id,sku,name,manufacturer',
                'substituteProduct:id,sku,name,manufacturer,catalog_price_net',
                'approver:id,name',
            ]), SupplierSpecialMask::forUser($request->user()))
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
            $this->masked($productSubstitute->fresh([
                'mainProduct:id,sku,name,manufacturer',
                'substituteProduct:id,sku,name,manufacturer,catalog_price_net',
                'approver:id,name',
            ]), SupplierSpecialMask::forUser($request->user()))
        );
    }

    /**
     * Zamiennik z kartami w widoku cen widza: bez uprawnienia cena specjalna B2B karty zamiennej w cenie
     * standardowej. Relacje podmienione tylko w odpowiedzi (klon karty nie da się zapisać).
     */
    private function masked(?ProductSubstitute $row, SupplierSpecialMask $mask): ?ProductSubstitute
    {
        if ($row === null || ! $mask->hides()) {
            return $row;
        }
        foreach (['substituteProduct', 'mainProduct'] as $relation) {
            $related = $row->relationLoaded($relation) ? $row->getRelation($relation) : null;
            // karta wczytana bez kolumn cen (mainProduct: id, sku, nazwa, producent) nie ma czego ukrywać — bez zapytań maski
            $priced = $related instanceof Product
                && array_intersect_key($related->getAttributes(), array_flip(['catalog_price_net', 'purchase_price', 'discount_percent'])) !== [];
            if ($priced) {
                $row->setRelation($relation, $mask->maskProduct($related));
            }
        }

        return $row;
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
