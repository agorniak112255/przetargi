<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\B2bAccount;
use App\Models\B2bProductLink;
use App\Models\Product;
use App\Models\ProductAccessory;
use App\Models\ProductDocument;
use App\Models\ProductEnrichmentCache;
use App\Models\ProductImage;
use App\Models\ProductSourcePrice;
use App\Services\B2b\B2bDescriptionSource;
use App\Services\Catalog\CardOwnership;
use App\Services\Pricing\ProductEffectivePrice;
use App\Support\BhpAttributeNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Pozostałości po cenniku z pliku producenta na kartach, które prowadzi już konto B2B tego producenta (decyzja
 * użytkownika 28.09.2026, Bolle: 207 kart z importu EMEA z 12.09.2026). price-lists:collapse przepiął plik do wpisu
 * konta w Cennikach, więc karta ma dwa sloty ceny — konta i starego pliku — a obok dane z wzbogacania AI zebrane
 * z obcych sklepów (cechy, materiały, normy, certyfikaty), choć opis przyszedł już z B2B. Te normy bywały cudze:
 * BAXCSP miał od AI „EN 166, EN 169”, a karta techniczna producenta podaje EN166 - EN172.
 *
 * Na karcie, którą prowadzi konto producenta jej marki (CardOwnership) i która ma slot pliku z wpisu tego konta:
 * - slot pliku znika tylko wtedy, gdy cenę karty i tak ustala konto (ProductEffectivePrice::explain) — cena się
 *   nie zmienia, a karta nie zostaje z ceną bez źródła; razem z nim ilość w opakowaniu i opakowanie (B2B ich nie
 *   podaje, piszą je tylko import pliku i wzbogacanie);
 * - dane AI znikają tylko przy opisie z B2B (B2bDescriptionSource — ta sama reguła, która chroni taki opis przed
 *   zbiorczym AI): enrichment_payload bez kluczy wzbogacania (zostają ślady synchronizacji i scalania rozmiarów),
 *   kolumna norm, stan wzbogacania „none”, pamięć AI po SKU (inaczej następne wzbogacenie odtworzyłoby stare dane
 *   z from_cache) i akcesoria z wzbogacania. Atrybuty BHP liczone od nowa, już bez danych AI.
 * Zdjęcia i dokumenty z sieci zostają — podgląd tylko je liczy (część kart nie ma innych zdjęć).
 * Historia cen i wpis w Cennikach bez zmian.
 *
 * Domyślnie podgląd. --apply zapisuje kopię (kolumny karty, slot, pamięć AI, akcesoria i stan po zmianie), potem
 * zmienia karty przez model — hak Product::saving przelicza indeks tekstowy, updated zleca reindeks wektora.
 * --restore przywraca kartę tylko wtedy, gdy od zmiany nikt jej nie ruszył.
 */
final class B2bClearFileLegacyCommand extends Command
{
    protected $signature = 'b2b:clear-file-legacy
        {--account= : Konto B2B producenta (id), wymagane poza --restore}
        {--apply : Zapisz zmiany (bez tej flagi tylko podgląd)}
        {--limit=25 : Ile kart pokazać w tabeli podglądu (0 = wszystkie)}
        {--backup= : Plik kopii przy --apply (domyślnie storage/app/repair-backups/clear-file-legacy-<konto>-<data>.json)}
        {--restore= : Przywróć karty z pliku kopii i zakończ}';

    protected $description = 'Usuwa pozostałości cennika z pliku (slot ceny, opakowanie, dane z wzbogacania AI) na kartach prowadzonych przez konto B2B producenta';

    /**
     * Klucze enrichment_payload, które nie pochodzą z wzbogacania: ślad nadpisania opisu przez synchronizację,
     * źródła opisu z B2B i scalone rozmiary. `attributes` liczymy od nowa. Reszta to wynik wzbogacania.
     */
    private const KEEP_PAYLOAD_KEYS = [
        'replaced_description',
        'replaced_description_at',
        'replaced_description_hash',
        'b2b_sources',
        'merged_size_skus',
    ];

    /** Kolumny karty w kopii — te, które polecenie zmienia (cena wraca przez ProductEffectivePrice::refresh). */
    private const PRODUCT_COLUMNS = [
        'pack_qty', 'packaging', 'norms', 'enrichment_status', 'enriched_at', 'enrichment_error', 'enrichment_trace',
        'enrichment_payload',
    ];

    public function handle(
        CardOwnership $ownership,
        ProductEffectivePrice $prices,
        B2bDescriptionSource $b2bDescriptions,
        BhpAttributeNormalizer $normalizer,
    ): int {
        $restore = trim((string) $this->option('restore'));
        if ($restore !== '') {
            return $this->restore($restore, $prices);
        }

        $account = B2bAccount::query()->find((int) $this->option('account'));
        if ($account === null) {
            $this->error('Podaj --account=<id konta B2B>.');

            return self::FAILURE;
        }
        if ($account->last_price_list_id === null) {
            $this->info(sprintf('Konto #%d %s nie ma wpisu w Cennikach — nie ma czego sprzątać.', $account->id, $account->username));

            return self::SUCCESS;
        }

        $plans = $this->plans($account, $ownership, $prices, $b2bDescriptions, $normalizer);
        $this->report($account, $plans);
        $changing = array_values(array_filter($plans, static fn (array $p): bool => $p['drop_slot'] || $p['drop_ai']));
        if ($changing === []) {
            $this->info('Nic do zmiany.');

            return self::SUCCESS;
        }
        if (! $this->option('apply')) {
            $this->info('Podgląd — uruchom z --apply, żeby zapisać (przed zapisem powstanie kopia zapasowa).');

            return self::SUCCESS;
        }

        return $this->apply($account, $changing, $prices);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function plans(
        B2bAccount $account,
        CardOwnership $ownership,
        ProductEffectivePrice $prices,
        B2bDescriptionSource $b2bDescriptions,
        BhpAttributeNormalizer $normalizer,
    ): array {
        $linked = B2bProductLink::query()
            ->where('b2b_account_id', $account->id)
            ->whereNotNull('product_id')
            ->distinct()
            ->pluck('product_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $withFileSlot = ProductSourcePrice::query()
            ->whereIn('product_id', $linked)
            ->where('source_key', ProductSourcePrice::SOURCE_FILE)
            ->where('price_list_id', $account->last_price_list_id)
            ->pluck('product_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        if ($withFileSlot === []) {
            return [];
        }
        $fromB2b = $b2bDescriptions->productIds($withFileSlot);
        $accountKey = ProductSourcePrice::b2bKey((int) $account->id);

        $plans = [];
        foreach (Product::query()->whereIn('id', $withFileSlot)->orderBy('id')->get() as $product) {
            /** @var Product $product */
            $plan = ['product' => $product, 'drop_slot' => false, 'drop_ai' => false, 'notes' => []];
            if (! $ownership->isOwnerAccount($product, $account)) {
                $plan['notes'][] = 'konto nie jest producentem marki karty';
                $plans[] = $plan;

                continue;
            }

            $winner = $prices->explain($product)['winner'];
            if ($winner !== null && (string) $winner->source_key === $accountKey) {
                $plan['drop_slot'] = true;
            } else {
                $plan['notes'][] = 'cenę ustala '.($winner === null ? 'nic (brak zwycięskiego slotu)' : (string) $winner->source_key).' — slot pliku zostaje';
            }

            $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
            $removedKeys = array_values(array_diff(array_keys($payload), [...self::KEEP_PAYLOAD_KEYS, 'attributes']));
            if (in_array($product->enrichment_status, [Product::ENRICHMENT_QUEUED, Product::ENRICHMENT_RUNNING], true)) {
                // zadanie w kolejce i tak zapisze wynik wzbogacania — sprzątanie przed nim nic by nie dało
                $plan['notes'][] = 'wzbogacanie w toku — dane AI zostają';
            } elseif (isset($fromB2b[(int) $product->id])) {
                $hasAi = $removedKeys !== [] || trim((string) $product->norms) !== ''
                    || ! in_array($product->enrichment_status, [Product::ENRICHMENT_NONE, null], true);
                $plan['drop_ai'] = $hasAi;
            } else {
                $plan['notes'][] = 'opis nie jest z B2B — dane AI zostają';
            }
            $plan['removed_keys'] = $plan['drop_ai'] ? $removedKeys : [];
            $plan['after'] = $this->afterState($product, $plan, $normalizer);
            $plans[] = $plan;
        }

        return $plans;
    }

    /**
     * Kolumny karty po zmianie (bez zapisu).
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    private function afterState(Product $product, array $plan, BhpAttributeNormalizer $normalizer): array
    {
        $after = [];
        if ($plan['drop_slot']) {
            $after['pack_qty'] = null;
            $after['packaging'] = null;
        }
        if ($plan['drop_ai']) {
            $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
            $kept = array_intersect_key($payload, array_flip(self::KEEP_PAYLOAD_KEYS));
            // atrybuty z samej karty: bez starych atrybutów (niosą normy AI w normy_en) i bez kolumny norm z AI
            $probe = $product->replicate();
            $probe->enrichment_payload = $kept === [] ? null : $kept;
            $probe->norms = null;
            $kept['attributes'] = $normalizer->forProduct($probe);
            $after += [
                'norms' => null,
                'enrichment_status' => Product::ENRICHMENT_NONE,
                'enriched_at' => null,
                'enrichment_error' => null,
                'enrichment_trace' => null,
                'enrichment_payload' => $kept,
            ];
        }

        return $after;
    }

    /**
     * @param  list<array<string, mixed>>  $plans
     */
    private function report(B2bAccount $account, array $plans): void
    {
        $this->info(sprintf('Konto #%d %s, wpis w Cennikach #%d — kart ze slotem pliku z tego wpisu: %d',
            $account->id, $account->username, $account->last_price_list_id, count($plans)));
        if ($plans === []) {
            return;
        }

        $ids = array_map(static fn (array $p): int => (int) $p['product']->id, $plans);
        $aiIds = array_map(static fn (array $p): int => (int) $p['product']->id, array_filter($plans, static fn (array $p): bool => $p['drop_ai']));
        $keys = [];
        $kindBefore = [];
        $kindAfter = [];
        foreach ($plans as $plan) {
            foreach ($plan['removed_keys'] ?? [] as $key) {
                $keys[$key] = ($keys[$key] ?? 0) + 1;
            }
            if ($plan['drop_ai']) {
                $before = $plan['product']->enrichment_payload['attributes']['kategoria_bhp'] ?? null;
                $after = $plan['after']['enrichment_payload']['attributes']['kategoria_bhp'] ?? null;
                $kindBefore[$before ?? '—'] = ($kindBefore[$before ?? '—'] ?? 0) + 1;
                $kindAfter[$after ?? '—'] = ($kindAfter[$after ?? '—'] ?? 0) + 1;
            }
        }
        arsort($keys);

        $count = static fn (callable $filter): int => count(array_filter($plans, $filter));
        $this->table(['Zmiana', 'Kart'], [
            ['slot ceny z pliku do usunięcia (cenę i tak ustala konto)', $count(static fn (array $p): bool => $p['drop_slot'])],
            ['… w tym z ilością w opakowaniu / opakowaniem', $count(static fn (array $p): bool => $p['drop_slot'] && ($p['product']->pack_qty !== null || trim((string) $p['product']->packaging) !== ''))],
            ['dane z wzbogacania AI do usunięcia (opis z B2B)', count($aiIds)],
            ['… w tym z kolumną norm', $count(static fn (array $p): bool => $p['drop_ai'] && trim((string) $p['product']->norms) !== '')],
            ['pamięć AI po SKU do usunięcia (wiersze)', $this->cacheRows($plans)->count()],
            ['akcesoria z wzbogacania do usunięcia (wiersze)', ProductAccessory::query()->whereIn('product_id', $aiIds)->where('source', ProductAccessory::SOURCE_ENRICHMENT)->count()],
            ['bez zmian (patrz uwagi)', $count(static fn (array $p): bool => ! $p['drop_slot'] && ! $p['drop_ai'])],
        ]);
        if ($keys !== []) {
            $this->line('Usuwane klucze enrichment_payload: '.implode(', ', array_map(static fn (string $k, int $n): string => "{$k} ({$n})", array_keys($keys), $keys)));
        }
        if ($kindBefore !== []) {
            $this->line('Kategoria BHP w atrybutach teraz: '.json_encode($kindBefore, JSON_UNESCAPED_UNICODE).' → po: '.json_encode($kindAfter, JSON_UNESCAPED_UNICODE));
        }
        $webImages = ProductImage::query()->whereIn('product_id', $ids)->whereNull('b2b_account_id')->where('source_url', '!=', '')->count();
        $webDocuments = ProductDocument::query()->whereIn('product_id', $ids)->whereNull('b2b_account_id')->where('source_url', '!=', '')->count();
        $this->line("Zostają (nie ruszam): zdjęcia z sieci {$webImages}, dokumenty z sieci {$webDocuments}.");

        $limit = max(0, (int) $this->option('limit'));
        $rows = $limit > 0 ? array_slice($plans, 0, $limit) : $plans;
        $this->table(
            ['ID', 'SKU', 'Slot pliku', 'Opakowanie', 'Normy (kolumna)', 'Stan', 'Uwagi'],
            array_map(static function (array $plan): array {
                $product = $plan['product'];

                return [
                    (int) $product->id,
                    (string) $product->sku,
                    $plan['drop_slot'] ? 'usunąć' : 'zostaje',
                    $plan['drop_slot'] ? trim(($product->pack_qty ?? '—').' / '.($product->packaging ?? '—')).' → —' : '',
                    $plan['drop_ai'] ? mb_substr((string) ($product->norms ?? '—'), 0, 40).' → —' : '',
                    $plan['drop_ai'] ? (string) $product->enrichment_status.' → none' : (string) $product->enrichment_status,
                    implode('; ', $plan['notes']),
                ];
            }, $rows),
        );
        if (count($plans) > count($rows)) {
            $this->line('… i '.(count($plans) - count($rows)).' kolejnych kart (--limit=0 pokazuje wszystkie).');
        }
    }

    /**
     * Wiersze pamięci AI po SKU dla kart, z których znikają dane AI.
     *
     * @param  list<array<string, mixed>>  $plans
     * @return Collection<int, ProductEnrichmentCache>
     */
    private function cacheRows(array $plans): Collection
    {
        $out = collect();
        foreach ($plans as $plan) {
            if ($plan['drop_ai']) {
                $out = $out->merge($this->cacheRowsFor($plan['product']));
            }
        }

        return $out;
    }

    /**
     * @return Collection<int, ProductEnrichmentCache>
     */
    private function cacheRowsFor(Product $product): Collection
    {
        $key = ProductEnrichmentCache::normalizeKey((string) $product->manufacturer, (string) $product->sku);

        return ProductEnrichmentCache::query()->where('manufacturer', $key['manufacturer'])->where('sku', $key['sku'])->get();
    }

    /**
     * @param  list<array<string, mixed>>  $plans
     */
    private function apply(B2bAccount $account, array $plans, ProductEffectivePrice $prices): int
    {
        $path = trim((string) $this->option('backup'))
            ?: storage_path('app/repair-backups/clear-file-legacy-'.$account->id.'-'.now()->format('Ymd-His').'.json');
        $entries = [];
        foreach ($plans as $plan) {
            $product = $plan['product'];
            $entries[] = [
                'product_id' => (int) $product->id,
                'sku' => (string) $product->sku,
                'columns' => $this->columns($product),
                'after' => $plan['after'],
                'file_slot' => $plan['drop_slot']
                    ? ProductSourcePrice::query()->where('product_id', $product->id)->where('source_key', ProductSourcePrice::SOURCE_FILE)->toBase()->first()
                    : null,
                'caches' => $plan['drop_ai'] ? $this->cacheRowsFor($product)->map(static fn (ProductEnrichmentCache $c): array => $c->getAttributes())->values()->all() : [],
                'accessories' => $plan['drop_ai'] ? ProductAccessory::query()->where('product_id', $product->id)
                    ->where('source', ProductAccessory::SOURCE_ENRICHMENT)->toBase()->get()->all() : [],
            ];
        }
        $error = $this->writeJson($path, [
            'account_id' => (int) $account->id,
            'created_at' => now()->toIso8601String(),
            'entries' => $entries,
        ], count($entries));
        if ($error !== null) {
            $this->error("Kopia zapasowa nie powstała ({$error}) — nic nie zmieniam.");

            return self::FAILURE;
        }

        $changed = 0;
        $skipped = 0;
        foreach ($plans as $plan) {
            $done = DB::transaction(function () use ($plan, $prices): bool {
                /** @var Product $product */
                $product = $plan['product'];
                // karta zmieniona od odczytu (np. równoległa synchronizacja konta) — plan i kopia byłyby nieaktualne
                $current = Product::query()->lockForUpdate()->findOrFail($product->id);
                if ($this->comparable($this->columns($current)) !== $this->comparable($this->columns($product))
                    || (string) $current->description !== (string) $product->description) {
                    return false;
                }
                // najpierw slot: deleteSlot przeładowuje kartę z bazy (refreshLocked), więc zmiany w pamięci przepadłyby
                if ($plan['drop_slot']) {
                    $prices->deleteSlot($product, ProductSourcePrice::SOURCE_FILE);
                }
                if ($plan['drop_ai']) {
                    foreach ($this->cacheRowsFor($product) as $cache) {
                        $cache->delete();
                    }
                    ProductAccessory::query()->where('product_id', $product->id)
                        ->where('source', ProductAccessory::SOURCE_ENRICHMENT)->delete();
                }
                $fresh = Product::query()->findOrFail($product->id);
                $fresh->forceFill($plan['after']);
                // przez model: hak saving przelicza search_blob i ppe_family, updated zleca reindeks wektora
                $fresh->save();

                return true;
            });
            if ($done) {
                $changed++;
            } else {
                $skipped++;
                $this->warn("#{$plan['product']->id} {$plan['product']->sku}: karta zmieniła się od odczytu — pomijam, uruchom polecenie ponownie");
            }
        }

        $this->info("Zmieniono kart: {$changed}".($skipped > 0 ? ", pominięte: {$skipped}" : '').". Kopia zapasowa: {$path}");
        $this->line("Przywrócenie stanu sprzed: --restore=\"{$path}\"");

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function columns(Product $product): array
    {
        $out = [];
        foreach (self::PRODUCT_COLUMNS as $column) {
            $out[$column] = $product->getAttribute($column);
        }
        $out['enriched_at'] = $product->enriched_at?->toIso8601String();

        return $out;
    }

    private function restore(string $path, ProductEffectivePrice $prices): int
    {
        try {
            $data = json_decode((string) @file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->error("Nie odczytano kopii zapasowej {$path}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $restored = 0;
        $skipped = 0;
        foreach ((array) ($data['entries'] ?? []) as $entry) {
            $product = Product::query()->find((int) ($entry['product_id'] ?? 0));
            if ($product === null) {
                $skipped++;

                continue;
            }
            // tylko karta w stanie, w jakim zostawiło ją polecenie — późniejszych zmian nie cofamy na ślepo
            $after = (array) ($entry['after'] ?? []);
            foreach ($after as $column => $value) {
                if ($this->comparable($product->getAttribute($column)) !== $this->comparable($value)) {
                    $this->warn("#{$product->id} {$product->sku}: karta zmieniła się po sprzątaniu ({$column}) — pomijam");
                    $skipped++;

                    continue 2;
                }
            }

            DB::transaction(function () use ($product, $entry, $prices): void {
                $slot = $entry['file_slot'] ?? null;
                if (is_array($slot) && ! ProductSourcePrice::query()->where('product_id', $product->id)
                    ->where('source_key', ProductSourcePrice::SOURCE_FILE)->exists()) {
                    DB::table((new ProductSourcePrice)->getTable())->insertOrIgnore($slot);
                }
                foreach ((array) ($entry['caches'] ?? []) as $row) {
                    DB::table((new ProductEnrichmentCache)->getTable())->insertOrIgnore((array) $row);
                }
                foreach ((array) ($entry['accessories'] ?? []) as $row) {
                    DB::table((new ProductAccessory)->getTable())->insertOrIgnore((array) $row);
                }
                // tylko kolumny, które polecenie zmieniło — reszty karty kopia nie dotyczy
                $fresh = Product::query()->findOrFail($product->id);
                $fresh->forceFill(array_intersect_key((array) ($entry['columns'] ?? []), (array) ($entry['after'] ?? [])));
                $fresh->save();
                $prices->refresh($fresh);
            });
            $restored++;
        }

        $this->info("Przywrócono kart: {$restored}".($skipped > 0 ? ", pominięte: {$skipped}" : '').'.');
        if ($restored > 0) {
            $this->line('Atrybuty BHP wróciły w wersji sprzed sprzątania — po przywróceniu uruchom ponownie products:backfill-bhp-attributes, jeśli karty mają już normy producenta.');
        }

        return self::SUCCESS;
    }

    /** Wartość do porównania bez różnic zapisu (liczba jako tekst, JSON z bazy, daty). */
    private function comparable(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if (is_array($value)) {
            return (string) json_encode($this->sorted($value), JSON_UNESCAPED_UNICODE);
        }

        return $value === null ? '' : (string) $value;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function sorted(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $v): mixed => is_array($v) ? $this->sorted($v) : $v, $value);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeJson(string $path, array $data, int $expected): ?string
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return "nie można utworzyć katalogu {$dir}";
        }
        try {
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (file_put_contents($path, $json) === false) {
                return 'zapis pliku nie powiódł się';
            }
            // kontrola przed pierwszą zmianą: kopia musi dać się odczytać w całości
            $read = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $e->getMessage();
        }

        return count($read['entries'] ?? []) === $expected ? null : 'kopia jest niekompletna';
    }
}
