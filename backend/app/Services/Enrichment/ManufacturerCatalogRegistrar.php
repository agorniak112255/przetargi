<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\CatalogHost;
use App\Models\CatalogPage;
use App\Models\ManufacturerSite;
use App\Models\Product;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Przy nowej marce zapamiętuje jej stronę katalogu i wrzuca sitemap do indeksu.
 */
final class ManufacturerCatalogRegistrar
{
    public function __construct(
        private readonly ManufacturerDomainResolver $resolver,
        private readonly CatalogSitemapIndexer $indexer,
    ) {}

    /**
     * @return list<string>
     */
    public function register(string $manufacturer, ?Product $sample = null, bool $index = true): array
    {
        $manufacturer = trim($manufacturer);
        if ($manufacturer === '') {
            return [];
        }

        $product = $sample ?? new Product([
            'manufacturer' => $manufacturer,
            'sku' => '',
            'name' => $manufacturer,
        ]);
        $brand = $this->resolver->brandKey($manufacturer);
        $fromConfig = $this->resolver->configDomainsFor($product);
        if ($fromConfig !== []) {
            ManufacturerSite::remember($brand, $manufacturer, $fromConfig, 'config');

            return $this->uniqueHosts($fromConfig);
        }

        $known = $this->resolver->domainsFor($product);
        if ($known !== []) {
            ManufacturerSite::remember($brand, $manufacturer, $known, 'discovered');

            return $this->uniqueHosts($known);
        }

        $found = $this->resolver->discoverOfficialDomains($product);
        if ($found === []) {
            return [];
        }
        ManufacturerSite::remember($brand, $manufacturer, $found, 'discovered');
        if ($index) {
            $this->indexHosts($found);
        }

        return $this->uniqueHosts($found);
    }

    /**
     * @param  list<string>  $hosts
     */
    public function remember(string $manufacturer, array $hosts, string $source = 'discovered'): void
    {
        ManufacturerSite::remember(
            $this->resolver->brandKey($manufacturer),
            $manufacturer,
            $hosts,
            $source
        );
    }

    /**
     * @return list<string>
     */
    public function hostsFor(Product $product): array
    {
        return $this->uniqueHosts($this->resolver->domainsFor($product));
    }

    /**
     * @return list<string>
     */
    public function allHosts(): array
    {
        return ManufacturerSite::allHosts();
    }

    /**
     * @param  list<string>  $hosts
     */
    private function indexHosts(array $hosts): void
    {
        $seen = [];
        foreach ($hosts as $host) {
            $bare = ManufacturerSite::normalizeHost($host);
            if ($bare === '' || isset($seen[$bare]) || $this->alreadyIndexed($bare)) {
                continue;
            }
            $seen[$bare] = true;
            try {
                $this->indexer->index($bare, 20000, 180);
            } catch (Throwable $e) {
                Log::info('Manufacturer catalog index skipped', [
                    'host' => $bare,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function alreadyIndexed(string $host): bool
    {
        if (CatalogPage::query()->where('host', $host)->exists()) {
            return true;
        }

        return CatalogHost::query()
            ->where('host', $host)
            ->where('last_attempt_at', '>=', now()->subDays(14))
            ->exists();
    }

    /**
     * @param  list<string>  $hosts
     * @return list<string>
     */
    private function uniqueHosts(array $hosts): array
    {
        $out = [];
        foreach ($hosts as $host) {
            $bare = ManufacturerSite::normalizeHost($host);
            if ($bare !== '' && ! isset($out[$bare])) {
                $out[$bare] = $bare;
            }
        }

        return array_values($out);
    }
}
