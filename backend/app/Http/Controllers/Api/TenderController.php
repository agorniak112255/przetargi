<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProductSubstitute;
use App\Models\Tender;
use App\Services\TenderWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenderController extends Controller
{
    public function __construct(
        private readonly TenderWorkflowService $workflow,
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

        return response()->json($query->orderByDesc('last_activity_at')->get());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'client_id' => ['required', 'exists:clients,id'],
            'deadline' => ['nullable', 'date'],
            'number' => ['nullable', 'string', 'max:50', 'unique:tenders,number'],
        ]);

        $year = (int) now()->format('Y');
        $seq = Tender::query()->where('number', 'like', "PRZ/{$year}/%")->count() + 1;
        $number = $data['number'] ?? sprintf('PRZ/%d/%04d', $year, $seq);

        $tender = Tender::query()->create([
            'number' => $number,
            'title' => $data['title'],
            'client_id' => $data['client_id'],
            'owner_id' => $request->user()->id,
            'deadline' => $data['deadline'] ?? null,
            'status' => 'draft',
            'ai_percent' => 0,
            'last_activity_at' => now(),
        ]);

        return response()->json(
            $tender->load(['client:id,name', 'owner:id,name'])->loadCount('items'),
            201
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
        ]);
    }

    public function transition(Request $request, Tender $tender): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $tender = $this->workflow->transition(
            $tender,
            $data['status'],
            $request->user(),
            $data['note'] ?? null
        );

        return response()->json([
            'tender' => $tender->load([
                'client',
                'owner:id,name,role',
                'items.mainProduct',
                'statusHistories.user:id,name,role',
            ]),
            'can_edit' => $this->workflow->canEditOffer($tender),
            'next_statuses' => $this->workflow->nextStatusesFor($tender, $request->user()),
        ]);
    }
}
