<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Cały tekst PDF — wiersze „kod + nazwa + cena”, bez modelu.
 * Cenniki z tysiącami SKU nie mieszczą się w max_tokens.
 */
final class PdfGenericSkuPriceParser
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
    public function parse(string $text): array
    {
        $currency = (new CurrencyDetector)->detect($text) ?? 'PLN';
        $category = null;
        $seen = [];
        $products = [];

        foreach (preg_split("/\r\n|\n|\r/", $text) ?: [] as $raw) {
            $line = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
            if ($line === '' || mb_strlen($line) < 6) {
                continue;
            }
            if ($this->looksLikeHeader($line)) {
                continue;
            }
            if ($this->looksLikeCategory($line)) {
                $category = mb_substr($line, 0, 80);

                continue;
            }
            if (preg_match('/(?P<price>\d+[.,]\d{2})\s*(?:zł|pln|eur|€)?\s*$/ui', $line, $pm) !== 1) {
                continue;
            }
            $price = (float) str_replace(',', '.', $pm['price']);
            if ($price <= 0 || $price > 100000) {
                continue;
            }
            $head = trim(mb_substr($line, 0, mb_strlen($line) - mb_strlen($pm[0])));
            $sku = $this->extractSku($head);
            if ($sku === null || isset($seen[$sku])) {
                continue;
            }
            $name = trim(str_ireplace($sku, '', $head));
            $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
            if ($name === '' || mb_strlen($name) < 3) {
                $name = $sku;
            }
            $seen[$sku] = true;
            $products[] = [
                'sku' => $sku,
                'name' => mb_substr($name, 0, 180),
                'catalog_price' => $price,
                'discount' => 0.0,
                'purchase' => $price,
                'currency' => $currency,
                'category' => $category,
            ];
        }

        return $products;
    }

    private function extractSku(string $head): ?string
    {
        if (preg_match('/\b(CE-[A-Z0-9][A-Z0-9.-]{2,})\b/u', $head, $m) === 1) {
            return strtoupper($m[1]);
        }
        if (preg_match('/\b(D\d{6,})\b/u', $head, $m) === 1) {
            return strtoupper($m[1]);
        }
        if (preg_match('/\b([A-Z]{2,5}\d{2,}[A-Z0-9\/-]{0,16})\b/u', $head, $m) === 1) {
            return strtoupper($m[1]);
        }
        if (preg_match('/\b(\d{5,8})\b/u', $head, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function looksLikeHeader(string $line): bool
    {
        return preg_match('/^(lp|nazwa|kod|sku|cena|cennik|price|index|ilość|ilosc)\b/ui', $line) === 1
            && preg_match('/\d+[.,]\d{2}\s*(?:zł|pln|eur|€)?\s*$/ui', $line) !== 1;
    }

    private function looksLikeCategory(string $line): bool
    {
        if (preg_match('/\d+[.,]\d{2}/', $line) === 1) {
            return false;
        }

        return preg_match('/^(rękaw|rekaw|obuwie|odzież|odziez|ochron|hełm|helm|okulary|nauszn)/ui', $line) === 1
            && mb_strlen($line) < 70;
    }
}
