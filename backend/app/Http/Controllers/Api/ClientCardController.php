<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Clients\ClientCardSummary;
use App\Services\Clients\ClientTimeline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Karta klienta (clients.view): GET /clients/{client} (ClientCard) i GET /clients/{client}/timeline?type= (oś czasu,
 * każda sekcja z własnym uprawnieniem — ClientTimeline).
 */
class ClientCardController extends Controller
{
    public function show(Request $request, Client $client, ClientCardSummary $summary): JsonResponse
    {
        return response()->json($summary->build($client, $request->user()));
    }

    public function timeline(Request $request, Client $client, ClientTimeline $timeline): JsonResponse
    {
        $type = (string) ($request->validate([
            'type' => ['sometimes', 'string', Rule::in(ClientTimeline::TYPES)],
        ], [
            'type.in' => 'Nieznany rodzaj wpisów osi czasu.',
        ])['type'] ?? 'all');

        return response()->json($timeline->build($client, $request->user(), $type));
    }
}
