<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Łącznik, który po przebiegu dopisuje własne podsumowanie do dziennika (np. ile kart trafiło w reguły
 * rabatowe, a ile zostało bez rabatu). Wywoływane raz, po przejściu listy produktów.
 */
interface B2bRunSummaryAware
{
    /**
     * @return list<string> linie dziennika; pusta lista = brak czego dopisywać
     */
    public function runSummary(): array;
}
