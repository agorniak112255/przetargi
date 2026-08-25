<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Heurystyka cenników Ansell / EMA / AlphaTec:
 * "N15S138 NV15S-00138 … 50 PCE / 3XL-5XL 40 PCE 2.62"
 */
final class AnsellEmaPdfParser
{
    /**
     * @return list<array{
     *     sku: string,
     *     name: string,
     *     catalog_price: float,
     *     discount: float,
     *     purchase: float|null,
     *     currency: string,
     *     pack_qty: int|null,
     *     packaging: string|null,
     *     category: string|null
     * }>
     */
    public function parse(string $text, ?string $manufacturer = null): array
    {
        $currentName = null;
        $currentCategory = null;
        $products = [];
        $seen = [];
        $currencyDetector = new CurrencyDetector;
        $docCurrency = $currencyDetector->detect($text) ?? 'EUR';

        foreach (preg_split("/\r\n|\n|\r/", $text) ?: [] as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }

            if ($this->looksLikeProductTitle($line)) {
                $currentName = $this->cleanTitle($line);

                continue;
            }

            if ($this->looksLikeSection($line)) {
                $currentCategory = mb_substr($line, 0, 100);

                continue;
            }

            if (! preg_match('/\b([A-Z]{2}\d{2}[A-Z]?-\d{5}(?:-\d{2})?)\b/', $line, $skuMatch)) {
                continue;
            }
            if (! preg_match('/(\d+[.,]\d{2})\s*$/', $line, $priceMatch)) {
                continue;
            }
            // uniknij nagłówków/spisu treści
            if (! preg_match('/\bPCE\b/i', $line) && ! preg_match('/\bPrice\b/i', $line)) {
                // wiele wierszy EMA ma PCE — bez PCE i bez typowego short code pomijamy
                if (! preg_match('/^[A-Z]\d{2}[A-Z]\d{3}\b/', $line)) {
                    continue;
                }
            }

            $sku = strtoupper($skuMatch[1]);
            $price = (float) str_replace(',', '.', $priceMatch[1]);
            if ($price <= 0 || $price > 50000) {
                continue;
            }
            if (isset($seen[$sku])) {
                continue;
            }

            $packQty = null;
            if (preg_match_all('/(\d+)\s+PCE\b/i', $line, $packs) && $packs[1] !== []) {
                $packQty = (int) end($packs[1]);
            }

            $short = null;
            if (preg_match('/^([A-Z0-9]{5,14})\s+'.preg_quote($sku, '/').'\b/', $line, $shortM)) {
                $short = $shortM[1];
            }

            $name = $currentName ?? ('AlphaTec '.$sku);
            if ($short !== null && ! str_contains($name, $short)) {
                $name .= ' ('.$short.')';
            }

            $rowCurrency = $currencyDetector->detect($line) ?? $docCurrency;

            $seen[$sku] = true;
            $products[] = [
                'sku' => $sku,
                'name' => $name,
                'catalog_price' => $price,
                'discount' => 0.0,
                'purchase' => $price,
                'currency' => $rowCurrency,
                'pack_qty' => $packQty,
                'packaging' => $packQty !== null ? $packQty.' PCE/karton' : null,
                'category' => $currentCategory,
            ];
        }

        return $products;
    }

    private function looksLikeProductTitle(string $line): bool
    {
        if (mb_strlen($line) < 8 || mb_strlen($line) > 120) {
            return false;
        }
        if (preg_match('/\bPCE\b|\bPrice\s*\(EUR\)|\bCONTENTS\b|\bPage\b/i', $line) === 1) {
            return false;
        }

        return preg_match('/AlphaTec|HyFlex|MICROFLEX|TouchNTuff|VersaTouch|BioClean|RINGERS|Viking/i', $line) === 1
            || preg_match('/\bModel\s+\d{2,4}\b/i', $line) === 1;
    }

    private function looksLikeSection(string $line): bool
    {
        return preg_match('/PROTECTION|ACCESSORIES|CONTENTS|TYPE\s+\d/i', $line) === 1
            && mb_strlen($line) < 80
            && preg_match('/\bPCE\b/', $line) !== 1;
    }

    private function cleanTitle(string $line): string
    {
        $line = str_replace(['TM', '®', '©'], '', $line);

        return trim(preg_replace('/\s+/', ' ', $line) ?? $line);
    }
}
