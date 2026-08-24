<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CatalogPage;
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
        {--max=60000 : Limit adresów na domenę}
        {--seconds=240 : Limit czasu na domenę}
        {--fresh-days=0 : Pomiń domeny odświeżone w ostatnich N dniach}
        {--skip= : Dodatkowe domeny do pominięcia, po przecinku}';

    protected $description = 'Indeksuje karty produktu z sitemap producentów i hurtowni';

    public function handle(CatalogSitemapIndexer $indexer): int
    {
        $hosts = $this->hosts();
        if ($hosts === []) {
            $this->error('Brak domen do zaindeksowania.');

            return self::FAILURE;
        }

        $max = max(100, (int) $this->option('max'));
        $seconds = max(30, (int) $this->option('seconds'));
        $freshDays = max(0, (int) $this->option('fresh-days'));
        $total = 0;
        $failed = 0;

        foreach ($hosts as $host) {
            $this->line('==> '.$host);
            if ($freshDays > 0 && $this->indexedRecently($host, $freshDays)) {
                $this->line('    pomijam — zaindeksowane w ostatnich '.$freshDays.' dniach');

                continue;
            }
            try {
                $result = $indexer->index($host, $max, $seconds);
                $total += $result['saved'];
                $this->info(sprintf(
                    '    %d adresów (sitemap: %d)%s%s',
                    $result['saved'],
                    count($result['sitemaps']),
                    $result['off_host'] > 0 ? sprintf(', %d z innej domeny', $result['off_host']) : '',
                    $result['timed_out'] ? ', przerwane limitem czasu' : ''
                ));
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

        $skip = [];
        foreach (array_merge(
            (array) config('enrichment.catalog_skip_hosts', []),
            explode(',', (string) $this->option('skip'))
        ) as $domain) {
            $host = $this->normalizeHost((string) $domain);
            if ($host !== '') {
                $skip[$host] = true;
            }
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
            $host = $this->normalizeHost($domain);
            // CDN-y trzymają pliki, nie karty produktu
            if ($host === '' || str_contains($host, 'cloudfront.net') || isset($skip[$host])) {
                continue;
            }
            $normalized[$host] = true;
        }

        return array_keys($normalized);
    }

    private function normalizeHost(string $domain): string
    {
        $host = mb_strtolower(trim(preg_replace('#^https?://#i', '', $domain) ?? $domain));
        $host = trim(explode('/', $host)[0] ?? $host);

        return preg_replace('/^www\./', '', $host) ?? $host;
    }

    private function indexedRecently(string $host, int $days): bool
    {
        return CatalogPage::query()
            ->where('host', $host)
            ->where('last_seen_at', '>=', now()->subDays($days))
            ->exists();
    }
}
