<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Łącznik, którego sklep nie ma własnego opisu wyrobu — ARTRA: blok opisowy karty to ten sam slogan marki
 * („Konstrukcja obuwia ARELAX®…”) na każdej karcie, a 22.09.2026 nadpisał nim opisy 171 kart. Decyzja użytkownika
 * 22.09.2026: opis takiej karty pisze model wyłącznie z karty katalogowej PDF producenta i tabelki ze strony
 * (product_shop_cards / shop_fields_summary), bez internetu i bez tekstu sklepu (DescribeB2bProductFromDatasheetJob).
 *
 * Dla synchronizacji znaczy to też, że łącznik opisu nie oddaje (description() jest puste z założenia), więc pusty
 * opis nie jest wiadomością, że opis zniknął ze strony — opis karty nigdy nie jest przez niego kasowany ani
 * zastępowany (B2bCatalogSync::applyCardDetails).
 */
interface B2bDatasheetOnlyDescription extends B2bDescribesFromDatasheet {}
