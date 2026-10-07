<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PriceList;
use App\Models\Product;
use App\Services\PriceListCards;
use App\Support\ProductNormsColumn;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use JsonException;
use SplFileObject;

/**
 * Odtwarza kolumnę products.norms skasowaną przez ponowny import cennika z pliku (B1, plan 07.10.2026: pozycja
 * cennika z 'norms' => null szła wprost do update karty). Lista norm opisu została w enrichment_payload.norms —
 * kolumna wraca z niej tą samą regułą co przy zapisie opisu (ProductNormsColumn, jak writeNormsColumn), bez modelu.
 *
 * Zmieniana jest tylko karta z pustą kolumną i niepustą listą w payloadzie; niepusta kolumna (np. normy z opisu B2B
 * albo z Presty) zostaje. Domyślnie podgląd; zapis z --apply po kopii zapasowej (JSON z jedną kartą w wierszu:
 * stara i wpisana wartość kolumny), cofnięcie przez --restore.
 *
 * Zapis idzie przez pełny model, świadomie: Product::saving przelicza search_blob (normy wracają do wyszukiwarki),
 * Product::updated zleca reindeks wektora — ten też stracił normy przy imporcie. Kosztem jest jedno zadanie
 * reindeksu na każdą odtworzoną kartę.
 */
final class RestoreNormsColumnCommand extends Command
{
    private const BACKUP_LABEL = 'restore-norms-column';

    /** Przegląd porcjami z samymi potrzebnymi kolumnami — CLI na serwerze ma 128 MB. */
    private const CHUNK = 500;

    /** Tyle kart pokazuje podgląd; liczby liczą wszystkie. */
    private const PREVIEW_ROWS = 10;

    protected $signature = 'products:restore-norms-column
                            {--price-list= : Tylko karty tego cennika (numer z Cenników; ostatni import i karty ze slotem ceny z pliku)}
                            {--manufacturer= : Tylko karty tego producenta (bez rozróżniania wielkości liter)}
                            {--limit=0 : Maksymalna liczba zmienianych kart (0 = bez limitu)}
                            {--backup= : Plik kopii zapasowej (domyślnie storage/app/repair-backups/restore-norms-column-<data>.json)}
                            {--restore= : Cofnij zapis z kopii zapasowej i zakończ}
                            {--apply : Zapisz zmiany (bez tej flagi tylko podgląd)}';

    protected $description = 'Kolumna norm kart: odtworzenie z enrichment_payload.norms tam, gdzie import cennika ją wyczyścił; podgląd bez --apply, kopia zapasowa i --restore';

    public function handle(): int
    {
        $restore = trim((string) $this->option('restore'));
        if ($restore !== '') {
            return $this->restore($restore);
        }

        $query = $this->scopedQuery();
        if ($query === null) {
            return self::FAILURE;
        }

        $limit = max(0, (int) $this->option('limit'));
        $checked = 0;
        /** @var list<array{id: int, sku: string, old: string|null, new: string}> $changes */
        $changes = [];

        // z payloadu tylko lista norm (JSON_EXTRACT działa w MariaDB i SQLite) — cały payload z opisami zastąpionymi
        // i źródłami B2B to dziesiątki KB na kartę, a porcja ma 500 kart
        $query
            ->select(['id', 'sku', 'norms'])
            ->selectRaw("JSON_EXTRACT(enrichment_payload, '$.norms') AS payload_norms")
            ->chunkById(self::CHUNK, function ($products) use ($limit, &$checked, &$changes): bool {
                foreach ($products as $product) {
                    /** @var Product $product */
                    $checked++;
                    $raw = $product->getAttribute('payload_norms');
                    $new = is_string($raw) ? ProductNormsColumn::fromList(json_decode($raw, true)) : null;
                    if ($new === null) {
                        continue;
                    }
                    if ($limit > 0 && count($changes) >= $limit) {
                        return false;
                    }
                    $changes[] = [
                        'id' => (int) $product->id,
                        'sku' => (string) $product->sku,
                        'old' => $product->getRawOriginal('norms'),
                        'new' => $new,
                    ];
                }

                return true;
            });

        $this->line("Karty z pustą kolumną norm i danymi opisu: {$checked}, do odtworzenia z listy norm opisu: ".count($changes));
        if ($changes === []) {
            $this->info('Nic do zmiany.');

            return self::SUCCESS;
        }
        foreach (array_slice($changes, 0, self::PREVIEW_ROWS) as $change) {
            $this->line(sprintf('  %s: „%s” → „%s”', $change['sku'] !== '' ? $change['sku'] : '#'.$change['id'],
                (string) $change['old'], mb_substr($change['new'], 0, 120)));
        }
        if (count($changes) > self::PREVIEW_ROWS) {
            $this->line('  … i '.(count($changes) - self::PREVIEW_ROWS).' kolejnych kart.');
        }

        if (! $this->option('apply')) {
            $this->info('Podgląd — uruchom z --apply, żeby zapisać (przed zapisem powstanie kopia zapasowa).');

            return self::SUCCESS;
        }

        $backup = trim((string) $this->option('backup'));
        if ($backup === '') {
            $backup = storage_path('app/repair-backups/'.self::BACKUP_LABEL.'-'.now()->format('Ymd-His').'.json');
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
            // pełny model (nie select z przeglądu): hak saving buduje search_blob ze wszystkich kolumn źródłowych
            foreach (Product::query()->whereKey(array_keys($byId))->get() as $product) {
                $change = $byId[(int) $product->id];
                // od przeglądu kolumnę ktoś zapisał albo opis się zmienił (wzbogacanie w tle) — świeższy stan zostaje
                if (trim((string) $product->norms) !== ''
                    || self::columnFromPayload($product->enrichment_payload) !== $change['new']) {
                    $skipped[] = $change['sku'] !== '' ? $change['sku'] : '#'.$change['id'];

                    continue;
                }
                $product->norms = $change['new'];
                $product->save();
                $written++;
            }
        }
        $this->info("Odtworzono kolumnę norm {$written} kart. Kopia zapasowa: {$backup}");
        if ($skipped !== []) {
            $this->warn('Pominięte (karta zmieniła się od przeglądu): '.implode('; ', array_slice($skipped, 0, 50)));
        }
        $this->line("Cofnięcie: --restore=\"{$backup}\"");

        return self::SUCCESS;
    }

    /** Wartość kolumny z listy norm opisu; null, gdy listy nie ma albo jest pusta. */
    private static function columnFromPayload(mixed $payload): ?string
    {
        return is_array($payload) ? ProductNormsColumn::fromList($payload['norms'] ?? null) : null;
    }

    /** Karty z pustą kolumną norm i zapisanym payloadem, zawężone opcjami; null (z komunikatem), gdy cennika nie ma. */
    private function scopedQuery(): ?Builder
    {
        $query = Product::query()
            ->whereNotNull('enrichment_payload')
            ->whereRaw("TRIM(COALESCE(norms, '')) = ''");
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
            // ostatni import i karty ze slotem ceny z tego cennika — sam product_ids gubi karty z wcześniejszych wgrań
            $listIds = app(PriceListCards::class)->ids($priceList);
            if ($listIds === []) {
                $this->error('Ten cennik nie ma zapisanych produktów (stary import).');

                return null;
            }
            $query->whereIntegerInRaw('id', $listIds);
        }

        return $query;
    }

    /**
     * Kopia kolumny norm zmienianych kart, karta w wierszu — plik jest poprawnym JSON-em, a zapis i kontrola idą
     * wiersz po wierszu.
     *
     * @param  list<array{id: int, sku: string, old: string|null, new: string}>  $changes
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
        try {
            $header = json_encode(['label' => self::BACKUP_LABEL, 'created_at' => now()->toIso8601String()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            fwrite($handle, substr($header, 0, -1).",\"products\":[\n");
            foreach ($changes as $i => $change) {
                $line = json_encode([
                    'id' => $change['id'],
                    'sku' => $change['sku'],
                    'norms' => $change['old'],
                    // stan po przebiegu — --restore cofa kartę tylko, gdy od przebiegu nikt kolumny nie zmienił
                    'written_norms' => $change['new'],
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                fwrite($handle, ($i === 0 ? '' : ",\n").$line);
            }
            fwrite($handle, "\n]}\n");
        } catch (JsonException $e) {
            return $e->getMessage();
        } finally {
            fclose($handle);
        }

        // kontrola przed pierwszą zmianą: każda karta musi dać się odczytać z kopii
        $expected = array_flip(array_column($changes, 'id'));
        $seen = 0;
        try {
            foreach ($this->backupEntries($path) as $entry) {
                if (isset($expected[(int) ($entry['id'] ?? 0)]) && is_string($entry['written_norms'] ?? null)) {
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
            $this->error('Nie przywrócono: to nie jest kopia z products:restore-norms-column.');

            return self::FAILURE;
        }
        $restored = 0;
        $skipped = [];
        try {
            foreach ($this->backupEntries($path) as $entry) {
                $id = (int) ($entry['id'] ?? 0);
                $done = $id > 0 && is_string($entry['written_norms'] ?? null)
                    && DB::transaction(static function () use ($id, $entry): bool {
                        $product = Product::query()->lockForUpdate()->find($id);
                        if ($product === null || $product->norms !== $entry['written_norms']) {
                            return false;
                        }
                        $old = $entry['norms'] ?? null;
                        $product->norms = is_string($old) ? $old : null;
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
            $this->warn('Pominięte (kolumna zmieniła się od przebiegu albo karta nie istnieje): '.implode('; ', array_slice($skipped, 0, 50)));
        }

        return self::SUCCESS;
    }
}
