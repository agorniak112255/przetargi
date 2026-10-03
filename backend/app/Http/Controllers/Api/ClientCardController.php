<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Karta klienta: GET /clients/{client} (ClientCard) i GET /clients/{client}/timeline?type= (oś czasu). Strumień B.
 *
 * ZAŚLEPKA (krok 0): odpowiada 501 „Jeszcze niegotowe”.
 */
class ClientCardController extends Controller
{
    public function show(Request $request, Client $client): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }

    public function timeline(Request $request, Client $client): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }
}
