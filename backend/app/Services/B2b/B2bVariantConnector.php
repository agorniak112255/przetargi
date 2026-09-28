<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Łącznik, którego produkt ma wiele wersji z osobnymi cenami (np. znak w formatach i podłożach).
 * Karta dostaje cenę 0, ceny trafiają do product_variants (kind „version”). price() takiego łącznika nie jest wołane.
 * Od 28.09.2026 product_variants trzyma też rozmiary w różnych cenach (kind „size”, karta ma cenę najtańszego
 * rozmiaru — B2bRemoteProduct::members[].price); reguły „karta z wersjami = cena 0” liczą tylko kind „version”.
 */
interface B2bVariantConnector extends B2bConnector
{
    /**
     * Wszystkie wersje produktu z cenami konta. Błąd pobrania ceny jednej wersji = priceError, nie wyjątek;
     * utrata sesji / blokada = B2bFatalException.
     *
     * @return list<B2bRemoteVariant>
     */
    public function variants(B2bRemoteProduct $product): array;

    /** Liczba wersji na liście dostawcy; znana od pierwszego elementu products(). */
    public function totalVariants(): int;

    /**
     * Wszystkie remote_id wersji z pełnej listy dostawcy — podstawa oznaczania wersji wycofanych.
     * null, gdy lista była niepełna (np. nie pobrała się część mapy strony).
     *
     * @return list<string>|null
     */
    public function listedVariantIds(): ?array;

    /** Limit czasu przebiegu w minutach (resztę dokończy następny przebieg); null = bez limitu. */
    public function runBudgetMinutes(): ?int;
}
