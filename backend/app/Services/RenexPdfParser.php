<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Cennik odzieży RENEX: „FARTUCH STANDARD CE-FARTU.065” + sekcja TKANINA/DZIANINA.
 * Ceny często są tylko na bitmapach — wtedy catalog_price = null.
 */
final class RenexPdfParser
{
    /**
     * @return list<array{
     *     sku: string,
     *     name: string,
     *     catalog_price: float|null,
     *     discount: float,
     *     purchase: float|null,
     *     currency: string,
     *     category: string|null
     * }>
     */
    public function parse(string $text): array
    {
        $category = null;
        $products = [];
        $seen = [];

        foreach (preg_split("/\r\n|\n|\r/", $text) ?: [] as $raw) {
            $line = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
            if ($line === '' || preg_match('/^nazwa\b/ui', $line) === 1) {
                continue;
            }
            if (preg_match('/^(TKANINA|DZIANINA)\b/ui', $line) === 1) {
                $category = mb_substr($line, 0, 80);

                continue;
            }
            if (preg_match(
                '/^(?:\d+\.\s*)?(?P<name>.*?)\s+(?P<sku>CE-[A-Z0-9][A-Z0-9.-]{2,})\s*(?P<price>\d+[.,]\d{2})?/u',
                $line,
                $m
            ) !== 1) {
                continue;
            }
            $sku = strtoupper($m['sku']);
            if (isset($seen[$sku])) {
                continue;
            }
            $name = trim((string) $m['name']);
            $name = preg_replace('/^\d+\.\s*/', '', $name) ?? $name;
            $name = trim($name);
            if ($name === '') {
                $name = $sku;
            }
            $price = null;
            if (isset($m['price']) && $m['price'] !== '') {
                $price = (float) str_replace(',', '.', $m['price']);
            }
            $seen[$sku] = true;
            $products[] = [
                'sku' => $sku,
                'name' => $name,
                'catalog_price' => $price,
                'discount' => 0.0,
                'purchase' => $price,
                'currency' => 'PLN',
                'category' => $category,
            ];
        }

        return $products;
    }
}
