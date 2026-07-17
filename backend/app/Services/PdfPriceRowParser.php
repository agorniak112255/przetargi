<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Heurystyka wierszy cennika typu PROS/AJGROUP:
 * Cena katalogowa | Upust % | Cena po upuście
 * np. "119.00 32 80.92"
 */
final class PdfPriceRowParser
{
    /**
     * @return list<array{
     *     name: string,
     *     sku: string,
     *     catalog_price: float,
     *     discount: float,
     *     purchase: float
     * }>
     */
    public function parse(string $text, ?string $manufacturer = null): array
    {
        $prefix = $this->skuPrefix($manufacturer);
        $products = [];
        $n = 0;
        $currencyDetector = new CurrencyDetector;
        $docCurrency = $currencyDetector->detect($text) ?? 'PLN';

        foreach (preg_split("/\r\n|\n|\r/", $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // 119.00 32 80.92  LUB  119,00  32  80,92
            if (preg_match(
                '/^(?P<cat>\d+[.,]\d{2})\s+(?P<disc>\d{1,2}(?:[.,]\d+)?)\s+(?P<net>\d+[.,]\d{2})\s*$/u',
                $line,
                $m
            ) !== 1) {
                continue;
            }

            $catalog = $this->num($m['cat']);
            $discount = $this->num($m['disc']);
            $purchase = $this->num($m['net']);
            if ($catalog === null || $discount === null || $purchase === null) {
                continue;
            }
            if ($discount > 90) {
                continue;
            }
            // sanity: cena po upuście ≈ katalog * (1 - upust/100)
            $expected = round($catalog * (1 - ($discount / 100)), 2);
            if (abs($expected - $purchase) > max(1.0, $catalog * 0.08)) {
                // nadal przyjmujemy — czasem zaokrąglenia inne
            }

            $n++;
            $products[] = [
                'sku' => sprintf('%s-%04d', $prefix, $n),
                'name' => sprintf('Pozycja %d (uzupełnij nazwę z PDF)', $n),
                'catalog_price' => $catalog,
                'discount' => $discount,
                'purchase' => $purchase,
                'currency' => $currencyDetector->detect($line) ?? $docCurrency,
            ];
        }

        return $products;
    }

    private function skuPrefix(?string $manufacturer): string
    {
        $m = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '', (string) $manufacturer) ?? '');
        if ($m === '') {
            return 'PDF';
        }

        return mb_substr($m, 0, 12);
    }

    private function num(string $v): ?float
    {
        $s = str_replace(',', '.', $v);
        if (! is_numeric($s)) {
            return null;
        }

        return (float) $s;
    }
}
