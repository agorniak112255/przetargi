<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAiTuningRequest;
use App\Services\Ai\AiSettingsService;
use Illuminate\Http\JsonResponse;

class AiTuningController extends Controller
{
    public function __construct(
        private readonly AiSettingsService $settings,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function update(UpdateAiTuningRequest $request): JsonResponse
    {
        // Zapisujemy tylko pola, które faktycznie przyszły — pominięty próg zostaje
        // taki, jak był, zamiast cicho wracać do wartości domyślnej.
        $payload = ['catalog_search_limit' => (int) $request->validated('catalog_search_limit')];
        foreach (['match_apply_score', 'match_substitute_score', 'match_min_score'] as $field) {
            if ($request->has($field)) {
                $payload[$field] = (int) $request->validated($field);
            }
        }
        if ($request->has('match_allow_catalog_rows')) {
            $payload['match_allow_catalog_rows'] = $request->boolean('match_allow_catalog_rows');
        }
        $this->settings->update($payload);

        return response()->json($this->payload());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'catalog_search_limit' => $this->settings->catalogSearchLimit(),
            'default' => AiSettingsService::CATALOG_SEARCH_LIMIT_DEFAULT,
            'min' => 1,
            'max' => AiSettingsService::CATALOG_SEARCH_LIMIT_MAX,
            'match_apply_score' => $this->settings->matchApplyScore(),
            'match_substitute_score' => $this->settings->matchSubstituteScore(),
            'match_min_score' => $this->settings->matchMinScore(),
            'match_allow_catalog_rows' => $this->settings->matchAllowsCatalogRows(),
            'match_defaults' => [
                'apply' => AiSettingsService::MATCH_APPLY_SCORE_DEFAULT,
                'substitute' => AiSettingsService::MATCH_SUBSTITUTE_SCORE_DEFAULT,
                'min' => AiSettingsService::MATCH_MIN_SCORE_DEFAULT,
                'score_min' => AiSettingsService::MATCH_SCORE_MIN,
                'score_max' => AiSettingsService::MATCH_SCORE_MAX,
            ],
        ];
    }
}
