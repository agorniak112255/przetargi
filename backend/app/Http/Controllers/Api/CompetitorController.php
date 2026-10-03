<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Podpowiedzi firm konkurencji (GET /competitors?q=) do formularza wyniku przetargu.
 * ZAŚLEPKA kroku 0 — pełną logikę dopisuje strumień A.
 */
class CompetitorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }
}
