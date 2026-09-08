<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductAccessory;

final class ProductKitService
{
    public function __construct(
        private readonly ProductKitSuggestionService $suggestions,
    ) {}

    /**
     * @param  list<int>  $relatedIds
     * @return list<array<string, mixed>>
     */
    public function attach(Product $product, array $relatedIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (mixed $id): int => (int) $id, $relatedIds),
            static fn (int $id): bool => $id > 0 && $id !== (int) $product->id
        )));
        if ($ids === []) {
            return $this->present($product);
        }

        $related = Product::query()->whereIn('id', $ids)->get()->keyBy('id');
        foreach ($ids as $id) {
            $child = $related->get($id);
            if (! $child instanceof Product) {
                continue;
            }
            $existing = ProductAccessory::query()
                ->where('product_id', $product->id)
                ->where('related_product_id', $child->id)
                ->first();
            if ($existing instanceof ProductAccessory) {
                continue;
            }
            ProductAccessory::query()->create([
                'product_id' => $product->id,
                'related_product_id' => $child->id,
                'source' => ProductAccessory::SOURCE_MANUAL,
                'link_key' => 'm:'.$child->id,
                'related_sku' => mb_substr((string) $child->sku, 0, 128),
                'related_ean' => mb_substr((string) $child->ean, 0, 32) ?: null,
                'related_name' => mb_substr((string) $child->name, 0, 255),
                'related_manufacturer' => mb_substr((string) ($child->manufacturer ?? ''), 0, 128) ?: null,
                'score' => 100,
                'method' => 'manual',
            ]);
        }

        return $this->present($product);
    }

    /**
     * @param  list<int>  $accessoryIds
     * @return list<array<string, mixed>>
     */
    public function detach(Product $product, array $accessoryIds = [], bool $all = false): array
    {
        $query = $product->accessories();
        if (! $all) {
            $ids = array_values(array_unique(array_filter(
                array_map(static fn (mixed $id): int => (int) $id, $accessoryIds),
                static fn (int $id): bool => $id > 0
            )));
            if ($ids === []) {
                return $this->present($product);
            }
            $query->whereIn('id', $ids);
        }
        $query->delete();

        return $this->present($product);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function present(Product $product): array
    {
        $product->unsetRelation('accessories');
        $product->load([
            'accessories.relatedProduct.images',
            'accessories.relatedProduct.prestaExport',
        ]);

        return $product->accessories->map(function (ProductAccessory $row): array {
            $related = $row->relatedProduct;
            $card = $related instanceof Product
                ? $this->suggestions->card($related)
                : [
                    'id' => null,
                    'sku' => $row->related_sku,
                    'name' => $row->related_name,
                    'manufacturer' => $row->related_manufacturer,
                    'short_description' => trim((string) $row->related_manufacturer),
                    'image_url' => null,
                    'role' => '',
                    'reason' => '',
                ];

            return [
                'id' => (int) $row->id,
                'source' => $row->source,
                'score' => (int) $row->score,
                'method' => $row->method,
                'related_product_id' => $row->related_product_id,
                'sku' => $card['sku'],
                'name' => $card['name'],
                'manufacturer' => $card['manufacturer'],
                'short_description' => $card['short_description'],
                'image_url' => $card['image_url'],
                'in_presta' => $this->suggestions->inPresta($related, $row->presta_related_id),
                'presta_id' => $this->suggestions->prestaId($related, $row->presta_related_id),
                'presta_url' => $this->suggestions->prestaUrl($related),
                'matched' => $related instanceof Product,
            ];
        })->values()->all();
    }
}
