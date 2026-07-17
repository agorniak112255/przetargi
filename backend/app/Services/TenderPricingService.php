<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\Tender;
use App\Models\TenderItem;

final class TenderPricingService
{
    public function recalculateItemMargin(TenderItem $item): void
    {
        if ($item->offer_price === null || $item->main_product_id === null) {
            $item->margin_percent = null;
            $item->save();

            return;
        }

        /** @var Product|null $product */
        $product = $item->mainProduct ?? Product::query()->find($item->main_product_id);
        if ($product === null || (float) $product->purchase_price <= 0) {
            $item->margin_percent = null;
            $item->save();

            return;
        }

        $offer = (float) $item->offer_price;
        $purchase = (float) $product->purchase_price;
        $item->margin_percent = round((($offer - $purchase) / $offer) * 100, 2);
        $item->save();
    }

    public function recalculateTenderTotals(Tender $tender): void
    {
        $tender->loadMissing('items.mainProduct');

        $value = 0.0;
        $weightedMargin = 0.0;

        foreach ($tender->items as $item) {
            if ($item->offer_price === null) {
                continue;
            }
            $line = (float) $item->offer_price * $item->quantity;
            $value += $line;
            if ($item->margin_percent !== null) {
                $weightedMargin += $line * (float) $item->margin_percent;
            }
        }

        $tender->offer_value_net = round($value, 2);
        $tender->margin_percent = $value > 0 ? round($weightedMargin / $value, 2) : null;
        $tender->last_activity_at = now();
        $tender->save();
    }
}
