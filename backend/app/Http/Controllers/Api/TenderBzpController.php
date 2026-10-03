<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * „Sprawdź w Biuletynie” (POST /tenders/{tender}/result/bzp-check) — dopasowanie przetargu do ogłoszeń już
 * zapisanych w bazie, bez zapytań do Biuletynu. ZAŚLEPKA kroku 0 — pełną logikę dopisuje strumień B.
 */
class TenderBzpController extends Controller
{
    public function check(Request $request, Tender $tender): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }
}
