<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Moje konto › Powiadomienia (GET/PUT /me/notification-preferences).
 * ZAŚLEPKA kroku 0 — pełną logikę dopisuje strumień C.
 */
class NotificationPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }

    public function update(Request $request): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }
}
