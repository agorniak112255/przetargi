<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\Product;
use App\Services\Vector\ProductEmbeddingIndexer;
use Illuminate\Console\Command;

class ReindexProductEmbeddingsCommand extends Command
{
    protected $signature = 'products:reindex-embeddings {--force : Indeksuj nawet gdy hash bez zmian} {--sync : Wykonaj synchronicznie zamiast kolejki}';

    protected $description = 'Reindeksuje embeddingi produktów do Qdrant';

    public function handle(ProductEmbeddingIndexer $indexer): int
    {
        if (! $indexer->shouldIndex()) {
            $this->warn('Wyszukiwanie wektorowe wyłączone lub brak qdrant_url — nic nie robimy.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force');
        $sync = (bool) $this->option('sync');
        $query = Product::query()->orderBy('id');
        $total = (clone $query)->count();
        $this->info("Kolejkuję reindex embeddings dla {$total} produktów".($force ? ' (force)' : '').'…');

        $dispatched = 0;
        $query->chunkById(100, function ($products) use ($force, $sync, $indexer, &$dispatched): void {
            foreach ($products as $product) {
                if ($sync) {
                    $indexer->index($product, $force);
                } else {
                    ReindexProductEmbeddingJob::dispatch($product->id, $force);
                }
                $dispatched++;
            }
        });

        $this->info($sync
            ? "Zaindeksowano synchronicznie: {$dispatched}."
            : "Wysłano do kolejki: {$dispatched} jobów.");

        return self::SUCCESS;
    }
}
