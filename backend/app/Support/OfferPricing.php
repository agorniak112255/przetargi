<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Proponowana cena oferty = zakup × narzut (domyślnie +18%).
 */
final class OfferPricing
{
    public static function markup(): float
    {
        $m = (float) config('pricing.offer_markup', 1.18);

        return $m > 0 ? $m : 1.18;
    }

    public static function markupPercent(): int
    {
        return max(0, (int) config('pricing.offer_markup_percent', 18));
    }

    public static function fromPurchase(float|string|null $purchase): ?float
    {
        if ($purchase === null || $purchase === '') {
            return null;
        }
        $p = (float) $purchase;
        if ($p <= 0) {
            return null;
        }

        return round($p * self::markup(), 2);
    }
}
