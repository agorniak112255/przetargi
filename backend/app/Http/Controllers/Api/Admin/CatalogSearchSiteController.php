<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreCatalogSearchSiteRequest;
use App\Services\Enrichment\CatalogSearchHostService;
use Illuminate\Http\JsonResponse;

class CatalogSearchSiteController extends Controller
{
    public function __construct(
        private readonly CatalogSearchHostService $sites,
    ) {}

    public function index(): JsonResponse
    {
        $rows = $this->sites->list();
        $withLinks = 0;
        $links = 0;
        foreach ($rows as $row) {
            $links += $row['links'];
            if ($row['links'] > 0) {
                $withLinks++;
            }
        }

        return response()->json([
            'sites' => $rows,
            'total' => count($rows),
            'with_links' => $withLinks,
            'links' => $links,
        ]);
    }

    public function store(StoreCatalogSearchSiteRequest $request): JsonResponse
    {
        $row = $this->sites->add((string) $request->validated()['url']);

        return response()->json($row, 201);
    }
}
