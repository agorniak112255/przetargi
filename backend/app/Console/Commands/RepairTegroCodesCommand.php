<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\PrestaProductMatch;
use App\Models\Product;
use App\Models\ProductIdentifier;
use App\Models\ProductShopCard;
use App\Services\B2b\TegroB2bConnector;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Decyzja właściciela 28.09.2026: kod karty Tegro = kod modelu bez rozmiaru („CITRIN 7” → „CITRIN”), a wyrób w jednym
 * rozmiarze — bez rozmiaru także w nazwie („RĘKAWICE RS ARBEITSSCHUTZ COMFORT PREMIUM 10” → „… COMFORT PREMIUM”; rozmiar
 * zostaje w liście „Rozmiary: 10 (COMFORT PREMIUM 10)”). Łącznik (TegroB2bConnector) robi tak od 28.09.2026 na nowych
 * kartach, ale synchronizacja nie zmienia kodu ani nazwy zastanej karty — to polecenie poprawia zastane karty konta.
 *
 * Rozmiar karty = etykiety kodów Tegro tej karty (product_identifiers source_code konta, variant_label), a bez etykiet —
 * wiersz „Rozmiar” tabelki sklepu tego konta (product_shop_cards, sekcja „Parametry produktu”), gdy to jedna wartość
 * (nie zakres „7-11”). Nic nie zgadujemy z samej nazwy ani kodu:
 * - kod: obecny kod kończy się na „ rozmiar” — nowy kod to reszta, a gdy są kody z etykietą, KAŻDY musi być
 *   „reszta rozmiar” (inaczej kod zostaje);
 * - nazwa: tylko nazwa równa nazwie pozycji z powiązania tego konta (remote_name) i kończąca się na „ rozmiar” — karta
 *   z rozmiarami ma już nazwę modelu i zostaje;
 * - karta z powiązaniem innego konta B2B albo z dopasowaniem PrestaShop — pomijana (kod i nazwa służą tam też innym
 *   źródłom);
 * - nowy kod zajęty przez inną kartę — kod zostaje („kod X zajęty przez #id”), nazwa się zmienia;
 * - dwie karty konta z tym samym nowym kodem (dawny podział rozmiarów według ceny: HEAVY 9/10/11 i HEAVY 8) — obie bez
 *   zmian, najpierw „Scal rozmiary”.
 *
 * Domyślnie podgląd; --apply zapisuje (przez model — hak Product::saving przelicza indeks tekstowy, Product::updated zleca
 * reindeks wektora), karta w osobnej transakcji, po kopii zapasowej JSON (storage/app/repair-backups). --restore oddaje
 * kod i nazwę sprzed naprawy tylko kartom, które mają wciąż kod i nazwę zostawione przez naprawę.
 */
final class RepairTegroCodesCommand extends Command
{
    private const BACKUP_LABEL = 'repair-tegro-codes';

    protected $signature = 'products:repair-tegro-codes
                            {--account= : Numer konta B2B Tegro}
                            {--backup= : Plik kopii zapasowej JSON (domyślnie storage/app/repair-backups)}
                            {--restore= : Przywróć kod i nazwę z kopii zapasowej i zakończ}
                            {--apply : Zapisz zmiany (bez tej flagi tylko podgląd)}';

    protected $description = 'Usuwa rozmiar z kodu kart Tegro (kod modelu) i z nazwy kart w jednym rozmiarze (podgląd bez --apply)';

    public function handle(): int
    {
        $restore = trim((string) $this->option('restore'));
        if ($restore !== '') {
            return $this->restore($restore);
        }

        $accountId = (int) $this->option('account');
        $account = $accountId > 0 ? B2bAccount::query()->find($accountId) : null;
        if ($account === null) {
            $this->error($accountId > 0 ? "Nie ma konta B2B numer {$accountId}." : 'Podaj numer konta Tegro, np. --account=12.');

            return self::FAILURE;
        }
        if ((string) $account->connector !== TegroB2bConnector::key()) {
            $this->error("Konto #{$account->id} nie jest kontem Tegro (łącznik: ".((string) $account->connector ?: 'brak').').');

            return self::FAILURE;
        }

        $plans = $this->plans($account);
        $changes = array_values(array_filter($plans, static fn (array $p): bool => $p['sku'] !== null || $p['name'] !== null));
        $rows = array_values(array_filter($plans, static fn (array $p): bool => $p['sku'] !== null || $p['name'] !== null || $p['notes'] !== []));

        $this->table(
            ['ID', 'Kod teraz', 'Kod nowy', 'Nazwa teraz', 'Nazwa nowa', 'Uwagi'],
            array_map(static fn (array $p): array => [
                (int) $p['product']->id,
                (string) $p['product']->sku,
                $p['sku'] ?? '(bez zmian)',
                mb_substr((string) $p['product']->name, 0, 60),
                $p['name'] !== null ? mb_substr($p['name'], 0, 60) : '(bez zmian)',
                implode('; ', $p['notes']),
            ], $rows),
        );
        $skus = count(array_filter($changes, static fn (array $p): bool => $p['sku'] !== null));
        $names = count(array_filter($changes, static fn (array $p): bool => $p['name'] !== null));
        $this->line(sprintf(
            'Kart konta: %d; do zmiany: %d (kodów %d, nazw %d); z uwagą bez zmian: %d; bez zmian: %d.',
            count($plans),
            count($changes),
            $skus,
            $names,
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
            $backup = storage_path('app/repair-backups/tegro-codes-'.$account->id.'-'.now()->format('Ymd-His').'.json');
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
                if ($product === null || $product->sku !== $change['product']->sku || $product->name !== $change['product']->name) {
                    return 'karta zmieniła się od podglądu';
                }
                if ($change['sku'] !== null && self::skuTakenBy($change['sku'], (int) $product->id) !== null) {
                    return "kod {$change['sku']} zajęty od podglądu";
                }
                if ($change['sku'] !== null) {
                    $product->sku = $change['sku'];
                }
                if ($change['name'] !== null) {
                    $product->name = $change['name'];
                }
                // przez model: hak saving przelicza indeks tekstowy, updated zleca reindeks wektora
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
     * Plan każdej karty konta: nowy kod i nazwa (null = bez zmian) i uwagi.
     *
     * @return list<array{product: Product, sku: ?string, name: ?string, notes: list<string>}>
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
        $identifiers = ProductIdentifier::query()
            ->whereIn('product_id', $productIds)
            ->where('b2b_account_id', $accountId)
            ->where('type', ProductIdentifier::TYPE_SOURCE_CODE)
            ->whereNull('removed_at')
            ->get()
            ->groupBy('product_id');
        $shopCards = ProductShopCard::query()->whereIn('product_id', $productIds)->where('b2b_account_id', $accountId)->get()->keyBy('product_id');

        $plans = [];
        foreach ($products as $product) {
            $id = (int) $product->id;
            /** @var Collection<int, B2bProductLink> $cardLinks */
            $cardLinks = $links->get($id, collect());
            $foreign = $cardLinks->filter(static fn (B2bProductLink $l): bool => (int) $l->b2b_account_id !== $accountId)
                ->pluck('b2b_account_id')->map(static fn (mixed $a): int => (int) $a)->unique()->sort()->values()->all();
            if ($foreign !== []) {
                $plans[] = ['product' => $product, 'sku' => null, 'name' => null, 'notes' => ['pominięta: powiązania innych kont B2B (#'.implode(', #', $foreign).')']];

                continue;
            }
            if (isset($presta[$id])) {
                $plans[] = ['product' => $product, 'sku' => null, 'name' => null, 'notes' => ['pominięta: karta dopasowana do PrestaShop']];

                continue;
            }
            $plans[] = $this->plan(
                $product,
                $identifiers->get($id, collect()),
                $shopCards->get($id),
                $cardLinks->map(static fn (B2bProductLink $l): string => trim((string) $l->remote_name))->filter()->values()->all(),
            );
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
                $plans[$i]['notes'][] = "najpierw Scal rozmiary ({$ids}) — ten sam kod {$plans[$i]['sku']}";
                $plans[$i]['sku'] = null;
                $plans[$i]['name'] = null;
            }
        }
        // kod zajęty przez kartę spoza tej naprawy — kod zostaje, nazwa się zmienia
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
     * @param  Collection<int, ProductIdentifier>  $identifiers  kody Tegro karty (source_code konta, aktywne)
     * @param  list<string>  $remoteNames  nazwy pozycji z powiązań tego konta
     * @return array{product: Product, sku: ?string, name: ?string, notes: list<string>}
     */
    private function plan(Product $product, Collection $identifiers, ?ProductShopCard $shopCard, array $remoteNames): array
    {
        $labelled = $identifiers->filter(static fn (ProductIdentifier $i): bool => trim((string) $i->variant_label) !== '');
        $labels = $labelled->map(static fn (ProductIdentifier $i): string => trim((string) $i->variant_label))->unique()->values()->all();
        $shopSize = $labels === [] ? self::shopSize($shopCard) : null;
        if ($shopSize !== null) {
            $labels = [$shopSize];
        }
        if ($labels === []) {
            return ['product' => $product, 'sku' => null, 'name' => null, 'notes' => []];
        }
        // dłuższa etykieta pierwsza, dalej alfabetycznie — ten sam wynik przy każdym przebiegu
        usort($labels, static fn (string $a, string $b): int => [strlen($b), $a] <=> [strlen($a), $b]);

        $notes = [];
        $sku = trim((string) $product->sku);
        $newSku = null;
        $label = self::endingLabel($sku, $labels);
        $code = $label !== null ? trim(substr($sku, 0, -strlen(' '.$label))) : '';
        if ($code !== '') {
            // każdy kod Tegro karty musi być „kod-modelu rozmiar” (jego etykieta, a bez etykiet — rozmiar z tabelki
            // sklepu); kod bez etykiety obok kodów z etykietą też blokuje — inaczej kod modelu byłby zgadywany
            $expected = static function (ProductIdentifier $i) use ($code, $shopSize): ?string {
                $size = $shopSize ?? trim((string) $i->variant_label);

                return $size !== '' ? $code.' '.$size : null;
            };
            $mismatch = $identifiers->first(static fn (ProductIdentifier $i): bool => trim((string) $i->value) !== $expected($i));
            if ($mismatch !== null) {
                $notes[] = 'kod '.trim((string) $mismatch->value).' nie jest „'.$code.' rozmiar” — kod zostaje';
            } else {
                $newSku = $code;
            }
        }

        $name = trim((string) $product->name);
        $newName = null;
        if (in_array($name, $remoteNames, true)) {
            $nameLabel = self::endingLabel($name, $labels);
            $stripped = $nameLabel !== null ? trim(substr($name, 0, -strlen(' '.$nameLabel))) : '';
            $newName = $stripped !== '' ? $stripped : null;
        }
        if ($shopSize !== null && ($newSku !== null || $newName !== null)) {
            $notes[] = "rozmiar {$shopSize} z wiersza „Rozmiar” tabelki sklepu";
        }

        return ['product' => $product, 'sku' => $newSku, 'name' => $newName, 'notes' => $notes];
    }

    /**
     * Etykieta, na którą (po spacji) kończy się tekst; null = żadna.
     *
     * @param  list<string>  $labels
     */
    private static function endingLabel(string $text, array $labels): ?string
    {
        foreach ($labels as $label) {
            if (str_ends_with($text, ' '.$label)) {
                return $label;
            }
        }

        return null;
    }

    /**
     * Wiersz „Rozmiar” sekcji „Parametry produktu” tabelki sklepu: jedna wartość bez białych znaków, nie zakres ani lista
     * (ta sama reguła co TegroB2bConnector::pageSizeOf).
     */
    private static function shopSize(?ProductShopCard $card): ?string
    {
        $values = [];
        foreach ((array) ($card?->fields ?? []) as $section) {
            if (! is_array($section) || trim((string) ($section['section'] ?? '')) !== 'Parametry produktu') {
                continue;
            }
            foreach ((array) ($section['rows'] ?? []) as $row) {
                if (is_array($row) && mb_strtolower(trim((string) ($row['name'] ?? ''))) === 'rozmiar') {
                    $values[trim((string) ($row['value'] ?? ''))] = true;
                }
            }
        }
        if (count($values) !== 1) {
            return null;
        }
        $size = (string) array_key_first($values);

        return $size !== '' && preg_match('/[\s\-\x{2013}\x{2014},;\/]/u', $size) !== 1 ? $size : null;
    }

    /** Karta (inna niż $exceptId) z tym kodem, bez rozróżniania wielkości liter — products.sku jest unikalne. */
    private static function skuTakenBy(string $sku, int $exceptId): ?int
    {
        $id = Product::query()->whereRaw('LOWER(sku) = ?', [mb_strtolower($sku)])->where('id', '!=', $exceptId)->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * @param  list<array{product: Product, sku: ?string, name: ?string, notes: list<string>}>  $changes
     * @return string|null błąd albo null, gdy kopia zapisana i odczytana w całości
     */
    private function writeBackup(string $path, array $changes): ?string
    {
        $entries = array_map(static fn (array $c): array => [
            'id' => (int) $c['product']->id,
            'sku' => (string) $c['product']->sku,
            'name' => (string) $c['product']->name,
            // stan po naprawie — --restore cofa kartę tylko wtedy, gdy od naprawy nikt jej nie zmienił
            'written_sku' => $c['sku'] ?? (string) $c['product']->sku,
            'written_name' => $c['name'] ?? (string) $c['product']->name,
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
            $this->error('Nie przywrócono: to nie jest kopia z products:repair-tegro-codes.');

            return self::FAILURE;
        }
        $restored = 0;
        $skipped = [];
        foreach ((array) ($data['products'] ?? []) as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            $reason = $id > 0 && isset($entry['sku'], $entry['name'], $entry['written_sku'], $entry['written_name'])
                ? DB::transaction(static function () use ($id, $entry): ?string {
                    $product = Product::query()->lockForUpdate()->find($id);
                    // compare-and-set: kartę zmienioną od naprawy (człowiek, synchronizacja) zostawiamy, jak jest
                    if ($product === null) {
                        return 'karta nie istnieje';
                    }
                    if ($product->sku !== $entry['written_sku'] || $product->name !== $entry['written_name']) {
                        return 'karta zmieniła się od naprawy';
                    }
                    if ($entry['sku'] !== $product->sku && self::skuTakenBy((string) $entry['sku'], $id) !== null) {
                        return 'dawny kod '.$entry['sku'].' ma teraz inna karta';
                    }
                    // przez model, jak przy naprawie — indeks tekstowy i wektor wracają razem z kodem i nazwą
                    $product->update(['sku' => (string) $entry['sku'], 'name' => (string) $entry['name']]);

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
