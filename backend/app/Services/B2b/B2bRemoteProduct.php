<?php

declare(strict_types=1);

namespace App\Services\B2b;

final readonly class B2bRemoteProduct
{
    /**
     * @param  array<string, mixed>  $raw  pozycja listy dostawcy (dla łącznika)
     */
    public function __construct(
        public string $remoteId,
        public string $sku,
        public string $name,
        public ?string $category = null,
        public ?string $sourceUrl = null,
        public array $raw = [],
    ) {}
}
