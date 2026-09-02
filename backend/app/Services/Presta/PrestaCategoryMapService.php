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
    ) {}

    /**
     * @return array{
     *     categories: list<array<string, mixed>>,
     *     maps: list<array<string, mixed>>,
     *     default_presta_id: int
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
        foreach (PrestaCategoryMap::query()->orderBy('local_category')->get() as $map) {
            $local = (string) $map->local_category;
            $seen[$local] = true;
            $maps[] = $this->mapRow($local, $map->presta_id !== null ? (int) $map->presta_id : null, (int) ($counts[$local] ?? 0));
        }
        foreach ($counts as $local => $cnt) {
            $local = trim((string) $local);
            if ($local === '' || isset($seen[$local])) {
                continue;
            }
            $maps[] = $this->mapRow($local, null, (int) $cnt);
        }
        usort($maps, static fn (array $a, array $b): int => strcasecmp((string) $a['local_category'], (string) $b['local_category']));

        return [
            'categories' => $categories,
            'maps' => $maps,
            'default_presta_id' => max(1, (int) $this->settings->resolve()['id_category_default']),
        ];
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
}
