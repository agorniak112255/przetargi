<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductImageRejection;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ręczne usunięcie zdjęcia z karty (zgłoszenie testującego 23.09.2026: na kartach ARTRA zostały zdjęcia innego
 * wariantu z pierwszego pobierania, a reguły audytu nie łapią każdego przypadku).
 *
 * Usunięcie zostawia ślad odrzucenia (ProductImageRejection), więc synchronizacja dostawcy ani ponowne
 * wzbogacanie nie przywrócą tego zdjęcia. Uprawnienie jak przy usuwaniu produktów.
 */
final class ProductImageController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function destroy(Request $request, Product $product, ProductImage $image): JsonResponse
    {
        if ((int) $image->product_id !== (int) $product->id) {
            return response()->json(['message' => 'To zdjęcie nie należy do tej karty.'], 404);
        }

        $sourceUrl = (string) ($image->source_url ?? '');
        $accountId = $image->b2b_account_id;
        ProductImageRejection::rejectAndDelete($image, ProductImageRejection::REASON_MANUAL, $request->user()?->id);

        $this->activity->log(
            action: 'product.image.deleted',
            user: $request->user(),
            subject: $product,
            meta: [
                'label' => 'Usunięcie zdjęcia karty '.$product->sku,
                'image_id' => (int) $image->id,
                'source_url' => $sourceUrl,
                'b2b_account_id' => $accountId,
            ],
            request: $request,
        );

        return response()->json([
            'message' => 'Usunięto zdjęcie. Synchronizacja i wzbogacanie nie dodadzą go ponownie.',
            'images' => $product->images()->orderBy('sort_order')->orderBy('id')->get()
                ->map(static fn (ProductImage $img): array => [
                    'id' => $img->id,
                    'url' => $img->url(),
                    'thumb_url' => $img->thumbUrl(),
                    'source_url' => $img->source_url,
                    'is_primary' => $img->is_primary,
                    'sort_order' => $img->sort_order,
                ])->values()->all(),
        ]);
    }
}
