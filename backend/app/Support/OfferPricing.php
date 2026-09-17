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

    /** Najwyższa marża, jaką wolno wpisać ręcznie. */
    public static function marginMax(): float
    {
        $max = config('pricing.offer_margin_max', 99);

        return is_numeric($max) && (float) $max >= 0 ? (float) $max : 99.0;
    }

    /**
     * Marża wpisana ręcznie: przecinek, znak procentu i spacje (także twarda spacja
     * z Worda) to normalny zapis. Jedno miejsce dla walidacji i dla liczenia ceny —
     * inaczej „30 %” przechodziło walidację, a cenę liczyła marża domyślna.
     */
    public static function percentFromInput(mixed $raw): ?float
    {
        if (is_float($raw) || is_int($raw)) {
            return (float) $raw;
        }
        if (! is_string($raw)) {
            return null;
        }
        $clean = str_replace([',', '%', ' ', "\u{00A0}", "\u{202F}"], ['.', '', '', '', ''], trim($raw));

        return $clean !== '' && is_numeric($clean) ? (float) $clean : null;
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
