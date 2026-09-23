<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Łącznik sam łączy rozmiary: karta = rozmiary jednego wyrobu w tej samej cenie, podane jako pozycje (members)
 * karty (decyzja użytkownika 15.09.2026: rozmiar w innej cenie to osobna karta). Skoro takie konto trzyma dwa kody
 * na osobnych kartach, to nie są rozmiary jednego wyrobu w tej samej cenie — propozycja „Łączenie kart” nie może ich
 * uznać za rozmiary (CardMatchFinder, warunek 3 sygnału rozmiar/kolor).
 */
interface B2bGroupsSizes {}
