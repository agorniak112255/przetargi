<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Kalendarz terminów składania ofert: GET /tenders/calendar?from=&to=&filter= (zakres do 62 dni) — przetargi,
 * które użytkownik może oglądać (view_all albo accessibleBy). Strumień A.
 *
 * ZAŚLEPKA (krok 0): odpowiada 501 „Jeszcze niegotowe”.
 */
class TenderCalendarController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }
}
