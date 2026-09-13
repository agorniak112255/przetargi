<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Enrichment\ProductEnrichmentResetter;
use App\Support\ProductDescriptionAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Karty z opisem obcej strony (raport `products:audit-descriptions --only=unrelated`) i ze
 * zrzutem strony zamiast opisu (`--only=page_dump`, także tytuł strony sklepu powtórzony jako opis)
 * wracają do kolejki wzbogacania: opis, normy, payload, źródło, cache SKU oraz zdjęcia, dokumenty
 * i akcesoria z sieci znikają, status = none. Przed zapisem powstaje kopia zapasowa (--restore ją
 * przywraca). Domyślnie tylko podgląd — zapis wymaga --apply.
 */
final class ResetForeignDescriptionsCommand extends Command
{
    private const REASONS = [ProductDescriptionAudit::REASON_UNRELATED, ProductDescriptionAudit::REASON_PAGE_DUMP];

    protected $signature = 'products:reset-foreign-descriptions
                            {--apply : Zapisz zmiany (bez tej flagi tylko podgląd)}
                            {--only= : Tylko jeden powód: unrelated albo page_dump}
                            {--manufacturer= : Tylko ten producent}
                            {--backup= : Plik kopii zapasowej JSON (domyślnie storage/app/repair-backups)}
                            {--restore= : Przywróć karty z kopii zapasowej i zakończ}
                            {--limit=0 : Maksymalna liczba wierszy w tabeli podglądu (0 = wszystkie)}';

    protected $description = 'Czyści opisy przypisane do niewłaściwego produktu i ustawia karty do ponownego wzbogacenia';

    public function handle(ProductDescriptionAudit $audit, ProductEnrichmentResetter $resetter): int
    {
        $restore = trim((string) $this->option('restore'));
        if ($restore !== '') {
            $result = $resetter->restore($restore);
            if (is_string($result)) {
                $this->error('Nie przywrócono: '.$result);

                return self::FAILURE;
            }
            $this->info("Przywrócono {$result} kart z kopii {$restore}.");

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $limit = max(0, (int) $this->option('limit'));
        $only = trim((string) $this->option('only'));
        if ($only !== '' && ! in_array($only, self::REASONS, true)) {
            $this->error('--only przyjmuje: '.implode(', ', self::REASONS));

            return self::FAILURE;
        }
        $reasons = $only !== '' ? [$only] : self::REASONS;
        $manufacturer = trim((string) $this->option('manufacturer'));

        /** @var list<array{finding: array{id: int, sku: string, name: string, reason: string, detail: string}, product: Product}> $matches */
        $matches = [];
        Product::query()
            ->whereNotNull('description')
            ->where('description', '!=', '')
            ->when($manufacturer !== '', static fn ($q) => $q->where('manufacturer', $manufacturer))
            ->orderBy('id')
            ->chunkById(200, function (Collection $products) use ($audit, $reasons, &$matches): void {
                foreach ($products as $product) {
                    /** @var Product $product */
                    $finding = $audit->inspect($product);
                    // obcy opis i zrzut strony czyścimy automatycznie; rozjazd rodziny/kroju wymaga oka człowieka
                    if ($finding !== null && in_array($finding['reason'], $reasons, true)) {
                        $matches[] = ['finding' => $finding, 'product' => $product];
                    }
                }
            });

        if ($matches === []) {
            $this->info('Brak kart z obcym opisem.');

            return self::SUCCESS;
        }

        $rows = $limit > 0 ? array_slice($matches, 0, $limit) : $matches;
        $this->table(
            ['ID', 'SKU', 'Powód', 'Nazwa'],
            array_map(static fn (array $m): array => [$m['finding']['id'], $m['finding']['sku'], $m['finding']['reason'], $m['finding']['name']], $rows),
        );
        $count = count($matches);
        if (! $apply) {
            $this->info("Do wyczyszczenia: {$count} kart. Uruchom z --apply, żeby skasować obce opisy.");

            return self::SUCCESS;
        }

        $backup = trim((string) $this->option('backup'));
        if ($backup === '') {
            $backup = storage_path('app/repair-backups/foreign-descriptions-'.now()->format('Ymd-His').'.json');
        }
        $error = $resetter->writeBackup($backup, 'reset-foreign-descriptions', array_column($matches, 'product'));
        if ($error !== null) {
            $this->error("Kopia zapasowa nie powstała ({$error}) — nic nie zmieniam.");

            return self::FAILURE;
        }
        foreach ($matches as $match) {
            $resetter->reset($match['product']);
        }
        $this->info("Wyczyszczono {$count} kart — wrócą do kolejki wzbogacania (status none).");
        $this->info("Kopia zapasowa: {$backup} (przywrócenie: --restore=\"{$backup}\").");

        return self::SUCCESS;
    }
}
