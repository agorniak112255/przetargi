<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PriceList;
use App\Services\PriceListDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class PriceListController extends Controller
{
    public function __construct(
        private readonly PriceListDeletionService $deletion,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json(
            PriceList::query()
                ->with('importer:id,name')
                ->latest()
                ->get()
        );
    }

    public function show(PriceList $priceList): JsonResponse
    {
        return response()->json($priceList->load('importer:id,name'));
    }

    public function destroy(Request $request, PriceList $priceList): JsonResponse
    {
        try {
            $result = $this->deletion->delete($priceList, $request->user());
        } catch (Throwable $e) {
            return response()->json([
                'message' => 'Nie udało się usunąć cennika: '.$e->getMessage(),
            ], 422);
        }

        return response()->json([
            'message' => sprintf(
                'Usunięto cennik %s / %s. Produktów usuniętych: %d%s.',
                $result['manufacturer'],
                $result['version'],
                $result['products_deleted'],
                $result['products_kept_shared'] > 0
                    ? ', zachowanych (w innych cennikach): '.$result['products_kept_shared']
                    : ''
            ),
            ...$result,
        ]);
    }
}
