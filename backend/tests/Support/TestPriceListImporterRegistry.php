<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\PriceLists\Importers\PriceListImporter;
use App\Services\PriceLists\Importers\PriceListImporterRegistry;

/** Rejestr importerów z listą klas podaną w teście (produkcyjny ma stałą IMPORTERS). */
final class TestPriceListImporterRegistry extends PriceListImporterRegistry
{
    /** @param  list<class-string<PriceListImporter>>  $classes */
    public function __construct(private readonly array $classes) {}

    public function classes(): array
    {
        return $this->classes;
    }
}
