<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Product;
use App\Models\ProductSourcePrice;
use Illuminate\Database\Eloquent\Builder;

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
     * @return array{status: string, standard_price: float, actual_discount_percent: float, saving_net: float, base_price: float, standard_discount_percent: float}|null
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
            // podstawa ceny standardowej — cena normalna kategorii bez ponownego pobierania cennika
            'base_price' => round($base, 2),
            'standard_discount_percent' => round($standardDiscountPercent, 2),
        ];
    }

    /**
     * Filtr listy kart: istnieje slot B2B z oceną $status dla ceny widocznej na karcie — ta sama reguła co
     * evaluate() i ProductController::cardSupplierSpecial (cena zakupu i waluta slotu = karty; slot bez waluty
     * dziedziczy walutę karty), przeniesiona do SQL, bo lista jest stronicowana w bazie. Tolerancja jak wyżej.
     *
     * @param  Builder<Product>  $query  zapytanie po tabeli products
     */
    public static function whereCardStatus(Builder $query, string $status): void
    {
        if (! in_array($status, [self::SPECIAL, self::WORSE_THAN_STANDARD], true)) {
            return;
        }
        $standard = 'ROUND(s.base_price_net * (1 - s.standard_discount_percent / 100.0), 2)';
        $tolerance = 'CASE WHEN '.$standard.' * '.self::TOLERANCE_SHARE.' > '.self::TOLERANCE_MIN
            .' THEN '.$standard.' * '.self::TOLERANCE_SHARE.' ELSE '.self::TOLERANCE_MIN.' END';
        $difference = '('.$standard.' - s.purchase_price)';
        $condition = $status === self::SPECIAL
            ? $difference.' > '.$tolerance
            : $difference.' < -('.$tolerance.')';
        $cardCurrency = "UPPER(TRIM(COALESCE(products.currency, '')))";

        $query->whereExists(static function ($sub) use ($condition, $cardCurrency): void {
            $sub->selectRaw('1')
                ->from('product_source_prices as s')
                ->whereColumn('s.product_id', 'products.id')
                ->where('s.source_key', 'like', 'b2b:%')
                ->whereNotNull('s.base_price_net')
                ->whereNotNull('s.standard_discount_percent')
                ->where('s.purchase_price', '>', 0)
                ->where('s.base_price_net', '>', 0)
                ->whereNotNull('products.purchase_price')
                ->whereRaw('ROUND(s.purchase_price, 2) = ROUND(products.purchase_price, 2)')
                ->whereRaw('COALESCE(UPPER(TRIM(s.currency)), '.$cardCurrency.') = '.$cardCurrency)
                ->whereRaw($condition);
        });
    }

    /**
     * @return array{status: string, standard_price: float, actual_discount_percent: float, saving_net: float, base_price: float, standard_discount_percent: float}|null
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
