<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Jeden wiersz karty wyrobu u dostawcy — etykieta i wartość dosłownie ze źródła, bez normalizacji, która mogłaby
 * zmienić znaczenie (jednostki, zakresy, oznaczenia norm zostają tak, jak je podaje sklep).
 *
 * Wiersze o tej samej nazwie w obrębie karty są dozwolone i zachowywane w kolejności ze źródła: u Protektu
 * „Materiał” powtarza się dla każdego podzespołu, u UVEX-a „Protection Level” ma wiele wierszy.
 */
final readonly class B2bRemoteShopField
{
    /**
     * @param  string  $section  nagłówek sekcji u dostawcy (np. „Informacje handlowe”); '' = wiersz bez sekcji
     * @param  string  $name  etykieta wiersza dosłownie ze źródła
     * @param  string  $value  wartość dosłownie ze źródła
     */
    public function __construct(
        public string $section,
        public string $name,
        public string $value,
    ) {}
}
