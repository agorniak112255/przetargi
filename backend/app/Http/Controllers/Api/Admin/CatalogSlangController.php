<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateCatalogSlangRequest;
use App\Services\Ai\AiSettingsService;
use App\Support\CatalogSlangDictionary;
use Illuminate\Http\JsonResponse;

class CatalogSlangController extends Controller
{
    public function __construct(
        private readonly AiSettingsService $settings,
    ) {}

    public function show(): JsonResponse
    {
        $view = $this->settings->publicView();

        return response()->json([
            'entries' => $view['catalog_slang'] ?? [],
            'defaults' => CatalogSlangDictionary::defaults(),
            'categories' => CatalogSlangDictionary::CATEGORY_LABELS,
        ]);
    }

    public function update(UpdateCatalogSlangRequest $request): JsonResponse
    {
        $this->settings->update([
            'catalog_slang' => $request->validated('catalog_slang'),
        ]);

        return $this->show();
    }
}
