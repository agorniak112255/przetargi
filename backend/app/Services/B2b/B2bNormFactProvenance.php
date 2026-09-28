<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Łącznik B2bNormFactSource, którego pary nie pochodzą ze strony wyrobu (source.url), tylko z innego dokumentu —
 * Bolle czyta normy z karty technicznej PDF rodziny. Synchronizacja dopisuje zwrócone pola do `source` kolumny
 * products.manufacturer_norms (ManufacturerNormFacts::build, $provenance), żeby każdą parę dało się odnieść do pliku,
 * strony i dosłownego wiersza, z którego ją odczytano.
 *
 * Wywoływane zaraz po normFacts() dla tego samego produktu. Pola muszą być stałe dla tego samego odczytu (bez daty):
 * ManufacturerNormFacts::sameFacts porównuje całe `source` poza synced_at — zmienny znacznik zapisywałby kartę przy
 * każdym przebiegu.
 */
interface B2bNormFactProvenance
{
    /**
     * Dodatkowe pola `source` dla par z ostatniego normFacts() tego produktu; [] = brak (pary bez dokumentu).
     *
     * @return array<string, mixed>
     */
    public function normFactProvenance(B2bRemoteProduct $product): array;
}
