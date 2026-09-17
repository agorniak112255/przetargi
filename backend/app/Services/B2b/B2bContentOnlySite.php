<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Łącznik witryny, która jest źródłem samej treści karty — opisu, tabelki parametrów, zdjęć i plików —
 * a nie cen (artra.pl: publiczny sklep producenta z cenami detalicznymi; ceny zakupu biorą się z cennika).
 *
 * B2bCatalogSync traktuje taki przebieg inaczej w trzech miejscach, i tylko w nich:
 * - brak ceny nie jest powodem pominięcia pozycji, bo tu żadnej ceny nie ma i mieć nie miało;
 * - nic nie trafia do slotu ceny konta (product_source_prices) ani do historii cen — cena detaliczna
 *   producenta zawyżyłaby każdą wycenę, a zapisanie jej „na wszelki wypadek” byłoby zmyśleniem danych
 *   handlowych;
 * - nie powstaje żadna nowa karta: katalog buduje cennik, więc pozycja bez karty jest pomijana z powodem.
 *   Inaczej witryna producenta dokładałaby karty wyrobów, których nie mamy od kogo kupić.
 *
 * Wszystko poza tym (opis, product_shop_cards, zdjęcia, dokumenty, variant_summary) idzie wspólną drogą.
 */
interface B2bContentOnlySite {}
