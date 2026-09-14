<?php

declare(strict_types=1);

namespace App\Services\B2b;

final readonly class B2bRemoteVariant
{
    /**
     * @param  array<string, string>  $attributes  np. ["Format" => "10 x 14,8 cm", "Podłoże" => "FN - folia samoprzylepna"]; [] gdy nie da się rozbić bez zgadywania
     * @param  B2bRemotePrice|null  $price  net = cena konta; base = cena katalogowa netto tylko gdy źródło podaje ją wprost
     * @param  string|null  $priceError  błąd pobrania ceny — zapisana cena i price_checked_at zostają bez zmian
     */
    public function __construct(
        public string $remoteId,
        public string $label,
        public array $attributes = [],
        public ?B2bRemotePrice $price = null,
        public ?string $priceError = null,
        public ?string $sourceUrl = null,
        public int $sortOrder = 0,
        public ?float $vatRate = null,
        public ?string $unit = null,
    ) {}
}
