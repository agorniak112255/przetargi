<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Łącznik, którego sklep podaje tylko cenę konta (naszą cenę zakupu), bez ceny katalogowej (VM Footwear). Reguły
 * rabatu konta (B2bDiscountRule) znaczą tu „rabat od ceny katalogowej”: cena katalogowa = cena zakupu ÷ (1 − rabat)
 * (właściciel 08.10.2026: „Cena katalogowa − 43% = nasza cena zakupu”). Cena zakupu zostaje ceną konta. Karta bez
 * pasującej reguły ma katalogową równą cenie zakupu, jak przed regułami — liczbę takich kart podaje podsumowanie.
 */
interface B2bCatalogFromPurchaseSite {}
