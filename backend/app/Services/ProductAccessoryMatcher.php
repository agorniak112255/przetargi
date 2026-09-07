<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PrestaProductMatch;
use App\Models\Product;
use App\Support\ProductModelFuzzy;
use Illuminate\Support\Facades\Schema;

final class ProductAccessoryMatcher
{
    public function __construct(
        private readonly ProductModelFuzzy $fuzzy,
    ) {}

    /**
     * @param  array{sku?: string, ean?: string, name?: string, manufacturer?: string, presta_id?: int}  $candidate
     * @return array{product: Product, method: string, score: int}|null
     */
    public function match(array $candidate, ?Product $except = null): ?array
    {
        $exceptId = $except instanceof Product ? (int) $except->id : 0;
        $prestaId = (int) ($candidate['presta_id'] ?? 0);
        if ($prestaId > 0 && Schema::hasTable('presta_product_matches')) {
            $linked = PrestaProductMatch::query()
                ->where('presta_id', $prestaId)
                ->orderByDesc('score')
                ->first();
            if ($linked instanceof PrestaProductMatch) {
                $product = Product::query()->find($linked->product_id);
                if ($product instanceof Product && (int) $product->id !== $exceptId) {
                    return ['product' => $product, 'method' => 'presta_id', 'score' => 99];
                }
            }
        }

        $ean = preg_replace('/\D+/', '', (string) ($candidate['ean'] ?? '')) ?? '';
        if (strlen($ean) >= 8) {
            $product = Product::query()->where('ean', $ean)->first();
            if ($product instanceof Product && (int) $product->id !== $exceptId) {
                return ['product' => $product, 'method' => 'ean', 'score' => 98];
            }
        }

        $sku = trim((string) ($candidate['sku'] ?? ''));
        if ($sku !== '') {
            $product = Product::query()->where('sku', $sku)->first();
            if (! $product instanceof Product) {
                $compact = $this->compact($sku);
                if ($compact !== '' && strlen($compact) >= 3) {
                    $product = Product::query()
                        ->whereRaw(
                            "LOWER(REPLACE(REPLACE(REPLACE(REPLACE(sku,' ',''),'-',''),'.',''),'/','')) = ?",
                            [$compact]
                        )
                        ->first();
                }
            }
            if ($product instanceof Product && (int) $product->id !== $exceptId) {
                return ['product' => $product, 'method' => 'sku', 'score' => 96];
            }
        }

        $name = trim((string) ($candidate['name'] ?? ''));
        $brand = trim((string) ($candidate['manufacturer'] ?? ''));
        if ($name === '' || mb_strlen($this->compact($name)) < 8) {
            return null;
        }

        $query = Product::query()->select(['id', 'sku', 'name', 'manufacturer', 'ean']);
        if ($brand !== '') {
            $query->where('manufacturer', 'like', '%'.addcslashes($brand, '%_\\').'%');
        }
        $needles = preg_split('/\s+/u', $name) ?: [];
        foreach (array_slice($needles, 0, 4) as $token) {
            $token = trim($token);
            if (mb_strlen($token) < 4) {
                continue;
            }
            $query->where('name', 'like', '%'.addcslashes($token, '%_\\').'%');
        }
        $best = null;
        $bestScore = 0;
        foreach ($query->limit(20)->get() as $row) {
            if ($exceptId > 0 && (int) $row->id === $exceptId) {
                continue;
            }
            $ghost = new Product;
            $ghost->forceFill([
                'sku' => (string) $row->sku,
                'name' => (string) $row->name,
                'manufacturer' => (string) $row->manufacturer,
            ]);
            $score = $this->fuzzy->score(trim($brand.' '.$name.' '.$sku), $ghost);
            if ($score >= 86 && $score > $bestScore) {
                $best = $row;
                $bestScore = $score;
            }
        }
        if ($best instanceof Product) {
            return ['product' => $best, 'method' => 'fuzzy_model', 'score' => min(94, $bestScore)];
        }

        return null;
    }

    public function compact(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];

        return preg_replace('/[^a-z0-9]/', '', strtr($value, $map)) ?? '';
    }

    public function linkKey(array $candidate): string
    {
        $prestaId = (int) ($candidate['presta_id'] ?? $candidate['presta_related_id'] ?? 0);
        if ($prestaId > 0) {
            return 'p:'.$prestaId;
        }
        $ean = preg_replace('/\D+/', '', (string) ($candidate['ean'] ?? '')) ?? '';
        if (strlen($ean) >= 8) {
            return 'e:'.$ean;
        }
        $sku = $this->compact((string) ($candidate['sku'] ?? ''));
        if ($sku !== '') {
            return 's:'.mb_substr($sku, 0, 64);
        }
        $name = $this->compact((string) ($candidate['name'] ?? ''));

        return 'n:'.mb_substr(sha1($name), 0, 16);
    }
}
