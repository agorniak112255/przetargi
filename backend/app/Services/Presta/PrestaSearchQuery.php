<?php

declare(strict_types=1);

namespace App\Services\Presta;

use App\Models\Product;
use App\Support\ProductSizeVariant;

final class PrestaSearchQuery
{
    public function __construct(
        private readonly ProductSizeVariant $sizes,
    ) {}

    public function ean(Product $product): string
    {
        $ean = preg_replace('/\D+/', '', (string) $product->ean) ?? '';

        return mb_strlen($ean) >= 8 ? $ean : '';
    }

    public function sku(Product $product): string
    {
        return trim((string) $product->sku);
    }

    public function brand(Product $product): string
    {
        return trim((string) $product->manufacturer);
    }

    public function nameNeedle(Product $product): string
    {
        $raw = trim((string) $product->name);
        $stripped = $this->sizes->stripSizeFromName($raw);
        $needle = $stripped !== '' ? $stripped : $raw;
        if (mb_strlen($needle) < 4) {
            return '';
        }

        return mb_substr($needle, 0, 48);
    }

    /**
     * @return list<string>
     */
    public function nameTokens(Product $product): array
    {
        $needle = $this->nameNeedle($product);
        if ($needle === '') {
            return [];
        }
        $tokens = [$needle];
        if (preg_match('/[A-Za-z]*\d[\w.\-]{2,}/u', $needle, $m) === 1) {
            $tok = trim((string) $m[0]);
            if (mb_strlen($tok) >= 4 && mb_strtolower($tok) !== mb_strtolower($needle)) {
                $tokens[] = $tok;
            }
        }

        return $tokens;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function rowMatchesCode(Product $product, array $row): bool
    {
        $ean = $this->ean($product);
        $rowEan = preg_replace('/\D+/', '', (string) ($row['ean13'] ?? '')) ?? '';
        if ($ean !== '' && $ean === $rowEan) {
            return true;
        }
        $sku = $this->sku($product);
        if ($sku === '') {
            return false;
        }
        $ref = trim((string) ($row['reference'] ?? ''));

        return $ref !== '' && ($ref === $sku || str_starts_with($ref, $sku));
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function rowMatchesBrandAndName(Product $product, array $row): bool
    {
        $brand = $this->compact($this->brand($product));
        $rowBrand = $this->compact((string) ($row['manufacturer'] ?? ''));
        if ($brand === '' || $rowBrand === '') {
            return false;
        }
        if (! str_contains($brand, $rowBrand) && ! str_contains($rowBrand, $brand)) {
            return false;
        }
        $hay = $this->compact((string) ($row['name'] ?? ''));
        if ($hay === '') {
            return false;
        }
        foreach ($this->nameTokens($product) as $token) {
            $compact = $this->compact($token);
            if ($compact !== '' && (str_contains($hay, $compact) || str_contains($compact, $hay))) {
                return true;
            }
        }

        return false;
    }

    public function likeContains(string $value): string
    {
        return '%'.$this->escapeLike($value).'%';
    }

    public function likePrefix(string $value): string
    {
        return $this->escapeLike($value).'%';
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    private function compact(string $s): string
    {
        $s = mb_strtolower($s);
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];

        return preg_replace('/[^a-z0-9]/', '', strtr($s, $map)) ?? '';
    }
}
