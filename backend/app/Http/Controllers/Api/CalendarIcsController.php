<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Publiczny plik kalendarza GET /calendar/{token}.ics (poza logowaniem i dziennikiem aktywności, limit 60/min).
 * Uprawnienia właściciela adresu sprawdzane przy każdym pobraniu; bez cen. Strumień A.
 *
 * ZAŚLEPKA (krok 0): odpowiada 501 „Jeszcze niegotowe”.
 */
class CalendarIcsController extends Controller
{
    public function show(Request $request, string $token): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }
}
