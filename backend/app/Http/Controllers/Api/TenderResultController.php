<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tender;
use App\Models\TenderLot;
use App\Services\Tenders\TenderResultService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Wynik przetargu per część zamówienia (GET/PUT /tenders/{tender}/result, DELETE …/result/lots/{lot}).
 * Dostęp do przetargu pilnuje middleware tender.access, edycję — permission:tenders.edit_offer na trasach.
 */
class TenderResultController extends Controller
{
    public function __construct(
        private readonly TenderResultService $results,
    ) {}

    public function show(Request $request, Tender $tender): JsonResponse
    {
        return response()->json($this->results->payload($tender, $request->user()));
    }

    public function update(Request $request, Tender $tender): JsonResponse
    {
        return response()->json($this->results->update($tender, $request->all(), $request->user()));
    }

    public function destroyLot(Request $request, Tender $tender, TenderLot $lot): JsonResponse
    {
        $this->results->deleteLot($tender, $lot, $request->user());

        return response()->json(['ok' => true]);
    }
}
