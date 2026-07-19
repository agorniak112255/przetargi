<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductSubstitute;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Services\ProductMatchService;
use App\Services\TenderActivityLogger;
use App\Services\TenderCoverageService;
use App\Services\TenderWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenderController extends Controller
{
    public function __construct(
        private readonly TenderWorkflowService $workflow,
        private readonly TenderCoverageService $coverage,
        private readonly TenderActivityLogger $activities,
        private readonly ProductMatchService $matcher,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = Tender::query()
            ->with(['client:id,name', 'owner:id,name'])
            ->withCount('items');

        if (! $request->user()->can('tenders.view_all')) {
            $query->where('owner_id', $request->user()->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $filter = (string) $request->input('filter', '');
        if ($filter === 'mine') {
            $query->where('owner_id', $request->user()->id);
        }
        if ($filter === 'unassigned') {
            $query->whereNull('owner_id');
        }
        if ($filter === 'deadline_soon') {
            $query->whereNotNull('deadline')
                ->whereDate('deadline', '<=', now()->addDays(7))
                ->whereDate('deadline', '>=', now()->toDateString())
                ->whereNotIn('status', ['archiwum', 'exported', 'odrzucony']);
        }
        if ($request->filled('owner_id')) {
            $query->where('owner_id', (int) $request->integer('owner_id'));
        }

        return response()->json($query->orderByDesc('last_activity_at')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'client_id' => ['required', 'exists:clients,id'],
            'deadline' => ['nullable', 'date'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'number' => ['nullable', 'string', 'max:50', 'unique:tenders,number'],
        ]);

        $year = (int) now()->format('Y');
        $seq = Tender::query()->where('number', 'like', "PRZ/{$year}/%")->count() + 1;
        $number = $data['number'] ?? sprintf('PRZ/%d/%04d', $year, $seq);

        $tender = Tender::query()->create([
            'number' => $number,
            'title' => $data['title'],
            'client_id' => $data['client_id'],
            'owner_id' => $data['owner_id'] ?? $request->user()->id,
            'deadline' => $data['deadline'] ?? null,
            'status' => 'draft',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);

        $this->activities->log($tender, 'created', $request->user(), null, [
            'title' => $tender->title,
        ]);

        return response()->json(
            $tender->load(['client:id,name', 'owner:id,name'])->loadCount('items'),
            201
        );
    }

    public function update(Request $request, Tender $tender): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'deadline' => ['sometimes', 'nullable', 'date'],
            'owner_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'client_id' => ['sometimes', 'integer', 'exists:clients,id'],
        ]);

        $before = [
            'title' => $tender->title,
            'deadline' => $tender->deadline?->format('Y-m-d'),
            'owner_id' => $tender->owner_id,
            'client_id' => $tender->client_id,
        ];

        if (array_key_exists('title', $data)) {
            $tender->title = $data['title'];
        }
        if (array_key_exists('deadline', $data)) {
            $tender->deadline = $data['deadline'];
        }
        if (array_key_exists('owner_id', $data)) {
            $tender->owner_id = $data['owner_id'];
        }
        if (array_key_exists('client_id', $data)) {
            $tender->client_id = $data['client_id'];
        }

        $tender->last_activity_at = now();
        $tender->save();

        $this->activities->log($tender, 'updated', $request->user(), null, [
            'before' => $before,
            'after' => [
                'title' => $tender->title,
                'deadline' => $tender->deadline?->format('Y-m-d'),
                'owner_id' => $tender->owner_id,
                'client_id' => $tender->client_id,
            ],
        ]);

        return response()->json(
            $tender->fresh()->load(['client:id,name', 'owner:id,name'])->loadCount('items')
        );
    }

    public function show(Request $request, Tender $tender): JsonResponse
    {
        $tender->load([
            'client',
            'owner:id,name,role',
            'items.mainProduct',
            'conditions',
            'statusHistories.user:id,name,role',
        ]);

        // stare pozycje (sprzed feature) nie mają ai_match_reasons — dolicz i zapisz
        foreach ($tender->items as $item) {
            /** @var TenderItem $item */
            if ($item->main_product_id === null || $item->mainProduct === null) {
                continue;
            }
            if (is_array($item->ai_match_reasons) && $item->ai_match_reasons !== []) {
                continue;
            }
            $explained = $this->matcher->explainMatch($item->requirement, $item->mainProduct);
            $item->ai_match_reasons = $explained['reasons'];
            if ($item->match_source === null) {
                $item->match_source = 'heuristic';
            }
            $item->saveQuietly();
        }

        $tender->setRelation(
            'documents',
            $tender->documents()
                ->with('uploader:id,name')
                ->get([
                    'id',
                    'tender_id',
                    'uploaded_by',
                    'original_name',
                    'extension',
                    'size_bytes',
                    'mode',
                    'targets',
                    'disk_path',
                    'created_at',
                ])
                ->map(static function ($d) {
                    $d->setAttribute('has_file', $d->disk_path !== null);
                    unset($d->disk_path);

                    return $d;
                })
        );

        $mainIds = $tender->items
            ->pluck('main_product_id')
            ->filter()
            ->unique()
            ->values();

        $substitutes = ProductSubstitute::query()
            ->with([
                'mainProduct:id,sku,name',
                'substituteProduct:id,sku,name,catalog_price_net,stock',
                'approver:id,name',
            ])
            ->whereIn('main_product_id', $mainIds)
            ->get()
            ->groupBy('main_product_id');

        return response()->json([
            'tender' => $tender,
            'substitutes_by_main' => $substitutes,
            'can_edit' => $this->workflow->canEditOffer($tender),
            'next_statuses' => $this->workflow->nextStatusesFor($tender, $request->user()),
            'coverage' => $this->coverage->summarize($tender),
        ]);
    }

    public function transition(Request $request, Tender $tender): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $from = $tender->status;
        $tender = $this->workflow->transition(
            $tender,
            $data['status'],
            $request->user(),
            $data['note'] ?? null
        );

        $this->activities->log($tender, 'status_changed', $request->user(), null, [
            'from' => $from,
            'to' => $tender->status,
            'note' => $data['note'] ?? null,
        ]);

        return response()->json([
            'tender' => $tender->load([
                'client',
                'owner:id,name,role',
                'items.mainProduct',
                'statusHistories.user:id,name,role',
            ]),
            'can_edit' => $this->workflow->canEditOffer($tender),
            'next_statuses' => $this->workflow->nextStatusesFor($tender, $request->user()),
            'coverage' => $this->coverage->summarize($tender),
        ]);
    }
}
