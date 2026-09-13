<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\Product;
use App\Models\ProductAccessory;
use App\Models\ProductDocument;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductImage;
use App\Services\PriceListImportService;
use App\Services\SpreadsheetColumnMapper;
use App\Services\SpreadsheetMappingHeuristic;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use JsonException;

/**
 * Import cennika Canis 2026 zapisał setkom produktów nazwę z wcześniejszego wiersza (polo DOVER
 * jako „High visible trousers”). Polecenie czyta ten sam plik poprawionym importem i poprawia nazwy.
 * Karty wzbogacone pod cudzą nazwą (opis, cache SKU, zdjęcia, dokumenty i akcesoria z sieci) wracają
 * do kolejki. Przed zapisem powstaje kopia zapasowa; --restore przywraca stan sprzed naprawy.
 * Domyślnie tylko podgląd — zapis wymaga --apply.
 */
final class RepairPriceListNamesCommand extends Command
{
    /** Kolumny produktu zapisywane w kopii i przywracane 1:1. */
    private const PRODUCT_COLUMNS = [
        'id', 'sku', 'name', 'description', 'norms', 'packaging', 'shop_source_url', 'enrichment_status',
        'enriched_at', 'enrichment_error', 'enrichment_trace', 'enrichment_payload',
    ];

    protected $signature = 'products:repair-price-list-names
                            {file? : Plik cennika XLSX, z którego importowano produkty}
                            {--manufacturer= : Producent w bazie, np. Canis}
                            {--apply : Zapisz zmiany (bez tej flagi tylko podgląd)}
                            {--backup= : Plik kopii zapasowej JSON (domyślnie storage/app/repair-backups)}
                            {--restore= : Przywróć produkty z kopii zapasowej i zakończ}
                            {--limit=30 : Maksymalna liczba wierszy w tabeli podglądu (0 = wszystkie)}';

    protected $description = 'Poprawia nazwy produktów przejęte z sąsiedniego wiersza cennika; karty opisane pod złą nazwą wracają do kolejki wzbogacania';

    public function handle(
        SpreadsheetMappingHeuristic $heuristic,
        SpreadsheetColumnMapper $columns,
        PriceListImportService $importer,
    ): int {
        $restore = trim((string) $this->option('restore'));
        if ($restore !== '') {
            return $this->restore($restore);
        }

        $path = (string) $this->argument('file');
        $manufacturer = trim((string) $this->option('manufacturer'));
        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));
        if ($manufacturer === '') {
            $this->error('Podaj producenta, np. --manufacturer=Canis.');

            return self::FAILURE;
        }
        if ($path === '' || ! is_file($path)) {
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

        $expected = $importer->productRowsFromMapping($path, $mapping, $manufacturer);
        if ($expected === []) {
            $this->error('Plik nie dał żadnych produktów — nic nie zmieniam.');

            return self::FAILURE;
        }

        /** @var list<array{product: Product, name: string, packaging: ?string, reset: bool}> $changes */
        $changes = [];
        $notInFile = 0;
        Product::query()
            ->where('manufacturer', $manufacturer)
            ->orderBy('id')
            ->chunkById(200, function (Collection $products) use ($expected, &$changes, &$notInFile): void {
                foreach ($products as $product) {
                    /** @var Product $product */
                    $row = $expected[(string) $product->sku] ?? null;
                    if ($row === null) {
                        $notInFile++;

                        continue;
                    }
                    if ($this->normalized((string) $product->name) === $this->normalized($row['name'])) {
                        continue;
                    }
                    $changes[] = [
                        'product' => $product,
                        'name' => $row['name'],
                        'packaging' => $row['packaging'],
                        // „…270g/m2, 44 -” → „…270g/m2, shortened version”: ten sam wyrób, opis zostaje
                        'reset' => $this->wasEnrichedAutomatically($product)
                            && ! $this->sameProductStart((string) $product->name, $row['name']),
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

        $backup = trim((string) $this->option('backup'));
        if ($backup === '') {
            $backup = storage_path('app/repair-backups/'.Str::slug($manufacturer).'-names-'.now()->format('Ymd-His').'.json');
        }
        if (! $this->writeBackup($backup, $manufacturer, $changes)) {
            return self::FAILURE;
        }

        foreach ($changes as $change) {
            $this->repair($change['product'], $change['name'], $change['packaging'], $change['reset']);
        }
        $this->info("Poprawiono {$count} nazw; {$resets} kart wraca do kolejki wzbogacania (status none).");
        $this->info("Kopia zapasowa: {$backup} (przywrócenie: --restore=\"{$backup}\").");

        return self::SUCCESS;
    }

    /**
     * Opis z pobierania kart, nie wpisany ręcznie ani z cennika. „Wpisz ręcznie” może mieć opis od
     * człowieka; status none z opisem to opis z kolumny cennika.
     */
    private function wasEnrichedAutomatically(Product $product): bool
    {
        $status = (string) $product->enrichment_status;
        if (in_array($status, [Product::ENRICHMENT_DONE, Product::ENRICHMENT_FAILED], true)) {
            return true;
        }

        return $status === Product::ENRICHMENT_NONE && $product->enrichment_payload !== null;
    }

    /**
     * @param  list<array{product: Product, name: string, packaging: ?string, reset: bool}>  $changes
     */
    private function writeBackup(string $path, string $manufacturer, array $changes): bool
    {
        $entries = [];
        foreach ($changes as $change) {
            $entries[] = $this->snapshot($change['product']);
        }
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            $this->error("Nie można utworzyć katalogu kopii: {$dir}");

            return false;
        }
        try {
            $json = json_encode(
                ['manufacturer' => $manufacturer, 'created_at' => now()->toIso8601String(), 'products' => $entries],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            if (file_put_contents($path, $json) === false) {
                throw new JsonException('zapis pliku nie powiódł się');
            }
            // kontrola przed pierwszym UPDATE: kopia musi dać się odczytać w całości
            $read = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error("Kopia zapasowa nie powstała ({$e->getMessage()}) — nic nie zmieniam.");

            return false;
        }
        if (count($read['products'] ?? []) !== count($changes)) {
            $this->error('Kopia zapasowa jest niekompletna — nic nie zmieniam.');

            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Product $product): array
    {
        $key = ProductEnrichmentCache::normalizeKey((string) $product->manufacturer, (string) $product->sku);
        $rows = static fn (string $table, callable $scope): array => $scope(DB::table($table))
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        return [
            'product' => (array) DB::table((new Product)->getTable())->where('id', $product->id)->first(self::PRODUCT_COLUMNS),
            'images' => $rows((new ProductImage)->getTable(), static fn ($q) => $q->where('product_id', $product->id)),
            'documents' => $rows((new ProductDocument)->getTable(), static fn ($q) => $q->where('product_id', $product->id)),
            'accessories' => $rows((new ProductAccessory)->getTable(), static fn ($q) => $q->where('product_id', $product->id)
                ->where('source', ProductAccessory::SOURCE_ENRICHMENT)),
            'caches' => $rows((new ProductEnrichmentCache)->getTable(), static fn ($q) => $q
                ->where('manufacturer', $key['manufacturer'])
                ->where('sku', $key['sku'])),
        ];
    }

    private function repair(Product $product, string $name, ?string $packaging, bool $reset): void
    {
        DB::transaction(function () use ($product, $name, $packaging, $reset): void {
            $updates = ['name' => $name];
            if ($reset) {
                // cache SKU→karta, zdjęcia, dokumenty i akcesoria pochodzą z wyszukiwania pod cudzą nazwą;
                // wgrane ręcznie (bez adresu źródła) i akcesoria z Presty/ręczne zostają. Pliki na dysku
                // zostają — kopia zapasowa może przywrócić wiersze.
                $key = ProductEnrichmentCache::normalizeKey((string) $product->manufacturer, (string) $product->sku);
                ProductEnrichmentCache::query()
                    ->where('manufacturer', $key['manufacturer'])
                    ->where('sku', $key['sku'])
                    ->delete();
                ProductImage::query()
                    ->where('product_id', $product->id)
                    ->whereNotNull('source_url')
                    ->where('source_url', '!=', '')
                    ->delete();
                ProductDocument::query()
                    ->where('product_id', $product->id)
                    ->whereNotNull('source_url')
                    ->where('source_url', '!=', '')
                    ->delete();
                ProductAccessory::query()
                    ->where('product_id', $product->id)
                    ->where('source', ProductAccessory::SOURCE_ENRICHMENT)
                    ->delete();

                $updates += [
                    'description' => null,
                    'norms' => null,
                    'enrichment_payload' => null,
                    'enrichment_status' => Product::ENRICHMENT_NONE,
                    'enriched_at' => null,
                    'enrichment_error' => null,
                    'enrichment_trace' => null,
                    'shop_source_url' => null,
                    // rozmiary dopisało wzbogacanie z cudzej karty; zostaje to, co podaje cennik
                    'packaging' => $packaging,
                ];
            }
            // hak Product::updated wysyła reindeks, gdy zmienia się nazwa lub opis
            $product->update($updates);
        });
    }

    private function restore(string $path): int
    {
        if (! is_file($path)) {
            $this->error("Brak kopii zapasowej: {$path}");

            return self::FAILURE;
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error("Kopia zapasowa jest uszkodzona: {$e->getMessage()}");

            return self::FAILURE;
        }

        $tables = [
            'images' => (new ProductImage)->getTable(),
            'documents' => (new ProductDocument)->getTable(),
            'accessories' => (new ProductAccessory)->getTable(),
            'caches' => (new ProductEnrichmentCache)->getTable(),
        ];
        $restored = 0;
        foreach ((array) ($data['products'] ?? []) as $entry) {
            $columns = (array) ($entry['product'] ?? []);
            $id = (int) ($columns['id'] ?? 0);
            if ($id <= 0 || ! DB::table((new Product)->getTable())->where('id', $id)->exists()) {
                continue;
            }
            unset($columns['id']);
            DB::transaction(function () use ($id, $columns, $entry, $tables): void {
                DB::table((new Product)->getTable())->where('id', $id)->update($columns);
                foreach ($tables as $section => $table) {
                    foreach ((array) ($entry[$section] ?? []) as $row) {
                        $row = (array) $row;
                        if (! DB::table($table)->where('id', $row['id'] ?? 0)->exists()) {
                            DB::table($table)->insert($row);
                        }
                    }
                }
            });
            ReindexProductEmbeddingJob::dispatch($id);
            $restored++;
        }
        $this->info("Przywrócono {$restored} produktów z kopii {$path}. Wiersze dodane po naprawie (nowe zdjęcia) zostają.");

        return self::SUCCESS;
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
