<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Search\GlobalSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Jedno pole wyszukiwania (Ctrl+K): GET /search?q= — grupy Produkty, Przetargi, Zapytania, Klienci, każda z własnym
 * uprawnieniem (bez uprawnień — pusta lista grup, nie odmowa); bez cen. Strumień A.
 */
class GlobalSearchController extends Controller
{
    public function __construct(
        private readonly GlobalSearch $search,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string']], [
            'q.required' => 'Wpisz, czego szukać.',
            'q.string' => 'Szukana fraza musi być tekstem.',
        ]);
        // długość po obcięciu spacji z brzegów — „ a ” to jeden znak, nie trzy
        $q = trim((string) $request->string('q'));
        $length = mb_strlen($q);
        if ($length < GlobalSearch::MIN_LENGTH || $length > GlobalSearch::MAX_LENGTH) {
            throw ValidationException::withMessages([
                'q' => ['Szukana fraza musi mieć od '.GlobalSearch::MIN_LENGTH.' do '.GlobalSearch::MAX_LENGTH.' znaków.'],
            ]);
        }

        return response()->json($this->search->search($request->user(), $q));
    }
}
