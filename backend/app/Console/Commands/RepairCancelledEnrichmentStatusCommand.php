<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PriceList;
use App\Models\Product;
use App\Services\PriceListCards;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use JsonException;
use SplFileObject;

/**
 * Karty, którym przerwana partia zapisała „błąd: Anulowano przez użytkownika” albo „Zatrzymano wszystkie pobierania
 * opisów” (przed 07.10.2026 tak kończyła każda karta przerwanej partii — Coba: 137 kart). To nie wynik pobierania,
 * więc karta wraca do stanu wynikającego z tego, co ma: z opisem → „gotowe”, bez opisu → „bez opisu”. Opis, ślad,
 * zdjęcia i reszta karty zostają. Domyślnie podgląd; zapis z --apply po kopii statusów (JSON z jedną kartą
 * w wierszu), --restore cofa. Status i komunikat nie zasilają search_blob ani wektora — zapis bez zdarzeń modelu.
 */
final class RepairCancelledEnrichmentStatusCommand extends Command
{
    private const BACKUP_LABEL = 'repair-cancelled-status';

    private const CHUNK = 200;

    private const PREVIEW_ROWS = 20;

    /** Komunikaty, które anulowanie zapisywało na karcie — dosłownie, jak w kodzie sprzed 07.10.2026. */
    public const CANCEL_MESSAGES = [
        'Anulowano przez użytkownika',
        'Zatrzymano wszystkie pobierania opisów',
    ];

    protected $signature = 'products:repair-cancelled-status
                            {--price-list= : Tylko karty tego cennika (numer cennika)}
                            {--manufacturer= : Tylko karty tego producenta (bez rozróżniania wielkości liter)}
                            {--backup= : Plik kopii zapasowej (domyślnie storage/app/repair-backups/repair-cancelled-status-<data>.json)}
                            {--restore= : Przywróć statusy z kopii zapasowej i zakończ}
                            {--apply : Zapisz zmiany (bez tej flagi tylko podgląd)}';

    protected $description = 'Karty z „błąd: Anulowano / Zatrzymano wszystkie” po przerwanej partii: z opisem → gotowe, bez opisu → bez opisu; podgląd bez --apply, kopia i --restore';

    public function handle(PriceListCards $cards): int
    {
        $restore = trim((string) $this->option('restore'));
        if ($restore !== '') {
            return $this->restore($restore);
        }

        $query = $this->scopedQuery($cards);
        if ($query === null) {
            return self::FAILURE;
        }

        /** @var list<array{id: int, sku: string, name: string, status: string, error: string, new_status: string}> $changes */
        $changes = [];
        $query->select(['id', 'sku', 'name', 'description', 'enriched_at', 'enrichment_status', 'enrichment_error'])
            ->chunkById(self::CHUNK, function ($products) use (&$changes): void {
                foreach ($products as $product) {
                    /** @var Product $product */
                    $changes[] = [
                        'id' => (int) $product->id,
                        'sku' => (string) $product->sku,
                        'name' => mb_substr((string) $product->name, 0, 60),
                        'status' => (string) $product->enrichment_status,
                        'error' => (string) $product->enrichment_error,
                        // „gotowe” tylko dla opisu z pobierania: tekst, który nie jest samą nazwą z cennika
                        // (hasDescriptionText), i data opisu — „gotowe” wpuszcza kartę przez bramkę opisu w dopasowaniu
                        // i zdejmuje ją ze zwykłego pobierania, więc nazwa z importu nie może tak skończyć
                        'new_status' => $product->hasDescriptionText() && $product->enriched_at !== null
                            ? Product::ENRICHMENT_DONE
                            : Product::ENRICHMENT_NONE,
                    ];
                }
            });

        $toDone = count(array_filter($changes, static fn (array $c): bool => $c['new_status'] === Product::ENRICHMENT_DONE));
        $this->info('Kart z błędem po przerwanej partii: '.count($changes)
            .' — z opisem (→ gotowe): '.$toDone.', bez opisu (→ bez opisu): '.(count($changes) - $toDone));
        if ($changes !== []) {
            $this->table(
                ['Karta', 'SKU', 'Nazwa', 'Komunikat', 'Nowy status'],
                array_map(static fn (array $c): array => [$c['id'], $c['sku'], $c['name'], $c['error'], $c['new_status']], array_slice($changes, 0, self::PREVIEW_ROWS))
            );
        }
        if (! $this->option('apply')) {
            $this->line('Podgląd — nic nie zapisano. Zapis: dodaj --apply.');

            return self::SUCCESS;
        }
        if ($changes === []) {
            return self::SUCCESS;
        }

        $backup = trim((string) $this->option('backup'));
        if ($backup === '') {
            $backup = storage_path('app/repair-backups/'.self::BACKUP_LABEL.'-'.now()->format('Ymd-His').'.json');
        }
        if (! $this->writeBackup($backup, $changes)) {
            $this->error("Nie zapisano: nie udało się utworzyć kopii zapasowej {$backup}");

            return self::FAILURE;
        }

        $written = 0;
        foreach ($changes as $change) {
            // ten sam warunek co przy wyborze — karta, którą w międzyczasie ktoś pobrał, zostaje
            $written += Product::query()
                ->whereKey($change['id'])
                ->where('enrichment_status', Product::ENRICHMENT_FAILED)
                ->whereIn('enrichment_error', self::CANCEL_MESSAGES)
                ->update([
                    'enrichment_status' => $change['new_status'],
                    'enrichment_error' => null,
                ]);
        }
        $this->info("Zapisano: {$written}. Kopia zapasowa: {$backup}");

        return self::SUCCESS;
    }

    /** @return Builder<Product>|null */
    private function scopedQuery(PriceListCards $cards): ?Builder
    {
        $query = Product::query()
            ->where('enrichment_status', Product::ENRICHMENT_FAILED)
            ->whereIn('enrichment_error', self::CANCEL_MESSAGES);

        $priceListId = (int) $this->option('price-list');
        if ($priceListId > 0) {
            $priceList = PriceList::query()->find($priceListId);
            if ($priceList === null) {
                $this->error("Nie ma cennika {$priceListId}.");

                return null;
            }
            $query->whereIn('id', $cards->ids($priceList));
        }
        $manufacturer = trim((string) $this->option('manufacturer'));
        if ($manufacturer !== '') {
            $query->whereRaw('LOWER(manufacturer) = ?', [mb_strtolower($manufacturer)]);
        }

        return $query;
    }

    /** @param  list<array{id: int, sku: string, name: string, status: string, error: string, new_status: string}>  $changes */
    private function writeBackup(string $path, array $changes): bool
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return false;
        }
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            return false;
        }
        try {
            fwrite($handle, json_encode(['label' => self::BACKUP_LABEL, 'at' => now()->toIso8601String()], JSON_UNESCAPED_UNICODE)."\n");
            foreach ($changes as $change) {
                fwrite($handle, json_encode([
                    'id' => $change['id'],
                    'sku' => $change['sku'],
                    'enrichment_status' => $change['status'],
                    'enrichment_error' => $change['error'],
                    'written_status' => $change['new_status'],
                ], JSON_UNESCAPED_UNICODE)."\n");
            }
        } finally {
            fclose($handle);
        }

        return true;
    }

    private function restore(string $path): int
    {
        if (! is_file($path)) {
            $this->error("Nie przywrócono: brak kopii zapasowej: {$path}");

            return self::FAILURE;
        }
        $file = new SplFileObject($path, 'rb');
        $header = (string) $file->fgets();
        if (! str_contains($header, '"label":"'.self::BACKUP_LABEL.'"')) {
            $this->error('Nie przywrócono: to nie jest kopia z products:repair-cancelled-status.');

            return self::FAILURE;
        }
        $restored = 0;
        $skipped = 0;
        while (! $file->eof()) {
            $line = trim((string) $file->fgets());
            if ($line === '') {
                continue;
            }
            try {
                $entry = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $this->error("Kopia zapasowa jest uszkodzona: {$e->getMessage()}");

                return self::FAILURE;
            }
            $id = (int) ($entry['id'] ?? 0);
            // tylko karta, której od naprawy nikt nie ruszył (status = zapisany przez naprawę, bez komunikatu)
            $updated = $id > 0 && is_string($entry['written_status'] ?? null)
                ? Product::query()
                    ->whereKey($id)
                    ->where('enrichment_status', $entry['written_status'])
                    ->whereNull('enrichment_error')
                    ->update([
                        'enrichment_status' => (string) ($entry['enrichment_status'] ?? Product::ENRICHMENT_FAILED),
                        'enrichment_error' => (string) ($entry['enrichment_error'] ?? ''),
                    ])
                : 0;
            $updated > 0 ? $restored++ : $skipped++;
        }
        $this->info("Przywrócono: {$restored}, pominięto (karta zmieniona od naprawy): {$skipped}.");

        return self::SUCCESS;
    }
}
