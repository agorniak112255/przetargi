<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Services\BattlecardService;
use App\Services\ProductMatchService;
use App\Services\TenderWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TenderMatchController extends Controller
{
    public function __construct(
        private readonly ProductMatchService $matcher,
        private readonly TenderWorkflowService $workflow,
        private readonly BattlecardService $battlecards,
    ) {}

    public function store(Request $request, Tender $tender): JsonResponse
    {
        if (! $this->workflow->canEditOffer($tender)) {
            throw ValidationException::withMessages([
                'tender' => ['Dopasowanie zablokowane — status: '.$tender->status],
            ]);
        }

        $request->validate([
            'only_empty' => ['sometimes', 'boolean'],
            'item_ids' => ['sometimes', 'array', 'max:2000'],
            'item_ids.*' => ['integer'],
            'progress_offset' => ['sometimes', 'integer', 'min:0'],
            'progress_total' => ['sometimes', 'integer', 'min:0'],
        ]);

        $itemIds = $request->has('item_ids') ? array_values($request->input('item_ids', [])) : null;

        if (function_exists('set_time_limit')) {
            @set_time_limit(3600);
        }

        $result = $this->matcher->matchTender(
            $tender,
            $request->boolean('only_empty', true),
            $itemIds,
            (int) $request->input('progress_offset', 0),
            $request->has('progress_total') ? (int) $request->input('progress_total') : null
        );

        return response()->json([
            ...$result,
            'tender_id' => $tender->id,
            'ai_percent' => $tender->fresh()->ai_percent,
        ]);
    }

    public function progress(Tender $tender): JsonResponse
    {
        return response()->json(ProductMatchService::readMatchProgress((int) $tender->id));
    }

    public function matchItem(Request $request, Tender $tender, TenderItem $item): JsonResponse
    {
        if (! $this->workflow->canEditOffer($tender)) {
            throw ValidationException::withMessages([
                'tender' => ['Dopasowanie zablokowane — status: '.$tender->status],
            ]);
        }
        if ((int) $item->tender_id !== (int) $tender->id) {
            abort(404);
        }

        $force = $request->boolean('force', false);
        $result = $this->matcher->matchItem($item, $force);
        $result['battlecard'] = $this->battlecards->forItem($item->fresh(['mainProduct']));

        return response()->json($result);
    }
}
