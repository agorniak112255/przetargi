<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PrestaProductMatch;
use App\Models\Product;
use App\Services\B2b\UvexB2bConnector;
use App\Services\B2b\UvexSizeGroups;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Decyzja użytkownika 30.09.2026: kod karty UVEX z rozmiarami bez rozmiaru — „6931/2”, nie „6931/2/35”; „NB60SZ”,
 * nie „NB60SZ/9”; „60023”, nie „6002306”; „HA2023”, nie „HA2023(L)”. Łącznik (UvexB2bConnector, UvexSizeGroups::modelCodes) robi tak
 * od 30.09.2026 na nowych kartach i przy „Scal rozmiary”, ale synchronizacja nie zmienia kodu zastanej karty — a karty
 * scalone 28–29.09.2026 dostały kod pierwszego rozmiaru. To polecenie poprawia zastane karty konta.
 *
 * Dowód = kody pozycji tego konta na karcie (b2b_product_links, remote_sku), nie nazwa:
 * - co najmniej dwie pozycje, każda z rozmiarem w nazwie u dostawcy albo na końcu kodu (UvexSizeGroups::sizeOf, ta
 *   sama reguła co łącznik) — inaczej to nie karta rozmiarów (kolory „2600.010” / „2600.011”) i kod zostaje bez uwagi;
 * - nowy kod = wspólny kod modelu kodów pozycji (UvexSizeGroups::modelCode: „/rozmiar”, „(rozmiar)”, dwie ostatnie
 *   cyfry); kody bez wspólnego kodu modelu (dwa modele na karcie, „6659/07 FOAM”) — kod zostaje z uwagą;
 * - obecny kod karty to kod rozmiaru tego modelu (także rozmiaru, którego sklep już nie podaje) — inny kod karty
 *   (nadany ręcznie) zostaje;
 * - karta z powiązaniem innego konta B2B albo z dopasowaniem PrestaShop — pomijana (kod służy tam też innym źródłom);
 * - nowy kod zajęty przez inną kartę albo wspólny dla dwóch kart konta — kod zostaje z uwagą.
 * Nazwa karty, kody pozycji (powiązania, identyfikatory, tabela rozmiarów) zostają dosłownie.
 *
 * Domyślnie podgląd; --apply zapisuje (przez model — hak Product::saving przelicza indeks tekstowy, Product::updated zleca
 * reindeks wektora), karta w osobnej transakcji, po kopii zapasowej JSON (storage/app/repair-backups). --restore oddaje
 * kod sprzed naprawy tylko kartom, które mają wciąż kod zostawiony przez naprawę.
 */
final class RepairUvexModelCodesCommand extends Command
{
    private const BACKUP_LABEL = 'repair-uvex-model-codes';

    protected $signature = 'products:repair-uvex-model-codes
                            {--account= : Numer konta B2B UVEX}
                            {--backup= : Plik kopii zapasowej JSON (domyślnie storage/app/repair-backups)}
                            {--restore= : Przywróć kody z kopii zapasowej i zakończ}
                            {--apply : Zapisz zmiany (bez tej flagi tylko podgląd)}';

    protected $description = 'Usuwa rozmiar z kodu kart UVEX z rozmiarami (6931/2/35 → 6931/2; podgląd bez --apply)';

    public function handle(): int
    {
        $restore = trim((string) $this->option('restore'));
        if ($restore !== '') {
            return $this->restore($restore);
        }

        $accountId = (int) $this->option('account');
        $account = $accountId > 0 ? B2bAccount::query()->find($accountId) : null;
        if ($account === null) {
            $this->error($accountId > 0 ? "Nie ma konta B2B numer {$accountId}." : 'Podaj numer konta UVEX, np. --account=5.');

            return self::FAILURE;
        }
        if ((string) $account->connector !== UvexB2bConnector::key()) {
            $this->error("Konto #{$account->id} nie jest kontem UVEX (łącznik: ".((string) $account->connector ?: 'brak').').');

            return self::FAILURE;
        }

        $plans = $this->plans($account);
        $changes = array_values(array_filter($plans, static fn (array $p): bool => $p['sku'] !== null));
        $rows = array_values(array_filter($plans, static fn (array $p): bool => $p['sku'] !== null || $p['notes'] !== []));

        $this->table(
            ['ID', 'Kod teraz', 'Kod nowy', 'Nazwa', 'Uwagi'],
            array_map(static fn (array $p): array => [
                (int) $p['product']->id,
                (string) $p['product']->sku,
                $p['sku'] ?? '(bez zmian)',
                mb_substr((string) $p['product']->name, 0, 60),
                implode('; ', $p['notes']),
            ], $rows),
        );
        $this->line(sprintf(
            'Kart konta: %d; do zmiany kodu: %d; z uwagą bez zmian: %d; bez zmian: %d.',
            count($plans),
            count($changes),
            count($rows) - count($changes),
            count($plans) - count($rows),
        ));

        if ($changes === []) {
            $this->info('Nic do zapisania.');

            return self::SUCCESS;
        }
        if (! $this->option('apply')) {
            $this->info('Podgląd — uruchom z --apply, żeby zapisać.');

            return self::SUCCESS;
        }

        $backup = trim((string) $this->option('backup'));
        if ($backup === '') {
            $backup = storage_path('app/repair-backups/uvex-model-codes-'.$account->id.'-'.now()->format('Ymd-His').'.json');
        }
        $error = $this->writeBackup($backup, $changes);
        if ($error !== null) {
            $this->error("Kopia zapasowa nie powstała ({$error}) — nic nie zmieniam.");

            return self::FAILURE;
        }

        $saved = 0;
        $skipped = [];
        foreach ($changes as $change) {
            $reason = DB::transaction(static function () use ($change): ?string {
                $product = Product::query()->lockForUpdate()->find($change['product']->id);
                // compare-and-set: karta zmieniona od podglądu (człowiek, synchronizacja) zostaje, jak jest
                if ($product === null || $product->sku !== $change['product']->sku) {
                    return 'karta zmieniła się od podglądu';
                }
                if (self::skuTakenBy($change['sku'], (int) $product->id) !== null) {
                    return "kod {$change['sku']} zajęty od podglądu";
                }
                // przez model: hak saving przelicza indeks tekstowy, updated zleca reindeks wektora
                $product->sku = $change['sku'];
                $product->save();

                return null;
            });
            if ($reason === null) {
                $saved++;
            } else {
                $skipped[] = '#'.$change['product']->id.' ('.$reason.')';
            }
        }
        $this->info("Poprawiono {$saved} kart. Kopia zapasowa: {$backup}");
        if ($skipped !== []) {
            $this->warn('Pominięte przy zapisie: '.implode('; ', $skipped));
        }
        $this->line("Przywrócenie stanu sprzed: --restore=\"{$backup}\"");

        return self::SUCCESS;
    }

    /**
     * Plan każdej karty konta: nowy kod (null = bez zmian) i uwagi.
     *
     * @return list<array{product: Product, sku: ?string, notes: list<string>}>
     */
    private function plans(B2bAccount $account): array
    {
        $accountId = (int) $account->id;
        $productIds = B2bProductLink::query()->where('b2b_account_id', $accountId)->distinct()->pluck('product_id')
            ->map(static fn (mixed $id): int => (int) $id)->all();
        if ($productIds === []) {
            return [];
        }
        /** @var Collection<int, Product> $products */
        $products = Product::query()->whereIn('id', $productIds)->orderBy('id')->get();
        $links = B2bProductLink::query()->whereIn('product_id', $productIds)->get()->groupBy('product_id');
        $presta = PrestaProductMatch::query()->whereIn('product_id', $productIds)->distinct()->pluck('product_id')
            ->map(static fn (mixed $id): int => (int) $id)->flip();
        $groups = new UvexSizeGroups;

        $plans = [];
        foreach ($products as $product) {
            $id = (int) $product->id;
            /** @var Collection<int, B2bProductLink> $cardLinks */
            $cardLinks = $links->get($id, collect());
            $own = $cardLinks->filter(static fn (B2bProductLink $l): bool => (int) $l->b2b_account_id === $accountId);
            $positions = $own->mapWithKeys(static fn (B2bProductLink $l): array => [trim((string) ($l->remote_sku ?: $l->remote_id)) => trim((string) $l->remote_name)])
                ->filter(static fn (string $name, int|string $code): bool => $code !== '')->sortKeys()->all();
            $plan = $this->plan($product, $positions, $groups);
            if ($plan['sku'] !== null) {
                $foreign = $cardLinks->filter(static fn (B2bProductLink $l): bool => (int) $l->b2b_account_id !== $accountId)
                    ->pluck('b2b_account_id')->map(static fn (mixed $a): int => (int) $a)->unique()->sort()->values()->all();
                if ($foreign !== []) {
                    $plan = ['product' => $product, 'sku' => null, 'notes' => ['pominięta: powiązania innych kont B2B (#'.implode(', #', $foreign).')']];
                } elseif (isset($presta[$id])) {
                    $plan = ['product' => $product, 'sku' => null, 'notes' => ['pominięta: karta dopasowana do PrestaShop']];
                }
            }
            $plans[] = $plan;
        }

        // dwie karty konta z tym samym nowym kodem — dawny podział rozmiarów według ceny: najpierw „Scal rozmiary”
        $byNewSku = [];
        foreach ($plans as $index => $plan) {
            if ($plan['sku'] !== null) {
                $byNewSku[mb_strtolower($plan['sku'])][] = $index;
            }
        }
        foreach ($byNewSku as $indexes) {
            if (count($indexes) < 2) {
                continue;
            }
            $ids = implode(', ', array_map(static fn (int $i): string => '#'.$plans[$i]['product']->id, $indexes));
            foreach ($indexes as $i) {
                $plans[$i]['notes'][] = "ten sam kod {$plans[$i]['sku']} na kartach {$ids} — najpierw Scal rozmiary";
                $plans[$i]['sku'] = null;
            }
        }
        // kod zajęty przez kartę spoza tej naprawy — kod zostaje
        foreach ($plans as $index => $plan) {
            if ($plan['sku'] === null) {
                continue;
            }
            $taken = self::skuTakenBy($plan['sku'], (int) $plan['product']->id);
            if ($taken !== null) {
                $plans[$index]['notes'][] = "kod {$plan['sku']} zajęty przez #{$taken}";
                $plans[$index]['sku'] = null;
            }
        }

        return $plans;
    }

    /**
     * @param  array<string, string>  $positions  kod pozycji tego konta na karcie => nazwa pozycji u dostawcy
     * @return array{product: Product, sku: ?string, notes: list<string>}
     */
    private function plan(Product $product, array $positions, UvexSizeGroups $groups): array
    {
        $unchanged = ['product' => $product, 'sku' => null, 'notes' => []];
        $codes = array_map('strval', array_keys($positions));
        if (count($codes) < 2) {
            return $unchanged;
        }
        // każda pozycja ma rozmiar (z nazwy u dostawcy albo z końca kodu — reguła łącznika); bez tego to nie karta
        // rozmiarów (kolory 2600.010 / 2600.011, scalone duplikaty) i kodu nie ruszamy
        foreach ($positions as $code => $name) {
            if ($groups->sizeOf($name, (string) $code) === null) {
                return $unchanged;
            }
        }
        $stem = $groups->modelCode($codes);
        if ($stem === null) {
            return [...$unchanged, 'notes' => ['kody pozycji bez wspólnego kodu modelu ('.implode(', ', array_slice($codes, 0, 4)).(count($codes) > 4 ? ', …' : '').') — kod zostaje']];
        }
        $sku = trim((string) $product->sku);
        if ($sku === $stem) {
            return $unchanged;
        }
        // kod karty = kod któregoś rozmiaru tego modelu (także rozmiaru, którego sklep już nie podaje)
        if ($groups->modelCode([...$codes, $sku]) !== $stem) {
            return [...$unchanged, 'notes' => ["kod karty nie jest kodem rozmiaru {$stem} — kod zostaje"]];
        }

        return ['product' => $product, 'sku' => $stem, 'notes' => []];
    }

    /** Karta (inna niż $exceptId) z tym kodem, bez rozróżniania wielkości liter — products.sku jest unikalne. */
    private static function skuTakenBy(string $sku, int $exceptId): ?int
    {
        $id = Product::query()->whereRaw('LOWER(sku) = ?', [mb_strtolower($sku)])->where('id', '!=', $exceptId)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @param  list<array{product: Product, sku: string, notes: list<string>}>  $changes
     * @return string|null błąd albo null, gdy kopia zapisana i odczytana w całości
     */
    private function writeBackup(string $path, array $changes): ?string
    {
        $entries = array_map(static fn (array $c): array => [
            'id' => (int) $c['product']->id,
            'sku' => (string) $c['product']->sku,
            // stan po naprawie — --restore cofa kartę tylko wtedy, gdy od naprawy nikt nie zmienił jej kodu
            'written_sku' => $c['sku'],
        ], $changes);
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return "nie można utworzyć katalogu {$dir}";
        }
        try {
            $json = json_encode(
                ['label' => self::BACKUP_LABEL, 'created_at' => now()->toIso8601String(), 'products' => $entries],
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            if (file_put_contents($path, $json) === false) {
                return 'zapis pliku nie powiódł się';
            }
            // kontrola przed pierwszą zmianą: kopia musi dać się odczytać w całości
            $read = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $e->getMessage();
        }

        return count($read['products'] ?? []) === count($changes) ? null : 'kopia jest niekompletna';
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
        if (($data['label'] ?? null) !== self::BACKUP_LABEL) {
            $this->error('Nie przywrócono: to nie jest kopia z products:repair-uvex-model-codes.');

            return self::FAILURE;
        }
        $restored = 0;
        $skipped = [];
        foreach ((array) ($data['products'] ?? []) as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            $reason = $id > 0 && isset($entry['sku'], $entry['written_sku'])
                ? DB::transaction(static function () use ($id, $entry): ?string {
                    $product = Product::query()->lockForUpdate()->find($id);
                    // compare-and-set: kartę zmienioną od naprawy (człowiek, synchronizacja) zostawiamy, jak jest
                    if ($product === null) {
                        return 'karta nie istnieje';
                    }
                    if ($product->sku !== $entry['written_sku']) {
                        return 'kod karty zmienił się od naprawy';
                    }
                    if (self::skuTakenBy((string) $entry['sku'], $id) !== null) {
                        return 'dawny kod '.$entry['sku'].' ma teraz inna karta';
                    }
                    // przez model, jak przy naprawie — indeks tekstowy i wektor wracają razem z kodem
                    $product->update(['sku' => (string) $entry['sku']]);

                    return null;
                })
                : 'niepełny wpis kopii';
            if ($reason === null) {
                $restored++;
            } else {
                $skipped[] = '#'.$id.' ('.$reason.')';
            }
        }
        $this->info("Przywrócono {$restored} kart z kopii {$path}.");
        if ($skipped !== []) {
            $this->warn('Pominięte: '.implode('; ', $skipped));
        }

        return self::SUCCESS;
    }
}
