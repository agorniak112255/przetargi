<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tender;
use App\Models\TenderItem;
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
        ]);

        $result = $this->matcher->matchTender(
            $tender,
            $request->boolean('only_empty', true)
        );

        return response()->json([
            ...$result,
            'tender_id' => $tender->id,
            'ai_percent' => $tender->fresh()->ai_percent,
        ]);
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

        $result = $this->matcher->matchItem($item);

        return response()->json($result);
    }
}
