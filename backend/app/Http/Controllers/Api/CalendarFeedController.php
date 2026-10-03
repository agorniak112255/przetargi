<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Osobisty adres kalendarza (ICS): GET|POST|DELETE /me/calendar-feed. Klucz pokazany raz przy wygenerowaniu, w bazie
 * tylko skrót SHA-256; nowy adres unieważnia stary. Strumień A.
 *
 * ZAŚLEPKA (krok 0): odpowiada 501 „Jeszcze niegotowe”.
 */
class CalendarFeedController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }

    public function destroy(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }
}
