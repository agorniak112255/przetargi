<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductSourcePrice;
use App\Models\User;
use App\Services\B2b\B2bAccountSyncRunner;
use App\Services\Catalog\ProductImportExclusions;
use App\Services\Catalog\ProductSourceDetacher;
use App\Services\Pricing\SourcePriceComparison;
use App\Services\Vector\ProductEmbeddingIndexer;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ProductDeletionService
{
    public function __construct(
        private readonly ProductEmbeddingIndexer $embeddings,
        private readonly ProductStoredFiles $files,
        private readonly ProductImportExclusions $exclusions,
        private readonly ProductSourceDetacher $detacher,
        private readonly SourcePriceComparison $labels,
    ) {}

    /**
     * $skipOnImport — „Usuń i pomijaj przy imporcie”: pozycje źródeł kart (B2B, cennik z pliku) dostają blokadę,
     * a synchronizacja i import nie zakładają ich od nowa.
     *
     * $onlyAccountId — usuwanie z listy kart konta dostawcy (Produkty filtrowane po koncie B2B): karta z pozycjami
     * tego konta i innym źródłem traci tylko pozycje konta (ProductSourceDetacher), karta tylko z tego konta jest
     * usuwana w całości, a karta bez pozycji konta zostaje nietknięta. Blokady — tylko pozycji tego konta.
     *
     * @param  list<int>  $productIds
     * @return array{
     *     deleted: int,
     *     product_ids_deleted: list<int>,
     *     detached: int,
     *     product_ids_detached: list<int>,
     *     refused: list<array{id: int, sku: string, reason: string}>,
     *     skipped: int,
     *     positions_excluded: int
     * }
     *
     * @throws DomainException trwa synchronizacja konta (nic nie zmienione)
     */
    public function deleteMany(array $productIds, User $actor, bool $skipOnImport = false, ?int $onlyAccountId = null): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn (int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return self::result([], [], [], 0, 0);
        }

        $backupPath = null;
        try {
            return $this->deleteLocked($ids, $actor, $skipOnImport, $onlyAccountId, $backupPath);
        } catch (Throwable $e) {
            // transakcja wycofana — kopia odpięcia, którego nie było, tylko by myliła
            if ($backupPath !== null && is_file($backupPath)) {
                @unlink($backupPath);
            }

            throw $e;
        }
    }

    /**
     * @param  list<int>  $ids
     * @return array{deleted: int, product_ids_deleted: list<int>, detached: int, product_ids_detached: list<int>, refused: list<array{id: int, sku: string, reason: string}>, skipped: int, positions_excluded: int}
     */
    private function deleteLocked(array $ids, User $actor, bool $skipOnImport, ?int $onlyAccountId, ?string &$backupPath): array
    {
        return DB::transaction(function () use ($ids, $actor, $skipOnImport, $onlyAccountId, &$backupPath): array {
            $deleteIds = $ids;
            $detachIds = [];
            $skipped = 0;
            if ($onlyAccountId !== null) {
                // przebieg konta w trakcie przepiąłby pozycję z powrotem z mapy wczytanej na starcie
                $running = B2bAccountSyncRunner::lockIdle([$onlyAccountId]);
                if ($running !== null) {
                    throw new DomainException('Trwa synchronizacja konta '.$this->labels->accountLabel($running).' — spróbuj po jej zakończeniu.');
                }
                $withAccount = array_fill_keys(B2bProductLink::query()
                    ->whereIn('product_id', $ids)
                    ->where('b2b_account_id', $onlyAccountId)
                    ->distinct()
                    ->pluck('product_id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all(), true);
                $deleteIds = [];
                foreach ($ids as $id) {
                    if (! isset($withAccount[$id])) {
                        $skipped++;
                    } elseif ($this->detacher->hasOtherSource($id, $onlyAccountId)) {
                        $detachIds[] = $id;
                    } else {
                        $deleteIds[] = $id;
                    }
                }
            }

            // odpięcie (z blokadami pozycji konta) przed usuwaniem — każda karta we własnym punkcie zapisu
            $detach = $this->detacher->detach($detachIds, (int) $onlyAccountId, $actor, $skipOnImport);
            $backupPath = $detach['backup_path'];
            $detached = $detach['detached'];
            $excluded = $detach['positions_excluded'];

            // blokady przed usunięciem kart — kaskada kasuje powiązania B2B i identyfikatory pozycji; z listy konta
            // dostawcy tylko jego pozycje (np. wycofane wiersze pliku karty zostają bez blokady)
            if ($skipOnImport && $deleteIds !== []) {
                $excluded += $this->exclusions->record(
                    $deleteIds,
                    $actor,
                    $onlyAccountId !== null ? ProductSourcePrice::b2bKey($onlyAccountId) : null,
                );
            }

            if ($deleteIds !== []) {
                // ścieżki przed usunięciem kart — kaskada kasuje wiersze zdjęć i dokumentów
                $paths = $this->files->pathsOf($deleteIds);
                $this->deleteEnrichmentCaches($deleteIds);
                $this->detachFromPriceLists($deleteIds);
                Product::query()->whereIn('id', $deleteIds)->delete();
                // wektory po commit: wycofana transakcja zostawia karty z embedding_hash, a karty bez punktu w Qdrant
                // zwykły reindeks (bez --force) by nie odtworzył — zniknęłyby z wyszukiwania wektorowego
                DB::afterCommit(fn () => $this->embeddings->deleteMany($deleteIds));
                // pliki też po commit: wycofanie zostawia karty z wierszami zdjęć i dokumentów, a pliku nie odtworzymy
                $this->files->deleteAfterCommit($paths);
            }

            Log::info('Products deleted', [
                'actor_id' => $actor->id,
                'actor_email' => $actor->email,
                'deleted' => count($deleteIds),
                'product_ids_deleted' => $deleteIds,
                'only_b2b_account_id' => $onlyAccountId,
                'product_ids_detached' => $detached,
                'detach_refused' => $detach['refused'],
                'detach_backup_path' => $backupPath,
                'skipped_without_account' => $skipped,
                'skip_on_import' => $skipOnImport,
                'positions_excluded' => $excluded,
            ]);

            return self::result($deleteIds, $detached, $detach['refused'], $skipped, $excluded);
        });
    }

    /**
     * @param  list<int>  $deleted
     * @param  list<int>  $detached
     * @param  list<array{id: int, sku: string, reason: string}>  $refused
     * @return array{deleted: int, product_ids_deleted: list<int>, detached: int, product_ids_detached: list<int>, refused: list<array{id: int, sku: string, reason: string}>, skipped: int, positions_excluded: int}
     */
    private static function result(array $deleted, array $detached, array $refused, int $skipped, int $excluded): array
    {
        return [
            'deleted' => count($deleted),
            'product_ids_deleted' => $deleted,
            'detached' => count($detached),
            'product_ids_detached' => $detached,
            'refused' => $refused,
            'skipped' => $skipped,
            'positions_excluded' => $excluded,
        ];
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
