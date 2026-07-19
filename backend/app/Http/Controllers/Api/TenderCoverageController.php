<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tender;
use App\Services\TenderCoverageService;
use Illuminate\Http\JsonResponse;

class TenderCoverageController extends Controller
{
    public function __construct(
        private readonly TenderCoverageService $coverage,
    ) {}

    public function __invoke(Tender $tender): JsonResponse
    {
        return response()->json($this->coverage->summarize($tender));
    }
}
