<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdatePrestaCategoryMapsRequest;
use App\Services\Presta\PrestaCategoryMapService;
use App\Services\Presta\PrestaCategorySyncService;
use Illuminate\Http\JsonResponse;
use RuntimeException;

class PrestaCategoryController extends Controller
{
    public function __construct(
        private readonly PrestaCategoryMapService $maps,
        private readonly PrestaCategorySyncService $sync,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json($this->maps->listing());
    }

    public function sync(): JsonResponse
    {
        try {
            $result = $this->sync->sync();
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result + $this->maps->listing());
    }

    public function updateMaps(UpdatePrestaCategoryMapsRequest $request): JsonResponse
    {
        $this->maps->saveMaps($request->validated()['maps']);

        return response()->json($this->maps->listing());
    }
}
