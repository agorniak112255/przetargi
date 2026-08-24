<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\CatalogPage;
use App\Services\Enrichment\CatalogSitemapIndexer;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Dobudowuje tokeny do stron zebranych wcześniej — bez ponownego pobierania sitemap.
 */
final class CatalogTokensCommand extends Command
{
    protected $signature = 'catalog:tokens {--chunk=500 : Ile stron na porcję}';

    protected $description = 'Uzupełnia tokeny wyszukiwania dla zaindeksowanych stron';

    public function handle(CatalogSitemapIndexer $indexer): int
    {
        $total = CatalogPage::query()->count();
        if ($total === 0) {
            $this->warn('Indeks jest pusty — najpierw uruchom catalog:index.');

            return self::SUCCESS;
        }

        $chunk = max(50, (int) $this->option('chunk'));
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        CatalogPage::query()
            ->orderBy('id')
            ->chunkById($chunk, function (Collection $pages) use ($indexer, $bar): void {
                $indexer->storeTokens($pages->pluck('url_hash')->all());
                $bar->advance($pages->count());
            }, 'id');

        $bar->finish();
        $this->newLine(2);
        $this->info('Gotowe.');

        return self::SUCCESS;
    }
}
