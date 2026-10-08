<?php

declare(strict_types=1);

return [
    /** Najwięcej pozycji w jednej ofercie. */
    'max_items' => (int) env('OFFERS_MAX_ITEMS', 30),

    /** Najwięcej adresów w jednej wysyłce (osobny mail do każdego, wysyłka od razu w żądaniu). */
    'max_recipients' => (int) env('OFFERS_MAX_RECIPIENTS', 10),

    /**
     * Stawka VAT cen brutto w mailu oferty (Offer::price_mode = gross): brutto = round(netto × (1 + VAT/100); 2). Jedna
     * stawka dla wszystkich pozycji — XL nie podaje stawki towaru; mail mówi wprost „z VAT 23%”.
     */
    'vat_percent' => 23,
];
