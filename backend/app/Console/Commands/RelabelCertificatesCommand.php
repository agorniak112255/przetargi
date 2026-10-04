<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductDocument;
use App\Support\CertificateLabels;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use JsonException;
use SplFileObject;

/**
 * Porządkuje enrichment_payload.certificates zapisane przed CertificateLabels (04.10.2026): zdejmuje automatyczne
 * etykiety („Certyfikat producenta” przy każdym pliku, także przy deklaracji opakowania PPWR i czeskiej DEKLARACE),
 * liczy je od nowa z plików certyfikatów pobranych przy wzbogacaniu (product_documents bez konta B2B — tylko te
 * dokładał przebieg wzbogacania) i przepuszcza listę przez filtr (bez „CE”, kategorii ŚOI, samego rozporządzenia).
 *
 * Zmieniana jest tylko karta, której lista ma automatyczną etykietę albo wpis niebędący certyfikatem; opis, atrybuty
 * i reszta payloadu zostają. Domyślnie podgląd; zapis z --apply po kopii całych enrichment_payload zmienianych kart
 * (JSON z jedną kartą w wierszu — kopia i jej kontrola nie trzymają całości w pamięci, CLI na serwerze ma 128 MB).
 * Zapis idzie przez model: Product::saving przelicza search_blob, Product::updated zleca reindeks wektora.
 */
final class RelabelCertificatesCommand extends Command
{
    private const BACKUP_LABEL = 'relabel-certificates';

    private const CHUNK = 200;

    /** Tyle kart pokazuje tabela przykładów; liczby liczą wszystkie. */
    private const PREVIEW_ROWS = 20;

    protected $signature = 'products:relabel-certificates
                            {--manufacturer= : Tylko karty tego producenta (bez rozróżniania wielkości liter)}
                            {--product=* : Tylko te karty (numer karty)}
                            {--limit=0 : Maksymalna liczba zmienianych kart (0 = bez limitu)}
                            {--backup= : Plik kopii zapasowej (domyślnie storage/app/repair-backups/relabel-certificates-<data>.json)}
                            {--restore= : Przywróć listy certyfikatów z kopii zapasowej i zakończ}
                            {--apply : Zapisz zmiany (bez tej flagi tylko podgląd)}';

    protected $description = 'Certyfikaty kart: etykiety plików od nowa (deklaracja ≠ certyfikat), bez CE i kategorii ŚOI; podgląd bez --apply, kopia zapasowa i --restore';

    public function handle(): int
    {
        $restore = trim((string) $this->option('restore'));
        if ($restore !== '') {
            return $this->restore($restore);
        }

        $limit = max(0, (int) $this->option('limit'));
        $checked = 0;
        /** @var list<array{id: int, sku: string, name: string, old: list<string>, new: list<string>}> $changes */
        $changes = [];
        /** @var array<string, int> $removed */
        $removed = [];
        /** @var array<string, int> $added */
        $added = [];

        $this->scopedQuery()
            ->select(['id', 'sku', 'name', 'enrichment_payload'])
            ->chunkById(self::CHUNK, function ($products) use ($limit, &$checked, &$changes, &$removed, &$added): bool {
                /** @var array<int, list<string>> $candidates */
                $candidates = [];
                $meta = [];
                foreach ($products as $product) {
                    /** @var Product $product */
                    $old = self::certificatesOf($product->enrichment_payload);
                    if ($old === []) {
                        continue;
                    }
                    $checked++;
                    if (! self::needsRelabel($old)) {
                        continue;
                    }
                    $candidates[(int) $product->id] = $old;
                    $meta[(int) $product->id] = [(string) $product->sku, mb_substr((string) $product->name, 0, 40)];
                }
                if ($candidates === []) {
                    return true;
                }
                $documents = ProductDocument::query()
                    ->whereIn('product_id', array_keys($candidates))
                    ->where('kind', ProductDocument::KIND_CERTIFICATE)
                    ->whereNull('b2b_account_id')
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->get(['id', 'product_id', 'kind', 'title', 'source_url'])
                    ->groupBy('product_id');
                foreach ($candidates as $id => $old) {
                    $new = CertificateLabels::relabel($old, $documents->get($id, collect())->all());
                    if ($new === $old) {
                        continue;
                    }
                    if ($limit > 0 && count($changes) >= $limit) {
                        return false;
                    }
                    foreach (array_diff($old, $new) as $value) {
                        $removed[$value] = ($removed[$value] ?? 0) + 1;
                    }
                    foreach (array_diff($new, $old) as $value) {
                        $added[$value] = ($added[$value] ?? 0) + 1;
                    }
                    $changes[] = ['id' => $id, 'sku' => $meta[$id][0], 'name' => $meta[$id][1], 'old' => $old, 'new' => $new];
                }

                return true;
            });

        $this->line("Karty z listą certyfikatów: {$checked}, do zmiany: ".count($changes));
        if ($changes === []) {
            $this->info('Nic do zmiany.');

            return self::SUCCESS;
        }
        $this->printCounts('Usuwane wpisy (10 najczęstszych)', $removed);
        $this->printCounts('Dodawane etykiety plików', $added);
        $this->table(
            ['ID', 'SKU', 'Nazwa', 'Teraz', 'Po'],
            array_map(static fn (array $c): array => [
                $c['id'],
                $c['sku'],
                $c['name'],
                mb_substr(implode('; ', $c['old']), 0, 70),
                mb_substr(implode('; ', $c['new']), 0, 70) ?: '—',
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
            $backup = storage_path('app/repair-backups/relabel-certificates-'.now()->format('Ymd-His').'.json');
        }
        $error = $this->writeBackup($backup, $changes);
        if ($error !== null) {
            $this->error("Kopia zapasowa nie powstała ({$error}) — nic nie zmieniam.");

            return self::FAILURE;
        }

        $written = 0;
        $skipped = [];
        foreach (array_chunk($changes, 100) as $chunk) {
            $byId = array_column($chunk, null, 'id');
            // pełny model (nie select z podglądu): hak saving buduje search_blob ze wszystkich kolumn źródłowych
            foreach (Product::query()->whereKey(array_keys($byId))->get() as $product) {
                $change = $byId[(int) $product->id];
                $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
                // lista zmieniła się od podglądu (wzbogacanie w tle) — nie nadpisujemy świeższego stanu
                if (self::certificatesOf($payload) !== $change['old']) {
                    $skipped[] = $change['sku'] !== '' ? $change['sku'] : (string) $change['id'];

                    continue;
                }
                $payload['certificates'] = $change['new'];
                $product->enrichment_payload = $payload;
                $product->save();
                $written++;
            }
        }
        $this->info("Zapisano certyfikaty {$written} kart. Kopia zapasowa: {$backup}");
        if ($skipped !== []) {
            $this->warn('Pominięte (lista zmieniła się od podglądu): '.implode('; ', array_slice($skipped, 0, 50)));
        }
        $this->line("Przywrócenie stanu sprzed: --restore=\"{$backup}\"");

        return self::SUCCESS;
    }

    /** @param  list<string>  $certificates */
    private static function needsRelabel(array $certificates): bool
    {
        foreach ($certificates as $item) {
            if (in_array(trim($item), CertificateLabels::AUTOMATIC, true) || CertificateLabels::isNotCertificate($item)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function certificatesOf(mixed $payload): array
    {
        $list = is_array($payload) && is_array($payload['certificates'] ?? null) ? $payload['certificates'] : [];

        return array_values(array_filter($list, static fn ($v): bool => is_string($v)));
    }

    private function scopedQuery(): Builder
    {
        $query = Product::query()->whereNotNull('enrichment_payload');
        $manufacturer = trim((string) $this->option('manufacturer'));
        if ($manufacturer !== '') {
            $query->whereRaw('LOWER(TRIM(manufacturer)) = ?', [mb_strtolower($manufacturer)]);
        }
        $onlyIds = array_values(array_filter(array_map('intval', (array) $this->option('product'))));
        if ($onlyIds !== []) {
            $query->whereIn('id', $onlyIds);
        }

        return $query;
    }

    /** @param  array<string, int>  $counts */
    private function printCounts(string $title, array $counts): void
    {
        if ($counts === []) {
            return;
        }
        arsort($counts);
        $this->line($title.':');
        $this->table(['Wpis', 'Kart'], array_map(
            static fn (string $value, int $n): array => [mb_substr($value, 0, 80), $n],
            array_keys(array_slice($counts, 0, 10, true)),
            array_values(array_slice($counts, 0, 10, true)),
        ));
    }

    /**
     * Kopia całych enrichment_payload zmienianych kart, karta w wierszu — plik jest poprawnym JSON-em, a zapis
     * i kontrola idą wiersz po wierszu, bez całej kopii w pamięci.
     *
     * @param  list<array{id: int, sku: string, name: string, old: list<string>, new: list<string>}>  $changes
     * @return string|null błąd albo null, gdy kopia zapisana i odczytana w całości
     */
    private function writeBackup(string $path, array $changes): ?string
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return "nie można utworzyć katalogu {$dir}";
        }
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            return 'nie można otworzyć pliku';
        }
        /** @var array<int, true> $expected */
        $expected = [];
        try {
            $header = json_encode(['label' => self::BACKUP_LABEL, 'created_at' => now()->toIso8601String()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            fwrite($handle, substr($header, 0, -1).",\"products\":[\n");
            $first = true;
            foreach (array_chunk($changes, 100) as $chunk) {
                $byId = array_column($chunk, null, 'id');
                foreach (Product::query()->whereKey(array_keys($byId))->orderBy('id')->get(['id', 'sku', 'enrichment_payload']) as $product) {
                    $line = json_encode([
                        'id' => (int) $product->id,
                        'sku' => (string) $product->sku,
                        'enrichment_payload' => $product->getRawOriginal('enrichment_payload'),
                        // stan po przebiegu — --restore cofa kartę tylko, gdy od przebiegu nikt listy nie zmienił
                        'written_certificates' => $byId[(int) $product->id]['new'],
                    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    fwrite($handle, ($first ? '' : ",\n").$line);
                    $first = false;
                    $expected[(int) $product->id] = true;
                }
            }
            fwrite($handle, "\n]}\n");
        } catch (JsonException $e) {
            return $e->getMessage();
        } finally {
            fclose($handle);
        }

        // kontrola przed pierwszą zmianą: każda karta musi dać się odczytać z kopii
        $seen = 0;
        try {
            foreach ($this->backupEntries($path) as $entry) {
                if (isset($expected[(int) ($entry['id'] ?? 0)]) && is_string($entry['enrichment_payload'] ?? null)) {
                    $seen++;
                }
            }
        } catch (JsonException $e) {
            return $e->getMessage();
        }

        return $seen === count($changes) ? null : 'kopia jest niekompletna';
    }

    /**
     * Wpisy kopii wiersz po wierszu (format z writeBackup).
     *
     * @return iterable<array<string, mixed>>
     *
     * @throws JsonException
     */
    private function backupEntries(string $path): iterable
    {
        $file = new SplFileObject($path, 'rb');
        $lineNo = 0;
        while (! $file->eof()) {
            $line = trim((string) $file->fgets());
            $lineNo++;
            if ($lineNo === 1 || $line === '' || $line === ']}') {
                continue;
            }
            $entry = json_decode(rtrim($line, ','), true, 512, JSON_THROW_ON_ERROR);
            if (is_array($entry)) {
                yield $entry;
            }
        }
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
            $this->error('Nie przywrócono: to nie jest kopia z products:relabel-certificates.');

            return self::FAILURE;
        }
        $restored = 0;
        $skipped = [];
        try {
            foreach ($this->backupEntries($path) as $entry) {
                $id = (int) ($entry['id'] ?? 0);
                $done = $id > 0 && is_string($entry['enrichment_payload'] ?? null) && is_array($entry['written_certificates'] ?? null)
                    && DB::transaction(static function () use ($id, $entry): bool {
                        $product = Product::query()->lockForUpdate()->find($id);
                        if ($product === null) {
                            return false;
                        }
                        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
                        if (self::certificatesOf($payload) !== $entry['written_certificates']) {
                            return false;
                        }
                        $before = json_decode((string) $entry['enrichment_payload'], true);
                        // wraca tylko lista certyfikatów — reszta payloadu mogła się od przebiegu zmienić
                        if (is_array($before) && array_key_exists('certificates', $before)) {
                            $payload['certificates'] = $before['certificates'];
                        } else {
                            unset($payload['certificates']);
                        }
                        $product->enrichment_payload = $payload;
                        $product->save();

                        return true;
                    });
                if ($done) {
                    $restored++;
                } else {
                    $skipped[] = (string) ($entry['sku'] ?? $id);
                }
            }
        } catch (JsonException $e) {
            $this->error("Kopia zapasowa jest uszkodzona: {$e->getMessage()}");

            return self::FAILURE;
        }
        $this->info("Przywrócono {$restored} kart z kopii {$path}.");
        if ($skipped !== []) {
            $this->warn('Pominięte (lista zmieniła się od przebiegu albo karta nie istnieje): '.implode('; ', array_slice($skipped, 0, 50)));
        }

        return self::SUCCESS;
    }
}
