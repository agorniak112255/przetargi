<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Product;
use App\Services\Ai\AiTask;
use App\Services\ProductAiSearchService;
use Illuminate\Support\Collection;

/**
 * Jedno wejście do wyszukiwania AI. Wszystko, co dobiera produkt do treści wymagania —
 * wyszukiwarka AI na /products, dopasowanie pozycji SIWZ, zapytania klientów, battlecard
 * i narzędzia pomiarowe — woła ten serwis, więc zmiana reguły działa wszędzie naraz.
 *
 * Ręczne szukanie po nazwie i numerze katalogowym (GET /products?q=) tu nie należy: tam
 * handlowiec zna wyrób i wpisuje kod, więc zostaje przy dopasowaniu tekstowym.
 *
 * Na tym etapie fasada wyłącznie deleguje — kształt odpowiedzi jest ten sam co dotąd,
 * powiększony o ślad wykonania (`trace`), żeby dało się porównywać obie ścieżki silnika.
 */
final class AiProductSearch
{
    public function __construct(
        private readonly ProductAiSearchService $engine,
    ) {}

    /**
     * Jedno wymaganie. Odpowiedź jak z ProductAiSearchService::search() plus `trace`.
     *
     * @return array<string, mixed>
     */
    public function find(
        string $query,
        int $limit = ProductAiSearchService::CATALOG_LIMIT,
        AiTask $task = AiTask::ProductSearch,
        bool $withExternalHint = false,
        bool $webOnly = false,
    ): array {
        $result = $this->engine->search($query, $limit, $withExternalHint, $task, $webOnly);
        // Tryb „tylko internet” nie przechodzi przez katalog, więc ślad zostaje pusty.
        $result['trace'] ??= $this->engine->lastTrace();

        return $result;
    }

    /**
     * Fala wymagań (dopasowanie całego przetargu, analiza zapytania klienta).
     *
     * @param  list<string>  $queries
     * @param  callable(string, int, int): void|null  $onProgress
     * @return list<array<string, mixed>>
     */
    public function findMany(
        array $queries,
        int $limit = ProductAiSearchService::CATALOG_LIMIT,
        AiTask $task = AiTask::ProductSearch,
        int $maxConcurrent = 10,
        ?callable $onProgress = null,
        bool $withExternalHint = false,
    ): array {
        return $this->engine->searchMany($queries, $limit, $withExternalHint, $task, $maxConcurrent, $onProgress);
    }

    /**
     * Deterministyczna lista katalogowa dla wymagania z cechą (norma, klasa, materiał) —
     * bez pytania modelu. Używana jako dobudowa okna kandydatów i jako zapas battlecarda.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogRows(string $query, int $limit): array
    {
        return $this->engine->requirementCatalogRows($query, $limit);
    }

    /**
     * Karty pasujące do treści wymagania, bez pytania modelu — ten sam retrieval co w `find()`,
     * z intencją liczoną lokalnie i kolejnością wg trafności. Dla wywołujących, którzy oceniają
     * karty sami (battlecard ocenia je dowodami z karty i sortuje ceną).
     *
     * @return Collection<int, Product>
     */
    public function candidates(string $query, int $limit, bool $anyBrand = false): Collection
    {
        // $anyBrand: szukanie zamienników — ten sam asortyment bez kotwicy na marce i modelu z wymagania.
        return $this->engine->candidatePool($query, $limit, $anyBrand);
    }

    /**
     * Karta innego producenta niż nazwany w wymaganiu, wg `parsed_intent` z odpowiedzi `find()`/`findMany()` — ta sama
     * reguła co lista zapasowa wyszukiwarki (całe słowa, podmarka ze słownika).
     *
     * @param  array<string, mixed>  $parsedIntent
     */
    public function isOtherRequestedProducer(array $parsedIntent, Product $product): bool
    {
        return $this->engine->isOtherRequestedProducer($parsedIntent, $product);
    }

    /**
     * @param  array<string, mixed>  $parsedIntent
     */
    public function requestedProducerName(array $parsedIntent): string
    {
        return $this->engine->requestedProducerName($parsedIntent);
    }

    /**
     * Ślad ostatniego wywołania `find()` — pula kandydatów, karty wysłane do modelu, czasy.
     * Sensowny wyłącznie zaraz po wywołaniu; `find()` zwraca go też w odpowiedzi.
     *
     * @return array<string, mixed>
     */
    public function lastTrace(): array
    {
        return $this->engine->lastTrace();
    }

    /** Diagnostyka: włącza zapis identyfikatorów kart w śladzie. */
    public function enableSourceTrace(): void
    {
        $this->engine->enableSourceTrace();
    }
}
