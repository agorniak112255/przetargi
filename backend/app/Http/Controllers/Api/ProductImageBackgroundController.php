<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\RemoveProductImageBackgroundJob;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ActivityLogger;
use App\Services\Catalog\ProductImageBackgroundRemover;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Usuwanie tła ze zdjęć kart (09.10.2026): zlecenie dla jednej karty (edycja karty) albo dla zaznaczonych na liście
 * produktów — wszystkie zdjęcia tych kart idą do kolejki `images` (RemoveProductImageBackgroundJob, ~20 s na
 * zdjęcie). Przywrócenie oryginału — od razu. Uprawnienie products.images.background, domyślnie administrator.
 */
final class ProductImageBackgroundController extends Controller
{
    /** Tyle kart najwyżej w jednym zleceniu z listy — 200 kart po kilka zdjęć to już kilka godzin pracy rembg. */
    private const MAX_PRODUCTS = 200;

    public function __construct(private readonly ActivityLogger $activity) {}

    public function queueProduct(Request $request, Product $product): JsonResponse
    {
        [$queued, $skipped] = $this->queue($product->images()->orderBy('sort_order')->orderBy('id')->get());

        $this->activity->log(
            action: 'product.images.background_queued',
            user: $request->user(),
            subject: $product,
            meta: ['label' => 'Usuwanie tła ze zdjęć karty '.$product->sku, 'queued_images' => $queued],
            request: $request,
        );

        return response()->json([
            'message' => $queued > 0
                ? 'Zlecono usunięcie tła: '.$queued.' '.self::photos($queued).' (ok. 20 s na zdjęcie).'
                : 'Nie ma zdjęć do wycięcia — wszystkie są już bez tła albo czekają w kolejce.',
            'queued_images' => $queued,
            'skipped_images' => $skipped,
            'images' => $this->images($product),
        ]);
    }

    public function queueMany(Request $request): JsonResponse
    {
        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_PRODUCTS],
            'product_ids.*' => ['required', 'integer', 'distinct'],
        ]);
        $ids = array_map('intval', $data['product_ids']);
        $images = ProductImage::query()->whereIn('product_id', $ids)->orderBy('product_id')->orderBy('sort_order')->orderBy('id')->get();
        [$queued, $skipped] = $this->queue($images);
        $products = $images->pluck('product_id')->unique()->count();

        $this->activity->log(
            action: 'product.images.background_queued',
            user: $request->user(),
            meta: [
                'label' => 'Usuwanie tła ze zdjęć '.$products.' kart',
                'product_ids' => array_slice($ids, 0, self::MAX_PRODUCTS),
                'queued_images' => $queued,
            ],
            request: $request,
        );

        return response()->json([
            'message' => $queued > 0
                ? 'Zlecono usunięcie tła: '.$queued.' '.self::photos($queued).' z '.$products.' kart (ok. 20 s na zdjęcie).'
                : 'Nie ma zdjęć do wycięcia — zaznaczone karty nie mają zdjęć albo wszystkie są już bez tła lub w kolejce.',
            'queued_images' => $queued,
            'skipped_images' => $skipped,
            'products' => $products,
        ]);
    }

    public function restore(Request $request, Product $product, ProductImage $image, ProductImageBackgroundRemover $remover): JsonResponse
    {
        if ((int) $image->product_id !== (int) $product->id) {
            return response()->json(['message' => 'To zdjęcie nie należy do tej karty.'], 404);
        }
        if (! $remover->restore($image)) {
            return response()->json(['message' => 'To zdjęcie nie ma wyciętego tła — nie ma czego przywracać.'], 422);
        }

        $this->activity->log(
            action: 'product.image.background_restored',
            user: $request->user(),
            subject: $product,
            meta: ['label' => 'Przywrócenie oryginału zdjęcia karty '.$product->sku, 'image_id' => (int) $image->id],
            request: $request,
        );

        return response()->json([
            'message' => 'Przywrócono oryginalne zdjęcie.',
            'images' => $this->images($product),
        ]);
    }

    /**
     * Zdjęcia do kolejki: pomijamy już wycięte i czekające w kolejce.
     *
     * @param  Collection<int, ProductImage>  $images
     * @return array{0: int, 1: int} [w kolejce, pominięte]
     */
    private function queue(Collection $images): array
    {
        $queued = 0;
        $skipped = 0;
        foreach ($images as $image) {
            if ($image->hasBackgroundRemoved() || $image->background_status === ProductImage::BACKGROUND_QUEUED) {
                $skipped++;

                continue;
            }
            $image->forceFill(['background_status' => ProductImage::BACKGROUND_QUEUED, 'background_note' => null])->save();
            RemoveProductImageBackgroundJob::dispatch((int) $image->id);
            $queued++;
        }

        return [$queued, $skipped];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function images(Product $product): array
    {
        return $product->images()->orderBy('sort_order')->orderBy('id')->get()
            ->map(static fn (ProductImage $img): array => $img->panelView())->values()->all();
    }

    private static function photos(int $n): string
    {
        return match (true) {
            $n === 1 => 'zdjęcie',
            $n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20) => 'zdjęcia',
            default => 'zdjęć',
        };
    }
}
