<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductImage;
use App\Models\ProductPriceHistory;
use App\Models\ProductSubstitute;
use App\Models\TenderItem;
use App\Support\ProductSizeVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ProductSizeMergeService
{
    public function __construct(
        private readonly ProductSizeVariant $sizes,
    ) {}

    /**
     * @return array{
     *     groups: int,
     *     deleted: int,
     *     dry_run: bool,
     *     examples: list<array{keep: string, drop: list<string>}>
     * }
     */
    public function merge(?string $manufacturer = null, bool $dryRun = false): array
    {
        $query = Product::query()->withCount('images')->orderBy('id');
        if ($manufacturer !== null && trim($manufacturer) !== '') {
            $query->where('manufacturer', trim($manufacturer));
        }

        /** @var array<string, list<Product>> $groups */
        $groups = [];
        foreach ($query->cursor() as $product) {
            $key = $this->sizes->groupKey(
                (string) $product->manufacturer,
                (string) $product->name,
                (string) $product->sku,
                $product->packaging !== null ? (string) $product->packaging : null,
            );
            if ($key === null) {
                continue;
            }
            $bucket = $key.'|'.$this->sizes->priceBucket($product->catalog_price_net, $product->purchase_price);
            $groups[$bucket][] = $product;
        }

        $mergedGroups = 0;
        $deleted = 0;
        $examples = [];
        foreach ($groups as $items) {
            if (count($items) < 2) {
                continue;
            }
            $winner = $this->pickWinner($items);
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
                $this->absorb($winner, $losers);
            }
        }

        return [
            'groups' => $mergedGroups,
            'deleted' => $deleted,
            'dry_run' => $dryRun,
            'examples' => $examples,
        ];
    }

    /**
     * @param  list<Product>  $variants
     */
    private function pickWinner(array $variants): Product
    {
        $best = $variants[0];
        $bestScore = -1;
        foreach ($variants as $product) {
            $hasImage = ((int) ($product->images_count ?? 0)) > 0;
            $hasDesc = $product->hasUsableDescription();
            $score = 0;
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
     */
    private function absorb(Product $winner, array $losers): void
    {
        $loserIds = array_map(static fn (Product $p): int => (int) $p->id, $losers);
        $map = [];
        foreach ($loserIds as $id) {
            $map[$id] = (int) $winner->id;
        }

        DB::transaction(function () use ($winner, $losers, $loserIds, $map): void {
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

            $stock = (int) $winner->stock;
            foreach ($losers as $loser) {
                $stock += (int) $loser->stock;
            }

            $updates = [
                'packaging' => null,
                'stock' => $stock,
                'enrichment_payload' => $payload,
            ];
            if ($stripped !== '' && $stripped !== (string) $winner->name) {
                $updates['name'] = $stripped;
            }
            if ($core !== null && $core !== (string) $winner->sku) {
                $taken = Product::query()
                    ->where('sku', $core)
                    ->where('id', '!=', $winner->id)
                    ->whereNotIn('id', $loserIds)
                    ->exists();
                if (! $taken) {
                    $updates['sku'] = $core;
                }
            }
            $winner->update($updates);

            Product::query()->whereIn('id', $loserIds)->delete();
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
        if (Schema::hasTable('product_images') && $winner->images()->count() === 0) {
            ProductImage::query()->whereIn('product_id', $loserIds)->update(['product_id' => $winner->id]);
        }
        if (Schema::hasTable('product_documents') && $winner->documents()->count() === 0) {
            ProductDocument::query()->whereIn('product_id', $loserIds)->update(['product_id' => $winner->id]);
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
