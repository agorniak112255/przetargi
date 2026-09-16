<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductImage;
use App\Services\Enrichment\ManufacturerDomainResolver;
use App\Support\NormCode;
use Illuminate\Console\Command;
use JsonException;

/**
 * Porównanie stanu z kopii zapasowej (`products:recheck-skus --apply`) z tym, co jest teraz.
 * Po ponownym pobraniu całych cenników trzeba wiedzieć, co naprawdę się zmieniło, zanim karty
 * zobaczy człowiek: ile kart zyskało opis, a ile go straciło, skąd pochodzą źródła i czy
 * zniknęły usterki zgłoszone w testach ręcznych (dane z własnego środowiska migracyjnego,
 * zdublowane normy). Polecenie niczego nie zapisuje.
 */
final class EnrichmentDiffCommand extends Command
{
    protected $signature = 'products:enrichment-diff
                            {--backup=* : Plik kopii zapasowej; bez tego wszystkie recheck-skus-*.json z ostatniej doby}
                            {--manufacturer= : Tylko ten producent}
                            {--lost-limit=20 : Ile kodów wypisać na liście „straciły opis”}';

    protected $description = 'Pokazuje, co zmieniło ponowne wzbogacenie: opisy, zdjęcia, dokumenty, źródła, normy';

    public function handle(ManufacturerDomainResolver $manufacturers): int
    {
        $files = $this->backupFiles();
        if ($files === []) {
            $this->error('Nie znalazłem kopii zapasowej — podaj --backup=ścieżka.');

            return self::FAILURE;
        }
        $this->line('Kopie: '.implode(', ', array_map(static fn (string $f): string => basename($f), $files)));

        $onlyManufacturer = trim((string) $this->option('manufacturer'));
        /** @var array<string, array<string, int|list<string>>> $stats */
        $stats = [];
        $lost = [];

        foreach ($files as $file) {
            foreach ($this->readProducts($file) as $entry) {
                $before = is_array($entry['product'] ?? null) ? $entry['product'] : [];
                $id = (int) ($before['id'] ?? 0);
                if ($id === 0) {
                    continue;
                }
                $product = Product::query()->find($id);
                if (! $product instanceof Product) {
                    continue;
                }
                $brand = (string) $product->manufacturer;
                if ($onlyManufacturer !== '' && $brand !== $onlyManufacturer) {
                    continue;
                }
                $row = &$stats[$brand !== '' ? $brand : 'bez producenta'];
                $row['karty'] = (int) ($row['karty'] ?? 0) + 1;

                $beforeDesc = mb_strlen(trim((string) ($before['description'] ?? '')));
                $afterDesc = mb_strlen(trim((string) ($product->description ?? '')));
                $row['opis_przed'] = (int) ($row['opis_przed'] ?? 0) + ($beforeDesc > 0 ? 1 : 0);
                $row['opis_po'] = (int) ($row['opis_po'] ?? 0) + ($afterDesc > 0 ? 1 : 0);
                $row['znaki_przed'] = (int) ($row['znaki_przed'] ?? 0) + $beforeDesc;
                $row['znaki_po'] = (int) ($row['znaki_po'] ?? 0) + $afterDesc;
                if ($beforeDesc > 0 && $afterDesc === 0) {
                    $lost[] = $product->sku.' ('.$brand.')';
                }

                $row['zdjecie_przed'] = (int) ($row['zdjecie_przed'] ?? 0) + (count($entry['images'] ?? []) > 0 ? 1 : 0);
                $row['zdjecie_po'] = (int) ($row['zdjecie_po'] ?? 0)
                    + (ProductImage::query()->where('product_id', $id)->exists() ? 1 : 0);
                $row['pdf_przed'] = (int) ($row['pdf_przed'] ?? 0) + (count($entry['documents'] ?? []) > 0 ? 1 : 0);
                $row['pdf_po'] = (int) ($row['pdf_po'] ?? 0)
                    + (ProductDocument::query()->where('product_id', $id)->exists() ? 1 : 0);

                $beforeSources = $this->sourceUrls($before['enrichment_payload'] ?? null);
                $afterSources = $this->sourceUrls($product->enrichment_payload);
                $row['producent_przed'] = (int) ($row['producent_przed'] ?? 0)
                    + ($this->firstIsManufacturer($beforeSources, $product, $manufacturers) ? 1 : 0);
                $row['producent_po'] = (int) ($row['producent_po'] ?? 0)
                    + ($this->firstIsManufacturer($afterSources, $product, $manufacturers) ? 1 : 0);
                $row['migracja_przed'] = (int) ($row['migracja_przed'] ?? 0) + ($this->hasBlockedHost($beforeSources) ? 1 : 0);
                $row['migracja_po'] = (int) ($row['migracja_po'] ?? 0) + ($this->hasBlockedHost($afterSources) ? 1 : 0);

                $row['normy_dubel_przed'] = (int) ($row['normy_dubel_przed'] ?? 0)
                    + ($this->hasDuplicateNorms($before['enrichment_payload'] ?? null) ? 1 : 0);
                $row['normy_dubel_po'] = (int) ($row['normy_dubel_po'] ?? 0)
                    + ($this->hasDuplicateNorms($product->enrichment_payload) ? 1 : 0);
                unset($row);
            }
        }

        if ($stats === []) {
            $this->error('Kopie nie zawierają kart, które są dziś w katalogu.');

            return self::FAILURE;
        }

        ksort($stats);
        $rows = [];
        foreach ($stats as $brand => $s) {
            $karty = (int) $s['karty'];
            $rows[] = [
                $brand,
                $karty,
                $this->change((int) $s['opis_przed'], (int) $s['opis_po']),
                $this->change(
                    $karty > 0 ? (int) round(((int) $s['znaki_przed']) / $karty) : 0,
                    $karty > 0 ? (int) round(((int) $s['znaki_po']) / $karty) : 0
                ),
                $this->change((int) $s['zdjecie_przed'], (int) $s['zdjecie_po']),
                $this->change((int) $s['pdf_przed'], (int) $s['pdf_po']),
                $this->change((int) $s['producent_przed'], (int) $s['producent_po']),
                $this->change((int) $s['migracja_przed'], (int) $s['migracja_po']),
                $this->change((int) $s['normy_dubel_przed'], (int) $s['normy_dubel_po']),
            ];
        }
        $this->table(
            ['Producent', 'Karty', 'Z opisem', 'Śr. znaków', 'Ze zdjęciem', 'Z PDF', 'Źródło producenta', 'Z migracji', 'Zdublowane normy'],
            $rows,
        );

        if ($lost !== []) {
            $limit = max(0, (int) $this->option('lost-limit'));
            $shown = $limit > 0 ? array_slice($lost, 0, $limit) : $lost;
            $this->warn('Straciły opis ('.count($lost).'): '.implode(', ', $shown).(count($lost) > count($shown) ? ' …' : ''));
            $this->line('Te karty warto obejrzeć przed pokazaniem katalogu — kopia zapasowa pozwala je cofnąć.');
        } else {
            $this->info('Żadna karta nie straciła opisu.');
        }

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function backupFiles(): array
    {
        $given = array_values(array_filter(array_map(
            static fn ($path): string => trim((string) $path),
            (array) $this->option('backup')
        )));
        if ($given !== []) {
            return array_values(array_filter($given, is_file(...)));
        }
        $found = glob(storage_path('app/repair-backups/recheck-skus-*.json')) ?: [];
        $fresh = array_values(array_filter(
            $found,
            static fn (string $path): bool => (int) filemtime($path) >= time() - 86400
        ));
        sort($fresh);

        return $fresh;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readProducts(string $file): array
    {
        try {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->warn("Pomijam {$file}: {$e->getMessage()}");

            return [];
        }
        $products = $data['products'] ?? [];

        return is_array($products) ? array_values(array_filter($products, 'is_array')) : [];
    }

    /**
     * @return list<string>
     */
    private function sourceUrls(mixed $payload): array
    {
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        $urls = is_array($payload) ? ($payload['source_urls'] ?? []) : [];

        return is_array($urls)
            ? array_values(array_filter($urls, static fn ($u): bool => is_string($u) && $u !== ''))
            : [];
    }

    /**
     * @param  list<string>  $urls
     */
    private function firstIsManufacturer(array $urls, Product $product, ManufacturerDomainResolver $manufacturers): bool
    {
        return $urls !== [] && $manufacturers->isManufacturerUrl($urls[0], $product);
    }

    /**
     * @param  list<string>  $urls
     */
    private function hasBlockedHost(array $urls): bool
    {
        $blocked = array_map(
            static fn ($host): string => mb_strtolower(trim((string) $host)),
            (array) config('enrichment.blocked_source_hosts', [])
        );
        foreach ($urls as $url) {
            $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
            foreach ($blocked as $needle) {
                if ($needle !== '' && ($host === $needle || str_ends_with($host, '.'.$needle))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasDuplicateNorms(mixed $payload): bool
    {
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        $norms = is_array($payload) ? ($payload['norms'] ?? []) : [];
        if (! is_array($norms) || count($norms) < 2) {
            return false;
        }
        $norms = array_values(array_filter($norms, static fn ($n): bool => is_string($n) && trim($n) !== ''));

        return count(NormCode::dedupe($norms)) < count($norms);
    }

    private function change(int $before, int $after): string
    {
        if ($before === $after) {
            return (string) $after;
        }

        return $before.' → '.$after.' ('.($after > $before ? '+' : '').($after - $before).')';
    }
}
