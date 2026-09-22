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

    /**
     * Kategorie aktualnego cennika bazowego (UVEX: arkusze bez pominiętych) — podpowiedzi i kontrola nazw
     * w oknie reguł, zanim pierwsza synchronizacja zapisze kategorie na kartach. Loguje się u dostawcy i pobiera
     * plik; błąd = wyjątek z powodem.
     *
     * @return list<string>
     */
    public function basePriceCategories(): array;

    /**
     * Rabaty standardowe podane przez dostawcę — okno reguł wstawia je kontu bez reguł jako propozycję
     * (zapis dopiero po zatwierdzeniu przez użytkownika).
     *
     * @return list<array{category: string, discount_percent: float}>
     */
    public static function defaultStandardDiscounts(): array;
}
