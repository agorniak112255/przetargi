<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Enrichment\CatalogSitemapIndexer;
use Illuminate\Console\Command;
use Throwable;

/**
 * php artisan catalog:index               — wszystkie domeny z config/enrichment.php
 * php artisan catalog:index optimumbhp.pl — jedna domena
 */
final class CatalogIndexCommand extends Command
{
    protected $signature = 'catalog:index
        {host? : Domena do zaindeksowania (domyślnie wszystkie z konfiguracji)}
        {--max=20000 : Limit adresów na domenę}';

    protected $description = 'Indeksuje karty produktu z sitemap producentów i hurtowni';

    public function handle(CatalogSitemapIndexer $indexer): int
    {
        $hosts = $this->hosts();
        if ($hosts === []) {
            $this->error('Brak domen do zaindeksowania.');

            return self::FAILURE;
        }

        $max = max(100, (int) $this->option('max'));
        $total = 0;
        $failed = 0;

        foreach ($hosts as $host) {
            $this->line('==> '.$host);
            try {
                $result = $indexer->index($host, $max);
                $total += $result['saved'];
                $this->info(sprintf('    %d adresów (sitemap: %d)', $result['saved'], count($result['sitemaps'])));
            } catch (Throwable $e) {
                $failed++;
                $this->warn('    '.$e->getMessage());
            }
        }

        $this->info(sprintf('Zaindeksowano %d adresów, domen z błędem: %d.', $total, $failed));

        return $failed === count($hosts) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function hosts(): array
    {
        $single = (string) ($this->argument('host') ?? '');
        if (trim($single) !== '') {
            return [trim($single)];
        }

        $out = [];
        foreach ((array) config('enrichment.retailer_domains', []) as $domain) {
            if (is_string($domain)) {
                $out[] = $domain;
            }
        }
        foreach ((array) config('enrichment.manufacturer_domains', []) as $domains) {
            foreach ((array) $domains as $domain) {
                if (is_string($domain)) {
                    $out[] = $domain;
                }
            }
        }

        $normalized = [];
        foreach ($out as $domain) {
            $host = mb_strtolower(trim(preg_replace('#^https?://#i', '', $domain) ?? $domain));
            $host = preg_replace('/^www\./', '', trim(explode('/', $host)[0] ?? $host)) ?? $host;
            // CDN-y trzymają pliki, nie karty produktu
            if ($host === '' || str_contains($host, 'cloudfront.net')) {
                continue;
            }
            $normalized[$host] = true;
        }

        return array_keys($normalized);
    }
}
