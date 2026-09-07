<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductAccessory;
use App\Services\Presta\PrestaAccessoryReader;
use Throwable;

final class ProductAccessorySyncService
{
    public function __construct(
        private readonly ProductAccessoryMatcher $matcher,
        private readonly PrestaAccessoryReader $reader,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $links
     * @return array{parents: int, links: int, matched: int, unmatched: int}
     */
    public function importPrestaLinks(array $links): array
    {
        $parents = [];
        $stored = 0;
        $matched = 0;
        $unmatched = 0;
        foreach ($links as $link) {
            $parent = $this->resolveParent($link);
            if (! $parent instanceof Product) {
                continue;
            }
            $parents[$parent->id] = true;
            $row = $this->upsert($parent, $this->childCandidate($link), ProductAccessory::SOURCE_PRESTA);
            if ($row === null) {
                continue;
            }
            $stored++;
            if ($row->related_product_id) {
                $matched++;
            } else {
                $unmatched++;
            }
        }

        return [
            'parents' => count($parents),
            'links' => $stored,
            'matched' => $matched,
            'unmatched' => $unmatched,
        ];
    }

    /**
     * @return array{parents: int, links: int, matched: int, unmatched: int}
     */
    public function syncFromPresta(?int $parentPrestaId = null): array
    {
        return $this->importPrestaLinks($this->reader->links($parentPrestaId));
    }

    public function syncFromPrestaParent(int $parentPrestaId, Product $product): int
    {
        $count = 0;
        foreach ($this->reader->links($parentPrestaId) as $link) {
            $row = $this->upsert($product, $this->childCandidate($link), ProductAccessory::SOURCE_PRESTA);
            if ($row instanceof ProductAccessory) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  list<array{sku?: string, ean?: string, name?: string, manufacturer?: string}>  $candidates
     */
    public function matchFromPages(Product $product, array $candidates): int
    {
        $count = 0;
        foreach ($candidates as $candidate) {
            $sku = trim((string) ($candidate['sku'] ?? ''));
            $ean = trim((string) ($candidate['ean'] ?? ''));
            $name = trim((string) ($candidate['name'] ?? ''));
            if ($sku === '' && $ean === '' && $name === '') {
                continue;
            }
            if ($this->isSelf($product, $candidate)) {
                continue;
            }
            $hit = $this->matcher->match($candidate, $product);
            if ($hit === null && $sku === '' && $ean === '') {
                continue;
            }
            $payload = $candidate;
            if ($hit !== null) {
                $payload['related_product'] = $hit['product'];
                $payload['method'] = $hit['method'];
                $payload['score'] = $hit['score'];
            }
            $row = $this->upsert($product, $payload, ProductAccessory::SOURCE_ENRICHMENT);
            if ($row instanceof ProductAccessory) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    public function upsert(Product $product, array $candidate, string $source): ?ProductAccessory
    {
        $related = $candidate['related_product'] ?? null;
        if (! $related instanceof Product) {
            $hit = $this->matcher->match([
                'sku' => (string) ($candidate['sku'] ?? ''),
                'ean' => (string) ($candidate['ean'] ?? ''),
                'name' => (string) ($candidate['name'] ?? ''),
                'manufacturer' => (string) ($candidate['manufacturer'] ?? ''),
                'presta_id' => (int) ($candidate['presta_id'] ?? $candidate['presta_related_id'] ?? 0),
            ], $product);
            if ($hit !== null) {
                $related = $hit['product'];
                $candidate['method'] = $candidate['method'] ?? $hit['method'];
                $candidate['score'] = $candidate['score'] ?? $hit['score'];
            }
        }
        if ($related instanceof Product && (int) $related->id === (int) $product->id) {
            return null;
        }

        $key = $this->matcher->linkKey($candidate + [
            'presta_related_id' => (int) ($candidate['presta_related_id'] ?? $candidate['presta_id'] ?? 0),
        ]);
        if ($key === 'n:'.mb_substr(sha1(''), 0, 16)) {
            return null;
        }

        try {
            return ProductAccessory::query()->updateOrCreate(
                [
                    'product_id' => $product->id,
                    'link_key' => $key,
                ],
                [
                    'related_product_id' => $related instanceof Product ? $related->id : null,
                    'source' => $source,
                    'presta_parent_id' => $this->nullableId($candidate['presta_parent_id'] ?? null),
                    'presta_related_id' => $this->nullableId($candidate['presta_related_id'] ?? $candidate['presta_id'] ?? null),
                    'related_sku' => $this->nullableString($candidate['sku'] ?? null, 128),
                    'related_ean' => $this->nullableString($candidate['ean'] ?? null, 32),
                    'related_name' => $this->nullableString($candidate['name'] ?? null, 255),
                    'related_manufacturer' => $this->nullableString($candidate['manufacturer'] ?? null, 128),
                    'score' => max(0, min(100, (int) ($candidate['score'] ?? ($related ? 90 : 0)))),
                    'method' => $this->nullableString($candidate['method'] ?? ($related ? 'match' : 'pending'), 32),
                ]
            );
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $link
     */
    private function resolveParent(array $link): ?Product
    {
        $hit = $this->matcher->match([
            'sku' => (string) ($link['parent_sku'] ?? ''),
            'ean' => (string) ($link['parent_ean'] ?? ''),
            'name' => (string) ($link['parent_name'] ?? ''),
            'manufacturer' => (string) ($link['parent_manufacturer'] ?? ''),
            'presta_id' => (int) ($link['parent_id'] ?? 0),
        ]);

        return $hit['product'] ?? null;
    }

    /**
     * @param  array<string, mixed>  $link
     * @return array<string, mixed>
     */
    private function childCandidate(array $link): array
    {
        return [
            'sku' => (string) ($link['child_sku'] ?? ''),
            'ean' => (string) ($link['child_ean'] ?? ''),
            'name' => (string) ($link['child_name'] ?? ''),
            'manufacturer' => (string) ($link['child_manufacturer'] ?? ''),
            'presta_id' => (int) ($link['child_id'] ?? 0),
            'presta_parent_id' => (int) ($link['parent_id'] ?? 0),
            'presta_related_id' => (int) ($link['child_id'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $candidate
     */
    private function isSelf(Product $product, array $candidate): bool
    {
        $sku = $this->matcher->compact((string) ($candidate['sku'] ?? ''));
        if ($sku !== '' && $sku === $this->matcher->compact((string) $product->sku)) {
            return true;
        }
        $name = $this->matcher->compact((string) ($candidate['name'] ?? ''));
        $own = $this->matcher->compact((string) $product->name);

        return $name !== '' && $own !== '' && $name === $own;
    }

    private function nullableId(mixed $value): ?int
    {
        $id = (int) $value;

        return $id > 0 ? $id : null;
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? mb_substr($text, 0, $max) : null;
    }
}
