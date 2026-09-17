<?php

declare(strict_types=1);

return [
    /*
    | Narzut oferty względem ceny zakupu (po upuście).
    | 1.18 = +18% (stała proponowana cena oferty).
    */
    'offer_markup' => (float) env('OFFER_MARKUP', 1.18),

    /** Procent do etykiet UI / eksportu (np. 18). */
    'offer_markup_percent' => (int) env('OFFER_MARKUP_PERCENT', 18),

    /** Gorna granica marzy wpisywanej recznie (%) - wyzej to pomylka, nie oferta. */
    'offer_margin_max' => (float) env('OFFER_MARGIN_MAX', 99),
];
