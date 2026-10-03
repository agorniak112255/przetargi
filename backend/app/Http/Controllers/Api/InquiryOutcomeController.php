<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientInquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Jak się skończyło zapytanie: PUT /inquiries/{inquiry}/outcome i powiązanie z klientem PUT /inquiries/{inquiry}/client
 * (tylko autor zapytania). Strumień C.
 *
 * ZAŚLEPKA (krok 0): odpowiada 501 „Jeszcze niegotowe”.
 */
class InquiryOutcomeController extends Controller
{
    public function update(Request $request, ClientInquiry $inquiry): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }

    public function client(Request $request, ClientInquiry $inquiry): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }
}
