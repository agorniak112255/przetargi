<?php

declare(strict_types=1);

namespace App\Services\Catalog;

use App\Models\ProductImportExclusion;

/**
 * Aktywne blokady jednego źródła (konto B2B albo producent cennika z pliku) wczytane raz na przebieg. Zbiera trafienia
 * — zapis licznika raz na koniec pełnego przebiegu (ProductImportExclusions::registerHits).
 */
final class ImportExclusionSet
{
    /** @var array<int, true> */
    private array $hitIds = [];

    /**
     * @param  array<string, ProductImportExclusion>  $byPosition  pozycja (ProductImportExclusions::normalize) => wiersz
     * @param  array<string, ProductImportExclusion>  $bySku  SKU karty (jak wyżej) => wiersz
     */
    public function __construct(
        private readonly array $byPosition = [],
        private readonly array $bySku = [],
    ) {}

    public static function empty(): self
    {
        return new self;
    }

    public function isEmpty(): bool
    {
        return $this->byPosition === [] && $this->bySku === [];
    }

    /** Blokada pozycji źródła (remote_id powiązania B2B, kod wiersza pliku). */
    public function position(string $position): ?ProductImportExclusion
    {
        if ($this->byPosition === []) {
            return null;
        }

        return $this->byPosition[ProductImportExclusions::normalize($position)] ?? null;
    }

    /**
     * Blokada po SKU usuniętej karty (wpis „sku” — karta z ceną z pliku bez kodów wierszy). Porównywać z SKU wiersza
     * i z kodami jego pozycji: po zwinięciu rozmiarów SKU wiersza bywa kodem modelu.
     */
    public function sku(string $code): ?ProductImportExclusion
    {
        if ($this->bySku === []) {
            return null;
        }

        return $this->bySku[ProductImportExclusions::normalize($code)] ?? null;
    }

    public function hit(ProductImportExclusion $row): void
    {
        $this->hitIds[(int) $row->id] = true;
    }

    /** @return list<int> */
    public function hitIds(): array
    {
        return array_keys($this->hitIds);
    }
}
