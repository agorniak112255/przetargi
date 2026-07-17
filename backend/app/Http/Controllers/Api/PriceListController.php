<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PriceList;
use Illuminate\Http\JsonResponse;

class PriceListController extends Controller
{
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
}
