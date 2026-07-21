<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Services\BattlecardService;
use Illuminate\Http\JsonResponse;

class TenderBattlecardController extends Controller
{
    public function __construct(
        private readonly BattlecardService $battlecards,
    ) {}

    public function show(Tender $tender, TenderItem $item): JsonResponse
    {
        if ((int) $item->tender_id !== (int) $tender->id) {
            abort(404);
        }

        return response()->json([
            'battlecard' => $this->battlecards->forItem($item),
        ]);
    }
}
