<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\EmbeddingRequestException;
use App\Models\Product;
use App\Services\Vector\ProductEmbeddingIndexer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReindexProductEmbeddingJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [20, 60];

    public int $timeout = 90;

    /** Osobna kolejka — reindeks całego katalogu nie może blokować pobierania opisów. */
    public const QUEUE = 'embeddings';

    /** Po 401/403 kolejka embeddings nie ma sensu — każdy następny job dostanie to samo. */
    public const HALT_CACHE_KEY = 'product_embeddings_halt';

    /** Ile wierszy kolejki kasuje jedna transakcja przy zatrzymaniu (zob. haltRun). */
    private const HALT_DELETE_CHUNK = 200;

    /**
     * Import cennika i enrichment potrafią zapisać ten sam produkt kilka razy pod
     * rząd — bez unikalności każdy zapis wkładałby do kolejki osobny reindeks.
     * Blokada schodzi w momencie wykonania joba, więc kolejna edycja i tak trafi.
     */
    public int $uniqueFor = 900;

    public function __construct(
        public readonly int $productId,
        public readonly bool $force = false,
    ) {
        $this->onQueue(self::QUEUE);
    }

    /**
     * Zadanie ma sens tylko wtedy, gdy jest dokąd zapisać wektor. Przy wyłączonym wyszukiwaniu
     * wektorowym każdy zapis karty wkładał do kolejki zadanie, które po pobraniu kończyło się
     * natychmiast — 16 workerów kolejki embeddings waliło wtedy w tabelę `jobs` bez przerwy
     * i wywracało ją zakleszczeniami (22.09.2026: 620 wpisów w failed_jobs,
     * wszystkie MaxAttemptsExceededException, przy zerze wektorów w katalogu).
     *
     * Po ponownym włączeniu wektorów nic nie ginie: embedding_hash każdej karty jest wtedy pusty
     * (AiSettingsService czyści go przy zmianie profilu), a pełny indeks buduje
     * products:reindex-embeddings.
     */
    public static function dispatch(...$arguments)
    {
        return static::dispatchIf(app(ProductEmbeddingIndexer::class)->shouldIndex(), ...$arguments);
    }

    public function uniqueId(): string
    {
        return (string) $this->productId;
    }

    public static function clearHalt(): void
    {
        Cache::forget(self::HALT_CACHE_KEY);
    }

    public function handle(ProductEmbeddingIndexer $indexer): void
    {
        if (! $indexer->shouldIndex()) {
            return;
        }

        if (Cache::has(self::HALT_CACHE_KEY)) {
            return;
        }

        $product = Product::query()->find($this->productId);
        if ($product === null) {
            return;
        }

        try {
            $indexer->index($product, $this->force);
        } catch (EmbeddingRequestException $e) {
            if ($e->isTerminal()) {
                $this->haltRun();
                if ($this->job !== null) {
                    $this->fail($e);

                    return;
                }
            }

            throw $e;
        }
    }

    private function haltRun(): void
    {
        Cache::put(self::HALT_CACHE_KEY, 1, 86400);
        if (! Schema::hasTable('jobs')) {
            return;
        }

        // Jeden DELETE po kolumnie `queue` skanuje cały zakres kolejki i bierze blokady na wierszach,
        // po które w tej samej chwili sięga `select ... for update` workerów pobierających zadania —
        // MySQL rozwiązywał to zakleszczeniem (1213) po obu stronach. Kasujemy porcjami po kluczu
        // głównym: blokada obejmuje wtedy tylko te wiersze, które naprawdę znikają.
        //
        // Górna granica z chwili zatrzymania: pętla ma skończyć na tym, co leżało w kolejce teraz,
        // a nie gonić zleceń dokładanych w trakcie kasowania.
        $lastId = (int) (DB::table('jobs')->where('queue', self::QUEUE)->max('id') ?? 0);
        if ($lastId === 0) {
            return;
        }

        while (true) {
            $ids = DB::table('jobs')
                ->where('queue', self::QUEUE)
                ->where('id', '<=', $lastId)
                ->whereNull('reserved_at')
                ->orderBy('id')
                ->limit(self::HALT_DELETE_CHUNK)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                return;
            }

            if (DB::table('jobs')->whereIn('id', $ids)->delete() === 0) {
                return;
            }
        }
    }
}
