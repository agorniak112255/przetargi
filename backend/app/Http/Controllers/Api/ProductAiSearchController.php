<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Ai\AiTask;
use App\Services\ProductAiSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class ProductAiSearchController extends Controller
{
    public function __construct(
        private readonly ProductAiSearchService $search,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'query' => ['required', 'string', 'min:3', 'max:2000'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:80'],
            'web' => ['sometimes', 'boolean'],
        ]);

        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        $webOnly = (bool) ($data['web'] ?? false);

        try {
            $result = $this->search->search(
                (string) $data['query'],
                (int) ($data['limit'] ?? ($webOnly ? 8 : 40)),
                true,
                AiTask::ProductSearch,
                $webOnly,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Błąd wyszukiwania AI: '.$e->getMessage(),
            ], 422);
        }

        return response()->json($result);
    }
}
