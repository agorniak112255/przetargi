<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\PriceListImport;
use App\Models\Product;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\Pricing\ProductEffectivePrice;
use App\Services\Vector\ProductEmbeddingIndexer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class PriceListDeletionService
{
    public function __construct(
        private readonly ProductEmbeddingIndexer $embeddings,
        private readonly ProductEffectivePrice $effectivePrices,
        private readonly ProductStoredFiles $files,
    ) {}

    /**
     * Cofa jedną aktualizację: znikają karty, które weszły tym przebiegiem i których nie przyniósł żaden
     * inny. Cennik producenta zostaje — razem z cenami kart, które przetrwały. To jest łagodna operacja,
     * którą dawniej robiło „usuń cennik”, kiedy każdy import miał własny wpis.
     *
     * Cen kart, które zostają, nie cofamy: poprzednie wartości są w historii ceny, a ciche przywracanie
     * ich przy usuwaniu wpisu byłoby zmianą cennika bez śladu, kto i kiedy ją zrobił.
     *
     * @return array{
     *     undone_import_id: int,
     *     manufacturer: string,
     *     version: string,
     *     products_deleted: int,
     *     products_kept_shared: int,
     *     product_ids_deleted: list<int>
     * }
     */
    public function undoImport(PriceListImport $import, User $actor): array
    {
        $priceList = $import->priceList;
        $ids = $this->productIdsOf($import->product_ids ?? []);

        return DB::transaction(function () use ($import, $priceList, $actor, $ids): array {
            $shared = array_values(array_unique([
                ...$this->productIdsReferencedByOtherImports((int) $import->id, $ids),
                ...$this->productIdsLinkedToB2b($ids),
            ]));
            $toDelete = array_values(array_diff($ids, $shared));

            if ($toDelete !== []) {
                // ścieżki przed usunięciem kart — kaskada kasuje wiersze zdjęć i dokumentów; pliki znikają po commit
                $paths = $this->files->pathsOf($toDelete);
                $this->deleteEnrichmentCaches($toDelete);
                Product::query()->whereIn('id', $toDelete)->delete();
                $this->deleteVectorsAfterCommit($toDelete);
                $this->files->deleteAfterCommit($paths);
            }

            $meta = [
                'undone_import_id' => (int) $import->id,
                'manufacturer' => (string) ($priceList?->manufacturer ?? ''),
                'version' => (string) $import->version,
                'products_deleted' => count($toDelete),
                'products_kept_shared' => count($shared),
                'product_ids_deleted' => $toDelete,
            ];
            $import->delete();

            Log::info('Price list import undone', [
                'actor_id' => $actor->id,
                'actor_email' => $actor->email,
                ...$meta,
            ]);

            return $meta;
        });
    }

    /**
     * Usuwa cennik producenta wraz z jego kartami — te, których nie przyniósł żaden inny cennik
     * i które nie mają powiązania B2B. Operacja szeroka: po zwinięciu Cenników do jednego wpisu na
     * producenta to jest skasowanie całego jego katalogu, a nie jednej aktualizacji.
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
                // ścieżki przed usunięciem kart — kaskada kasuje wiersze zdjęć i dokumentów; pliki znikają po commit
                $paths = $this->files->pathsOf($toDelete);
                $this->deleteEnrichmentCaches($toDelete);
                // sloty cen kasowanych kart znikają kaskadą
                Product::query()->whereIn('id', $toDelete)->delete();
                $this->deleteVectorsAfterCommit($toDelete);
                $this->files->deleteAfterCommit($paths);
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
    /**
     * @param  list<mixed>  $raw
     * @return list<int>
     */
    private function productIdsOf(array $raw): array
    {
        return array_values(array_unique(array_filter(
            array_map('intval', $raw),
            static fn (int $id): bool => $id > 0
        )));
    }

    /**
     * Karty przyniesione także przez inną aktualizację — cofnięcie jednej ich nie zabiera.
     *
     * @param  list<int>  $productIds
     * @return list<int>
     */
    private function productIdsReferencedByOtherImports(int $importId, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $lookup = array_fill_keys($productIds, true);
        $shared = [];
        $others = PriceListImport::query()
            ->where('id', '!=', $importId)
            ->whereNotNull('product_ids')
            ->get(['id', 'product_ids']);

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
     * zostaje). deleteSlot przelicza cenę obowiązującą z pozostałych slotów (konta B2B), a bez innych slotów z ceną
     * cena karty zostaje bez zmian.
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
     * Wektory usuniętych kart w Qdrant — po commit. Wycofana transakcja zostawia karty razem z embedding_hash, a karty
     * bez punktu w Qdrant zwykły reindeks (bez --force) by nie odtworzył — zniknęłyby z wyszukiwania wektorowego.
     * Błąd Qdrant tylko w logu (ProductEmbeddingIndexer::deleteMany), usunięcie zostaje.
     *
     * @param  list<int>  $productIds
     */
    private function deleteVectorsAfterCommit(array $productIds): void
    {
        DB::afterCommit(fn () => $this->embeddings->deleteMany($productIds));
    }
}
