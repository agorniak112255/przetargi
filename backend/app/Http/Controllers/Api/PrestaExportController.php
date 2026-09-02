<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ExportProductToPrestaJob;
use App\Models\PriceList;
use App\Models\Product;
use App\Services\Presta\PrestaProductExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class PrestaExportController extends Controller
{
    public const SYNC_LIMIT = 20;

    public function __construct(
        private readonly PrestaProductExportService $export,
    ) {}

    public function exportProduct(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'force' => ['sometimes', 'boolean'],
        ]);

        if (function_exists('set_time_limit')) {
            @set_time_limit(180);
        }

        try {
            $result = $this->export->export($product, (bool) ($data['force'] ?? false));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($result);
    }

    public function exportProducts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:400'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'force' => ['sometimes', 'boolean'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $data['product_ids'])));
        $force = (bool) ($data['force'] ?? false);

        if (count($ids) <= self::SYNC_LIMIT) {
            if (function_exists('set_time_limit')) {
                @set_time_limit(500);
            }

            return response()->json($this->export->exportMany($ids, $force));
        }

        foreach ($ids as $id) {
            ExportProductToPrestaJob::dispatch($id, $force);
        }

        return response()->json([
            'queued' => count($ids),
            'exported' => 0,
            'skipped' => 0,
            'failed' => 0,
            'items' => [],
            'errors' => [],
            'product_ids' => $ids,
        ]);
    }

    public function exportPriceList(Request $request, PriceList $priceList): JsonResponse
    {
        $data = $request->validate([
            'force' => ['sometimes', 'boolean'],
        ]);
        $ids = $this->export->priceListProductIds($priceList);
        if ($ids === []) {
            return response()->json(['message' => 'Ten cennik nie ma powiązanych produktów.'], 422);
        }
        $force = (bool) ($data['force'] ?? false);

        if (count($ids) <= self::SYNC_LIMIT) {
            if (function_exists('set_time_limit')) {
                @set_time_limit(500);
            }

            return response()->json($this->export->exportMany($ids, $force) + [
                'queued' => 0,
                'product_ids' => $ids,
            ]);
        }

        foreach ($ids as $id) {
            ExportProductToPrestaJob::dispatch($id, $force);
        }

        return response()->json([
            'queued' => count($ids),
            'exported' => 0,
            'skipped' => 0,
            'failed' => 0,
            'items' => [],
            'errors' => [],
            'product_ids' => $ids,
        ]);
    }
}
