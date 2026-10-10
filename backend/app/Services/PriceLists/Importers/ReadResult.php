<?php

declare(strict_types=1);

namespace App\Services\PriceLists\Importers;

/** Wynik odczytu pliku przez importer. */
final class ReadResult
{
    /**
     * @param  list<ImportedRow>  $rows
     * @param  list<array{ref: string, sku: ?string, reason: string}>  $skipped  pominięte wiersze z powodem (nagłówki, sekcje, braki)
     * @param  list<string>  $notes  uwagi importera do pokazania w podglądzie
     */
    public function __construct(
        public readonly array $rows,
        public readonly array $skipped,
        public readonly int $rowsTotal,
        public readonly array $notes = [],
    ) {}
}
