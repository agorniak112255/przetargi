<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DestroyCatalogSearchSiteRequest;
use App\Http\Requests\Admin\ShowCatalogSearchSitePagesRequest;
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

    public function pages(ShowCatalogSearchSitePagesRequest $request, string $host): JsonResponse
    {
        $data = $request->validated();

        return response()->json($this->sites->pages(
            $host,
            (string) ($data['q'] ?? ''),
            (int) ($data['page'] ?? 1),
            (int) ($data['per_page'] ?? 40),
        ));
    }

    public function reindex(string $host): JsonResponse
    {
        return response()->json($this->sites->reindex($host));
    }

    public function unskip(string $host): JsonResponse
    {
        return response()->json($this->sites->unskip($host));
    }

    public function reskip(string $host): JsonResponse
    {
        return response()->json($this->sites->reskip($host));
    }

    public function destroy(DestroyCatalogSearchSiteRequest $request, string $host): JsonResponse
    {
        return response()->json($this->sites->remove($host));
    }
}
