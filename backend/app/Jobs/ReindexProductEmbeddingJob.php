<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\EmbeddingRequestException;
use App\Models\Product;
use App\Services\Ai\AiSettingsService;
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
use Throwable;

class ReindexProductEmbeddingJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [20, 60];

    /**
     * Ustawiany w konstruktorze z „Limitu czasu” w Ustawieniach AI — zob. timeoutSeconds().
     * Wartość tutaj obowiązuje, gdy ustawień nie da się odczytać.
     */
    public int $timeout = self::TIMEOUT_FALLBACK;

    /** Zapas na wyszukanie karty, zapis punktu w Qdrancie i zapis produktu — poza samym osadzeniem. */
    private const TIMEOUT_MARGIN = 60;

    /** Górna granica: limit zadania musi zmieścić się w limicie workera (420 s) i retry_after (480 s). */
    private const TIMEOUT_MAX = 400;

    private const TIMEOUT_FALLBACK = 300;

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
        $this->timeout = self::timeoutSeconds();
    }

    /**
     * Laravel bierze limit zadania przed limitem workera (`--timeout`), więc to ta liczba decyduje,
     * kiedy worker dostanie sygnał zabicia. Klient osadzeń czeka na odpowiedź tyle, ile wynosi
     * „Limit czasu” w Ustawieniach AI (EmbeddingClient), więc sztywne 90 sekund ubijało workera
     * w środku zapytania: wiersz zostawał zarezerwowany, wracał po retry_after i lądował
     * w failed_jobs jako przekroczenie prób, choć samo osadzenie nie miało prawa się wyrobić.
     */
    private static function timeoutSeconds(): int
    {
        try {
            $configured = (int) app(AiSettingsService::class)->resolve()['timeout_seconds'];
        } catch (Throwable) {
            return self::TIMEOUT_FALLBACK;
        }

        if ($configured < 1) {
            return self::TIMEOUT_FALLBACK;
        }

        return min(self::TIMEOUT_MAX, $configured + self::TIMEOUT_MARGIN);
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
