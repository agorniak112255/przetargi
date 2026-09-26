<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PriceList;
use App\Models\Product;
use App\Services\Enrichment\ProductSearchIdentity;
use App\Support\BrandDictionary;
use App\Support\CatalogManufacturerContext;

/**
 * Marka towaru w cenniku wielomarkowym z pliku (decyzja właściciela z 26.09.2026). Canis podpisuje cały plik swoją
 * nazwą, a sprzedaje też wyroby 3M, MSA, Ansella i DuPonta: karta „Respirator 3M 9914” miała producenta „Canis”,
 * więc nie dało się jej połączyć z kartą tego samego wyrobu od P4S (producent 3M) i porównać cen.
 *
 * Markę czyta ten sam czytnik, którego używa wzbogacanie (ProductSearchIdentity::goodsBrandKeys): zamknięta lista
 * marek z podmarkami (Peltor i E.A.R to 3M, AlphaTec i HyFlex to Ansell, Tyvek to DuPont), „3M” i „MSA” tylko wielkimi
 * literami („Measure tape, 3m” to metry) i bez marki urządzenia („Filter for 3M masks”). Dwie różne marki w nazwie
 * albo żadna — wiersz zostaje przy producencie pliku.
 *
 * Marka liczy się tylko w nazwie do pierwszego przecinka: dalej Canis wymienia składniki własnych wyrobów („High visible
 * pants, twill …, reflective stripes 3M”, „… 3M Thinsulate lining”). W pliku z 1.5.2026 wszystkie 68 prawdziwych trafień
 * ma markę przed przecinkiem, a 5 fałszywych — za nim.
 */
final class PriceListGoodsBrand
{
    /** Klucz marki z goodsBrandKeys => zapis producenta, gdy katalog nie ma jeszcze karty tej marki. */
    private const PRODUCERS = [
        '3m' => '3M',
        'msa' => 'MSA',
        'ansell' => 'Ansell',
        'dupont' => 'DuPont',
    ];

    private ?ProductSearchIdentity $identity = null;

    /** @var array<string, string> zapis producenta z katalogu na czas jednego importu */
    private array $spelling = [];

    public function enabledFor(string $listManufacturer): bool
    {
        $key = PriceList::manufacturerKey($listManufacturer);

        return $key !== '' && in_array($key, (array) config('price_lists.brand_from_name', []), true);
    }

    /**
     * Producent wyrobu z nazwy wiersza (zapis jak w katalogu) albo null, gdy wiersz zostaje przy producencie pliku.
     *
     * @param  array<string, mixed>  $row
     */
    public function brandOf(array $row, string $listManufacturer): ?string
    {
        $name = trim(explode(',', (string) ($row['name'] ?? ''), 2)[0]);
        if ($name === '') {
            return null;
        }
        $this->identity ??= app(ProductSearchIdentity::class);
        $keys = $this->identity->goodsBrandKeys(new Product([
            'name' => $name,
            'manufacturer' => $listManufacturer,
            'model_name' => is_string($row['model_name'] ?? null) ? $row['model_name'] : null,
        ]));
        if (count($keys) !== 1 || ! isset(self::PRODUCERS[$keys[0]])) {
            return null;
        }

        return $this->catalogSpelling(self::PRODUCERS[$keys[0]]);
    }

    /**
     * Wiersze pliku, które dostaną markę z nazwy — do podglądu importu (wszystkie pozycje, nie tylko przykładowe).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{brand: string, count: int, skus: list<string>}>
     */
    public function summarize(array $rows, string $listManufacturer): array
    {
        if (! $this->enabledFor($listManufacturer)) {
            return [];
        }
        $byBrand = [];
        foreach ($rows as $row) {
            $brand = $this->brandOf($row, $listManufacturer);
            if ($brand !== null) {
                $byBrand[$brand][] = (string) ($row['sku'] ?? '');
            }
        }
        $out = [];
        foreach ($byBrand as $brand => $skus) {
            $out[] = ['brand' => (string) $brand, 'count' => count($skus), 'skus' => array_slice($skus, 0, 20)];
        }
        usort($out, static fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        return $out;
    }

    /** Zapis producenta z katalogu („3M”), żeby scalanie kart porównało tę samą markę; bez karty — zapis domyślny. */
    private function catalogSpelling(string $producer): string
    {
        if (isset($this->spelling[$producer])) {
            return $this->spelling[$producer];
        }
        $key = BrandDictionary::key($producer);
        foreach (app(CatalogManufacturerContext::class)->catalogManufacturers() as $name) {
            if (BrandDictionary::key($name) === $key) {
                return $this->spelling[$producer] = $name;
            }
        }

        return $this->spelling[$producer] = $producer;
    }
}
