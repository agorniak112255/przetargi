<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ReindexProductEmbeddingJob;
use App\Models\Product;
use App\Services\Ai\AiSettingsService;
use App\Services\Vector\EmbeddingClient;
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

    public function handle(
        ProductEmbeddingIndexer $indexer,
        QdrantClient $qdrant,
        EmbeddingClient $embeddings,
        AiSettingsService $settings,
    ): int {
        if (! $indexer->shouldIndex()) {
            $this->warn('Wyszukiwanie wektorowe wyłączone lub brak qdrant_url — nic nie robimy.');

            return self::SUCCESS;
        }

        ReindexProductEmbeddingJob::clearHalt();

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

        if (! $this->probeEmbeddingProfile($embeddings, $qdrant, $settings)) {
            return self::FAILURE;
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

    /**
     * Jedno osadzenie próbne przed zakolejkowaniem całego katalogu. Bez niego zły profil
     * (adres bez /embeddings, model, którego dostawca nie zna) albo kolekcja o innym wymiarze
     * wychodziły dopiero na kilkudziesięciu tysiącach zadań — 22.09.2026 skończyło się to
     * 620 wpisami w failed_jobs i zerem wektorów. Próba kosztuje jedno wywołanie API.
     */
    private function probeEmbeddingProfile(
        EmbeddingClient $embeddings,
        QdrantClient $qdrant,
        AiSettingsService $settings,
    ): bool {
        $profile = $settings->embeddingProfile();
        $where = $profile['base_url'].'/embeddings, model '.$profile['model']
            .' (dostawca: '.$profile['provider'].')';

        try {
            $vector = $embeddings->embed('test profilu embeddingów SUPON');
        } catch (Throwable $e) {
            $this->error('Profil embeddingów nie odpowiada — '.$where.': '.$e->getMessage());
            $this->line('Popraw Ustawienia AI → Wyszukiwanie wektorowe i uruchom komendę ponownie.');

            return false;
        }

        try {
            $qdrant->ensureCollection(count($vector));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return false;
        }

        $this->info('Profil embeddingów OK — '.$where.', wymiar '.count($vector)
            .', kolekcja '.$qdrant->collection().'.');

        return true;
    }
}
