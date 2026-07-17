<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductSubstitute;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

    public function approve(Request $request, ProductSubstitute $productSubstitute): JsonResponse
    {
        $role = $request->user()->role;
        if (! in_array($role, ['kierownik', 'admin', 'dyrektor'], true)) {
            throw ValidationException::withMessages([
                'approval_status' => ['Tylko kierownik / dyrektor może zatwierdzać zamienniki.'],
            ]);
        }

        $data = $request->validate([
            'approval_status' => ['required', 'in:zatwierdzony,odrzucony,oczekuje'],
        ]);

        $productSubstitute->update([
            'approval_status' => $data['approval_status'],
            'approved_by' => $request->user()->id,
        ]);

        return response()->json(
            $productSubstitute->fresh(['substituteProduct', 'mainProduct', 'approver'])
        );
    }
}
