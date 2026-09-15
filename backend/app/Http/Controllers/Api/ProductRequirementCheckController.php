<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\RequirementCheck\RequirementCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Porównanie parametrów wymagania z kartą do okna „Weryfikacja karty”. Same reguły — bez modelu AI,
 * więc handlowiec za każdym razem widzi to samo i każdy werdykt ma wskazane pole karty.
 */
class ProductRequirementCheckController extends Controller
{
    public function __construct(
        private readonly RequirementCheck $check,
    ) {}

    public function __invoke(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'min:3', 'max:5000'],
        ]);

        return response()->json($this->check->compare((string) $data['query'], $product));
    }
}
