<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientNote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notatki na karcie klienta: POST /clients/{client}/notes, PATCH|DELETE /clients/{client}/notes/{note} (autor albo
 * clients.manage). Strumień B.
 *
 * ZAŚLEPKA (krok 0): odpowiada 501 „Jeszcze niegotowe”.
 */
class ClientNoteController extends Controller
{
    public function store(Request $request, Client $client): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }

    public function update(Request $request, Client $client, ClientNote $note): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }

    public function destroy(Request $request, Client $client, ClientNote $note): JsonResponse
    {
        return response()->json(['message' => 'Jeszcze niegotowe'], 501);
    }
}
