<?php

declare(strict_types=1);

namespace App\Services\B2b;

use App\Models\ProductIdentifier;

/**
 * Identyfikator wyrobu podany przez łącznik (EAN, kod producenta…) — dosłownie ze źródła. Łącznik deklaruje go wprost:
 * SKU karty bywa złożone przez nas (kod + rozmiar) i nie jest kodem ze źródła.
 *
 * @see ProductIdentifier typy
 */
final readonly class B2bRemoteIdentifier
{
    /**
     * @param  string  $type  ProductIdentifier::TYPE_*
     * @param  string|null  $remoteId  pozycja karty (members[].remote_id); null = cała karta (jej remoteId)
     * @param  string|null  $label  rozmiar / kolor pozycji dosłownie
     * @param  string|null  $field  nazwa pola w źródle („Ean”, „Kod producenta”)
     */
    public function __construct(
        public string $type,
        public string $value,
        public ?string $remoteId = null,
        public ?string $label = null,
        public ?string $field = null,
    ) {}
}
