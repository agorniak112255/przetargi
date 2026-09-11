<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductImage;
use App\Models\User;
use App\Services\Vector\ProductEmbeddingIndexer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class ProductDeletionService
{
    public function __construct(
        private readonly ProductEmbeddingIndexer $embeddings,
    ) {}

    /**
     * @param  list<int>  $productIds
     * @return array{
     *     deleted: int,
     *     product_ids_deleted: list<int>
     * }
     */
    public function deleteMany(array $productIds, User $actor): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return [
                'deleted' => 0,
                'product_ids_deleted' => [],
            ];
        }

        return DB::transaction(function () use ($ids, $actor): array {
            $this->deleteStoredFiles($ids);
            $this->deleteEnrichmentCaches($ids);
            foreach ($ids as $productId) {
                $this->embeddings->delete($productId);
            }
            $this->detachFromPriceLists($ids);
            Product::query()->whereIn('id', $ids)->delete();

            Log::info('Products deleted', [
                'actor_id' => $actor->id,
                'actor_email' => $actor->email,
                'deleted' => count($ids),
                'product_ids_deleted' => $ids,
            ]);

            return [
                'deleted' => count($ids),
                'product_ids_deleted' => $ids,
            ];
        });
    }

    /**
     * @param  list<int>  $productIds
     */
    private function deleteStoredFiles(array $productIds): void
    {
        $imagePaths = ProductImage::query()
            ->whereIn('product_id', $productIds)
            ->pluck('path')
            ->all();
        $documentPaths = ProductDocument::query()
            ->whereIn('product_id', $productIds)
            ->pluck('path')
            ->all();

        foreach ([...$imagePaths, ...$documentPaths] as $path) {
            if (! is_string($path) || ! $this->isStoredPath($path)) {
                continue;
            }
            try {
                Storage::disk('public')->delete($path);
            } catch (\Throwable) {
                // plik mógł już nie istnieć
            }
        }
    }

    private function isStoredPath(string $path): bool
    {
        return $path !== ''
            && $path !== 'remote'
            && ! str_starts_with($path, 'http://')
            && ! str_starts_with($path, 'https://');
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

    /**
     * @param  list<int>  $productIds
     */
    private function detachFromPriceLists(array $productIds): void
    {
        $lookup = array_fill_keys($productIds, true);

        foreach (PriceList::query()->whereNotNull('product_ids')->cursor() as $list) {
            $current = is_array($list->product_ids) ? $list->product_ids : [];
            $next = [];
            $changed = false;
            foreach ($current as $rawId) {
                $id = (int) $rawId;
                if (isset($lookup[$id])) {
                    $changed = true;

                    continue;
                }
                $next[] = $id;
            }
            if ($changed) {
                $list->update(['product_ids' => array_values($next)]);
            }
        }
    }
}
