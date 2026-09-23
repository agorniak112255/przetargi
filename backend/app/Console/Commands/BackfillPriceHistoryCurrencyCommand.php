<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProductSourcePrice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Uzupełnia walutę wierszy historii cen sprzed kolumny product_price_history.currency (23.09.2026). Bez niej tabela
 * „Historia cen” podpisywała ceny walutą karty — na karcie Bolle w EUR cena Procery w PLN wyglądała na EUR.
 *
 * Waluta jest WNIOSKOWANA z obecnego slotu tego samego źródła na karcie (źródła nie zmieniają waluty):
 * - import i rabat cennika — slot pliku;
 * - wiersz przebiegu B2B — slot konta tego przebiegu;
 * - „b2b:{łącznik}” bez przebiegu — sloty kont tego łącznika, gdy mają jedną walutę;
 * - dawne „b2b_api”, puste i inne — wszystkie sloty karty, gdy mają jedną walutę.
 * Czego nie da się ustalić, zostaje puste (waluta nieznana). Wypełniane są tylko puste waluty — nic nie jest
 * nadpisywane. Przed zapisem powstaje kopia z identyfikatorami wierszy; --restore czyści walutę tylko tych wierszy.
 */
final class BackfillPriceHistoryCurrencyCommand extends Command
{
    protected $signature = 'prices:backfill-history-currency
        {--apply : Zapisz waluty (bez tego tylko podgląd)}
        {--backup= : Ścieżka kopii z identyfikatorami wierszy (domyślnie storage/app/repair-backups)}
        {--restore= : Wyczyść walutę wierszy z podanej kopii (cofnięcie)}';

    protected $description = 'Uzupełnia walutę starych wierszy historii cen z waluty slotu tego samego źródła na karcie';

    private const FILE_SOURCES = ['price_list_import', 'price_list_discount'];

    private const B2B_PREFIX = 'b2b:';

    private const PRODUCTS_PER_CHUNK = 500;

    private const UPDATE_CHUNK = 1000;

    private const PREVIEW_ROWS = 20;

    public function handle(): int
    {
        $restore = trim((string) $this->option('restore'));
        if ($restore !== '') {
            return $this->restore($restore);
        }

        /** @var array<string, list<int>> $plan waluta => id wierszy */
        $plan = [];
        /** @var array<string, int> $byRule */
        $byRule = [];
        /** @var array<string, int> $unresolved powód => liczba wierszy */
        $unresolved = [];
        /** @var list<string> $unresolvedSamples */
        $unresolvedSamples = [];
        $connectors = DB::table('b2b_accounts')->pluck('connector', 'id')->map(static fn (mixed $c): string => (string) $c)->all();

        DB::table('product_price_history')
            ->whereNull('currency')
            ->select('product_id')
            ->distinct()
            ->orderBy('product_id')
            ->chunk(self::PRODUCTS_PER_CHUNK, function ($chunk) use (&$plan, &$byRule, &$unresolved, &$unresolvedSamples, $connectors): void {
                $productIds = $chunk->pluck('product_id')->map(static fn (mixed $id): int => (int) $id)->all();
                /** @var array<int, array<string, string|null>> $slots product_id => source_key => waluta */
                $slots = [];
                foreach (DB::table('product_source_prices')->whereIn('product_id', $productIds)->get(['product_id', 'source_key', 'currency']) as $slot) {
                    $slots[(int) $slot->product_id][(string) $slot->source_key] = $this->currency($slot->currency);
                }
                $rows = DB::table('product_price_history as h')
                    ->leftJoin('b2b_sync_runs as r', 'r.id', '=', 'h.b2b_sync_run_id')
                    ->whereIn('h.product_id', $productIds)
                    ->whereNull('h.currency')
                    ->get(['h.id', 'h.product_id', 'h.source', 'r.b2b_account_id']);
                foreach ($rows as $row) {
                    [$currency, $rule] = $this->resolve($row, $slots[(int) $row->product_id] ?? [], $connectors);
                    if ($currency === null) {
                        $unresolved[$rule] = ($unresolved[$rule] ?? 0) + 1;
                        if (count($unresolvedSamples) < self::PREVIEW_ROWS) {
                            $unresolvedSamples[] = sprintf('wiersz #%d karta #%d źródło „%s”: %s', $row->id, $row->product_id, (string) $row->source, $rule);
                        }

                        continue;
                    }
                    $plan[$currency][] = (int) $row->id;
                    $byRule[$rule] = ($byRule[$rule] ?? 0) + 1;
                }
            });

        $this->report($plan, $byRule, $unresolved, $unresolvedSamples);

        if (! $this->option('apply')) {
            $this->info('Podgląd — uruchom z --apply, żeby zapisać (przed zapisem powstanie kopia z identyfikatorami wierszy).');

            return self::SUCCESS;
        }
        if ($plan === []) {
            $this->info('Nic do zapisania.');

            return self::SUCCESS;
        }

        $backup = trim((string) $this->option('backup'));
        if ($backup === '') {
            $backup = storage_path('app/repair-backups/price-history-currency-'.now()->format('Ymd-His').'.json');
        }
        $error = $this->writeBackup($backup, $plan);
        if ($error !== null) {
            $this->error("Kopia nie powstała ({$error}) — nic nie zmieniam.");

            return self::FAILURE;
        }

        $updated = 0;
        foreach ($plan as $currency => $ids) {
            foreach (array_chunk($ids, self::UPDATE_CHUNK) as $part) {
                // tylko puste — wiersz zapisany w międzyczasie z walutą zostaje
                $updated += DB::table('product_price_history')->whereIn('id', $part)->whereNull('currency')->update(['currency' => $currency]);
            }
        }
        $this->info("Zapisano walutę {$updated} wierszy. Kopia: {$backup}");
        $this->line("Cofnięcie: --restore=\"{$backup}\"");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string|null>  $slots  source_key => waluta slotu tej karty
     * @param  array<int, string>  $connectors  b2b_account_id => łącznik
     * @return array{0: string|null, 1: string} waluta (null = nie da się ustalić) i reguła albo powód
     */
    private function resolve(object $row, array $slots, array $connectors): array
    {
        $source = trim((string) $row->source);

        if (in_array($source, self::FILE_SOURCES, true)) {
            return array_key_exists(ProductSourcePrice::SOURCE_FILE, $slots) && $slots[ProductSourcePrice::SOURCE_FILE] !== null
                ? [$slots[ProductSourcePrice::SOURCE_FILE], 'plik → slot pliku']
                : [null, 'wiersz z pliku, karta bez slotu pliku z walutą'];
        }

        if ($row->b2b_account_id !== null) {
            $key = ProductSourcePrice::b2bKey((int) $row->b2b_account_id);

            return ($slots[$key] ?? null) !== null
                ? [$slots[$key], 'przebieg B2B → slot konta']
                : [null, 'wiersz przebiegu B2B, karta bez slotu tego konta z walutą'];
        }

        if (str_starts_with($source, self::B2B_PREFIX)) {
            $connector = substr($source, strlen(self::B2B_PREFIX));
            $currencies = [];
            foreach ($slots as $key => $currency) {
                if (str_starts_with($key, self::B2B_PREFIX) && ($connectors[(int) substr($key, strlen(self::B2B_PREFIX))] ?? null) === $connector) {
                    $currencies[] = $currency;
                }
            }

            return $this->single($currencies, 'łącznik bez przebiegu → sloty kont łącznika', 'łącznik bez przebiegu, sloty kont łącznika brak lub różne waluty');
        }

        return $this->single(array_values($slots), 'dawne/nieznane źródło → jedna waluta wszystkich slotów karty', 'dawne/nieznane źródło, sloty karty brak lub różne waluty');
    }

    /**
     * @param  list<string|null>  $currencies
     * @return array{0: string|null, 1: string}
     */
    private function single(array $currencies, string $rule, string $reason): array
    {
        $distinct = array_values(array_unique($currencies));

        return count($distinct) === 1 && $distinct[0] !== null ? [$distinct[0], $rule] : [null, $reason];
    }

    /**
     * @param  array<string, list<int>>  $plan
     * @param  array<string, int>  $byRule
     * @param  array<string, int>  $unresolved
     * @param  list<string>  $samples
     */
    private function report(array $plan, array $byRule, array $unresolved, array $samples): void
    {
        $this->line('Do uzupełnienia wg waluty:');
        foreach ($plan as $currency => $ids) {
            $this->line(sprintf('  %s: %d', $currency, count($ids)));
        }
        $this->line('Wg reguły:');
        foreach ($byRule as $rule => $count) {
            $this->line(sprintf('  %s: %d', $rule, $count));
        }
        $this->line('Zostaje bez waluty (nie da się ustalić): '.array_sum($unresolved));
        foreach ($unresolved as $reason => $count) {
            $this->line(sprintf('  %s: %d', $reason, $count));
        }
        foreach ($samples as $sample) {
            $this->line('  · '.$sample);
        }
    }

    /**
     * @param  array<string, list<int>>  $plan
     */
    private function writeBackup(string $path, array $plan): ?string
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return 'brak katalogu '.$dir;
        }
        try {
            $json = json_encode(['created_at' => now()->toIso8601String(), 'ids_by_currency' => $plan], JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $e->getMessage();
        }

        return @file_put_contents($path, $json) === false ? 'nie można zapisać '.$path : null;
    }

    private function restore(string $path): int
    {
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($data) || ! is_array($data['ids_by_currency'] ?? null)) {
            $this->error('Nie można odczytać kopii: '.$path);

            return self::FAILURE;
        }
        $cleared = 0;
        foreach ($data['ids_by_currency'] as $currency => $ids) {
            foreach (array_chunk(array_map('intval', (array) $ids), self::UPDATE_CHUNK) as $part) {
                // tylko wiersze nadal z walutą z kopii — późniejszej zmiany nie cofamy
                $cleared += DB::table('product_price_history')->whereIn('id', $part)->where('currency', (string) $currency)->update(['currency' => null]);
            }
        }
        $this->info("Wyczyszczono walutę {$cleared} wierszy.");

        return self::SUCCESS;
    }

    private function currency(mixed $value): ?string
    {
        $code = strtoupper(trim((string) $value));

        return $code === '' ? null : $code;
    }
}
