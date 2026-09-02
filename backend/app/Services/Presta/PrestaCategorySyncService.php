<?php

declare(strict_types=1);

namespace App\Services\Presta;

use App\Models\PrestaCategory;
use App\Models\PrestaCategoryMap;
use App\Models\Product;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

final class PrestaCategorySyncService
{
    public function __construct(
        private readonly PrestaSettingsService $settings,
    ) {}

    /**
     * @return array{imported: int, maps: int}
     */
    public function sync(): array
    {
        $rows = $this->fetchFromPresta();
        $imported = $this->storeCategories($rows);
        $maps = $this->ensureLocalMaps();

        return ['imported' => $imported, 'maps' => $maps];
    }

    /**
     * @return list<array{presta_id: int, parent_presta_id: int, name: string, level_depth: int, active: bool}>
     */
    private function fetchFromPresta(): array
    {
        $cfg = $this->settings->resolve();
        if (! $cfg['enabled'] || $cfg['host'] === '' || $cfg['database'] === '') {
            throw new RuntimeException('Sklep Presta nie jest włączony albo brak połączenia z bazą.');
        }
        Config::set('database.connections.prestashop.host', $cfg['host']);
        Config::set('database.connections.prestashop.port', $cfg['port']);
        Config::set('database.connections.prestashop.database', $cfg['database']);
        Config::set('database.connections.prestashop.username', $cfg['username']);
        Config::set('database.connections.prestashop.password', $cfg['password']);
        DB::purge('prestashop');

        $prefix = $cfg['prefix'];
        $lang = max(1, (int) $cfg['id_lang']);
        try {
            $query = DB::connection('prestashop')->table($prefix.'category as c')
                ->join($prefix.'category_lang as cl', function ($join) use ($lang): void {
                    $join->on('cl.id_category', '=', 'c.id_category')
                        ->where('cl.id_lang', '=', $lang);
                })
                ->orderBy('c.level_depth')
                ->orderBy('cl.name');
            if (Schema::connection('prestashop')->hasColumn($prefix.'category', 'active')) {
                $query->where('c.active', 1);
            }
            $raw = $query->get(['c.id_category', 'c.id_parent', 'c.level_depth', 'cl.name']);
        } catch (Throwable $e) {
            throw new RuntimeException('Nie udało się pobrać kategorii z Presty: '.$e->getMessage());
        }

        $out = [];
        foreach ($raw as $row) {
            $id = (int) $row->id_category;
            $name = trim((string) $row->name);
            if ($id <= 1 || $name === '') {
                continue;
            }
            $out[] = [
                'presta_id' => $id,
                'parent_presta_id' => (int) $row->id_parent,
                'name' => $name,
                'level_depth' => (int) $row->level_depth,
                'active' => true,
            ];
        }

        return $out;
    }

    /**
     * @param  list<array{presta_id: int, parent_presta_id: int, name: string, level_depth: int, active: bool}>  $rows
     */
    public function storeCategories(array $rows): int
    {
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['presta_id']] = $row;
        }
        $seen = [];
        foreach ($byId as $id => $row) {
            $path = $this->pathFor($id, $byId);
            PrestaCategory::query()->updateOrCreate(
                ['presta_id' => $id],
                [
                    'parent_presta_id' => (int) $row['parent_presta_id'],
                    'name' => (string) $row['name'],
                    'path' => $path,
                    'level_depth' => (int) $row['level_depth'],
                    'active' => true,
                ]
            );
            $seen[] = $id;
        }
        if ($seen !== []) {
            PrestaCategory::query()->whereNotIn('presta_id', $seen)->update(['active' => false]);
        }

        return count($seen);
    }

    public function ensureLocalMaps(): int
    {
        $locals = Product::query()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');
        $exact = [];
        foreach (PrestaCategory::query()->where('active', true)->get() as $cat) {
            $exact[mb_strtolower(trim((string) $cat->name))] = (int) $cat->presta_id;
        }
        $count = 0;
        foreach ($locals as $local) {
            $local = trim((string) $local);
            if ($local === '') {
                continue;
            }
            $map = PrestaCategoryMap::query()->firstOrNew(['local_category' => $local]);
            if (! $map->exists) {
                $key = mb_strtolower($local);
                if (isset($exact[$key])) {
                    $map->presta_id = $exact[$key];
                }
                $map->save();
            }
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<int, array{presta_id: int, parent_presta_id: int, name: string, level_depth: int, active: bool}>  $byId
     */
    private function pathFor(int $id, array $byId): string
    {
        $parts = [];
        $cur = $id;
        $guard = 0;
        while ($cur > 1 && isset($byId[$cur]) && $guard++ < 24) {
            array_unshift($parts, (string) $byId[$cur]['name']);
            $next = (int) $byId[$cur]['parent_presta_id'];
            if ($next === $cur) {
                break;
            }
            $cur = $next;
        }

        return implode(' / ', $parts);
    }
}
