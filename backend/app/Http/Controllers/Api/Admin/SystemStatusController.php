<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\SystemAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administracja › Stan systemu (GET /admin/system-status, …/gaps/{kind}, POST /admin/system-alerts/{alert}/mute|unmute).
 * ZAŚLEPKA kroku 0 — pełną logikę dopisuje strumień D.
 */
class SystemStatusController extends Controller
{
    /** Rodzaje „Danych do uzupełnienia” (SystemGapKind we frontendzie). */
    public const GAP_KINDS = [
        'tenders_without_time',
        'tenders_without_notice',
        'salespeople_without_operator',
        'clients_without_xl',
        'sold_items_without_card',
    ];

    public function show(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }

    public function gaps(Request $request, string $kind): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }

    public function mute(Request $request, SystemAlert $alert): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }

    public function unmute(Request $request, SystemAlert $alert): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }
}
