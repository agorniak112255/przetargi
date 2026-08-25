<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AssortmentGroup;
use InvalidArgumentException;

final class AssortmentGroupService
{
    /**
     * @param  list<array<string, mixed>>  $products
     * @return array{
     *     has_grouping: bool,
     *     detected: list<array{name: string, product_count: int, discount_percent: float, id: int|null}>,
     *     ungrouped_count: int,
     *     existing: list<array{id: int, name: string, discount_percent: float, is_global: bool}>,
     *     global_discount_percent: float
     * }
     */
    public function summarize(array $products, string $manufacturer): array
    {
        $counts = [];
        $ungrouped = 0;
        foreach ($products as $product) {
            $name = $this->normalizeGroupName($product['category'] ?? $product['group_name'] ?? null);
            if ($name === null) {
                $ungrouped++;

                continue;
            }
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        $existing = AssortmentGroup::query()
            ->where('manufacturer', $manufacturer)
            ->orderBy('is_global')
            ->orderBy('name')
            ->get();

        $byName = [];
        $globalDiscount = 0.0;
        foreach ($existing as $group) {
            $byName[$group->name] = $group;
            if ($group->is_global) {
                $globalDiscount = (float) $group->discount_percent;
            }
        }

        $detected = [];
        foreach ($counts as $name => $count) {
            $match = $byName[$name] ?? null;
            $detected[] = [
                'name' => $name,
                'product_count' => $count,
                'discount_percent' => $match !== null ? (float) $match->discount_percent : 0.0,
                'id' => $match?->id,
            ];
        }
        usort($detected, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return [
            'has_grouping' => $counts !== [],
            'detected' => $detected,
            'ungrouped_count' => $ungrouped,
            'existing' => $existing->map(static fn (AssortmentGroup $g): array => [
                'id' => $g->id,
                'name' => $g->name,
                'discount_percent' => (float) $g->discount_percent,
                'is_global' => (bool) $g->is_global,
            ])->values()->all(),
            'global_discount_percent' => $globalDiscount,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @param  array{
     *     groups?: list<array{name?: string, discount_percent?: float|int|string}>,
     *     default_discount?: float|int|string|null,
     *     ungrouped_group?: string|null,
     *     product_assignments?: array<string, string>
     * }  $options
     * @return list<array<string, mixed>>
     */
    public function applyToProducts(array $products, string $manufacturer, array $options): array
    {
        $groupDefs = $this->normalizeGroupDefs($options['groups'] ?? []);
        $assignments = is_array($options['product_assignments'] ?? null)
            ? $options['product_assignments']
            : [];
        $ungroupedGroup = $this->normalizeGroupName($options['ungrouped_group'] ?? null);
        $defaultDiscount = isset($options['default_discount']) && is_numeric($options['default_discount'])
            ? round((float) $options['default_discount'], 2)
            : null;

        $hasGrouping = $groupDefs !== [];
        if (! $hasGrouping) {
            return $this->applyGlobalDiscount($products, $manufacturer, $defaultDiscount);
        }

        if ($ungroupedGroup !== null && ! isset($groupDefs[$ungroupedGroup])) {
            $groupDefs[$ungroupedGroup] = 0.0;
        }

        $resolvedGroups = [];
        foreach ($groupDefs as $name => $discount) {
            $resolvedGroups[$name] = $this->upsertGroup($manufacturer, $name, $discount, false);
        }

        $out = [];
        $missing = [];
        foreach ($products as $product) {
            $sku = (string) ($product['sku'] ?? '');
            $assigned = $this->normalizeGroupName($assignments[$sku] ?? null)
                ?? $this->normalizeGroupName($product['group_name'] ?? null)
                ?? $this->normalizeGroupName($product['category'] ?? null)
                ?? $ungroupedGroup;

            if ($assigned === null || ! isset($resolvedGroups[$assigned])) {
                $missing[] = $sku !== '' ? $sku : ($product['name'] ?? '?');

                continue;
            }

            $group = $resolvedGroups[$assigned];
            $discount = (float) $group->discount_percent;
            $catalog = (float) ($product['catalog_price_net'] ?? 0);
            $product['category'] = $assigned;
            $product['assortment_group_id'] = $group->id;
            $product['discount_percent'] = $discount;
            $product['purchase_price'] = round($catalog * (1 - ($discount / 100)), 2);
            $out[] = $product;
        }

        if ($missing !== []) {
            $sample = implode(', ', array_slice($missing, 0, 8));
            $more = count($missing) > 8 ? '…' : '';
            throw new InvalidArgumentException(
                'Przy grupowaniu asortymentowym każdy towar musi mieć grupę. Brak przypisania: '
                .$sample.$more
                .(count($missing) > 8 ? ' (łącznie '.count($missing).')' : '')
                .'. Dodaj grupę lub wskaż grupę domyślną dla pozycji bez kategorii.'
            );
        }

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $products
     * @return list<array<string, mixed>>
     */
    private function applyGlobalDiscount(array $products, string $manufacturer, ?float $defaultDiscount): array
    {
        if ($defaultDiscount === null) {
            return $products;
        }

        $this->upsertGroup($manufacturer, AssortmentGroup::GLOBAL_NAME, $defaultDiscount, true);

        $out = [];
        foreach ($products as $product) {
            $catalog = (float) ($product['catalog_price_net'] ?? 0);
            $product['discount_percent'] = $defaultDiscount;
            $product['purchase_price'] = round($catalog * (1 - ($defaultDiscount / 100)), 2);
            $product['assortment_group_id'] = null;
            $out[] = $product;
        }

        return $out;
    }

    private function upsertGroup(
        string $manufacturer,
        string $name,
        float $discount,
        bool $isGlobal,
    ): AssortmentGroup {
        $discount = max(0.0, min(100.0, round($discount, 2)));

        /** @var AssortmentGroup $group */
        $group = AssortmentGroup::query()->updateOrCreate(
            [
                'manufacturer' => $manufacturer,
                'name' => $name,
            ],
            [
                'discount_percent' => $discount,
                'is_global' => $isGlobal,
            ],
        );

        return $group;
    }

    /**
     * @param  list<array{name?: string, discount_percent?: float|int|string}>  $groups
     * @return array<string, float>
     */
    private function normalizeGroupDefs(array $groups): array
    {
        $out = [];
        foreach ($groups as $row) {
            if (! is_array($row)) {
                continue;
            }
            $name = $this->normalizeGroupName($row['name'] ?? null);
            if ($name === null) {
                continue;
            }
            $discount = is_numeric($row['discount_percent'] ?? null)
                ? (float) $row['discount_percent']
                : 0.0;
            $out[$name] = max(0.0, min(100.0, round($discount, 2)));
        }

        return $out;
    }

    private function normalizeGroupName(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $name = trim((string) $value);
        if ($name === '') {
            return null;
        }

        return mb_substr($name, 0, 150);
    }
}
