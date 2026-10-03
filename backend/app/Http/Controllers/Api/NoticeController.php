<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProcurementNotice;
use App\Models\ProcurementNoticeSkip;
use App\Services\Bzp\NoticeListQuery;
use App\Services\Bzp\NoticeTenderCreator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Zakładka „Ogłoszenia”: lista ogłoszeń o zamówieniu z Biuletynu (NoticeListQuery), wspólna decyzja „pominięte”
 * i zakładanie przetargu z ogłoszenia (NoticeTenderCreator). Uprawnienia w trasach: lista i pomijanie —
 * tenders.create albo tenders.view_all; zakładanie przetargu — tenders.create.
 */
class NoticeController extends Controller
{
    public function __construct(
        private readonly NoticeListQuery $list,
        private readonly NoticeTenderCreator $creator,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tab' => ['nullable', Rule::in(NoticeListQuery::TABS)],
            'category' => ['nullable', 'string', Rule::in(array_keys((array) config('bzp.cpv_categories', [])))],
            'province' => ['nullable', 'string', 'max:10'],
            'q' => ['nullable', 'string', 'max:200'],
            'past' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'source' => ['nullable', Rule::in(NoticeListQuery::SOURCES)],
        ]);

        return response()->json($this->list->list($request->user(), [
            'tab' => $data['tab'] ?? null,
            'category' => $data['category'] ?? null,
            'province' => $data['province'] ?? null,
            'q' => $data['q'] ?? null,
            'past' => filter_var($data['past'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'page' => (int) ($data['page'] ?? 1),
            'source' => $data['source'] ?? null,
        ]));
    }

    public function skip(Request $request, ProcurementNotice $notice): JsonResponse
    {
        $this->ensureContractNotice($notice);

        if (! ProcurementNoticeSkip::query()->where('procurement_notice_id', $notice->id)->exists()) {
            try {
                ProcurementNoticeSkip::query()->create([
                    'procurement_notice_id' => $notice->id,
                    'user_id' => $request->user()->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                // ktoś pominął to ogłoszenie w tej samej chwili — decyzja już jest
            }
        }

        return response()->json($this->list->row($notice, $request->user()));
    }

    public function unskip(Request $request, ProcurementNotice $notice): JsonResponse
    {
        $this->ensureContractNotice($notice);

        ProcurementNoticeSkip::query()->where('procurement_notice_id', $notice->id)->delete();

        return response()->json($this->list->row($notice, $request->user()));
    }

    public function createTender(Request $request, ProcurementNotice $notice): JsonResponse
    {
        $this->ensureContractNotice($notice);

        $result = $this->creator->create($notice, $request->user());
        if (! $result['created']) {
            return response()->json([
                'message' => 'Przetarg z tym postępowaniem już jest ('.$result['tender']->number.').',
                'tender_id' => (int) $result['tender']->id,
            ], 409);
        }

        return response()->json(['tender_id' => (int) $result['tender']->id], 201);
    }

    /** Lista i akcje dotyczą tylko ogłoszeń o zamówieniu (ogłoszenie o wyniku → 404). */
    private function ensureContractNotice(ProcurementNotice $notice): void
    {
        abort_if($notice->notice_type !== ProcurementNotice::TYPE_CONTRACT, 404, 'To nie jest ogłoszenie o zamówieniu.');
    }
}
