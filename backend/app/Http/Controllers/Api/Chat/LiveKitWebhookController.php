<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Services\Chat\ChatCallService;
use App\Services\Chat\LiveKitRooms;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook serwera LiveKit — bez logowania, bez limitu żądań i poza `log.activity`: tożsamość potwierdza podpis
 * (JWT HS256 sekretem API z sumą sha256 surowego body). Obsługiwane: participant_joined, participant_left,
 * participant_connection_aborted, room_finished; reszta i nieznane pokoje → 200 bez zmian.
 */
class LiveKitWebhookController extends Controller
{
    private const HANDLED = ['participant_joined', 'participant_left', 'participant_connection_aborted', 'room_finished'];

    public function __invoke(Request $request, LiveKitRooms $rooms, ChatCallService $calls): JsonResponse
    {
        $body = $request->getContent();
        if (! $rooms->verifyWebhook($request->header('Authorization'), $body)) {
            return response()->json(['message' => 'Nieprawidłowy podpis.'], 401);
        }

        $payload = json_decode($body, true);
        if (is_array($payload) && in_array($payload['event'] ?? null, self::HANDLED, true)) {
            $calls->handleWebhook($payload);
        }

        return response()->json(['ok' => true]);
    }
}
