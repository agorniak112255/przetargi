<?php

declare(strict_types=1);

namespace App\Services\Erp;

use App\Models\ErpItem;
use App\Models\ErpItemLink;

/**
 * Wyszukiwarka listy produktów po kodzie towaru ERP XL (Twr_Kod, np. SOK9198014) albo jego EAN.
 *
 * Karty tylko przez pewne powiązania (auto i potwierdzone) — propozycja nie jest dowodem, że towar XL to ta karta.
 * Kod dokładnie albo początek kodu: początek od 5 znaków i z cyfrą, bo krótkie przedrostki („SOK” = okulary,
 * „ARK” = rękawice) i słowa bez cyfr wciągałyby do wyników całe grupy asortymentu.
 */
final class ErpCodeSearch
{
    private const PREFIX_MIN_LENGTH = 5;

    /** Górna granica towarów XL z jednego przedrostka — więcej to już nie „szukam kodu”. */
    private const MAX_ITEMS = 500;

    /**
     * @return array<int, list<string>> id karty → kody XL, które ją wskazały
     */
    public function productCodes(string $term): array
    {
        $term = trim($term);
        if ($term === '' || preg_match('/\s/u', $term) === 1 || mb_strlen($term) > 100) {
            return [];
        }
        $upper = mb_strtoupper($term);
        $prefix = mb_strlen($term) >= self::PREFIX_MIN_LENGTH && preg_match('/\d/', $term) === 1;

        $items = ErpItem::query()
            ->whereNull('removed_at')
            ->where(function ($q) use ($term, $upper, $prefix): void {
                $q->whereIn('code', array_values(array_unique([$term, $upper])))
                    ->orWhere('ean', $term);
                if ($prefix) {
                    $q->orWhere('code', 'like', addcslashes($upper, '%_\\').'%');
                }
            })
            ->limit(self::MAX_ITEMS)
            ->pluck('code', 'id');
        if ($items->isEmpty()) {
            return [];
        }

        $links = ErpItemLink::query()
            ->whereIn('erp_item_id', $items->keys()->all())
            ->whereIn('status', [ErpItemLink::STATUS_AUTO, ErpItemLink::STATUS_CONFIRMED])
            ->whereNotNull('product_id')
            ->get(['erp_item_id', 'product_id']);

        $codes = [];
        foreach ($links as $link) {
            $codes[(int) $link->product_id][] = (string) $items[(int) $link->erp_item_id];
        }
        foreach ($codes as $productId => $list) {
            $list = array_values(array_unique($list));
            sort($list);
            $codes[$productId] = $list;
        }

        return $codes;
    }
}
