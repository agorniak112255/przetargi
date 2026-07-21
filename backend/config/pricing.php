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
];
