<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Proponowana cena oferty = zakup × (1 + marża%/100).
 * Domyślnie +18% (config/pricing.php), albo marża docelowa przetargu.
 */
final class OfferPricing
{
    public static function markup(): float
    {
        $m = (float) config('pricing.offer_markup', 1.18);

        return $m > 0 ? $m : 1.18;
    }

    public static function markupPercent(): float
    {
        return max(0, (float) config('pricing.offer_markup_percent', 18));
    }

    public static function factorFromPercent(?float $percent): float
    {
        $p = $percent ?? self::markupPercent();
        $factor = 1 + ($p / 100);

        return $factor > 0 ? $factor : 1.0;
    }

    public static function fromPurchase(float|string|null $purchase, ?float $markupPercent = null): ?float
    {
        if ($purchase === null || $purchase === '') {
            return null;
        }
        $p = (float) $purchase;
        if ($p <= 0) {
            return null;
        }

        return round($p * self::factorFromPercent($markupPercent), 2);
    }

    /**
     * Link zewnętrzny: stara cena × (nowy narzut / stary narzut).
     */
    public static function scaleByMarginChange(?float $price, float $oldPercent, float $newPercent): ?float
    {
        if ($price === null || $price <= 0) {
            return $price;
        }
        $old = self::factorFromPercent($oldPercent);
        $new = self::factorFromPercent($newPercent);
        if ($old <= 0) {
            return round($price * $new, 2);
        }

        return round($price * ($new / $old), 2);
    }
}