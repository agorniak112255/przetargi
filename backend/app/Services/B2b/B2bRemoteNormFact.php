<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Jedna para z listy norm na karcie wyrobu u jego producenta — oznaczenie normy i poziom, dosłownie ze źródła.
 *
 * Bez normalizacji: „EN 388:2016 + A1:2018” zostaje w brzmieniu karty, a poziom („4331B”, „X1XXXX”, „KLMNOP”,
 * „A2” przy ANSI/ISEA) nie jest przepisywany ani skracany. Odczytem — co z tego jest poziomem EN 388, a co tylko
 * cytatem — zajmuje się App\Support\ManufacturerNormFacts.
 *
 * Poziomu może nie być (EN ISO 21420 to sama zgodność z normą): wtedy `value` jest null i nic go nie zastępuje.
 */
final readonly class B2bRemoteNormFact
{
    public function __construct(
        public string $label,
        public ?string $value = null,
    ) {}
}
