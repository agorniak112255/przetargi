<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cele handlowców: GET /reports/targets?month=, PUT /reports/targets/{month} (reports.view + reports.targets.manage)
 * i własny cel GET /me/sales-target. Strumień D.
 *
 * ZAŚLEPKA (krok 0): odpowiada 501 „Jeszcze niegotowe”.
 */
class SalesTargetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }

    public function update(Request $request, string $month): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }

    public function mine(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }
}
