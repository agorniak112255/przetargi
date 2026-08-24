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
    protected $signature = 'catalog:tokens
        {--chunk=200 : Ile stron na porcję}
        {--sleep=150 : Przerwa między porcjami w ms — chroni dysk przed zalaniem zapisami}';

    protected $description = 'Uzupełnia tokeny wyszukiwania dla zaindeksowanych stron';

    public function handle(CatalogSitemapIndexer $indexer): int
    {
        // strony z tokenami pomijamy, więc przerwany przebieg wznawia się tanio
        $pending = CatalogPage::query()->whereDoesntHave('tokens');
        $total = (clone $pending)->count();
        if ($total === 0) {
            $this->info(CatalogPage::query()->exists()
                ? 'Wszystkie strony mają już tokeny.'
                : 'Indeks jest pusty — najpierw uruchom catalog:index.');

            return self::SUCCESS;
        }

        $chunk = max(50, (int) $this->option('chunk'));
        $sleep = max(0, (int) $this->option('sleep')) * 1000;
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $pending
            ->orderBy('id')
            ->chunkById($chunk, function (Collection $pages) use ($indexer, $bar, $sleep): void {
                $indexer->storeTokens($pages->pluck('url_hash')->all());
                $bar->advance($pages->count());
                if ($sleep > 0) {
                    usleep($sleep);
                }
            }, 'id');

        $bar->finish();
        $this->newLine(2);
        $this->info('Gotowe.');

        return self::SUCCESS;
    }
}
