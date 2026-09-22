<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PriceList;
use App\Models\Product;
use App\Support\BhpAttributeNormalizer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Przelicza enrichment_payload.attributes z opisu, nazwy i norm (BhpAttributeNormalizer::forProduct), bez modelu.
 *
 * Domyślnie tylko podgląd różnic — dotąd polecenie szło po całym katalogu i zapisywało od razu, bez kopii i przez
 * saveQuietly (indeks tekstowy, ppe_family i wektor zostawały stare). Zapis wymaga --apply: przed pierwszą zmianą
 * powstaje kopia zapasowa atrybutów zmienianych kart, a zapis idzie przez model — hak Product::saving przelicza
 * search_blob i ppe_family, Product::updated zleca reindeks wektora. --restore przywraca atrybuty z kopii tylko
 * kartom, których atrybuty są wciąż takie, jak zostawił je przebieg (wzorzec: RepairB2bNamesCommand).
 */
final class BackfillBhpAttributesCommand extends Command
{
    private const BACKUP_LABEL = 'backfill-bhp-attributes';

    /** Tyle kart z różnicami pokazuje tabela podglądu; liczby per pole liczą wszystkie. */
    private const PREVIEW_ROWS = 40;

    protected $signature = 'products:backfill-bhp-attributes
                            {--force : Przelicz też karty, które mają już użyteczne attributes}
                            {--limit=0 : Maksymalna liczba zmienianych kart (0 = bez limitu)}
                            {--manufacturer= : Tylko karty tego producenta (bez rozróżniania wielkości liter)}
                            {--price-list= : Tylko karty z tego cennika (numer)}
                            {--id=* : Zawęź do tych kart}
                            {--backup= : Plik kopii zapasowej JSON (domyślnie storage/app/repair-backups)}
                            {--restore= : Przywróć attributes z kopii zapasowej i zakończ}
                            {--apply : Zapisz zmiany (bez tej flagi tylko podgląd różnic)}
                            {--report : Tylko raport pokrycia, bez przeliczania}';

    protected $description = 'Przelicza enrichment_payload.attributes z opisu/nazwy/norm (bez AI); podgląd różnic bez --apply, kopia zapasowa i --restore';

    public function handle(BhpAttributeNormalizer $normalizer): int
    {
        $restore = trim((string) $this->option('restore'));
        if ($restore !== '') {
            return $this->restore($restore);
        }

        $query = $this->scopedQuery();
        if ($query === null) {
            return self::FAILURE;
        }

        if ((bool) $this->option('report')) {
            return $this->report($query);
        }

        $force = (bool) $this->option('force');
        $limit = max(0, (int) $this->option('limit'));
        $total = 0;
        $skipped = 0;
        /** @var list<array{product: Product, old: array<string, mixed>|null, new: array<string, mixed>, fields: list<string>}> $changes */
        $changes = [];
        /** @var array<string, int> $fieldCounts */
        $fieldCounts = [];

        $query->orderBy('id')->chunkById(100, function ($products) use (
            $normalizer, $force, $limit, &$total, &$skipped, &$changes, &$fieldCounts,
        ): bool {
            foreach ($products as $product) {
                /** @var Product $product */
                $total++;
                $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
                $old = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : null;
                if ($this->hasUsefulAttributes($old) && ! $force) {
                    $skipped++;

                    continue;
                }
                $new = $normalizer->forProduct($product);
                $fields = $this->changedFields($old, $new);
                if ($fields === []) {
                    continue;
                }
                if ($limit > 0 && count($changes) >= $limit) {
                    return false;
                }
                foreach ($fields as $field) {
                    $fieldCounts[$field] = ($fieldCounts[$field] ?? 0) + 1;
                }
                $changes[] = ['product' => $product, 'old' => $old, 'new' => $new, 'fields' => $fields];
            }

            return true;
        });

        $this->line("Sprawdzone karty: {$total}, pominięte z atrybutami (bez --force): {$skipped}, do zmiany: ".count($changes));
        if ($changes === []) {
            $this->info('Nic do zmiany.');

            return self::SUCCESS;
        }
        arsort($fieldCounts);
        $this->table(['Pole', 'Kart ze zmianą'], array_map(
            static fn (string $f, int $n): array => [$f, $n],
            array_keys($fieldCounts),
            array_values($fieldCounts),
        ));
        $this->table(
            ['ID', 'SKU', 'Nazwa', 'Zmiany (teraz → po)'],
            array_map(fn (array $c): array => [
                (int) $c['product']->id,
                (string) $c['product']->sku,
                mb_substr((string) $c['product']->name, 0, 40),
                $this->describeChanges($c['old'], $c['new'], $c['fields']),
            ], array_slice($changes, 0, self::PREVIEW_ROWS)),
        );
        if (count($changes) > self::PREVIEW_ROWS) {
            $this->line('… i '.(count($changes) - self::PREVIEW_ROWS).' kolejnych kart.');
        }

        if (! $this->option('apply')) {
            $this->info('Podgląd — uruchom z --apply, żeby zapisać (przed zapisem powstanie kopia zapasowa).');

            return self::SUCCESS;
        }

        $backup = trim((string) $this->option('backup'));
        if ($backup === '') {
            $backup = storage_path('app/repair-backups/bhp-attributes-'.now()->format('Ymd-His').'.json');
        }
        $error = $this->writeBackup($backup, $changes);
        if ($error !== null) {
            $this->error("Kopia zapasowa nie powstała ({$error}) — nic nie zmieniam.");

            return self::FAILURE;
        }
        foreach ($changes as $change) {
            $product = $change['product'];
            $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
            $payload['attributes'] = $change['new'];
            $product->enrichment_payload = $payload;
            // przez model, nie saveQuietly: hak saving przelicza search_blob i ppe_family, updated zleca reindeks wektora
            $product->save();
        }
        $this->info('Zapisano atrybuty '.count($changes).' kart. Kopia zapasowa: '.$backup);
        $this->line("Przywrócenie stanu sprzed: --restore=\"{$backup}\"");

        return self::SUCCESS;
    }

    /** Zapytanie zawężone opcjami; null (z komunikatem), gdy cennika nie ma. */
    private function scopedQuery(): ?Builder
    {
        $query = Product::query();
        $manufacturer = trim((string) $this->option('manufacturer'));
        if ($manufacturer !== '') {
            $query->whereRaw('LOWER(TRIM(manufacturer)) = ?', [mb_strtolower($manufacturer)]);
        }
        $priceListId = (int) $this->option('price-list');
        if ($priceListId > 0) {
            $priceList = PriceList::query()->find($priceListId);
            if ($priceList === null) {
                $this->error("Nie ma cennika numer {$priceListId}.");

                return null;
            }
            $listIds = array_values(array_unique(array_map('intval', $priceList->product_ids ?? [])));
            if ($listIds === []) {
                $this->error('Ten cennik nie ma zapisanych produktów (stary import).');

                return null;
            }
            $query->whereIn('id', $listIds);
        }
        $onlyIds = array_values(array_filter(array_map('intval', (array) $this->option('id'))));
        if ($onlyIds !== []) {
            $query->whereIn('id', $onlyIds);
        }

        return $query;
    }

    private function report(Builder $query): int
    {
        $total = 0;
        $withUseful = 0;
        $query->orderBy('id')->chunkById(100, function ($products) use (&$total, &$withUseful): void {
            foreach ($products as $product) {
                $total++;
                $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
                if ($this->hasUsefulAttributes(is_array($payload['attributes'] ?? null) ? $payload['attributes'] : null)) {
                    $withUseful++;
                }
            }
        });
        $this->info("Produkty: {$total}, z użytecznymi attributes: {$withUseful}, brak: ".($total - $withUseful));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>  $new
     * @return list<string>
     */
    private function changedFields(?array $old, array $new): array
    {
        $old ??= [];
        $fields = [];
        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $field) {
            if (($old[$field] ?? null) !== ($new[$field] ?? null)) {
                $fields[] = (string) $field;
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>  $new
     * @param  list<string>  $fields
     */
    private function describeChanges(?array $old, array $new, array $fields): string
    {
        $show = static function (mixed $v): string {
            if ($v === null) {
                return '—';
            }
            $s = is_array($v) ? implode(', ', array_map('strval', $v)) : (string) $v;

            return mb_substr($s, 0, 30);
        };

        return implode('; ', array_map(
            static fn (string $f): string => $f.': '.$show($old[$f] ?? null).' → '.$show($new[$f] ?? null),
            $fields,
        ));
    }

    /**
     * @param  list<array{product: Product, old: array<string, mixed>|null, new: array<string, mixed>, fields: list<string>}>  $changes
     * @return string|null błąd albo null, gdy kopia zapisana i odczytana w całości
     */
    private function writeBackup(string $path, array $changes): ?string
    {
        $entries = array_map(static fn (array $c): array => [
            'id' => (int) $c['product']->id,
            'sku' => (string) $c['product']->sku,
            // null = karta nie miała attributes; --restore zdejmuje wtedy klucz
            'attributes' => $c['old'],
            // stan po przebiegu — --restore cofa kartę tylko wtedy, gdy od przebiegu nikt jej atrybutów nie zmienił
            'written_attributes' => $c['new'],
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
            $this->error('Nie przywrócono: to nie jest kopia z products:backfill-bhp-attributes.');

            return self::FAILURE;
        }
        $restored = 0;
        $skipped = [];
        foreach ((array) ($data['products'] ?? []) as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            // compare-and-set: kartę, której atrybuty zmieniły się od przebiegu (wzbogacanie, człowiek), zostawiamy
            $done = $id > 0 && array_key_exists('attributes', $entry) && array_key_exists('written_attributes', $entry)
                && DB::transaction(static function () use ($id, $entry): bool {
                    $product = Product::query()->lockForUpdate()->find($id);
                    if ($product === null) {
                        return false;
                    }
                    $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
                    $current = is_array($payload['attributes'] ?? null) ? $payload['attributes'] : null;
                    if ($current != $entry['written_attributes']) {
                        return false;
                    }
                    if (is_array($entry['attributes'])) {
                        $payload['attributes'] = $entry['attributes'];
                    } else {
                        unset($payload['attributes']);
                    }
                    $product->enrichment_payload = $payload;
                    // przez model, jak przy zapisie — indeks tekstowy i wektor wracają razem z atrybutami
                    $product->save();

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
            $this->warn('Pominięte (atrybuty zmieniły się od przebiegu albo karta nie istnieje): '.implode('; ', $skipped));
        }

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>|null  $attrs */
    private function hasUsefulAttributes(?array $attrs): bool
    {
        if ($attrs === null) {
            return false;
        }

        // Obuwie bez klasy ochrony nie jest uzupełnione, choćby miało materiał i normy: stary parser gubił
        // „S3L” czy „S1 PL”, a przebieg bez --force uznawał taką kartę za gotową i nigdy jej nie poprawiał.
        if (($attrs['kategoria_bhp'] ?? null) === 'obuwie' && ($attrs['klasa_ochrony'] ?? null) === null) {
            return false;
        }

        return ($attrs['material'] ?? null) !== null
            || ($attrs['kategoria_bhp'] ?? null) !== null
            || (is_array($attrs['normy_en'] ?? null) && $attrs['normy_en'] !== []);
    }
}
