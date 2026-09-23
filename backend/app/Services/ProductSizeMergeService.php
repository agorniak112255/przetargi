<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductIdentifier;
use App\Models\ProductImage;
use App\Models\ProductImageRejection;
use App\Models\ProductPriceHistory;
use App\Models\ProductShopCard;
use App\Models\ProductSourcePrice;
use App\Models\ProductSubstitute;
use App\Models\TenderItem;
use App\Services\B2b\B2bCatalogSync;
use App\Services\Pricing\ProductEffectivePrice;
use App\Support\ProductSizeVariant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ProductSizeMergeService
{
    public function __construct(
        private readonly ProductSizeVariant $sizes,
        private readonly ProductEffectivePrice $effectivePrices,
    ) {}

    /**
     * Koszyk ceny do łączenia rozmiarów: z ceny z pliku (slot „file”) — rozmiary z jednego cennika mają tę samą cenę
     * z pliku, a cena obowiązująca bywa ceną B2B, która rozdzieliłaby rozmiary. Karta bez slotu pliku (sprzed slotów,
     * tylko B2B, ręczna) — z ceny karty, jak dotąd.
     */
    public function filePriceBucket(Product $product, ?ProductSourcePrice $fileSlot): string
    {
        if ($fileSlot === null) {
            return $this->sizes->priceBucket($product->catalog_price_net, $product->purchase_price);
        }

        return $this->sizes->priceBucket(
            $fileSlot->catalog_price_net ?? $fileSlot->purchase_price,
            $fileSlot->purchase_price ?? $fileSlot->catalog_price_net,
        );
    }

    /**
     * @return array{
     *     groups: int,
     *     deleted: int,
     *     dry_run: bool,
     *     examples: list<array{keep: string, drop: list<string>}>,
     *     errors: list<string>
     * }
     */
    public function merge(?string $manufacturer = null, bool $dryRun = false): array
    {
        // Karta z wersjami B2B (znak w formatach × podłożach) to już jedna karta z cenami w wersjach — cena karty 0
        // skleiłaby różne znaki w jedną grupę, a usunięcie karty skasowałoby jej wersje i historię cen.
        $query = Product::query()->withCount('images')->whereDoesntHave('variants')->orderBy('id');
        if ($manufacturer !== null && trim($manufacturer) !== '') {
            $query->where('manufacturer', trim($manufacturer));
        }

        $knownStems = [];
        $rows = [];
        foreach ((clone $query)->cursor() as $product) {
            $stem = $this->sizes->skuTailStem((string) $product->sku);
            if ($stem !== null) {
                $knownStems[mb_strtolower($stem)] = $stem;
            }
            $rows[] = [
                'id' => (int) $product->id,
                'manufacturer' => (string) $product->manufacturer,
                'name' => (string) $product->name,
                'sku' => (string) $product->sku,
            ];
        }
        // Litera rozmiaru w środku kodu — rozpoznawana z rodzeństwa, więc przed grupowaniem.
        $midGroups = $this->sizes->midCodeSizeVariantGroups($rows);

        /** @var array<int, ProductSourcePrice> $fileSlots */
        $fileSlots = ProductSourcePrice::query()
            ->where('source_key', ProductSourcePrice::SOURCE_FILE)
            ->whereIn('product_id', (clone $query)->reorder()->select('products.id'))
            ->get()
            ->keyBy('product_id')
            ->all();

        /** @var array<string, list<Product>> $groups */
        $groups = [];
        // Karty modelu z wcześniejszego łączenia: nazwa i SKU bez rozmiaru, więc poza grupami. Kod ze scalonej listy
        // (merged_size_skus) → karta; kod na dwóch listach to niejednoznaczny dowód — odpada.
        /** @var array<int, Product> $modelCards */
        $modelCards = [];
        /** @var array<string, list<int>> $modelBySku */
        $modelBySku = [];
        foreach ($query->cursor() as $product) {
            $key = $this->mergeGroupKey($product, $knownStems, $midGroups, $fileSlots[(int) $product->id] ?? null);
            if ($key === null) {
                $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
                foreach (is_array($payload['merged_size_skus'] ?? null) ? $payload['merged_size_skus'] : [] as $sku) {
                    $modelCards[(int) $product->id] = $product;
                    $modelBySku[$this->modelSkuKey($product, (string) $sku)][] = (int) $product->id;
                }

                continue;
            }
            $groups[$key][] = $product;
        }
        $modelBySku = array_filter($modelBySku, static fn (array $ids): bool => count(array_unique($ids)) === 1);

        $mergedGroups = 0;
        $deleted = 0;
        $examples = [];
        $errors = [];
        foreach ($groups as $key => $items) {
            $model = null;
            if (str_starts_with((string) $key, 'name:')) {
                $modelIds = [];
                foreach ($items as $product) {
                    $ids = $modelBySku[$this->modelSkuKey($product, (string) $product->sku)] ?? [];
                    if ($ids !== []) {
                        $modelIds[$ids[0]] = true;
                    }
                }
                if (count($modelIds) > 1) {
                    $errors[] = $items[0]->sku.': kody grupy scalono wcześniej do kilku kart (#'
                        .implode(', #', array_keys($modelIds)).') — grupa pominięta';

                    continue;
                }
                $candidate = $modelIds !== [] ? $modelCards[(int) array_key_first($modelIds)] : null;
                if ($candidate !== null
                    && $this->sizes->namesCompatibleForMerge([(string) $candidate->name, (string) $items[0]->name])) {
                    $model = $candidate;
                    $items = [$model, ...$items];
                }
            }
            if (count($items) < 2) {
                continue;
            }
            if (str_starts_with((string) $key, 'stem:') && ! $this->stemNamesAllowMerge($items, $knownStems)) {
                continue;
            }
            // karta modelu zostaje: to samo id, jej powiązania innych kont, pozycje przetargów, nazwa i SKU
            $winner = $model ?? $this->pickWinner($items);
            $losers = array_values(array_filter(
                $items,
                static fn (Product $p): bool => (int) $p->id !== (int) $winner->id
            ));
            if ($losers === []) {
                continue;
            }
            $mergedGroups++;
            $deleted += count($losers);
            if (count($examples) < 20) {
                $examples[] = [
                    'keep' => (string) $winner->sku,
                    'drop' => array_map(static fn (Product $p): string => (string) $p->sku, $losers),
                ];
            }
            if (! $dryRun) {
                try {
                    $this->absorb(
                        $winner,
                        $losers,
                        str_starts_with((string) $key, 'mid:') ? $this->midSizesBySku($items, $midGroups) : null,
                        $model !== null,
                    );
                } catch (Throwable $e) {
                    $mergedGroups--;
                    $deleted -= count($losers);
                    if (count($examples) > 0) {
                        array_pop($examples);
                    }
                    $errors[] = $winner->sku.': '.$e->getMessage();
                }
            }
        }

        return [
            'groups' => $mergedGroups,
            'deleted' => $deleted,
            'dry_run' => $dryRun,
            'examples' => $examples,
            'errors' => $errors,
        ];
    }

    /** Klucz kodu w mapie kart modelu — z producentem, bo merge(null) obejmuje cały katalog. */
    private function modelSkuKey(Product $product, string $sku): string
    {
        return mb_strtolower(trim((string) $product->manufacturer)).'|'.mb_strtolower(trim($sku));
    }

    /**
     * @param  array<string, string>  $knownStems
     * @param  array<int, array{key: string, size: string}>  $midGroups
     */
    private function mergeGroupKey(
        Product $product,
        array $knownStems,
        array $midGroups,
        ?ProductSourcePrice $fileSlot,
    ): ?string {
        $price = $this->filePriceBucket($product, $fileSlot);
        $stem = $this->sizes->resolveMergeStem((string) $product->sku, $knownStems);
        if ($stem !== null) {
            return 'stem:'.mb_strtolower((string) $product->manufacturer).'|'.mb_strtolower($stem).'|'.$price;
        }

        $key = $this->sizes->groupKey(
            (string) $product->manufacturer,
            (string) $product->name,
            (string) $product->sku,
            $product->packaging !== null ? (string) $product->packaging : null,
        );
        if ($key !== null) {
            return $key.'|'.$price;
        }

        // Ostatnia szansa: rozmiar literą w środku kodu (ścieżki wyżej mają pierwszeństwo).
        $mid = $midGroups[(int) $product->id] ?? null;

        return $mid !== null ? $mid['key'].'|'.$price : null;
    }

    /**
     * Litery rozmiaru grupy „mid” po SKU — trafiają na kartę zwycięzcy jako lista rozmiarów.
     *
     * @param  list<Product>  $items
     * @param  array<int, array{key: string, size: string}>  $midGroups
     * @return array<string, string>|null null = grupa spoza ścieżki „litera w środku kodu”
     */
    private function midSizesBySku(array $items, array $midGroups): ?array
    {
        $sizes = [];
        foreach ($items as $product) {
            $mid = $midGroups[(int) $product->id] ?? null;
            if ($mid === null) {
                return null;
            }
            $sizes[(string) $product->sku] = $mid['size'];
        }

        return $sizes === [] ? null : $sizes;
    }

    /**
     * @param  list<Product>  $items
     * @param  array<string, string>  $knownStems
     */
    private function stemNamesAllowMerge(array $items, array $knownStems): bool
    {
        foreach ($items as $product) {
            if (isset($knownStems[mb_strtolower((string) $product->sku)])) {
                return true;
            }
        }
        $names = array_map(static fn (Product $p): string => (string) $p->name, $items);

        return $this->sizes->namesCompatibleForMerge($names);
    }

    /**
     * @param  list<Product>  $variants
     */
    private function pickWinner(array $variants): Product
    {
        $knownStems = [];
        foreach ($variants as $product) {
            $stem = $this->sizes->skuTailStem((string) $product->sku);
            if ($stem !== null) {
                $knownStems[mb_strtolower($stem)] = $stem;
            }
        }

        $best = $variants[0];
        $bestScore = -1;
        foreach ($variants as $product) {
            $hasImage = ((int) ($product->images_count ?? 0)) > 0;
            $hasDesc = $product->hasUsableDescription();
            $score = 0;
            if (isset($knownStems[mb_strtolower((string) $product->sku)])) {
                $score += 80;
            }
            if ($hasImage && $hasDesc) {
                $score += 200;
            }
            if ($hasImage) {
                $score += 100;
            }
            if ($hasDesc) {
                $score += 50;
            }
            if ($product->enrichment_status === Product::ENRICHMENT_DONE) {
                $score += 20;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $product;
            }
        }

        return $best;
    }

    /**
     * @param  list<Product>  $losers
     * @param  array<string, string>|null  $midSizes  SKU => litera rozmiaru z kodu (tylko ścieżka „mid”)
     * @param  bool  $keepIdentity  karta modelu z wcześniejszego łączenia — nazwa, SKU i lista rozmiarów zostają
     *                              (stripSizeFromName nie jest idempotentne, a packaging mogło wypełnić wzbogacanie)
     */
    private function absorb(Product $winner, array $losers, ?array $midSizes = null, bool $keepIdentity = false): void
    {
        $loserIds = array_map(static fn (Product $p): int => (int) $p->id, $losers);
        $map = [];
        foreach ($loserIds as $id) {
            $map[$id] = (int) $winner->id;
        }

        DB::transaction(function () use ($winner, $losers, $loserIds, $map, $midSizes, $keepIdentity): void {
            $this->remapTenderItems($map);
            $this->remapSubstitutes((int) $winner->id, $loserIds);
            $this->moveMedia($winner, $loserIds);
            $this->remapPriceHistory((int) $winner->id, $loserIds);
            $this->remapPresta((int) $winner->id, $loserIds);
            $this->remapPriceLists($map);

            $core = $this->sizes->skuCore((string) $winner->sku, (string) $winner->name);
            $stripped = $this->sizes->stripSizeFromName((string) $winner->name);
            $payload = is_array($winner->enrichment_payload) ? $winner->enrichment_payload : [];
            $mergedSkus = is_array($payload['merged_size_skus'] ?? null) ? $payload['merged_size_skus'] : [];
            foreach ($losers as $loser) {
                $mergedSkus[] = (string) $loser->sku;
            }
            $payload['merged_size_skus'] = array_values(array_unique(array_filter($mergedSkus)));

            // Rozmiar odczytany z kodu nie może zniknąć: lista na kartę, przypisanie SKU → rozmiar do payloadu.
            $sizeLabel = null;
            if ($midSizes !== null && $midSizes !== []) {
                $sizeLabel = $this->sizes->midCodeSizeLabel(array_values($midSizes));
                $knownSizes = is_array($payload['merged_size_variants'] ?? null) ? $payload['merged_size_variants'] : [];
                $payload['merged_size_variants'] = [...$knownSizes, ...$midSizes];
            }

            $stock = (int) $winner->stock;
            foreach ($losers as $loser) {
                $stock += (int) $loser->stock;
            }

            $updates = [
                'stock' => $stock,
                'enrichment_payload' => $payload,
            ];
            if (! $keepIdentity) {
                $updates['packaging'] = $sizeLabel;
            }
            if (! $keepIdentity && $stripped !== '' && $stripped !== (string) $winner->name) {
                $updates['name'] = $stripped;
            }
            $newSku = null;
            if (! $keepIdentity && $core !== null && $core !== (string) $winner->sku) {
                $taken = Product::query()
                    ->where('sku', $core)
                    ->where('id', '!=', $winner->id)
                    ->whereNotIn('id', $loserIds)
                    ->exists();
                if (! $taken) {
                    $newSku = $core;
                }
            }
            // przed usunięciem scalanych kart — ich sloty, powiązania B2B, tabelki ze sklepu i odrzucone zdjęcia
            // zniknęłyby kaskadą, a następna synchronizacja założyłaby dla kodu dostawcy osobną kartę
            $this->moveSourcePrices((int) $winner->id, $loserIds);
            $this->moveB2bLinks((int) $winner->id, $loserIds);
            $this->moveShopCards((int) $winner->id, $loserIds);
            $this->moveImageRejections((int) $winner->id, $loserIds);
            // identyfikatory ze źródeł cen (EAN, kody scalanych rozmiarów) — UNIQUE bez product_id, więc bez konfliktów
            ProductIdentifier::query()->whereIn('product_id', $loserIds)->update(['product_id' => $winner->id]);
            $winner->update($updates);
            Product::query()->whereIn('id', $loserIds)->delete();
            if ($newSku !== null) {
                $winner->update(['sku' => $newSku]);
            }
            B2bCatalogSync::refreshShopFieldsSummary($winner);
            $this->effectivePrices->refresh($winner);
        });

        try {
            ReindexProductEmbeddingJob::dispatch((int) $winner->id, true);
        } catch (Throwable) {
            // kolejka embeddingów nie blokuje scalenia
        }
    }

    /**
     * @param  array<int, int>  $map
     */
    private function remapTenderItems(array $map): void
    {
        if (! Schema::hasTable('tender_items') || $map === []) {
            return;
        }
        foreach ($map as $from => $to) {
            TenderItem::query()->where('main_product_id', $from)->update(['main_product_id' => $to]);
        }
    }

    /**
     * @param  list<int>  $loserIds
     */
    private function remapSubstitutes(int $winnerId, array $loserIds): void
    {
        if (! Schema::hasTable('product_substitutes') || $loserIds === []) {
            return;
        }
        ProductSubstitute::query()
            ->whereIn('main_product_id', $loserIds)
            ->update(['main_product_id' => $winnerId]);
        ProductSubstitute::query()
            ->whereIn('substitute_product_id', $loserIds)
            ->update(['substitute_product_id' => $winnerId]);
        ProductSubstitute::query()
            ->whereColumn('main_product_id', 'substitute_product_id')
            ->delete();

        $seen = [];
        foreach (ProductSubstitute::query()
            ->where('main_product_id', $winnerId)
            ->orWhere('substitute_product_id', $winnerId)
            ->orderBy('id')
            ->get() as $row) {
            $pair = $row->main_product_id.'-'.$row->substitute_product_id;
            if (isset($seen[$pair])) {
                $row->delete();

                continue;
            }
            $seen[$pair] = true;
        }
    }

    /**
     * @param  list<int>  $loserIds
     */
    private function moveMedia(Product $winner, array $loserIds): void
    {
        if ($loserIds === []) {
            return;
        }
        if (Schema::hasTable('product_images')) {
            $this->reassignUniqueChecksums(
                ProductImage::query()->whereIn('product_id', $loserIds)->get(),
                $winner->images()->pluck('checksum')->filter()->map(static fn ($c): string => (string) $c)->all(),
                (int) $winner->id,
            );
        }
        if (Schema::hasTable('product_documents')) {
            $this->reassignUniqueChecksums(
                ProductDocument::query()->whereIn('product_id', $loserIds)->get(),
                $winner->documents()->pluck('checksum')->filter()->map(static fn ($c): string => (string) $c)->all(),
                (int) $winner->id,
            );
        }
    }

    /**
     * Ten sam PDF/zdjęcie na wielu rozmiarach — przenosimy raz, resztę kasujemy.
     *
     * @param  Collection<int, ProductImage|ProductDocument>  $rows
     * @param  list<string>  $takenChecksums
     */
    private function reassignUniqueChecksums($rows, array $takenChecksums, int $winnerId): void
    {
        $seen = array_fill_keys($takenChecksums, true);
        foreach ($rows as $row) {
            $sum = trim((string) ($row->checksum ?? ''));
            if ($sum !== '' && isset($seen[$sum])) {
                $row->delete();

                continue;
            }
            if ($sum !== '') {
                $seen[$sum] = true;
            }
            $row->product_id = $winnerId;
            $row->save();
        }
    }

    /**
     * @param  list<int>  $loserIds
     */
    private function remapPriceHistory(int $winnerId, array $loserIds): void
    {
        if (! Schema::hasTable('product_price_history') || $loserIds === []) {
            return;
        }
        ProductPriceHistory::query()->whereIn('product_id', $loserIds)->update(['product_id' => $winnerId]);
    }

    /**
     * Sloty cen scalanych kart na kartę docelową. Ten sam source_key (UNIQUE) — zostaje slot z nowszym checked_at.
     *
     * @param  list<int>  $loserIds
     */
    private function moveSourcePrices(int $winnerId, array $loserIds): void
    {
        if (! Schema::hasTable('product_source_prices') || $loserIds === []) {
            return;
        }
        $kept = ProductSourcePrice::query()->where('product_id', $winnerId)->get()->keyBy('source_key')->all();
        foreach (ProductSourcePrice::query()->whereIn('product_id', $loserIds)->orderBy('id')->get() as $slot) {
            $key = (string) $slot->source_key;
            $current = $kept[$key] ?? null;
            if ($current !== null) {
                if (($slot->checked_at?->getTimestamp() ?? 0) <= ($current->checked_at?->getTimestamp() ?? 0)) {
                    $slot->delete();

                    continue;
                }
                $current->delete();
            }
            $slot->product_id = $winnerId;
            $slot->save();
            $kept[$key] = $slot;
        }
    }

    /**
     * Powiązania kodów dostawcy na kartę docelową. UNIQUE (b2b_account_id, remote_id) nie zależy od karty, a kilka
     * kodów jednego konta na jednej karcie to zwykły stan grupy rozmiarów w B2bCatalogSync. merged_at oznacza kartę
     * scaloną — synchronizacja nie oddaje jej kolejnych grup rozmiarów konta na nowe karty (resolveGroupCard).
     *
     * @param  list<int>  $loserIds
     */
    private function moveB2bLinks(int $winnerId, array $loserIds): void
    {
        if (! Schema::hasTable('b2b_product_links') || $loserIds === []) {
            return;
        }
        B2bProductLink::query()->whereIn('product_id', $loserIds)->update(['product_id' => $winnerId, 'merged_at' => now()]);
    }

    /**
     * Tabelki ze sklepu dostawcy na kartę docelową. Ta sama para (karta, konto) jest UNIQUE — zostaje tabelka
     * pobrana później (jak slot ceny w moveSourcePrices).
     *
     * @param  list<int>  $loserIds
     */
    private function moveShopCards(int $winnerId, array $loserIds): void
    {
        if (! Schema::hasTable('product_shop_cards') || $loserIds === []) {
            return;
        }
        $kept = ProductShopCard::query()->where('product_id', $winnerId)->get()->keyBy('b2b_account_id')->all();
        foreach (ProductShopCard::query()->whereIn('product_id', $loserIds)->orderBy('id')->get() as $card) {
            $accountId = (int) $card->b2b_account_id;
            $current = $kept[$accountId] ?? null;
            if ($current !== null) {
                if (($card->synced_at?->getTimestamp() ?? 0) <= ($current->synced_at?->getTimestamp() ?? 0)) {
                    $card->delete();

                    continue;
                }
                $current->delete();
            }
            $card->product_id = $winnerId;
            $card->save();
            $kept[$accountId] = $card;
        }
    }

    /**
     * Zdjęcie odrzucone na scalanej karcie nie może wrócić na kartę docelową z galerii przeniesionego powiązania B2B.
     * UNIQUE (product_id, file_key_hash) — odrzucenie już zapisane na karcie docelowej wystarcza.
     *
     * @param  list<int>  $loserIds
     */
    private function moveImageRejections(int $winnerId, array $loserIds): void
    {
        if (! Schema::hasTable('product_image_rejections') || $loserIds === []) {
            return;
        }
        $taken = array_fill_keys(
            ProductImageRejection::query()->where('product_id', $winnerId)->pluck('file_key_hash')->all(),
            true,
        );
        foreach (ProductImageRejection::query()->whereIn('product_id', $loserIds)->orderBy('id')->get() as $rejection) {
            $hash = (string) $rejection->file_key_hash;
            if (isset($taken[$hash])) {
                $rejection->delete();

                continue;
            }
            $taken[$hash] = true;
            $rejection->product_id = $winnerId;
            $rejection->save();
        }
    }

    /**
     * @param  list<int>  $loserIds
     */
    private function remapPresta(int $winnerId, array $loserIds): void
    {
        if (! Schema::hasTable('presta_product_matches') || $loserIds === []) {
            return;
        }
        foreach ($loserIds as $from) {
            try {
                DB::table('presta_product_matches')->where('product_id', $from)->update(['product_id' => $winnerId]);
            } catch (Throwable) {
                DB::table('presta_product_matches')->where('product_id', $from)->delete();
            }
        }
    }

    /**
     * @param  array<int, int>  $map
     */
    private function remapPriceLists(array $map): void
    {
        if (! Schema::hasTable('price_lists') || $map === []) {
            return;
        }
        foreach (PriceList::query()->whereNotNull('product_ids')->cursor() as $list) {
            $ids = is_array($list->product_ids) ? $list->product_ids : [];
            $next = [];
            $changed = false;
            foreach ($ids as $id) {
                $nid = $map[(int) $id] ?? (int) $id;
                if ($nid !== (int) $id) {
                    $changed = true;
                }
                if (! in_array($nid, $next, true)) {
                    $next[] = $nid;
                }
            }
            if ($changed) {
                $list->update(['product_ids' => $next]);
            }
        }
    }
}
