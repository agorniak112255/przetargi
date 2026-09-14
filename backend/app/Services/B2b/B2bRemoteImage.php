<?php

declare(strict_types=1);

namespace App\Services\B2b;

final readonly class B2bRemoteImage
{
    /**
     * @param  string  $sourceUrl  adres zapisywany jako źródło — bez tokenów
     */
    public function __construct(
        public string $bytes,
        public string $mime,
        public string $sourceUrl,
    ) {}
}
