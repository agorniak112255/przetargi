<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductDescriptionVersion;
use App\Models\ProductImage;
use App\Models\ProductSourceDocument;
use App\Support\ColourWords;
use ErrorException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Porównanie kart z pomiaru „przed” (CSV: Grupa;Karta;SKU;Nazwa;Adres źródła opisu;Zdjęcie;…, np.
 * SUPON_AI_Pomiar_Coba_przed_2026-10-07.csv) ze stanem obecnym — tylko odczyt, po jednej karcie (pamięć 128 MB).
 * Dla każdej karty: obecny adres źródła opisu (ten sam / inny niż w pliku), werdykt tożsamości (z wersji published,
 * zapasowo z payloadu), powód przeglądu, pochodzenie i numer wersji published, pierwsze zdjęcie (to samo / inne niż
 * w pliku) i zgodność zbioru jego kolorów ze zbiorem kolorów w nazwie karty (ColourWords::sameSet — karta dwubarwna
 * ze zdjęciem jednobarwnym to kolor sprzeczny), liczba zapisanych źródeł (product_source_documents). Podsumowanie
 * per grupa z pliku — kryteria pomiaru „po” z planu etapu 2 (§4 krok 6). --out: plik CSV, brakujący katalog powstaje.
 * Czyta też plik audytu opisów (id;sku;nazwa;problem;waga;szczegóły;link — etap 3): grupa = problem (auditRows).
 */
final class CompareCardsCommand extends Command
{
    protected $signature = 'products:compare-cards
                            {--csv= : Plik CSV pomiaru „przed” (;) — kolumny Grupa, Karta, SKU, Nazwa, Adres źródła opisu, Zdjęcie; albo plik audytu: id, sku, nazwa, problem}
                            {--out= : Plik CSV z porównaniem każdej karty}';

    protected $description = 'Karty z pomiaru „przed” a stan obecny: źródło opisu, werdykt, powód przeglądu, wersja, zdjęcie i kolor, liczba źródeł (niczego nie zmienia)';

    public function handle(): int
    {
        $rows = $this->rowsFromCsv();
        if ($rows === null) {
            return self::FAILURE;
        }

        $results = [];
        // plik audytu ma kartę w kilku wierszach (po jednym na problem) — ta sama karta z tym samym „przed” liczona raz
        $compared = [];
        foreach ($rows as $i => $row) {
            $result = $compared[$row['id']."\n".($row['url'] ?? '')."\n".($row['image'] ?? '')] ??= $this->compare($row);
            $results[] = $row + ['result' => $result];
            $this->line(sprintf(
                '[%d/%d] %s %s → źródło %s · werdykt %s · przegląd %s · wersja %s · zdjęcie %s (kolor %s) · źródeł %s',
                $i + 1, count($rows), $row['id'], $row['sku'],
                $result['source_same'], $result['verdict'] ?? '—', $result['review_reason'] ?? '—', $result['version'] ?? '—',
                $result['image_same'], $result['colour_match'], $result['sources'] === null ? '—' : (string) $result['sources'],
            ));
        }

        $this->summary($results);
        $out = trim((string) $this->option('out'));
        if ($out !== '') {
            if (! $this->writeOut($out, $results)) {
                return self::FAILURE;
            }
            $this->info('Zapisano: '.$out);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{group: string, id: string, sku: string, name: string, url: ?string, image: ?string}  $row
     * @return array{
     *     exists: bool, source_url: ?string, source_same: string, verdict: ?string, review_reason: ?string,
     *     version: ?string, origin: ?string, image_url: ?string, image_same: string, image_colour: ?string,
     *     name_colour: ?string, colour_match: string, sources: ?int
     * } image_colour / name_colour — zbiór kolorów kanonicznych rozdzielony „/” („black/blue”), null = bez koloru;
     *   colour_match — „zgodny” tylko przy równych zbiorach, „—” gdy którejś strony nie znamy
     */
    private function compare(array $row): array
    {
        $empty = [
            'exists' => false, 'source_url' => null, 'source_same' => 'karta nie istnieje', 'verdict' => null, 'review_reason' => null,
            'version' => null, 'origin' => null, 'image_url' => null, 'image_same' => '—', 'image_colour' => null,
            'name_colour' => null, 'colour_match' => '—', 'sources' => null,
        ];
        $product = (int) $row['id'] > 0
            ? Product::query()->find((int) $row['id'], ['id', 'sku', 'name', 'manufacturer', 'review_reason', 'enrichment_payload'])
            : null;
        if ($product === null) {
            return $empty;
        }
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $sourceUrl = is_string($payload['primary_source_url'] ?? null) && trim($payload['primary_source_url']) !== ''
            ? trim($payload['primary_source_url'])
            : (is_string(($payload['source_urls'] ?? [])[0] ?? null) ? trim($payload['source_urls'][0]) : null);
        $sourceSame = match (true) {
            $sourceUrl === null && $row['url'] === null => '—',
            $sourceUrl === null => 'brak',
            $row['url'] === null => 'nowy',
            Product::normalizeShopUrl($sourceUrl) === Product::normalizeShopUrl($row['url']) => 'ten sam',
            default => 'inny',
        };

        $published = ProductDescriptionVersion::query()
            ->where('product_id', $product->id)
            ->where('status', ProductDescriptionVersion::STATUS_PUBLISHED)
            ->orderByDesc('id')
            ->first(['id', 'origin', 'identity_verdict', 'review_reason']);
        $payloadIdentity = is_array($payload['identity'] ?? null) ? $payload['identity'] : [];
        $verdict = $published?->identity_verdict ?? (is_string($payloadIdentity['verdict'] ?? null) ? $payloadIdentity['verdict'] : null);

        $image = ProductImage::query()
            ->where('product_id', $product->id)
            ->orderByDesc('is_primary')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first(['id', 'source_url', 'path']);
        $imageUrl = $image !== null ? trim((string) ($image->source_url ?? '')) : '';
        $imageUrl = $imageUrl !== '' ? $imageUrl : null;
        $imageSame = match (true) {
            $image === null && $row['image'] === null => '—',
            $image === null => 'brak',
            $row['image'] === null => 'nowe',
            $imageUrl !== null && $imageUrl === $row['image'] => 'to samo',
            default => 'inne',
        };
        // zbiory kolorów, nie pierwsze słowo: „COBAwash Czarny/Niebieski” ze zdjęciem „…-black.jpg” to kolor sprzeczny
        $imageColours = $imageUrl !== null ? ColourWords::allInUrl($imageUrl) : [];
        $nameColours = ColourWords::allInName((string) $product->name);
        $colourMatch = $imageColours !== [] && $nameColours !== []
            ? (ColourWords::sameSet($imageColours, $nameColours) ? 'zgodny' : 'sprzeczny')
            : '—';

        return [
            'exists' => true,
            'source_url' => $sourceUrl,
            'source_same' => $sourceSame,
            'verdict' => is_string($verdict) ? $verdict : null,
            'review_reason' => is_string($product->review_reason) ? $product->review_reason : null,
            'version' => $published !== null ? $published->origin.' #'.$published->id : null,
            'origin' => $published?->origin,
            'image_url' => $imageUrl,
            'image_same' => $imageSame,
            'image_colour' => $imageColours !== [] ? implode('/', $imageColours) : null,
            'name_colour' => $nameColours !== [] ? implode('/', $nameColours) : null,
            'colour_match' => $colourMatch,
            'sources' => ProductSourceDocument::query()->where('product_id', $product->id)->count(),
        ];
    }

    /**
     * @return list<array{group: string, id: string, sku: string, name: string, url: ?string, image: ?string}>|null
     */
    private function rowsFromCsv(): ?array
    {
        $path = trim((string) $this->option('csv'));
        if ($path === '') {
            $this->error('Podaj --csv=<plik> z pomiarem „przed”.');

            return null;
        }
        $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        if ($lines === false || $lines === []) {
            $this->error("Nie ma pliku albo jest pusty: {$path}");

            return null;
        }
        $header = array_map(static fn (string $h): string => trim($h), str_getcsv((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) array_shift($lines)), ';'));
        $col = array_flip($header);
        if (isset($col['id'], $col['problem'])) {
            return $this->auditRows($col, $lines);
        }
        foreach (['Karta', 'SKU', 'Nazwa', 'Adres źródła opisu'] as $required) {
            if (! isset($col[$required])) {
                $this->error("Brak kolumny „{$required}” w nagłówku pliku.");

                return null;
            }
        }

        $rows = [];
        foreach ($lines as $line) {
            $cells = str_getcsv($line, ';');
            $get = static fn (string $name): string => isset($col[$name]) ? trim((string) ($cells[$col[$name]] ?? '')) : '';
            $rows[] = [
                'group' => $get('Grupa') !== '' ? $get('Grupa') : '(bez grupy)',
                'id' => $get('Karta'),
                'sku' => $get('SKU'),
                'name' => $get('Nazwa'),
                'url' => $get('Adres źródła opisu') !== '' ? $get('Adres źródła opisu') : null,
                'image' => $get('Zdjęcie') !== '' ? $get('Zdjęcie') : null,
            ];
        }

        return $rows;
    }

    /**
     * Plik audytu opisów (SUPON_AI_Audyt_<marka>_2026-10-08.csv: id;sku;nazwa;problem;waga;szczegóły;link, u UVEX
     * dodatkowo „część”): wiersz = jeden problem jednej karty, grupa = problem (karta z kilkoma problemami jest
     * w kilku grupach). Audyt nie zapisuje adresu źródła ani zdjęcia — porównanie pokazuje tylko stan obecny
     * (adres „—”/„nowy”, zdjęcie „—”/„nowe”).
     *
     * @param  array<string, int>  $col  nagłówek => numer kolumny
     * @param  list<string>  $lines
     * @return list<array{group: string, id: string, sku: string, name: string, url: ?string, image: ?string}>|null
     */
    private function auditRows(array $col, array $lines): ?array
    {
        foreach (['sku', 'nazwa'] as $required) {
            if (! isset($col[$required])) {
                $this->error("Brak kolumny „{$required}” w nagłówku pliku audytu.");

                return null;
            }
        }

        $rows = [];
        foreach ($lines as $line) {
            $cells = str_getcsv($line, ';');
            $get = static fn (string $name): string => isset($col[$name]) ? trim((string) ($cells[$col[$name]] ?? '')) : '';
            $rows[] = [
                'group' => $get('problem') !== '' ? $get('problem') : '(bez problemu)',
                'id' => $get('id'),
                'sku' => $get('sku'),
                'name' => $get('nazwa'),
                'url' => null,
                'image' => null,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function summary(array $results): void
    {
        $groups = [];
        foreach ($results as $row) {
            $r = $row['result'];
            $key = (string) $row['group'];
            $groups[$key] ??= ['cards' => 0, 'missing' => 0, 'same' => 0, 'other' => 0, 'no_source' => 0, 'hard' => 0, 'soft' => 0,
                'none' => 0, 'unknown' => 0, 'review' => 0, 'model_shared' => 0, 'image_conflict' => 0, 'no_docs' => 0];
            $groups[$key]['cards']++;
            if (! $r['exists']) {
                $groups[$key]['missing']++;

                continue;
            }
            $sourceBucket = match ($r['source_same']) {
                'ten sam' => 'same',
                'inny', 'nowy' => 'other',
                default => 'no_source',
            };
            $groups[$key][$sourceBucket]++;
            $verdictBucket = in_array($r['verdict'], ['hard', 'soft', 'none'], true) ? $r['verdict'] : 'unknown';
            $groups[$key][$verdictBucket]++;
            $groups[$key]['review'] += $r['review_reason'] !== null ? 1 : 0;
            $groups[$key]['model_shared'] += $r['origin'] === ProductDescriptionVersion::ORIGIN_MODEL_SHARED ? 1 : 0;
            $groups[$key]['image_conflict'] += $r['colour_match'] === 'sprzeczny' ? 1 : 0;
            $groups[$key]['no_docs'] += ($r['sources'] ?? 0) === 0 ? 1 : 0;
        }
        ksort($groups);
        $table = [];
        foreach ($groups as $group => $g) {
            $table[] = [
                $group, $g['cards'], $g['missing'], $g['same'], $g['other'], $g['no_source'], $g['hard'], $g['soft'], $g['none'], $g['unknown'],
                $g['review'], $g['model_shared'], $g['image_conflict'], $g['no_docs'],
            ];
        }
        $this->newLine();
        $this->table([
            'Grupa', 'Kart', 'Brak karty', 'Ten sam adres', 'Inny adres', 'Bez źródła', 'hard', 'soft', 'none', '?',
            'Do przeglądu', 'Opis modelu', 'Zdjęcie sprzeczne', 'Bez zapisanych źródeł',
        ], $table);
    }

    /**
     * Plik CSV z porównaniem; brakujący katalog powstaje (plan: storage/app/reports/). false = nie dało się zapisać
     * (komunikat już wypisany) — polecenie kończy się błędem, nie „Zapisano”.
     *
     * @param  list<array<string, mixed>>  $results
     */
    private function writeOut(string $path, array $results): bool
    {
        try {
            File::ensureDirectoryExists(dirname($path));
            $handle = fopen($path, 'wb');
        } catch (ErrorException) {
            // ostrzeżenie mkdir/fopen (ścieżka przez plik, brak uprawnień) Laravel zamienia w wyjątek
            $handle = false;
        }
        if ($handle === false) {
            $this->error("Nie da się zapisać pliku: {$path}");

            return false;
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, [
            'Grupa', 'Karta', 'SKU', 'Nazwa', 'Adres źródła przed', 'Adres źródła teraz', 'Adres', 'Werdykt', 'Powód przeglądu',
            'Wersja', 'Zdjęcie przed', 'Zdjęcie teraz', 'Zdjęcie', 'Kolor zdjęcia', 'Kolor w nazwie', 'Kolor', 'Źródeł',
        ], ';');
        foreach ($results as $row) {
            $r = $row['result'];
            fputcsv($handle, [
                $row['group'], $row['id'], $row['sku'], $row['name'], (string) ($row['url'] ?? ''), (string) ($r['source_url'] ?? ''), $r['source_same'],
                (string) ($r['verdict'] ?? ''), (string) ($r['review_reason'] ?? ''), (string) ($r['version'] ?? ''),
                (string) ($row['image'] ?? ''), (string) ($r['image_url'] ?? ''), $r['image_same'],
                (string) ($r['image_colour'] ?? ''), (string) ($r['name_colour'] ?? ''), $r['colour_match'],
                $r['sources'] === null ? '' : (string) $r['sources'],
            ], ';');
        }
        fclose($handle);

        return true;
    }
}
