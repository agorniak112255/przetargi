<?php

declare(strict_types=1);

namespace App\Services\Pricing;

/**
 * Cena specjalna konta B2B ukryta przed użytkownikiem bez uprawnienia prices.supplier_special.view: w jej miejsce
 * cena standardowa (cennik bazowy − rabat standardowy kategorii, App\Support\SupplierSpecialPrice::evaluate()).
 * Rozmiary tego samego slotu skalujemy tym samym stosunkiem (decyzja D2) — najtańszy rozmiar to cena karty, więc
 * droższe rozmiary w prawdziwej cenie zdradzałyby rabat.
 *
 * realPurchase to prawdziwa cena konta: służy tylko do wyliczenia stosunku i NIGDY nie trafia do odpowiedzi API.
 */
final class MaskedPrice
{
    /**
     * @param  string  $sourceKey  „b2b:{id}” slotu z ceną specjalną
     * @param  string  $currency  waluta slotu, a gdy slot jej nie ma — waluta karty (wielkimi literami)
     * @param  float  $realPurchase  prawdziwa cena konta — nie serializować
     * @param  float  $standardPrice  evaluate()['standard_price']
     * @param  float  $ratio  standardPrice / realPurchase (> 1)
     * @param  array{status: string, standard_price: float, actual_discount_percent: float, saving_net: float, base_price: float, standard_discount_percent: float, category: string|null}  $evaluation  ocena ceny standardowej (status „standard”, oszczędność 0)
     */
    public function __construct(
        public readonly string $sourceKey,
        public readonly int $accountId,
        public readonly string $currency,
        public readonly float $realPurchase,
        public readonly float $standardPrice,
        public readonly float $ratio,
        public readonly array $evaluation,
    ) {}

    /**
     * Pola ceny karty albo slotu w widoku standardowym. Katalogowa równa cenie zakupu (UVEX: katalogowa = cena
     * konta, rabat 0) albo pusta staje się ceną standardową — inaczej zdradzałaby cenę specjalną. Inna katalogowa
     * (cennik dostawcy) zostaje, a rabat liczymy od niej na nowo.
     *
     * @return array{catalog_price_net: string, purchase_price: string, discount_percent: string|null}
     */
    public function fields(mixed $catalog, mixed $purchase, mixed $discount): array
    {
        $catalogValue = self::number($catalog);
        $purchaseValue = self::number($purchase);
        // porównanie także z prawdziwą ceną konta: wiersz bez ceny zakupu nie może przemycić jej jako katalogowej
        $leaks = $catalogValue === null
            || ($purchaseValue !== null && abs($catalogValue - $purchaseValue) < 0.005)
            || abs($catalogValue - $this->realPurchase) < 0.005;
        $newCatalog = $leaks ? $this->standardPrice : (float) $catalogValue;
        $discountValue = $newCatalog > 0
            ? round((1 - $this->standardPrice / $newCatalog) * 100, 2)
            : self::number($discount);

        return [
            'catalog_price_net' => self::format($newCatalog),
            'purchase_price' => self::format($this->standardPrice),
            // rabat bez katalogowej zostaje, jaki był; brak rabatu nie staje się zerem
            'discount_percent' => $discountValue === null ? null : self::format($discountValue),
        ];
    }

    /** Kwota rozmiaru tego samego slotu przeskalowana do ceny standardowej; null zostaje null. */
    public function scale(mixed $amount): ?string
    {
        $value = self::number($amount);

        return $value === null ? null : self::format($value * $this->ratio);
    }

    private static function number(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private static function format(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }
}
