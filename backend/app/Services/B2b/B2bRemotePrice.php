<?php

declare(strict_types=1);

namespace App\Services\B2b;

final readonly class B2bRemotePrice
{
    /**
     * @param  float  $net  cena netto konta (po rabacie klienta)
     * @param  float|null  $base  cena bazowa/katalogowa netto, gdy dostawca ją podaje
     * @param  B2bOrderQuantity|null  $order  warunek zamawiania; null = źródło go nie podaje (zapisany zostaje)
     */
    public function __construct(
        public float $net,
        public ?float $base = null,
        public float $discountPercent = 0.0,
        public string $currency = 'PLN',
        public ?B2bOrderQuantity $order = null,
    ) {}
}
