<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Services\Pricing\SupplierSpecialMask;
use App\Services\Tenders\TenderPriceView;
use App\Support\OfferPricing;
use App\Support\ProductDisplayName;

/**
 * Pełny wiersz oferty + skrót battlecard do Excel/PDF.
 */
final class TenderOfferExportService
{
    public function __construct(
        private readonly BattlecardService $battlecards,
        private readonly TenderPricingService $pricing,
        private readonly TenderPriceView $priceView,
    ) {}

    /**
     * Wiersze w cenach widza ($mask): bez uprawnienia prices.supplier_special.view zakup, sugerowana cena i marże
     * od ceny standardowej kart z ceną specjalną B2B (decyzja właściciela 30.09.2026).
     *
     * @return list<array<string, mixed>>
     */
    public function rows(Tender $tender, SupplierSpecialMask $mask): array
    {
        $tender->loadMissing(['client', 'items.mainProduct', 'items.mainVariant', 'items.companionProduct', 'owner']);
        $rows = [];
        foreach ($tender->items as $item) {
            $product = $item->mainProduct;
            $companion = $item->companionProduct;
            // wariant wybrany w ofercie: jego kod, etykieta przy nazwie i cena zakupu
            $variant = $product !== null ? $item->offerVariant() : null;
            $purchase = $this->sumPurchasePln($item, $product, $companion, $mask);
            $offer = $item->lineOfferUnit();
            $line = $offer !== null ? round($offer * (int) $item->quantity, 2) : null;
            $card = $this->battlecards->forItem($item, $mask);
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
                'sku' => $this->joinedSku($product, $companion, $variant),
                'product_name' => $this->joinedName($item, $product, $companion, $variant),
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
                'margin_percent' => $this->priceView->itemMargin($item, $mask),
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

    /** Marża przetargu w widoku widza — do nagłówka Excela i stopki PDF. */
    public function tenderMargin(Tender $tender, SupplierSpecialMask $mask): ?float
    {
        return $this->priceView->tenderMargin($tender, $mask);
    }

    private function sumPurchasePln(TenderItem $item, ?Product $main, ?Product $companion, SupplierSpecialMask $mask): ?float
    {
        $sum = 0.0;
        $has = false;
        foreach ([$main, $companion] as $product) {
            if ($product === null) {
                continue;
            }
            $pln = $product === $main ? $this->pricing->mainPurchasePln($item, $mask) : $mask->purchasePln($product);
            if ($pln === null || $pln <= 0) {
                continue;
            }
            $sum += $pln;
            $has = true;
        }

        return $has ? round($sum, 2) : null;
    }

    private function joinedSku(?Product $main, ?Product $companion, ?ProductVariant $variant = null): ?string
    {
        $variantSku = trim((string) $variant?->sku);
        $parts = array_values(array_filter([
            $variantSku !== '' ? $variantSku : $main?->sku,
            $companion?->sku,
        ], static fn (?string $sku): bool => is_string($sku) && $sku !== ''));

        return $parts === [] ? null : implode(' + ', $parts);
    }

    private function joinedName(TenderItem $item, ?Product $main, ?Product $companion, ?ProductVariant $variant = null): string
    {
        $parts = [];
        if ($main !== null) {
            $label = trim((string) $variant?->label);
            $parts[] = ProductDisplayName::for($main, 80).($label !== '' ? ' — '.$label : '');
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

        return $item->hasCustomOffer() ? 'Spoza katalogu' : null;
    }
}
