<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Services\B2b\ProtektB2bConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Nazwy z rozmiarem, który nie opisuje karty (odczyt produkcji 24.09.2026):
 * - PROTEKT, 231 kart: każdy rozmiar ma u Protektu osobny adres z tym samym numerem katalogowym, karta jest jedna na
 *   numer, a nazwę dostała z pierwszego adresu — „P-50mX - Szelki bezpieczeństwa - rozmiar S” obejmuje S, M - XL i XXL.
 *   Synchronizacja nie zmienia nazwy istniejącej karty (decyzja użytkownika 15.09.2026), więc stare karty naprawia to
 *   polecenie; nowe dostają nazwę bez rozmiaru w łączniku (ProtektB2bConnector::cardNameWithoutSize, ta sama reguła).
 * - karta scalona z rozmiarów (enrichment_payload.merged_size_skus) z urwanym słowem rozmiaru na końcu: łączenie obcinało
 *   z „roz. 7” samą liczbę (UVEX #25211 „Rękawice C500 Dry - nakrapiane roz.”) — poprawione w
 *   ProductSizeVariant::stripSizeFromName. Nazwy spoza łączenia („…, size” w cenniku Canis) to tekst źródła — zostają.
 *
 * Domyślnie tylko podgląd. --apply zapisuje przez model (hak przelicza indeks tekstowy i zleca reindeks wektora) po
 * kopii zapasowej; --restore przywraca nazwę sprzed naprawy tylko kartom, których nazwa jest wciąż taka, jak zostawiła
 * ją naprawa — poprawki zrobione później (człowiek, import) zostają.
 */
final class RepairSizeNamesCommand extends Command
{
    private const LABEL = 'repair-size-names';

    /** Słowo rozmiaru bez wartości na końcu nazwy, razem z separatorem przed nim. */
    private const DANGLING_SIZE_WORD = '/[\s,;:\-–—\/]*\b(?:size|rozmiar|taille|rozm\.?|roz\.)\s*$/iu';

    protected $signature = 'products:repair-size-names
                            {--backup= : Plik kopii zapasowej JSON (domyślnie storage/app/repair-backups)}
                            {--restore= : Przywróć nazwy z kopii zapasowej i zakończ}
                            {--apply : Zapisz zmiany (bez tej flagi tylko podgląd)}';

    protected $description = 'Usuwa z nazw rozmiar, który nie opisuje karty: karty PROTEKT z rozmiarem jednej podstrony i urwane „roz.” po łączeniu rozmiarów (podgląd bez --apply)';

    public function handle(): int
    {
        $restore = trim((string) $this->option('restore'));
        if ($restore !== '') {
            return $this->restore($restore);
        }

        $changes = $this->changes();
        if ($changes === []) {
            $this->info('Nazwy kart nie mają rozmiaru do usunięcia — nic do naprawy.');

            return self::SUCCESS;
        }

        $reasons = array_count_values(array_column($changes, 'reason'));
        foreach ($changes as $row) {
            $this->line("#{$row['id']} [{$row['sku']}] {$row['name']} → {$row['written_name']}");
        }
        $summary = count($changes).' nazw (PROTEKT: '.($reasons['protekt'] ?? 0)
            .', urwane słowo rozmiaru po łączeniu: '.($reasons['merged'] ?? 0).')';
        if (! $this->option('apply')) {
            $this->info("Podgląd: {$summary}. Zapis: --apply");

            return self::SUCCESS;
        }

        $path = trim((string) $this->option('backup'));
        if ($path === '') {
            $path = storage_path('app/repair-backups/size-names-'.now()->format('Ymd-His').'.json');
        }
        try {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }
            file_put_contents($path, json_encode(
                ['label' => self::LABEL, 'created_at' => now()->toIso8601String(), 'products' => $changes],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));
        } catch (JsonException $e) {
            $this->error('Kopia zapasowa nie powstała: '.$e->getMessage().' — nic nie zapisano.');

            return self::FAILURE;
        }

        $written = 0;
        $skipped = [];
        foreach ($changes as $row) {
            if ($this->swapName($row['id'], $row['name'], $row['written_name'])) {
                $written++;
            } else {
                $skipped[] = '#'.$row['id'];
            }
        }
        $this->info("Zapisano {$written} nazw. Kopia zapasowa: {$path}");
        if ($skipped !== []) {
            $this->warn('Pominięte (nazwa zmieniła się po podglądzie): '.implode(', ', $skipped));
        }

        return self::SUCCESS;
    }

    /**
     * @return list<array{id: int, sku: string, reason: string, name: string, written_name: string}>
     */
    private function changes(): array
    {
        $changes = [];
        $protektIds = B2bProductLink::query()
            ->whereIn('b2b_account_id', B2bAccount::query()->where('connector', ProtektB2bConnector::key())->select('id'))
            ->distinct()
            ->pluck('product_id')
            ->all();
        foreach (array_chunk($protektIds, 500) as $ids) {
            foreach (Product::query()->whereIn('id', $ids)->get(['id', 'sku', 'name']) as $product) {
                $name = (string) $product->name;
                $new = ProtektB2bConnector::cardNameWithoutSize($name);
                if ($new !== null && $new !== $name) {
                    $changes[(int) $product->id] = $this->row($product, 'protekt', $new);
                }
            }
        }

        Product::query()
            ->where('enrichment_payload', 'like', '%merged_size_skus%')
            ->orderBy('id')
            ->each(function (Product $product) use (&$changes): void {
                $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
                if (isset($changes[(int) $product->id]) || ! is_array($payload['merged_size_skus'] ?? null)
                    || $payload['merged_size_skus'] === []) {
                    return;
                }
                $name = (string) $product->name;
                $new = trim(preg_replace(self::DANGLING_SIZE_WORD, '', $name) ?? $name);
                if ($new !== '' && $new !== $name) {
                    $changes[(int) $product->id] = $this->row($product, 'merged', $new);
                }
            });
        ksort($changes);

        return array_values($changes);
    }

    /**
     * @return array{id: int, sku: string, reason: string, name: string, written_name: string}
     */
    private function row(Product $product, string $reason, string $new): array
    {
        return [
            'id' => (int) $product->id,
            'sku' => (string) $product->sku,
            'reason' => $reason,
            'name' => (string) $product->name,
            'written_name' => mb_substr($new, 0, 1000),
        ];
    }

    /** Compare-and-set: nazwa zmienia się tylko, gdy wciąż jest taka, jak w podglądzie albo kopii zapasowej. */
    private function swapName(int $id, string $expected, string $name): bool
    {
        return DB::transaction(static function () use ($id, $expected, $name): bool {
            $product = Product::query()->lockForUpdate()->find($id);
            if ($product === null || (string) $product->name !== $expected) {
                return false;
            }
            // przez model — hak przelicza indeks tekstowy i zleca reindeks wektora
            $product->update(['name' => $name]);

            return true;
        });
    }

    private function restore(string $path): int
    {
        if (! is_file($path)) {
            $this->error("Nie przywrócono: brak kopii zapasowej: {$path}");

            return self::FAILURE;
        }
        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error("Nie przywrócono: kopia zapasowa jest uszkodzona: {$e->getMessage()}");

            return self::FAILURE;
        }
        if (! is_array($data) || ($data['label'] ?? null) !== self::LABEL) {
            $this->error('Nie przywrócono: to nie jest kopia z products:repair-size-names.');

            return self::FAILURE;
        }
        $restored = 0;
        $skipped = [];
        foreach ((array) ($data['products'] ?? []) as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            $done = $id > 0 && is_string($entry['name'] ?? null) && is_string($entry['written_name'] ?? null)
                && $this->swapName($id, $entry['written_name'], $entry['name']);
            if ($done) {
                $restored++;
            } else {
                $skipped[] = '#'.$id;
            }
        }
        $this->info("Przywrócono {$restored} nazw.");
        if ($skipped !== []) {
            $this->warn('Pominięte (karta usunięta albo nazwa zmieniona po naprawie): '.implode(', ', $skipped));
        }

        return self::SUCCESS;
    }
}
