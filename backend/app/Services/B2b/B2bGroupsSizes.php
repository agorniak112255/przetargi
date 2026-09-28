<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Łącznik sam łączy rozmiary: karta = rozmiary jednego wyrobu, podane jako pozycje (members) karty.
 *
 * Do 28.09.2026 (decyzja użytkownika 15.09.2026) rozmiar w innej cenie był osobną kartą. Od 28.09.2026 (decyzja
 * użytkownika) rozmiary w różnych cenach to jedna karta: łącznik podaje cenę każdej pozycji (members[].price), karta
 * ma cenę najtańszego rozmiaru, a rozmiary z cenami są wierszami product_variants (kind „size”). Przeszedł na to na
 * razie tylko Mascot; pozostałe łączniki z tym znacznikiem dalej dzielą wyrób na karty według ceny.
 *
 * Skoro takie konto trzyma dwa kody na osobnych kartach, to nie są rozmiary jednego wyrobu — propozycja „Łączenie
 * kart” nie może ich uznać za rozmiary (CardMatchFinder, warunek 3 sygnału rozmiar/kolor). Przy łącznikach po zmianie
 * zasady warunek jest prawdziwy dopiero po etapie 2 (scalenie kart rozbitych dawniej według ceny); do tego czasu
 * stare karty rozbite według ceny zostają osobno (B2bCatalogSync, tryb „według kart”).
 */
interface B2bGroupsSizes {}
