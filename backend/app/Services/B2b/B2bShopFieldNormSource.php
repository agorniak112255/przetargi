<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Łącznik witryny producenta, której tabelka karty wyrobu ma wiersz z normami (Protekt „Normy / Norma”, Polstar
 * „Parametry produktu / Norma”, ARTRA „Parametry / norma”, 3M „Spełnione specyfikacje”, UVEX „Protection Class /
 * Norm”). Pary „norma → oznaczenie” czytamy z tabelki zapisanej już przez synchronizację (ProductShopCard) —
 * bez dodatkowego zapytania do sklepu — i zapisujemy w products.manufacturer_norms (App\Services\B2b\ShopCardNormFacts).
 *
 * Jak przy B2bNormFactSource zapis dotyczy tylko kart marki tej witryny, więc łącznik musi być też B2bManufacturerSite.
 */
interface B2bShopFieldNormSource
{
    /**
     * Nazwy wierszy tabelki z normami, dosłownie jak u producenta (porównanie bez wielkości liter).
     *
     * @return list<string>
     */
    public static function normShopFieldNames(): array;
}
