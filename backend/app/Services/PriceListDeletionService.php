<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Vector\ProductEmbeddingIndexer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class PriceListDeletionService
{
    public function __construct(
        private readonly ProductEmbeddingIndexer $embeddings,
    ) {}

    /**
     * Usuwa cennik oraz produkty, które nie występują w innych importach.
     *
     * @return array{
     *     deleted_price_list_id: int,
     *     manufacturer: string,
     *     version: string,
     *     products_deleted: int,
     *     products_kept_shared: int,
     *     product_ids_deleted: list<int>
     * }
     */
    public function delete(PriceList $priceList, User $actor): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $priceList->product_ids ?? []),
            static fn (int $id): bool => $id > 0
        )));

        return DB::transaction(function () use ($priceList, $actor, $ids): array {
            $shared = $this->productIdsReferencedByOtherPriceLists($priceList->id, $ids);
            $toDelete = array_values(array_diff($ids, $shared));

            if ($toDelete !== []) {
                $this->deleteProductFiles($toDelete);
                $this->deleteEnrichmentCaches($toDelete);
                foreach ($toDelete as $productId) {
                    $this->embeddings->delete($productId);
                }
                Product::query()->whereIn('id', $toDelete)->delete();
            }

            $meta = [
                'deleted_price_list_id' => $priceList->id,
                'manufacturer' => (string) $priceList->manufacturer,
                'version' => (string) $priceList->version,
                'original_filename' => $priceList->original_filename,
                'products_deleted' => count($toDelete),
                'products_kept_shared' => count($shared),
                'product_ids_deleted' => $toDelete,
            ];

            $priceList->delete();

            Log::info('Price list deleted', [
                'actor_id' => $actor->id,
                'actor_email' => $actor->email,
                ...$meta,
            ]);

            return $meta;
        });
    }

    /**
     * @param  list<int>  $productIds
     * @return list<int>
     */
    private function productIdsReferencedByOtherPriceLists(int $priceListId, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $shared = [];
        $others = PriceList::query()
            ->where('id', '!=', $priceListId)
            ->whereNotNull('product_ids')
            ->get(['id', 'product_ids']);

        $lookup = array_fill_keys($productIds, true);

        foreach ($others as $other) {
            foreach ($other->product_ids ?? [] as $rawId) {
                $id = (int) $rawId;
                if (isset($lookup[$id])) {
                    $shared[$id] = true;
                }
            }
        }

        return array_map('intval', array_keys($shared));
    }

    /**
     * @param  list<int>  $productIds
     */
    private function deleteProductFiles(array $productIds): void
    {
        $paths = ProductImage::query()
            ->whereIn('product_id', $productIds)
            ->pluck('path')
            ->filter(static fn ($p): bool => is_string($p) && $p !== '')
            ->unique()
            ->values()
            ->all();

        foreach ($paths as $path) {
            try {
                Storage::disk('public')->delete($path);
            } catch (\Throwable) {
                // plik mógł już nie istnieć
            }
        }
    }

    /**
     * @param  list<int>  $productIds
     */
    private function deleteEnrichmentCaches(array $productIds): void
    {
        $keys = Product::query()
            ->whereIn('id', $productIds)
            ->get(['manufacturer', 'sku']);

        foreach ($keys as $product) {
            $key = ProductEnrichmentCache::normalizeKey(
                (string) $product->manufacturer,
                (string) $product->sku,
            );
            ProductEnrichmentCache::query()
                ->where('manufacturer', $key['manufacturer'])
                ->where('sku', $key['sku'])
                ->delete();
        }
    }
}
