<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Services\Pricing\SupplierSpecialMask;
use App\Support\OfferPricing;
use InvalidArgumentException;

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

    /**
     * Cena oferty z karty. Maska = kto wywołuje: użytkownik bez prices.supplier_special.view liczy ofertę od ceny
     * standardowej karty z ceną specjalną B2B (decyzja właściciela 30.09.2026), uprawniony i CLI — od prawdziwej.
     */
    public function offerFromProduct(Tender $tender, Product $product, SupplierSpecialMask $mask): ?float
    {
        return OfferPricing::fromPurchase(
            $mask->purchasePln($product),
            $tender->targetMarkupPercent(),
        );
    }

    /**
     * Zakup wariantu w PLN (waluta wiersza, a gdy jej brak — waluta karty). Null, gdy wariant nie ma ceny —
     * wtedy obowiązuje cena karty. Rozmiar konta ze slotem specjalnym maska skaluje do ceny standardowej.
     */
    public function variantPurchasePln(ProductVariant $variant, ?Product $product, SupplierSpecialMask $mask): ?float
    {
        $masked = $mask->maskVariant($this->withAccountColumn($variant, $mask));

        return $this->fx->toPlnOrNull($masked->purchase_price, $masked->currency ?? $product?->currency);
    }

    /** Cena oferty z ceny wariantu; null, gdy wariant nie ma ceny (cenę liczy wtedy offerFromProduct). */
    public function offerFromVariant(Tender $tender, ProductVariant $variant, ?Product $product, SupplierSpecialMask $mask): ?float
    {
        $purchase = $this->variantPurchasePln($variant, $product, $mask);

        return $purchase === null ? null : OfferPricing::fromPurchase($purchase, $tender->targetMarkupPercent());
    }

    /**
     * purchase_price_pln na wczytanych wariantach pozycji (wybranym i liście karty) — do odpowiedzi JSON,
     * żeby panel liczył marżę wariantu tak jak karty. Wartości prawdziwe: widok bez uprawnienia podmienia je
     * na poziomie tablicy (App\Services\Tenders\TenderPriceView). Wariant, który nie należy już do karty pozycji
     * (scalanie kart bez zdarzeń modelu), znika z main_variant — jak w offerVariant(). Niczego nie wczytuje.
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
        $real = SupplierSpecialMask::revealing();
        foreach ($variants as $variant) {
            $variant->setAttribute('purchase_price_pln', $this->variantPurchasePln($variant, $product, $real));
        }
    }

    /**
     * Bieżący zakup głównego produktu pozycji w PLN: wariant wybrany do oferty, gdy ma cenę, inaczej karta.
     * Karta bez ceny daje 0 (albo surową cenę) jak dotąd — o tym, czy zakup jest, rozstrzyga wywołujący (> 0).
     * Null tylko bez karty.
     */
    public function mainPurchasePln(TenderItem $item, SupplierSpecialMask $mask): ?float
    {
        if ($item->main_product_id === null) {
            return null;
        }
        $main = $item->mainProduct ?? Product::query()->find($item->main_product_id);
        $variant = $item->offerVariant();
        if ($variant !== null) {
            $variantPurchase = $this->variantPurchasePln($variant, $main, $mask);
            if ($variantPurchase !== null) {
                return $variantPurchase;
            }
        }
        if ($main === null) {
            return null;
        }

        return $this->cardPurchasePln($main, $mask);
    }

    /**
     * Zmiana marży docelowej przetargu. Pozycje trzymają cenę oferty z chwili dopasowania (decyzja użytkownika
     * 15.09.2026): cena karty zmieniona później przez import cennika / B2B nie może cicho zmienić oferty, więc
     * dotychczasowa cena oferty (także drugiego produktu) jest tylko przeskalowana narzutem stary → nowy — jak dla
     * ofert własnych. Z bieżącej ceny karty liczona jest wyłącznie cena, której pozycja jeszcze nie ma (null) —
     * nie ma wtedy czego trzymać; liczy ją widok cen osoby, która zmienia marżę ($actor).
     */
    public function applyTargetMarginChange(Tender $tender, float $oldPercent, float $newPercent, SupplierSpecialMask $actor): void
    {
        if (abs($oldPercent - $newPercent) < 0.0001) {
            return;
        }

        $tender->loadMissing(['items.mainProduct', 'items.mainVariant', 'items.companionProduct']);
        // jedna maska standardowa na cały przetarg — karty wczytane hurtem, nie dwa zapytania na pozycję
        $standard = $this->standardMask($tender->items);

        foreach ($tender->items as $item) {
            if ($item->main_product_id !== null) {
                $item->offer_price = $this->repricedOffer(
                    $item->offer_price,
                    $item->mainProduct !== null ? $this->mainPurchasePln($item, $actor) : null,
                    $oldPercent,
                    $newPercent,
                );
                if ($item->companion_product_id !== null) {
                    $item->companion_offer_price = $this->repricedOffer(
                        $item->companion_offer_price,
                        $item->companionProduct !== null ? $actor->purchasePln($item->companionProduct) : null,
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
            $this->writeItemMargins($item, $standard);
        }

        $this->recalculateTenderTotals($tender->fresh());
    }

    /**
     * Marża pozycji liczona od BIEŻĄCEJ ceny zakupu karty (i drugiego produktu), a nie od ceny z chwili dopasowania —
     * to informacja o realnym koszcie: po zmianie ceny karty oferta zostaje, a marża pokazuje, ile na niej zostaje.
     * Zapisuje dwie marże: margin_percent od prawdziwych cen (dla uprawnionych) i margin_percent_standard od ceny
     * standardowej kart z ceną specjalną B2B (dla pozostałych — decyzja właściciela 30.09.2026).
     *
     * $standard — maska ukrywająca wspólna dla paczki pozycji (standardMask()): bez niej każda pozycja czyta karty
     * i sloty osobno. Maska pamięta ceny, więc wolno jej żyć tylko w obrębie jednego żądania albo paczki, w której
     * ceny kart się nie zmieniają (worker kolejki żyje długo — nie trzymać jej w polu serwisu).
     */
    public function recalculateItemMargin(TenderItem $item, ?SupplierSpecialMask $standard = null): void
    {
        if ($standard !== null && ! $standard->hides()) {
            // maska odsłaniająca wpisałaby prawdziwą marżę w miejsce bliźniaczej
            throw new InvalidArgumentException('Marża bliźniacza wymaga maski ukrywającej');
        }
        $this->writeItemMargins($item, $standard ?? SupplierSpecialMask::hiding());
    }

    /**
     * Maska ukrywająca dla paczki pozycji z wczytanymi hurtem kartami głównymi i drugimi produktami — do
     * recalculateItemMargin() w pętli. Karty dopisane do pozycji później maska doczyta sama.
     *
     * @param  iterable<TenderItem>  $items
     */
    public function standardMask(iterable $items): SupplierSpecialMask
    {
        $mask = SupplierSpecialMask::hiding();
        $ids = [];
        foreach ($items as $item) {
            foreach ([$item->main_product_id, $item->companion_product_id] as $id) {
                if ($id !== null) {
                    $ids[(int) $id] = (int) $id;
                }
            }
        }
        $mask->preload(array_values($ids));

        return $mask;
    }

    /** Marża pozycji w widoku cen $mask; null, gdy nie ma oferty, karty albo zakupu. Niczego nie zapisuje. */
    public function itemMargin(TenderItem $item, SupplierSpecialMask $mask): ?float
    {
        $offer = $item->lineOfferUnit();
        if ($offer === null || $offer <= 0 || $item->main_product_id === null) {
            return null;
        }

        $item->loadMissing(['mainProduct', 'companionProduct']);
        $purchase = 0.0;
        $hasPurchase = false;

        // wariant wybrany do oferty ma własną cenę zakupu (karta z wariantami trzyma cenę najniższego)
        $mainPurchase = $this->mainPurchasePln($item, $mask);
        if ($mainPurchase !== null && $mainPurchase > 0) {
            $purchase += $mainPurchase;
            $hasPurchase = true;
        }

        if ($item->companion_product_id !== null) {
            $companion = $item->companionProduct ?? Product::query()->find($item->companion_product_id);
            if ($companion !== null) {
                $companionPurchase = $this->cardPurchasePln($companion, $mask);
                if ($companionPurchase > 0) {
                    $purchase += $companionPurchase;
                    $hasPurchase = true;
                }
            }
        }

        if (! $hasPurchase) {
            return null;
        }

        // decimal(8,2): ±999999.99 — clamp na wypadek ekstremalnych cen
        return max(-999999.99, min(999999.99, round((($offer - $purchase) / $offer) * 100, 2)));
    }

    public function recalculateTenderTotals(Tender $tender): void
    {
        $tender->loadMissing('items.mainProduct');

        $value = 0.0;
        foreach ($tender->items as $item) {
            $unit = $item->lineOfferUnit();
            if ($unit !== null) {
                $value += $unit * $item->quantity;
            }
        }

        $tender->offer_value_net = round($value, 2);
        $tender->margin_percent = $this->weightedMargin($tender, 'margin_percent');
        $tender->margin_percent_standard = $this->weightedMargin($tender, 'margin_percent_standard');
        $tender->last_activity_at = now();
        $tender->save();
    }

    /**
     * Marża przetargu ważona wartością linii z kolumny marży pozycji ($column: margin_percent albo
     * margin_percent_standard). Pozycja bez marży liczy się do wartości, nie do marży — jak dotąd. Null bez wartości.
     *
     * Marża bliźniacza, pozycja z marżą prawdziwą, a bez bliźniaczej — nigdy nie kopiujemy prawdziwej (mogła
     * powstać z ceny specjalnej karty skasowanej później):
     * - bliźniaczą da się dziś policzyć (czeka na uzupełnienie) → marża bliźniacza przetargu nieznana (null, „—”),
     *   bo liczona jak 0% zaniżałaby średnią;
     * - nie da się (karta bez ceny, skasowana) → pozycja poza średnią i poza wartością — bliźniaczej nie dostanie
     *   nigdy, a jej przeliczenie wyzerowałoby też prawdziwą marżę. Bez tego przetarg zostałby z „—” na zawsze.
     * Sprawdzane tylko dla takich rzadkich pozycji, jedną maską z kartami wczytanymi hurtem.
     */
    public function weightedMargin(Tender $tender, string $column): ?float
    {
        $excluded = [];
        if ($column === 'margin_percent_standard') {
            $missing = $tender->items->filter(static fn (TenderItem $item): bool => $item->lineOfferUnit() !== null
                && $item->getAttribute('margin_percent') !== null
                && $item->getAttribute('margin_percent_standard') === null);
            if ($missing->isNotEmpty()) {
                $standard = $this->standardMask($missing);
                foreach ($missing as $item) {
                    if ($this->itemMargin($item, $standard) !== null) {
                        return null;
                    }
                    $excluded[spl_object_id($item)] = true;
                }
            }
        }

        $value = 0.0;
        $weightedMargin = 0.0;

        foreach ($tender->items as $item) {
            $unit = $item->lineOfferUnit();
            if ($unit === null || isset($excluded[spl_object_id($item)])) {
                continue;
            }
            $line = $unit * $item->quantity;
            $value += $line;
            $margin = $item->getAttribute($column);
            if ($margin !== null) {
                $weightedMargin += $line * (float) $margin;
            }
        }

        return $value > 0
            ? max(-999999.99, min(999999.99, round($weightedMargin / $value, 2)))
            : null;
    }

    private function writeItemMargins(TenderItem $item, SupplierSpecialMask $standard): void
    {
        $item->margin_percent = $this->itemMargin($item, SupplierSpecialMask::revealing());
        $item->margin_percent_standard = $this->itemMargin($item, $standard);
        $item->save();
    }

    /** Zakup karty w PLN w widoku $mask; bez kursu surowa cena (0 bez ceny) — jak dotąd. */
    private function cardPurchasePln(Product $product, SupplierSpecialMask $mask): float
    {
        return $mask->purchasePln($product) ?? (float) $mask->maskProduct($product)->purchase_price;
    }

    /**
     * Wariant wczytany bez kolumny konta (wybór kolumn przez wołającego) nie może ominąć maski — rozmiar konta
     * ze slotem specjalnym rozpoznaje ona po b2b_account_id, więc brakujące kolumny doczytujemy.
     */
    private function withAccountColumn(ProductVariant $variant, SupplierSpecialMask $mask): ProductVariant
    {
        $raw = $variant->getAttributes();
        if (! $mask->hides() || ! $variant->exists
            || (array_key_exists('b2b_account_id', $raw) && array_key_exists('product_id', $raw))) {
            return $variant;
        }
        $stored = ProductVariant::query()->whereKey($variant->getKey())->toBase()->first(['product_id', 'b2b_account_id']);
        if ($stored === null) {
            return $variant;
        }
        $copy = clone $variant;
        $copy->setRawAttributes([...$raw, 'product_id' => $stored->product_id, 'b2b_account_id' => $stored->b2b_account_id]);

        return $copy;
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
