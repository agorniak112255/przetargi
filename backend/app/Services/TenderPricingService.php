<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
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

    /**
     * Zakup wariantu w PLN (waluta wiersza, a gdy jej brak — waluta karty). Null, gdy wariant nie ma ceny —
     * wtedy obowiązuje cena karty.
     */
    public function variantPurchasePln(ProductVariant $variant, ?Product $product = null): ?float
    {
        return $this->fx->toPlnOrNull($variant->purchase_price, $variant->currency ?? $product?->currency);
    }

    /** Cena oferty z ceny wariantu; null, gdy wariant nie ma ceny (cenę liczy wtedy offerFromProduct). */
    public function offerFromVariant(Tender $tender, ProductVariant $variant, ?Product $product = null): ?float
    {
        $purchase = $this->variantPurchasePln($variant, $product);

        return $purchase === null ? null : OfferPricing::fromPurchase($purchase, $tender->targetMarkupPercent());
    }

    /**
     * purchase_price_pln na wczytanych wariantach pozycji (wybranym i liście karty) — do odpowiedzi JSON,
     * żeby panel liczył marżę wariantu tak jak karty. Wariant, który nie należy już do karty pozycji (scalanie
     * kart bez zdarzeń modelu), znika z main_variant — jak w offerVariant(). Niczego nie wczytuje.
     */
    public function appendVariantPricesPln(TenderItem $item): void
    {
        $product = $item->relationLoaded('mainProduct') ? $item->mainProduct : null;
        $variants = [];
        if ($item->relationLoaded('mainVariant') && $item->mainVariant !== null) {
            if ($item->offerVariant() === null) {
                $item->setRelation('mainVariant', null);
            } else {
                $variants[] = $item->mainVariant;
            }
        }
        if ($product !== null && $product->relationLoaded('activeVariants')) {
            array_push($variants, ...$product->activeVariants->all());
        }
        foreach ($variants as $variant) {
            $variant->setAttribute('purchase_price_pln', $this->variantPurchasePln($variant, $product));
        }
    }

    /**
     * Bieżący zakup głównego produktu pozycji w PLN: wariant wybrany do oferty, gdy ma cenę, inaczej karta.
     * Karta bez ceny daje 0 (albo surową cenę) jak dotąd — o tym, czy zakup jest, rozstrzyga wywołujący (> 0).
     * Null tylko bez karty.
     */
    public function mainPurchasePln(TenderItem $item): ?float
    {
        if ($item->main_product_id === null) {
            return null;
        }
        $main = $item->mainProduct ?? Product::query()->find($item->main_product_id);
        $variant = $item->offerVariant();
        if ($variant !== null) {
            $variantPurchase = $this->variantPurchasePln($variant, $main);
            if ($variantPurchase !== null) {
                return $variantPurchase;
            }
        }
        if ($main === null) {
            return null;
        }

        return $this->fx->purchasePln($main) ?? (float) $main->purchase_price;
    }

    /**
     * Zmiana marży docelowej przetargu. Pozycje trzymają cenę oferty z chwili dopasowania (decyzja użytkownika
     * 15.09.2026): cena karty zmieniona później przez import cennika / B2B nie może cicho zmienić oferty, więc
     * dotychczasowa cena oferty (także drugiego produktu) jest tylko przeskalowana narzutem stary → nowy — jak dla
     * ofert własnych. Z bieżącej ceny karty liczona jest wyłącznie cena, której pozycja jeszcze nie ma (null) —
     * nie ma wtedy czego trzymać.
     */
    public function applyTargetMarginChange(Tender $tender, float $oldPercent, float $newPercent): void
    {
        if (abs($oldPercent - $newPercent) < 0.0001) {
            return;
        }

        $tender->loadMissing(['items.mainProduct', 'items.mainVariant', 'items.companionProduct']);

        foreach ($tender->items as $item) {
            if ($item->main_product_id !== null) {
                $item->offer_price = $this->repricedOffer(
                    $item->offer_price,
                    $item->mainProduct !== null ? $this->mainPurchasePln($item) : null,
                    $oldPercent,
                    $newPercent,
                );
                if ($item->companion_product_id !== null) {
                    $item->companion_offer_price = $this->repricedOffer(
                        $item->companion_offer_price,
                        $item->companionProduct !== null ? $this->fx->purchasePln($item->companionProduct) : null,
                        $oldPercent,
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

    /**
     * Marża pozycji liczona od BIEŻĄCEJ ceny zakupu karty (i drugiego produktu), a nie od ceny z chwili dopasowania —
     * to informacja o realnym koszcie: po zmianie ceny karty oferta zostaje, a marża pokazuje, ile na niej zostaje.
     */
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

        // wariant wybrany do oferty ma własną cenę zakupu (karta z wariantami trzyma cenę najniższego)
        $mainPurchase = $this->mainPurchasePln($item);
        if ($mainPurchase !== null && $mainPurchase > 0) {
            $purchase += $mainPurchase;
            $hasPurchase = true;
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

    /**
     * Cena oferty po zmianie marży: istniejąca — przeskalowana; brak ceny — z bieżącego zakupu w PLN (karty albo
     * wybranego wariantu; null, gdy zakupu nie ma).
     */
    private function repricedOffer(mixed $offer, ?float $purchasePln, float $oldPercent, float $newPercent): ?float
    {
        if ($offer !== null) {
            return OfferPricing::scaleByMarginChange((float) $offer, $oldPercent, $newPercent);
        }
        if ($purchasePln === null || $purchasePln <= 0) {
            return null;
        }

        return OfferPricing::fromPurchase($purchasePln, $newPercent);
    }
}
