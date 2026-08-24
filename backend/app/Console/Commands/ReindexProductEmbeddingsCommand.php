<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\Product;
use App\Services\Vector\ProductEmbeddingIndexer;
use App\Services\Vector\QdrantClient;
use Illuminate\Console\Command;
use Throwable;

class ReindexProductEmbeddingsCommand extends Command
{
    protected $signature = 'products:reindex-embeddings
        {--force : Indeksuj nawet gdy hash bez zmian}
        {--fresh : Skasuj kolekcję przed indeksowaniem (po zmianie modelu/wymiaru)}
        {--sync : Wykonaj synchronicznie zamiast kolejki}';

    protected $description = 'Reindeksuje embeddingi produktów do Qdrant';

    public function handle(ProductEmbeddingIndexer $indexer, QdrantClient $qdrant): int
    {
        if (! $indexer->shouldIndex()) {
            $this->warn('Wyszukiwanie wektorowe wyłączone lub brak qdrant_url — nic nie robimy.');

            return self::SUCCESS;
        }

        $force = (bool) $this->option('force') || (bool) $this->option('fresh');

        if ($this->option('fresh')) {
            $collection = $qdrant->collection();
            try {
                $qdrant->dropCollection();
            } catch (Throwable $e) {
                $this->error('Nie udało się skasować kolekcji '.$collection.': '.$e->getMessage());

                return self::FAILURE;
            }
            $this->info('Skasowano kolekcję '.$collection.' — powstanie od nowa z aktualnym wymiarem.');
            Product::query()
                ->whereNotNull('embedding_hash')
                ->orWhereNotNull('embedding_synced_at')
                ->update(['embedding_hash' => null, 'embedding_synced_at' => null]);
        }

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
