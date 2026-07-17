<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\OpenAiCompatibleClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class AiSettingsController extends Controller
{
    public function __construct(
        private readonly AiSettingsService $settings,
        private readonly OpenAiCompatibleClient $llm,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json($this->settings->publicView());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'provider' => ['sometimes', 'string', 'max:50'],
            'base_url' => ['sometimes', 'url', 'max:255'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'model' => ['sometimes', 'string', 'max:120'],
            'timeout_seconds' => ['sometimes', 'integer', 'min:10', 'max:300'],
            'temperature' => ['sometimes', 'numeric', 'min:0', 'max:2'],
        ]);

        $this->settings->update($data);

        return response()->json($this->settings->publicView());
    }

    public function test(): JsonResponse
    {
        try {
            $result = $this->llm->chatJson([
                [
                    'role' => 'system',
                    'content' => 'Odpowiedz wyłącznie JSON: {"ok":true,"message":"krótki tekst po polsku"}',
                ],
                [
                    'role' => 'user',
                    'content' => 'Ping test połączenia z API modelu AI.',
                ],
            ]);
        } catch (RuntimeException|Throwable $e) {
            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => is_string($result['message'] ?? null)
                ? $result['message']
                : 'Połączenie z API AI działa.',
            'raw' => $result,
            'settings' => $this->settings->publicView(),
        ]);
    }
}
