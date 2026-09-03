<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;

/**
 * Producenci z katalogu + aliasy z config — kontekst dla analizy SIWZ przez model.
 */
final class CatalogManufacturerContext
{
    private const CACHE_KEY = 'catalog.manufacturers.distinct.v1';

    private const CACHE_TTL_SECONDS = 3600;

    /**
     * @return list<string>
     */
    public function catalogManufacturers(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): array {
            return Product::query()
                ->whereNotNull('manufacturer')
                ->where('manufacturer', '!=', '')
                ->distinct()
                ->orderBy('manufacturer')
                ->pluck('manufacturer')
                ->map(static fn (mixed $name): string => trim((string) $name))
                ->filter(static fn (string $name): bool => $name !== '')
                ->values()
                ->all();
        });
    }

    public function promptBlock(): string
    {
        $lines = $this->catalogManufacturers();
        if ($lines === []) {
            return 'Producenci w katalogu: (pusto).';
        }

        return 'Producenci obecni w katalogu (pole manufacturer — użyj dokładnie jednej nazwy lub null): '
            .implode('; ', $lines).'.';
    }

    public function matchManufacturer(?string $guess): ?string
    {
        $guess = trim((string) $guess);
        if ($guess === '') {
            return null;
        }
        $needle = $this->compact($guess);
        if ($needle === '') {
            return null;
        }
        foreach ($this->catalogManufacturers() as $canonical) {
            if ($this->compact($canonical) === $needle) {
                return $canonical;
            }
        }
        foreach ($this->catalogManufacturers() as $canonical) {
            $c = $this->compact($canonical);
            if ($c !== '' && (str_contains($c, $needle) || str_contains($needle, $c))) {
                return $canonical;
            }
        }
        foreach ($this->configAliasKeys() as $alias) {
            if ($alias === $needle) {
                foreach ($this->catalogManufacturers() as $canonical) {
                    if (str_contains($this->compact($canonical), $alias) || str_contains($alias, $this->compact($canonical))) {
                        return $canonical;
                    }
                }

                return null;
            }
        }

        return null;
    }

    public function hasProductsForManufacturer(string $canonical): bool
    {
        $canonical = trim($canonical);
        if ($canonical === '') {
            return false;
        }
        $compact = $this->compact($canonical);

        return Product::query()
            ->where(function ($q) use ($canonical, $compact): void {
                $q->where('manufacturer', $canonical)
                    ->orWhere('manufacturer', 'like', '%'.addcslashes($canonical, '%_\\').'%');
                if ($compact !== '' && $compact !== $this->compact($canonical)) {
                    $like = '%'.addcslashes($compact, '%_\\').'%';
                    $q->orWhereRaw('LOWER(manufacturer) LIKE ?', [mb_strtolower($like)]);
                }
            })
            ->exists();
    }

    /**
     * @return list<string>
     */
    public function configAliasKeys(): array
    {
        $out = [];
        foreach (array_keys((array) config('enrichment.manufacturer_domains', [])) as $key) {
            $c = $this->compact((string) $key);
            if ($c !== '') {
                $out[] = $c;
            }
        }

        return array_values(array_unique($out));
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private function compact(string $text): string
    {
        $t = mb_strtolower($text);
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', ' ' => '', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];

        return (string) preg_replace('/[^a-z0-9]/u', '', strtr($t, $map));
    }
}
