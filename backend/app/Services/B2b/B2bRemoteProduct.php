<?php

declare(strict_types=1);

namespace App\Services\B2b;

final readonly class B2bRemoteProduct
{
    /**
     * @param  array<string, mixed>  $raw  pozycja listy dostawcy (dla łącznika)
     * @param  string|null  $availability  dostępność u dostawcy dosłownie ze źródła (slot ceny konta); null = źródło jej nie podaje
     * @param  string|null  $variantSummary  lista rozmiarów/kodów karty (products.variant_summary); null = nie zmieniać, '' = wyczyść
     * @param  list<array{remote_id: string, sku: string, name: string}>  $members  pozycje dostawcy scalone w tę kartę
     *                                                                              (np. rozmiary o tej samej cenie), razem z remoteId; [] = jedna pozycja
     * @param  list<B2bRemoteIdentifier>|null  $identifiers  identyfikatory pozycji (EAN, kod producenta); null = łącznik ich
     *                                                       nie podaje (zapisane zostają), [] = podaje i nie ma żadnych
     */
    public function __construct(
        public string $remoteId,
        public string $sku,
        public string $name,
        public ?string $category = null,
        public ?string $sourceUrl = null,
        public array $raw = [],
        public ?string $availability = null,
        public ?string $variantSummary = null,
        public array $members = [],
        public ?array $identifiers = null,
    ) {}
}
