<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Services\BattlecardService;
use App\Services\ProductMatchService;
use App\Services\TenderActivityLogger;
use App\Services\TenderPricingService;
use App\Services\TenderWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TenderItemController extends Controller
{
    public function __construct(
        private readonly TenderPricingService $pricing,
        private readonly TenderWorkflowService $workflow,
        private readonly TenderActivityLogger $activities,
        private readonly ProductMatchService $matcher,
        private readonly BattlecardService $battlecards,
    ) {}

    /**
     * Zastosuj najtańszy zamiennik (po upuście) na pozycjach, gdzie oszczędność ≥ próg.
     */
    public function applyCheaperSubstitutes(Request $request, Tender $tender): JsonResponse
    {
        if (! $this->workflow->canEditOffer($tender)) {
            throw ValidationException::withMessages([
                'tender' => ['Oferta zablokowana — status: '.$tender->status],
            ]);
        }

        $data = $request->validate([
            'min_save_percent' => ['sometimes', 'numeric', 'min:1', 'max:80'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);
        $minSave = (float) ($data['min_save_percent'] ?? 3);
        $dryRun = (bool) ($data['dry_run'] ?? false);

        $tender->load(['items.mainProduct']);
        $applied = [];
        $candidates = [];

        foreach ($tender->items as $item) {
            $pick = $this->battlecards->bestCheaperSubstitute($item, $minSave);
            if ($pick === null) {
                continue;
            }
            $fromSku = $item->mainProduct?->sku;
            $candidates[] = [
                'item_id' => $item->id,
                'line_no' => $item->line_no,
                'from_sku' => $fromSku,
                'to_sku' => $pick['sku'],
                'to_product_id' => $pick['product_id'],
                'save_percent' => $pick['save_percent'],
                'purchase_price' => $pick['purchase_price'],
            ];

            if ($dryRun) {
                continue;
            }

            $before = [
                'main_product_id' => $item->main_product_id,
                'offer_price' => $item->offer_price,
            ];
            $product = Product::query()->find($pick['product_id']);
            if ($product === null) {
                continue;
            }

            $item->main_product_id = $product->id;
            $item->offer_price = round((float) $product->purchase_price * 1.18, 2);
            $item->status = 'matched';
            $item->match_source = 'battlecard';
            $item->ai_match_percent = $pick['match_percent'];
            $item->ai_match_reasons = [
                [
                    'code' => 'battlecard_batch',
                    'label' => sprintf(
                        'Zastosowano tańszy zamiennik %s (−%.0f%% po upuście)',
                        $pick['sku'],
                        $pick['save_percent'],
                    ),
                    'points' => $pick['match_percent'],
                ],
            ];
            $item->save();
            $item->load('mainProduct');
            $this->pricing->recalculateItemMargin($item);
            $this->activities->log($tender, 'item_updated', $request->user(), $item, [
                'before' => $before,
                'after' => [
                    'main_product_id' => $item->main_product_id,
                    'offer_price' => $item->offer_price,
                    'match_source' => $item->match_source,
                ],
                'batch' => 'cheaper_substitutes',
            ]);
            $applied[] = [
                'item_id' => $item->id,
                'line_no' => $item->line_no,
                'from_sku' => $fromSku,
                'to_sku' => $pick['sku'],
                'save_percent' => $pick['save_percent'],
                'offer_price' => $item->offer_price,
            ];
        }

        if (! $dryRun && $applied !== []) {
            $this->pricing->recalculateTenderTotals($tender->fresh());
            $tender->last_activity_at = now();
            $tender->save();
        }

        return response()->json([
            'dry_run' => $dryRun,
            'min_save_percent' => $minSave,
            'candidates_count' => count($candidates),
            'applied_count' => count($applied),
            'candidates' => $candidates,
            'applied' => $applied,
        ]);
    }

    public function bulkUpdate(Request $request, Tender $tender): JsonResponse
    {
        if (! $this->workflow->canEditOffer($tender)) {
            throw ValidationException::withMessages([
                'tender' => ['Oferta zablokowana — status: '.$tender->status],
            ]);
        }

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.main_product_id' => ['nullable', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.offer_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $updated = 0;
        DB::transaction(function () use ($tender, $data, $request, &$updated): void {
            foreach ($data['items'] as $row) {
                $item = TenderItem::query()
                    ->where('tender_id', $tender->id)
                    ->where('id', $row['id'])
                    ->first();
                if ($item === null) {
                    continue;
                }

                $before = [
                    'main_product_id' => $item->main_product_id,
                    'quantity' => $item->quantity,
                    'offer_price' => $item->offer_price,
                ];

                $item->main_product_id = $row['main_product_id'] ?? null;
                $item->quantity = (int) $row['quantity'];
                $item->offer_price = array_key_exists('offer_price', $row) ? $row['offer_price'] : $item->offer_price;
                if ($item->main_product_id !== null) {
                    $item->status = 'matched';
                }
                $item->save();
                $item->load('mainProduct');
                $this->pricing->recalculateItemMargin($item);
                $this->activities->log($tender, 'item_bulk_updated', $request->user(), $item, [
                    'before' => $before,
                    'after' => [
                        'main_product_id' => $item->main_product_id,
                        'quantity' => $item->quantity,
                        'offer_price' => $item->offer_price,
                    ],
                ]);
                $updated++;
            }
        });

        $this->pricing->recalculateTenderTotals($tender->fresh());
        $tender->last_activity_at = now();
        $tender->save();

        return response()->json([
            'updated' => $updated,
            'tender_id' => $tender->id,
        ]);
    }

    public function update(Request $request, Tender $tender, TenderItem $item): JsonResponse
    {
        if ($item->tender_id !== $tender->id) {
            abort(404);
        }

        if (! $this->workflow->canEditOffer($tender)) {
            throw ValidationException::withMessages([
                'tender' => ['Oferta zablokowana — status: '.$tender->status],
            ]);
        }

        $data = $request->validate([
            'main_product_id' => ['sometimes', 'nullable', 'exists:products,id'],
            'quantity' => ['sometimes', 'integer', 'min:1'],
            'offer_price' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'requirement' => ['sometimes', 'string', 'max:2000'],
            'status' => ['sometimes', 'string', 'max:32'],
            'ai_match_percent' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'ai_match_reasons' => ['sometimes', 'nullable', 'array'],
            'match_source' => ['sometimes', 'nullable', 'string', 'max:32'],
        ]);

        $before = [
            'main_product_id' => $item->main_product_id,
            'quantity' => $item->quantity,
            'offer_price' => $item->offer_price,
            'ai_match_percent' => $item->ai_match_percent,
        ];

        if (array_key_exists('main_product_id', $data)) {
            $item->main_product_id = $data['main_product_id'];
            if ($data['main_product_id'] !== null && ! array_key_exists('offer_price', $data)) {
                $product = Product::query()->find($data['main_product_id']);
                if ($product !== null && (float) $product->purchase_price > 0) {
                    $item->offer_price = round((float) $product->purchase_price * 1.18, 2);
                }
                if (! array_key_exists('ai_match_reasons', $data) && $product !== null) {
                    $explained = $this->matcher->explainMatch($item->requirement, $product);
                    $item->ai_match_reasons = $explained['reasons'];
                    if (! array_key_exists('ai_match_percent', $data)) {
                        $item->ai_match_percent = $explained['score'];
                    }
                    if (! array_key_exists('match_source', $data)) {
                        $item->match_source = 'manual';
                    }
                }
            }
            if ($data['main_product_id'] === null) {
                $item->ai_match_reasons = null;
                $item->match_source = null;
            }
        }

        if (array_key_exists('quantity', $data)) {
            $item->quantity = $data['quantity'];
        }
        if (array_key_exists('offer_price', $data)) {
            $item->offer_price = $data['offer_price'];
        }
        if (array_key_exists('requirement', $data)) {
            $item->requirement = $data['requirement'];
        }
        if (array_key_exists('status', $data)) {
            $item->status = $data['status'];
        }
        if (array_key_exists('ai_match_percent', $data)) {
            $item->ai_match_percent = $data['ai_match_percent'];
        }
        if (array_key_exists('ai_match_reasons', $data)) {
            $item->ai_match_reasons = $data['ai_match_reasons'];
        }
        if (array_key_exists('match_source', $data)) {
            $item->match_source = $data['match_source'];
        }

        $item->save();
        $item->load('mainProduct');
        $this->pricing->recalculateItemMargin($item);
        $this->pricing->recalculateTenderTotals($tender->fresh());

        $this->activities->log($tender, 'item_updated', $request->user(), $item, [
            'before' => $before,
            'after' => [
                'main_product_id' => $item->main_product_id,
                'quantity' => $item->quantity,
                'offer_price' => $item->offer_price,
                'ai_match_percent' => $item->ai_match_percent,
                'match_source' => $item->match_source,
            ],
        ]);

        return response()->json($item->fresh('mainProduct'));
    }
}
