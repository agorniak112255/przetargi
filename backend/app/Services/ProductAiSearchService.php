<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Search\ProductTextSearch;
use App\Services\Vector\ProductVectorSearch;
use App\Support\BhpAttributeNormalizer;
use App\Support\CatalogCascadeRecall;
use App\Support\CatalogManufacturerContext;
use App\Support\CatalogRequirementRecall;
use App\Support\CatalogSlangDictionary;
use App\Support\PpeAssortment;
use App\Support\PpeFilterType;
use App\Support\ProductModelFuzzy;
use App\Support\RrfFusion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class ProductAiSearchService
{
    /** Retrieval ma być szeroki — do puli dla modelu zawęża dopiero fuzja rang. */
    private const TEXT_POOL = 300;

    private const VECTOR_POOL = 150;

    /** Kart bez rozpoznanej rodziny są tysiące — bierzemy tylko czubek trafień. */
    private const UNCLASSIFIED_POOL = 120;

    private const CANDIDATE_POOL = 80;

    private const RANK_CARDS = 24;

    private const MAX_MATCHES = 20;

    /**
     * Wiersz z zapasowej listy katalogowej, a nie z oceny modelu. Dopasowanie SIWZ
     * nie może traktować go jak werdyktu AI — model tej karty nie widział albo jej
     * nie wskazał.
     */
    public const MATCH_SOURCE_CATALOG = 'catalog';

    /** Limit listy /products „Szukaj w katalogu” — ranking zawsze do tego progu. */
    public const CATALOG_LIMIT = 40;

    public const WEB_LIMIT = 8;

    private const RANK_MAX_TOKENS_LONG = 2500;

    private const RANK_MAX_TOKENS_SHORT = 800;

    /** Trafienie w kod modelu jest pewniejsze niż podobieństwo tekstu czy wektora. */
    private const RRF_WEIGHT_PRIORITY = 3.0;

    private const RRF_WEIGHT_TEXT = 1.0;

    private const RRF_WEIGHT_VECTOR = 1.0;

    /** Karta bez opisu mówi o sobie mniej, więc jej trafienie waży mniej. */
    private const RRF_WEIGHT_UNCLASSIFIED = 0.6;

    /**
     * Wersja promptu rankingu — ląduje w `search_events`, żeby spadek jakości dało
     * się powiązać ze zmianą instrukcji. Podnieś przy każdej zmianie rankMessages().
     */
    public const RANK_PROMPT_VERSION = 'rank-2026-09-06';

    /** @var array<string, int> */
    private array $timingMs = [];

    /**
     * Ślad ostatniego search(): pula z retrievalu, karty pokazane modelowi i jego
     * surowe trafienia. Zbiera go telemetria (`search_events`) i ewaluacja
     * (`search:eval`) — inaczej recall etapu 1 jest niemierzalny.
     *
     * @var array{candidate_ids: list<int>, rank_card_ids: list<int>, llm_matches: list<array{id: int, score: int}>, passes: int}
     */
    private array $trace = self::EMPTY_TRACE;

    private const EMPTY_TRACE = [
        'candidate_ids' => [],
        'rank_card_ids' => [],
        'llm_matches' => [],
        'passes' => 0,
    ];

    public function __construct(
        private readonly OpenAiCompatibleClient $llm,
        private readonly ProductVectorSearch $vectorSearch,
        private readonly ExternalCatalogHintService $externalHints,
        private readonly ProductModelFuzzy $modelFuzzy,
        private readonly PpeAssortment $assortment,
        private readonly PpeFilterType $filterType,
        private readonly ProductTextSearch $textSearch,
        private readonly RrfFusion $rrf,
        private readonly BhpAttributeNormalizer $bhpAttributes,
        private readonly AiSettingsService $aiSettings,
        private readonly CatalogManufacturerContext $manufacturerContext,
        private readonly CatalogRequirementRecall $catalogRecall,
        private readonly CatalogCascadeRecall $cascadeRecall,
        private readonly CatalogSlangDictionary $catalogSlang,
        private readonly NbpExchangeRateService $fx,
    ) {}

    /**
     * @return array{
     *     query: string,
     *     total: int,
     *     products: list<array<string, mixed>>,
     *     needed: string,
     *     search_phrases: list<string>,
     *     ai_note: string|null,
     *     external_hint: array{url: string, title: string}|null,
     *     external_hints: list<array{url: string, title: string}>
     * }
     */
    public function search(
        string $query,
        int $limit = self::CATALOG_LIMIT,
        bool $withExternalHint = false,
        AiTask $task = AiTask::ProductSearch,
        bool $webOnly = false,
    ): array {
        $query = trim($query);
        if ($query === '') {
            throw new RuntimeException('Podaj treść wymagania dla AI.');
        }
        $wanted = max(1, min(80, $limit));
        $this->trace = self::EMPTY_TRACE;

        if ($webOnly) {
            return $this->webOnlyResult($query, $wanted);
        }

        $wanted = min($wanted, $this->catalogLimit());
        $this->timingMs = [];
        $started = hrtime(true);
        $intent = $this->clock('intent', fn (): array => $this->intentForSearch($query, $task));
        $result = $this->clipResult(
            $this->finishSearch(
                $query,
                $intent,
                $this->rankLimit($wanted),
                $withExternalHint,
                $task,
            ),
            $wanted,
        );
        $this->timingMs['total'] = (int) round((hrtime(true) - $started) / 1e6);
        $result['timings_ms'] = $this->timingMs;
        Log::info('product-ai-search.timings', [
            'query' => mb_substr($query, 0, 80),
            'timings_ms' => $this->timingMs,
            'products' => $result['total'] ?? 0,
        ]);

        return $result;
    }

    /**
     * To samo wyszukiwanie co „Szukaj w katalogu” na /products.
     */
    public function searchForTenderMatch(string $query, int $limit = self::CATALOG_LIMIT): array
    {
        return $this->search($query, $limit, false, AiTask::ProductSearch);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function requirementCatalogRows(string $query, int $limit): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $intent = $this->normalizeIntent($this->localIntent($query));
        $intent = $this->enrichIntentManufacturers($intent, $query);
        $catalogQ = $this->catalogSearchQuery($query, $intent);
        if (! $this->catalogRecall->shouldBackfillCatalog($catalogQ, $intent)) {
            return [];
        }

        return $this->rowsFromRequirementCatalog($catalogQ, max(1, min(80, $limit)));
    }

    /**
     * Wiele zapytań: fala analizy wymagań, retrieval, potem fala rankingu (max $maxConcurrent).
     *
     * @param  list<string>  $queries
     * @return list<array{
     *     query: string,
     *     total: int,
     *     products: list<array<string, mixed>>,
     *     needed: string,
     *     search_phrases: list<string>,
     *     ai_note: string|null,
     *     external_hint: array{url: string, title: string}|null
     * }>
     */
    public function searchMany(
        array $queries,
        int $limit = self::CATALOG_LIMIT,
        bool $withExternalHint = false,
        AiTask $task = AiTask::ProductSearch,
        int $maxConcurrent = 10,
    ): array {
        $clean = [];
        foreach ($queries as $query) {
            $query = trim((string) $query);
            if ($query !== '') {
                $clean[] = $query;
            }
        }
        if ($clean === []) {
            return [];
        }
        $wanted = min(max(1, min(80, $limit)), $this->catalogLimit());
        $limit = $this->rankLimit($wanted);
        $maxConcurrent = $this->clampLlmConcurrency($maxConcurrent);

        $pending = [];
        $done = [];
        $intents = $this->analyzeQueriesForRetrieve($clean, $task, $maxConcurrent);
        $this->prefetchVectorQueries($clean, $intents);
        $retrieveIntents = [];
        foreach ($clean as $i => $query) {
            $retrieveIntents[$i] = $intents[$i];
            $prepared = $this->prepareSearch($query, $intents[$i], $limit);
            if ($task === AiTask::TenderMatch && $prepared['rank_cards'] !== null) {
                $prepared['rank_cards'] = $prepared['rank_cards']->take(12)->values();
            }
            if ($prepared['rank_cards'] === null) {
                $done[$i] = $this->searchResult($query, $intents[$i], $prepared['products'], $prepared['note'], $withExternalHint);
            } else {
                $pending[$i] = $prepared;
            }
        }

        $rankMessages = [];
        $rankOrder = [];
        foreach ($pending as $i => $prepared) {
            $rankOrder[] = $i;
            $rankMessages[] = $this->analyzeAndRankMessages(
                $clean[$i],
                $prepared['rank_cards'],
                $limit,
                $intents[$i]['needed'],
                $intents[$i]['constraints'],
                $task,
            );
        }
        $rankRaws = $this->llm->chatJsonMany($rankMessages, $this->rankMaxTokens($task), $task, $maxConcurrent);
        foreach ($rankOrder as $pos => $i) {
            $raw = is_array($rankRaws[$pos] ?? null) ? $rankRaws[$pos] : [];
            $intents[$i] = $this->withCatalogAliases($this->parseIntent($raw, $clean[$i]), $clean[$i]);
            $retrieveIntent = $this->mergeRetrieveIntent($intents[$i], $retrieveIntents[$i]);
            $ranked = $this->rowsFromLlmMatches(
                $clean[$i],
                $pending[$i]['rank_cards'] ?? $pending[$i]['candidates'],
                $raw,
                $limit,
                $retrieveIntent['needed'],
                $retrieveIntent,
            );
            $catalogQ = $this->catalogSearchQuery($clean[$i], $retrieveIntent);
            $ranked = $this->filterRankedCompatible($catalogQ, $ranked, $clean[$i]);
            if ($ranked === []) {
                $ranked = $this->rowsFromGenericCatalog($clean[$i], $pending[$i]['candidates'], $limit, $retrieveIntent);
            } else {
                $ranked = $this->mergeRequirementCatalogRows($clean[$i], $ranked, $limit, $retrieveIntent);
            }
            $ranked = $this->orderApparelSetRows($clean[$i], $ranked, $pending[$i]['candidates']);
            $done[$i] = $this->searchResult(
                $clean[$i],
                $this->applySlangIntent($clean[$i], $retrieveIntent),
                $ranked,
                $ranked === [] ? 'Model nie znalazł pasującego produktu w katalogu.' : null,
                $withExternalHint,
            );
        }
        if ($task !== AiTask::TenderMatch) {
            $this->rewriteEmptySearchMany($clean, $done, $intents, $retrieveIntents, $limit, $withExternalHint, $task, $maxConcurrent);
        }
        ksort($done);

        return array_map(
            fn (array $row): array => $this->clipResult($row, $wanted),
            array_values($done)
        );
    }

    /**
     * @param  array{needed: string, search_phrases: list<string>, constraints: list<string>}  $intent
     * @return array{
     *     products: list<array<string, mixed>>,
     *     note: string|null,
     *     rank_cards: Collection<int, Product>|null,
     *     candidates: Collection<int, Product>
     * }
     */
    private function prepareSearch(string $query, array $intent, int $limit): array
    {
        $intent = $this->applySlangIntent($query, $this->normalizeIntent($intent));
        $searchIntent = $this->intentForRetrieval($intent);
        $modelQuery = $this->intentModelQuery($query, $searchIntent);
        $candidates = $this->keepCompatible(
            $this->assortmentText($query, $intent['needed']),
            $this->retrieveCandidates($query, $searchIntent, self::CANDIDATE_POOL)
        );
        $candidates = $this->mergeEyeWearSetCandidates($query, $candidates);
        $this->traceProducts('candidate_ids', $candidates);
        $named = $candidates->filter(
            fn (Product $p): bool => $this->modelFuzzy->matches($modelQuery, $p)
                && $this->filterType->covers($query, $this->filterHaystack($p))
        )->values();
        if ($named->isNotEmpty()) {
            $namedRows = $this->rowsFromNamedModels($modelQuery, $named, $limit);
            if ($namedRows !== []) {
                return [
                    'products' => $this->orderEyeWearSetRows($query, $namedRows, $candidates),
                    'note' => null,
                    'rank_cards' => null,
                    'candidates' => $candidates,
                ];
            }
        }
        $snrRows = $this->rowsFromSnrMatches($query, $candidates, $limit);
        if ($snrRows !== []) {
            return [
                'products' => $this->orderEyeWearSetRows($query, $snrRows, $candidates),
                'note' => null,
                'rank_cards' => null,
                'candidates' => $candidates,
            ];
        }
        if ($candidates->isEmpty()) {
            return [
                'products' => [],
                'note' => 'Brak kart z opisem w katalogu do porównania.',
                'rank_cards' => null,
                'candidates' => $candidates,
            ];
        }

        $rankCards = $this->cardsForRanking($query, $candidates, $intent['constraints']);
        $this->traceProducts('rank_card_ids', $rankCards);

        return [
            'products' => [],
            'note' => null,
            'rank_cards' => $rankCards,
            'candidates' => $candidates,
        ];
    }

    /** @param array<string, mixed> $intent */
    private function normalizeIntent(array $intent): array
    {
        return [
            'needed' => trim((string) ($intent['needed'] ?? '')),
            'search_phrases' => is_array($intent['search_phrases'] ?? null) ? $intent['search_phrases'] : [],
            'constraints' => is_array($intent['constraints'] ?? null) ? $intent['constraints'] : [],
            'manufacturer' => isset($intent['manufacturer']) && is_string($intent['manufacturer'])
                ? trim($intent['manufacturer'])
                : null,
            'manufacturer_requested' => isset($intent['manufacturer_requested']) && is_string($intent['manufacturer_requested'])
                ? trim($intent['manufacturer_requested'])
                : null,
            'model_name' => isset($intent['model_name']) && is_string($intent['model_name'])
                ? trim($intent['model_name'])
                : null,
            'size_note' => isset($intent['size_note']) && is_string($intent['size_note'])
                ? trim($intent['size_note'])
                : null,
            'manufacturer_absent_in_catalog' => (bool) ($intent['manufacturer_absent_in_catalog'] ?? false),
            'search_steps' => $this->stringStepList($intent['search_steps'] ?? []),
        ];
    }

    private function needsStructuredIntent(string $query): bool
    {
        $query = trim($query);
        if ($query === '') {
            return false;
        }
        if (mb_strlen($query) < 25 && $this->modelFuzzy->usesModelAnchoredCatalogSearch($query)) {
            return false;
        }
        if (mb_strlen($query) < 18 && preg_match('/^[A-Za-z0-9\-\/\._]+$/u', $query) === 1) {
            return false;
        }
        $should = false;
        if (preg_match('/\b(?:en|iso|pn-?en|iec|astm|din)\s*-?\s*\d/ui', $query) === 1) {
            $should = true;
        }
        if (preg_match('/\bprod\.?\s*\w+/ui', $query) === 1) {
            $should = true;
        }
        if (preg_match('/\b[A-ZĄĆĘŁŃÓŚŹŻ]{6,}\b/u', $query) === 1) {
            $should = true;
        }
        if (mb_strlen($query) >= 40) {
            $should = true;
        }
        if ($this->isSpecificRequirement($query)) {
            $should = true;
        }
        if ($this->slangRewriteFor($query) !== null) {
            $should = true;
        }
        if (! $should) {
            return false;
        }
        if ($this->hasHighConfidenceNamedModelMatch($query)) {
            return false;
        }

        return true;
    }

    private function hasHighConfidenceNamedModelMatch(string $query): bool
    {
        $local = $this->normalizeIntent($this->localIntent($query));
        $modelQuery = $this->intentModelQuery($query, $local);
        if (! $this->modelFuzzy->usesModelAnchoredCatalogSearch($modelQuery)) {
            return false;
        }
        $candidates = $this->retrieveCandidates($query, $local, 12);

        return $candidates->contains(
            fn (Product $p): bool => $this->modelFuzzy->matches($modelQuery, $p)
        );
    }

    /** @param array<string, mixed> $intent */
    private function intentModelQuery(string $query, array $intent): string
    {
        $intent = $this->normalizeIntent($intent);
        if ($intent['manufacturer_absent_in_catalog']) {
            $needed = trim($intent['needed']);

            return $needed !== '' ? $needed : $query;
        }
        $model = trim((string) ($intent['model_name'] ?? ''));
        if ($model === '') {
            return $query;
        }

        return $model.' '.$query;
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return list<string>
     */
    private function intentCatalogBrandTokens(string $query, array $intent): array
    {
        $intent = $this->normalizeIntent($intent);
        if ($intent['manufacturer_absent_in_catalog']) {
            return [];
        }
        $canonical = trim((string) ($intent['manufacturer'] ?? ''));
        if ($canonical !== '' && ! ($intent['manufacturer_absent_in_catalog'] ?? false)) {
            $compact = mb_strtolower(preg_replace('/[^a-z0-9]/iu', '', $canonical) ?? '');

            return $compact !== '' ? [$compact] : [];
        }

        return $this->modelFuzzy->catalogBrands($query);
    }

    private function intentForRetrieval(array $intent): array
    {
        $intent = $this->normalizeIntent($intent);
        if (! $intent['manufacturer_absent_in_catalog']) {
            return $intent;
        }
        $intent['manufacturer'] = null;
        $intent['model_name'] = null;

        return $this->dropAbsentBrandSteps($intent);
    }

    /** @param array<string, mixed> $intent */
    private function manufacturerAbsentNote(array $intent): string
    {
        $name = trim((string) ($intent['manufacturer_requested'] ?? ''));
        if ($name === '') {
            $name = 'podanej marki';
        }

        return "Marki {$name} nie ma w katalogu — dodaj cennik albo użyj AI Internet.";
    }

    /**
     * @param  array<string, mixed>  $intent
     * @param  list<array<string, mixed>>  $products
     */
    private function manufacturerSubstituteNote(array $intent, array $products): string
    {
        $name = trim((string) ($intent['manufacturer_requested'] ?? ''));
        if ($name === '') {
            $name = 'Podanej marki';
        }
        $makers = [];
        foreach ($products as $row) {
            $maker = trim((string) ($row['manufacturer'] ?? ''));
            if ($maker === '' || in_array($maker, $makers, true)) {
                continue;
            }
            $makers[] = $maker;
            if (count($makers) >= 5) {
                break;
            }
        }
        if ($makers === []) {
            return "Marki {$name} nie ma w katalogu — poniżej produkty innych producentów spełniające wymaganie.";
        }

        return 'Marki '.$name.' nie ma w katalogu — poniżej zamienniki od '
            .implode(', ', $makers).' (to samo wymaganie, inny producent).';
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    private function publicIntentSlice(array $intent): array
    {
        $intent = $this->normalizeIntent($intent);
        $out = [];
        if ($intent['manufacturer'] !== null && $intent['manufacturer'] !== '') {
            $out['manufacturer'] = $intent['manufacturer'];
        }
        if ($intent['model_name'] !== null && $intent['model_name'] !== '') {
            $out['model_name'] = $intent['model_name'];
        }
        if ($intent['manufacturer_requested'] !== null && $intent['manufacturer_requested'] !== '') {
            $out['manufacturer_requested'] = $intent['manufacturer_requested'];
        }
        if ($intent['manufacturer_absent_in_catalog']) {
            $out['manufacturer_absent_in_catalog'] = true;
        }
        if ($intent['search_steps'] !== []) {
            $out['search_steps'] = $intent['search_steps'];
        }

        return $out;
    }

    /**
     * @param  array{needed: string, search_phrases: list<string>, constraints: list<string>}  $intent
     * @param  list<array<string, mixed>>  $products
     * @return array{
     *     query: string,
     *     total: int,
     *     products: list<array<string, mixed>>,
     *     needed: string,
     *     search_phrases: list<string>,
     *     ai_note: string|null,
     *     external_hint: array{url: string, title: string}|null
     * }
     */
    private function searchResult(
        string $query,
        array $intent,
        array $products,
        ?string $note,
        bool $withExternalHint,
    ): array {
        $intent = $this->normalizeIntent($intent);
        if ($products === [] && $intent['manufacturer_absent_in_catalog']) {
            $note = $this->manufacturerAbsentNote($intent);
        }
        if ($products !== [] && $intent['manufacturer_absent_in_catalog']) {
            $note = $this->manufacturerSubstituteNote($intent, $products);
        }
        if ($products === [] && $note !== null) {
            return $this->emptyResult($query, $intent, $withExternalHint, $note);
        }

        return [
            'query' => $this->displayQuery($query, $intent),
            'total' => count($products),
            'products' => $products,
            'needed' => $intent['needed'],
            'search_phrases' => $intent['search_phrases'],
            'parsed_intent' => $this->publicIntentSlice($intent),
            'ai_note' => $note,
            'external_hint' => null,
        ];
    }

    private function catalogLimit(): int
    {
        return $this->aiSettings->catalogSearchLimit();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $fn
     * @return T
     */
    private function clock(string $stage, callable $fn): mixed
    {
        $started = hrtime(true);
        try {
            return $fn();
        } finally {
            $this->timingMs[$stage] = (int) round(
                ($this->timingMs[$stage] ?? 0) + ((hrtime(true) - $started) / 1e6)
            );
        }
    }

    /**
     * Ślad ostatniego search() — pula kandydatów, karty wysłane do modelu i jego
     * surowe trafienia (przed bramkami). Sensowny wyłącznie zaraz po search().
     *
     * @return array{candidate_ids: list<int>, rank_card_ids: list<int>, llm_matches: list<array{id: int, score: int}>, passes: int, timings_ms: array<string, int>, prompt_version: string}
     */
    public function lastTrace(): array
    {
        return $this->trace + [
            'timings_ms' => $this->timingMs,
            'prompt_version' => self::RANK_PROMPT_VERSION,
        ];
    }

    /**
     * Przebiegów bywa kilka (rewrite po pustym wyniku), a produkt wystarczy, że
     * pojawił się w którymkolwiek — dlatego suma, nie ostatni przebieg.
     *
     * @param  Collection<int, Product>  $products
     */
    private function traceProducts(string $key, Collection $products): void
    {
        $ids = $products->pluck('id')->map(intval(...))->all();
        $this->trace[$key] = array_values(array_unique([...$this->trace[$key], ...$ids]));
    }

    /** @param array<string, mixed> $raw */
    private function traceLlmMatches(array $raw): void
    {
        $this->trace['passes']++;
        $matches = is_array($raw['matches'] ?? null) ? $raw['matches'] : [];
        foreach ($matches as $match) {
            if (! is_array($match)) {
                continue;
            }
            $id = (int) ($match['id'] ?? 0);
            if ($id > 0) {
                $this->trace['llm_matches'][] = ['id' => $id, 'score' => (int) ($match['score'] ?? 0)];
            }
        }
    }

    private function rankLimit(int $displayLimit): int
    {
        return max($displayLimit, $this->catalogLimit());
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function clipResult(array $result, int $limit): array
    {
        $products = is_array($result['products'] ?? null) ? $result['products'] : [];
        $result['products'] = array_values(array_slice($products, 0, $limit));
        $result['total'] = count($result['products']);

        return $result;
    }

    /** @param array{needed: string, search_phrases: list<string>} $intent */
    private function displayQuery(string $query, array $intent): string
    {
        return $query;
    }

    /**
     * @param  array{needed: string, search_phrases: list<string>, constraints: list<string>}  $intent
     * @return array{
     *     query: string,
     *     total: int,
     *     products: list<array<string, mixed>>,
     *     needed: string,
     *     search_phrases: list<string>,
     *     ai_note: string|null,
     *     external_hint: array{url: string, title: string}|null
     * }
     */
    private function finishSearch(
        string $query,
        array $intent,
        int $limit,
        bool $withExternalHint,
        AiTask $task,
        bool $allowRewrite = true,
    ): array {
        $retrieveIntent = $intent;
        $prepared = $this->clock('prepare', fn (): array => $this->prepareSearch($query, $intent, $limit));
        if ($task === AiTask::TenderMatch && $prepared['rank_cards'] !== null) {
            $prepared['rank_cards'] = $prepared['rank_cards']->take(12)->values();
        }
        if ($prepared['rank_cards'] === null) {
            $result = $this->searchResult($query, $intent, $prepared['products'], $prepared['note'], $withExternalHint);
            if ($allowRewrite && $result['products'] === [] && ! $this->normalizeIntent($intent)['manufacturer_absent_in_catalog']) {
                return $this->retryAfterRewrite($query, $retrieveIntent, $limit, $withExternalHint, $task);
            }

            return $result;
        }
        [$rankedIntent, $ranked, $rankFailed] = $this->clock(
            'rank_llm',
            fn (): array => $this->analyzeAndRank(
                $query,
                $prepared['rank_cards'],
                $limit,
                $task,
                $intent['constraints'],
                $intent,
            )
        );
        [$rankedIntent, $ranked] = $this->clock('post_rank', function () use ($query, $intent, $limit, $task, $prepared, $rankedIntent, $ranked, $rankFailed): array {
            $rankedIntent = $this->withCatalogAliases($rankedIntent, $query);
            $catalogQ = $this->catalogSearchQuery($query, $intent);
            $ranked = $this->filterRankedCompatible($catalogQ, $ranked, $query);
            // Model padł, a wymaganie ma warunek (substancja, norma, klasa) — podstawienie
            // czegokolwiek z katalogu byłoby udawaniem oceny, której nikt nie zrobił.
            // Użytkownik dostaje pustą listę i informację, że to błąd modelu.
            $noGuessing = $rankFailed && $this->isSpecificRequirement($query);
            $useCatalog = ! $noGuessing && $this->catalogRecall->shouldBackfillCatalog($catalogQ, $intent);
            $deferCatalogMerge = $task === AiTask::TenderMatch;
            if (! $deferCatalogMerge && $useCatalog && count($ranked) < $limit) {
                $ranked = $this->mergeRequirementCatalogRows($query, $ranked, $limit, $intent);
            }
            if (! $deferCatalogMerge && $ranked === [] && $useCatalog) {
                $ranked = $this->rowsFromRequirementCatalog($catalogQ, $limit);
            } elseif ($ranked === [] && ! $noGuessing
                && (! $deferCatalogMerge || $this->aiSettings->matchAllowsCatalogRows())) {
                // Przy dopasowaniu SIWZ wynik idzie prosto do pozycji oferty, więc
                // najsłabszy poziom („ten sam rodzaj w katalogu”) tu nie wchodzi —
                // kalesony nie mogą zostać kombinezonem tylko dlatego, że to odzież.
                $ranked = $this->rowsFromGenericCatalog($query, $prepared['candidates'], $limit, $intent);
            }

            return [$rankedIntent, $this->orderApparelSetRows($query, $ranked, $prepared['candidates'])];
        });
        $emptyNote = $rankFailed
            ? 'Nie udało się ocenić kart przez model. Spróbuj ponownie albo użyj zwykłego wyszukiwania.'
            : 'Model nie znalazł pasującego produktu w katalogu.';
        $resultIntent = $this->applySlangIntent($query, $this->mergeRetrieveIntent($rankedIntent, $intent));
        $result = $this->searchResult(
            $query,
            $resultIntent,
            $ranked,
            $ranked === [] ? $emptyNote : null,
            $withExternalHint,
        );
        if ($result['products'] !== [] || ! $allowRewrite) {
            return $result;
        }
        if ($this->intentChanged($retrieveIntent, $rankedIntent)) {
            return $this->finishSearch($query, $rankedIntent, $limit, $withExternalHint, $task, false);
        }

        return $this->retryAfterRewrite($query, $retrieveIntent, $limit, $withExternalHint, $task);
    }

    /**
     * @param  array{needed: string, search_phrases: list<string>, constraints: list<string>}  $usedIntent
     * @return array{
     *     query: string,
     *     total: int,
     *     products: list<array<string, mixed>>,
     *     needed: string,
     *     search_phrases: list<string>,
     *     ai_note: string|null,
     *     external_hint: array{url: string, title: string}|null
     * }
     */
    private function retryAfterRewrite(
        string $query,
        array $usedIntent,
        int $limit,
        bool $withExternalHint,
        AiTask $task,
    ): array {
        $rewritten = $this->clock('rewrite_llm', fn (): array => $this->rewriteCatalogIntent($query, $task));
        if (! $this->intentChanged($usedIntent, $rewritten)) {
            return $this->emptyResult(
                $query,
                $rewritten,
                $withExternalHint,
                'Model nie znalazł pasującego produktu w katalogu.',
            );
        }

        return $this->finishSearch($query, $rewritten, $limit, $withExternalHint, $task, false);
    }

    /**
     * @return array{needed: string, search_phrases: list<string>, constraints: list<string>}
     */
    private function rewriteCatalogIntent(string $query, AiTask $task): array
    {
        try {
            $raw = $this->llm->chatJson($this->rewriteMessages($query), null, 900, null, $task);

            return $this->intentFromRewrite(is_array($raw) ? $raw : [], $query);
        } catch (Throwable) {
            return $this->localIntent($query);
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{needed: string, search_phrases: list<string>, constraints: list<string>}
     */
    private function intentFromRewrite(array $raw, string $query): array
    {
        $intent = $this->parseIntent($raw, $query);
        $modelConstraints = [];
        foreach ([$raw['constraints'] ?? [], $raw['must_evidence'] ?? []] as $list) {
            if (! is_array($list)) {
                continue;
            }
            foreach ($list as $term) {
                if (is_string($term) && mb_strlen(trim($term)) >= 3) {
                    $modelConstraints[] = trim($term);
                }
            }
        }
        $intent['constraints'] = $this->sanitizeConstraints(
            $modelConstraints !== [] ? $modelConstraints : $this->fallbackConstraints($intent['needed'])
        );

        return $intent;
    }

    /**
     * @param  array{needed: string, search_phrases: list<string>}  $before
     * @param  array{needed: string, search_phrases: list<string>}  $after
     */
    private function intentChanged(array $before, array $after): bool
    {
        $beforeSet = $this->normalizedPhraseSet($before['search_phrases']);
        foreach ($this->normalizedPhraseSet($after['search_phrases']) as $phrase) {
            if (! isset($beforeSet[$phrase])) {
                return true;
            }
        }

        return $this->lexicalNormalize($before['needed']) !== $this->lexicalNormalize($after['needed']);
    }

    /**
     * @param  list<string>  $phrases
     * @return array<string, true>
     */
    private function normalizedPhraseSet(array $phrases): array
    {
        $out = [];
        foreach ($phrases as $phrase) {
            $n = trim($this->lexicalNormalize($phrase));
            if ($n !== '') {
                $out[$n] = true;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $clean
     * @param  array<int, array<string, mixed>>  $done
     * @param  array<int, array{needed: string, search_phrases: list<string>, constraints: list<string>}>  $intents
     * @param  array<int, array{needed: string, search_phrases: list<string>, constraints: list<string>}>  $retrieveIntents
     */
    private function rewriteEmptySearchMany(
        array $clean,
        array &$done,
        array &$intents,
        array $retrieveIntents,
        int $limit,
        bool $withExternalHint,
        AiTask $task,
        int $maxConcurrent,
    ): void {
        $empty = [];
        foreach ($done as $i => $row) {
            if (($row['products'] ?? []) === []) {
                $empty[] = $i;
            }
        }
        if ($empty === []) {
            return;
        }

        $pending = [];
        $needLlm = [];
        foreach ($empty as $i) {
            $current = $intents[$i];
            if ($this->intentChanged($retrieveIntents[$i], $current)) {
                $prepared = $this->prepareSearch($clean[$i], $current, $limit);
                if ($prepared['rank_cards'] === null) {
                    $done[$i] = $this->searchResult(
                        $clean[$i],
                        $current,
                        $prepared['products'],
                        $prepared['note'],
                        $withExternalHint,
                    );
                } else {
                    $pending[$i] = $prepared;
                }
            } else {
                $needLlm[] = $i;
            }
        }

        if ($needLlm !== []) {
            $messages = [];
            foreach ($needLlm as $i) {
                $messages[] = $this->rewriteMessages($clean[$i]);
            }
            $raws = $this->llm->chatJsonMany($messages, 900, $task, $maxConcurrent);
            foreach ($needLlm as $pos => $i) {
                $raw = is_array($raws[$pos] ?? null) ? $raws[$pos] : [];
                $rewritten = $this->intentFromRewrite($raw, $clean[$i]);
                if (! $this->intentChanged($intents[$i], $rewritten)) {
                    continue;
                }
                $intents[$i] = $rewritten;
                $prepared = $this->prepareSearch($clean[$i], $rewritten, $limit);
                if ($prepared['rank_cards'] === null) {
                    $done[$i] = $this->searchResult(
                        $clean[$i],
                        $rewritten,
                        $prepared['products'],
                        $prepared['note'],
                        $withExternalHint,
                    );
                } else {
                    $pending[$i] = $prepared;
                }
            }
        }

        if ($pending === []) {
            return;
        }

        $rankMessages = [];
        $rankOrder = [];
        foreach ($pending as $i => $prepared) {
            $rankOrder[] = $i;
            $rankMessages[] = $this->analyzeAndRankMessages(
                $clean[$i],
                $prepared['rank_cards'],
                $limit,
                $intents[$i]['needed'],
                $intents[$i]['constraints'],
                $task,
            );
        }
        $rankRaws = $this->llm->chatJsonMany($rankMessages, $this->rankMaxTokens($task), $task, $maxConcurrent);
        foreach ($rankOrder as $pos => $i) {
            $raw = is_array($rankRaws[$pos] ?? null) ? $rankRaws[$pos] : [];
            $intents[$i] = $this->withCatalogAliases($this->parseIntent($raw, $clean[$i]), $clean[$i]);
            $retrieveIntent = $this->mergeRetrieveIntent($intents[$i], $retrieveIntents[$i] ?? $intents[$i]);
            $ranked = $this->rowsFromLlmMatches(
                $clean[$i],
                $pending[$i]['rank_cards'] ?? $pending[$i]['candidates'],
                $raw,
                $limit,
                $retrieveIntent['needed'],
                $retrieveIntent,
            );
            $catalogQ = $this->catalogSearchQuery($clean[$i], $retrieveIntent);
            $ranked = $this->filterRankedCompatible($catalogQ, $ranked, $clean[$i]);
            if ($ranked === []) {
                $ranked = $this->rowsFromGenericCatalog($clean[$i], $pending[$i]['candidates'], $limit, $retrieveIntent);
            } else {
                $ranked = $this->mergeRequirementCatalogRows($clean[$i], $ranked, $limit, $retrieveIntent);
            }
            $ranked = $this->orderApparelSetRows($clean[$i], $ranked, $pending[$i]['candidates']);
            $done[$i] = $this->searchResult(
                $clean[$i],
                $this->applySlangIntent($clean[$i], $retrieveIntent),
                $ranked,
                $ranked === [] ? 'Model nie znalazł pasującego produktu w katalogu.' : null,
                $withExternalHint,
            );
        }
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function rewriteMessages(string $query): array
    {
        return [
            [
                'role' => 'system',
                'content' => $this->understandSystemPrompt(true),
            ],
            [
                'role' => 'user',
                'content' => $this->requirementWithSlang($query),
            ],
        ];
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function understandMessages(string $query): array
    {
        return [
            [
                'role' => 'system',
                'content' => $this->understandSystemPrompt(false),
            ],
            [
                'role' => 'user',
                'content' => $this->requirementWithSlang($query),
            ],
        ];
    }

    private function understandSystemPrompt(bool $rewriteEmpty): string
    {
        $lead = $rewriteEmpty
            ? 'Jesteś ekspertem BHP i katalogów. Pierwsze wyszukiwanie nic nie dało. '
                .'Zweryfikuj potrzebę i podaj nowe kroki wyszukiwania w języku cennika. '
            : 'Jesteś ekspertem BHP i katalogów. Najpierw ZROZUM wymaganie, potem podaj kroki wyszukiwania. ';

        return $lead
            .$this->manufacturerContext->promptBlock().' '
            .'Blok „Żargon SIWZ” w wiadomości użytkownika to DODATEK do tekstu — nie zastępuje wymagania. '
            .'Przetłumacz żargon na frazy cennika; nie cytuj żargonu (wampirki, nitrylki) w krokach. '
            .'needed: rodzaj produktu (rzeczownik + typ katalogowy), bez normy i bez surowego cytatu SIWZ. '
            .'search_steps: 2-6 warunków AND, od NAJWAŻNIEJSZEGO do najmniej istotnego. '
            .'Krok 1 = rodzaj (np. rękawice). Dalej cechy, które karta ma mieć. '
            .'NA KOŃCU zawsze producent, jeśli jest w wymaganiu; jeśli marki nie ma na liście — i tak daj nazwę z SIWZ jako ostatni krok. '
            .'Nie zgaduj marki spoza wymagania. Nie podstawiaj innej marki z listy. '
            .'Nie dawaj ogólników („ochrona przed cieczą”, „uniwersalne”) jako osobnego kroku. '
            .'wampirki = dzianina + dłoń powlekana / nakrapiane — NIE jednorazowy nitryl, NIE zarękawki, NIE zimowe. '
            .'nitrylowe / nitrylki = rękawice z nitrylu (jednorazowe, diagnostyczne, bezpudrowe) — NIE dzianina z nitrylem na dłoni. '
            .'„lekkie” przy nitrylu = cienkie, NIE „do prac lekkich”. '
            .'Przykład: „Rękawice wampirki uniwersalne” → search_steps: ["rękawice","dzianinowe","dłoń powlekana"]. '
            .'Przykład: „Rękawice nitrylowe lekkie” → search_steps: ["rękawice","nitrylowe","jednorazowe"]. '
            .'Przykład: „Rękawice nitrylowe RTELA” → search_steps: ["rękawice","nitrylowe","RTELA"]. '
            .'manufacturer: nazwa z wymagania. Jeśli jest na liście katalogu — dokładna nazwa z listy. '
            .'Jeśli nie ma jej na liście — wpisz nazwę z SIWZ (nie null). '
            .'model_name: model/kolekcja (np. TRONCHETTO), nie producent. size_note: rozmiary, nie łącz z modelem. '
            .'search_phrases: 3-8 synonimów sklepowych. constraints: 0-6 twardych dowodów (EN 374), puste przy samej nazwie/kolorze. '
            .'Nie zmieniaj rodzaju. Popraw literówki (podnie→spodnie, TEPM-ICE→TEMP-ICE). '
            .$this->dualRequirementPromptRule()
            .'JSON: {"needed":"...","search_steps":["..."],"manufacturer":null,"model_name":null,"size_note":null,'
            .'"search_phrases":["..."],"constraints":[]}.';
    }

    private function requirementWithSlang(string $query): string
    {
        $appendix = $this->catalogSlang->queryAppendix($query);
        $text = "Wymaganie:\n".$query;
        if ($appendix !== '') {
            $text .= "\n\n".$appendix;
        }

        return $text;
    }

    /**
     * @param  Collection<int, Product>  $candidates
     * @return list<array<string, mixed>>
     */
    public function rankCandidates(
        string $query,
        Collection $candidates,
        int $limit = 5,
        ?string $needed = null,
        AiTask $task = AiTask::TenderMatch
    ): array {
        if ($candidates->isEmpty()) {
            return [];
        }

        return $this->rankWithLlm(
            $query,
            $candidates->values(),
            max(1, min(80, $limit)),
            $needed,
            $task,
            $this->fallbackConstraints($query),
        );
    }

    /**
     * @return array{needed: string, search_phrases: list<string>, constraints: list<string>}
     */
    public function understandRequirement(string $query, AiTask $task = AiTask::ProductSearch): array
    {
        try {
            $raw = $this->llm->chatJson($this->understandMessages($query), null, 900, null, $task);

            return $this->withCatalogAliases($this->parseIntent($raw, $query), $query);
        } catch (Throwable) {
            return $this->localIntent($query);
        }
    }

    /**
     * Przy warunku (norma, cecha, zagrożenie) najpierw analiza, potem frazy katalogowe.
     * Sam SKU/model albo goła nazwa asortymentu — od razu retrieval.
     *
     * @return array{needed: string, search_phrases: list<string>, constraints: list<string>}
     */
    private function intentForSearch(string $query, AiTask $task): array
    {
        if ($task === AiTask::TenderMatch) {
            return $this->applySlangIntent($query, $this->normalizeIntent($this->localIntent($query)));
        }
        if (! $this->needsStructuredIntent($query)) {
            return $this->applySlangIntent($query, $this->normalizeIntent($this->localIntent($query)));
        }

        return $this->applySlangIntent(
            $query,
            $this->normalizeIntent($this->understandRequirement($query, $task))
        );
    }

    /**
     * @param  list<string>  $queries
     * @return array<int, array{needed: string, search_phrases: list<string>, constraints: list<string>}>
     */
    private function analyzeQueriesForRetrieve(array $queries, AiTask $task, int $maxConcurrent): array
    {
        $intents = [];
        $need = [];
        foreach ($queries as $i => $query) {
            if ($task !== AiTask::TenderMatch && $this->needsStructuredIntent($query)) {
                $need[] = $i;
            } else {
                $intents[$i] = $this->applySlangIntent($query, $this->normalizeIntent($this->localIntent($query)));
            }
        }
        if ($need === []) {
            return $intents;
        }

        $messages = [];
        foreach ($need as $i) {
            $messages[] = $this->understandMessages($queries[$i]);
        }
        $raws = $this->llm->chatJsonMany($messages, 900, $task, $maxConcurrent);
        foreach ($need as $pos => $i) {
            $raw = is_array($raws[$pos] ?? null) ? $raws[$pos] : [];
            $intents[$i] = $this->applySlangIntent(
                $queries[$i],
                $raw === []
                    ? $this->normalizeIntent($this->localIntent($queries[$i]))
                    : $this->normalizeIntent($this->withCatalogAliases($this->parseIntent($raw, $queries[$i]), $queries[$i]))
            );
        }

        return $intents;
    }

    private function clampLlmConcurrency(int $maxConcurrent): int
    {
        return max(1, min(AiSettingsService::CONCURRENCY_MAX, $maxConcurrent));
    }

    /**
     * Retrieval nie czeka na model — frazy i rodzina z zapytania (z korektą rzeczownika).
     *
     * @return array{needed: string, search_phrases: list<string>, constraints: list<string>}
     */
    private function localIntent(string $query): array
    {
        $corrected = $this->correctQueryNouns($query);

        return $this->parseIntent([
            'needed' => $corrected,
            'search_phrases' => array_values(array_unique(array_filter([
                $corrected,
                $query,
                ...$this->catalogAliasPhrases($corrected),
                ...$this->fallbackPhrases($corrected),
                ...$this->fallbackPhrases($query),
            ]))),
            'constraints' => $this->fallbackConstraints($corrected),
        ], $query);
    }

    /** Literówki rzeczownika, które wcześniej poprawiał tylko model — bez tego rodzina się zeruje. */
    private function correctQueryNouns(string $query): string
    {
        $fixed = preg_replace([
            '/\bpodnie\b/ui',
            '/\bpodni\b/ui',
            '/\bkamizelaka\b/ui',
            '/\bkamizelaki\b/ui',
        ], [
            'spodnie',
            'spodnie',
            'kamizelka',
            'kamizelki',
        ], $query);

        return is_string($fixed) ? $fixed : $query;
    }

    /**
     * @param  array{needed: string, search_phrases: list<string>, constraints: list<string>}  $intent
     * @return array{needed: string, search_phrases: list<string>, constraints: list<string>}
     */
    private function withCatalogAliases(array $intent, string $query): array
    {
        $intent['search_phrases'] = array_values(array_unique(array_filter([
            ...$intent['search_phrases'],
            ...$this->catalogAliasPhrases((string) ($intent['needed'] ?? '')),
            ...$this->catalogAliasPhrases($query),
        ])));

        return $intent;
    }

    /**
     * Nazwy sklepowe, gdy SIWZ używa tkaniny/żargonu zamiast asortymentu z cennika.
     *
     * @return list<string>
     */
    private function catalogAliasPhrases(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        if ($this->assortment->isUnderHelmetLiner($query)) {
            return [];
        }
        $phrases = $this->catalogSlang->phrasesFor($query);
        if ($this->assortment->articleType($query) === 'cap') {
            $phrases[] = 'czapka z daszkiem';
            $phrases[] = 'czapka robocza';
        }

        return array_values(array_unique($phrases));
    }

    /** @return array{needed: string, search_phrases: list<string>, family: string|null}|null */
    private function slangRewriteFor(string $query): ?array
    {
        if ($this->assortment->isUnderHelmetLiner($query)) {
            return null;
        }

        return $this->catalogSlang->searchRewrite($query);
    }

    private function applySlangIntent(string $query, array $intent): array
    {
        $intent = $this->normalizeIntent($intent);
        $slang = $this->slangRewriteFor($query);
        if ($slang !== null) {
            $intent['search_phrases'] = $this->slangSearchPhrases($slang, $query, $intent['search_phrases']);
        }
        if ($intent['search_steps'] === []) {
            $intent['search_steps'] = $this->defaultSearchSteps($query, $intent);
        } else {
            $intent['search_steps'] = $this->withQueryNameFirst($query, $intent['search_steps'], $intent);
        }

        return $this->reconcileManufacturerIntent($intent, $query);
    }

    /**
     * @param  array{needed: string, search_phrases: list<string>, family: string|null}  $slang
     * @param  list<string>  $existing
     * @return list<string>
     */
    private function slangSearchPhrases(array $slang, string $query, array $existing = []): array
    {
        $extra = [];
        foreach ($this->fallbackPhrases($query) as $token) {
            $norm = $this->lexicalNormalize($token);
            if (
                $norm === ''
                || $this->isNonTechnicalToken($norm)
                || $this->isGenericAssortmentToken($norm)
                || $this->isAbsentManufacturerToken($query, $token)
            ) {
                continue;
            }
            $extra[] = $token;
        }

        return array_values(array_unique(array_filter(
            [...$this->queryNameSearchSteps($query), ...$existing, ...$slang['search_phrases'], ...$extra],
            fn (string $phrase): bool => ! $this->isWeakSearchStep($phrase)
        )));
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return list<string>
     */
    private function defaultSearchSteps(string $query, array $intent): array
    {
        $intent = $this->normalizeIntent($intent);
        $steps = [];
        if ($this->assortment->isApparelSet($query)) {
            foreach ($this->queryNormSteps($query) as $norm) {
                $steps[] = $norm;
            }

            return $this->sanitizeSearchSteps($steps, $intent);
        }
        if ($this->assortment->isEyeWearSet($query)) {
            $steps[] = 'etui';
        }
        foreach ($this->queryNameSearchSteps($query) as $nameStep) {
            $steps[] = $nameStep;
        }
        $slang = $this->slangRewriteFor($query);
        if ($slang !== null) {
            foreach ($slang['search_phrases'] as $phrase) {
                $phrase = trim((string) $phrase);
                if ($phrase === '' || $this->isWeakSearchStep($phrase)) {
                    continue;
                }
                if ($this->stepAlreadyListed($steps, $phrase)) {
                    continue;
                }
                $steps[] = $phrase;
                break;
            }
        }
        if ($steps === []) {
            foreach ($this->fallbackPhrases($query) as $token) {
                if (! $this->isWeakSearchStep($token)) {
                    $steps[] = $token;
                }
            }
        }
        if ($steps === []) {
            $needed = trim((string) $intent['needed']);
            if ($needed !== '' && mb_strlen($needed) <= 60 && ! $this->isWeakSearchStep($needed)) {
                $steps[] = $needed;
            }
        }

        return $this->sanitizeSearchSteps($steps, $intent);
    }

    /**
     * @param  list<mixed>  $steps
     * @param  array<string, mixed>  $intent
     * @return list<string>
     */
    private function sanitizeSearchSteps(array $steps, array $intent): array
    {
        $intent = $this->normalizeIntent($intent);
        $brand = trim((string) ($intent['manufacturer'] ?? $intent['manufacturer_requested'] ?? ''));
        $core = [];
        $brandSteps = [];
        foreach ($this->stringStepList($steps) as $step) {
            if ($this->isWeakSearchStep($step)) {
                continue;
            }
            if (
                $this->catalogSlang->isJargonNorm($this->lexicalNormalize($step))
                && ! $this->assortment->isCatalogNounStep($step)
            ) {
                continue;
            }
            if ($brand !== '' && $this->nameAppearsInQuery($step, $brand)) {
                $brandSteps[] = $step;

                continue;
            }
            $core[] = $step;
        }
        if ($brand !== '' && $brandSteps === [] && ! $intent['manufacturer_absent_in_catalog']) {
            $brandSteps[] = $brand;
        }
        if ($brand !== '' && $brandSteps === [] && $intent['manufacturer_absent_in_catalog']) {
            $brandSteps[] = $brand;
        }

        return array_values(array_unique([...$core, ...$brandSteps]));
    }

    /** @param  list<mixed>  $raw */
    private function stringStepList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $step) {
            if (! is_string($step)) {
                continue;
            }
            $step = trim($step);
            if ($step === '' || mb_strlen($step) < 2) {
                continue;
            }
            $out[] = mb_substr($step, 0, 80);
            if (count($out) >= 8) {
                break;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $steps
     * @param  array<string, mixed>  $intent
     * @return list<string>
     */
    private function withQueryNameFirst(string $query, array $steps, array $intent): array
    {
        $steps = $this->sanitizeSearchSteps($steps, $intent);
        foreach (array_reverse($this->queryNameSearchSteps($query)) as $nameStep) {
            if (! $this->stepAlreadyListed($steps, $nameStep)) {
                array_unshift($steps, $nameStep);
            }
        }

        return $this->sanitizeSearchSteps($steps, $intent);
    }

    /** @return list<string> */
    private function queryNameSearchSteps(string $query): array
    {
        foreach ($this->fallbackPhrases($query) as $token) {
            if ($this->assortment->isCatalogNounStep($token)) {
                return [$token];
            }
        }
        $likes = $this->assortment->catalogNounLikes($query);
        if ($likes !== []) {
            return [$likes[0]];
        }

        return [];
    }

    /** @param  list<string>  $steps */
    private function stepAlreadyListed(array $steps, string $candidate): bool
    {
        $needle = $this->compactLex($candidate);
        if ($needle === '') {
            return false;
        }
        foreach ($steps as $step) {
            $hay = $this->compactLex($step);
            if ($hay !== '' && ($hay === $needle || str_contains($hay, $needle) || str_contains($needle, $hay))) {
                return true;
            }
        }

        return false;
    }

    private function isWeakSearchStep(string $step): bool
    {
        if ($this->assortment->isCatalogNounStep($step)) {
            return false;
        }
        $meaningful = 0;
        foreach (preg_split('/[\s,;\/|+]+/u', $this->lexicalNormalize($step)) ?: [] as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            if (mb_strlen($token) < 4 && ! $this->catalogSlang->isIndexedTerm($token)) {
                continue;
            }
            if ($this->catalogSlang->isJargonNorm($token)) {
                continue;
            }
            if (preg_match('/^(ochrona|przed|ciecz|olej|plyn|proste|uniwersaln|lekki|cienki)/u', $token) === 1) {
                continue;
            }
            $meaningful++;
        }

        return $meaningful === 0;
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    private function dropAbsentBrandSteps(array $intent): array
    {
        $intent = $this->normalizeIntent($intent);
        $needles = array_values(array_filter([
            $this->compactLex((string) ($intent['manufacturer_requested'] ?? '')),
            $this->compactLex((string) ($intent['model_name'] ?? '')),
        ], static fn (string $n): bool => mb_strlen($n) >= 3));
        if ($needles === []) {
            return $intent;
        }
        $intent['search_steps'] = array_values(array_filter(
            $intent['search_steps'],
            fn (string $step): bool => ! $this->phraseMentionsNeedles($step, $needles)
        ));

        return $intent;
    }

    private function catalogSearchQuery(string $query, array $intent): string
    {
        $intent = $this->normalizeIntent($intent);
        $base = $intent['needed'] !== '' ? trim($intent['needed']) : trim($query);
        $chunks = $base !== '' ? [$base] : [];
        foreach ($intent['constraints'] as $constraint) {
            $constraint = trim((string) $constraint);
            if ($constraint !== '') {
                $chunks[] = $constraint;
            }
        }
        $merged = trim(implode(' ', $chunks));
        if ($this->assortment->requiresAntistatic($query)
            && ! $this->assortment->requiresAntistatic($merged)) {
            $chunks[] = 'antyelektrostatyczne';
        }
        if (preg_match('/\bgumow\w*/u', $this->lexicalNormalize($query)) === 1
            && preg_match('/\bgumow\w*/u', $this->lexicalNormalize($merged)) !== 1) {
            $chunks[] = 'gumowe';
        }
        if (preg_match('/\bdamsk\w*/u', $this->lexicalNormalize($query)) === 1
            && preg_match('/\bdamsk\w*/u', $this->lexicalNormalize($merged)) !== 1) {
            $chunks[] = 'damskie';
        }
        $merged = trim(implode(' ', array_unique($chunks)));
        if ($this->assortment->isEyeWearSet($query) && ! $this->assortment->isEyeWearSet($merged)) {
            $merged = trim($merged.' etui');
        }

        return $merged !== '' ? $merged : $query;
    }

    /**
     * @param  list<array<string, mixed>>  $ranked
     * @return list<array<string, mixed>>
     */
    private function filterRankedCompatible(string $requirement, array $ranked, ?string $slangQuery = null): array
    {
        if ($ranked === []) {
            return [];
        }
        $ids = array_values(array_filter(array_map(
            static fn (array $row): int => (int) ($row['id'] ?? 0),
            $ranked,
        )));
        if ($ids === []) {
            return [];
        }
        $byId = Product::query()->whereIn('id', $ids)->get()->keyBy('id');
        $out = [];
        foreach ($ranked as $row) {
            $id = (int) ($row['id'] ?? 0);
            $product = $byId->get($id);
            if (! $product instanceof Product) {
                continue;
            }
            $compat = is_string($slangQuery) && $slangQuery !== '' && $this->assortment->isEyeWearSet($slangQuery)
                ? $slangQuery
                : $requirement;
            if (! $this->assortment->compatibleProduct($compat, $product)) {
                continue;
            }
            $skipSlang = $this->assortment->isEyeWearSet($slangQuery ?? $requirement)
                && $this->assortment->eyeWearRole((string) $product->name) === 'case';
            if (! $skipSlang && ! $this->matchesSlangEvidence($slangQuery ?? $requirement, $product)) {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Proste wymaganie (sam rodzaj + tkanina/kolor): nie czekaj na przepisanie przez model.
     *
     * @param  Collection<int, Product>  $candidates
     * @return list<array<string, mixed>>
     */
    private function rowsFromGenericCatalog(string $query, Collection $candidates, int $limit, array $intent = []): array
    {
        $intent = $this->normalizeIntent($intent);
        $catalogQ = $this->catalogSearchQuery($query, $intent);
        if ($this->catalogRecall->shouldBackfillCatalog($catalogQ, $intent)) {
            return $this->rowsFromRequirementCatalog($catalogQ, $limit);
        }
        if ($candidates->isEmpty()) {
            return [];
        }

        $requirement = $this->assortmentText($query, null);
        $products = $this->withResponseRelations(
            $candidates
                ->filter(fn (Product $p): bool => $this->assortment->compatibleProduct($requirement, $p)
                    && $this->matchesSlangEvidence($query, $p)
                    && $this->filterType->covers($requirement, $this->filterHaystack($p))
                    // To najsłabszy poziom odpowiedzi („ten sam rodzaj w katalogu”).
                    // Karta bez opisu i bez enrichmentu nie mówi o sobie nic poza nazwą,
                    // więc jako dopasowanie AI byłaby obietnicą bez pokrycia.
                    && $this->hasSomethingToShow($p))
                ->values()
        );

        $out = [];
        foreach ($products as $product) {
            if (! $product instanceof Product) {
                continue;
            }
            $row = $this->productToRow($product);
            $row['ai_match_percent'] = min(86, max(55, 48 + $this->articleTypeScore($query, $product)));
            $row['ai_match_source'] = self::MATCH_SOURCE_CATALOG;
            $slang = $this->slangRewriteFor($query);
            $row['ai_match_reason'] = $slang !== null
                ? 'Żargon SIWZ → '.$slang['needed']
                : ($this->isSpecificRequirement($query)
                    ? 'Słabsze dopasowanie z zapytania — sprawdź, czy zostawić.'
                    : 'Ten sam rodzaj w katalogu (np. czapka robocza / z daszkiem).');
            $out[] = $row;
        }

        return array_slice($this->orderApparelSetRows($query, $out, $candidates), 0, max(1, min(80, $limit)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    /** Karta opisana (albo świadomie oznaczona jako ręczna) — jest czym uzasadnić trafienie. */
    private function hasSomethingToShow(Product $product): bool
    {
        return trim((string) ($product->description ?? '')) !== ''
            || $product->enrichment_status !== Product::ENRICHMENT_NONE;
    }

    private function rowsFromRequirementCatalog(string $query, int $limit): array
    {
        $products = $this->withResponseRelations(
            $this->retrieveByRequirementCatalog($query, max(40, $limit))
        );
        $reason = $this->catalogRecall->catalogMatchReason($query);
        // Backfill jest odpowiedzią na wymaganie z warunkiem (norma, materiał, klasa).
        // Karta bez opisu i bez enrichmentu nie potwierdza niczego poza nazwą, więc
        // przy takim wymaganiu nie ma czego pokazać. Kart opisanych ręcznie (MANUAL)
        // to nie dotyczy — tam opis jest świadomą decyzją człowieka.
        $specific = $this->isSpecificRequirement($query);
        $out = [];
        foreach ($products as $product) {
            if (! $product instanceof Product) {
                continue;
            }
            if ($specific && ! $this->hasSomethingToShow($product)) {
                continue;
            }
            $row = $this->productToRow($product);
            $score = min(88, max(52, 50 + $this->requirementCatalogScore($query, $product)));
            $row['ai_match_percent'] = $score;
            $row['ai_match_reason'] = $reason;
            $row['ai_match_source'] = self::MATCH_SOURCE_CATALOG;
            $out[] = $row;
        }

        return array_slice($this->sortRankedByMatchPercent($out), 0, max(1, min(80, $limit)));
    }

    /**
     * @param  list<array<string, mixed>>  $ranked
     * @return list<array<string, mixed>>
     */
    private function mergeRequirementCatalogRows(string $query, array $ranked, int $limit, array $intent = []): array
    {
        $catalogQ = $this->catalogSearchQuery($query, $intent);
        if (! $this->catalogRecall->shouldBackfillCatalog($catalogQ, $intent)) {
            return $ranked;
        }
        $seen = [];
        foreach ($ranked as $row) {
            $seen[(int) ($row['id'] ?? 0)] = true;
        }
        foreach ($this->rowsFromRequirementCatalog($catalogQ, $limit) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $ranked[] = $row;
            $seen[$id] = true;
            if (count($ranked) >= $limit) {
                break;
            }
        }

        return $this->sortRankedByMatchPercent($ranked);
    }

    /**
     * @param  list<array<string, mixed>>  $ranked
     * @return list<array<string, mixed>>
     */
    private function sortRankedByMatchPercent(array $ranked): array
    {
        usort($ranked, function (array $a, array $b): int {
            $byPrice = $this->rowPurchasePln($a) <=> $this->rowPurchasePln($b);
            if ($byPrice !== 0) {
                return $byPrice;
            }

            return ($b['ai_match_percent'] ?? 0) <=> ($a['ai_match_percent'] ?? 0);
        });

        return $ranked;
    }

    /**
     * Komplet bluza+spodnie: nie zostawiać samej tańszej nogawki na całej liście.
     *
     * @param  list<array<string, mixed>>  $ranked
     * @param  Collection<int, Product>  $candidates
     * @return list<array<string, mixed>>
     */
    private function orderApparelSetRows(string $query, array $ranked, Collection $candidates): array
    {
        $ranked = $this->sortRankedByMatchPercent($ranked);
        if (! $this->assortment->isApparelSet($query)) {
            return $this->orderEyeWearSetRows($query, $ranked, $candidates);
        }
        $have = [];
        foreach ($ranked as $row) {
            $g = $this->assortment->garment((string) ($row['name'] ?? ''));
            if ($g === 'jacket' || $g === 'pants') {
                $have[$g] = true;
            }
        }
        $seen = [];
        foreach ($ranked as $row) {
            $seen[(int) ($row['id'] ?? 0)] = true;
        }
        foreach (['jacket', 'pants'] as $need) {
            if (isset($have[$need])) {
                continue;
            }
            foreach ($candidates as $product) {
                if (! $product instanceof Product || isset($seen[(int) $product->id])) {
                    continue;
                }
                if ($this->assortment->garment((string) $product->name) !== $need) {
                    continue;
                }
                $row = $this->productToRow($product);
                $row['ai_match_percent'] = 68;
                $row['ai_match_reason'] = 'Drugi element kompletu z katalogu.';
                $ranked[] = $row;
                break;
            }
        }

        return $this->orderEyeWearSetRows($query, $this->interleaveApparelSetRows($ranked), $candidates);
    }

    /**
     * @param  list<array<string, mixed>>  $ranked
     * @param  Collection<int, Product>  $candidates
     * @return list<array<string, mixed>>
     */
    private function orderEyeWearSetRows(string $query, array $ranked, Collection $candidates): array
    {
        if (! $this->assortment->isEyeWearSet($query)) {
            return $ranked;
        }
        $have = [];
        foreach ($ranked as $row) {
            $role = $this->assortment->eyeWearRole((string) ($row['name'] ?? ''));
            if ($role !== null) {
                $have[$role] = true;
            }
        }
        $seen = [];
        foreach ($ranked as $row) {
            $seen[(int) ($row['id'] ?? 0)] = true;
        }
        foreach (['glasses', 'case'] as $need) {
            if (isset($have[$need])) {
                continue;
            }
            foreach ($candidates as $product) {
                if (! $product instanceof Product || isset($seen[(int) $product->id])) {
                    continue;
                }
                if ($this->assortment->eyeWearRole((string) $product->name) !== $need) {
                    continue;
                }
                $row = $this->productToRow($product);
                $row['ai_match_percent'] = 68;
                $row['ai_match_reason'] = $need === 'case'
                    ? 'Etui z katalogu do kompletu z okularami.'
                    : 'Okulary z katalogu do kompletu z etui.';
                $ranked[] = $row;
                $seen[(int) $product->id] = true;
                break;
            }
        }

        return $this->orderEyeWearSetHead($ranked);
    }

    /**
     * Najpierw 3–5 okularów, potem etui — nie przeplatać tanich futerałów na górę.
     *
     * @param  list<array<string, mixed>>  $ranked
     * @return list<array<string, mixed>>
     */
    private function orderEyeWearSetHead(array $ranked): array
    {
        $glasses = [];
        $cases = [];
        $other = [];
        foreach ($ranked as $row) {
            $role = $this->assortment->eyeWearRole((string) ($row['name'] ?? ''));
            if ($role === 'glasses') {
                $glasses[] = $row;
            } elseif ($role === 'case') {
                $cases[] = $row;
            } else {
                $other[] = $row;
            }
        }
        if ($glasses === [] || $cases === []) {
            return $ranked;
        }
        $head = array_slice($glasses, 0, 5);
        $moreGlasses = array_slice($glasses, 5);
        $caseHead = array_slice($cases, 0, 5);

        return array_values(array_merge($head, $caseHead, $moreGlasses, array_slice($cases, 5), $other));
    }

    /**
     * @param  Collection<int, Product>  $candidates
     * @return Collection<int, Product>
     */
    private function mergeEyeWearSetCandidates(string $query, Collection $candidates): Collection
    {
        if (! $this->assortment->isEyeWearSet($query)) {
            return $candidates;
        }
        $candidates = $this->capEyeWearSetCases($candidates, 6);
        $haveCase = $candidates->contains(
            fn ($p): bool => $p instanceof Product && $this->assortment->eyeWearRole((string) $p->name) === 'case'
        );
        if ($haveCase) {
            return $candidates->values();
        }
        $extra = $this->retrieveEyeWearCases($query);
        if ($extra->isEmpty()) {
            return $candidates->values();
        }
        $seen = $candidates->map(fn ($p): int => $p instanceof Product ? (int) $p->id : 0)->flip();
        foreach ($extra as $product) {
            if (! $product instanceof Product || $seen->has((int) $product->id)) {
                continue;
            }
            $candidates->push($product);
            $seen->put((int) $product->id, true);
        }

        return $this->capEyeWearSetCases($candidates, 6);
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return Collection<int, Product>
     */
    private function capEyeWearSetCases(Collection $products, int $maxCases): Collection
    {
        $glasses = collect();
        $cases = collect();
        $other = collect();
        foreach ($products as $product) {
            if (! $product instanceof Product) {
                continue;
            }
            $role = $this->assortment->eyeWearRole((string) $product->name);
            if ($role === 'glasses') {
                $glasses->push($product);
            } elseif ($role === 'case') {
                $cases->push($product);
            } else {
                $other->push($product);
            }
        }

        return $glasses->concat($cases->take($maxCases))->concat($other)->values();
    }

    /**
     * @return Collection<int, Product>
     */
    private function retrieveEyeWearCases(string $query): Collection
    {
        $likes = ['etui', 'futeral', 'futerał'];
        $q = $this->productBaseQuery();
        $q->where(function ($outer) use ($likes): void {
            foreach ($likes as $like) {
                $esc = '%'.$like.'%';
                $outer->orWhere('name', 'like', $esc)
                    ->orWhere('description', 'like', $esc)
                    ->orWhere('search_blob', 'like', $esc);
            }
        });

        return $q->limit(80)
            ->get()
            ->filter(fn (Product $p): bool => $this->assortment->eyeWearRole((string) $p->name) === 'case'
                && $this->assortment->compatibleProduct($query, $p))
            ->take(6)
            ->values();
    }

    /**
     * @param  list<array<string, mixed>>  $ranked
     * @return list<array<string, mixed>>
     */
    private function interleaveApparelSetRows(array $ranked): array
    {
        $jacket = [];
        $pants = [];
        $other = [];
        foreach ($ranked as $row) {
            $g = $this->assortment->garment((string) ($row['name'] ?? ''));
            if ($g === 'jacket') {
                $jacket[] = $row;
            } elseif ($g === 'pants') {
                $pants[] = $row;
            } else {
                $other[] = $row;
            }
        }
        if ($jacket === [] || $pants === []) {
            return $ranked;
        }
        $out = [];
        $i = $j = $k = 0;
        while (isset($jacket[$i]) || isset($pants[$j]) || isset($other[$k])) {
            if (isset($jacket[$i])) {
                $out[] = $jacket[$i++];
            }
            if (isset($pants[$j])) {
                $out[] = $pants[$j++];
            }
            if (isset($other[$k])) {
                $out[] = $other[$k++];
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function rowPurchasePln(array $row): float
    {
        if (isset($row['purchase_price_pln']) && is_numeric($row['purchase_price_pln'])) {
            $pln = (float) $row['purchase_price_pln'];
            if ($pln > 0) {
                return $pln;
            }
        }
        $pln = $this->fx->toPlnOrNull($row['purchase_price'] ?? null, isset($row['currency']) ? (string) $row['currency'] : 'PLN');
        if ($pln !== null && $pln > 0) {
            return $pln;
        }

        return PHP_FLOAT_MAX;
    }

    /**
     * @return Collection<int, Product>
     */
    private function retrieveByRequirementCatalog(string $query, int $limit): Collection
    {
        $rows = $this->catalogRecall->retrieve(
            fn (): Builder => $this->productBaseQuery(),
            $query,
            $limit,
            fn (Product $p): string => $this->filterHaystack($p),
            fn (string $q, Product $p): int => $this->requirementCatalogScore($q, $p),
            fn (Product $p): ?int => $this->productHeatCelsius($p),
        );

        return $this->preferQueryManufacturers($query, $rows)->values();
    }

    private function requirementCatalogScore(string $query, Product $product): int
    {
        $score = $this->articleTypeScore($query, $product);
        if ($this->filterType->sqlLikes($query) !== []) {
            $score += $this->filterType->coverageScore($query, $this->filterHaystack($product));
        }
        $minHeat = $this->bhpAttributes->requiredCelsius($query);
        if ($minHeat !== null) {
            $c = $this->productHeatCelsius($product);
            if ($c !== null) {
                $score += min(25, (int) floor($c / 20));
            }
        }
        if (! $this->assortment->requiresAntistatic($query)) {
            return $score;
        }
        $hay = $this->lexicalNormalize($this->filterHaystack($product));
        if (preg_match('/\bdamsk\w*/u', $this->lexicalNormalize($query)) === 1
            && preg_match('/\bdamsk\w*/u', $hay) === 1) {
            $score += 12;
        }
        if (preg_match('/\bgumow\w*/u', $this->lexicalNormalize($query)) === 1
            && (preg_match('/\bgumow\w*/u', $hay) === 1 || preg_match('/\bguma\b/u', $hay) === 1)) {
            $score += 10;
        }
        if (str_contains($hay, 'esd')) {
            $score += 8;
        }

        return $score;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{needed: string, search_phrases: list<string>, constraints: list<string>}
     */
    private function parseIntent(array $raw, string $query): array
    {
        $needed = trim((string) ($raw['needed'] ?? $raw['needed_product'] ?? ''));
        $phrases = [];
        foreach ([$raw['search_phrases'] ?? [], $raw['search_terms'] ?? []] as $list) {
            if (! is_array($list)) {
                continue;
            }
            foreach ($list as $term) {
                if (is_string($term) && mb_strlen(trim($term)) >= 3) {
                    $phrases[] = trim($term);
                }
            }
        }

        if ($needed === '') {
            $needed = $query;
        }
        if ($phrases === []) {
            $phrases = $this->fallbackPhrases($query);
        }

        $phrases = array_values(array_filter(
            $phrases,
            fn (string $p): bool => ! $this->isClothingSizePhrase($p)
        ));
        if ($phrases === []) {
            $phrases = $this->fallbackPhrases($query);
        }
        foreach ($this->filterType->compactCodes($query) as $code) {
            $phrases[] = $code;
            $phrases[] = $this->filterType->hyphenated($code);
        }
        foreach ($this->catalogAliasPhrases($needed) as $alias) {
            $phrases[] = $alias;
        }
        foreach ($this->catalogAliasPhrases($query) as $alias) {
            $phrases[] = $alias;
        }

        $constraints = [];
        foreach ([$raw['constraints'] ?? [], $raw['must_evidence'] ?? []] as $list) {
            if (! is_array($list)) {
                continue;
            }
            foreach ($list as $term) {
                if (is_string($term) && mb_strlen(trim($term)) >= 3) {
                    $constraints[] = trim($term);
                }
            }
        }
        $rawGaveConstraints = array_key_exists('constraints', $raw) || array_key_exists('must_evidence', $raw);
        if ($constraints === [] && ! $rawGaveConstraints) {
            $constraints = $this->fallbackConstraints($query);
        }

        $manufacturerRequested = trim((string) ($raw['manufacturer'] ?? ''));
        $canonical = $manufacturerRequested !== ''
            ? $this->manufacturerContext->matchManufacturer($manufacturerRequested)
            : null;
        $manufacturerAbsent = false;
        if ($manufacturerRequested !== '' && $canonical === null) {
            $manufacturerAbsent = true;
        } elseif ($canonical !== null && ! $this->manufacturerContext->hasProductsForManufacturer($canonical)) {
            $manufacturerAbsent = true;
            $canonical = null;
        }
        $modelName = trim((string) ($raw['model_name'] ?? $raw['model'] ?? ''));
        $sizeNote = trim((string) ($raw['size_note'] ?? ''));
        $steps = $this->stringStepList($raw['search_steps'] ?? $raw['steps'] ?? []);

        $intent = $this->normalizeIntent($this->reconcileManufacturerIntent($this->enrichIntentManufacturers([
            'needed' => $needed,
            'search_phrases' => array_values(array_unique($phrases)),
            'constraints' => $this->sanitizeConstraints($constraints),
            'manufacturer' => $canonical,
            'manufacturer_requested' => $manufacturerRequested !== '' ? $manufacturerRequested : null,
            'model_name' => $modelName !== '' ? $modelName : null,
            'size_note' => $sizeNote !== '' ? $sizeNote : null,
            'manufacturer_absent_in_catalog' => $manufacturerAbsent,
            'search_steps' => $steps,
        ], $query), $query));
        $intent['search_steps'] = $this->sanitizeSearchSteps($intent['search_steps'], $intent);

        return $intent;
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    private function enrichIntentManufacturers(array $intent, string $query): array
    {
        $intent = $this->normalizeIntent($intent);
        if ($intent['manufacturer'] !== null || $intent['manufacturer_absent_in_catalog']) {
            return $intent;
        }
        foreach ($this->modelFuzzy->catalogBrands($query) as $token) {
            $canonical = $this->manufacturerContext->matchManufacturer($token);
            if ($canonical === null) {
                $intent['manufacturer_requested'] = strtoupper($token);
                $intent['manufacturer_absent_in_catalog'] = true;

                return $intent;
            }
            $intent['manufacturer'] = $canonical;

            return $intent;
        }

        return $intent;
    }

    /**
     * Marka spoza katalogu (RTELA) albo halucynacja modelu (Reis zamiast RTELA)
     * nie może wycinać zamienników ani blokować sitka.
     *
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    private function reconcileManufacturerIntent(array $intent, string $query): array
    {
        $intent = $this->normalizeIntent($intent);
        if (
            $intent['manufacturer'] !== null
            && ! $this->nameAppearsInQuery($query, (string) $intent['manufacturer'])
            && ! $this->nameAppearsInQuery($query, (string) ($intent['manufacturer_requested'] ?? ''))
        ) {
            $intent['manufacturer'] = null;
            $intent['manufacturer_requested'] = null;
            $intent['manufacturer_absent_in_catalog'] = false;
        }

        if ($intent['manufacturer'] === null && ! $intent['manufacturer_absent_in_catalog']) {
            foreach ($this->manufacturerTokensFromQuery($query) as $token) {
                $canonical = $this->manufacturerContext->matchManufacturer($token);
                if ($canonical !== null) {
                    $intent['manufacturer'] = $canonical;
                    $intent['manufacturer_requested'] = $token;
                    break;
                }
                if ($this->catalogHasIdentityToken($token)) {
                    continue;
                }
                $intent['manufacturer'] = null;
                $intent['manufacturer_requested'] = $token;
                $intent['manufacturer_absent_in_catalog'] = true;
                break;
            }
        }

        return $this->stripAbsentManufacturerNoise($intent);
    }

    /**
     * @param  array<string, mixed>  $intent
     * @return array<string, mixed>
     */
    private function stripAbsentManufacturerNoise(array $intent): array
    {
        $intent = $this->normalizeIntent($intent);
        if (! $intent['manufacturer_absent_in_catalog']) {
            return $intent;
        }
        $needles = array_values(array_filter([
            $this->compactLex((string) ($intent['manufacturer_requested'] ?? '')),
            $this->compactLex((string) ($intent['model_name'] ?? '')),
        ], static fn (string $n): bool => mb_strlen($n) >= 3));
        if ($needles === []) {
            return $intent;
        }
        if (
            $intent['model_name'] !== null
            && $this->compactLex((string) $intent['model_name']) === $this->compactLex((string) ($intent['manufacturer_requested'] ?? ''))
        ) {
            $intent['model_name'] = null;
        }
        $neededCompact = $this->compactLex($intent['needed']);
        $intent['search_phrases'] = array_values(array_filter(
            $intent['search_phrases'],
            fn (string $phrase): bool => ! $this->phraseMentionsNeedles($phrase, $needles)
                || $this->compactLex($phrase) === $neededCompact
        ));
        $intent['constraints'] = array_values(array_filter(
            $intent['constraints'],
            fn (string $phrase): bool => ! $this->phraseMentionsNeedles($phrase, $needles)
        ));
        $intent['search_steps'] = array_values(array_filter(
            $intent['search_steps'],
            fn (string $phrase): bool => ! $this->phraseMentionsNeedles($phrase, $needles)
        ));

        return $intent;
    }

    /** @param  list<string>  $needles */
    private function phraseMentionsNeedles(string $phrase, array $needles): bool
    {
        $compact = $this->compactLex($phrase);
        if ($compact === '') {
            return false;
        }
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($compact, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function nameAppearsInQuery(string $query, string $name): bool
    {
        $needle = $this->compactLex($name);
        $hay = $this->compactLex($query);

        return $needle !== '' && mb_strlen($needle) >= 2 && str_contains($hay, $needle);
    }

    private function compactLex(string $text): string
    {
        return str_replace(' ', '', $this->lexicalNormalize($text));
    }

    /**
     * @return list<string>
     */
    private function manufacturerTokensFromQuery(string $query): array
    {
        $out = [];
        foreach (preg_split('/[\s,;:·•\/|+]+/u', $query) ?: [] as $raw) {
            $raw = trim((string) $raw);
            if ($raw === '') {
                continue;
            }
            if (preg_match('/^prod\.?\s*(.+)$/ui', $raw, $m) === 1) {
                $raw = trim((string) $m[1]);
            }
            if (! $this->looksLikeManufacturerToken($raw)) {
                continue;
            }
            $norm = $this->lexicalNormalize($raw);
            if ($norm === '' || $this->isNonTechnicalToken($norm) || $this->isGenericAssortmentToken($norm)) {
                continue;
            }
            $out[] = $raw;
        }
        foreach ($this->quotedIdentityTokens($query) as $raw) {
            $norm = $this->lexicalNormalize($raw);
            if ($norm === '' || $this->isNonTechnicalToken($norm) || $this->isGenericAssortmentToken($norm)) {
                continue;
            }
            $out[] = $raw;
        }

        return array_values(array_unique($out));
    }

    /**
     * Nazwa w cudzysłowie SIWZ („Nortex”) — nie musi być CAPS, żeby uznać ją za markę/model.
     *
     * @return list<string>
     */
    private function quotedIdentityTokens(string $query): array
    {
        $out = [];
        if (preg_match_all('/[„“«"\']([^„“»”"\']{3,24})[”»“"\']/u', $query, $m)) {
            foreach ($m[1] as $raw) {
                $raw = trim((string) $raw);
                $letters = (string) preg_replace('/[^\p{L}]/u', '', $raw);
                if ($raw === '' || preg_match('/\d/u', $raw) === 1) {
                    continue;
                }
                if (mb_strlen($letters) < 4 || mb_strlen($letters) > 16) {
                    continue;
                }
                $out[] = $raw;
            }
        }

        return array_values(array_unique($out));
    }

    private function looksLikeManufacturerToken(string $token): bool
    {
        $letters = (string) preg_replace('/[^\p{L}]/u', '', $token);
        if (mb_strlen($letters) < 3 || mb_strlen($letters) > 16) {
            return false;
        }

        return $letters === mb_strtoupper($letters, 'UTF-8');
    }

    private function isAbsentManufacturerToken(string $query, string $token): bool
    {
        // Sygnałem marki są wersaliki w samym wymaganiu („MSA”, „UVEX”). Testowanie
        // tokenu podniesionego do wersalików przechodziło zawsze i zdejmowało
        // z warunków każde słowo techniczne spoza katalogu („amoniakiem”).
        $raw = $this->queryTokenForCompact($query, $token) ?? $token;
        if (! $this->looksLikeManufacturerToken($raw)) {
            return false;
        }
        if ($this->manufacturerContext->matchManufacturer($raw) !== null) {
            return false;
        }

        return ! $this->catalogHasIdentityToken($raw);
    }

    private function queryTokenForCompact(string $query, string $token): ?string
    {
        $want = $this->compactLex($token);
        if ($want === '') {
            return null;
        }
        foreach (preg_split('/[\s,;:·•\/|+]+/u', $query) ?: [] as $raw) {
            $raw = trim((string) $raw);
            if ($raw !== '' && $this->compactLex($raw) === $want) {
                return $raw;
            }
        }

        return null;
    }

    private function catalogHasIdentityToken(string $token): bool
    {
        $token = trim($token);
        if (mb_strlen($token) < 3) {
            return false;
        }
        $like = '%'.addcslashes($token, '%_\\').'%';

        return Product::query()
            ->where(function ($q) use ($like): void {
                $q->where('name', 'like', $like)
                    ->orWhere('sku', 'like', $like);
            })
            ->exists();
    }

    /**
     * Czy wymaganie ma warunek poza samą nazwą asortymentu — wtedy ocenia model, nie skrót „ten sam typ”.
     */
    public function isSpecificRequirement(string $query): bool
    {
        if ($this->modelFuzzy->usesModelAnchoredCatalogSearch($query)) {
            return false;
        }
        $stripped = $this->stripNonTechnicalTokens($query);
        if (preg_match('/\b(?:en|iso|pn-?en|iec|astm|din)\s*-?\s*\d/ui', $stripped) === 1) {
            return true;
        }
        if (preg_match('/\d/u', $stripped) === 1) {
            return true;
        }

        return $this->fallbackConstraints($query) !== [];
    }

    /**
     * @param  list<string>  $constraints
     * @return list<string>
     */
    private function sanitizeConstraints(array $constraints): array
    {
        $out = [];
        foreach ($constraints as $term) {
            $term = trim($term);
            if ($term === '') {
                continue;
            }
            $kept = [];
            foreach (preg_split('/\s+/u', $this->lexicalNormalize($term)) ?: [] as $part) {
                if ($part === '' || $this->isNonTechnicalToken($part) || $this->isGenericAssortmentToken($part)) {
                    continue;
                }
                $kept[] = $part;
            }
            if ($kept !== []) {
                $out[] = $term;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private function fallbackConstraints(string $query): array
    {
        $out = [];
        foreach ($this->fallbackPhrases($query) as $token) {
            $norm = $this->lexicalNormalize($token);
            if ($norm === '' || $this->isNonTechnicalToken($norm) || $this->isAbsentManufacturerToken($query, $token)) {
                continue;
            }
            $out[] = $token;
            if (count($out) >= 6) {
                break;
            }
        }

        return $out;
    }

    private function stripNonTechnicalTokens(string $query): string
    {
        $kept = [];
        foreach (preg_split('/[\s,;\/|+]+/u', $query) ?: [] as $token) {
            $token = trim($token);
            if ($token === '' || $this->isNonTechnicalToken($this->lexicalNormalize($token))) {
                continue;
            }
            $kept[] = $token;
        }

        return implode(' ', $kept);
    }

    private function isNonTechnicalToken(string $normalized): bool
    {
        $t = trim($normalized);
        if ($t === '' || $this->isClothingSizePhrase($t) || $this->isGenericAssortmentToken($t)) {
            return true;
        }
        if ($this->catalogSlang->isJargonNorm($t)) {
            return true;
        }

        return preg_match(
            '/^(czarn|bial|zol|niebies|czerw|zielon|szar|granat|pomaranc|brazow|bezow'
            .'|srebrn|zlot|grafit|khaki|navy|black|white|yellow|blue|red|green|grey|gray|orange'
            .'|polar(?!yz)|poliestr|baweln|nylon|elastan|lycra|ociepl|pokryt|kolor|rozmiar'
            .'|drelich|drill|twill|denim|kanw|flanel|welur|sztruks|oxford|ripstop|softshell'
            .'|uniwersaln'
            .'|nisk|wysok|sredn|poziom|stopien|tlumien|attenuat|snr)/u',
            $t
        ) === 1;
    }

    /**
     * @return list<string>
     */
    private function queryNormSteps(string $query): array
    {
        $out = [];
        if (preg_match_all('/\b(?:en(?:\s*iso)?\s*)?(\d{4,5})\b/ui', $query, $m)) {
            foreach ($m[1] as $n) {
                $out[] = $n;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return list<string>
     */
    private function fallbackPhrases(string $query): array
    {
        $tokens = preg_split('/[\s,;\/|+]+/u', mb_strtolower($query)) ?: [];
        $stop = [
            'do', 'pracy', 'z', 'na', 'oraz', 'dla', 'the', 'and', 'with', 'od', 'przy',
            'bez', 'jak', 'lub', 'czy', 'jest', 'się', 'pod', 'nad', 'typ', 'rodzaju',
            'przed', 'formie', 'celu', 'oraz', 'produkt',
        ];
        $out = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            $hasDigit = preg_match('/\d/u', $token) === 1;
            if (
                (! $hasDigit && mb_strlen($token) < 4)
                || ($hasDigit && mb_strlen($token) < 2)
                || in_array($token, $stop, true)
                || $this->isClothingSizePhrase($token)
            ) {
                continue;
            }
            $out[] = $token;
            if (count($out) >= 12) {
                break;
            }
        }

        return $out !== [] ? $out : [mb_substr($query, 0, 80)];
    }

    private function isClothingSizePhrase(string $phrase): bool
    {
        $t = preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($phrase))) ?? '';

        return in_array($t, [
            'xxs', 'xs', 'xxl', 'xxxl', 'xxxxl', 'xxxxxl',
            '2xl', '3xl', '4xl', '5xl', '2x', '3x', '4x',
        ], true);
    }

    private function isGenericAssortmentToken(string $normalized): bool
    {
        return preg_match(
            '/^(rekawic|glove|spodn|kurtk|bluz|czapk|czepek|kominiark|balaclava|helm|kask'
            .'|fartuch|kitel|kamizelk|kombinezon|ogrodniczk|buty|obuwie|trzewik|polbut'
            .'|kalosz|gumiak|gumowc|wellington'
            .'|sztyblet|okular|gogl|nausznik|sluch|polmask|respirator|pochlaniacz|filtr'
            .'|nakolann|wkladk|robocz|ochronn|zimow|letni|mesk|damsk)/u',
            trim($normalized)
        ) === 1;
    }

    private function lexicalNormalize(string $text): string
    {
        $t = mb_strtolower($text);
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];

        return (string) preg_replace('/[^a-z0-9]+/u', ' ', strtr($t, $map));
    }

    /**
     * @param  array{needed: string, search_phrases: list<string>}  $intent
     * @return Collection<int, Product>
     */
    private function retrieveCandidates(string $query, array $intent, int $limit): Collection
    {
        $intent = $this->applySlangIntent($query, $this->intentForRetrieval($this->normalizeIntent($intent)));
        $modelQuery = $this->intentModelQuery($query, $intent);
        $searchText = $intent['needed'] !== '' ? $intent['needed'] : $query;
        $requirement = $this->assortmentText($query, $intent['needed']);
        $codeHits = $this->clock('retrieve_codes', fn (): Collection => $this->retrieveByModelCode($modelQuery.' '.$searchText, $limit));
        $snrHits = $this->clock('retrieve_snr', fn (): Collection => $this->retrieveBySnr($query, $limit));
        $mountHits = $this->clock('retrieve_mount', fn (): Collection => $this->retrieveByHearingMount($query, $limit));
        $forcedHits = $this->uniqueProducts($codeHits->concat($snrHits)->concat($mountHits), $limit);
        $fuzzyHits = $this->clock('retrieve_fuzzy', fn (): Collection => $this->retrieveByFuzzyModel($modelQuery.' '.$searchText, $limit));
        $filterHits = $this->clock('retrieve_filter', fn (): Collection => $this->retrieveByFilterType($query, $limit));
        $brandHits = $this->modelFuzzy->usesModelAnchoredCatalogSearch($modelQuery)
            ? collect()
            : $this->clock('retrieve_brand', fn (): Collection => $this->retrieveByManufacturer($query, $intent, $limit));
        $priority = $this->uniqueProducts(
            $filterHits->concat($fuzzyHits)->concat($forcedHits)->concat($brandHits),
            $limit
        );

        if ($this->modelFuzzy->usesModelAnchoredCatalogSearch($modelQuery)) {
            $namedPriority = $this->preferCatalogBrands(
                $query,
                $this->keepCompatible($requirement, $priority),
                $intent
            );
            if ($namedPriority->isNotEmpty()) {
                // Filtr „kombinezon” zapełnia limit zrzutem asortymentu i wypycha
                // markę+numer z nazwy (Tychem 4000, SKU magazynowy, producent DuPont).
                return $this->withModelCodeHits($requirement, $forcedHits, $namedPriority, $limit);
            }
        }

        $recalled = $this->clock('retrieve_cascade', function () use ($query, $intent, $requirement, $limit): array {
            return $this->cascadeRecall->retrieve($query, $intent, $requirement, $limit);
        });
        $cascaded = $recalled['products'];
        $cascadeLevel = $recalled['level'] ?? null;
        $cascadeKept = collect();
        if ($cascaded->isNotEmpty()) {
            $fromNameSteps = is_string($cascadeLevel) && str_starts_with($cascadeLevel, 'steps_');
            $pool = $fromNameSteps
                ? $forcedHits->concat($cascaded)
                : ($this->slangRewriteFor($query) !== null
                    ? $forcedHits->concat($cascaded)->concat($priority)
                    : $priority->concat($cascaded));

            $cascadeKept = $this->keepCompatible($requirement, $this->uniqueProducts($pool, $limit));
            // Pełna pula kończy retrieval — dokładanie czegokolwiek i tak by z niej wypadło.
            // Trafienie po kodzie modelu albo progu SNR zostaje z przodu: kaskada
            // „nauszniki nagłowne” nie może zepchnąć X2A-EU poza limit.
            if ($cascadeKept->count() >= $limit) {
                return $this->withModelCodeHits($requirement, $forcedHits, $cascadeKept, $limit);
            }
        }
        if ($this->catalogSlang->requiresTightEvidence($query)) {
            // Żargon wymaga twardego dowodu na karcie, ale sprawdza go bramka zgodności,
            // nie kroki kaskady. Dokładamy więc trafienia tekstowe — każde i tak musi
            // przejść przez ten sam dowód, a bez nich pula bywa jednoelementowa.
            return $this->keepCompatible(
                $requirement,
                $this->uniqueProducts(
                    $cascadeKept->concat($priority)->concat($this->hydrate($this->clock(
                        'retrieve_text',
                        fn (): array => $this->textSearch->search(
                            $intent['search_phrases'],
                            $this->searchFamily($query, $intent['needed']),
                            self::TEXT_POOL
                        )
                    ))),
                    $limit
                )
            )->values();
        }
        // Kaskada z trafieniem w konkret jest odpowiedzią. Dalej idziemy tylko wtedy, gdy
        // bramka zgodności zdjęła z niej wszystko (krok po „czapce” przy wymaganiu na
        // kominiarkę) albo gdy zeszła do samego rzeczownika rodzaju — wtedy z definicji
        // nie widzi kart bez tego słowa w nazwie („URG-A”).
        if ($cascadeKept->isNotEmpty() && ! $this->cascadeSweptFamilyNoun($cascadeLevel, $intent)) {
            return $this->withModelCodeHits($requirement, $forcedHits, $cascadeKept, $limit);
        }

        // Gdy rodzina jest rozpoznana, indeks zwraca cały zgodny asortyment — także karty
        // bez trafienia we frazę, tylko niżej. Wcześniej wymagał tego skan całego katalogu
        // po stronie PHP; teraz warunek idzie do WHERE.
        $family = $this->searchFamily($query, $intent['needed']);
        $rankings = [
            'priority' => $priority->pluck('id')->map(intval(...))->all(),
            'text' => $this->clock(
                'retrieve_text',
                fn (): array => $this->textSearch->search($intent['search_phrases'], $family, self::TEXT_POOL)
            ),
            // Zawężenie do rodziny wycina karty, którym rodziny nie dało się ustalić —
            // w tym katalogu to dwie trzecie pozycji. Wracają osobnym źródłem, ale
            // wyłącznie z trafieniem w tekst, więc nie zalewają zgodnego asortymentu.
            'unclassified' => $family === null
                ? []
                : $this->clock(
                    'retrieve_unclassified',
                    fn (): array => $this->textSearch->searchUnclassified($intent['search_phrases'], self::UNCLASSIFIED_POOL)
                ),
            'vector' => $this->clock(
                'retrieve_vector',
                fn (): array => $this->retrieveVectorIds($searchText, self::VECTOR_POOL)
            ),
        ];

        $fused = $this->rrf->fuse(
            $rankings,
            [
                'priority' => self::RRF_WEIGHT_PRIORITY,
                'text' => self::RRF_WEIGHT_TEXT,
                'unclassified' => self::RRF_WEIGHT_UNCLASSIFIED,
                'vector' => self::RRF_WEIGHT_VECTOR,
            ],
            $limit * 2,
        );

        $recall = $this->clock('retrieve_catalog', function () use ($query, $intent, $limit): Collection {
            return $this->catalogRecall->shouldRecallToCandidatePool(
                $this->catalogSearchQuery($query, $intent),
                $intent,
            )
                ? $this->retrieveByRequirementCatalog($this->catalogSearchQuery($query, $intent), $limit)
                : collect();
        });

        $merged = $this->clock('retrieve_hydrate', function () use ($requirement, $forcedHits, $cascadeKept, $fused, $recall, $brandHits, $limit): Collection {
            return $this->keepCompatible(
                $requirement,
                $this->uniqueProducts(
                    // Tu docieramy tylko wtedy, gdy kaskada zeszła do samego rzeczownika
                    // rodzaju: jej karty są równe co do wartości („SPODNIE MACH 1..60”)
                    // i wypchnęłyby z puli trafienie wektorowe. Fuzja rang wie więcej, więc
                    // idzie pierwsza, a kaskada uzupełnia resztę puli.
                    $this->hydrate($fused)->concat($forcedHits)->concat($cascadeKept)->concat($recall)->concat($brandHits),
                    $limit * 3
                )
            );
        });
        $branded = $this->preferCatalogBrands($query, $merged, $intent);

        return $this->uniqueProducts($branded, $limit)->values();
    }

    /**
     * Czy kaskada skończyła na krokach po samym rzeczowniku rodzaju („spodnie”,
     * „czapka”). Taki krok bierze wyłącznie karty z tym słowem w nazwie, więc karta
     * zgodna, ale opisana inaczej („URG-A” w kategorii „Odzież robocza”), do puli
     * nie wejdzie — i dlatego trzeba ją uzupełnić resztą rodziny. Krok po cesze albo
     * po żargonie („kalesony”, „bielizna termiczna”) jest już rozstrzygnięciem i
     * niczego nie dokładamy.
     *
     * @param  array{search_steps: list<string>}  $intent
     */
    private function cascadeSweptFamilyNoun(?string $level, array $intent): bool
    {
        if (! is_string($level) || ! str_starts_with($level, 'steps_')) {
            return false;
        }
        $used = array_slice($intent['search_steps'], 0, max(1, (int) mb_substr($level, 6)));
        if ($used === []) {
            return false;
        }
        foreach ($used as $step) {
            if (! $this->isGenericAssortmentToken($this->lexicalNormalize($step))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Literówka w rzeczowniku ("podnie" zamiast "spodnie") zeruje rozpoznanie rodziny,
     * a wraz z nim zawężenie do zgodnego asortymentu i bramkę kompatybilności.
     * Gdy surowe wymaganie nie wskazuje rodziny, dokładamy nazwę odczytaną przez model —
     * ta jest już po korekcie pisowni.
     */
    private function assortmentText(string $query, ?string $needed): string
    {
        $query = $this->correctQueryNouns($query);
        $needed = trim((string) $needed);
        if ($needed === '' || $needed === $query || $this->assortment->family($query) !== null) {
            return $query;
        }

        return $needed.' '.$query;
    }

    private function searchFamily(string $query, ?string $needed): ?string
    {
        $family = $this->assortment->family($this->assortmentText($query, $needed));
        if ($family !== null) {
            return $family;
        }

        $slang = $this->slangRewriteFor($query);

        return $slang['family'] ?? null;
    }

    private function slangProductHaystack(Product $product): string
    {
        return implode(' ', [
            (string) $product->name,
            (string) $product->sku,
            (string) ($product->category ?? ''),
            (string) ($product->description ?? ''),
            (string) ($product->search_blob ?? ''),
        ]);
    }

    private function matchesSlangEvidence(string $query, Product $product): bool
    {
        if ($this->slangRewriteFor($query) === null) {
            return true;
        }
        $hay = $this->slangProductHaystack($product);

        return $this->catalogSlang->matchesEvidence($query, $hay);
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Product>
     */
    private function hydrate(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        $byId = $this->productBaseQuery()->whereIn('id', $ids)->get()->keyBy('id');
        $out = collect();
        foreach ($ids as $id) {
            $product = $byId->get($id);
            if ($product instanceof Product) {
                $out->push($product);
            }
        }

        return $out->values();
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return Collection<int, Product>
     */
    private function keepCompatible(string $query, Collection $products): Collection
    {
        return $products
            ->filter(fn (Product $p): bool => (
                $this->assortment->compatibleProduct($query, $p)
                || $this->modelFuzzy->matches($query, $p)
            ) && $this->matchesSlangEvidence($query, $p)
                && $this->meetsRequiredSnr($query, $p))
            ->values();
    }

    private function meetsRequiredSnr(string $query, Product $product): bool
    {
        $min = $this->bhpAttributes->requiredSnr($query);
        if ($min === null) {
            return true;
        }
        $have = $this->bhpAttributes->snrRating($this->filterHaystack($product));

        return $have === null || $have >= $min;
    }

    /**
     * EN 14387 w nazwie/SKU/opisie — A2-B2-E2-K2-Hg-CO-NO-P3 i a2b2e2k2no to ten sam filtr.
     *
     * @return Collection<int, Product>
     */
    private function retrieveByFilterType(string $query, int $limit): Collection
    {
        $likes = $this->filterType->sqlLikes($query);
        if ($likes === []) {
            return collect();
        }

        $q = $this->productBaseQuery();
        $q->where(function ($outer) use ($likes): void {
            foreach ($likes as $like) {
                $esc = '%'.$like.'%';
                $outer->orWhere('name', 'like', $esc)
                    ->orWhere('sku', 'like', $esc)
                    ->orWhere('description', 'like', $esc)
                    ->orWhere('norms', 'like', $esc)
                    ->orWhere('search_blob', 'like', $esc);
            }
        });

        return $q->limit(200)
            ->get()
            ->filter(fn (Product $p): bool => $this->filterType->covers($query, $this->filterHaystack($p)))
            ->sortByDesc(fn (Product $p): int => $this->filterType->coverageScore($query, $this->filterHaystack($p)))
            ->take(max(8, $limit))
            ->values();
    }

    /**
     * Karty z SNR na karcie, które spełniają próg z SIWZ — kaskada rodzaju ich nie widzi.
     *
     * @return Collection<int, Product>
     */
    private function retrieveBySnr(string $query, int $limit): Collection
    {
        $min = $this->bhpAttributes->requiredSnr($query);
        if ($min === null) {
            return collect();
        }

        $q = $this->productBaseQuery();
        $q->where(function ($outer): void {
            $outer->where('name', 'like', '%snr%')
                ->orWhere('description', 'like', '%snr%')
                ->orWhere('search_blob', 'like', '%snr%')
                ->orWhere('norms', 'like', '%snr%');
        });

        return $q->limit(800)
            ->get()
            ->filter(function (Product $p) use ($min): bool {
                $have = $this->bhpAttributes->snrRating($this->filterHaystack($p));

                return $have !== null && $have >= $min;
            })
            ->sortByDesc(fn (Product $p): int => $this->snrRetrieveScore($query, $p))
            ->take(max(8, $limit))
            ->values();
    }

    /** Nauszniki nagłowne przed zatyczkami o wyższym SNR — próg to minimum, nie ranking. */
    private function snrRetrieveScore(string $query, Product $product): int
    {
        $score = 0;
        $reqType = $this->assortment->articleType($query, PpeAssortment::FAMILY_HEARING);
        $prodType = $this->assortment->articleType((string) $product->name, PpeAssortment::FAMILY_HEARING);
        if ($reqType !== null && $prodType === $reqType) {
            $score += 1000;
        }
        $name = $this->lexicalNormalize((string) $product->name);
        foreach ($this->fallbackPhrases($query) as $token) {
            $stem = mb_substr($this->lexicalNormalize($token), 0, 7);
            if (mb_strlen($stem) >= 6 && str_contains($name, $stem)) {
                $score += 200;
            }
        }

        return $score;
    }

    /**
     * Nahełmowe / nagłowne — kaskada po „hełm” nie widzi karty z samym „nahełmowa”.
     *
     * @return Collection<int, Product>
     */
    private function retrieveByHearingMount(string $query, int $limit): Collection
    {
        $want = $this->assortment->hearingMount($query);
        if ($want === null) {
            return collect();
        }

        $q = $this->productBaseQuery();
        $q->where(function ($outer) use ($want): void {
            if ($want === PpeAssortment::MOUNT_HELMET) {
                foreach (['%nahelm%', '%nahełm%', '%do hełm%', '%do helm%', '%na hełm%', '%na helm%', '%p3e%'] as $like) {
                    $outer->orWhere('name', 'like', $like)
                        ->orWhere('sku', 'like', $like)
                        ->orWhere('category', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhere('search_blob', 'like', $like);
                }

                return;
            }
            foreach (['%naglown%', '%nagłown%', '%pałąk%', '%palak%'] as $like) {
                $outer->orWhere('name', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('search_blob', 'like', $like);
            }
        });

        return $q->limit(400)
            ->get()
            ->filter(fn (Product $p): bool => $this->assortment->hearingMount(
                (string) $p->name.' '.$p->sku.' '.($p->category ?? '')
            ) === $want)
            ->take(max(8, $limit))
            ->values();
    }

    private function articleTypeScore(string $query, Product $product): int
    {
        $name = $this->lexicalNormalize(
            $product->name.' '.$product->sku.' '.($product->category ?? '')
        );
        $full = $this->lexicalNormalize(
            $name.' '.($product->description ?? '').' '.($product->norms ?? '')
        );
        $score = 10;
        $slang = $this->slangRewriteFor($query);
        $tokens = $slang !== null
            ? $this->fallbackPhrases(implode(' ', $slang['search_phrases']))
            : $this->fallbackPhrases($query);
        foreach ($tokens as $token) {
            $t = $this->lexicalNormalize($token);
            if ($t === '' || mb_strlen($t) < 4) {
                continue;
            }
            if ($this->isGenericAssortmentToken($t)) {
                if (str_contains($name, $t)) {
                    $score += 2;
                }

                continue;
            }
            if (str_contains($name, $t)) {
                $score += 8;
            } elseif (str_contains($full, $t)) {
                $score += 5;
            }
        }

        return $score;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return Collection<int, Product>
     */
    private function preferQueryManufacturers(string $query, Collection $products): Collection
    {
        $hints = $this->modelFuzzy->brandHints($query);
        if ($hints === [] || $products->isEmpty()) {
            return $products;
        }

        $matched = [];
        foreach ($products as $product) {
            $manuf = mb_strtolower((string) preg_replace('/[^a-z0-9]/iu', '', (string) $product->manufacturer));
            if ($manuf === '') {
                continue;
            }
            foreach ($hints as $hint) {
                if (str_contains($manuf, $hint) || (mb_strlen($manuf) >= 3 && str_contains($hint, $manuf))) {
                    $matched[$hint] = true;
                }
            }
        }
        if ($matched === []) {
            return $products;
        }

        return $products->filter(function (Product $product) use ($matched): bool {
            $manuf = mb_strtolower((string) preg_replace('/[^a-z0-9]/iu', '', (string) $product->manufacturer));
            if ($manuf === '') {
                return false;
            }
            foreach (array_keys($matched) as $hint) {
                if (str_contains($manuf, $hint) || (mb_strlen($manuf) >= 3 && str_contains($hint, $manuf))) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /**
     * Marka z SIWZ jest twardym znacznikiem — nie pokazuj Portwest, gdy napisano MSA.
     *
     * @param  Collection<int, Product>  $products
     * @return Collection<int, Product>
     */
    private function preferCatalogBrands(string $query, Collection $products, ?array $intent = null): Collection
    {
        $intent = $this->normalizeIntent($intent ?? []);
        if ($intent['manufacturer_absent_in_catalog']) {
            return $products;
        }
        $brands = $this->intentCatalogBrandTokens($query, $intent);
        if ($brands === [] && $intent['manufacturer'] === null) {
            $brands = $this->modelFuzzy->catalogBrands($query);
        }
        if ($brands === [] || $products->isEmpty()) {
            return $products;
        }
        $matched = $products
            ->filter(fn (Product $p): bool => $this->modelFuzzy->matchesCatalogBrand($p, $brands))
            ->values();

        return $matched->isNotEmpty() ? $matched : $products;
    }

    /**
     * @return Collection<int, Product>
     */
    private function retrieveByManufacturer(string $query, array $intent, int $limit): Collection
    {
        $intent = $this->normalizeIntent($intent);
        $brands = $this->intentCatalogBrandTokens($query, $intent);
        if ($brands === []) {
            $brands = $this->modelFuzzy->catalogBrands($query);
        }
        if ($brands === []) {
            return collect();
        }

        $q = $this->productBaseQuery();
        $q->where(function ($outer) use ($brands, $intent): void {
            foreach ($brands as $brand) {
                $like = '%'.addcslashes($brand, '%_\\').'%';
                $outer->orWhere('manufacturer', 'like', $like)
                    ->orWhere('name', 'like', $like);
            }
            $canonical = trim((string) ($intent['manufacturer'] ?? ''));
            if ($canonical !== '') {
                $like = '%'.addcslashes($canonical, '%_\\').'%';
                $outer->orWhere('manufacturer', 'like', $like);
            }
        });

        return $q->limit(max(24, $limit * 3))->get()->values();
    }

    /**
     * Karty bez opisu też — gdy SIWZ ma kod modelu występujący w SKU/nazwie (6503 → 6503-EN).
     *
     * @return Collection<int, Product>
     */
    private function retrieveByModelCode(string $query, int $limit): Collection
    {
        $codes = $this->modelCodePhrases($query);
        $pairs = $this->modelFuzzy->catalogModelWordDigitPairs($query);
        if ($codes === [] && $pairs === []) {
            return collect();
        }

        $cap = max(8, $limit);
        $pairHits = collect();
        if ($pairs !== []) {
            $pairHits = $this->productBaseQuery()
                ->where(function ($outer) use ($pairs): void {
                    foreach ($pairs as [$word, $num]) {
                        $w = '%'.addcslashes($word, '%_\\').'%';
                        $n = '%'.addcslashes($num, '%_\\').'%';
                        $outer->orWhere(function ($inner) use ($w, $n): void {
                            $inner->where(function ($col) use ($w, $n): void {
                                $col->where('name', 'like', $w)->where('name', 'like', $n);
                            })->orWhere(function ($col) use ($w, $n): void {
                                $col->where('sku', 'like', $w)->where('sku', 'like', $n);
                            });
                        });
                    }
                })
                ->limit($cap)
                ->get()
                ->values();
        }

        $codeHits = collect();
        if ($codes !== []) {
            $codeHits = $this->productBaseQuery()
                ->where(function ($outer) use ($codes): void {
                    foreach ($codes as $code) {
                        $like = addcslashes($code, '%_\\');
                        $outer->orWhere('name', 'like', '%'.$like.'%');
                        $outer->orWhere('sku', 'like', mb_strlen($code) <= 4 ? '%'.$like.'%' : $like.'%');
                    }
                })
                ->limit($cap)
                ->get()
                ->values();
        }

        return $this->uniqueProducts($pairHits->concat($codeHits), $cap);
    }

    /**
     * Karty bez opisu też — literówka w modelu (TEPM-ICE → TEMP-ICE).
     *
     * @return Collection<int, Product>
     */
    private function retrieveByFuzzyModel(string $query, int $limit): Collection
    {
        $brands = $this->modelFuzzy->manufacturerHints($query);
        $q = $this->productBaseQuery();

        $nums = $this->modelFuzzy->modelNumbers($query);
        if ($brands !== []) {
            $q->where(function ($outer) use ($brands): void {
                foreach ($brands as $brand) {
                    $like = '%'.addcslashes($brand, '%_\\').'%';
                    $outer->orWhere('manufacturer', 'like', $like)
                        ->orWhere('name', 'like', $like);
                }
            });
        } elseif ($this->modelFuzzy->hasNamedModel($query)) {
            $parts = $this->modelFuzzy->hyphenLetterParts($query);
            if ($parts === [] && $nums === []) {
                return collect();
            }
            foreach ($parts as $part) {
                $like = '%'.addcslashes($part, '%_\\').'%';
                $q->where(function ($w) use ($like): void {
                    $w->where('name', 'like', $like)->orWhere('sku', 'like', $like);
                });
            }
        } else {
            return collect();
        }
        if ($nums !== []) {
            $q->where(function ($w) use ($nums): void {
                foreach ($nums as $num) {
                    $like = '%'.addcslashes($num, '%_\\').'%';
                    $w->orWhere('name', 'like', $like)->orWhere('sku', 'like', $like);
                }
            });
        }

        return $q->limit(800)
            ->get()
            ->filter(fn (Product $p): bool => $this->modelFuzzy->matches($query, $p))
            ->sortByDesc(fn (Product $p): int => $this->modelFuzzy->score($query, $p))
            ->take(max(8, $limit))
            ->values();
    }

    /**
     * Do modelu idą najpierw karty z śladem warunku — zrzut „wszystkie kombinezony”
     * nie może zająć 24 miejsc i wypchnąć Tychema.
     *
     * @param  Collection<int, Product>  $candidates
     * @param  list<string>  $constraints
     * @return Collection<int, Product>
     */
    private function cardsForRanking(string $query, Collection $candidates, array $constraints): Collection
    {
        $candidates = $this->interleaveApparelSetProducts($query, $candidates->values());
        if ($candidates->count() <= self::RANK_CARDS || $constraints === []) {
            return $candidates->take(self::RANK_CARDS)->values();
        }

        $needles = $this->constraintNeedles($constraints);
        $minSnr = $this->bhpAttributes->requiredSnr($query);
        if ($needles === [] && $minSnr === null) {
            return $candidates->take(self::RANK_CARDS)->values();
        }

        $with = collect();
        $without = collect();
        foreach ($candidates as $product) {
            if (! $product instanceof Product) {
                continue;
            }
            $meetsSnr = $minSnr !== null && $this->productMeetsSnr($product, $minSnr);
            $hasNeedle = $needles !== [] && $this->haystackHasNeedle($this->rankingHaystack($product), $needles);
            if ($meetsSnr || ($minSnr === null && $hasNeedle)) {
                $with->push($product);
            } else {
                $without->push($product);
            }
        }

        return $this->interleaveApparelSetProducts($query, $with->concat($without))->take(self::RANK_CARDS)->values();
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return Collection<int, Product>
     */
    private function interleaveApparelSetProducts(string $query, Collection $products): Collection
    {
        if ($this->assortment->isEyeWearSet($query) && $products->count() >= 2) {
            return $this->interleaveEyeWearSetProducts($products);
        }
        if (! $this->assortment->isApparelSet($query) || $products->count() < 2) {
            return $products->values();
        }
        $jacket = collect();
        $pants = collect();
        $other = collect();
        foreach ($products as $product) {
            if (! $product instanceof Product) {
                continue;
            }
            $g = $this->assortment->garment((string) $product->name);
            if ($g === 'jacket') {
                $jacket->push($product);
            } elseif ($g === 'pants') {
                $pants->push($product);
            } else {
                $other->push($product);
            }
        }
        if ($jacket->isEmpty() || $pants->isEmpty()) {
            return $products->values();
        }
        $out = collect();
        $i = 0;
        while ($i < $jacket->count() || $i < $pants->count() || $i < $other->count()) {
            if ($jacket->get($i) instanceof Product) {
                $out->push($jacket->get($i));
            }
            if ($pants->get($i) instanceof Product) {
                $out->push($pants->get($i));
            }
            if ($other->get($i) instanceof Product) {
                $out->push($other->get($i));
            }
            $i++;
        }

        return $out->values();
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return Collection<int, Product>
     */
    private function interleaveEyeWearSetProducts(Collection $products): Collection
    {
        $glasses = collect();
        $cases = collect();
        $other = collect();
        foreach ($products as $product) {
            if (! $product instanceof Product) {
                continue;
            }
            $role = $this->assortment->eyeWearRole((string) $product->name);
            if ($role === 'glasses') {
                $glasses->push($product);
            } elseif ($role === 'case') {
                $cases->push($product);
            } else {
                $other->push($product);
            }
        }
        if ($glasses->isEmpty() || $cases->isEmpty()) {
            return $products->values();
        }

        return $glasses->concat($cases->take(6))->concat($other)->values();
    }

    /**
     * @param  list<string>  $constraints
     * @return list<string>
     */
    private function constraintNeedles(array $constraints): array
    {
        $needles = [];
        foreach ($constraints as $constraint) {
            $norm = $this->lexicalNormalize($constraint);
            if ($norm === '') {
                continue;
            }
            foreach (preg_split('/\s+/u', $norm) ?: [] as $part) {
                if ($part === '' || $this->isNonTechnicalToken($part)) {
                    continue;
                }
                // Wartość techniczna bywa krótsza od czterech znaków („250” g/m², „s3”,
                // „o2”, „a2”) i to właśnie ona decyduje o zgodności. Próg czterech
                // znaków trzymamy tylko dla samych słów, żeby nie wpuszczać skrótów.
                $minLength = preg_match('/\d/u', $part) === 1 ? 2 : 4;
                if (mb_strlen($part) < $minLength) {
                    continue;
                }
                $needles[] = $part;
            }
        }

        return array_values(array_unique($needles));
    }

    private function rankingHaystack(Product $product): string
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];

        return $this->lexicalNormalize(implode(' ', [
            (string) $product->name,
            (string) $product->sku,
            (string) ($product->category ?? ''),
            (string) ($product->description ?? ''),
            (string) ($product->norms ?? ''),
            implode(' ', $this->stringList($payload['specs'] ?? null)),
            implode(' ', $this->stringList($payload['norms'] ?? null)),
            implode(' ', $this->stringList($payload['features'] ?? null)),
            implode(' ', $this->stringList($payload['use_cases'] ?? null)),
        ]));
    }

    /**
     * @param  list<string>  $needles
     */
    private function haystackHasNeedle(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return Collection<int, Product>
     */
    /**
     * Kaskada rodzaju nie może wypchnąć karty znalezionej po kodzie modelu albo progu SNR.
     *
     * @param  Collection<int, Product>  $codeHits
     * @param  Collection<int, Product>  $pool
     * @return Collection<int, Product>
     */
    private function withModelCodeHits(string $requirement, Collection $codeHits, Collection $pool, int $limit): Collection
    {
        return $this->uniqueProducts(
            $this->keepCompatible($requirement, $codeHits)->concat($pool),
            $limit
        )->values();
    }

    private function uniqueProducts(Collection $products, int $limit): Collection
    {
        $seen = [];
        $out = collect();
        foreach ($products as $product) {
            if (! $product instanceof Product || isset($seen[$product->id])) {
                continue;
            }
            $seen[$product->id] = true;
            $out->push($product);
            if ($out->count() >= $limit) {
                break;
            }
        }

        return $out->values();
    }

    /**
     * @param  list<array<string, mixed>>  $ranked
     * @param  Collection<int, Product>  $candidates
     * @return list<array<string, mixed>>
     */
    private function preferNamedModelHits(string $query, array $ranked, Collection $candidates, int $limit): array
    {
        $named = $candidates->filter(
            fn (Product $p): bool => $this->modelFuzzy->matches($query, $p)
        )->values();
        if ($named->isEmpty()) {
            return $ranked;
        }

        $namedIds = [];
        foreach ($named as $product) {
            $namedIds[(int) $product->id] = true;
        }

        $kept = [];
        foreach ($ranked as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0 && isset($namedIds[$id])) {
                $kept[] = $row;
            }
        }
        if ($kept !== []) {
            return array_slice($kept, 0, $limit);
        }

        return $this->rowsFromNamedModels($query, $named, $limit);
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return list<array<string, mixed>>
     */
    private function rowsFromNamedModels(string $query, Collection $products, int $limit): array
    {
        $products = $this->withResponseRelations($products->values());
        $out = [];
        foreach ($products as $product) {
            if (! $product instanceof Product) {
                continue;
            }
            if (! $this->modelFuzzy->matches($query, $product)
                && ! $this->assortment->compatibleProduct($query, $product)) {
                continue;
            }
            $row = $this->productToRow($product);
            $row['ai_match_percent'] = min(99, max(80, $this->modelFuzzy->score($query, $product)));
            $row['ai_match_reason'] = 'Marka i model z SIWZ (literówka w nazwie modelu jest dopuszczalna).';
            $out[] = $row;
        }
        usort($out, function (array $a, array $b) use ($query, $products): int {
            $pa = $products->firstWhere('id', (int) ($a['id'] ?? 0));
            $pb = $products->firstWhere('id', (int) ($b['id'] ?? 0));
            if ($pa instanceof Product && $pb instanceof Product) {
                $byType = $this->namedModelTypeScore($query, $pb) <=> $this->namedModelTypeScore($query, $pa);
                if ($byType !== 0) {
                    return $byType;
                }
                $byModel = $this->modelFuzzy->score($query, $pb) <=> $this->modelFuzzy->score($query, $pa);
                if ($byModel !== 0) {
                    return $byModel;
                }
            }

            return $this->rowPurchasePln($a) <=> $this->rowPurchasePln($b);
        });

        return array_slice($out, 0, max(1, min(80, $limit)));
    }

    /** Dłuższy rzeczownik z SIWZ w nazwie („ogrodniczki”) wyżej niż ogólniejsze „spodnie”. */
    private function namedModelTypeScore(string $query, Product $product): int
    {
        $name = $this->lexicalNormalize($product->name.' '.$product->sku);
        $best = 0;
        foreach ($this->fallbackPhrases($query) as $token) {
            $t = $this->lexicalNormalize($token);
            if ($t === '' || mb_strlen($t) < 4 || ! str_contains($name, $t)) {
                continue;
            }
            $best = max($best, mb_strlen($t));
        }

        return $best;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return list<array<string, mixed>>
     */
    private function rowsFromSnrMatches(string $query, Collection $products, int $limit): array
    {
        $min = $this->bhpAttributes->requiredSnr($query);
        if ($min === null) {
            return [];
        }
        $products = $this->withResponseRelations(
            $products->filter(fn (Product $p): bool => $this->productMeetsSnr($p, $min))->values()
        );
        if ($products->isEmpty()) {
            return [];
        }
        $out = [];
        foreach ($products as $product) {
            if (! $product instanceof Product) {
                continue;
            }
            $row = $this->productToRow($product);
            $row['ai_match_percent'] = min(99, max(80, 80 + intdiv($this->snrRetrieveScore($query, $product), 20)));
            $row['ai_match_reason'] = 'Tłumienie SNR na karcie spełnia próg z SIWZ.';
            $out[] = $row;
        }

        return array_slice($out, 0, max(1, min(80, $limit)));
    }

    private function productMeetsSnr(Product $product, int $min): bool
    {
        $have = $this->bhpAttributes->snrRating($this->filterHaystack($product));

        return $have !== null && $have >= $min;
    }

    /**
     * @return list<string>
     */
    private function modelCodePhrases(string $query): array
    {
        $norm = mb_strtolower($query);
        $norm = preg_replace('/\ben(?:\s*iso)?\s*\d+(?:\s+\d+)*/u', ' ', $norm) ?? $norm;
        $norm = preg_replace('/\biso\s*\d+/u', ' ', $norm) ?? $norm;
        $out = [];
        if (preg_match_all('/\b[a-z]{0,6}\d[a-z0-9\-\/]{1,}\b/u', $norm, $m)) {
            foreach ($m[0] as $raw) {
                $c = preg_replace('/[^a-z0-9]/', '', $raw) ?? '';
                if ($c === '' || mb_strlen($c) < 3) {
                    continue;
                }
                if (mb_strlen($c) < 4 && (ctype_digit($c) || preg_match('/[a-z]/', $c) !== 1)) {
                    continue;
                }
                if (ctype_digit($c) && mb_strlen($c) < 4) {
                    continue;
                }
                $out[] = $c;
            }
        }
        if (preg_match_all('/\b[a-z]\d\b/u', $norm, $short)) {
            foreach ($short[0] as $raw) {
                $c = preg_replace('/[^a-z0-9]/', '', $raw) ?? '';
                if ($c !== '') {
                    $out[] = $c;
                }
            }
        }
        if (preg_match_all('/\b[a-z]{2,5}-[a-z0-9]{1,3}\b/u', $norm, $hyphen)) {
            foreach ($hyphen[0] as $raw) {
                $c = preg_replace('/[^a-z0-9]/', '', $raw) ?? '';
                if (mb_strlen($c) >= 4) {
                    $out[] = $raw;
                    $out[] = $c;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @param  list<string>  $queries
     * @param  array<int, array<string, mixed>>  $intents
     */
    private function prefetchVectorQueries(array $queries, array $intents): void
    {
        if (! $this->vectorSearch->enabled()) {
            return;
        }

        $texts = $queries;
        foreach ($intents as $intent) {
            $needed = trim((string) ($this->normalizeIntent($intent)['needed'] ?? ''));
            if ($needed !== '') {
                $texts[] = $needed;
            }
        }
        $this->vectorSearch->prefetch($texts);
    }

    private function retrieveVectorIds(string $query, int $limit): array
    {
        if (! $this->vectorSearch->enabled()) {
            return [];
        }

        $ids = [];
        foreach ($this->vectorSearch->similar($query, $limit) as $hit) {
            $id = (int) ($hit['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Retrieval — bez zdjęć i zliczeń. Relacje ładujemy dopiero na kartach wyniku.
     *
     * @return Builder<Product>
     */
    private function productBaseQuery()
    {
        return Product::query();
    }

    /**
     * @return Builder<Product>
     */
    private function productResponseQuery()
    {
        return Product::query()
            ->with(['images' => static fn ($img) => $img->orderBy('sort_order')->orderBy('id')])
            ->withCount(['substitutes', 'images']);
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return Collection<int, Product>
     */
    private function withResponseRelations(Collection $products): Collection
    {
        $ids = $products->pluck('id')->filter()->map(intval(...))->all();
        if ($ids === []) {
            return $products;
        }

        $loaded = $this->productResponseQuery()->whereIn('id', $ids)->get()->keyBy('id');

        return $products
            ->map(static fn (Product $p): ?Product => $loaded->get($p->id))
            ->filter(static fn (mixed $p): bool => $p instanceof Product)
            ->values();
    }

    /**
     * @param  array{needed: string, search_phrases: list<string>}  $intent
     * @return array{
     *     query: string,
     *     total: int,
     *     products: list<array<string, mixed>>,
     *     needed: string,
     *     search_phrases: list<string>,
     *     ai_note: string,
     *     external_hint: array{url: string, title: string}|null
     * }
     */
    private function emptyResult(string $query, array $intent, bool $withExternalHint, string $note): array
    {
        $hint = null;
        if ($withExternalHint) {
            $hint = $this->externalHints->hint($query);
            if ($hint !== null) {
                $note .= ' Podpowiedź spoza katalogu (link).';
            }
        }

        return [
            'query' => $this->displayQuery($query, $intent),
            'total' => 0,
            'products' => [],
            'needed' => $intent['needed'],
            'search_phrases' => $intent['search_phrases'],
            'parsed_intent' => $this->publicIntentSlice($intent),
            'ai_note' => $note,
            'external_hint' => $hint,
            'external_hints' => $hint !== null ? [$hint] : [],
        ];
    }

    /**
     * @return array{
     *     query: string,
     *     total: int,
     *     products: list<array<string, mixed>>,
     *     needed: string,
     *     search_phrases: list<string>,
     *     ai_note: string|null,
     *     external_hint: array{url: string, title: string}|null,
     *     external_hints: list<array{url: string, title: string}>
     * }
     */
    private function webOnlyResult(string $query, int $limit): array
    {
        $hints = $this->externalHints->hints($query, min(8, $limit));
        $first = $hints[0] ?? null;

        return [
            'query' => $query,
            'total' => 0,
            'products' => [],
            'needed' => $query,
            'search_phrases' => [],
            'ai_note' => $hints === []
                ? 'Nie znaleziono strony produktu w internecie.'
                : null,
            'external_hint' => $first,
            'external_hints' => $hints,
        ];
    }

    /**
     * @param  Collection<int, Product>  $candidates
     * @param  list<string>  $constraints
     * @return list<array<string, mixed>>
     */
    private function rankWithLlm(
        string $query,
        Collection $candidates,
        int $limit,
        ?string $needed = null,
        AiTask $task = AiTask::ProductSearch,
        array $constraints = [],
    ): array {
        try {
            $raw = $this->llm->chatJson(
                $this->rankMessages($query, $candidates, $limit, $needed, $constraints, $task),
                null,
                $this->rankMaxTokens($task),
                null,
                $task,
            );
        } catch (Throwable) {
            return [];
        }

        return $this->rowsFromLlmMatches($query, $candidates, $raw, $limit, $needed);
    }

    /**
     * Jedno wywołanie: korekta wymagania + ranking kart, które już leżą w puli.
     *
     * @param  Collection<int, Product>  $candidates
     * @param  list<string>  $constraints
     * @return array{0: array{needed: string, search_phrases: list<string>, constraints: list<string>}, 1: list<array<string, mixed>>, 2: bool}
     */
    private function analyzeAndRank(
        string $query,
        Collection $candidates,
        int $limit,
        AiTask $task,
        array $constraints,
        array $retrieveIntent = [],
    ): array {
        try {
            $raw = $this->llm->chatJson(
                $this->analyzeAndRankMessages($query, $candidates, $limit, null, $constraints, $task, $retrieveIntent),
                null,
                $this->rankMaxTokens($task),
                null,
                $task,
            );
        } catch (Throwable $e) {
            Log::warning('product-ai-search.rank-failed', ['message' => $e->getMessage()]);

            return [$this->localIntent($query), [], true];
        }
        $intent = $this->mergeRetrieveIntent($this->parseIntent($raw, $query), $retrieveIntent);

        return [$intent, $this->rowsFromLlmMatches($query, $candidates, $raw, $limit, $intent['needed'], $intent), false];
    }

    /** @param array<string, mixed> $parsed @param array<string, mixed> $retrieve */
    private function mergeRetrieveIntent(array $parsed, array $retrieve): array
    {
        $parsed = $this->normalizeIntent($parsed);
        $retrieve = $this->normalizeIntent($retrieve);
        if ($parsed['search_steps'] === [] && $retrieve['search_steps'] !== []) {
            $parsed['search_steps'] = $retrieve['search_steps'];
        }
        if (! $retrieve['manufacturer_absent_in_catalog']) {
            return $parsed;
        }
        $parsed['manufacturer_absent_in_catalog'] = true;
        if ($retrieve['manufacturer_requested'] !== null) {
            $parsed['manufacturer_requested'] = $retrieve['manufacturer_requested'];
        }
        $parsed['manufacturer'] = null;

        return $this->dropAbsentBrandSteps($parsed);
    }

    /**
     * @param  Collection<int, Product>  $candidates
     * @param  list<string>  $constraints
     * @return list<array{role: string, content: string}>
     */
    private function analyzeAndRankMessages(
        string $query,
        Collection $candidates,
        int $limit,
        ?string $needed,
        array $constraints,
        AiTask $task,
        array $retrieveIntent = [],
    ): array {
        $messages = $this->rankMessages($query, $candidates, $limit, $needed, $constraints, $task, $retrieveIntent);
        $messages[0]['content'] = str_replace(
            'JSON: {"matches":[{"id":1,"score":0-100,"reason":"uzasadnienie"}]}.',
            'needed: krótka nazwa (rzeczownik); search_phrases: 2-8, pierwsze 2 = nazwa; constraints: 0-6. '
            .'Popraw literówki (podnie→spodnie, rekawice→rękawice, kamizelaka→kamizelka, TEPM-ICE→TEMP-ICE). '
            .'JSON: {"needed":"nazwa","search_phrases":["najpierw nazwa"],"constraints":[],'
            .'"matches":[{"id":1,"score":0-100,"reason":"uzasadnienie"}]}.',
            $messages[0]['content'],
        );

        return $messages;
    }

    private function useShortSearchCards(AiTask $task): bool
    {
        return $task === AiTask::ProductSearch && $this->aiSettings->productSearchUsesShortCards();
    }

    private function rankMaxTokens(AiTask $task): int
    {
        return $this->useShortSearchCards($task)
            ? self::RANK_MAX_TOKENS_SHORT
            : self::RANK_MAX_TOKENS_LONG;
    }

    /**
     * @return array<string, mixed>
     */
    private function rankCard(Product $product, bool $short): array
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $card = [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => mb_substr((string) $product->name, 0, $short ? 80 : 120),
            'category' => $product->category,
            'manufacturer' => $product->manufacturer,
            'norms' => $product->norms,
            'heat_celsius' => $this->productHeatCelsius($product),
            'specs' => array_slice($this->stringList($payload['specs'] ?? null), 0, $short ? 2 : 8),
            'use_cases' => array_slice($this->stringList($payload['use_cases'] ?? null), 0, $short ? 2 : 4),
            'payload_norms' => array_slice($this->stringList($payload['norms'] ?? null), 0, 6),
        ];
        if (! $short) {
            $card['description'] = mb_substr((string) ($product->description ?? ''), 0, 360);
            $card['features'] = array_slice($this->stringList($payload['features'] ?? null), 0, 4);
        }

        return $card;
    }

    /** Komplet dwóch krojów/produktów — na teraz i na kolejne SIWZ. */
    private function dualRequirementPromptRule(): string
    {
        return 'Wymaganie podwójne (dwa kroje lub dwa produkty w jednym zapytaniu: „A + B”, „A lub B”, komplet): '
            .'needed i frazy zostaw dla OBU elementów; w matches dawaj oba rodzaje na przemian '
            .'— nie sam jeden krój i nie same najtańsze. Tak samo przy kolejnych takich SIWZ, nie tylko odzież. ';
    }

    private function slangWordProofRule(string $query): string
    {
        $slang = $this->slangRewriteFor($query);
        if ($slang === null) {
            return '';
        }
        $hints = array_slice($slang['search_phrases'], 0, 4);
        if ($hints === []) {
            return '';
        }

        return 'Gdy wymaganie ma żargon/słowo cechy, karta musi mieć to słowo albo synonim ('
            .implode(', ', $hints)
            .') w name/description — sama norma EN nie zastępuje tego słowa. ';
    }

    /**
     * @param  Collection<int, Product>  $candidates
     * @param  list<string>  $constraints
     * @return list<array{role: string, content: string}>
     */
    private function rankMessages(
        string $query,
        Collection $candidates,
        int $limit,
        ?string $needed,
        array $constraints,
        AiTask $task,
        array $retrieveIntent = [],
    ): array {
        $short = $this->useShortSearchCards($task);
        $cards = $candidates->map(fn (Product $p): array => $this->rankCard($p, $short))->values()->all();

        $neededLine = is_string($needed) && trim($needed) !== ''
            ? "\nSzukany produkt (z analizy):\n".trim($needed)
            : '';
        $intent = $this->normalizeIntent($retrieveIntent);
        $intentLine = '';
        $brandRule = 'Marka z wymagania (MSA, 3M, uvex, Portwest…) jest twardym warunkiem — inna marka → nie zwracaj. ';
        if ($intent['manufacturer_absent_in_catalog']) {
            $absentName = trim((string) ($intent['manufacturer_requested'] ?? ''));
            if ($absentName === '') {
                $absentName = 'podanej marki';
            }
            $intentLine .= "\nMarki {$absentName} nie ma w katalogu — rankuj zamienniki tego samego asortymentu. "
                ."Marka i model {$absentName} NIE są warunkiem.";
            $brandRule = "Marki {$absentName} nie ma w katalogu — zwracaj inne marki tego samego produktu. ";
        } elseif ($intent['manufacturer'] !== null && $intent['manufacturer'] !== '') {
            $intentLine .= "\nMarka z analizy SIWZ: ".$intent['manufacturer'];
        }
        if ($intent['model_name'] !== null && $intent['model_name'] !== '') {
            $intentLine .= "\nModel z analizy: ".$intent['model_name'];
        }
        $proofFields = $short
            ? 'norms/specs/payload_norms/use_cases/heat_celsius'
            : 'norms/specs/payload_norms/features/use_cases/description';
        $constraintLine = $constraints === []
            ? ''
            : "\nWarunki, które karta MUSI potwierdzać w {$proofFields} (nie zgaduj):\n- "
                .implode("\n- ", $constraints);
        $maxMatches = max(1, min($limit, self::MAX_MATCHES));
        $json = json_encode($cards, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $reasonHint = $short ? 'reason: max 8 słów. ' : '';

        return [
            [
                'role' => 'system',
                'content' => 'Jesteś ekspertem BHP. Ranking w dwóch krokach — nie mieszaj ich. '
                    .'1) NAZWA: rzeczownik z wymagania = ten sam produkt co w polu name karty '
                    .'(synonimy: buty=obuwie=trzewiki; kurtka≈bluza ochronna; '
                    .'czapka drelichowa=czapka z daszkiem=czapka robocza — drelich to tkanina, nie asortyment). '
                    .'Inny rodzaj → nie zwracaj: kamizelka ≠ osłona twarzy; rękawice ≠ obuwie. '
                    .'Sam ten sam rodzaj (kombinezon, kalosz) NIE wystarczy, gdy wymaganie ma warunek. '
                    .'2) WARUNEK: substancja, stężenie, klasa, typ, norma, napięcie — tylko gdy widać to '
                    .'w polach '.$proofFields.'. '
                    .'Brak potwierdzenia → nie zwracaj (kombinezon pszczelarski / EN 343 ≠ kwas siarkowy). '
                    .'Równoważny dowód = spełnione: synonim katalogowy, norma/klasa, materiał konstrukcyjny '
                    .'(metalowy nosek = podnosek stalowy/steel toe; chemoodporny = EN 374 / Typ 3/4 / Tychem; '
                    .'antystatyczny = EN 1149). Nie wymagaj dosłownego cytatu z SIWZ. '
                    .$this->slangWordProofRule($query)
                    .'Obuwie: antyelektrostatyczne/ESD z SIWZ to nie to samo co antystatyczna podeszwa '
                    .'ani klasa O1/S1/S1P bez ESD w dowodzie — zwracaj tylko przy ESD/antyelektrostat '
                    .'lub EN 1149/61340 w polach dowodu; przy wymaganiu butów gumowych/kaloszy '
                    .'nie zwracaj trzewika/półbuta tylko dlatego, że w opisie jest „funkcja ESD”. '
                    .'Przeciwieństwo cechy (kompozyt vs metal, Typ 6 vs Typ 3) → nie zwracaj. '
                    .'Nie zgaduj z nazwy handlowej. '
                    .'Wspólna cecha (siatkowa) albo przypadkowa norma EN NIE wystarczy. '
                    .$brandRule
                    .'Marka/model z SIWZ wygrywa przy literówce (TEPM-ICE=TEMP-ICE); nie zmieniaj marki przez EN. '
                    .'Pochłaniacz/filtr EN 14387: A2B2E2K2 ≠ A2B2E2K2NO — bez NO/Hg/CO z wymagania nie zwracaj karty. '
                    .'Literówka w wymaganiu nie dyskwalifikuje karty — nazwę czytaj z linii "Szukany produkt (z analizy)" '
                    .'(podnie = spodnie, rekawice = rękawice). '
                    .'Brak zgodnej nazwy albo braku dowodu na warunek: {"matches":[]}. '
                    .'W matches TYLKO id, score, reason — bez sku, name, specs, opisu i karty. '
                    .'JSON: {"matches":[{"id":1,"score":0-100,"reason":"uzasadnienie"}]}. '
                    .$reasonHint
                    .'score>=40 tylko przy zgodnej nazwie I spełnionych warunkach. Max '.$maxMatches.'. '
                    .'Zwróć każdą kartę, która spełnia wymaganie — nie skracaj listy na siłę. '
                    .$this->dualRequirementPromptRule()
                    .'Tylko id z listy. Nie wymyślaj.',
            ],
            [
                'role' => 'user',
                'content' => "Wymaganie:\n{$query}{$neededLine}{$intentLine}{$constraintLine}\n\nKarty katalogu:\n{$json}",
            ],
        ];
    }

    /**
     * @param  Collection<int, Product>  $candidates
     * @param  array<string, mixed>  $raw
     * @return list<array<string, mixed>>
     */
    private function rowsFromLlmMatches(
        string $query,
        Collection $candidates,
        array $raw,
        int $limit,
        ?string $needed,
        ?array $intent = null,
    ): array {
        $intent = $this->normalizeIntent($intent ?? []);
        $requirement = $this->assortmentText($query, $needed);
        $this->traceLlmMatches($raw);
        $matches = is_array($raw['matches'] ?? null) ? $raw['matches'] : [];
        $candidates = $this->withResponseRelations($candidates);
        $byId = $candidates->keyBy('id');
        $out = [];

        foreach ($matches as $m) {
            if (! is_array($m)) {
                continue;
            }
            $id = (int) ($m['id'] ?? 0);
            $score = (int) ($m['score'] ?? 0);
            if ($score <= 0 && $id > 0 && (isset($m['sku']) || isset($m['name']))) {
                $score = 70;
            }
            if ($id <= 0 || $score < 40 || ! $byId->has($id)) {
                continue;
            }
            /** @var Product $product */
            $product = $byId->get($id);
            if (! $this->assortment->compatibleProduct($requirement, $product)
                && ! $this->modelFuzzy->matches($requirement, $product)) {
                continue;
            }
            $brands = $intent['manufacturer_absent_in_catalog']
                ? []
                : $this->modelFuzzy->catalogBrands($requirement);
            if ($brands !== [] && ! $this->modelFuzzy->matchesCatalogBrand($product, $brands)) {
                continue;
            }
            if (! $intent['manufacturer_absent_in_catalog']
                && $this->modelFuzzy->usesModelAnchoredCatalogSearch($requirement)
                && ! $this->modelFuzzy->matches($requirement, $product)) {
                continue;
            }
            if (! $this->filterType->covers($requirement, $this->filterHaystack($product))) {
                continue;
            }
            if (! $this->matchesSlangEvidence($query, $product)) {
                continue;
            }
            $row = $this->productToRow($product);
            $row['ai_match_percent'] = min(99, max(0, $score));
            $row['ai_match_reason'] = is_string($m['reason'] ?? null) ? $m['reason'] : null;
            $out[] = $row;
        }

        return array_slice($this->sortRankedByMatchPercent($out), 0, max(1, min(80, $limit)));
    }

    private function filterHaystack(Product $product): string
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];

        return trim(implode(' ', array_filter([
            (string) $product->name,
            (string) $product->sku,
            (string) ($product->manufacturer ?? ''),
            (string) ($product->description ?? ''),
            (string) ($product->norms ?? ''),
            (string) ($product->search_blob ?? ''),
            ...$this->stringList($payload['features'] ?? null),
            ...$this->stringList($payload['use_cases'] ?? null),
            ...$this->stringList($payload['norms'] ?? null),
            ...$this->stringList($payload['specs'] ?? null),
        ])));
    }

    /** °C z karty — bez folderu sklepu w search_blob („Rękawice termiczne 350°C”). */
    private function heatHaystack(Product $product): string
    {
        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];

        return trim(implode(' ', array_filter([
            (string) $product->name,
            (string) $product->sku,
            (string) ($product->manufacturer ?? ''),
            (string) ($product->description ?? ''),
            (string) ($product->norms ?? ''),
            ...$this->stringList($payload['features'] ?? null),
            ...$this->stringList($payload['use_cases'] ?? null),
            ...$this->stringList($payload['norms'] ?? null),
            ...$this->stringList($payload['specs'] ?? null),
        ])));
    }

    private function productHeatCelsius(Product $product): ?int
    {
        return $this->bhpAttributes->maxCelsius($this->heatHaystack($product), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function productToRow(Product $product): array
    {
        $row = $product->toArray();
        $row['images'] = $product->images->map(static fn ($img): array => [
            'id' => $img->id,
            'url' => $img->url(),
            'source_url' => $img->source_url,
            'is_primary' => $img->is_primary,
            'sort_order' => $img->sort_order,
        ])->values()->all();
        $row['images_count'] = $product->images_count ?? count($row['images']);
        $row['substitutes_count'] = $product->substitutes_count ?? 0;

        return $row;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn ($v): bool => is_string($v) && trim($v) !== ''
        ));
    }
}
