<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePrestaCategoryMapsRequest;
use App\Jobs\RewriteProductCategoriesJob;
use App\Services\Presta\PrestaCategoryMapService;
use App\Services\Presta\PrestaCategoryRewriteService;
use App\Services\Presta\PrestaCategorySyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

class PrestaCategoryController extends Controller
{
    public function __construct(
        private readonly PrestaCategoryMapService $maps,
        private readonly PrestaCategorySyncService $sync,
        private readonly PrestaCategoryRewriteService $rewrite,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function sync(): JsonResponse
    {
        try {
            $result = $this->sync->sync();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result + $this->payload());
    }

    public function updateMaps(UpdatePrestaCategoryMapsRequest $request): JsonResponse
    {
        $this->maps->saveMaps($request->validated()['maps']);
        $applied = $this->maps->applyMappedNames();

        return response()->json($this->payload() + ['applied' => $applied]);
    }

    public function autoMap(): JsonResponse
    {
        $filled = $this->maps->autoFillMaps();

        return response()->json($this->payload() + ['filled' => $filled]);
    }

    public function apply(): JsonResponse
    {
        $applied = $this->maps->applyMappedNames();

        return response()->json($this->payload() + ['applied' => $applied]);
    }

    public function rewrite(): JsonResponse
    {
        if (app()->environment('testing')) {
            $result = $this->rewrite->rewrite();

            return response()->json($result + $this->payload());
        }

        Cache::put(RewriteProductCategoriesJob::CACHE_KEY, ['running' => true], 3600);
        RewriteProductCategoriesJob::dispatch();

        return response()->json($this->payload() + [
            'queued' => true,
            'updated' => 0,
            'cleared' => 0,
            'skipped' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        $status = Cache::get(RewriteProductCategoriesJob::CACHE_KEY);

        return $this->maps->listing() + [
            'rewrite_status' => is_array($status) ? $status : null,
        ];
    }
}
