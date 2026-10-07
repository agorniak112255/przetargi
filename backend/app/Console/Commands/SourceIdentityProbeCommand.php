<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PriceList;
use App\Models\Product;
use App\Services\Enrichment\ManufacturerProfiles;
use App\Services\Enrichment\ProductPageFetcher;
use App\Services\Enrichment\SourceIdentity;
use Illuminate\Console\Command;

/**
 * Kalibracja werdyktu tożsamości źródła (SourceIdentity) — tylko odczyt. Dla każdej karty pobiera stronę jej źródła
 * opisu tym samym pobieraczem co wzbogacanie (pamięć podręczna stron 24 h) i liczy werdykt hard / soft / none;
 * w bazie niczego nie zapisuje.
 *
 * Karty: z pliku --csv (format pomiaru Coby: Grupa;Karta;SKU;Nazwa;Adres źródła opisu; opcjonalnie Producent, Model,
 * EAN, Adres ręczny, Rodzaj źródła — karta powstaje tylko w pamięci, numer z pliku jest numerem z produkcji) albo
 * z bazy: --price-list (karty cennika) / --manufacturer. Źródło karty z bazy = enrichment_payload.primary_source_url
 * (zapasowo pierwszy z source_urls), grupa = rodzaj źródła (primary_source_kind).
 */
final class SourceIdentityProbeCommand extends Command
{
    protected $signature = 'products:source-identity-probe
                            {--csv= : Plik CSV (;) z kartami do sprawdzenia — format pomiaru Coby}
                            {--price-list= : Numer cennika — karty z tego importu}
                            {--manufacturer= : Producent kart z bazy; przy --csv producent kart bez kolumny Producent}
                            {--limit=0 : Najwyżej tyle kart (0 = wszystkie)}
                            {--out= : Plik CSV z werdyktem każdej karty}';

    protected $description = 'Pomiar werdyktu tożsamości źródła opisu (hard/soft/none) na kartach z pliku albo z bazy (niczego nie zmienia)';

    public function __construct(
        private readonly SourceIdentity $identity,
        private readonly ProductPageFetcher $pages,
        private readonly ManufacturerProfiles $profiles,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $rows = trim((string) $this->option('csv')) !== '' ? $this->rowsFromCsv() : $this->rowsFromDatabase();
        if ($rows === null) {
            return self::FAILURE;
        }
        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        $results = [];
        foreach ($rows as $i => $row) {
            $result = $this->judge($row['product'], $row['url'], $row['kind']);
            $results[] = $row + ['result' => $result];
            $this->line(sprintf('[%d/%d] %s %s → %s (%s)', $i + 1, count($rows), $row['id'], (string) $row['product']->sku,
                $result['verdict'] ?? 'null', $result['reason']));
        }

        $this->summary($results);
        $out = trim((string) $this->option('out'));
        if ($out !== '') {
            $this->writeOut($out, $results);
            $this->info('Zapisano: '.$out);
        }

        return self::SUCCESS;
    }

    /**
     * @return array{verdict: ?string, reason: string, key_type: ?string, key: ?string, where: ?string, source_url: ?string, profile: ?string}
     */
    private function judge(Product $product, ?string $url, ?string $kind): array
    {
        $pages = [];
        $note = '';
        if ($url !== null && $kind !== 'manual' && $kind !== 'catalog') {
            $raw = $this->pages->fetchRaw($url);
            $title = $raw !== null ? ($this->pages->pageTitles($raw['html'])[0] ?? '') : '';
            $row = ['url' => $url, 'title' => $title, 'snippet' => ''];
            $fetched = $this->pages->fetch([$row], (string) $product->sku, 1, [], null);
            $pages = $fetched['pages'];
            if ($pages === []) {
                // Bramka pobierania odrzuciła stronę (np. „dłuższy wariant SKU”) — mierzymy sam werdykt, więc stronę
                // czytamy jeszcze raz bez kodu karty i zapisujemy, czemu odpadła.
                $note = ' [pobieranie: '.implode(', ', array_column($fetched['rejected'], 'reason')).']';
                $pages = $this->pages->fetch([$row], '', 1, [], null)['pages'];
            }
        }
        $result = $this->identity->judgeCard($product, $pages, $url, $kind, $this->profiles->for($product)?->catalogs ?? []);
        $result['reason'] .= $note;

        return $result;
    }

    /**
     * @return list<array{group: string, id: string, product: Product, url: ?string, kind: ?string}>|null
     */
    private function rowsFromCsv(): ?array
    {
        $path = trim((string) $this->option('csv'));
        $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        if ($lines === false || $lines === []) {
            $this->error("Nie ma pliku albo jest pusty: {$path}");

            return null;
        }
        $header = array_map(static fn (string $h): string => trim($h), str_getcsv((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) array_shift($lines)), ';'));
        $col = array_flip($header);
        foreach (['Karta', 'SKU', 'Nazwa', 'Adres źródła opisu'] as $required) {
            if (! isset($col[$required])) {
                $this->error("Brak kolumny „{$required}” w nagłówku pliku.");

                return null;
            }
        }
        $defaultManufacturer = trim((string) $this->option('manufacturer'));
        if (! isset($col['Producent']) && $defaultManufacturer === '') {
            $this->error('Plik bez kolumny Producent — podaj --manufacturer=.');

            return null;
        }

        $rows = [];
        foreach ($lines as $line) {
            $cells = str_getcsv($line, ';');
            $get = static fn (string $name): string => isset($col[$name]) ? trim((string) ($cells[$col[$name]] ?? '')) : '';
            $manufacturer = $get('Producent') !== '' ? $get('Producent') : $defaultManufacturer;
            $product = new Product([
                'sku' => $get('SKU'),
                'name' => $get('Nazwa'),
                'manufacturer' => $manufacturer,
                'model_name' => $get('Model') !== '' ? $get('Model') : null,
                'ean' => $get('EAN') !== '' ? $get('EAN') : null,
                'shop_source_url' => $get('Adres ręczny') !== '' ? $get('Adres ręczny') : null,
            ]);
            $url = $get('Adres źródła opisu');
            $rows[] = [
                'group' => $get('Grupa') !== '' ? $get('Grupa') : '(bez grupy)',
                'id' => $get('Karta'),
                'product' => $product,
                'url' => $url !== '' ? $url : null,
                'kind' => $get('Rodzaj źródła') !== '' ? $get('Rodzaj źródła') : null,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{group: string, id: string, product: Product, url: ?string, kind: ?string}>|null
     */
    private function rowsFromDatabase(): ?array
    {
        $priceListId = (int) $this->option('price-list');
        $manufacturer = trim((string) $this->option('manufacturer'));
        if ($priceListId <= 0 && $manufacturer === '') {
            $this->error('Podaj --csv=<plik>, --price-list=<numer> albo --manufacturer=<nazwa>.');

            return null;
        }
        $ids = [];
        if ($priceListId > 0) {
            $list = PriceList::query()->find($priceListId);
            if ($list === null) {
                $this->error("Nie ma cennika numer {$priceListId}.");

                return null;
            }
            $ids = array_values(array_unique(array_map('intval', $list->product_ids ?? [])));
            if ($ids === []) {
                $this->error('Ten cennik nie ma zapisanych produktów (stary import) — użyj --manufacturer=.');

                return null;
            }
        }

        $rows = [];
        Product::query()
            ->when($ids !== [], static fn ($q) => $q->whereIn('id', $ids))
            ->when($ids === [], static fn ($q) => $q->whereRaw('LOWER(TRIM(manufacturer)) = ?', [mb_strtolower($manufacturer)]))
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use (&$rows): void {
                foreach ($chunk as $product) {
                    $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
                    $url = is_string($payload['primary_source_url'] ?? null) && $payload['primary_source_url'] !== ''
                        ? $payload['primary_source_url']
                        : (is_string(($payload['source_urls'] ?? [])[0] ?? null) ? $payload['source_urls'][0] : null);
                    $kind = is_string($payload['primary_source_kind'] ?? null) ? $payload['primary_source_kind'] : null;
                    $rows[] = [
                        'group' => $kind ?? ($url === null ? '(bez źródła)' : '(rodzaj nieznany)'),
                        'id' => (string) $product->id,
                        'product' => $product,
                        'url' => $url,
                        'kind' => $kind,
                    ];
                }
            });

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function summary(array $results): void
    {
        $groups = [];
        foreach ($results as $row) {
            $verdict = $row['result']['verdict'] ?? 'null';
            $groups[$row['group']][$verdict] = ($groups[$row['group']][$verdict] ?? 0) + 1;
        }
        ksort($groups);
        $table = [];
        foreach ($groups as $group => $counts) {
            $total = array_sum($counts);
            $hard = $counts[SourceIdentity::HARD] ?? 0;
            $table[] = [
                $group, $total, $hard, $counts[SourceIdentity::SOFT] ?? 0, $counts[SourceIdentity::NONE] ?? 0,
                $counts['null'] ?? 0, $total > 0 ? number_format(100 * $hard / $total, 1, ',', '').'%' : '—',
            ];
        }
        $this->newLine();
        $this->table(['Grupa', 'Kart', 'hard', 'soft', 'none', 'brak werdyktu', '% hard'], $table);
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function writeOut(string $path, array $results): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            $this->error("Nie da się zapisać pliku: {$path}");

            return;
        }
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['Grupa', 'Karta', 'SKU', 'Nazwa', 'Adres źródła opisu', 'Werdykt', 'Powód', 'Rodzaj klucza', 'Klucz', 'Gdzie', 'Profil'], ';');
        foreach ($results as $row) {
            $r = $row['result'];
            fputcsv($handle, [
                $row['group'], $row['id'], (string) $row['product']->sku, (string) $row['product']->name, (string) ($row['url'] ?? ''),
                (string) ($r['verdict'] ?? ''), $r['reason'], (string) ($r['key_type'] ?? ''), (string) ($r['key'] ?? ''),
                (string) ($r['where'] ?? ''), (string) ($r['profile'] ?? ''),
            ], ';');
        }
        fclose($handle);
    }
}
