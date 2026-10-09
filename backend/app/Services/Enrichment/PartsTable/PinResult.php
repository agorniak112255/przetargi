<?php

declare(strict_types=1);

namespace App\Services\Enrichment\PartsTable;

/**
 * Wynik przypięcia karty do tabeli części: pin albo powód, dla którego karta zostaje bez przypięcia (i bez zmian —
 * stara ścieżka wzbogacania). $candidates — kody z tabeli, gdy pasuje kilka.
 */
final class PinResult
{
    /** @param  list<string>  $candidates */
    public function __construct(
        public readonly ?PartsTablePin $pin,
        public readonly ?string $unresolvedReason = null,
        public readonly array $candidates = [],
    ) {}

    public function resolved(): bool
    {
        return $this->pin !== null;
    }
}
