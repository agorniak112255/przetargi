<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\B2bAccount;

/**
 * Łącznik jednej witryny B2B. Każda domena ma własne logowanie i API, więc własną klasę;
 * zasady zapisu do katalogu są wspólne (B2bCatalogSync).
 */
interface B2bConnector
{
    /** Klucz zapisywany na koncie, np. "anro". */
    public static function key(): string;

    /** Nazwa dostawcy — także nazwa w historii cenników. */
    public static function label(): string;

    /** Domena witryny, po której łącznik jest wykrywany z konta. */
    public static function host(): string;

    public static function forAccount(B2bAccount $account, int $delayMs): self;

    public function login(): void;

    /**
     * Produkty z listy dostawcy (bez cen i szczegółów — te dociągane są tylko gdy potrzebne).
     *
     * @return iterable<B2bRemoteProduct>
     */
    public function products(): iterable;

    /** Liczba produktów po stronie dostawcy; znana po pobraniu pierwszej strony listy. */
    public function totalProducts(): int;

    public function manufacturer(B2bRemoteProduct $product): string;

    /** Cena konta; null gdy dostawca jej nie podaje. */
    public function price(B2bRemoteProduct $product): ?B2bRemotePrice;

    /** Opis jako tekst, dosłownie ze źródła; pusty gdy brak. */
    public function description(B2bRemoteProduct $product): string;

    public function image(B2bRemoteProduct $product): ?B2bRemoteImage;
}
