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
        $this->settings->update([
            'catalog_search_limit' => $request->validated('catalog_search_limit'),
        ]);

        return response()->json($this->payload());
    }

    /**
     * @return array{catalog_search_limit: int, default: int, min: int, max: int}
     */
    private function payload(): array
    {
        return [
            'catalog_search_limit' => $this->settings->catalogSearchLimit(),
            'default' => AiSettingsService::CATALOG_SEARCH_LIMIT_DEFAULT,
            'min' => 1,
            'max' => AiSettingsService::CATALOG_SEARCH_LIMIT_MAX,
        ];
    }
}
