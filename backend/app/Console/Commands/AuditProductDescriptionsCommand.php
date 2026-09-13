<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\ProductDescriptionAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Wykrywa opisy przypisane do niewłaściwego produktu (enrichment trafił w obcą kartę).
 * Wyłącznie raport — nie modyfikuje bazy.
 */
final class AuditProductDescriptionsCommand extends Command
{
    protected $signature = 'products:audit-descriptions
                            {--limit=0 : Maksymalna liczba wierszy w tabeli (0 = wszystkie)}
                            {--csv= : Zapisz pełną listę do pliku CSV}
                            {--only= : Filtruj po powodzie: family|garment|unrelated|page_dump}';

    protected $description = 'Raport produktów z podejrzanie rozjechanym opisem (bez zapisu)';

    public function handle(ProductDescriptionAudit $audit): int
    {
        $only = (string) ($this->option('only') ?? '');
        $limit = max(0, (int) $this->option('limit'));
        $findings = [];
        $scanned = 0;

        Product::query()
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function (Collection $products) use ($audit, $only, &$findings, &$scanned): void {
                foreach ($products as $product) {
                    /** @var Product $product */
                    $scanned++;
                    $finding = $audit->inspect($product);
                    if ($finding === null) {
                        continue;
                    }
                    if ($only !== '' && $finding['reason'] !== $only) {
                        continue;
                    }
                    $findings[] = $finding;
                }
            });

        return $this->report($findings, $scanned, $limit);
    }

    /** @param  list<array{id: int, sku: string, name: string, reason: string, detail: string}>  $findings */
    private function report(array $findings, int $scanned, int $limit): int
    {
        $csv = (string) ($this->option('csv') ?? '');
        if ($csv !== '') {
            $this->writeCsv($csv, $findings);
            $this->info("Zapisano CSV: {$csv}");
        }

        if ($findings === []) {
            $this->info("Przeskanowano {$scanned} opisów — brak podejrzanych.");

            return self::SUCCESS;
        }

        $rows = $limit > 0 ? array_slice($findings, 0, $limit) : $findings;
        $this->table(['ID', 'SKU', 'Nazwa', 'Powód', 'Szczegóły'], $rows);

        $counts = array_count_values(array_column($findings, 'reason'));
        $summary = [];
        foreach ($counts as $reason => $count) {
            $summary[] = "{$reason}: {$count}";
        }

        $this->warn('Podejrzane: '.count($findings)." z {$scanned} opisów (".implode(', ', $summary).')');

        return self::SUCCESS;
    }

    /** @param  list<array{id: int, sku: string, name: string, reason: string, detail: string}>  $findings */
    private function writeCsv(string $path, array $findings): void
    {
        $handle = fopen($path, 'w');
        if ($handle === false) {
            $this->error("Nie mogę zapisać pliku: {$path}");

            return;
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['id', 'sku', 'nazwa', 'powod', 'szczegoly'], ';');
        foreach ($findings as $row) {
            fputcsv($handle, array_values($row), ';');
        }
        fclose($handle);
    }
}
