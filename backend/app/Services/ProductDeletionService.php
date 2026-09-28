<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductEnrichmentCache;
use App\Models\User;
use App\Services\Catalog\ProductImportExclusions;
use App\Services\Vector\ProductEmbeddingIndexer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ProductDeletionService
{
    public function __construct(
        private readonly ProductEmbeddingIndexer $embeddings,
        private readonly ProductStoredFiles $files,
        private readonly ProductImportExclusions $exclusions,
    ) {}

    /**
     * $skipOnImport — „Usuń i pomijaj przy imporcie”: pozycje źródeł kart (B2B, cennik z pliku) dostają blokadę,
     * a synchronizacja i import nie zakładają ich od nowa.
     *
     * @param  list<int>  $productIds
     * @return array{
     *     deleted: int,
     *     product_ids_deleted: list<int>,
     *     positions_excluded: int
     * }
     */
    public function deleteMany(array $productIds, User $actor, bool $skipOnImport = false): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return [
                'deleted' => 0,
                'product_ids_deleted' => [],
                'positions_excluded' => 0,
            ];
        }

        return DB::transaction(function () use ($ids, $actor, $skipOnImport): array {
            // blokady przed usunięciem kart — kaskada kasuje powiązania B2B i identyfikatory pozycji
            $excluded = $skipOnImport ? $this->exclusions->record($ids, $actor) : 0;
            // ścieżki przed usunięciem kart — kaskada kasuje wiersze zdjęć i dokumentów
            $paths = $this->files->pathsOf($ids);
            $this->deleteEnrichmentCaches($ids);
            $this->detachFromPriceLists($ids);
            Product::query()->whereIn('id', $ids)->delete();
            // wektory po commit: wycofana transakcja zostawia karty z embedding_hash, a karty bez punktu w Qdrant
            // zwykły reindeks (bez --force) by nie odtworzył — zniknęłyby z wyszukiwania wektorowego
            DB::afterCommit(fn () => $this->embeddings->deleteMany($ids));
            // pliki też po commit: wycofanie zostawia karty z wierszami zdjęć i dokumentów, a pliku nie odtworzymy
            $this->files->deleteAfterCommit($paths);

            Log::info('Products deleted', [
                'actor_id' => $actor->id,
                'actor_email' => $actor->email,
                'deleted' => count($ids),
                'product_ids_deleted' => $ids,
                'skip_on_import' => $skipOnImport,
                'positions_excluded' => $excluded,
            ]);

            return [
                'deleted' => count($ids),
                'product_ids_deleted' => $ids,
                'positions_excluded' => $excluded,
            ];
        });
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
