<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\Product;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductImage;
use App\Services\PriceListImportService;
use App\Services\SpreadsheetColumnMapper;
use App\Services\SpreadsheetMappingHeuristic;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Import cennika Canis 2026 zapisał setkom produktów nazwę z wcześniejszego wiersza (polo DOVER
 * jako „High visible trousers”). Polecenie czyta ten sam plik poprawionym importem, poprawia nazwy
 * i — skoro opis, cache SKU i zdjęcia szukano pod cudzą nazwą — zwraca te karty do wzbogacania.
 * Domyślnie tylko podgląd — zapis wymaga --apply.
 */
final class RepairPriceListNamesCommand extends Command
{
    protected $signature = 'products:repair-price-list-names
                            {file : Plik cennika XLSX, z którego importowano produkty}
                            {--manufacturer= : Producent w bazie, np. Canis}
                            {--apply : Zapisz zmiany (bez tej flagi tylko podgląd)}
                            {--limit=30 : Maksymalna liczba wierszy w tabeli podglądu (0 = wszystkie)}';

    protected $description = 'Poprawia nazwy produktów przejęte z sąsiedniego wiersza cennika; karty opisane pod złą nazwą wracają do kolejki wzbogacania';

    public function handle(
        SpreadsheetMappingHeuristic $heuristic,
        SpreadsheetColumnMapper $columns,
        PriceListImportService $importer,
    ): int {
        $path = (string) $this->argument('file');
        $manufacturer = trim((string) $this->option('manufacturer'));
        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));
        if ($manufacturer === '') {
            $this->error('Podaj producenta, np. --manufacturer=Canis.');

            return self::FAILURE;
        }
        if (! is_file($path)) {
            $this->error("Brak pliku: {$path}");

            return self::FAILURE;
        }

        $mapping = $heuristic->detect($path);
        if ($mapping === null) {
            $this->error('Nie rozpoznano kolumn cennika.');

            return self::FAILURE;
        }
        $mapping = $columns->refineMapping($path, $mapping);
        foreach ($mapping['sheets'] as $sheet) {
            if (($sheet['include'] ?? false) && is_array($sheet['columns'] ?? null)) {
                $used = array_filter($sheet['columns'], static fn ($column): bool => $column !== null);
                $this->line('Arkusz „'.$sheet['sheet'].'”: '.json_encode($used, JSON_UNESCAPED_UNICODE));
            }
        }

        $expected = $importer->productNamesFromMapping($path, $mapping, $manufacturer);
        if ($expected === []) {
            $this->error('Plik nie dał żadnych produktów — nic nie zmieniam.');

            return self::FAILURE;
        }

        /** @var list<array{product: Product, name: string, reset: bool}> $changes */
        $changes = [];
        $notInFile = 0;
        Product::query()
            ->where('manufacturer', $manufacturer)
            ->orderBy('id')
            ->chunkById(200, function (Collection $products) use ($expected, &$changes, &$notInFile): void {
                foreach ($products as $product) {
                    /** @var Product $product */
                    $name = $expected[(string) $product->sku] ?? null;
                    if ($name === null) {
                        $notInFile++;

                        continue;
                    }
                    if ($this->normalized((string) $product->name) === $this->normalized($name)) {
                        continue;
                    }
                    $changes[] = [
                        'product' => $product,
                        'name' => $name,
                        // „…270g/m2, 44 -” → „…270g/m2, shortened version”: ten sam wyrób, opis zostaje
                        'reset' => ($product->enrichment_status !== Product::ENRICHMENT_NONE
                            || trim((string) $product->description) !== '')
                            && ! $this->sameProductStart((string) $product->name, $name),
                    ];
                }
            });

        if ($changes === []) {
            $this->info("Nazwy produktów {$manufacturer} zgadzają się z plikiem.");

            return self::SUCCESS;
        }

        $rows = $limit > 0 ? array_slice($changes, 0, $limit) : $changes;
        $this->table(
            ['ID', 'SKU', 'Status', 'Opis', 'Nazwa teraz', 'Nazwa z cennika'],
            array_map(fn (array $change): array => [
                $change['product']->id,
                $change['product']->sku,
                $change['product']->enrichment_status,
                $change['reset'] ? 'do pobrania' : 'zostaje',
                mb_substr((string) $change['product']->name, 0, 60),
                mb_substr($change['name'], 0, 60),
            ], $rows),
        );

        $count = count($changes);
        $resets = count(array_filter($changes, static fn (array $change): bool => $change['reset']));
        if (! $apply) {
            $this->info("Do poprawy: {$count} nazw, w tym {$resets} kart do ponownego wzbogacenia. "
                ."Produkty {$manufacturer} bez wiersza w pliku (bez zmian): {$notInFile}. Uruchom z --apply, żeby zapisać.");

            return self::SUCCESS;
        }

        foreach ($changes as $change) {
            $this->repair($change['product'], $change['name'], $change['reset']);
        }
        $this->info("Poprawiono {$count} nazw; {$resets} kart wraca do kolejki wzbogacania (status none).");

        return self::SUCCESS;
    }

    private function repair(Product $product, string $name, bool $reset): void
    {
        $imagePaths = [];
        DB::transaction(function () use ($product, $name, $reset, &$imagePaths): void {
            $updates = ['name' => $name];
            if ($reset) {
                // cache SKU→karta i zdjęcia pochodzą z wyszukiwania pod cudzą nazwą
                $key = ProductEnrichmentCache::normalizeKey((string) $product->manufacturer, (string) $product->sku);
                ProductEnrichmentCache::query()
                    ->where('manufacturer', $key['manufacturer'])
                    ->where('sku', $key['sku'])
                    ->delete();

                // tylko zdjęcia pobrane z internetu; wgrane ręcznie (bez adresu źródła) zostają
                $downloaded = ProductImage::query()
                    ->where('product_id', $product->id)
                    ->whereNotNull('source_url')
                    ->where('source_url', '!=', '');
                $imagePaths = (clone $downloaded)->pluck('path')->all();
                $downloaded->delete();

                $updates += [
                    'description' => null,
                    'norms' => null,
                    'enrichment_payload' => null,
                    'enrichment_status' => Product::ENRICHMENT_NONE,
                    'enriched_at' => null,
                    'enrichment_error' => null,
                    'enrichment_trace' => null,
                    'shop_source_url' => null,
                ];
            }
            $product->update($updates);
        });

        foreach ($imagePaths as $path) {
            if (! is_string($path) || $path === '' || $path === 'remote' || preg_match('#^https?://#i', $path) === 1) {
                continue;
            }
            try {
                Storage::disk('public')->delete($path);
            } catch (Throwable) {
                // plik mógł już nie istnieć
            }
        }

        ReindexProductEmbeddingJob::dispatch($product->id);
    }

    /** Nazwy różnią się tylko końcówką (ucięty rozmiar, kolor, przecinek) — nie cudzy wyrób. */
    private function sameProductStart(string $current, string $expected): bool
    {
        $current = $this->normalized($current);
        $expected = $this->normalized($expected);
        $length = min(40, mb_strlen($current), mb_strlen($expected));

        return $length >= 20 && mb_substr($current, 0, $length) === mb_substr($expected, 0, $length);
    }

    private function normalized(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
}
