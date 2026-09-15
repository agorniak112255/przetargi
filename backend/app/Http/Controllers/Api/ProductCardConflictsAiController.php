<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\CardConflictAiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * „Sprawdź modelem” w oknie „Weryfikacja karty” — sprzeczności między polami karty. Każdy cytat
 * sprawdza serwis; AI wyłączone, brak klucza, 402 i 429 wracają z klienta jako czytelny komunikat.
 */
class ProductCardConflictsAiController extends Controller
{
    public function __construct(
        private readonly CardConflictAiService $conflicts,
    ) {}

    public function __invoke(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'refresh' => ['sometimes', 'boolean'],
        ]);

        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        try {
            $payload = $this->conflicts->find($product, (bool) ($data['refresh'] ?? false));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Nie udało się sprawdzić karty modelem: '.$e->getMessage()], 422);
        }

        return response()->json($payload);
    }
}
