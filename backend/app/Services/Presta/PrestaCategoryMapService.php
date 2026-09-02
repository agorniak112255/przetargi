<?php

declare(strict_types=1);

namespace App\Services\Presta;

use App\Models\PrestaCategory;
use App\Models\PrestaCategoryMap;
use App\Models\Product;

final class PrestaCategoryMapService
{
    public function __construct(
        private readonly PrestaSettingsService $settings,
        private readonly ProductCategorySanitizer $sanitizer,
    ) {}

    /**
     * @return array{
     *     categories: list<array<string, mixed>>,
     *     maps: list<array<string, mixed>>,
     *     default_presta_id: int,
     *     garbage_categories: int,
     *     garbage_products: int
     * }
     */
    public function listing(): array
    {
        $counts = Product::query()
            ->selectRaw('category, COUNT(*) as cnt')
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->groupBy('category')
            ->pluck('cnt', 'category');

        $categories = PrestaCategory::query()
            ->orderBy('path')
            ->orderBy('name')
            ->get()
            ->map(static fn (PrestaCategory $row): array => [
                'presta_id' => (int) $row->presta_id,
                'parent_presta_id' => (int) $row->parent_presta_id,
                'name' => (string) $row->name,
                'path' => (string) ($row->path !== '' ? $row->path : $row->name),
                'level_depth' => (int) $row->level_depth,
                'active' => (bool) $row->active,
            ])
            ->all();

        $maps = [];
        $seen = [];
        $garbageCategories = 0;
        $garbageProducts = 0;
        foreach (PrestaCategoryMap::query()->orderBy('local_category')->get() as $map) {
            $local = (string) $map->local_category;
            $seen[$local] = true;
            $cnt = (int) ($counts[$local] ?? 0);
            $mapped = $map->presta_id !== null ? (int) $map->presta_id : null;
            if ($this->sanitizer->isGarbage($local) && ($mapped === null || $mapped <= 0)) {
                $garbageCategories++;
                $garbageProducts += $cnt;

                continue;
            }
            $maps[] = $this->mapRow($local, $mapped, $cnt);
        }
        foreach ($counts as $local => $cnt) {
            $local = trim((string) $local);
            if ($local === '' || isset($seen[$local])) {
                continue;
            }
            if ($this->sanitizer->isGarbage($local)) {
                $garbageCategories++;
                $garbageProducts += (int) $cnt;

                continue;
            }
            $maps[] = $this->mapRow($local, null, (int) $cnt);
        }
        $maps = array_values(array_filter(
            $maps,
            static fn (array $row): bool => (int) $row['product_count'] > 0
        ));
        usort($maps, static fn (array $a, array $b): int => strcasecmp((string) $a['local_category'], (string) $b['local_category']));

        return [
            'categories' => $categories,
            'maps' => $maps,
            'default_presta_id' => $this->defaultId(),
            'garbage_categories' => $garbageCategories,
            'garbage_products' => $garbageProducts,
        ];
    }

    public function defaultId(): int
    {
        return max(1, (int) $this->settings->resolve()['id_category_default']);
    }

    /**
     * @param  list<array{local_category?: mixed, presta_id?: mixed}>  $rows
     */
    public function saveMaps(array $rows): void
    {
        foreach ($rows as $row) {
            $local = trim((string) ($row['local_category'] ?? ''));
            if ($local === '') {
                continue;
            }
            $prestaId = isset($row['presta_id']) ? (int) $row['presta_id'] : 0;
            PrestaCategoryMap::query()->updateOrCreate(
                ['local_category' => $local],
                ['presta_id' => $prestaId > 0 ? $prestaId : null]
            );
        }
    }

    public function resolveId(?string $localCategory): int
    {
        $fallback = max(1, (int) $this->settings->resolve()['id_category_default']);
        $local = trim((string) $localCategory);
        if ($local === '') {
            return $fallback;
        }
        $map = PrestaCategoryMap::query()
            ->whereRaw('LOWER(local_category) = ?', [mb_strtolower($local)])
            ->first();
        if ($map instanceof PrestaCategoryMap && (int) $map->presta_id > 0) {
            return (int) $map->presta_id;
        }

        return $fallback;
    }

    /**
     * Uzupełnia puste mapowania tylko gdy nazwa/ścieżka/liść Presty jest jednoznaczna.
     */
    public function autoFillMaps(): int
    {
        $lookup = $this->uniquePrestaLookup();
        $locals = Product::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');
        $filled = 0;
        foreach ($locals as $local) {
            $local = trim((string) $local);
            if ($local === '' || $this->sanitizer->isGarbage($local)) {
                continue;
            }
            $map = PrestaCategoryMap::query()->firstOrNew(['local_category' => $local]);
            if ((int) ($map->presta_id ?? 0) > 0) {
                if (! $map->exists) {
                    $map->save();
                }

                continue;
            }
            $prestaId = $this->uniqueMatch($local, $lookup);
            if ($prestaId !== null) {
                $map->presta_id = $prestaId;
                $filled++;
            }
            $map->save();
        }

        return $filled;
    }

    /**
     * Nadpisuje products.category ścieżką z Presty. Stare nazwy zostają w mapie (cenniki).
     */
    public function applyMappedNames(): int
    {
        $updated = 0;
        $maps = PrestaCategoryMap::query()
            ->whereNotNull('presta_id')
            ->where('presta_id', '>', 0)
            ->get();
        foreach ($maps as $map) {
            $cat = PrestaCategory::query()->where('presta_id', (int) $map->presta_id)->first();
            if (! $cat instanceof PrestaCategory) {
                continue;
            }
            $path = trim((string) ($cat->path !== '' ? $cat->path : $cat->name));
            $local = trim((string) $map->local_category);
            if ($path === '' || $local === '' || mb_strtolower($path) === mb_strtolower($local)) {
                continue;
            }
            $updated += Product::query()->where('category', $local)->update(['category' => $path]);
            PrestaCategoryMap::query()->updateOrCreate(
                ['local_category' => $path],
                ['presta_id' => (int) $map->presta_id]
            );
        }

        return $updated;
    }

    /**
     * @return array{local_category: string, presta_id: int|null, product_count: int, presta_path: string|null}
     */
    private function mapRow(string $local, ?int $prestaId, int $count): array
    {
        $path = null;
        if ($prestaId !== null && $prestaId > 0) {
            $cat = PrestaCategory::query()->where('presta_id', $prestaId)->first();
            $path = $cat instanceof PrestaCategory
                ? (string) ($cat->path !== '' ? $cat->path : $cat->name)
                : null;
        }

        return [
            'local_category' => $local,
            'presta_id' => $prestaId,
            'product_count' => $count,
            'presta_path' => $path,
        ];
    }

    /**
     * @return array{name: array<string, list<int>>, leaf: array<string, list<int>>, path: array<string, list<int>>}
     */
    private function uniquePrestaLookup(): array
    {
        $name = [];
        $leaf = [];
        $path = [];
        foreach (PrestaCategory::query()->where('active', true)->get() as $cat) {
            $id = (int) $cat->presta_id;
            $full = trim((string) ($cat->path !== '' ? $cat->path : $cat->name));
            $nameKey = mb_strtolower(trim((string) $cat->name));
            $pathKey = mb_strtolower($full);
            $parts = preg_split('/\s*\/\s*/u', $full) ?: [];
            $leafKey = mb_strtolower(trim((string) (end($parts) ?: $cat->name)));
            if ($nameKey !== '') {
                $name[$nameKey][] = $id;
            }
            if ($pathKey !== '') {
                $path[$pathKey][] = $id;
            }
            if ($leafKey !== '') {
                $leaf[$leafKey][] = $id;
            }
        }

        return ['name' => $name, 'leaf' => $leaf, 'path' => $path];
    }

    /**
     * @param  array{name: array<string, list<int>>, leaf: array<string, list<int>>, path: array<string, list<int>>}  $lookup
     */
    private function uniqueMatch(string $local, array $lookup): ?int
    {
        $key = mb_strtolower(trim($local));
        if ($key === '') {
            return null;
        }
        foreach (['path', 'name', 'leaf'] as $bucket) {
            $ids = $lookup[$bucket][$key] ?? [];
            if (count($ids) === 1) {
                return (int) $ids[0];
            }
        }

        return null;
    }
}
