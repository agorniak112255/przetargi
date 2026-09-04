<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RestoreEnrichmentDescriptionTemplateRequest;
use App\Http\Requests\Admin\UpdateEnrichmentDescriptionTemplateRequest;
use App\Services\Enrichment\EnrichmentDescriptionTemplateService;
use App\Support\EnrichmentDescriptionTemplates;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use RuntimeException;

class EnrichmentDescriptionTemplateController extends Controller
{
    public function __construct(
        private readonly EnrichmentDescriptionTemplateService $templates,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'templates' => $this->templates->list(),
            'labels' => EnrichmentDescriptionTemplates::LABELS,
        ]);
    }

    public function update(UpdateEnrichmentDescriptionTemplateRequest $request, string $kategoria): JsonResponse
    {
        try {
            $row = $this->templates->update(
                $kategoria,
                (string) $request->validated('instructions')
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json($row);
    }

    public function restore(RestoreEnrichmentDescriptionTemplateRequest $request, string $kategoria): JsonResponse
    {
        $request->validated();
        try {
            $row = $this->templates->restore($kategoria);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 503);
        }

        return response()->json($row);
    }
}
