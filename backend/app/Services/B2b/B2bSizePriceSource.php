<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Łącznik podaje cenę każdego rozmiaru (B2bRemoteProduct::members[].price, decyzja użytkownika 28.09.2026): wyrób to
 * jedna karta z tabelą rozmiarów. Konto takiego łącznika ma w panelu „Scal rozmiary” — scalanie kart rozbitych
 * dawniej według ceny rozmiaru (B2bSizePriceMerger).
 */
interface B2bSizePriceSource {}
