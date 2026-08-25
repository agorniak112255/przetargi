<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Support\PpeAssortment;
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
                            {--only= : Filtruj po powodzie: family|garment|unrelated}';

    protected $description = 'Raport produktów z podejrzanie rozjechanym opisem (bez zapisu)';

    private const STOP_TOKENS = [
        'robocze', 'ochronne', 'ochronna', 'ochronny', 'meskie', 'damskie', 'czarne', 'czarny',
        'granatowe', 'granatowy', 'niebieskie', 'zielone', 'szare', 'biale', 'guma', 'skora',
        'rozmiar', 'komplet', 'zestaw', 'para', 'sztuka', 'linia', 'seria', 'model',
    ];

    public function handle(PpeAssortment $ppe): int
    {
        $only = (string) ($this->option('only') ?? '');
        $limit = max(0, (int) $this->option('limit'));
        $findings = [];
        $scanned = 0;

        Product::query()
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function (Collection $products) use ($ppe, $only, &$findings, &$scanned): void {
                foreach ($products as $product) {
                    /** @var Product $product */
                    $scanned++;
                    $finding = $this->inspect($ppe, $product);
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

    /** @return array{id: int, sku: string, name: string, reason: string, detail: string}|null */
    private function inspect(PpeAssortment $ppe, Product $product): ?array
    {
        $description = (string) $product->description;
        $productText = trim($product->name.' '.$product->sku.' '.(string) $product->category);

        $nameFamily = $ppe->family($productText);
        $descFamily = $ppe->family($description);

        if ($nameFamily !== null && $descFamily !== null && $nameFamily !== $descFamily) {
            return $this->finding($product, 'family', "nazwa={$nameFamily} opis={$descFamily}");
        }

        if ($nameFamily === PpeAssortment::FAMILY_APPAREL && $descFamily === PpeAssortment::FAMILY_APPAREL) {
            $nameGarment = $ppe->garment($productText);
            $descGarment = $ppe->garment($description);
            if ($nameGarment !== null && $descGarment !== null && $nameGarment !== $descGarment) {
                return $this->finding($product, 'garment', "nazwa={$nameGarment} opis={$descGarment}");
            }
        }

        if (! $this->descriptionSharesToken($product, $description)) {
            return $this->finding($product, 'unrelated', 'opis nie zawiera SKU, modelu ani marki');
        }

        return null;
    }

    /** @return array{id: int, sku: string, name: string, reason: string, detail: string} */
    private function finding(Product $product, string $reason, string $detail): array
    {
        return [
            'id' => (int) $product->id,
            'sku' => (string) $product->sku,
            'name' => mb_substr((string) $product->name, 0, 60),
            'reason' => $reason,
            'detail' => $detail,
        ];
    }

    /** Opis powinien nazwać po imieniu model, kod albo markę — inaczej to cudza karta. */
    private function descriptionSharesToken(Product $product, string $description): bool
    {
        $hay = mb_strtolower($description);

        foreach ([(string) $product->sku, (string) $product->manufacturer] as $value) {
            $value = mb_strtolower(trim($value));
            if ($value !== '' && str_contains($hay, $value)) {
                return true;
            }
        }

        foreach (preg_split('/[\s\-®™\/_,.]+/u', mb_strtolower((string) $product->name)) ?: [] as $token) {
            $token = trim($token);
            if (mb_strlen($token) < 4 || in_array($token, self::STOP_TOKENS, true)) {
                continue;
            }
            if (str_contains($hay, $token)) {
                return true;
            }
        }

        return false;
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
