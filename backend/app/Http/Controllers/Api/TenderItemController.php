<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Tender;
use App\Models\TenderItem;
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
    ) {}

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
        DB::transaction(function () use ($tender, $data, &$updated): void {
            foreach ($data['items'] as $row) {
                $item = TenderItem::query()
                    ->where('tender_id', $tender->id)
                    ->where('id', $row['id'])
                    ->first();
                if ($item === null) {
                    continue;
                }

                $item->main_product_id = $row['main_product_id'] ?? null;
                $item->quantity = (int) $row['quantity'];
                $item->offer_price = array_key_exists('offer_price', $row) ? $row['offer_price'] : $item->offer_price;
                if ($item->main_product_id !== null) {
                    $item->status = 'matched';
                }
                $item->save();
                $item->load('mainProduct');
                $this->pricing->recalculateItemMargin($item);
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
        ]);

        if (array_key_exists('main_product_id', $data)) {
            $item->main_product_id = $data['main_product_id'];
            if ($data['main_product_id'] !== null && ! array_key_exists('offer_price', $data)) {
                $product = Product::query()->find($data['main_product_id']);
                if ($product !== null && (float) $product->purchase_price > 0) {
                    // sugerowana oferta ~ +18% od zakupu
                    $item->offer_price = round((float) $product->purchase_price * 1.18, 2);
                }
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

        $item->save();
        $item->load('mainProduct');
        $this->pricing->recalculateItemMargin($item);
        $this->pricing->recalculateTenderTotals($tender->fresh());

        return response()->json($item->fresh('mainProduct'));
    }
}
