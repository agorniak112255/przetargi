<?php

declare(strict_types=1);

return [
    /** Najwięcej pozycji w jednej ofercie. */
    'max_items' => (int) env('OFFERS_MAX_ITEMS', 30),

    /** Najwięcej adresów w jednej wysyłce (osobny mail do każdego, wysyłka od razu w żądaniu). */
    'max_recipients' => (int) env('OFFERS_MAX_RECIPIENTS', 10),
];
