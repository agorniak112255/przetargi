<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Łącznik witryny, która podaje normy wyrobu jako dane, a nie jako prozę opisu (u ATG w danych strukturalnych
 * schema.org: para oznaczenie → poziom). Synchronizacja zapisuje je w products.manufacturer_norms i to one
 * wygrywają przy dopasowaniu do wymagania przetargu.
 *
 * Zapis jest zawężony do witryny producenta tej marki — tak samo jak nadpisanie opisu (B2bManufacturerSite):
 * u dystrybutora lista norm bywa przepisana z cudzej karty i właśnie takie listy naprawiamy. Dlatego łącznik,
 * który implementuje ten interfejs, musi też być B2bManufacturerSite, a karta musi należeć do jego marki.
 */
interface B2bNormFactSource
{
    /**
     * Pary z listy norm na karcie; [] gdy karta żadnej nie podaje (wtedy zapisane normy zostają bez zmian —
     * pusty odczyt nie jest wiadomością, że wyrób norm nie ma).
     *
     * @return list<B2bRemoteNormFact>
     */
    public function normFacts(B2bRemoteProduct $product): array;
}
