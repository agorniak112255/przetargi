<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\B2bProductLink;
use App\Models\ErpItemLink;
use App\Models\PriceList;
use App\Models\PriceListImport;
use App\Models\Product;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductImage;
use App\Models\ProductSourcePrice;
use App\Models\ProductSubstitute;
use App\Models\TenderItem;
use App\Models\User;
use App\Services\Pricing\ProductEffectivePrice;
use App\Services\Vector\ProductEmbeddingIndexer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class PriceListDeletionService
{
    /** Kawałek listy kart w jednym IN — cennik może mieć dziesiątki tysięcy kart (limit 65 535 parametrów MySQL). */
    private const CHUNK = 1000;

    public function __construct(
        private readonly ProductEmbeddingIndexer $embeddings,
        private readonly ProductEffectivePrice $effectivePrices,
        private readonly ProductStoredFiles $files,
        private readonly PriceListCards $cards,
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
     * Usuwa cennik producenta wraz z jego kartami (PriceListCards: ostatni import i karty ze slotem ceny z pliku tego
     * cennika). Zostają — tracąc tylko slot ceny z tego cennika — karty z innego cennika, z powiązaniem B2B, z inną
     * ceną (slot B2B albo pliku innego cennika) i karty użyte w przetargach (partition()). Operacja szeroka: po
     * zwinięciu Cenników do jednego wpisu na producenta to jest skasowanie całego jego katalogu, a nie jednej
     * aktualizacji.
     *
     * @return array{
     *     deleted_price_list_id: int,
     *     manufacturer: string,
     *     version: string,
     *     products_deleted: int,
     *     products_kept_shared: int,
     *     products_kept_tenders: int,
     *     product_ids_deleted: list<int>
     * }
     */
    public function delete(PriceList $priceList, User $actor): array
    {
        return DB::transaction(function () use ($priceList, $actor): array {
            $split = $this->partition($priceList);
            $toDelete = $split['to_delete'];

            if ($toDelete !== []) {
                // ścieżki przed usunięciem kart — kaskada kasuje wiersze zdjęć i dokumentów; pliki znikają po commit
                $paths = [];
                foreach (array_chunk($toDelete, self::CHUNK) as $chunk) {
                    $paths = [...$paths, ...$this->files->pathsOf($chunk)];
                    $this->deleteEnrichmentCaches($chunk);
                }
                $paths = array_values(array_unique($paths));
                // sloty cen kasowanych kart znikają kaskadą
                foreach (array_chunk($toDelete, self::CHUNK) as $chunk) {
                    Product::query()->whereIn('id', $chunk)->delete();
                }
                $this->deleteVectorsAfterCommit($toDelete);
                $this->files->deleteAfterCommit($paths);
            }

            $this->deleteFileSlotsOfPriceList($priceList->id, $toDelete);

            $kept = 0;
            foreach ($split['kept'] as $ids) {
                $kept += count($ids);
            }
            $meta = [
                'deleted_price_list_id' => $priceList->id,
                'manufacturer' => (string) $priceList->manufacturer,
                'version' => (string) $priceList->version,
                'original_filename' => $priceList->original_filename,
                'products_deleted' => count($toDelete),
                // wszystkie zachowane (każdy powód), w tym te z przetargów
                'products_kept_shared' => $kept,
                'products_kept_tenders' => count($split['kept']['tenders']),
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
     * Skutki delete() bez zapisu — okno „Usuń cennik” pokazuje je przed potwierdzeniem. Ten sam podział co delete()
     * (partition()); karta jest liczona w pierwszym pasującym powodzie zachowania, więc products_total = do usunięcia
     * + suma kept_*.
     *
     * @return array{
     *     products_total: int,
     *     products_to_delete: int,
     *     kept_other_price_lists: int,
     *     kept_b2b: int,
     *     kept_other_slots: int,
     *     kept_tenders: int,
     *     not_in_last_import: int,
     *     to_delete_with_erp_links: int,
     *     to_delete_with_substitutes: int,
     *     to_delete_with_images: int
     * }
     */
    public function preview(PriceList $priceList): array
    {
        $split = $this->partition($priceList);
        $toDelete = $split['to_delete'];

        return [
            'products_total' => count($split['cards']),
            'products_to_delete' => count($toDelete),
            'kept_other_price_lists' => count($split['kept']['other_price_lists']),
            'kept_b2b' => count($split['kept']['b2b']),
            'kept_other_slots' => count($split['kept']['other_slots']),
            'kept_tenders' => count($split['kept']['tenders']),
            'not_in_last_import' => count($split['not_in_last_import']),
            // powiązanie z towarem XL ma product_id nullOnDelete — zostaje bez karty; odrzucone się nie liczą
            'to_delete_with_erp_links' => count($this->idsHaving(
                $toDelete,
                static fn (array $chunk): array => ErpItemLink::query()
                    ->whereIn('product_id', $chunk)
                    ->where('status', '!=', ErpItemLink::STATUS_REJECTED)
                    ->distinct()
                    ->pluck('product_id')
                    ->all(),
            )),
            // zamiennik znika kaskadą, gdy usuwana karta jest po którejkolwiek stronie pary
            'to_delete_with_substitutes' => count($this->idsHaving(
                $toDelete,
                static fn (array $chunk): array => [
                    ...ProductSubstitute::query()->whereIn('main_product_id', $chunk)->distinct()->pluck('main_product_id')->all(),
                    ...ProductSubstitute::query()->whereIn('substitute_product_id', $chunk)->distinct()->pluck('substitute_product_id')->all(),
                ],
            )),
            'to_delete_with_images' => count($this->idsHaving(
                $toDelete,
                static fn (array $chunk): array => ProductImage::query()
                    ->whereIn('product_id', $chunk)
                    ->distinct()
                    ->pluck('product_id')
                    ->all(),
            )),
        ];
    }

    /**
     * Podział kart cennika na usuwane i zachowane — wspólny dla delete() i preview(), żeby podgląd nie rozjechał się
     * z usunięciem. Liczą się tylko istniejące karty (product_ids może pamiętać karty usunięte wcześniej).
     * Powody zachowania w kolejności; karta trafia do pierwszego pasującego:
     *  - other_price_lists: jest w product_ids innego cennika,
     *  - b2b: ma powiązanie B2B (cena z konta), nawet gdy wpis konta nie ma jej w product_ids,
     *  - other_slots: ma inny slot ceny niż plik tego cennika (konto B2B, plik innego cennika, plik bez cennika),
     *  - tenders: jest wybrana w pozycji przetargu (główna albo dodatkowa) — usunięcie wyczyściłoby ją w ofercie.
     *
     * @return array{
     *     cards: list<int>,
     *     to_delete: list<int>,
     *     kept: array{other_price_lists: list<int>, b2b: list<int>, other_slots: list<int>, tenders: list<int>},
     *     not_in_last_import: list<int>
     * }
     */
    private function partition(PriceList $priceList): array
    {
        $listId = (int) $priceList->id;
        $cards = $this->idsHaving(
            $this->cards->ids($priceList),
            static fn (array $chunk): array => Product::query()->whereIn('id', $chunk)->pluck('id')->all(),
        );
        $lastImport = array_fill_keys($this->productIdsOf($priceList->product_ids ?? []), true);

        $remaining = array_fill_keys($cards, true);
        $kept = [];
        // inne cenniki: jedno przejście po ich product_ids (bez SQL po kartach); reszta po kawałkach (idsHaving)
        $finders = [
            'other_price_lists' => fn (array $ids): array => $this->productIdsReferencedByOtherPriceLists($listId, $ids),
            'b2b' => fn (array $ids): array => $this->idsHaving($ids, fn (array $chunk): array => $this->productIdsLinkedToB2b($chunk)),
            'other_slots' => fn (array $ids): array => $this->idsHaving($ids, fn (array $chunk): array => $this->productIdsWithOtherSlots($listId, $chunk)),
            'tenders' => fn (array $ids): array => $this->idsHaving($ids, fn (array $chunk): array => $this->productIdsUsedInTenders($chunk)),
        ];
        foreach ($finders as $reason => $finder) {
            $hits = [];
            if ($remaining !== []) {
                foreach ($finder(array_map('intval', array_keys($remaining))) as $id) {
                    if (isset($remaining[(int) $id])) {
                        $hits[] = (int) $id;
                        unset($remaining[(int) $id]);
                    }
                }
            }
            $kept[$reason] = $hits;
        }

        return [
            'cards' => $cards,
            'to_delete' => array_map('intval', array_keys($remaining)),
            'kept' => $kept,
            'not_in_last_import' => array_values(array_filter(
                $cards,
                static fn (int $id): bool => ! isset($lastImport[$id])
            )),
        ];
    }

    /**
     * Karty z $productIds, dla których $finder (po kawałkach — bez wielkich IN) zwrócił id; kolejność $productIds.
     *
     * @param  list<int>  $productIds
     * @param  callable(list<int>): array<mixed>  $finder
     * @return list<int>
     */
    private function idsHaving(array $productIds, callable $finder): array
    {
        $found = [];
        foreach (array_chunk($productIds, self::CHUNK) as $chunk) {
            foreach ($finder($chunk) as $id) {
                $found[(int) $id] = true;
            }
        }

        return array_values(array_filter($productIds, static fn (int $id): bool => isset($found[$id])));
    }

    /**
     * Karty z innym slotem ceny niż plik tego cennika — po usunięciu zostaje im cena.
     *
     * @param  list<int>  $productIds
     * @return list<int>
     */
    private function productIdsWithOtherSlots(int $priceListId, array $productIds): array
    {
        return ProductSourcePrice::query()
            ->whereIn('product_id', $productIds)
            ->where(static fn ($q) => $q
                ->where('source_key', '!=', ProductSourcePrice::SOURCE_FILE)
                ->orWhereNull('price_list_id')
                ->orWhere('price_list_id', '!=', $priceListId))
            ->distinct()
            ->pluck('product_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Karty wybrane w pozycjach przetargów — tender_items.main_product_id i companion_product_id są nullOnDelete,
     * więc usunięcie karty po cichu zabrałoby produkt z oferty.
     *
     * @param  list<int>  $productIds
     * @return list<int>
     */
    private function productIdsUsedInTenders(array $productIds): array
    {
        return array_map('intval', [
            ...TenderItem::query()->whereIn('main_product_id', $productIds)->distinct()->pluck('main_product_id')->all(),
            ...TenderItem::query()->whereIn('companion_product_id', $productIds)->distinct()->pluck('companion_product_id')->all(),
        ]);
    }

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
        // usunięte karty odejmowane w PHP, nie NOT IN z tysiącami parametrów
        $deleted = array_fill_keys($deletedProductIds, true);
        $productIds = array_values(array_filter(
            array_map('intval', ProductSourcePrice::query()
                ->where('source_key', ProductSourcePrice::SOURCE_FILE)
                ->where('price_list_id', $priceListId)
                ->pluck('product_id')
                ->all()),
            static fn (int $id): bool => ! isset($deleted[$id])
        ));

        foreach (array_chunk($productIds, 500) as $chunk) {
            foreach (Product::query()->whereIn('id', $chunk)->get() as $product) {
                $this->effectivePrices->deleteSlot($product, ProductSourcePrice::SOURCE_FILE);
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
