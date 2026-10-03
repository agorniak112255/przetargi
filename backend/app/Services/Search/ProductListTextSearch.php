<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Product;
use App\Support\ProductModelFuzzy;
use Illuminate\Database\Eloquent\Builder;

/**
 * Wyszukiwanie tekstowe listy produktów (/products?q=) — po SKU, nazwie i producencie, z numerem modelu i marką.
 * Wydzielone bez zmiany działania z ProductController, żeby jedno pole wyszukiwania (GlobalSearch) znajdowało te same
 * karty co lista produktów, do której prowadzi „Pokaż wszystkie”.
 *
 * Osobna klasa, a nie ProductTextSearch: tamta to ranking pełnotekstowy (FULLTEXT po search_blob) wyszukiwarki AI,
 * ta — warunki LIKE listy produktów; mają inne wyniki i inną kolejność.
 */
final class ProductListTextSearch
{
    public function __construct(
        private readonly ProductModelFuzzy $modelFuzzy,
    ) {}

    /**
     * Wyszukiwanie po SKU, nazwie i producencie (z numerem modelu i marką) — warunki idą na przekazany builder.
     *
     * @param  Builder<Product>  $query
     */
    public function applyTextSearch(Builder $query, string $term): void
    {
        $brands = $this->modelFuzzy->catalogBrands($term);
        $modelNeedles = $this->modelFuzzy->catalogModelNeedles($term);

        if ($modelNeedles !== []) {
            $wordDigitPairs = $this->modelFuzzy->catalogModelWordDigitPairs($term);
            $query->where(function ($builder) use ($modelNeedles, $wordDigitPairs) {
                foreach ($modelNeedles as $needle) {
                    $esc = '%'.addcslashes($needle, '%_\\').'%';
                    $builder->orWhere('sku', 'like', $esc)
                        ->orWhere('name', 'like', $esc)
                        ->orWhere('search_blob', 'like', $esc);
                }
                foreach ($wordDigitPairs as [$word, $num]) {
                    $w = '%'.addcslashes($word, '%_\\').'%';
                    $n = '%'.addcslashes($num, '%_\\').'%';
                    $builder->orWhere(function ($q) use ($w, $n) {
                        foreach (['sku', 'name', 'search_blob'] as $col) {
                            $q->orWhere(function ($q2) use ($col, $w, $n) {
                                $q2->where($col, 'like', $w)->where($col, 'like', $n);
                            });
                        }
                    });
                }
            });
        } else {
            // znaki % i _ we frazie szukane dosłownie — bez ucieczki „__” pasowało do każdej karty (pełny skan)
            $like = '%'.addcslashes($term, '%_\\').'%';
            $codes = $this->modelFuzzy->shortCodes($term);
            $tokens = $brands === [] ? [] : $this->queryTokens($term, $brands);
            $query->where(function ($builder) use ($like, $term, $codes, $brands, $tokens) {
                $builder->where('sku', 'like', $like)
                    ->orWhere('name', 'like', $like)
                    ->orWhere('manufacturer', 'like', $like);
                if ($term !== '') {
                    $builder->orWhere('sku', $term);
                }
                foreach ($codes as $code) {
                    $esc = '%'.addcslashes($code, '%_\\').'%';
                    $builder->orWhere('sku', 'like', $esc)
                        ->orWhere('name', 'like', $esc);
                }
                foreach ($tokens as $token) {
                    $esc = '%'.addcslashes($token, '%_\\').'%';
                    $builder->orWhere('sku', 'like', $esc)
                        ->orWhere('name', 'like', $esc);
                }
                if ($brands !== [] && $codes === [] && $tokens === []) {
                    foreach ($brands as $brand) {
                        $esc = '%'.addcslashes($brand, '%_\\').'%';
                        $builder->orWhere('manufacturer', 'like', $esc)
                            ->orWhere('name', 'like', $esc);
                    }
                }
            });
        }
        if ($brands !== []) {
            $query->where(function ($builder) use ($brands) {
                foreach ($brands as $brand) {
                    $esc = '%'.addcslashes($brand, '%_\\').'%';
                    $builder->orWhere('manufacturer', 'like', $esc)
                        ->orWhere('name', 'like', $esc);
                }
            });
        }
    }

    /**
     * Wpisany numer katalogowy wychodzi pierwszy: dokładne SKU i karty wskazane kodem ERP XL (0), SKU zawierające
     * frazę (1), reszta (2). Zbioru wyników nie zawęża — dalsze sortowanie dokłada wywołujący.
     *
     * @param  Builder<Product>  $query
     * @param  list<int>  $erpIds  karty wskazane kodem towaru ERP XL (ErpCodeSearch)
     */
    public function orderByMatch(Builder $query, string $term, array $erpIds): void
    {
        $erpCase = $erpIds === [] ? '' : 'WHEN id IN ('.implode(',', array_fill(0, count($erpIds), '?')).') THEN 0 ';
        $query->orderByRaw(
            'CASE WHEN sku = ? THEN 0 '.$erpCase.'WHEN sku LIKE ? THEN 1 ELSE 2 END ASC',
            [$term, ...$erpIds, '%'.addcslashes($term, '%_\\').'%'],
        );
    }

    /**
     * @param  list<string>  $brands
     * @return list<string>
     */
    private function queryTokens(string $term, array $brands): array
    {
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];
        $norm = strtr(mb_strtolower($term), $map);
        $out = [];
        foreach (preg_split('/[\s,;:·•\/|+]+/u', $norm) ?: [] as $token) {
            $c = preg_replace('/[^a-z0-9]/', '', $token) ?? '';
            if (mb_strlen($c) < 4 || in_array($c, $brands, true)) {
                continue;
            }
            $out[] = $c;
        }

        return array_values(array_unique($out));
    }
}
