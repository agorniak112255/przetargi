<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PrestaProductMatch;
use App\Models\Product;
use Illuminate\Support\Facades\Schema;

final class ProductAccessoryMatcher
{
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
        if ($this->isConcreteSku($sku)) {
            $product = Product::query()->where('sku', $sku)->first();
            if (! $product instanceof Product) {
                $compact = $this->compact($sku);
                if (strlen($compact) >= 4) {
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

        return null;
    }

    public function isConcreteSku(string $sku): bool
    {
        $sku = trim($sku);

        return $sku !== '' && mb_strlen($sku) >= 3 && preg_match('/\d/', $sku) === 1;
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
        $sku = trim((string) ($candidate['sku'] ?? ''));
        if ($this->isConcreteSku($sku)) {
            return 's:'.mb_substr($this->compact($sku), 0, 64);
        }
        $name = $this->compact((string) ($candidate['name'] ?? ''));

        return 'n:'.mb_substr(sha1($name), 0, 16);
    }
}
