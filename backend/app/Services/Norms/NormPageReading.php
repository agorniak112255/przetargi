<?php

declare(strict_types=1);

namespace App\Services\Norms;

/**
 * Wynik czytnika norm strony producenta: pary dosłownie ze strony, fragment strony, z którego pochodzą (do zapisu
 * w pochodzeniu i do sprawdzenia strony rodziny), i nazwa czytnika.
 */
final readonly class NormPageReading
{
    /**
     * @param  list<array{label: string, value: ?string}>  $rows  pary dosłownie ze strony
     * @param  string  $block  dosłowny tekst źródła par, najwyżej 1000 znaków
     * @param  string  $reader  krótka nazwa czytnika
     */
    public function __construct(
        public array $rows,
        public string $block,
        public string $reader,
    ) {}
}
