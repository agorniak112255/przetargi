<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CatalogPage;
use App\Models\Product;
use App\Services\Enrichment\CatalogIndexSearch;
use App\Services\Enrichment\CatalogSitemapIndexer;
use App\Services\Enrichment\DuckDuckGoHtmlSearch;
use App\Services\Enrichment\ProductSearchIdentity;
use Illuminate\Console\Command;
use Throwable;

/**
 * Znajduje sklepy, których brakuje w indeksie: bierze marki z największą liczbą
 * chybień, pyta o nie wyszukiwarkę i pokazuje domeny, które warto zaindeksować.
 */
final class CatalogDiscoverCommand extends Command
{
    protected $signature = 'catalog:discover
        {--sample=400 : Ile produktów losujemy do oceny pokrycia}
        {--brands=8 : Ile marek z największą liczbą chybień sprawdzamy}
        {--queries=3 : Ile produktów na markę pytamy w wyszukiwarce}
        {--index : Od razu zaindeksuj znalezione domeny}';

    protected $description = 'Podpowiada domeny sklepów brakujące w indeksie katalogów';

    public function handle(
        CatalogIndexSearch $catalog,
        ProductSearchIdentity $identity,
        DuckDuckGoHtmlSearch $search,
        CatalogSitemapIndexer $indexer,
    ): int {
        $missing = $this->missingByBrand($catalog, (int) $this->option('sample'));
        if ($missing === []) {
            $this->info('Wszystkie losowane produkty mają trafienie w indeksie.');

            return self::SUCCESS;
        }

        $known = $this->knownHosts();
        $hosts = [];
        $brands = array_slice($missing, 0, max(1, (int) $this->option('brands')), true);

        foreach ($brands as $brand => $products) {
            $this->line('==> '.$brand.' ('.count($products).' chybień w próbce)');
            foreach (array_slice($products, 0, max(1, (int) $this->option('queries'))) as $product) {
                $query = $identity->primaryQueries($product)[0]
                    ?? $identity->productNameWithManufacturer($product);
                if (trim($query) === '') {
                    continue;
                }
                try {
                    $results = $search->search($query, 8);
                } catch (Throwable $e) {
                    $this->warn('    '.mb_substr($e->getMessage(), 0, 120));

                    continue;
                }
                foreach ($results as $row) {
                    $host = $this->normalizeHost((string) ($row['url'] ?? ''));
                    if ($host === '' || isset($known[$host])) {
                        continue;
                    }
                    $hosts[$host] = ($hosts[$host] ?? 0) + 1;
                }
            }
        }

        if ($hosts === []) {
            $this->warn('Wyszukiwarka nie zwróciła nowych domen.');

            return self::SUCCESS;
        }

        arsort($hosts);
        $this->newLine();
        $this->info('Domeny spoza indeksu (liczba trafień):');
        foreach (array_slice($hosts, 0, 25, true) as $host => $count) {
            $this->line(sprintf('    %-40s %d', $host, $count));
        }

        if (! $this->option('index')) {
            $this->newLine();
            $this->line('Dodaj wybrane do config/enrichment.php lub uruchom: artisan catalog:index <domena>');

            return self::SUCCESS;
        }

        foreach (array_slice($hosts, 0, 10, true) as $host => $count) {
            $this->line('==> indeksuję '.$host);
            try {
                $result = $indexer->index($host, 20000, 120);
                $this->info('    '.$result['saved'].' adresów');
            } catch (Throwable $e) {
                $this->warn('    '.$e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, list<Product>>
     */
    private function missingByBrand(CatalogIndexSearch $catalog, int $sample): array
    {
        $out = [];
        $products = Product::query()->inRandomOrder()->limit(max(50, $sample))->get();
        foreach ($products as $product) {
            if ($catalog->findFor($product) !== []) {
                continue;
            }
            $brand = trim((string) $product->manufacturer);
            if ($brand === '') {
                continue;
            }
            $out[$brand][] = $product;
        }

        uasort($out, static fn (array $a, array $b): int => count($b) <=> count($a));

        return $out;
    }

    /**
     * @return array<string, true>
     */
    private function knownHosts(): array
    {
        $out = [];
        foreach (CatalogPage::query()->distinct()->pluck('host') as $host) {
            $out[$this->normalizeHost('https://'.$host)] = true;
        }
        foreach (['allegro.pl', 'olx.pl', 'ceneo.pl', 'youtube.com', 'facebook.com', 'wikipedia.org'] as $noise) {
            $out[$noise] = true;
        }

        return $out;
    }

    private function normalizeHost(string $url): string
    {
        $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));

        return preg_replace('/^www\./', '', $host) ?? $host;
    }
}
