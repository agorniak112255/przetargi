<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Support\OfferPricing;
use App\Support\ProductDisplayName;

/**
 * Pełny wiersz oferty + skrót battlecard do Excel/PDF.
 */
final class TenderOfferExportService
{
    public function __construct(
        private readonly BattlecardService $battlecards,
        private readonly NbpExchangeRateService $fx,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(Tender $tender): array
    {
        $tender->loadMissing(['client', 'items.mainProduct', 'items.companionProduct', 'owner']);
        $rows = [];
        foreach ($tender->items as $item) {
            $product = $item->mainProduct;
            $companion = $item->companionProduct;
            $purchase = $this->sumPurchasePln($product, $companion);
            $offer = $item->lineOfferUnit();
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
                'sku' => $this->joinedSku($product, $companion),
                'product_name' => $this->joinedName($item, $product, $companion),
                'catalog_name' => $this->joinedCatalogName($item, $product, $companion),
                'manufacturer' => $this->joinedManufacturer($item, $product, $companion),
                'custom_url' => $item->custom_url,
                'quantity' => (int) $item->quantity,
                'purchase_price' => $purchase,
                'offer_price' => $offer,
                'suggested_offer_price' => OfferPricing::fromPurchase(
                    $purchase,
                    $tender->targetMarkupPercent(),
                ),
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

    private function sumPurchasePln(?Product $main, ?Product $companion): ?float
    {
        $sum = 0.0;
        $has = false;
        foreach ([$main, $companion] as $product) {
            if ($product === null) {
                continue;
            }
            $pln = $this->fx->purchasePln($product);
            if ($pln === null || $pln <= 0) {
                continue;
            }
            $sum += $pln;
            $has = true;
        }

        return $has ? round($sum, 2) : null;
    }

    private function joinedSku(?Product $main, ?Product $companion): ?string
    {
        $parts = array_values(array_filter([
            $main?->sku,
            $companion?->sku,
        ], static fn (?string $sku): bool => is_string($sku) && $sku !== ''));

        return $parts === [] ? null : implode(' + ', $parts);
    }

    private function joinedName(TenderItem $item, ?Product $main, ?Product $companion): string
    {
        $parts = [];
        if ($main !== null) {
            $parts[] = ProductDisplayName::for($main, 80);
        }
        if ($companion !== null) {
            $parts[] = ProductDisplayName::for($companion, 80);
        }
        if ($parts !== []) {
            return implode(' + ', $parts);
        }

        return trim((string) ($item->custom_name ?? '')) ?: '—';
    }

    private function joinedCatalogName(TenderItem $item, ?Product $main, ?Product $companion): ?string
    {
        $parts = array_values(array_filter([
            $main?->name,
            $companion?->name,
        ], static fn (?string $name): bool => is_string($name) && $name !== ''));

        if ($parts !== []) {
            return implode(' + ', $parts);
        }

        return $item->custom_name;
    }

    private function joinedManufacturer(TenderItem $item, ?Product $main, ?Product $companion): ?string
    {
        $parts = array_values(array_unique(array_filter([
            $main?->manufacturer,
            $companion?->manufacturer,
        ], static fn (?string $name): bool => is_string($name) && $name !== '')));

        if ($parts !== []) {
            return implode(' / ', $parts);
        }

        return $item->hasCustomOffer() ? 'Poza katalogiem' : null;
    }
}
