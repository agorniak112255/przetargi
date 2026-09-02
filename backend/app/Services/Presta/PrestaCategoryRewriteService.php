<?php

declare(strict_types=1);

namespace App\Services\Presta;

use App\Models\PrestaCategory;
use App\Models\Product;
use App\Support\PpeAssortment;

final class PrestaCategoryRewriteService
{
    /** @var array<string, list<string>> */
    private const FAMILY_TOKENS = [
        PpeAssortment::FAMILY_GLOVES => ['rekawic', 'gloves', 'hand protection', 'guanti'],
        PpeAssortment::FAMILY_FOOTWEAR => ['obuwie', 'buty', 'shoes', 'footwear', 'sandal', 'trzewik'],
        PpeAssortment::FAMILY_HEARING => ['sluch', 'hearing', 'nausznik', 'ear muff', 'ear plug'],
        PpeAssortment::FAMILY_EYES => ['oczu', 'okular', 'gogl', 'eye', 'spectacles'],
        PpeAssortment::FAMILY_FACE => ['twarz', 'face', 'przylbic', 'oslony twarzy'],
        PpeAssortment::FAMILY_RESPIRATORY => ['oddech', 'respiratory', 'polmask', 'maska', 'ffp'],
        PpeAssortment::FAMILY_FALL => ['upadk', 'fall', 'asekurac', 'szelk', 'wysokosci'],
        PpeAssortment::FAMILY_APPAREL => ['odziez', 'clothing', 'kurtk', 'spodn', 'kombinezon'],
        PpeAssortment::FAMILY_HEAD => ['kask', 'helm', 'czapk', 'head'],
        PpeAssortment::FAMILY_KNEE => ['kolan', 'knee'],
        ProductCategorySanitizer::FAMILY_TOWEL => ['recznik', 'towel'],
        ProductCategorySanitizer::FAMILY_CLEANING => ['czystosci', 'chemia', 'czyszcz'],
    ];

    public function __construct(
        private readonly ProductCategorySanitizer $sanitizer,
        private readonly PrestaCategoryMapService $maps,
    ) {}

    /**
     * @return array{updated: int, cleared: int, skipped: int}
     */
    public function rewrite(): array
    {
        $tree = PrestaCategory::query()->where('active', true)->get();
        $pathCache = [];
        /** @var array<string, list<int>> $byPath */
        $byPath = [];
        /** @var list<int> $clearIds */
        $clearIds = [];
        $skipped = 0;

        Product::query()->select(['id', 'name', 'category'])->orderBy('id')
            ->chunkById(500, function ($products) use ($tree, &$pathCache, &$byPath, &$clearIds, &$skipped): void {
                foreach ($products as $product) {
                    if (! $product instanceof Product) {
                        continue;
                    }
                    $current = trim((string) ($product->category ?? ''));
                    $target = $this->resolvePath($product, $tree, $pathCache);
                    if ($target !== null && mb_strtolower($target) === mb_strtolower($current)) {
                        $skipped++;

                        continue;
                    }
                    if ($target !== null) {
                        $byPath[$target][] = (int) $product->id;

                        continue;
                    }
                    if ($this->sanitizer->isGarbage($current)) {
                        $clearIds[] = (int) $product->id;

                        continue;
                    }
                    $skipped++;
                }
            });

        $updated = 0;
        foreach ($byPath as $path => $ids) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                Product::query()->whereIn('id', $chunk)->update(['category' => $path]);
                $updated += count($chunk);
            }
        }
        $cleared = 0;
        foreach (array_chunk($clearIds, 1000) as $chunk) {
            Product::query()->whereIn('id', $chunk)->update(['category' => null]);
            $cleared += count($chunk);
        }

        $this->maps->autoFillMaps();

        return ['updated' => $updated, 'cleared' => $cleared, 'skipped' => $skipped];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PrestaCategory>  $tree
     * @param  array<string, string|null>  $pathCache
     */
    public function resolvePath(Product $product, $tree, array &$pathCache = []): ?string
    {
        $current = trim((string) ($product->category ?? ''));
        $name = (string) $product->name;
        $fromName = $this->sanitizer->familyFromText($name);
        if ($fromName !== null) {
            $cacheKey = $fromName.'|'.$this->extraKey($name);
            if (! array_key_exists($cacheKey, $pathCache)) {
                $pathCache[$cacheKey] = $this->bestTreePath($fromName, $name, $tree)
                    ?? (ProductCategorySanitizer::FAMILY_LABELS[$fromName] ?? null);
            }

            return $pathCache[$cacheKey];
        }
        if ($current !== '' && ! $this->sanitizer->isGarbage($current)) {
            $exact = $this->maps->resolveId($current);
            if ($exact > 0 && $exact !== $this->maps->defaultId()) {
                $cat = $tree->firstWhere('presta_id', $exact);
                if ($cat instanceof PrestaCategory) {
                    return $this->pathOf($cat);
                }
            }
            $byName = $this->uniqueNamePath($current, $tree);
            if ($byName !== null) {
                return $byName;
            }
            $fromCat = $this->sanitizer->familyFromText($current);
            if ($fromCat !== null) {
                return $this->bestTreePath($fromCat, $name.' '.$current, $tree)
                    ?? (ProductCategorySanitizer::FAMILY_LABELS[$fromCat] ?? null);
            }
        }

        return $this->sanitizer->inferLabel($name, $current !== '' ? $current : null);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PrestaCategory>  $tree
     */
    private function uniqueNamePath(string $local, $tree): ?string
    {
        $key = mb_strtolower(trim($local));
        $hits = $tree->filter(static function (PrestaCategory $cat) use ($key): bool {
            $name = mb_strtolower(trim((string) $cat->name));
            $path = mb_strtolower(trim((string) ($cat->path !== '' ? $cat->path : $cat->name)));

            return $name === $key || $path === $key;
        });
        if ($hits->count() !== 1) {
            return null;
        }
        $cat = $hits->first();

        return $cat instanceof PrestaCategory ? $this->pathOf($cat) : null;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, PrestaCategory>  $tree
     */
    private function bestTreePath(string $family, string $productName, $tree): ?string
    {
        $tokens = self::FAMILY_TOKENS[$family] ?? [];
        if ($tokens === [] || $tree->isEmpty()) {
            return null;
        }
        $nameNorm = $this->normalize($productName);
        $best = null;
        $bestScore = 0;
        foreach ($tree as $cat) {
            if (! $cat instanceof PrestaCategory) {
                continue;
            }
            $hay = $this->normalize($cat->name.' '.$cat->path);
            $hits = 0;
            foreach ($tokens as $token) {
                if ($token !== '' && str_contains($hay, $token)) {
                    $hits++;
                }
            }
            if ($hits === 0) {
                continue;
            }
            $score = ($hits * 10) + (int) $cat->level_depth;
            foreach (['baweln', 'papier', 'hotel', 'robocz', 'ochron'] as $extra) {
                if (str_contains($nameNorm, $extra) && str_contains($hay, $extra)) {
                    $score += 8;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $cat;
            }
        }

        return $best instanceof PrestaCategory ? $this->pathOf($best) : null;
    }

    private function extraKey(string $productName): string
    {
        $n = $this->normalize($productName);
        $bits = [];
        foreach (['baweln', 'papier', 'hotel', 'robocz', 'ochron'] as $extra) {
            if (str_contains($n, $extra)) {
                $bits[] = $extra;
            }
        }

        return implode(',', $bits);
    }

    private function pathOf(PrestaCategory $cat): string
    {
        $path = trim((string) ($cat->path !== '' ? $cat->path : $cat->name));

        return $path !== '' ? $path : (string) $cat->name;
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower(trim($s));

        return strtr($s, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z',
        ]);
    }
}
