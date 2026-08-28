<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Cennik hurtowy SUNGBOO: karty „Cena netto” + cena w groszach (1628 = 16,28 zł).
 */
final class SungbooPdfParser
{
    /** @var list<string> */
    private const NAMED = [
        'INDUSTRIAL CUT',
        'PREMIUM CUT',
        'SPECIAL CUT',
        'EXTRA CUT',
        'YELLOW NITRILE',
        'COMFORT GRIP',
        'MICRO FIT',
        'SOFT GRIP',
        'SAFE WORK',
        'DOT GRIP',
        'TOP FOAM',
        'TOP SOFT',
        'TOP CUT',
        'FLEX 4',
    ];

    /**
     * @return list<array{
     *     sku: string,
     *     name: string,
     *     catalog_price: float,
     *     discount: float,
     *     purchase: float,
     *     currency: string,
     *     category: string|null,
     *     packaging: string|null
     * }>
     */
    public function parse(string $text): array
    {
        $category = null;
        $products = [];
        $seen = [];

        $blocks = preg_split('/(?=Cena\s*netto)/ui', $text) ?: [];
        foreach ($blocks as $block) {
            $cat = $this->detectCategory($block);
            if ($cat !== null) {
                $category = $cat;
            }
            $row = $this->parseBlock($block, $category);
            if ($row === null) {
                continue;
            }
            $sku = $this->uniqueSku($row['sku'], $seen);
            $row['sku'] = $sku;
            $seen[$sku] = true;
            $products[] = $row;
        }

        return $products;
    }

    public function looksLike(string $text): bool
    {
        if (preg_match_all('/cena\s*netto/ui', $text) < 3) {
            return false;
        }

        return preg_match('/sungboo|r[ęe]kawic|rkawic|en388|en420/ui', $text) === 1;
    }

    /**
     * @return array{
     *     sku: string,
     *     name: string,
     *     catalog_price: float,
     *     discount: float,
     *     purchase: float,
     *     currency: string,
     *     category: string|null,
     *     packaging: string|null
     * }|null
     */
    private function parseBlock(string $block, ?string $category): ?array
    {
        if ($this->isFooterOnly($block)) {
            return null;
        }

        $grosze = $this->findPriceGrosze($block);
        if ($grosze === null) {
            return null;
        }

        $flat = trim(preg_replace('/\s+/u', ' ', $block) ?? $block);
        $identity = $this->findSkuAndName($flat);
        $desc = $this->findDescription($flat);
        $pack = $this->findPack($flat);

        $sku = $identity['sku'];
        $name = $identity['name'];
        if ($name === '' && $desc !== null) {
            $name = mb_substr($desc, 0, 90);
        }
        if ($sku === '') {
            $slug = strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', mb_substr($name, 0, 24)) ?? '');
            $slug = trim($slug, '-');
            $sku = ($slug !== '' ? 'SB-'.$slug : 'SB').'-'.$grosze;
        }
        if ($name === '') {
            $name = $sku;
        }

        $price = round($grosze / 100, 2);

        return [
            'sku' => $sku,
            'name' => $name,
            'catalog_price' => $price,
            'discount' => 0.0,
            'purchase' => $price,
            'currency' => 'PLN',
            'category' => $category,
            'packaging' => $pack,
        ];
    }

    /**
     * @return array{sku: string, name: string}
     */
    private function findSkuAndName(string $flat): array
    {
        $sku = '';
        $name = '';

        if (preg_match('/\b(11N-[A-Z0-9]+(?:\s+[A-Z]{1,3})?)\b/u', $flat, $m) === 1) {
            $sku = strtoupper(trim(preg_replace('/\s+/', '-', trim($m[1])) ?? $m[1]));
        }
        if (preg_match('/\b(FOOD-\d+)\b/u', $flat, $m) === 1) {
            $sku = strtoupper($m[1]);
            $name = $sku;
        }
        if (preg_match('/\b(SWG-[A-Z0-9]+(?:\s+light)?)\b/ui', $flat, $m) === 1) {
            $sku = strtoupper(str_replace(' ', '-', trim($m[1])));
        }

        foreach (self::NAMED as $label) {
            if ($this->labelAppears($flat, $label)) {
                $name = $label;
                if ($sku === '') {
                    $sku = str_replace(' ', '-', $label);
                }
                break;
            }
        }

        if ($name === '' && $sku !== '') {
            $name = str_replace('-', ' ', $sku);
        }

        return ['sku' => $sku, 'name' => $name];
    }

    private function labelAppears(string $flat, string $label): bool
    {
        if (strcasecmp($label, 'FLEX 4') === 0) {
            return preg_match('/\bFLEX\s*4\b/ui', $flat) === 1;
        }
        $parts = preg_split('/\s+/', $label) ?: [];
        if (count($parts) === 2) {
            return preg_match('/\b'.preg_quote($parts[0], '/').'\b/ui', $flat) === 1
                && preg_match('/\b'.preg_quote($parts[1], '/').'\b/ui', $flat) === 1;
        }

        return preg_match('/\b'.preg_quote($label, '/').'\b/ui', $flat) === 1;
    }

    private function findPriceGrosze(string $block): ?int
    {
        foreach (preg_split("/\r\n|\n|\r/", $block) ?: [] as $raw) {
            $line = trim($raw);
            if ($line === '') {
                continue;
            }
            if (preg_match('/Sungboo\s+Sp|NIP:|KRS:|SANTANDER|Kapital zakl/ui', $line) === 1) {
                break;
            }

            $stripped = preg_replace('/\d{1,2}\/\d{2,3}/', ' ', $line) ?? $line;
            $stripped = preg_replace('/\b(?:[6-9]|1[0-2])\b/', ' ', $stripped) ?? $stripped;
            if (preg_match_all('/\b(\d{3,4})\b/', $stripped, $mm) !== false) {
                foreach ($mm[1] as $digits) {
                    $value = (int) $digits;
                    if ($this->isGroszePrice($value)) {
                        return $value;
                    }
                }
            }
        }

        return null;
    }

    private function isGroszePrice(int $value): bool
    {
        if ($value < 140 || $value > 4000) {
            return false;
        }
        if ($value >= 2020 && $value <= 2035) {
            return false;
        }
        // kod EN388 bez litery (np. 1141), nie cena
        if ($value >= 1000 && preg_match('/^[0-4]{4}$/', (string) $value) === 1) {
            return false;
        }

        return true;
    }

    private function findDescription(string $flat): ?string
    {
        if (preg_match('/\b((?:R[ęe]kawice|Rkawice|Cienkie rkawice|Grube rkawice)\b.{10,220})/ui', $flat, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }

    private function findPack(string $flat): ?string
    {
        if (preg_match('/\b(\d{1,2}\/\d{2,3})\b/', $flat, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function detectCategory(string $block): ?string
    {
        if (preg_match('/\bR[ĘE]KAWICE\s+[A-ZĄĆĘŁŃÓŚŹŻ() \/]{3,70}/u', $block, $m) !== 1) {
            return null;
        }
        $cat = trim(preg_replace('/\s+/u', ' ', $m[0]) ?? $m[0]);

        return mb_substr($cat, 0, 80);
    }

    private function isFooterOnly(string $block): bool
    {
        return preg_match('/Sungboo\s+Sp|NIP:\s*\d/ui', $block) === 1
            && preg_match('/r[ęe]kawic|rkawic/ui', $block) !== 1;
    }

    /**
     * @param  array<string, true>  $seen
     */
    private function uniqueSku(string $sku, array $seen): string
    {
        if (! isset($seen[$sku])) {
            return $sku;
        }
        $n = 2;
        $base = $sku;
        while (isset($seen[$sku])) {
            $sku = $base.'-'.$n;
            $n++;
        }

        return $sku;
    }
}
