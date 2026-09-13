<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Enrichment\ProductEnrichmentResetter;
use App\Services\PriceListImportService;
use App\Services\SpreadsheetColumnMapper;
use App\Services\SpreadsheetMappingHeuristic;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Import cennika Canis 2026 zapisał setkom produktów nazwę z wcześniejszego wiersza (polo DOVER
 * jako „High visible trousers”). Polecenie czyta ten sam plik poprawionym importem i poprawia nazwy.
 * Karty wzbogacone pod cudzą nazwą (opis, cache SKU, zdjęcia, dokumenty i akcesoria z sieci) wracają
 * do kolejki. Przed zapisem powstaje kopia zapasowa; --restore przywraca stan sprzed naprawy.
 * Domyślnie tylko podgląd — zapis wymaga --apply.
 */
final class RepairPriceListNamesCommand extends Command
{
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
        ProductEnrichmentResetter $resetter,
    ): int {
        $restore = trim((string) $this->option('restore'));
        if ($restore !== '') {
            $result = $resetter->restore($restore);
            if (is_string($result)) {
                $this->error('Nie przywrócono: '.$result);

                return self::FAILURE;
            }
            $this->info("Przywrócono {$result} produktów z kopii {$restore}. Zdjęcia, dokumenty i cache pobrane po naprawie zastąpił stan z kopii.");

            return self::SUCCESS;
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
        $error = $resetter->writeBackup($backup, $manufacturer.' names', array_column($changes, 'product'));
        if ($error !== null) {
            $this->error("Kopia zapasowa nie powstała ({$error}) — nic nie zmieniam.");

            return self::FAILURE;
        }

        foreach ($changes as $change) {
            if ($change['reset']) {
                // rozmiary dopisało wzbogacanie z cudzej karty; zostaje to, co podaje cennik
                $resetter->reset($change['product'], ['name' => $change['name'], 'packaging' => $change['packaging']]);
            } else {
                $change['product']->update(['name' => $change['name']]);
            }
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
