<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tender;
use App\Models\TenderComment;
use App\Models\TenderItem;
use App\Services\TenderActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenderCommentController extends Controller
{
    public function __construct(
        private readonly TenderActivityLogger $activities,
    ) {}

    public function index(Tender $tender): JsonResponse
    {
        $comments = TenderComment::query()
            ->where('tender_id', $tender->id)
            ->with(['user:id,name,role', 'item:id,line_no'])
            ->latest('id')
            ->get();

        return response()->json(['data' => $comments]);
    }

    public function store(Request $request, Tender $tender): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:4000'],
            'tender_item_id' => ['nullable', 'integer', 'exists:tender_items,id'],
        ]);

        $item = null;
        if (! empty($data['tender_item_id'])) {
            $item = TenderItem::query()
                ->where('tender_id', $tender->id)
                ->where('id', $data['tender_item_id'])
                ->firstOrFail();
        }

        $comment = TenderComment::query()->create([
            'tender_id' => $tender->id,
            'tender_item_id' => $item?->id,
            'user_id' => $request->user()->id,
            'body' => $data['body'],
        ]);

        $this->activities->log($tender, 'comment_added', $request->user(), $item, [
            'comment_id' => $comment->id,
        ]);

        $tender->last_activity_at = now();
        $tender->save();

        return response()->json($comment->load(['user:id,name,role', 'item:id,line_no']), 201);
    }

    public function destroy(Request $request, Tender $tender, TenderComment $comment): JsonResponse
    {
        if ($comment->tender_id !== $tender->id) {
            abort(404);
        }

        $user = $request->user();
        if ((int) $comment->user_id !== (int) $user->id && ! $user->can('admin.access')) {
            abort(403);
        }

        $comment->delete();

        return response()->json(['ok' => true]);
    }
}
