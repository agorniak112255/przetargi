<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tender;
use App\Support\ProductDisplayName;

/**
 * Pełny wiersz oferty + skrót battlecard do Excel/PDF.
 */
final class TenderOfferExportService
{
    public function __construct(
        private readonly BattlecardService $battlecards,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(Tender $tender): array
    {
        $tender->loadMissing(['client', 'items.mainProduct', 'owner']);
        $rows = [];
        foreach ($tender->items as $item) {
            $product = $item->mainProduct;
            $purchase = $product?->purchase_price !== null ? (float) $product->purchase_price : null;
            $offer = $item->offer_price !== null ? (float) $item->offer_price : null;
            $line = $offer !== null ? round($offer * (int) $item->quantity, 2) : null;
            $card = $this->battlecards->forItem($item);
            $subSkus = collect($card['substitutes'] ?? [])
                ->pluck('sku')
                ->filter()
                ->values()
                ->all();
            $reasons = is_array($item->ai_match_reasons) ? $item->ai_match_reasons : [];
            $reasonLabels = collect($reasons)
                ->map(static fn ($r) => is_array($r) ? (string) ($r['label'] ?? '') : '')
                ->filter()
                ->take(3)
                ->implode('; ');

            $rows[] = [
                'line_no' => (int) $item->line_no,
                'requirement' => (string) $item->requirement,
                'sku' => $product?->sku,
                'product_name' => ProductDisplayName::for($product, 80),
                'catalog_name' => $product?->name,
                'manufacturer' => $product?->manufacturer,
                'quantity' => (int) $item->quantity,
                'purchase_price' => $purchase,
                'offer_price' => $offer,
                'suggested_offer_price' => $purchase !== null && $purchase > 0
                    ? round($purchase * 1.18, 2)
                    : null,
                'margin_percent' => $item->margin_percent !== null ? (float) $item->margin_percent : null,
                'line_value' => $line,
                'match_percent' => $item->ai_match_percent !== null ? (int) $item->ai_match_percent : null,
                'match_source' => $item->match_source,
                'match_reasons' => $reasonLabels,
                'substitute_skus' => implode(', ', $subSkus),
                'highlights' => implode(' | ', $card['highlights'] ?? []),
            ];
        }

        return $rows;
    }
}
