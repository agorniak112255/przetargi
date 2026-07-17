<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tender;
use App\Models\TenderCondition;
use App\Services\TenderWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TenderConditionController extends Controller
{
    public function __construct(
        private readonly TenderWorkflowService $workflow,
    ) {}

    public function index(Tender $tender): JsonResponse
    {
        return response()->json([
            'data' => $tender->conditions()->get(),
        ]);
    }

    public function store(Request $request, Tender $tender): JsonResponse
    {
        $this->assertEditable($tender);
        $data = $request->validate([
            'content' => ['required', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:64'],
        ]);

        $sort = (int) ($tender->conditions()->max('sort_order') ?? 0) + 1;
        $condition = TenderCondition::query()->create([
            'tender_id' => $tender->id,
            'sort_order' => $sort,
            'category' => $data['category'] ?? null,
            'content' => $data['content'],
            'source' => 'manual',
        ]);
        $tender->last_activity_at = now();
        $tender->save();

        return response()->json($condition, 201);
    }

    public function update(Request $request, Tender $tender, TenderCondition $condition): JsonResponse
    {
        $this->assertEditable($tender);
        $this->assertOwns($tender, $condition);
        $data = $request->validate([
            'content' => ['sometimes', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:64'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);
        $condition->update($data);
        $tender->last_activity_at = now();
        $tender->save();

        return response()->json($condition->fresh());
    }

    public function destroy(Tender $tender, TenderCondition $condition): JsonResponse
    {
        $this->assertEditable($tender);
        $this->assertOwns($tender, $condition);
        $condition->delete();
        $tender->last_activity_at = now();
        $tender->save();

        return response()->json(['ok' => true]);
    }

    private function assertEditable(Tender $tender): void
    {
        if (! $this->workflow->canEditOffer($tender)) {
            throw ValidationException::withMessages([
                'tender' => ['Edycja zablokowana — status: '.$tender->status],
            ]);
        }
    }

    private function assertOwns(Tender $tender, TenderCondition $condition): void
    {
        if ((int) $condition->tender_id !== (int) $tender->id) {
            abort(404);
        }
    }
}
