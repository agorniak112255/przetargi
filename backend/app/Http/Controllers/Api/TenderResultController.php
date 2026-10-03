<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tender;
use App\Models\TenderLot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Wynik przetargu per część zamówienia (GET/PUT /tenders/{tender}/result, DELETE …/result/lots/{lot}).
 * ZAŚLEPKA kroku 0 — pełną logikę dopisuje strumień A (TenderResultService).
 */
class TenderResultController extends Controller
{
    public function show(Request $request, Tender $tender): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }

    public function update(Request $request, Tender $tender): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }

    public function destroyLot(Request $request, Tender $tender, TenderLot $lot): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }
}
