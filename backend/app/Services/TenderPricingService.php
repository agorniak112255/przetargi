<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Support\OfferPricing;

final class TenderPricingService
{
    public function __construct(
        private readonly NbpExchangeRateService $fx,
    ) {}

    public function offerFromPurchase(Tender $tender, float|string|null $purchase, ?string $currency = 'PLN'): ?float
    {
        return OfferPricing::fromPurchase(
            $this->fx->toPlnOrNull($purchase, $currency),
            $tender->targetMarkupPercent(),
        );
    }

    public function offerFromProduct(Tender $tender, Product $product): ?float
    {
        return OfferPricing::fromPurchase(
            $this->fx->purchasePln($product),
            $tender->targetMarkupPercent(),
        );
    }

    public function applyTargetMarginChange(Tender $tender, float $oldPercent, float $newPercent): void
    {
        if (abs($oldPercent - $newPercent) < 0.0001) {
            return;
        }

        $tender->loadMissing(['items.mainProduct', 'items.companionProduct']);

        foreach ($tender->items as $item) {
            if ($item->main_product_id !== null) {
                $product = $item->mainProduct;
                if ($product !== null && (float) $product->purchase_price > 0) {
                    $item->offer_price = OfferPricing::fromPurchase(
                        $this->fx->purchasePln($product),
                        $newPercent,
                    );
                }
                $companion = $item->companionProduct;
                if ($companion !== null && (float) $companion->purchase_price > 0) {
                    $item->companion_offer_price = OfferPricing::fromPurchase(
                        $this->fx->purchasePln($companion),
                        $newPercent,
                    );
                }
            } elseif ($item->hasCustomOffer() && $item->offer_price !== null) {
                $item->offer_price = OfferPricing::scaleByMarginChange(
                    (float) $item->offer_price,
                    $oldPercent,
                    $newPercent,
                );
            } else {
                continue;
            }
            $item->save();
            $this->recalculateItemMargin($item);
        }

        $this->recalculateTenderTotals($tender->fresh());
    }

    public function recalculateItemMargin(TenderItem $item): void
    {
        $offer = $item->lineOfferUnit();
        if ($offer === null || $offer <= 0 || $item->main_product_id === null) {
            $item->margin_percent = null;
            $item->save();

            return;
        }

        $item->loadMissing(['mainProduct', 'companionProduct']);
        $purchase = 0.0;
        $hasPurchase = false;

        $main = $item->mainProduct ?? Product::query()->find($item->main_product_id);
        if ($main !== null) {
            $mainPurchase = $this->fx->purchasePln($main) ?? (float) $main->purchase_price;
            if ($mainPurchase > 0) {
                $purchase += $mainPurchase;
                $hasPurchase = true;
            }
        }

        if ($item->companion_product_id !== null) {
            $companion = $item->companionProduct ?? Product::query()->find($item->companion_product_id);
            if ($companion !== null) {
                $companionPurchase = $this->fx->purchasePln($companion) ?? (float) $companion->purchase_price;
                if ($companionPurchase > 0) {
                    $purchase += $companionPurchase;
                    $hasPurchase = true;
                }
            }
        }

        if (! $hasPurchase) {
            $item->margin_percent = null;
            $item->save();

            return;
        }

        // decimal(8,2): ±999999.99 — clamp na wypadek ekstremalnych cen
        $item->margin_percent = max(-999999.99, min(999999.99, round((($offer - $purchase) / $offer) * 100, 2)));
        $item->save();
    }

    public function recalculateTenderTotals(Tender $tender): void
    {
        $tender->loadMissing('items.mainProduct');

        $value = 0.0;
        $weightedMargin = 0.0;

        foreach ($tender->items as $item) {
            $unit = $item->lineOfferUnit();
            if ($unit === null) {
                continue;
            }
            $line = $unit * $item->quantity;
            $value += $line;
            if ($item->margin_percent !== null) {
                $weightedMargin += $line * (float) $item->margin_percent;
            }
        }

        $tender->offer_value_net = round($value, 2);
        $tender->margin_percent = $value > 0
            ? max(-999999.99, min(999999.99, round($weightedMargin / $value, 2)))
            : null;
        $tender->last_activity_at = now();
        $tender->save();
    }
}
