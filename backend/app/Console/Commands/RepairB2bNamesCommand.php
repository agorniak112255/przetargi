<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bProductLink;
use App\Models\PriceList;
use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Pierwszy ręczny import cennika ARTRA (17.09.2026) wziął kolumnę typu wyrobu za nazwę, a numer
 * wiersza za kategorię: 7 kart (26552–26558, np. SKU „ARYA 300 671460 S1 PL”) ma nazwę „sandały”
 * albo „półbuty” i kategorię „144”…„158”. Heurystykę mapowania już poprawiono, ale karty zostały:
 * ponowny import nie ruszy nazwy karty z powiązaniem B2B (PriceListImportService — decyzja
 * użytkownika 15.09.2026), a synchronizacja B2B pisze nazwę tylko na nową kartę i kategorię tylko
 * na pustą (B2bCatalogSync).
 *
 * Polecenie przywraca nazwę z powiązania B2B (b2b_product_links.remote_name) kartom, których nazwa
 * to jedno słowo typu wyrobu powtarzające się w co najmniej 20% kart cennika. Kategorii nie
 * zgadujemy: kategoria-liczba zmienia się tylko na wartość podaną w --category, bez niej polecenie
 * wypisuje kategorie pozostałych kart cennika. Zapis idzie przez model, więc hak Product::saving
 * przelicza indeks tekstowy, a Product::updated zleca reindeks wektora.
 *
 * Domyślnie tylko podgląd — zapis wymaga --apply. Przed zapisem powstaje kopia zapasowa (nazwa
 * i kategoria sprzed naprawy oraz wartości wpisane przez naprawę), --restore przywraca stan sprzed naprawy
 * tylko kartom, których nazwa i kategoria są wciąż takie, jak zostawiła je naprawa — poprawki zrobione
 * później (człowiek, import) zostają.
 */
final class RepairB2bNamesCommand extends Command
{
    /** Słowo typu musi się powtarzać w takiej części kart cennika — pojedyncza dziwna nazwa to nie błąd importu. */
    private const TYPE_WORD_SHARE = 0.2;

    protected $signature = 'products:repair-b2b-names
                            {--price-list= : Numer cennika, którego karty sprawdzić}
                            {--category= : Kategoria dla kart, których kategoria jest samą liczbą (bez tej opcji tylko wypis)}
                            {--id=* : Zawęź do tych kart (np. inna kategoria dla sandałów, inna dla półbutów)}
                            {--backup= : Plik kopii zapasowej JSON (domyślnie storage/app/repair-backups)}
                            {--restore= : Przywróć nazwę i kategorię z kopii zapasowej i zakończ}
                            {--apply : Zapisz zmiany (bez tej flagi tylko podgląd)}';

    protected $description = 'Przywraca nazwę z powiązania B2B kartom cennika z nazwą-typem wyrobu („sandały”); kategoria-liczba tylko z --category (podgląd bez --apply)';

    public function handle(): int
    {
        $restore = trim((string) $this->option('restore'));
        if ($restore !== '') {
            return $this->restore($restore);
        }

        $priceListId = (int) $this->option('price-list');
        $priceList = $priceListId > 0 ? PriceList::query()->find($priceListId) : null;
        if ($priceList === null) {
            $this->error($priceListId > 0 ? "Nie ma cennika numer {$priceListId}." : 'Podaj numer cennika, np. --price-list=8.');

            return self::FAILURE;
        }
        $listIds = array_values(array_unique(array_map('intval', $priceList->product_ids ?? [])));
        if ($listIds === []) {
            $this->error('Ten cennik nie ma zapisanych produktów (stary import).');

            return self::FAILURE;
        }

        /** @var Collection<int, Product> $products */
        $products = Product::query()->whereIn('id', $listIds)->orderBy('id')->get();
        $typeWords = $this->typeWords($products);
        $onlyIds = array_values(array_filter(array_map('intval', (array) $this->option('id'))));
        $category = trim((string) $this->option('category'));

        $candidates = $products->filter(function (Product $p) use ($typeWords, $onlyIds): bool {
            if ($onlyIds !== [] && ! in_array((int) $p->id, $onlyIds, true)) {
                return false;
            }

            return isset($typeWords[$this->word((string) $p->name)]) || $this->isNumericCategory($p);
        })->values();

        if ($candidates->isEmpty()) {
            $this->info('Karty tego cennika nie mają nazwy-typu wyrobu ani kategorii-liczby — nic do naprawy.');

            return self::SUCCESS;
        }

        $links = B2bProductLink::query()
            ->whereIn('product_id', $candidates->pluck('id')->all())
            ->orderBy('id')
            ->get()
            ->groupBy('product_id');

        /** @var list<array{product: Product, name: ?string, category: ?string, note: string}> $changes */
        $changes = [];
        foreach ($candidates as $product) {
            $name = null;
            $notes = [];
            if (isset($typeWords[$this->word((string) $product->name)])) {
                $link = $this->pickLink($product, $links->get($product->id, collect()));
                if ($link === null) {
                    $notes[] = 'brak powiązania B2B z nazwą — nazwa zostaje';
                } elseif (trim((string) $link->remote_name) !== trim((string) $product->name)) {
                    $name = trim((string) $link->remote_name);
                    $count = $links->get($product->id, collect())->count();
                    if ($count > 1) {
                        $notes[] = "{$count} powiązania — wybrane #{$link->id} (konto {$link->b2b_account_id}, kod {$link->remote_sku})";
                    }
                }
            }
            $newCategory = null;
            if ($this->isNumericCategory($product)) {
                if ($category !== '') {
                    $newCategory = $category;
                } else {
                    $notes[] = 'kategoria-liczba — podaj --category';
                }
            }
            if ($name === null && $newCategory === null && $notes === []) {
                continue;
            }
            $changes[] = ['product' => $product, 'name' => $name, 'category' => $newCategory, 'note' => implode('; ', $notes)];
        }

        $this->line('Słowa typu (≥'.(int) (self::TYPE_WORD_SHARE * 100).'% kart cennika): '
            .($typeWords === [] ? '—' : implode(', ', array_map(
                static fn (string $w, int $n): string => "{$w} ({$n})",
                array_keys($typeWords),
                array_values($typeWords),
            ))));
        $this->table(
            ['ID', 'SKU', 'Nazwa teraz', 'Nazwa z B2B', 'Kategoria teraz', 'Kategoria nowa', 'Opis AI', 'Uwagi'],
            array_map(static fn (array $c): array => [
                (int) $c['product']->id,
                (string) $c['product']->sku,
                mb_substr((string) $c['product']->name, 0, 40),
                $c['name'] !== null ? mb_substr($c['name'], 0, 50) : '(bez zmian)',
                mb_substr((string) $c['product']->category, 0, 30),
                $c['category'] !== null ? mb_substr($c['category'], 0, 40) : '(bez zmian)',
                is_array($c['product']->enrichment_payload) && $c['product']->enrichment_payload !== [] ? 'jest' : 'brak',
                $c['note'],
            ], $changes),
        );

        if ($category === '' && $candidates->contains(fn (Product $p): bool => $this->isNumericCategory($p))) {
            $this->line('Kategorie pozostałych kart cennika (do wyboru w --category, niczego nie zgaduję):');
            $histogram = $products
                ->reject(fn (Product $p): bool => $this->isNumericCategory($p) || trim((string) $p->category) === '')
                ->countBy(static fn (Product $p): string => trim((string) $p->category))
                ->sortDesc();
            $this->table(['Kategoria', 'Kart'], $histogram->map(static fn (int $n, string $c): array => [$c, $n])->values()->all());
            $this->line('Karty różnego typu (np. sandały i półbuty) potrzebują różnych kategorii — zawęź przebieg przez --id=.');
        }

        $toSave = array_values(array_filter($changes, static fn (array $c): bool => $c['name'] !== null || $c['category'] !== null));
        $withoutPayload = array_filter(
            $toSave,
            static fn (array $c): bool => ! is_array($c['product']->enrichment_payload) || $c['product']->enrichment_payload === [],
        );
        if ($withoutPayload !== []) {
            $this->warn(sprintf(
                'Karty bez danych wzbogacania: %d (%s) — po poprawie nazwy wymagają opisu (etap opisu z karty PDF / wzbogacanie).',
                count($withoutPayload),
                implode(', ', array_map(static fn (array $c): int => (int) $c['product']->id, $withoutPayload)),
            ));
        }

        if ($toSave === []) {
            $this->info('Nic do zapisania.');

            return self::SUCCESS;
        }
        $names = count(array_filter($toSave, static fn (array $c): bool => $c['name'] !== null));
        $categories = count(array_filter($toSave, static fn (array $c): bool => $c['category'] !== null));
        if (! $this->option('apply')) {
            $this->info("Do poprawy: {$names} nazw, {$categories} kategorii. Podgląd — uruchom z --apply, żeby zapisać.");

            return self::SUCCESS;
        }

        $backup = trim((string) $this->option('backup'));
        if ($backup === '') {
            $backup = storage_path('app/repair-backups/b2b-names-'.$priceList->id.'-'.now()->format('Ymd-His').'.json');
        }
        $error = $this->writeBackup($backup, $toSave);
        if ($error !== null) {
            $this->error("Kopia zapasowa nie powstała ({$error}) — nic nie zmieniam.");

            return self::FAILURE;
        }
        foreach ($toSave as $change) {
            $updates = [];
            if ($change['name'] !== null) {
                $updates['name'] = $change['name'];
            }
            if ($change['category'] !== null) {
                $updates['category'] = $change['category'];
                // kategorię podał człowiek w --category — to wybór ręczny, dowód rodzaju wyrobu, i automat
                // (przepisanie drzewa, import) nie może go po cichu nadpisać
                $updates['category_source'] = Product::CATEGORY_SOURCE_MANUAL;
            }
            // przez model: hak saving przelicza indeks tekstowy, updated zleca reindeks wektora
            $change['product']->update($updates);
        }
        $this->info("Poprawiono {$names} nazw i {$categories} kategorii. Kopia zapasowa: {$backup}");
        $this->line("Przywrócenie stanu sprzed: --restore=\"{$backup}\"");

        return self::SUCCESS;
    }

    /**
     * Słowa, które opisują typ wyrobu w tym cenniku: występują jako osobne słowo w nazwie, kategorii
     * albo typie wyrobu z cennika w co najmniej 20% kart. Liczone są tylko słowa, które są czyjąś
     * całą nazwą — inne nas nie interesują.
     *
     * @param  Collection<int, Product>  $products
     * @return array<string, int> słowo => liczba kart
     */
    private function typeWords(Collection $products): array
    {
        $singles = [];
        foreach ($products as $p) {
            $word = $this->word((string) $p->name);
            if ($word !== '') {
                $singles[$word] = 0;
            }
        }
        if ($singles === []) {
            return [];
        }
        foreach ($products as $p) {
            $attributes = is_array($p->price_list_attributes) ? $p->price_list_attributes : [];
            $text = mb_strtolower(implode(' ', [(string) $p->name, (string) $p->category, (string) ($attributes['typ_wyrobu'] ?? '')]));
            $tokens = array_flip(preg_split('/[^\p{L}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: []);
            foreach (array_keys($singles) as $word) {
                if (isset($tokens[$word])) {
                    $singles[$word]++;
                }
            }
        }
        $threshold = self::TYPE_WORD_SHARE * $products->count();

        return array_filter($singles, static fn (int $n): bool => $n >= $threshold);
    }

    /** Cała nazwa jako jedno słowo z samych liter (małymi), inaczej ''. */
    private function word(string $name): string
    {
        $name = mb_strtolower(trim($name));

        return preg_match('/^\p{L}+$/u', $name) === 1 ? $name : '';
    }

    private function isNumericCategory(Product $product): bool
    {
        return preg_match('/^\d+$/', trim((string) $product->category)) === 1;
    }

    /**
     * Powiązanie z niepustą nazwą; przy kilku — najpierw to z kodem równym kodowi karty, potem
     * najświeżej widziane, potem najstarsze. Ten sam wynik przy każdym przebiegu.
     *
     * @param  Collection<int, B2bProductLink>  $links
     */
    private function pickLink(Product $product, Collection $links): ?B2bProductLink
    {
        $sku = mb_strtolower(trim((string) $product->sku));

        return $links
            ->filter(static fn (B2bProductLink $l): bool => trim((string) $l->remote_name) !== '')
            ->sort(static function (B2bProductLink $a, B2bProductLink $b) use ($sku): int {
                $skuA = mb_strtolower(trim((string) $a->remote_sku)) === $sku ? 0 : 1;
                $skuB = mb_strtolower(trim((string) $b->remote_sku)) === $sku ? 0 : 1;

                return [$skuA, -($a->last_seen_at?->getTimestamp() ?? 0), (int) $a->id]
                    <=> [$skuB, -($b->last_seen_at?->getTimestamp() ?? 0), (int) $b->id];
            })
            ->first();
    }

    /**
     * @param  list<array{product: Product, name: ?string, category: ?string, note: string}>  $changes
     * @return string|null błąd albo null, gdy kopia zapisana i odczytana w całości
     */
    private function writeBackup(string $path, array $changes): ?string
    {
        $entries = array_map(static fn (array $c): array => [
            'id' => (int) $c['product']->id,
            'sku' => (string) $c['product']->sku,
            'name' => $c['product']->name,
            'category' => $c['product']->category,
            // pochodzenie kategorii sprzed naprawy — --restore oddaje je razem z kategorią
            'category_source' => $c['product']->category_source,
            // stan po naprawie — --restore cofa kartę tylko wtedy, gdy od naprawy nikt jej nie zmienił
            'written_name' => $c['name'] ?? $c['product']->name,
            'written_category' => $c['category'] ?? $c['product']->category,
        ], $changes);
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return "nie można utworzyć katalogu {$dir}";
        }
        try {
            $json = json_encode(
                ['label' => 'repair-b2b-names', 'created_at' => now()->toIso8601String(), 'products' => $entries],
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
        if (($data['label'] ?? null) !== 'repair-b2b-names') {
            $this->error('Nie przywrócono: to nie jest kopia z products:repair-b2b-names.');

            return self::FAILURE;
        }
        $restored = 0;
        $skipped = [];
        foreach ((array) ($data['products'] ?? []) as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            // compare-and-set: kartę zmienioną od naprawy (człowiek, import) zostawiamy, jak jest
            $done = $id > 0 && array_key_exists('written_name', $entry) && array_key_exists('written_category', $entry)
                && DB::transaction(static function () use ($id, $entry): bool {
                    $product = Product::query()->lockForUpdate()->find($id);
                    if ($product === null || $product->name !== $entry['written_name'] || $product->category !== $entry['written_category']) {
                        return false;
                    }
                    // przez model, jak przy naprawie — indeks tekstowy i wektor wracają razem z nazwą
                    $restore = ['name' => $entry['name'] ?? $product->name, 'category' => $entry['category'] ?? null];
                    // kopia sprzed znacznika pochodzenia nie ma tego klucza — wtedy pochodzenia nie ruszamy
                    if (array_key_exists('category_source', $entry)) {
                        $restore['category_source'] = $entry['category_source'];
                    }
                    $product->update($restore);

                    return true;
                });
            if ($done) {
                $restored++;
            } else {
                $skipped[] = (string) ($entry['sku'] ?? $id);
            }
        }
        $this->info("Przywrócono {$restored} kart z kopii {$path}.");
        if ($skipped !== []) {
            $this->warn('Pominięte (karta zmieniła się od naprawy albo nie istnieje): '.implode('; ', $skipped));
        }

        return self::SUCCESS;
    }
}
