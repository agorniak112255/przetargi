<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ProductCatalogHealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class ProductCatalogHealthController extends Controller
{
    public function __construct(
        private readonly ProductCatalogHealthService $health,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        return response()->json(
            $this->health->report($data['manufacturer'] ?? null)
        );
    }

    public function vector(Request $request): JsonResponse
    {
        $data = $request->validate([
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        return response()->json(
            $this->health->vectorReport($data['manufacturer'] ?? null)
        );
    }

    public function queue(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'in:missing_description,not_enriched'],
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:120'],
            'force' => ['sometimes', 'boolean'],
        ]);

        try {
            $result = $this->health->queue(
                $request->user(),
                $data['reason'],
                $data['manufacturer'] ?? null,
                (bool) ($data['force'] ?? false),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $batch = $result['batch'];

        return response()->json([
            'queued' => $result['queued'],
            'reason' => $result['reason'],
            'message' => $result['message'],
            'batch' => [
                'id' => $batch->id,
                'status' => $batch->status,
                'total' => $batch->total,
                'done' => $batch->done,
                'failed' => $batch->failed,
                'message' => $batch->message,
            ],
        ], 202);
    }

    public function backfillAttributes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:120'],
            'force' => ['sometimes', 'boolean'],
        ]);

        $result = $this->health->backfillAttributes(
            $data['manufacturer'] ?? null,
            (bool) ($data['force'] ?? false),
        );

        $message = "Wypełniono z nazwy i norm: {$result['filled']} produktów (bez AI).";
        if ($result['pending'] > 0) {
            $message .= " Czeka na opis: {$result['pending']} — nazwa nie zawiera materiału, normy ani kategorii.";
        }

        return response()->json([
            'updated' => $result['updated'],
            'filled' => $result['filled'],
            'pending' => $result['pending'],
            'message' => $message,
        ]);
    }
}
