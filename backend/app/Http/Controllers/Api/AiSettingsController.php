<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiModelProfiles;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\AiTask;
use App\Services\Ai\ReasoningEffort;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Vector\EmbeddingClient;
use App\Services\Vector\QdrantClient;
use App\Support\CatalogSlangDictionary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
            'reasoning_effort' => ['sometimes', 'string', 'in:'.implode(',', ReasoningEffort::ALL)],
            'web_search_enabled' => ['sometimes', 'boolean'],
            'tavily_api_key' => ['nullable', 'string', 'max:500'],
            'search_engine' => ['sometimes', 'string', 'in:tavily,duckduckgo,searxng'],
            'searxng_url' => ['nullable', 'url', 'max:255'],
            'search_fallback' => ['sometimes', 'string', 'in:tavily,none'],
            'tavily_search_mode' => ['sometimes', 'string', 'in:eco,balanced,full'],
            'enrichment_batch_limit' => ['sometimes', 'integer', 'min:1'],
            'match_concurrency' => ['sometimes', 'integer', 'min:1', 'max:'.AiSettingsService::CONCURRENCY_MAX],
            'product_search_card_detail' => ['sometimes', 'string', 'in:'.implode(',', AiSettingsService::PRODUCT_SEARCH_CARD_DETAILS)],
            'vector_enabled' => ['sometimes', 'boolean'],
            'qdrant_url' => ['nullable', 'url', 'max:255'],
            'qdrant_api_key' => ['nullable', 'string', 'max:500'],
            'qdrant_collection' => ['nullable', 'string', 'max:120'],
            'embedding_model' => ['nullable', 'string', 'max:120'],
            'embedding_base_url' => ['nullable', 'url', 'max:255'],
            'embedding_api_key' => ['nullable', 'string', 'max:500'],
            'embedding_provider' => ['sometimes', 'string', 'in:'.implode(',', AiSettingsService::EMBEDDING_PROVIDERS)],
            'embedding_cloud_model' => ['nullable', 'string', 'max:120'],
            'embedding_cloud_api_key' => ['nullable', 'string', 'max:500'],
            'model_profiles' => ['sometimes', 'array', 'max:'.AiModelProfiles::MAX_PROFILES],
            'model_profiles.*.id' => ['nullable', 'string', 'max:64'],
            'model_profiles.*.name' => ['nullable', 'string', 'max:120'],
            'model_profiles.*.base_url' => ['nullable', 'string', 'max:255'],
            'model_profiles.*.model' => ['nullable', 'string', 'max:120'],
            'model_profiles.*.api_key' => ['nullable', 'string', 'max:500'],
            'model_profiles.*.timeout_seconds' => ['nullable', 'integer', 'min:10', 'max:600'],
            'model_profiles.*.temperature' => ['nullable', 'numeric', 'min:0', 'max:2'],
            'model_profiles.*.reasoning_effort' => ['nullable', 'string', 'in:'.implode(',', ReasoningEffort::ALL)],
            'model_profiles.*.tasks' => ['nullable', 'array'],
            'model_profiles.*.tasks.*' => ['string', 'in:'.implode(',', AiTask::keys())],
            'catalog_slang' => ['sometimes', 'array', 'max:'.CatalogSlangDictionary::MAX_ENTRIES],
            'catalog_slang.*.category' => ['nullable', 'string', 'in:'.implode(',', CatalogSlangDictionary::CATEGORIES)],
            'catalog_slang.*.terms' => ['required', 'array', 'min:1', 'max:'.CatalogSlangDictionary::MAX_TERMS],
            'catalog_slang.*.terms.*' => ['string', 'max:'.CatalogSlangDictionary::MAX_TERM_LEN],
            'catalog_slang.*.phrases' => ['required', 'array', 'min:1', 'max:'.CatalogSlangDictionary::MAX_PHRASES],
            'catalog_slang.*.phrases.*' => ['string', 'max:'.CatalogSlangDictionary::MAX_PHRASE_LEN],
            'catalog_slang.*.note' => ['nullable', 'string', 'max:'.CatalogSlangDictionary::MAX_NOTE_LEN],
            'catalog_slang.*.jargon' => ['sometimes', 'boolean'],
            'catalog_slang.*.keywords' => ['sometimes', 'array', 'max:'.CatalogSlangDictionary::MAX_KEYWORDS],
            'catalog_slang.*.keywords.*' => ['string', 'max:'.CatalogSlangDictionary::MAX_PHRASE_LEN],
            'catalog_slang.*.tags' => ['sometimes', 'array', 'max:'.CatalogSlangDictionary::MAX_TAGS],
            'catalog_slang.*.tags.*' => ['string', 'max:'.CatalogSlangDictionary::MAX_TERM_LEN],
        ]);

        $this->settings->update($data);

        return response()->json($this->settings->publicView());
    }

    public function test(): JsonResponse
    {
        $results = $this->llm->testConnections();
        $allOk = $results !== [] && array_reduce(
            $results,
            static fn (bool $carry, array $row): bool => $carry && $row['ok'],
            true
        );
        $lines = array_map(
            static fn (array $row): string => ($row['ok'] ? 'Połączono' : 'Brak połączenia')
                .' — '.$row['label']
                .($row['message'] !== '' ? ': '.$row['message'] : ''),
            $results
        );

        return response()->json([
            'ok' => $allOk,
            'message' => implode("\n", $lines),
            'results' => $results,
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
