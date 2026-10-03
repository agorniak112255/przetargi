<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ProcurementNotice;
use App\Models\ProcurementNoticeSkip;
use App\Services\Bzp\NoticeClientAmbiguousException;
use App\Services\Bzp\NoticeListQuery;
use App\Services\Bzp\NoticeTenderCreator;
use App\Services\TenderAccessService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Zakładka „Ogłoszenia”: lista ogłoszeń o zamówieniu z Biuletynu (NoticeListQuery), wspólna decyzja „pominięte”
 * i zakładanie przetargu z ogłoszenia (NoticeTenderCreator). Uprawnienia w trasach: lista i pomijanie —
 * tenders.create albo tenders.view_all; zakładanie przetargu — tenders.create.
 * „Pominięte” dotyczy postępowania (bzp_number), nie wersji ogłoszenia — nowa wersja nie wraca do „Nowe”.
 */
class NoticeController extends Controller
{
    public function __construct(
        private readonly NoticeListQuery $list,
        private readonly NoticeTenderCreator $creator,
        private readonly TenderAccessService $access,
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

        if (! ProcurementNoticeSkip::query()->where('bzp_number', $notice->bzp_number)->exists()) {
            try {
                ProcurementNoticeSkip::query()->create([
                    'bzp_number' => $notice->bzp_number,
                    'procurement_notice_id' => $notice->id,
                    'user_id' => $request->user()->id,
                ]);
            } catch (UniqueConstraintViolationException) {
                // ktoś pominął to postępowanie w tej samej chwili — decyzja już jest
            }
        }

        return response()->json($this->list->row($notice, $request->user()));
    }

    public function unskip(Request $request, ProcurementNotice $notice): JsonResponse
    {
        $this->ensureContractNotice($notice);

        ProcurementNoticeSkip::query()->where('bzp_number', $notice->bzp_number)->delete();

        return response()->json($this->list->row($notice, $request->user()));
    }

    /**
     * Body: client_id (opcjonalnie) — zamawiający wybrany przez człowieka, dowolny istniejący klient. Bez niego przy
     * kilku pasujących klientach — 422 z listą client_candidates (nic nie powstaje).
     */
    public function createTender(Request $request, ProcurementNotice $notice): JsonResponse
    {
        $this->ensureContractNotice($notice);
        $data = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
        ]);
        $chosen = isset($data['client_id']) ? Client::query()->findOrFail((int) $data['client_id']) : null;

        try {
            $result = $this->creator->create($notice, $request->user(), $chosen);
        } catch (NoticeClientAmbiguousException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['client_id' => [$e->getMessage()]],
                'client_candidates' => $e->candidates,
            ], 422);
        } catch (LockTimeoutException) {
            return response()->json([
                'message' => 'Ktoś inny zakłada w tej chwili przetarg z ogłoszenia. Spróbuj ponownie za kilka sekund.',
            ], 423);
        }
        if (! $result['created']) {
            return response()->json([
                'message' => 'Przetarg z tym postępowaniem już jest ('.$result['tender']->number.').',
                'tender_id' => (int) $result['tender']->id,
                'tender_number' => (string) $result['tender']->number,
                // bez dostępu do przetargu front pokazuje sam numer zamiast przejścia
                'can_open' => $this->access->canView($request->user(), $result['tender']),
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
