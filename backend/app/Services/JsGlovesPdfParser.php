<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Heurystyka cenników JS GLOVES / Szewczyk:
 * „Rękawice … ROC5 11,008,36” = katalog 11,00 + po upuście 8,36 (upust z nagłówka -24%).
 */
final class JsGlovesPdfParser
{
    /**
     * @return list<array{
     *     sku: string,
     *     name: string,
     *     catalog_price: float,
     *     discount: float,
     *     purchase: float,
     *     currency: string,
     *     category: string|null
     * }>
     */
    public function parse(string $text, ?string $manufacturer = null): array
    {
        $discount = 24.0;
        if (preg_match('/-?\s*(\d{1,2})\s*%/', $text, $dm) === 1) {
            $discount = (float) $dm[1];
        }

        $currentCategory = null;
        $products = [];
        $seen = [];

        foreach (preg_split("/\r\n|\n|\r/", $text) ?: [] as $rawLine) {
            $line = trim(preg_replace('/\s+/u', ' ', $rawLine) ?? $rawLine);
            if ($line === '') {
                continue;
            }

            if ($this->looksLikeCategory($line)) {
                $currentCategory = mb_substr($line, 0, 120);
                continue;
            }

            // dwie ceny na końcu (sklejone 11,008,36 lub ze spacją)
            if (preg_match('/(?P<p1>\d+,\d{2})\s*(?P<p2>\d+,\d{2})\s*$/u', $line, $priceM) !== 1) {
                continue;
            }
            preg_match_all('/\b(RO[A-Z0-9][A-Z0-9\/-]{0,10})\b/', $line, $skuM);
            $skus = $skuM[1] ?? [];
            if ($skus === []) {
                continue;
            }
            // ostatni symbol RO* przed cenami (najbliżej cen)
            $sku = strtoupper((string) end($skus));
            if (isset($seen[$sku])) {
                continue;
            }

            $catalog = (float) str_replace(',', '.', $priceM['p1']);
            $purchase = (float) str_replace(',', '.', $priceM['p2']);
            if ($catalog <= 0 || $catalog > 50000 || $purchase <= 0 || $purchase > $catalog * 1.05) {
                continue;
            }

            $pos = strpos($line, $sku);
            $name = $pos !== false ? trim(substr($line, 0, $pos)) : $line;
            $name = preg_replace('/^\d+\s*/', '', $name) ?? $name;
            $name = trim($name);
            if (mb_strlen($name) < 4) {
                $name = 'JS GLOVES '.$sku;
            }

            $seen[$sku] = true;
            $products[] = [
                'sku' => $sku,
                'name' => $name,
                'catalog_price' => $catalog,
                'discount' => $discount,
                'purchase' => $purchase,
                'currency' => 'PLN',
                'category' => $currentCategory,
            ];
        }

        return $products;
    }

    public function looksLike(string $text): bool
    {
        $t = mb_strtolower($text);

        return str_contains($t, 'js gloves')
            || str_contains($t, 'szewczyk')
            || (str_contains($t, 'nazwa wyrobu') && str_contains($t, 'symbol') && preg_match('/\broc\d/i', $t) === 1);
    }

    private function looksLikeCategory(string $line): bool
    {
        if (mb_strlen($line) < 12 || mb_strlen($line) > 140) {
            return false;
        }
        if (preg_match('/\d+,\d{2}\d+,\d{2}/', $line) === 1 || preg_match('/\d+,\d{2}\s+\d+,\d{2}/', $line) === 1) {
            return false;
        }

        return preg_match('/JS GLOVES|Line|odporność|Odporność|KEVLAR|COMFORT|TERMO/i', $line) === 1
            && preg_match('/\bRO[A-Z0-9]/i', $line) !== 1;
    }
}
