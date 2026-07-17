<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Client::query()
                ->with(['owner:id,name'])
                ->withCount('tenders')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'nip' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:100'],
        ]);

        $client = Client::query()->create([
            ...$data,
            'owner_id' => $request->user()->id,
        ]);

        return response()->json(
            $client->load(['owner:id,name'])->loadCount('tenders'),
            201
        );
    }

    public function update(Request $request, Client $client): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'nip' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:100'],
        ]);

        $client->update($data);

        return response()->json(
            $client->fresh()->load(['owner:id,name'])->loadCount('tenders')
        );
    }
}
