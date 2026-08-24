<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Vector\EmbeddingClient;
use App\Services\Vector\QdrantClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class AiSettingsController extends Controller
{
    public function __construct(
        private readonly AiSettingsService $settings,
        private readonly OpenAiCompatibleClient $llm,
        private readonly QdrantClient $qdrant,
        private readonly EmbeddingClient $embeddings,
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
            'enrichment_model' => ['nullable', 'string', 'max:120'],
            'enrichment_use_large_model' => ['sometimes', 'boolean'],
            'timeout_seconds' => ['sometimes', 'integer', 'min:10', 'max:300'],
            'temperature' => ['sometimes', 'numeric', 'min:0', 'max:2'],
            'web_search_enabled' => ['sometimes', 'boolean'],
            'tavily_api_key' => ['nullable', 'string', 'max:500'],
            'search_engine' => ['sometimes', 'string', 'in:tavily,duckduckgo'],
            'search_fallback' => ['sometimes', 'string', 'in:tavily,none'],
            'tavily_search_mode' => ['sometimes', 'string', 'in:eco,balanced,full'],
            'enrichment_batch_limit' => ['sometimes', 'integer', 'min:1', 'max:'.AiSettingsService::ENRICHMENT_BATCH_MAX],
            'match_concurrency' => ['sometimes', 'integer', 'min:1', 'max:'.AiSettingsService::CONCURRENCY_MAX],
            'vector_enabled' => ['sometimes', 'boolean'],
            'qdrant_url' => ['nullable', 'url', 'max:255'],
            'qdrant_api_key' => ['nullable', 'string', 'max:500'],
            'qdrant_collection' => ['nullable', 'string', 'max:120'],
            'embedding_model' => ['nullable', 'string', 'max:120'],
            'embedding_base_url' => ['nullable', 'url', 'max:255'],
            'embedding_api_key' => ['nullable', 'string', 'max:500'],
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

    public function testVector(): JsonResponse
    {
        $cfg = $this->settings->resolve();
        if (! ($cfg['vector_enabled'] ?? false)) {
            return response()->json([
                'ok' => false,
                'message' => 'Włącz „Wyszukiwanie wektorowe”, zapisz i spróbuj ponownie.',
            ], 422);
        }

        $ping = $this->qdrant->ping();
        if (! ($ping['ok'] ?? false)) {
            return response()->json([
                'ok' => false,
                'message' => $ping['message'] ?? 'Qdrant niedostępny.',
            ], 422);
        }

        $embedOk = null;
        $embedMessage = null;
        try {
            $vector = $this->embeddings->embed('test połączenia embeddings SUPON');
            $embedOk = true;
            $embedMessage = 'Embedding OK (wymiar: '.count($vector).').';
            $this->qdrant->ensureCollection(count($vector));
        } catch (Throwable $e) {
            $embedOk = false;
            $embedMessage = $e->getMessage();
        }

        return response()->json([
            'ok' => $embedOk === true,
            'message' => ($ping['message'] ?? 'Qdrant OK').' '.$embedMessage,
            'qdrant' => $ping,
            'embedding_ok' => $embedOk,
            'settings' => $this->settings->publicView(),
        ], $embedOk === true ? 200 : 422);
    }
}
