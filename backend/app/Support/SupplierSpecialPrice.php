<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ProductSourcePrice;

/**
 * Cena specjalna dostawcy: cena konta B2B niższa niż cennik bazowy × (1 − rabat standardowy kategorii).
 * To wniosek z porównania, nie potwierdzenie — potwierdzenie ceny specjalnej przychodzi od dostawcy mailem,
 * którego system nie widzi. Brak ceny bazowej albo rabatu standardowego = brak oceny (null), nigdy „standard”.
 *
 * Tolerancja w złotówkach, nie w punktach rabatu: ceny bazowe bywają przeliczane z euro z ułamkami groszy
 * (Heckel 255,3060… × 0,85 = 217,01 przy cenie konta 216,75), a 1 pp rabatu gubiłby prawdziwe ceny specjalne
 * drogich wyrobów. Różnica mniejsza niż większa z wartości: 5 gr albo 0,5% ceny standardowej = zaokrąglenie.
 */
final class SupplierSpecialPrice
{
    public const SPECIAL = 'special';

    public const STANDARD = 'standard';

    /** Cena konta wyższa niż wynika z rabatu standardowego (zła kategoria, nieaktualny cennik, inny warunek). */
    public const WORSE_THAN_STANDARD = 'worse_than_standard';

    private const TOLERANCE_MIN = 0.05;

    private const TOLERANCE_SHARE = 0.005;

    /**
     * @return array{status: string, standard_price: float, actual_discount_percent: float, saving_net: float}|null
     */
    public static function evaluate(?float $purchase, ?float $base, ?float $standardDiscountPercent): ?array
    {
        if ($purchase === null || $base === null || $standardDiscountPercent === null || $purchase <= 0 || $base <= 0) {
            return null;
        }

        $standardPrice = round($base * (1 - $standardDiscountPercent / 100), 2);
        $tolerance = max(self::TOLERANCE_MIN, $standardPrice * self::TOLERANCE_SHARE);
        $difference = $standardPrice - $purchase;

        $status = match (true) {
            $difference > $tolerance => self::SPECIAL,
            $difference < -$tolerance => self::WORSE_THAN_STANDARD,
            default => self::STANDARD,
        };

        return [
            'status' => $status,
            'standard_price' => $standardPrice,
            'actual_discount_percent' => round((1 - $purchase / $base) * 100, 2),
            // o ile cena konta jest niższa od standardowej (ujemne = wyższa)
            'saving_net' => round($difference, 2),
        ];
    }

    /**
     * @return array{status: string, standard_price: float, actual_discount_percent: float, saving_net: float}|null
     */
    public static function forSlot(ProductSourcePrice $slot): ?array
    {
        return self::evaluate(
            $slot->purchase_price !== null ? (float) $slot->purchase_price : null,
            $slot->base_price_net !== null ? (float) $slot->base_price_net : null,
            $slot->standard_discount_percent !== null ? (float) $slot->standard_discount_percent : null,
        );
    }
}
