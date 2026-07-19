<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tender;
use App\Models\TenderActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenderActivityController extends Controller
{
    public function index(Request $request, Tender $tender): JsonResponse
    {
        $perPage = min(100, max(10, (int) $request->integer('per_page', 40)));

        $page = TenderActivity::query()
            ->where('tender_id', $tender->id)
            ->with(['user:id,name', 'item:id,line_no'])
            ->latest('id')
            ->paginate($perPage);

        return response()->json($page);
    }
}
