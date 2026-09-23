<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Services\B2b\B2bConnectorRegistry;
use App\Services\B2b\ShopCardNormFacts;
use App\Support\ManufacturerNormFacts;
use Illuminate\Console\Command;
use JsonException;

/**
 * Normy producenta z tabelki jego sklepu B2B dla kart, które już ją mają (plan norm z 23.09.2026, etap 2). Synchronizacja
 * zapisuje je sama przy każdym przebiegu (B2bCatalogSync::storeNormFacts), a to polecenie uzupełnia karty od razu,
 * z tabelek zapisanych wcześniej — bez żadnego zapytania do sklepu.
 *
 * Domyślnie podgląd. `--apply` zapisuje products.manufacturer_norms i przed pierwszą zmianą robi kopię poprzednich
 * wartości; `--restore=plik` przywraca je z tej kopii. Pary innego łącznika producenta zostają nietknięte.
 * Zapis kolumny zleca przeliczenie indeksu i wektora karty (jak każda zmiana norm producenta).
 */
final class NormsFromShopCardsCommand extends Command
{
    protected $signature = 'norms:from-shop-cards
        {--connector= : Tylko ten łącznik (np. protekt, polstar, artra, 3m, uvex)}
        {--samples=5 : Ile przykładowych kart pokazać przy każdym łączniku}
        {--apply : Zapisz normy producenta (bez tej flagi tylko podgląd)}
        {--backup= : Plik kopii zapasowej JSON (domyślnie storage/app/repair-backups/norms-from-shop-cards-<data>.json)}
        {--restore= : Przywróć normy producenta z pliku kopii i zakończ}';

    protected $description = 'Normy producenta z tabelek jego sklepu B2B dla istniejących kart (podgląd bez --apply)';

    public function handle(B2bConnectorRegistry $registry, ShopCardNormFacts $facts): int
    {
        ini_set('memory_limit', '2048M');
        $restore = trim((string) $this->option('restore'));
        if ($restore !== '') {
            return $this->restore($restore);
        }

        $only = trim((string) $this->option('connector'));
        $apply = (bool) $this->option('apply');
        $sampleLimit = max(0, (int) $this->option('samples'));
        $backupPath = trim((string) $this->option('backup'))
            ?: storage_path('app/repair-backups/norms-from-shop-cards-'.now()->format('Ymd-His').'.json');

        $plan = [];
        $summary = [];
        foreach (B2bAccount::query()->orderBy('id')->get() as $account) {
            $key = $registry->keyForAccount($account);
            $source = $key !== null ? $registry->shopFieldNormSource($key) : null;
            if ($source === null || ($only !== '' && $only !== $key)) {
                continue;
            }
            $row = ['konto' => $account->id, 'łącznik' => $key, ShopCardNormFacts::SAVED => 0, ShopCardNormFacts::SAME => 0,
                ShopCardNormFacts::NONE => 0, ShopCardNormFacts::OTHER_SOURCE => 0, 'samples' => []];
            $ids = B2bProductLink::query()->where('b2b_account_id', $account->id)
                ->pluck('product_id')->map(static fn ($id): int => (int) $id)->unique()->values()->all();
            foreach (array_chunk($ids, 500) as $chunk) {
                foreach (Product::query()->whereIn('id', $chunk)->get() as $product) {
                    $result = $facts->resolve($product, $account, $key, $source['brand'], $source['names']);
                    $row[$result['status']]++;
                    if ($result['status'] !== ShopCardNormFacts::SAVED) {
                        continue;
                    }
                    $plan[] = [$product, $result['column']];
                    if (count($row['samples']) < $sampleLimit) {
                        $row['samples'][] = '#'.$product->id.' '.mb_substr((string) $product->name, 0, 40).': '
                            .implode(', ', array_map(
                                static fn (array $r): string => $r['label'].($r['value'] !== '' ? ' '.$r['value'] : ''),
                                ManufacturerNormFacts::rows($result['column']),
                            ));
                    }
                }
            }
            $summary[] = $row;
        }

        if ($summary === []) {
            $this->warn('Brak kont łącznika producenta z normami w tabelce karty'.($only !== '' ? " „{$only}”" : '').'.');

            return self::SUCCESS;
        }
        $this->table(
            ['konto', 'łącznik', 'do zapisu', 'bez zmian', 'bez norm w tabelce', 'inny łącznik (zostaje)'],
            array_map(static fn (array $r): array => [$r['konto'], $r['łącznik'], $r[ShopCardNormFacts::SAVED], $r[ShopCardNormFacts::SAME],
                $r[ShopCardNormFacts::NONE], $r[ShopCardNormFacts::OTHER_SOURCE]], $summary),
        );
        foreach ($summary as $r) {
            foreach ($r['samples'] as $line) {
                $this->line("  {$r['łącznik']} {$line}");
            }
        }

        if (! $apply) {
            $this->info('Podgląd — uruchom z --apply, żeby zapisać '.count($plan).' kart.');

            return self::SUCCESS;
        }
        if ($plan === []) {
            $this->info('Nic do zapisania.');

            return self::SUCCESS;
        }

        $backup = [];
        foreach ($plan as [$product]) {
            $backup[(string) $product->id] = $product->manufacturer_norms;
        }
        $error = $this->writeBackup($backupPath, $backup);
        if ($error !== null) {
            $this->error("Nie zapisano kopii zapasowej ({$error}) — nic nie zmieniono.");

            return self::FAILURE;
        }
        foreach ($plan as [$product, $column]) {
            $product->manufacturer_norms = $column;
            $product->save();
        }
        $this->info('Zapisano normy producenta na '.count($plan).' kartach. Kopia: '.$backupPath);
        $this->line('Przywrócenie stanu sprzed: --restore="'.$backupPath.'"');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $backup  id karty => poprzednia zawartość manufacturer_norms
     */
    private function writeBackup(string $path, array $backup): ?string
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return "nie można utworzyć katalogu {$dir}";
        }
        try {
            $json = json_encode(['created_at' => now()->toIso8601String(), 'manufacturer_norms' => $backup], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $e->getMessage();
        }

        return file_put_contents($path, $json) === false ? "nie można zapisać {$path}" : null;
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
        $restored = 0;
        foreach ((array) ($data['manufacturer_norms'] ?? []) as $id => $column) {
            $product = Product::query()->find((int) $id);
            if ($product === null) {
                continue;
            }
            $product->manufacturer_norms = is_array($column) ? $column : null;
            $product->save();
            $restored++;
        }
        $this->info("Przywrócono normy producenta na {$restored} kartach z kopii {$path}.");

        return self::SUCCESS;
    }
}
