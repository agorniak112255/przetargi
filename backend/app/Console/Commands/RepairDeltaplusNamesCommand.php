<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Services\B2b\DeltaplusB2bClient;
use App\Services\B2b\DeltaplusB2bConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

/**
 * Nazwy kart Delta Plus, których h1 na witrynie jest samym kodem (odczyt produkcji 28.09.2026: 441 z 999 kart, 95
 * różnych przedrostków): łącznik dokładał przed kod nagłówek kategorii — sektor albo zastosowanie, nie rodzaj wyrobu
 * („Kształtowanie krajobrazu DPVE733”, „Prace w środowisku zaolejonym i tłustym …”) — albo krótki opis, który bywa
 * hasłem reklamowym („Pracujemy jak dorośli …”). Od decyzji właściciela 28.09.2026 nowe karty dostają przedrostek
 * z kolumny OPIS cennika publicznego do pierwszego przecinka, dosłownie (DeltaplusB2bConnector::publicNames,
 * cardName). Synchronizacja nie zmienia nazwy istniejącej karty (decyzja 15.09.2026), więc stare naprawia to polecenie.
 *
 * Karta z powiązaniem konta --account dostaje nazwę „{OPIS do przecinka} {kod}” tylko wtedy, gdy obecna nazwa to
 * nietknięta nazwa automatu: „{P} {kod}” (kod = ostatnie słowo z cyfrą), gdzie P to ostatni człon kategorii karty
 * (ścieżka nawigacji — nagłówek kategorii) albo początek opisu karty (krótki opis otwiera opis, prose()). Nazwę inną
 * (h1 strony „APOLLON VV733”, poprawka człowieka) i kartę bez jednoznacznego OPIS modelu zostawiamy. Porównania bez
 * wielkości liter, odstępów i znaków zerowej szerokości (DeltaplusB2bConnector::modelKey).
 *
 * Domyślnie tylko podgląd. --apply zapisuje przez model (hak przelicza indeks tekstowy i zleca reindeks wektora), karta
 * po karcie w transakcji, po kopii zapasowej; --restore przywraca nazwę sprzed naprawy tylko kartom, których nazwa jest
 * wciąż taka, jak zostawiła ją naprawa — poprawki zrobione później (człowiek, import) zostają. --file bierze cennik
 * z pliku zamiast pobierania z witryny (logowanie kontem).
 */
final class RepairDeltaplusNamesCommand extends Command
{
    private const LABEL = 'repair-deltaplus-names';

    protected $signature = 'products:repair-deltaplus-names
                            {--account= : konto B2B Delta Plus}
                            {--file= : plik xlsx cennika publicznego zamiast pobierania}
                            {--backup= : Plik kopii zapasowej JSON (domyślnie storage/app/repair-backups)}
                            {--restore= : Przywróć nazwy z kopii zapasowej i zakończ}
                            {--apply : Zapisz zmiany (bez tej flagi tylko podgląd)}';

    protected $description = 'Nazwy kart Delta Plus z kodem w h1: przedrostek kategorii albo krótkiego opisu zamienia na OPIS z cennika publicznego do pierwszego przecinka (podgląd bez --apply)';

    public function handle(): int
    {
        $restore = trim((string) $this->option('restore'));
        if ($restore !== '') {
            return $this->restore($restore);
        }

        $accountId = (int) $this->option('account');
        $account = $accountId > 0 ? B2bAccount::query()->find($accountId) : null;
        if ($account === null || $account->connector !== DeltaplusB2bConnector::key()) {
            $ids = B2bAccount::query()->where('connector', DeltaplusB2bConnector::key())->orderBy('id')->pluck('id')->all();
            $this->error(($account === null ? 'Podaj konto B2B Delta Plus' : "Konto {$accountId} nie jest kontem Delta Plus")
                .' (--account=; konta Delta Plus: '.($ids === [] ? 'brak' : implode(', ', $ids)).').');

            return self::FAILURE;
        }

        $names = $this->publicNames($account);
        if ($names === null) {
            return self::FAILURE;
        }
        if ($names === []) {
            $this->error('Cennik publiczny nie dał żadnej nazwy z kolumny OPIS (zmieniony układ arkusza?) — nic nie zmieniam.');

            return self::FAILURE;
        }
        $this->line('Cennik publiczny: nazwy z kolumny OPIS dla '.count($names).' modeli.');

        [$changes, $rows, $counts] = $this->plan($account, $names);
        if ($rows !== []) {
            $this->table(['ID', 'SKU', 'Nazwa teraz', 'Nazwa nowa', 'Powód'], $rows);
        }
        $this->line(sprintf(
            'Do zmiany: %d; automatyczna nazwa bez OPIS w cenniku (zostaje): %d; nie przedrostek automatu — h1 strony albo'
            .' nazwa ręczna (zostaje%s): %d; już z OPIS: %d; bez kodu na końcu nazwy: %d.',
            count($changes),
            $counts['no_opis'],
            $this->output->isVerbose() ? '' : ', lista z -v',
            $counts['manual'],
            $counts['done'],
            $counts['no_code'],
        ));
        if ($changes === []) {
            $this->info('Nic do zapisania.');

            return self::SUCCESS;
        }
        if (! $this->option('apply')) {
            $this->info('Podgląd — uruchom z --apply, żeby zapisać.');

            return self::SUCCESS;
        }

        $path = trim((string) $this->option('backup'));
        if ($path === '') {
            $path = storage_path('app/repair-backups/deltaplus-names-'.$account->id.'-'.now()->format('Ymd-His').'.json');
        }
        $error = $this->writeBackup($path, $changes);
        if ($error !== null) {
            $this->error("Kopia zapasowa nie powstała ({$error}) — nic nie zmieniam.");

            return self::FAILURE;
        }

        $written = 0;
        $skipped = [];
        foreach ($changes as $change) {
            if ($this->swapName($change['id'], $change['name'], $change['written_name'])) {
                $written++;
            } else {
                $skipped[] = '#'.$change['id'];
            }
        }
        $this->info("Zapisano {$written} nazw. Kopia zapasowa: {$path}");
        $this->line("Przywrócenie stanu sprzed: --restore=\"{$path}\"");
        if ($skipped !== []) {
            $this->warn('Pominięte (nazwa zmieniła się po podglądzie albo karty nie ma): '.implode(', ', $skipped));
        }

        return self::SUCCESS;
    }

    /**
     * Nazwy z cennika publicznego: z --file albo pobrane kontem (klient loguje się sam). Null — błąd już wypisany.
     *
     * @return array<string, string>|null
     */
    private function publicNames(B2bAccount $account): ?array
    {
        try {
            $file = trim((string) $this->option('file'));
            if ($file !== '') {
                if (! is_file($file)) {
                    $this->error("Nie ma pliku cennika: {$file}");

                    return null;
                }

                return DeltaplusB2bConnector::publicNames((string) file_get_contents($file));
            }
            $download = (new DeltaplusB2bClient((string) $account->username, (string) $account->password))->publicPriceListXlsx();
            if ($download === null) {
                $this->error('Na stronie „Cenniki i promocje” nie ma odnośnika do cennika publicznego (xlsx) — podaj plik w --file=.');

                return null;
            }
            $this->line("Cennik publiczny pobrany: {$download['name']}");

            return DeltaplusB2bConnector::publicNames($download['bytes']);
        } catch (Throwable $e) {
            $this->error('Cennik publiczny nieodczytany: '.$e->getMessage());

            return null;
        }
    }

    /**
     * @param  array<string, string>  $names
     * @return array{
     *     0: list<array{id: int, sku: string, name: string, written_name: string}>,
     *     1: list<list<string|int>>,
     *     2: array{no_opis: int, manual: int, done: int, no_code: int}
     * }
     */
    private function plan(B2bAccount $account, array $names): array
    {
        $changes = [];
        $rows = [];
        $counts = ['no_opis' => 0, 'manual' => 0, 'done' => 0, 'no_code' => 0];
        $productIds = B2bProductLink::query()
            ->where('b2b_account_id', $account->id)
            ->whereNotNull('product_id')
            ->distinct()
            ->orderBy('product_id')
            ->pluck('product_id')
            ->all();
        foreach (array_chunk($productIds, 500) as $ids) {
            foreach (Product::query()->whereIn('id', $ids)->orderBy('id')->get(['id', 'sku', 'name', 'category', 'description']) as $product) {
                $name = (string) $product->name;
                // „{P} {kod}”: kod = ostatnie słowo z cyfrą, P niepuste
                if (preg_match('/^(.*\S)\s+(\S*\d\S*)$/u', trim($name), $m) !== 1) {
                    $counts['no_code']++;

                    continue;
                }
                [, $prefix, $code] = $m;
                $target = $names[DeltaplusB2bConnector::modelKey($code)] ?? null;
                $new = $target !== null ? $target.' '.$code : null;
                if ($new === $name) {
                    $counts['done']++;

                    continue;
                }
                $source = $this->automaticPrefixSource($product, $prefix);
                $row = [(int) $product->id, (string) $product->sku, mb_substr($name, 0, 60)];
                if ($source === null) {
                    $counts['manual']++;
                    if ($this->output->isVerbose()) {
                        $rows[] = [...$row, '(bez zmian)', 'nazwa ręczna albo h1 strony'];
                    }

                    continue;
                }
                if ($new === null) {
                    $counts['no_opis']++;
                    $rows[] = [...$row, '(bez zmian)', 'brak jednoznacznego OPIS modelu '.DeltaplusB2bConnector::modelKey($code).' w cenniku'];

                    continue;
                }
                $changes[] = ['id' => (int) $product->id, 'sku' => (string) $product->sku, 'name' => $name, 'written_name' => $new];
                $rows[] = [...$row, mb_substr($new, 0, 60), 'przedrostek = '.$source];
            }
        }

        return [$changes, $rows, $counts];
    }

    /**
     * Skąd automat wziął przedrostek: 'kategoria' (ostatni człon ścieżki kategorii karty), 'krótki opis' (opis karty
     * zaczyna się od niego — całe słowo albo cały opis); null — nazwa nie wygląda na nazwę automatu.
     */
    private function automaticPrefixSource(Product $product, string $prefix): ?string
    {
        $key = DeltaplusB2bConnector::modelKey($prefix);
        if ($key === '') {
            return null;
        }
        $parts = explode(' > ', (string) $product->category);
        if (DeltaplusB2bConnector::modelKey((string) end($parts)) === $key) {
            return 'kategoria';
        }
        $description = DeltaplusB2bConnector::modelKey((string) $product->description);
        if (str_starts_with($description, $key) && (strlen($description) === strlen($key) || $description[strlen($key)] === ' ')) {
            return 'krótki opis';
        }

        return null;
    }

    /**
     * @param  list<array{id: int, sku: string, name: string, written_name: string}>  $changes
     * @return string|null błąd albo null, gdy kopia zapisana i odczytana w całości
     */
    private function writeBackup(string $path, array $changes): ?string
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return "nie można utworzyć katalogu {$dir}";
        }
        try {
            $json = json_encode(
                ['label' => self::LABEL, 'created_at' => now()->toIso8601String(), 'products' => $changes],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            );
            if (file_put_contents($path, $json) === false) {
                return 'zapis pliku nie powiódł się';
            }
            // kontrola przed pierwszą zmianą: kopia musi dać się odczytać w całości
            $read = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $e->getMessage();
        }

        return is_array($read) && count($read['products'] ?? []) === count($changes) ? null : 'kopia jest niekompletna';
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
            $this->error('Nie przywrócono: to nie jest kopia z products:repair-deltaplus-names.');

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
        $this->info("Przywrócono {$restored} nazw z kopii {$path}.");
        if ($skipped !== []) {
            $this->warn('Pominięte (karta usunięta albo nazwa zmieniona po naprawie): '.implode(', ', $skipped));
        }

        return self::SUCCESS;
    }
}
