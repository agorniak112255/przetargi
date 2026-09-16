<?php

declare(strict_types=1);

namespace App\Services\B2b;

/**
 * Rabat rozpoznany dla jednej karty dostawcy, razem z regułą, z której pochodzi
 * — żeby cenę zakupu dało się wytłumaczyć wpisem w konfiguracji, a nie „tak wyszło”.
 */
final readonly class B2bDiscountMatch
{
    public function __construct(
        public int $ruleId,
        public string $ruleName,
        public float $discountPercent,
        public ?int $assortmentGroupId = null,
    ) {}
}
