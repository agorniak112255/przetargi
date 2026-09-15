<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductImage;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\Pricing\ProductEffectivePrice;
use App\Services\Vector\ProductEmbeddingIndexer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class PriceListDeletionService
{
    public function __construct(
        private readonly ProductEmbeddingIndexer $embeddings,
        private readonly ProductEffectivePrice $effectivePrices,
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
            // karta z powiązaniem B2B zostaje (ma cenę z B2B), nawet gdy wpis konta nie ma jej w product_ids
            $shared = array_values(array_unique([
                ...$this->productIdsReferencedByOtherPriceLists($priceList->id, $ids),
                ...$this->productIdsLinkedToB2b($ids),
            ]));
            $toDelete = array_values(array_diff($ids, $shared));

            if ($toDelete !== []) {
                $this->deleteProductFiles($toDelete);
                $this->deleteEnrichmentCaches($toDelete);
                foreach ($toDelete as $productId) {
                    $this->embeddings->delete($productId);
                }
                // sloty cen kasowanych kart znikają kaskadą
                Product::query()->whereIn('id', $toDelete)->delete();
            }

            $this->deleteFileSlotsOfPriceList($priceList->id, $toDelete);

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
     * @return list<int>
     */
    private function productIdsLinkedToB2b(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return B2bProductLink::query()
            ->whereIn('product_id', $productIds)
            ->distinct()
            ->pluck('product_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Karty, które zostają: znika tylko slot ceny z pliku pochodzący z usuwanego cennika (slot z nowszego cennika
     * zostaje). deleteSlot przelicza cenę obowiązującą — z B2B, a bez innych slotów cena karty zostaje bez zmian.
     * Po price_list_id slotu, nie po product_ids — sloty przeniesione przy scalaniu rozmiarów też się liczą.
     *
     * @param  list<int>  $deletedProductIds
     */
    private function deleteFileSlotsOfPriceList(int $priceListId, array $deletedProductIds): void
    {
        $productIds = ProductSourcePrice::query()
            ->where('source_key', ProductSourcePrice::SOURCE_FILE)
            ->where('price_list_id', $priceListId)
            ->whereNotIn('product_id', $deletedProductIds)
            ->pluck('product_id')
            ->all();

        foreach (Product::query()->whereIn('id', $productIds)->get() as $product) {
            $this->effectivePrices->deleteSlot($product, ProductSourcePrice::SOURCE_FILE);
        }
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
