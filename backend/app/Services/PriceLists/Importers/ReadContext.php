<?php

declare(strict_types=1);

namespace App\Services\PriceLists\Importers;

use App\Models\PriceList;

/** Ustawienia cennika potrzebne przy odczycie pliku. */
final class ReadContext
{
    public function __construct(
        public readonly PriceList $priceList,
        /** rabat wspólny cennika (grupa GLOBAL w assortment_groups) — null, gdy nie ustawiono */
        public readonly ?float $globalDiscountPercent = null,
    ) {}
}
