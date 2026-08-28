<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\NbpExchangeRateService;
use Illuminate\Http\JsonResponse;

class ExchangeRateController extends Controller
{
    public function __invoke(NbpExchangeRateService $fx): JsonResponse
    {
        return response()->json($fx->snapshot());
    }
}
