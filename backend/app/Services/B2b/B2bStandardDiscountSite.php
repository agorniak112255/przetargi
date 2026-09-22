<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Łącznik z ceną konta i osobnym cennikiem bazowym dostawcy (UVEX). Reguły rabatu konta (B2bDiscountRule)
 * znaczą tu „rabat standardowy” — cena konta niższa niż cennik bazowy × (1 − rabat standardowy) to cena
 * specjalna (App\Support\SupplierSpecialPrice). Cena zakupu i katalogowa pozostają ceną konta.
 */
interface B2bStandardDiscountSite
{
    /**
     * Wiersz cennika bazowego dla karty z rabatem standardowym z reguł konta. null = karty nie ma w cenniku
     * (albo jej kategoria jest pominięta). Wołane po price(); wyjątek niedozwolony — błędy cennika zgłasza
     * basePriceListLoaded()/runSummary().
     */
    public function basePrice(B2bRemoteProduct $product): ?B2bBasePrice;

    /**
     * Czy cennik bazowy wczytał się w tym przebiegu. false = pliku nie udało się pobrać ani odczytać —
     * synchronizacja zostawia wtedy ceny bazowe z poprzedniego przebiegu (null z basePrice() nie znaczy „brak”).
     */
    public function basePriceListLoaded(): bool;
}
