<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\AiTask;
use App\Services\Search\AiProductSearch;
use App\Services\Vector\ProductVectorSearch;
use App\Support\BhpAttributeNormalizer;
use App\Support\CatalogManufacturerContext;
use App\Support\OfferPricing;
use App\Support\PpeAssortment;
use App\Support\ProductFeatureMatch;
use App\Support\ProductModelFuzzy;
use App\Support\ProductSizeVariant;
use App\Support\RequirementCodeNoise;
use App\Support\TechnicalAbbreviations;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class ProductMatchService
{
    /**
     * Progi są domyślne — panel „Strojenie AI” może je przestroić, więc w kodzie
     * czytamy je metodami minMatchScore() / applyMatchScore() / substituteMatchScore().
     * Stałe zostają dla miejsc, które potrzebują wartości bez kontekstu ustawień.
     */

    /** Minimalny wynik dopasowania, poniżej którego nie proponujemy produktu. */
    public const MIN_MATCH_SCORE = AiSettingsService::MATCH_MIN_SCORE_DEFAULT;

    /** Od tego wyniku zapisujemy pierwszy trafiony produkt AI w ofercie. */
    public const APPLY_MATCH_SCORE = AiSettingsService::MATCH_APPLY_SCORE_DEFAULT;

    /** Inna marka/model niż w SIWZ — zapis zamiennika od tego progu (po zgodności rodzaju). */
    public const SUBSTITUTE_MATCH_SCORE = AiSettingsService::MATCH_SUBSTITUTE_SCORE_DEFAULT;

    /**
     * Okno kandydatów AI na pozycję — tyle pierwszych wierszy odpowiedzi wyszukiwarki rozważa
     * dopasowanie (przetarg i pojedyncza pozycja). Przy 5 właściwa karta bywała 8. w rankingu
     * i nigdy nie trafiała pod explainMatch.
     */
    private const AI_CANDIDATE_WINDOW = 10;

    /**
     * Źródła wierszy, które nie są oceną modelu: zapasowa lista katalogowa oraz skróty
     * deterministyczne (klasa obuwia, cut) — wyszukiwarka oznacza je literałem 'rule'
     * (kontrakt W2↔W4: literał, nie stała — pakiety równoległe). Oba traktujemy jednakowo:
     * do pozycji tylko za jawną zgodą admina (match_allow_catalog_rows).
     */
    private const CATALOG_ROW_SOURCES = [ProductAiSearchService::MATCH_SOURCE_CATALOG, 'rule'];

    /** Oceny modelu różniące się o mniej niż 5 pkt to remis (model daje 95 / 90 / 85 — to są różne poziomy). */
    private const MODEL_SCORE_TIE_MARGIN = 4;

    /** Dowody ze słów karty wykluczają kartę dopiero przy takiej różnicy do najlepiej opisanej (ART 702: 35 vs 99). */
    private const EVIDENCE_VETO_GAP = 30;

    /** Progi z ustawień czytamy raz na żądanie — resolve() chodzi do bazy. */
    /** @var array<string, int> */
    private array $matchScores = [];

    /** @var array<string, list<array{id: int, sku: string, name: string, score: int, reason: ?string, source: string}>> */
    private array $aiCandidatesCache = [];

    /**
     * Stan modelu z fali `searchMany` per wymaganie (ProductAiSearchService::MODEL_STATE_*).
     * Opis bez kodu nie może dostać karty „po słowach” z 99%, gdy model nie odpowiedział.
     *
     * @var array<string, string>
     */
    private array $aiModelState = [];

    /** Powód, dla którego bieżąca pozycja zostaje bez produktu (poza „nic nie pasuje”). */
    private ?string $lastNoMatchReason = null;

    /** @var array{sku: string, score: int}|null karta oceniona przez model poniżej progu, której słowa karty nie zapisały */
    private ?array $lastModelLowScore = null;

    /** SKU pierwszej karty pominiętej w propozycji, bo nie ma opisu — trafia do powodu braku karty. */
    private ?string $lastUndescribedSku = null;

    /** Karta wskazana kodem z SIWZ, pominięta, bo nie ma opisu — inna karta automatyczna jej nie zastępuje. */
    private ?int $lastUndescribedCodePickId = null;

    /** Wybór heurystyczny bez potwierdzenia modelu przy opisie bez kodu — tyle najwyżej. */
    private const HEURISTIC_ONLY_CAP = 70;

    private const NO_MATCH_MODEL_UNAVAILABLE = 'model_unavailable';

    /** Najlepsza karta nie ma opisu — decyzja użytkownika (13.09): karta bez opisu nie trafia do propozycji. */
    public const NO_MATCH_NO_DESCRIPTION = 'no_description';

    /** Poprzednia karta zostaje, ale ten przebieg jej nie potwierdził (powód na górze listy). */
    private const NOT_RECONFIRMED = 'not_reconfirmed';

    /** Wybór człowieka (ręczna karta, tańszy zamiennik z porównania) — przebieg go nie tnie. */
    private const USER_DECIDED_SOURCES = ['manual', 'battlecard'];

    /** Karta wskazana kiedyś przez model — wybór po samych słowach karty jej nie wypiera. */
    private const MODEL_PICK_SOURCES = ['ai', 'vector', 'ai_substitute'];

    /** Etap paczki przed modelem: kody z SIWZ i pula kart. */
    public const PROGRESS_STAGE_PREPARE = 'prepare';

    /** Etap paczki po modelu: wybór i zapis pozycji jedna po drugiej. */
    public const PROGRESS_STAGE_SAVE = 'save';

    /** @var array{url: string, title: string}|null */
    private ?array $lastExternalHint = null;

    /** Tokeny zbyt ogólne — nie podbijają score overlap. */
    private const STOPWORDS = [
        'ochronne', 'ochronna', 'ochronny', 'robocze', 'robocza',
        'produkt', 'art', 'kat', 'para', 'par', 'szt', 'sztuk', 'the', 'and', 'for',
        'with', 'bez', 'oraz', 'typ', 'model', 'kolor', 'rozmiar',
        'kieszen', 'rekawy', 'zolta', 'granat', 'bialy', 'damski', 'meski',
    ];

    /** Rozmiary odzieży — nie są SKU ani kodem modelu (np. XXXXL ⊂ 07-755-XXXXL). */
    private const CLOTHING_SIZES = [
        'xxs', 'xs', 'xxl', 'xxxl', 'xxxxl', 'xxxxxl',
        '2xl', '3xl', '4xl', '5xl', '2x', '3x', '4x',
    ];

    /**
     * Typowe słowa SIWZ pisane KAPITALIKAMI — to nie są kody modelu; te same rzeczowniki
     * rodzajowe w nazwie karty nie są też „marką/modelem” (brandModelScore).
     */
    private const GENERIC_SIWZ_CODES = [
        'kurtka', 'bluza', 'spodnie', 'odziez', 'ubranie', 'komplet', 'zestaw',
        'ochronna', 'ochronne', 'robocza', 'robocze', 'odblask', 'ostrzegaw',
        'elektryk', 'spawal', 'laboratory', 'fartuch', 'kamizelka', 'kitel',
        'kombinezon', 'kalesony', 'rekawic', 'oslona', 'przylbica',
        'polmask', 'okular', 'gogl', 'nausznik', 'szelk', 'trzewik',
        'obuwie', 'helm', 'kask', 'kominiarka', 'siatkowa', 'odblaskowa',
        // rdzenie: „szelka/szelkami”, „półmaskami”, „okularów”, „zaworem”, „bezpieczne/bezpieczeństwa”
        'typu', 'zawor', 'soczewk', 'oczy', 'oczu', 'filtrujac', 'bezpieczenstw', 'bezpieczn', 'ochron',
    ];

    /** Oznaczenia klas ochrony (FFP1, S1P, OB, A2, K2) — nie marka ani kod modelu. */
    private const PROTECTION_CLASS_CODE = '/^(?:ffp[1-3]|s[1-7]p?l?|sb|ob|o[1-7]|a[1-3]|b[1-3]|e[1-2]|k[1-2]|p[1-3])$/';

    public function __construct(
        private readonly TenderPricingService $pricing,
        private readonly AiProductSearch $aiSearch,
        private readonly AiSettingsService $aiSettings,
        private readonly ProductVectorSearch $vectorSearch,
        private readonly BhpAttributeNormalizer $bhpAttributes,
        private readonly ProductModelFuzzy $modelFuzzy,
        private readonly PpeAssortment $assortment,
        private readonly CatalogManufacturerContext $manufacturerContext,
        private readonly ProductSizeVariant $sizes,
        private readonly NbpExchangeRateService $fx,
        private readonly ProductFeatureMatch $features,
    ) {}

    /**
     * @param  list<int>|null  $itemIds  null = cała oferta; [] = nic nie ruszaj
     * @return array{
     *     matched: int,
     *     skipped: int,
     *     avg_score: float,
     *     processed: int,
     *     changed: int,
     *     unchanged: int,
     *     cleared: int,
     *     skipped_custom: int,
     *     no_match: int,
     *     model_failed: int,
     *     changes: list<array{id: int, line_no: int, action: string, from_sku: ?string, to_sku: ?string}>
     * }
     */
    public function matchTender(
        Tender $tender,
        bool $onlyEmpty = true,
        ?array $itemIds = null,
        int $progressOffset = 0,
        ?int $progressTotal = null,
    ): array {
        $matched = 0;
        $skipped = 0;
        $scores = [];

        $emptyReport = [
            'matched' => 0,
            'skipped' => 0,
            'avg_score' => 0.0,
            'processed' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'cleared' => 0,
            'skipped_custom' => 0,
            'no_match' => 0,
            'model_failed' => 0,
            'changes' => [],
        ];

        if ($itemIds !== null) {
            $itemIds = array_values(array_unique(array_map('intval', $itemIds)));
            if ($itemIds === []) {
                $this->writeMatchProgress((int) $tender->id, 0, 0, 'done', null, null);

                return $emptyReport;
            }
        }

        // onlyEmpty: puste + stare słabe propozycje (< progu) — żeby nie zostawały buty przy 34%
        $items = $tender->items()
            ->with('mainProduct')
            ->when($itemIds !== null, fn ($q) => $q->whereIn('id', $itemIds))
            ->when(
                $onlyEmpty,
                fn ($q) => $q->where(function ($q) {
                    $q->where(function ($w) {
                        $w->whereNull('custom_name')->orWhere('custom_name', '');
                    })->where(function ($q) {
                        $q->whereNull('main_product_id')
                            ->orWhere(function ($w) {
                                $w->whereNotNull('ai_match_percent')
                                    ->where('ai_match_percent', '<', $this->minMatchScore())
                                    // Wybór ręczny i z battlecard to decyzja użytkownika — „Dopasuj AI (puste)” obiecuje,
                                    // że zapisanych nie rusza (recenzja dopasowania 13.09, błąd C).
                                    ->where(function ($source) {
                                        $source->whereNull('match_source')
                                            ->orWhereNotIn('match_source', self::USER_DECIDED_SOURCES);
                                    });
                            });
                    });
                })
            )->get();

        // Postęp startuje przed modelem — ranking całej paczki trwa minuty i bez etapu okno
        // stało na „0 / N”, a potem skakało od razu do końca.
        $startedAt = time();
        $batchCount = $items->count();
        $displayTotal = $progressTotal ?? $batchCount;
        $done = 0;
        $tenderId = (int) $tender->id;
        $stageProgress = function (string $stage, int $stageDone, int $stageTotal) use ($tenderId, $progressOffset, $displayTotal, $startedAt): void {
            $this->writeMatchProgress($tenderId, $progressOffset, $displayTotal, 'running', null, null, $startedAt, $stage, $stageDone, $stageTotal);
        };
        $stageProgress(self::PROGRESS_STAGE_PREPARE, 0, $batchCount);

        $products = $this->productsForItems($items);

        $this->prefetchAiCandidates(
            $items
                ->filter(fn (TenderItem $item): bool => ! $item->isManualCustomOffer())
                ->filter(function (TenderItem $item) use ($products): bool {
                    $skuPick = $this->strongSkuPick($item->requirement, $products);
                    if ($skuPick === null) {
                        return true;
                    }

                    return $this->persistableScore(
                        $item->requirement,
                        $skuPick['product'],
                        $skuPick['score']
                    ) === null;
                })
                ->map(static fn (TenderItem $item): string => $item->requirement)
                ->all(),
            $stageProgress,
        );

        $stageProgress(self::PROGRESS_STAGE_SAVE, 0, $batchCount);

        $changes = [];
        $modelUnavailable = 0;
        $modelFailed = 0;
        foreach ($items as $item) {
            $beforeId = $item->main_product_id !== null ? (int) $item->main_product_id : null;
            $beforeSku = $item->mainProduct?->sku;
            if ($item->isManualCustomOffer()) {
                $skipped++;
                $changes[] = $this->matchChangeRow($item, 'skipped_custom', $beforeSku, $beforeSku);
            } else {
                $this->lastExternalHint = null;
                $this->lastNoMatchReason = null;
                $this->lastModelLowScore = null;
                $this->lastUndescribedSku = null;
                $this->lastUndescribedCodePickId = null;
                $pick = $this->resolveBestPick($item->requirement, $products);
                // Zapytanie do modelu padło (limit tempa, timeout, błąd API). Liczymy każdą taką pozycję,
                // także tę, która zachowała poprzednią kartę — inaczej awaria dostawcy wygląda w raporcie
                // jak spadek jakości dopasowania (15.09: wszystkie pozycje po 70% przy HTTP 429).
                if ($this->modelStateFor($item->requirement) === ProductAiSearchService::MODEL_STATE_UNAVAILABLE) {
                    $modelFailed++;
                }
                $applied = $pick !== null && ! $this->heuristicWouldReplaceModelPick($item, $pick) && $this->applyProduct(
                    $item,
                    $pick['product'],
                    $pick['score'],
                    $pick['source'] ?? 'heuristic',
                    // Oceny, z których wybór karty właśnie skorzystał — czytane z pamięci, bez nowego
                    // zapytania do modelu (karta wskazana kodem z SIWZ w ogóle modelu nie pyta).
                    $this->modelReasonForPick($pick, $this->aiCandidatesCache[$this->aiCandidatesCacheKey($item->requirement)] ?? []),
                    (bool) ($pick['heuristic_only'] ?? false),
                );
                if (! $applied) {
                    if ($this->lastNoMatchReason === self::NO_MATCH_MODEL_UNAVAILABLE) {
                        $modelUnavailable++;
                    }
                    $this->applyNoCatalogMatch($item, $products);
                    $item->refresh();
                    if ($item->hasCustomOffer()) {
                        $matched++;
                        $changes[] = $this->matchChangeRow($item, 'changed', $beforeSku, $item->custom_name);
                    } elseif ($beforeId !== null && (int) $item->main_product_id === $beforeId) {
                        $skipped++;
                        $changes[] = $this->matchChangeRow($item, 'unchanged', $beforeSku, $beforeSku);
                    } elseif ($beforeId !== null && $item->main_product_id === null) {
                        $skipped++;
                        $changes[] = $this->matchChangeRow($item, 'cleared', $beforeSku, null);
                    } else {
                        $skipped++;
                        $changes[] = $this->matchChangeRow($item, 'no_match', $beforeSku, null);
                    }
                } else {
                    $matched++;
                    $scores[] = (int) $item->ai_match_percent;
                    $afterSku = $pick['product']->sku;
                    $action = $beforeId === (int) $pick['product']->id ? 'unchanged' : 'changed';
                    $changes[] = $this->matchChangeRow($item, $action, $beforeSku, $afterSku);
                }
            }
            $done++;
            $this->writeMatchProgress(
                (int) $tender->id,
                $progressOffset + $done,
                $displayTotal,
                'running',
                (int) $item->line_no,
                (string) $item->requirement,
                $startedAt,
                self::PROGRESS_STAGE_SAVE,
                $done,
                $batchCount,
            );
        }

        $finished = ($progressOffset + $batchCount) >= $displayTotal;
        $this->writeMatchProgress(
            (int) $tender->id,
            $progressOffset + $batchCount,
            $displayTotal,
            $finished ? 'done' : 'running',
            null,
            null,
            $startedAt
        );

        $allScores = $tender->items()->whereNotNull('ai_match_percent')->pluck('ai_match_percent');
        $avgAll = $allScores->isEmpty() ? 0.0 : (float) $allScores->avg();

        $tender->ai_percent = (int) round($avgAll);
        $tender->last_activity_at = now();
        if ($tender->status === 'draft') {
            $tender->status = 'wycena';
        }
        $tender->save();

        $this->pricing->recalculateTenderTotals($tender->fresh(['items.mainProduct']));

        $avg = $scores === [] ? 0.0 : array_sum($scores) / count($scores);

        $changedRows = array_values(array_filter(
            $changes,
            static fn (array $row): bool => in_array($row['action'], ['changed', 'cleared'], true)
        ));

        $processedIds = $items
            ->filter(static fn (TenderItem $item): bool => ! $item->isManualCustomOffer())
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        return [
            'matched' => $matched,
            'skipped' => $skipped,
            'avg_score' => round($avg, 1),
            'processed' => count($changes),
            'changed' => count($changedRows),
            'unchanged' => count(array_filter($changes, static fn (array $r): bool => $r['action'] === 'unchanged')),
            'cleared' => count(array_filter($changes, static fn (array $r): bool => $r['action'] === 'cleared')),
            'skipped_custom' => count(array_filter($changes, static fn (array $r): bool => $r['action'] === 'skipped_custom')),
            'no_match' => count(array_filter($changes, static fn (array $r): bool => $r['action'] === 'no_match')),
            // pozycje bez produktu, bo model nie odpowiedział — do ponowienia, nie „brak w katalogu”
            'model_unavailable' => $modelUnavailable,
            // wszystkie pozycje, przy których model nie odpowiedział — także te z zachowaną kartą
            'model_failed' => $modelFailed,
            'changes' => $changedRows,
            'processed_item_ids' => $processedIds,
        ];
    }

    public static function progressCacheKey(int $tenderId): string
    {
        return 'tender-match-progress:'.$tenderId;
    }

    /**
     * @return array{status: string, done: int, total: int, line_no: int|null, requirement: string|null, started_at: int|null, stage: string|null, stage_done: int, stage_total: int}
     */
    public static function readMatchProgress(int $tenderId): array
    {
        $raw = Cache::get(self::progressCacheKey($tenderId));
        if (! is_array($raw)) {
            return [
                'status' => 'idle',
                'done' => 0,
                'total' => 0,
                'line_no' => null,
                'requirement' => null,
                'started_at' => null,
                'stage' => null,
                'stage_done' => 0,
                'stage_total' => 0,
            ];
        }

        return [
            'status' => is_string($raw['status'] ?? null) ? $raw['status'] : 'idle',
            'done' => (int) ($raw['done'] ?? 0),
            'total' => (int) ($raw['total'] ?? 0),
            'line_no' => isset($raw['line_no']) && is_numeric($raw['line_no']) ? (int) $raw['line_no'] : null,
            'requirement' => is_string($raw['requirement'] ?? null) ? $raw['requirement'] : null,
            'started_at' => isset($raw['started_at']) && is_numeric($raw['started_at']) ? (int) $raw['started_at'] : null,
            'stage' => is_string($raw['stage'] ?? null) ? $raw['stage'] : null,
            'stage_done' => (int) ($raw['stage_done'] ?? 0),
            'stage_total' => (int) ($raw['stage_total'] ?? 0),
        ];
    }

    private function writeMatchProgress(
        int $tenderId,
        int $done,
        int $total,
        string $status,
        ?int $lineNo,
        ?string $requirement,
        ?int $startedAt = null,
        ?string $stage = null,
        int $stageDone = 0,
        int $stageTotal = 0,
    ): void {
        Cache::put(self::progressCacheKey($tenderId), [
            'status' => $status,
            'done' => $done,
            'total' => $total,
            'line_no' => $lineNo,
            'requirement' => $requirement !== null ? mb_substr($requirement, 0, 120) : null,
            'started_at' => $startedAt ?? time(),
            'stage' => $stage,
            'stage_done' => $stageDone,
            'stage_total' => $stageTotal,
        ], 1800);
    }

    /**
     * @return array{id: int, line_no: int, action: string, from_sku: ?string, to_sku: ?string}
     */
    private function matchChangeRow(TenderItem $item, string $action, ?string $fromSku, ?string $toSku): array
    {
        return [
            'id' => (int) $item->id,
            'line_no' => (int) $item->line_no,
            'action' => $action,
            'from_sku' => $fromSku,
            'to_sku' => $toSku,
        ];
    }

    /** Poniżej tego wyniku nie proponujemy produktu (panel: Strojenie AI). */
    public function minMatchScore(): int
    {
        return $this->matchScores['min'] ??= $this->aiSettings->matchMinScore();
    }

    /** Od tego wyniku zapisujemy trafiony produkt w pozycji oferty. */
    public function applyMatchScore(): int
    {
        return $this->matchScores['apply'] ??= $this->aiSettings->matchApplyScore();
    }

    /** Próg zapisu zamiennika — innej marki/modelu niż w SIWZ. */
    public function substituteMatchScore(): int
    {
        return $this->matchScores['substitute'] ??= $this->aiSettings->matchSubstituteScore();
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array{product: Product, score: int}|null
     */
    public function bestMatch(string $requirement, Collection $products): ?array
    {
        $ranked = $this->rankProducts($requirement, $products, 1);

        return $ranked[0] ?? null;
    }

    /**
     * Ranking produktów wg heurystyki match (bez AI).
     *
     * @param  Collection<int, Product>  $products
     * @return list<array{product: Product, score: int}>
     */
    public function rankProducts(string $requirement, Collection $products, int $limit = 5): array
    {
        $req = $this->normalize($requirement);
        $reqTokens = $this->significantTokens($req);
        $reqCodes = $this->codeCandidates($requirement);
        if ($this->modelFuzzy->hasNamedModel($requirement)) {
            $named = $products->filter(
                fn (Product $p): bool => $this->modelFuzzy->matches($requirement, $p)
            );
            if ($named->isNotEmpty()) {
                $compatibleNamed = $named->filter(
                    fn (Product $p): bool => $this->assortment->compatibleProduct($requirement, $p)
                );
                $products = $compatibleNamed->isNotEmpty() ? $compatibleNamed : $products;
            }
        }
        $scored = [];

        foreach ($products as $product) {
            $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
            $materials = is_array($payload['materials'] ?? null) ? $payload['materials'] : [];
            $features = is_array($payload['features'] ?? null) ? $payload['features'] : [];
            $useCases = is_array($payload['use_cases'] ?? null) ? $payload['use_cases'] : [];
            $normsPayload = is_array($payload['norms'] ?? null) ? $payload['norms'] : [];

            $attrs = $this->bhpAttributes->forProduct($product);
            $extra = implode(' ', [
                (string) ($product->description ?? ''),
                implode(' ', $features),
                implode(' ', $useCases),
                implode(' ', $materials),
                implode(' ', $normsPayload),
                $this->bhpAttributes->toSearchText($attrs),
            ]);
            $hay = $this->normalize(
                $product->name.' '.$product->sku.' '.$product->manufacturer.' '
                .($product->norms ?? '').' '.($product->category ?? '').' '.$extra
            );
            if (! $this->assortment->compatibleProduct($requirement, $product)) {
                continue;
            }
            $score = $this->score($req, $reqTokens, $reqCodes, $hay, $product, $materials, $attrs);
            $fuzzy = $this->modelFuzzy->score($requirement, $product);
            if ($fuzzy >= 80) {
                $score = max($score, $fuzzy);
            }
            // Komplet z SIWZ („bluza + spodnie”) pokrywa się dwiema kartami. Pojedyncza
            // sztuka to połowa wymagania, więc sama nie może przejść progu dopasowania —
            // od kompletowania jest produkt towarzyszący przy pozycji oferty.
            if ($this->assortment->isApparelSet($requirement) && ! $this->assortment->isApparelSet($hay)) {
                $score = min($score, $this->minMatchScore() - 1);
            }
            $scored[] = ['product' => $product, 'score' => $score];
        }

        $scored = $this->preferCheapestSizeVariants($scored);
        $minScore = $this->minMatchScore();
        $qualified = array_values(array_filter(
            $scored,
            static fn (array $row): bool => $row['score'] >= $minScore
        ));
        $pool = $qualified !== [] ? $qualified : $scored;
        usort($pool, function (array $a, array $b): int {
            $byPrice = $this->purchasePln($a['product']) <=> $this->purchasePln($b['product']);
            if ($byPrice !== 0) {
                return $byPrice;
            }

            return $b['score'] <=> $a['score'];
        });
        $scored = $pool;

        return array_slice($scored, 0, max(1, $limit));
    }

    /**
     * W grupie rozmiarów (ten sam model) zostaw najtańszy, gdy wynik jest zbliżony.
     *
     * @param  list<array{product: Product, score: int}>  $scored
     * @return list<array{product: Product, score: int}>
     */
    private function preferCheapestSizeVariants(array $scored): array
    {
        $best = [];
        foreach ($scored as $row) {
            /** @var Product $product */
            $product = $row['product'];
            $key = $this->sizes->groupKey(
                (string) $product->manufacturer,
                (string) $product->name,
                (string) $product->sku,
                $product->packaging !== null ? (string) $product->packaging : null,
            ) ?? 'id:'.$product->id;
            if (! isset($best[$key]) || $this->isPreferredVariant($row, $best[$key])) {
                $best[$key] = $row;
            }
        }

        return array_values($best);
    }

    /**
     * @param  array{product: Product, score: int}  $challenger
     * @param  array{product: Product, score: int}  $incumbent
     */
    private function isPreferredVariant(array $challenger, array $incumbent): bool
    {
        $cs = $challenger['score'];
        $is = $incumbent['score'];
        if ($cs > $is + 8) {
            return true;
        }
        if ($is > $cs + 8) {
            return false;
        }
        $cp = $this->purchasePln($challenger['product']);
        $ip = $this->purchasePln($incumbent['product']);
        if ($cp === $ip) {
            return $cs > $is;
        }

        return $cp < $ip;
    }

    /**
     * Rozstrzyga między kandydatami, które przeszły progi. Kolejność:
     *  1. ocena modelu — najwyższy poziom (okno MODEL_SCORE_TIE_MARGIN);
     *  2. dowody ze słów karty tylko jako weto — odpada karta słabsza od najlepiej opisanej o EVIDENCE_VETO_GAP
     *     (ART 702 z dowodami 35 nie wygra ceną z T5912100 z dowodami 99 przy równych 92%);
     *  3. twarde dowody (SKU / model z cyframi / klasa ochrony);
     *  4. komplet norm — gdy któraś karta podaje wszystkie normy wymienione w przetargu, karty bez kompletu odpadają
     *     (pomiar 20260914_200209 poz. 6: SWIFTN20E z samą EN 166 wygrała ceną z Rush+ z EN 166, EN 172 i EN ISO 16321-1);
     *  5. najniższa cena; remis — wyższa ocena, potem dowody.
     * Dotąd dowody ze słów (nieskalibrowana suma) rozstrzygały pierwsze w oknie 5 pkt: przy ogólnym wymaganiu
     * (przetarg 1 poz. 2 — model dał 95 osiemnastu rękawicom antyprzecięciowym) wygrywała karta z najdłuższym opisem
     * (ATG 76-833, rękawica chemiczna 35 cm, 59 zł) zamiast pasującej i najtańszej (Canis 3630-024-700-00, 6 zł).
     *
     * @param  list<array{product: Product, score: int, source: string, evidence: int, hard: int, norms_complete?: bool}>  $options
     * @return array{product: Product, score: int, source: string}|null
     */
    private function preferCheapestAmongCloseScores(array $options): ?array
    {
        if ($options === []) {
            return null;
        }
        $topScore = max(array_column($options, 'score'));
        $near = array_values(array_filter(
            $options,
            static fn (array $option): bool => $option['score'] >= $topScore - self::MODEL_SCORE_TIE_MARGIN
        ));
        $topEvidence = max(array_column($near, 'evidence'));
        $near = array_values(array_filter(
            $near,
            static fn (array $option): bool => $option['evidence'] >= $topEvidence - self::EVIDENCE_VETO_GAP
        ));
        $topHard = max(array_column($near, 'hard'));
        $near = array_values(array_filter(
            $near,
            static fn (array $option): bool => $option['hard'] === $topHard
        ));
        $complete = array_values(array_filter(
            $near,
            static fn (array $option): bool => ($option['norms_complete'] ?? false) === true
        ));
        if ($complete !== []) {
            $near = $complete;
        }
        usort($near, function (array $a, array $b): int {
            $byPrice = $this->purchasePln($a['product']) <=> $this->purchasePln($b['product']);
            if ($byPrice !== 0) {
                return $byPrice;
            }
            if ($a['score'] !== $b['score']) {
                return $b['score'] <=> $a['score'];
            }

            return $b['evidence'] <=> $a['evidence'];
        });

        return [
            'product' => $near[0]['product'],
            'score' => $near[0]['score'],
            'source' => $near[0]['source'],
        ];
    }

    /**
     * Czy karta (nazwa, normy, opis) podaje każdy numer normy EN wymieniony w przetargu. Przetarg bez norm — true dla
     * wszystkich, więc krok nic nie zmienia. EN 420 zastąpiła EN ISO 21420 — karta z nowszą normą spełnia starszy zapis.
     * Brak normy na karcie to brak informacji, nie sprzeczność: taka karta nie odpada w bramce, tylko przegrywa remis
     * z kartą, która normy podaje.
     */
    private function showsAllRequiredNorms(string $requirement, Product $product): bool
    {
        $required = $this->features->norms($requirement);
        if ($required === []) {
            return true;
        }
        $shown = $this->features->norms(implode(' ', [
            (string) $product->name,
            (string) ($product->norms ?? ''),
            (string) ($product->description ?? ''),
        ]));
        if (in_array('21420', $shown, true)) {
            $shown[] = '420';
        }

        return array_diff($required, $shown) === [];
    }

    /** Wiersz listy katalogowej albo skrótu deterministycznego — nie ocena modelu. */
    private function isCatalogRowSource(string $source): bool
    {
        return in_array($source, self::CATALOG_ROW_SOURCES, true);
    }

    /**
     * Czy procentowi wiersza wolno ufać jak ocenie modelu w persistableScore: ocena modelu
     * (ai/vector, także zamiennik) — tak; wiersz katalogowy/skrótu — tylko za jawną zgodą admina.
     */
    private function trustsRowScore(string $source): bool
    {
        return in_array($source, ['ai', 'vector', 'ai_substitute'], true)
            || ($this->isCatalogRowSource($source) && $this->aiSettings->matchAllowsCatalogRows());
    }

    /** Trafienie SKU/kodu albo modelu z SIWZ — to samo, co waży persistableScore. */
    private function skuishScore(string $requirement, Product $product): int
    {
        return max(
            $this->skuMatchScore(
                $this->normalize($requirement),
                $this->codeCandidates($requirement),
                $product
            ),
            $this->modelFuzzy->score($requirement, $product)
        );
    }

    /**
     * Próg zapisu (D1): trafienie zapisujemy od minMatchScore. Poniżej tylko wyjątkowo —
     * trafienie SKU/kodu (skuish ≥ 70, także bez cyfr: RNITZ) albo wiersz katalogowy przy jawnym
     * match_allow_catalog_rows=true (próg apply — świadoma decyzja admina). Inaczej pozycja zostaje
     * „brak”: 58% to nie dopasowanie, a brak informacji nie może wyglądać jak fakt. Liczone
     * po persistableScore, którego semantyka (pinowane 45 i 94) zostaje bez zmian.
     */
    private function meetsPersistThreshold(string $requirement, Product $product, int $honest, string $source): bool
    {
        if ($honest >= $this->minMatchScore()) {
            return true;
        }
        if ($this->skuishScore($requirement, $product) >= 70) {
            return true;
        }

        return $this->isCatalogRowSource($source) && $this->aiSettings->matchAllowsCatalogRows();
    }

    /**
     * Twarde dowody na karcie: trafienie SKU/kodu (4), model z SIWZ z cyframi (2), klasa ochrony
     * z atrybutów (1). Różnica w nich wyklucza rozstrzyganie ceną między bliskimi wynikami explain.
     *
     * @param  array{score: int, reasons: list<array{code: string, label: string, points: int}>}  $explained
     */
    private function hardEvidenceLevel(string $requirement, Product $product, array $explained): int
    {
        $level = 0;
        if ($this->skuMatchScore($this->normalize($requirement), $this->codeCandidates($requirement), $product) >= 70) {
            $level |= 4;
        }
        if ($this->modelFuzzy->score($requirement, $product) >= 80) {
            foreach ($this->modelFuzzy->catalogModelNeedles($requirement) as $needle) {
                if (preg_match('/\d/', $needle) === 1) {
                    $level |= 2;
                    break;
                }
            }
        }
        foreach ($explained['reasons'] as $reason) {
            if (($reason['code'] ?? '') === 'attr_klasa') {
                $level |= 1;
                break;
            }
        }

        return $level;
    }

    private function purchasePln(Product $product): float
    {
        $pln = $this->fx->purchasePln($product);
        if ($pln !== null && $pln > 0) {
            return $pln;
        }
        $raw = (float) ($product->purchase_price ?? 0);
        if ($raw > 0) {
            return $raw;
        }

        return PHP_FLOAT_MAX;
    }

    private function familyFromKategoria(mixed $kategoria): ?string
    {
        return $this->assortment->familyFromKategoria(is_string($kategoria) ? $kategoria : null);
    }

    private function detectAssortmentFamily(string $text): ?string
    {
        return $this->assortment->family($text);
    }

    /**
     * @param  list<string>  $reqTokens
     * @param  list<string>  $reqCodes
     * @param  list<string>  $materials
     * @param  array<string, mixed>  $attrs
     */
    private function score(
        string $req,
        array $reqTokens,
        array $reqCodes,
        string $hay,
        Product $product,
        array $materials,
        array $attrs = [],
    ): int {
        $score = 0;
        $skuHit = $this->skuMatchScore($req, $reqCodes, $product);
        $score += $skuHit;
        $score += $this->typeNameScore($req, $product);

        // bez sensownego SKU — dopasowanie po wymaganiach / materiale / marce / kodzie w opisie
        $score += $this->materialRequirementScore($req, $hay, $materials);
        $score += $this->brandModelScore($reqTokens, $hay, $product);
        if ($skuHit === 0) {
            $score += $this->modelCodeInTextScore($reqCodes, $hay);
        }

        $nameNorm = $this->normalize($product->name);
        if ($nameNorm !== '' && mb_strlen($nameNorm) >= 5
            && (str_contains($req, $nameNorm) || str_contains($nameNorm, $req))) {
            $score += 35;
        }

        $score += $this->attributeMatchScore($req, $product, $attrs)['points'];

        $hayTokens = $this->significantTokens($hay);
        $overlap = count(array_intersect($reqTokens, $hayTokens));
        // overlap tylko jako drobny bonus — nie może sam „przepchnąć” ponad próg
        $score += min(16, $overlap * 4);

        // similar_text na długim opisie zawyża wynik — ograniczamy mocno
        if ($skuHit === 0) {
            similar_text($req, mb_substr($hay, 0, 220), $pct);
            $score += (int) round($pct * 0.12);
        }

        return min(99, $score);
    }

    /**
     * Scoring po kanonicznych atrybutach BHP (normy EN, klasa, materiał, kod).
     *
     * @param  array<string, mixed>  $attrs
     * @return array{points: int, reasons: list<array{code: string, label: string, points: int}>}
     */
    private function attributeMatchScore(string $req, Product $product, array $attrs): array
    {
        if ($attrs === []) {
            $attrs = $this->bhpAttributes->forProduct($product);
        }

        $reasons = [];
        $points = 0;
        $reqCompact = preg_replace('/\s+/', '', $req) ?? $req;

        $normy = is_array($attrs['normy_en'] ?? null) ? $attrs['normy_en'] : [];
        $normHay = preg_replace(
            '/\s+/',
            '',
            mb_strtolower(implode(' ', $normy).' '.(string) ($product->norms ?? ''))
        ) ?? '';
        $normPts = 0;
        if (preg_match_all('/en(?:iso)?\s*[\d]+/i', $req, $m)) {
            foreach ($m[0] as $norm) {
                $n = preg_replace('/\s+/', '', mb_strtolower($norm)) ?? '';
                if ($n !== '' && $normHay !== '' && str_contains($normHay, $n)) {
                    $normPts += 22;
                }
            }
        }
        if ($normPts > 0) {
            $normPts = min(44, $normPts);
            $reasons[] = ['code' => 'attr_norma', 'label' => 'Norma EN (atrybuty)', 'points' => $normPts];
            $points += $normPts;
        }

        $klasa = is_string($attrs['klasa_ochrony'] ?? null) ? mb_strtolower((string) $attrs['klasa_ochrony']) : '';
        if ($klasa !== '' && $this->classMatchesRequirement($req, $klasa)
            // Klasa OB przy „półbuty elektroizolacyjne 20 kV” nic nie dowodzi, gdy karta nie pokazuje elektroizolacji.
            && $this->assortment->productMeetsElectricalInsulationRequirement($req, $product)) {
            $reasons[] = ['code' => 'attr_klasa', 'label' => 'Klasa ochrony ('.$attrs['klasa_ochrony'].')', 'points' => 18];
            $points += 18;
        }

        $en388 = is_string($attrs['poziomy_en388'] ?? null) ? mb_strtoupper((string) $attrs['poziomy_en388']) : '';
        if ($en388 !== '' && str_contains(mb_strtoupper($reqCompact), $en388)) {
            $reasons[] = ['code' => 'attr_en388', 'label' => 'Poziomy EN 388 ('.$en388.')', 'points' => 16];
            $points += 16;
        }

        $material = is_string($attrs['material'] ?? null) ? $this->normalize((string) $attrs['material']) : '';
        if ($material !== '' && mb_strlen($material) >= 3 && str_contains($req, $material)) {
            $reasons[] = ['code' => 'attr_material', 'label' => 'Materiał kanoniczny ('.$attrs['material'].')', 'points' => 14];
            $points += 14;
        }

        $kod = is_string($attrs['kod_producenta'] ?? null) ? $this->normalize((string) $attrs['kod_producenta']) : '';
        $kodCompact = preg_replace('/\s+/', '', $kod) ?? $kod;
        if ($kodCompact !== '' && mb_strlen($kodCompact) >= 4 && str_contains($reqCompact, $kodCompact)
            && $kodCompact !== $this->normalize($product->sku)) {
            $reasons[] = ['code' => 'attr_kod', 'label' => 'Kod producenta (atrybuty)', 'points' => 12];
            $points += 12;
        }

        return ['points' => min(60, $points), 'reasons' => $reasons];
    }

    /**
     * Klasa karty trafia w SIWZ. Dla klas obuwia liczy się klasa z wymagania („S1 P” = S1P),
     * a klasa niższa niż wymagana (S1 przy S1P) nie jest dowodem.
     */
    private function classMatchesRequirement(string $req, string $klasa): bool
    {
        $reqClass = $this->bhpAttributes->footwearClass($req);
        $haveClass = $this->bhpAttributes->footwearClass($klasa);
        if ($reqClass !== null && $haveClass !== null) {
            return $this->bhpAttributes->footwearClassMeets($reqClass, $haveClass);
        }

        return str_contains($req, $this->normalize($klasa))
            || preg_match('/\b'.preg_quote($klasa, '/').'\b/u', $req) === 1;
    }

    /**
     * 1) dokładny SKU w SIWZ, 2) mocny kod modelowy — bez „600” ⊂ „60028”.
     *
     * @param  list<string>  $reqCodes
     */
    private function skuMatchScore(string $req, array $reqCodes, Product $product): int
    {
        $skuNorm = $this->normalize($product->sku);
        $skuCompact = preg_replace('/\s+/', '', $skuNorm) ?? $skuNorm;
        if ($skuCompact === '') {
            return 0;
        }

        $reqNoNorms = $this->stripNormNumbers($req);

        // pełny SKU jako osobny token — POLA nie trafia w POLAR
        if (mb_strlen($skuCompact) >= 4 && preg_match(
            '/(^|[^a-z0-9])'.preg_quote($skuCompact, '/').'([^a-z0-9]|$)/u',
            $reqNoNorms
        ) === 1) {
            return 85;
        }

        foreach ($reqCodes as $code) {
            if ($this->codesMatch($skuCompact, $code)) {
                return mb_strlen($code) >= 5 ? 80 : 70;
            }
        }

        return 0;
    }

    private function codesMatch(string $skuCompact, string $code): bool
    {
        if ($code === '' || $skuCompact === '') {
            return false;
        }
        if ($code === $skuCompact) {
            return true;
        }

        // cyfry: 600 ⊄ 60028, ale 6503 ⊂ 6503-EN / 6503QL-EN (kolejny znak to nie cyfra)
        if (ctype_digit($code)) {
            if ($code === $skuCompact) {
                return true;
            }
            if (mb_strlen($code) < 4) {
                return false;
            }
            if (str_starts_with($skuCompact, $code)) {
                $next = mb_substr($skuCompact, mb_strlen($code), 1);

                return $next !== '' && ! ctype_digit($next);
            }

            return false;
        }

        // alfanumeryczny model (RNITZ, RDR…): równość lub SKU zaczyna/kończy się kodem przy podobnej długości
        if (mb_strlen($code) < 4) {
            return false;
        }
        if ($skuCompact === $code) {
            return true;
        }
        if (str_starts_with($skuCompact, $code) || str_ends_with($skuCompact, $code)) {
            return abs(mb_strlen($skuCompact) - mb_strlen($code)) <= 2;
        }

        return false;
    }

    /**
     * @param  list<string>  $materials
     */
    private function materialRequirementScore(string $req, string $hay, array $materials): int
    {
        $score = 0;
        $materialHints = [
            'nitryl' => ['nitryl', 'nitrile', 'nbr', 'rnitz'],
            'lateks' => ['lateks', 'latex'],
            'skorz' => ['skorz', 'leather', 'koz'],
            'poliuretan' => ['poliuretan', ' polyurethane', ' pu ', 'powlek'],
            'neopren' => ['neopren', 'neoprene'],
            'pvc' => [' pvc', 'pcv'],
            'sciagacz' => ['sciagacz', 'sciagaczem', 'cuff', 'sciag'],
            'powlek' => ['powlek', 'coated'],
            'ocieplan' => ['ocieplan', 'winter', 'thermo', 'zimow'],
            'antyprzecieciow' => ['antyprzecieciow', 'cut', 'powercut', 'krytech', 'unidur'],
            'chemoodporn' => ['chemoodporn', 'alphatec', 'chemic'],
        ];

        $matNorm = $this->normalize(implode(' ', $materials));

        foreach ($materialHints as $inReq => $inProduct) {
            if (! str_contains($req, $inReq) && ! $this->reqHasAny($req, $inProduct)) {
                continue;
            }
            foreach ($inProduct as $hint) {
                $h = $this->normalize(trim($hint));
                if ($h === '') {
                    continue;
                }
                if (str_contains($hay, $h) || ($matNorm !== '' && str_contains($matNorm, $h))) {
                    $score += 18;
                    break;
                }
            }
        }

        return min(54, $score);
    }

    /**
     * @param  list<string>  $needles
     */
    private function reqHasAny(string $req, array $needles): bool
    {
        foreach ($needles as $n) {
            $h = $this->normalize(trim($n));
            if ($h !== '' && str_contains($req, $h)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Marka / model z SIWZ w nazwie, producencie lub (dla kodów) w opisie.
     *
     * @param  list<string>  $reqTokens
     */
    private function brandModelScore(array $reqTokens, string $hay, Product $product): int
    {
        $score = 0;
        $manuf = $this->normalize($product->manufacturer);
        $name = $this->normalize($product->name);
        $sku = $this->normalize($product->sku);

        foreach ($reqTokens as $token) {
            if (mb_strlen($token) < 4 || in_array($token, self::STOPWORDS, true) || $this->isClothingSize($token)) {
                continue;
            }
            // „szelki”, „półmaska”, „typu”, „FFP1” w nazwie karty to rodzaj wyrobu, nie marka z SIWZ
            if ($this->isGenericSiwzCode($token)) {
                continue;
            }
            if ($manuf !== '' && str_contains($manuf, $token)) {
                $score += 28;

                continue;
            }
            if ($name !== '' && str_contains($name, $token)) {
                $score += 26;

                continue;
            }
            if ($sku !== '' && str_contains($sku, $token)) {
                $score += 30;

                continue;
            }
            // w opisie tylko tokeny „kodowe” (litery+cyfry) — nie ogólne słowa typu safety/szare
            if (preg_match('/[a-z]/', $token) === 1 && preg_match('/\d/', $token) === 1 && str_contains($hay, $token)) {
                $score += 24;
            }
        }

        return min(50, $score);
    }

    /**
     * Kod modelowy z SIWZ (RNITZ, REJS…) występuje w nazwie/opisie produktu.
     *
     * @param  list<string>  $reqCodes
     */
    private function modelCodeInTextScore(array $reqCodes, string $hay): int
    {
        $score = 0;
        foreach ($reqCodes as $code) {
            if (ctype_digit($code) || mb_strlen($code) < 4 || $this->isClothingSize($code)) {
                continue;
            }
            if (str_contains($hay, $code)) {
                $score += 34;
            }
        }

        return min(40, $score);
    }

    private function normalize(string $s): string
    {
        $s = mb_strtolower($s);
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];
        $s = strtr($s, $map);

        return preg_replace('/[^a-z0-9\s]/', ' ', $s) ?? $s;
    }

    /**
     * @return list<string>
     */
    private function tokens(string $s): array
    {
        $parts = preg_split('/\s+/', $s) ?: [];

        return array_values(array_filter($parts, static fn ($t) => mb_strlen($t) >= 3));
    }

    /**
     * @return list<string>
     */
    private function significantTokens(string $s): array
    {
        return array_values(array_filter(
            $this->tokens($s),
            fn (string $t): bool => ! in_array($t, self::STOPWORDS, true) && ! $this->isClothingSize($t)
        ));
    }

    /**
     * Tekst znormalizowany bez numerów norm z rokiem i poprawką („en 388 2016 a1 2018”,
     * „pn en 140 2004”, „iso 13997”), numerów rozporządzeń („ue 2016 425”) i liczb z jednostką
     * („5000 ppm”, „500 ml”) — inaczej rok, numer rozporządzenia albo stężenie zostaje kodem produktu.
     */
    private function stripNormNumbers(string $text): string
    {
        $t = preg_replace(
            '/\b(?:pn\s*)?en(?:\s*iso)?\s*\d+(?:\s+\d+)*(?:\s+a\d+(?:\s+\d+)?)*/u',
            ' ',
            $text
        ) ?? $text;
        $t = preg_replace('/\biso\s*\d+(?:\s+\d+)*/u', ' ', $t) ?? $t;
        $t = preg_replace('/\b(?:ue|we|eu)\s+\d{4}\s+\d{2,4}\b/u', ' ', $t) ?? $t;

        return preg_replace(
            '/\b\d+\s+(?:ppm|ml|mm|cm|m|g|kg|l|szt|par|kv|v|db|min|mies|lat|c|m\s+s)\b/u',
            ' ',
            $t
        ) ?? $t;
    }

    /** Ten sam typ w SIWZ i w nazwie produktu (kominiarka ↔ kominiarka). */
    private function typeNameScore(string $req, Product $product): int
    {
        $reqFamily = $this->detectAssortmentFamily($req);
        if ($reqFamily === null) {
            return 0;
        }
        $name = $this->normalize((string) $product->name);
        if ($name === '' || $this->detectAssortmentFamily($name) !== $reqFamily) {
            return 0;
        }
        // Ta sama rodzina to za mało: półmaska wielorazowa ≠ FFP1, gogle ≠ okulary, spodniobuty ≠ spodnie.
        if ($this->assortment->subtypesConflict($req, $product)) {
            return 0;
        }

        return 40;
    }

    /**
     * Kody z SIWZ: SKU z cyfrą oraz krótkie kody WIELKIMI literami (RNITZ, REJS).
     * Pomija gołe 2–4 cyfry (rozmiary, „600”), skróty norm/klas/materiałów (ESD, SRC, PVC, FDA, III)
     * oraz lata i poprawki norm („EN 420:2003+A1:2009”) — opis bez marki i modelu ma nie mieć kodów.
     *
     * @return list<string>
     */
    private function codeCandidates(string $req): array
    {
        $out = [];

        if (preg_match_all('/\b[A-Z]{3,10}\b/u', $req, $m2)) {
            foreach ($m2[0] as $raw) {
                $c = $this->normalize($raw);
                if ($c !== '' && ! in_array($c, self::STOPWORDS, true) && ! TechnicalAbbreviations::isNormOrClass($raw)
                    && ! $this->isGenericSiwzCode($c) && ! $this->isClothingSize($c)) {
                    $out[] = $c;
                }
            }
        }

        // pełne wyrażenia norm (EN 420:2003+A1:2009, EN ISO 20345:2011) zna fuzzy; po normalizacji
        // dwukropki i plusy znikają, więc resztę (ue 2016 425, 5000 ppm) zdejmuje stripNormNumbers
        $stripped = $this->stripNormNumbers($this->normalize(RequirementCodeNoise::strip($req)));
        if (preg_match_all('/\b[A-Za-z]{0,6}\d[A-Za-z0-9\-\/]{1,}\b/', $stripped, $m)) {
            foreach ($m[0] as $raw) {
                $c = preg_replace('/\s+/', '', $this->normalize($raw)) ?? '';
                if ($c === '' || (ctype_digit($c) && mb_strlen($c) < 4)) {
                    continue;
                }
                if (mb_strlen($c) >= 4 && ! $this->isClothingSize($c)) {
                    $out[] = $c;
                }
            }
        }

        return array_values(array_unique($out));
    }

    private function isGenericSiwzCode(string $code): bool
    {
        if (preg_match(self::PROTECTION_CLASS_CODE, $code) === 1) {
            return true;
        }
        foreach (self::GENERIC_SIWZ_CODES as $generic) {
            if ($code === $generic || str_starts_with($code, $generic) || str_contains($generic, $code)) {
                return true;
            }
        }

        return false;
    }

    private function isClothingSize(string $token): bool
    {
        $t = preg_replace('/[^a-z0-9]/', '', mb_strtolower($token)) ?? '';

        return $t !== '' && in_array($t, self::CLOTHING_SIZES, true);
    }

    /**
     * Dopasuj jedną pozycję: źródło heurystyczne + top 5 z modelu AI.
     *
     * @return array<string, mixed>
     */
    public function matchItem(TenderItem $item, bool $force = false): array
    {
        // zapisana pozycja z produktem — nie nadpisuj przy ponownym wejściu / kliku
        if (! $force && $item->hasCustomOffer()) {
            return [
                'matched' => true,
                'score' => (int) ($item->ai_match_percent ?? 0),
                'product_id' => null,
                'product' => null,
                'offer_price' => $item->offer_price,
                'skipped_existing' => true,
                'sources' => [
                    'heuristic' => null,
                    'ai' => [],
                ],
                'candidates' => [],
                'ai_match_reasons' => $item->ai_match_reasons,
                'match_source' => $item->match_source,
            ];
        }

        if (! $force && $item->main_product_id !== null) {
            $item->loadMissing('mainProduct');
            $p = $item->mainProduct;

            return [
                'matched' => true,
                'score' => (int) ($item->ai_match_percent ?? 100),
                'product_id' => $item->main_product_id,
                'product' => $p ? [
                    'id' => $p->id,
                    'sku' => $p->sku,
                    'name' => $p->name,
                ] : null,
                'offer_price' => $item->offer_price,
                'skipped_existing' => true,
                'sources' => [
                    'heuristic' => null,
                    'ai' => [],
                ],
                'candidates' => [],
            ];
        }

        $products = $this->productsForRequirement($item->requirement);
        $aiCandidates = $this->aiTopCandidates($item->requirement);
        $pool = $this->heuristicCandidatePool($item->requirement, $products, $aiCandidates, null);
        $described = $this->withDescriptions($pool);
        $heuristic = $described->isEmpty() ? null : $this->bestMatch($item->requirement, $described);

        $sources = [
            'heuristic' => $heuristic === null ? null : [
                'score' => $heuristic['score'],
                'product' => [
                    'id' => $heuristic['product']->id,
                    'sku' => $heuristic['product']->sku,
                    'name' => $heuristic['product']->name,
                ],
            ],
            'ai' => $aiCandidates,
        ];

        $candidates = $this->mergeCandidates($heuristic, $aiCandidates);
        $this->lastExternalHint = null;
        $this->lastNoMatchReason = null;
        $this->lastModelLowScore = null;
        $this->lastUndescribedSku = null;
        $this->lastUndescribedCodePickId = null;
        $pick = $this->resolveBestPick($item->requirement, $products, $aiCandidates);

        if ($pick === null) {
            if ($item->hasCustomOffer()) {
                return [
                    'matched' => true,
                    'score' => (int) ($item->ai_match_percent ?? 0),
                    'product_id' => null,
                    'product' => null,
                    'offer_price' => $item->offer_price,
                    'skipped_existing' => true,
                    'sources' => $sources,
                    'candidates' => $candidates,
                    'ai_match_reasons' => $item->ai_match_reasons,
                    'match_source' => $item->match_source,
                ];
            }
            $this->applyNoCatalogMatch($item, $products);
            $item->refresh();

            return [
                'matched' => $item->hasOfferProduct(),
                'score' => (int) ($item->ai_match_percent ?? 0),
                'product_id' => $item->main_product_id,
                'offer_price' => $item->offer_price,
                'sources' => $sources,
                'candidates' => $candidates,
                'ai_match_reasons' => $item->ai_match_reasons,
                'match_source' => $item->match_source,
            ];
        }

        $aiReason = $this->modelReasonForPick($pick, $aiCandidates);
        if ($this->heuristicWouldReplaceModelPick($item, $pick)
            || ! $this->applyProduct($item, $pick['product'], $pick['score'], $pick['source'], $aiReason, (bool) ($pick['heuristic_only'] ?? false))) {
            $this->applyNoCatalogMatch($item, $products);
            $item->refresh();

            return [
                'matched' => $item->hasOfferProduct(),
                'score' => (int) ($item->ai_match_percent ?? 0),
                'product_id' => $item->main_product_id,
                'offer_price' => $item->offer_price,
                'sources' => $sources,
                'candidates' => $candidates,
                'ai_match_reasons' => $item->ai_match_reasons,
                'match_source' => $item->match_source,
            ];
        }
        $item->load(['mainProduct', 'tender']);
        if ($item->tender !== null) {
            $this->pricing->recalculateTenderTotals($item->tender);
        }

        $p = $pick['product'];

        return [
            'matched' => true,
            'score' => (int) ($item->ai_match_percent ?? $pick['score']),
            'source' => $pick['source'],
            'product_id' => $p->id,
            'product' => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
            ],
            'offer_price' => $item->offer_price,
            'sources' => $sources,
            'candidates' => $candidates,
            'ai_match_reasons' => $item->fresh()->ai_match_reasons,
            'match_source' => $item->match_source,
        ];
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array{product: Product, score: int, source: string}|null
     */
    private function resolveBestPick(string $requirement, Collection $products, ?array $aiCandidates = null): ?array
    {
        $skuPick = $this->strongSkuPick($requirement, $products);
        if ($skuPick !== null) {
            $honest = $this->persistableScore($requirement, $skuPick['product'], $skuPick['score']);
            if ($honest !== null) {
                // Kod z SIWZ wskazał kartę bez opisu: pozycja czeka na opis tej karty — bez podstawiania zamiennika.
                if (! $skuPick['product']->hasDescriptionText()) {
                    $this->lastUndescribedSku ??= (string) $skuPick['product']->sku;
                    $this->lastUndescribedCodePickId = (int) $skuPick['product']->id;

                    return null;
                }
                $skuPick['score'] = $honest;

                return $skuPick;
            }
        }

        $aiCandidates ??= $this->aiTopCandidates($requirement);
        $pool = $this->heuristicCandidatePool($requirement, $products, $aiCandidates, $skuPick);
        $described = $this->withDescriptions($pool);
        $heuristic = $described->isEmpty() ? null : $this->bestMatch($requirement, $described);
        $picked = $this->pickAuto($requirement, $heuristic, $aiCandidates, $described);
        if ($picked === null) {
            return null;
        }

        $source = (string) ($picked['source'] ?? 'heuristic');
        // Ta sama bramka co w pickAuto — wiersz katalogowy/skrótu bez zgody admina nie dochodzi
        // do persistableScore; za jawną zgodą jego procentowi ufamy jak ocenie modelu (D8).
        if ($this->isCatalogRowSource($source) && ! $this->aiSettings->matchAllowsCatalogRows()) {
            return null;
        }
        $picked['product'] = $this->resolveCatalogBySku($picked['product'], $products);
        $honest = $this->persistableScore(
            $requirement,
            $picked['product'],
            $picked['score'],
            $this->trustsRowScore($source)
        );
        if ($honest === null) {
            return null;
        }
        if (! $this->meetsPersistThreshold($requirement, $picked['product'], $honest, $source)) {
            return null;
        }
        $picked['score'] = $honest;

        return $picked;
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return Collection<int, Product>
     */
    private function withDescriptions(Collection $products): Collection
    {
        return $products->filter(static fn (Product $p): bool => $p->hasDescriptionText())->values();
    }

    /**
     * @param  Collection<int, Product>  $catalog
     */
    private function resolveCatalogBySku(Product $found, Collection $catalog): Product
    {
        $sku = trim((string) $found->sku);
        if ($sku === '') {
            return $found;
        }

        $same = $catalog->first(
            static fn (Product $p): bool => strcasecmp(trim((string) $p->sku), $sku) === 0
        );

        return $same instanceof Product ? $same : $found;
    }

    /**
     * @param  Collection<int, TenderItem>  $items
     * @return Collection<int, Product>
     */
    private function productsForItems(Collection $items): Collection
    {
        $families = [];
        foreach ($items as $item) {
            $family = $this->detectAssortmentFamily((string) $item->requirement);
            if ($family !== null) {
                $families[$family] = true;
            }
        }

        return $this->productsForFamilies(array_keys($families));
    }

    private function productsForRequirement(string $requirement): Collection
    {
        $family = $this->detectAssortmentFamily($requirement);

        return $this->productsForFamilies($family !== null ? [$family] : []);
    }

    /**
     * @param  list<string>  $families
     * @return Collection<int, Product>
     */
    private function productsForFamilies(array $families): Collection
    {
        $families = array_values(array_unique(array_filter($families)));
        if ($families === []) {
            return collect();
        }

        return Product::query()->whereIn('ppe_family', $families)->get();
    }

    /**
     * @param  list<array{id: int, sku: string, name: string, score: int, reason: ?string, source: string}>  $aiCandidates
     * @param  array{product: Product, score: int, source: string}|null  $skuPick
     * @param  Collection<int, Product>  $familyProducts
     * @return Collection<int, Product>
     */
    private function heuristicCandidatePool(
        string $requirement,
        Collection $familyProducts,
        array $aiCandidates,
        ?array $skuPick,
    ): Collection {
        $ids = [];
        foreach ($aiCandidates as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[$id] = true;
            }
        }
        if ($skuPick !== null) {
            $ids[(int) $skuPick['product']->id] = true;
        }
        if ($ids === []) {
            if ($familyProducts->count() > 0 && $familyProducts->count() <= 120) {
                return $familyProducts->values();
            }
            foreach ($this->aiSearch->catalogRows($requirement, 80) as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id > 0) {
                    $ids[$id] = true;
                }
            }
        }
        if ($ids === []) {
            return collect();
        }

        $want = array_keys($ids);
        $fromFamily = $familyProducts->filter(
            static fn (Product $p): bool => isset($ids[(int) $p->id])
        )->values();
        if ($fromFamily->count() >= count($want)) {
            return $fromFamily;
        }
        $have = $fromFamily->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $missing = array_values(array_diff($want, $have));
        if ($missing === []) {
            return $fromFamily;
        }

        return $fromFamily->concat(Product::query()->whereIn('id', $missing)->get())->values();
    }

    /**
     * @param  list<string>  $reqCodes
     * @return Collection<int, Product>
     */
    private function narrowSkuPool(string $requirement, array $reqCodes, bool $hasNamed): Collection
    {
        $needles = $reqCodes;
        if ($hasNamed) {
            foreach ($this->modelFuzzy->catalogModelNeedles($requirement) as $needle) {
                $needles[] = $needle;
            }
        }
        $needles = array_values(array_unique(array_filter(
            $needles,
            static fn (string $needle): bool => mb_strlen($needle) >= 3
        )));
        if ($needles === []) {
            return collect();
        }

        $family = $this->detectAssortmentFamily($requirement);
        $query = Product::query();
        if ($family !== null) {
            $query->where('ppe_family', $family);
        }
        $query->where(function ($outer) use ($needles): void {
            foreach ($needles as $needle) {
                $like = '%'.addcslashes($needle, '%_\\').'%';
                $outer->orWhere('sku', 'like', $like)
                    ->orWhere('name', 'like', $like);
            }
        });

        return $query->limit(80)->get();
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array{product: Product, score: int, source: string}|null
     */
    private function strongSkuPick(string $requirement, Collection $products): ?array
    {
        $best = null;
        $reqNorm = $this->normalize($requirement);
        $reqCodes = $this->codeCandidates($requirement);
        $hasNamed = $this->modelFuzzy->hasNamedModel($requirement);
        if ($products->count() > 120 || ($products->isEmpty() && ($reqCodes !== [] || $hasNamed))) {
            $products = $this->narrowSkuPool($requirement, $reqCodes, $hasNamed);
        }
        if ($products->isEmpty()) {
            return null;
        }
        // remis punktów: najpierw więcej dowodów z karty (explainMatch), dopiero potem cena
        $explained = [];
        $evidence = function (Product $p) use (&$explained, $requirement): int {
            return $explained[spl_object_id($p)] ??= (int) $this->explainMatch($requirement, $p)['score'];
        };
        foreach ($products as $product) {
            if (! $this->assortment->compatibleProduct($requirement, $product)) {
                continue;
            }
            // fuzzy rozstrzyga jak SKU tylko na mocnych igłach (z cyfrą, URG-A, linia po marce) —
            // „FFP1” czy goły wyraz nie może zablokować pozycji przed oceną modelu
            $score = max(
                $this->skuMatchScore($reqNorm, $reqCodes, $product),
                $this->modelFuzzy->strongSkuScore($requirement, $product)
            );
            if ($score < 70) {
                continue;
            }
            $candidate = [
                'product' => $product,
                'score' => max($this->minMatchScore(), $score),
                'source' => 'heuristic',
            ];
            if ($best === null || $candidate['score'] > $best['score']) {
                $best = $candidate;

                continue;
            }
            if ($candidate['score'] !== $best['score']) {
                continue;
            }
            $gap = $evidence($product) <=> $evidence($best['product']);
            if ($gap > 0 || ($gap === 0 && $this->purchasePln($product) < $this->purchasePln($best['product']))) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function hasStrongSkuInRequirement(string $requirement, Product $product): bool
    {
        return $this->skuMatchScore(
            $this->normalize($requirement),
            $this->codeCandidates($requirement),
            $product
        ) >= 70 || $this->modelFuzzy->strongSkuScore($requirement, $product) >= 80;
    }

    /** Kody z cyfrą (6503, HF803) — nie wolno podstawić innej półmaski tej samej marki. */
    private function honorsSpecificModelCodes(string $requirement, Product $product): bool
    {
        if ($this->modelFuzzy->hasNamedModel($requirement)) {
            return $this->modelFuzzy->matches($requirement, $product);
        }

        $codes = [];
        foreach ($this->codeCandidates($requirement) as $code) {
            if (preg_match('/\d/', $code) === 1 && mb_strlen($code) >= 4) {
                $codes[] = $code;
            }
        }
        if ($codes === []) {
            return true;
        }

        $skuCompact = preg_replace('/\s+/', '', $this->normalize($product->sku)) ?? '';
        $name = $this->normalize((string) $product->name);
        foreach ($codes as $code) {
            if ($this->codesMatch($skuCompact, $code)) {
                return true;
            }
            if ($name !== '' && preg_match(
                '/(^|[^a-z0-9])'.preg_quote($code, '/').'([^a-z0-9]|$)/u',
                $name
            ) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{product: Product, score: int}|null  $heuristic
     * @param  list<array{id: int, sku: string, name: string, score: int, reason: ?string, source: string}>  $aiCandidates
     * @param  Collection<int, Product>  $products
     * @return array{product: Product, score: int, source: string}|null
     */
    private function pickAuto(string $requirement, ?array $heuristic, array $aiCandidates, Collection $products): ?array
    {
        if ($heuristic !== null && $heuristic['score'] >= $this->applyMatchScore()
            && $this->hasStrongSkuInRequirement($requirement, $heuristic['product'])
            && $this->persistableScore($requirement, $heuristic['product'], $heuristic['score']) !== null) {
            return [
                'product' => $heuristic['product'],
                'score' => $heuristic['score'],
                'source' => 'heuristic',
            ];
        }

        $options = [];
        foreach ($aiCandidates as $topAi) {
            $product = $products->firstWhere('id', $topAi['id'])
                ?? Product::query()->find($topAi['id']);
            if (! $product instanceof Product) {
                continue;
            }
            if (! $this->assortment->compatibleProduct($requirement, $product)) {
                continue;
            }
            // Karta bez opisu nie trafia do propozycji — nawet z wysoką oceną modelu (ocena z samej nazwy).
            if (! $product->hasDescriptionText()) {
                $this->lastUndescribedSku ??= (string) $product->sku;

                continue;
            }
            $source = (string) ($topAi['source'] ?? 'ai');
            // Twarda bramka: wiersz listy katalogowej / skrótu nie jest oceną modelu, więc bez
            // zgody admina odpada ZANIM persistableScore zważy go samym explainMatch (explain ≥ 40
            // przepuszczał wiersz „catalog” mimo wyłączonego ustawienia). Trzeba to robić tu,
            // bo prefetch przetargu idzie jako AiTask::ProductSearch (prefetchAiCandidates), więc
            // wyłącznik z ProductAiSearchService::finishSearch nie działa na tej ścieżce.
            // Fala nie dokłada już listy katalogowej po awarii modelu przy wymaganiu z warunkiem
            // (ta sama bramka co w wyszukiwarce), ale przy modelu, który odpowiedział „nic nie
            // pasuje”, i przy ogólnym wymaganiu dalej to robi — dlatego ta bramka zostaje.
            if ($this->isCatalogRowSource($source) && ! $this->aiSettings->matchAllowsCatalogRows()) {
                continue;
            }
            $exact = $this->honorsSpecificModelCodes($requirement, $product);
            $minScore = $exact ? $this->applyMatchScore() : $this->substituteMatchScore();
            if ($topAi['score'] < $minScore) {
                continue;
            }
            $honest = $this->persistableScore($requirement, $product, $topAi['score'], $this->trustsRowScore($source));
            if ($honest === null) {
                continue;
            }
            if (! $exact && $honest < $this->substituteMatchScore()
                && $topAi['score'] < $this->substituteMatchScore()) {
                continue;
            }
            if (! $this->meetsPersistThreshold($requirement, $product, $honest, $source)) {
                continue;
            }

            $explained = $this->explainMatch($requirement, $product);
            $options[] = [
                'product' => $product,
                'score' => $honest,
                // Wiersz katalogowy/skrótu zachowuje swoje źródło także jako zamiennik — to jego
                // pochodzenie (nie ocena modelu); progi i zaufanie czytają je po źródle.
                'source' => $exact || $this->isCatalogRowSource($source) ? $source
                    : ($this->qualifiesAsBrandSubstitute($requirement, $product) ? 'ai_substitute' : $source),
                'exact' => $exact,
                'evidence' => $explained['score'],
                'hard' => $this->hardEvidenceLevel($requirement, $product, $explained),
                'norms_complete' => $this->showsAllRequiredNorms($requirement, $product),
            ];
        }

        if ($options !== []) {
            // Gdy choć jedna karta ma dowody ≥ min, karty z explain < apply (przeszły wyłącznie
            // obejściem trustModel w persistableScore) nie mają czego bronić — także w roli
            // „dokładnego” trafienia przed zamiennikami.
            $minScore = $this->minMatchScore();
            $applyScore = $this->applyMatchScore();
            $anyProven = array_filter($options, static fn (array $row): bool => $row['evidence'] >= $minScore) !== [];
            if ($anyProven) {
                $options = array_values(array_filter(
                    $options,
                    static fn (array $row): bool => $row['evidence'] >= $applyScore
                ));
            }
            $exact = array_values(array_filter(
                $options,
                static fn (array $row): bool => $row['exact']
            ));

            return $this->preferCheapestAmongCloseScores($exact !== [] ? $exact : $options);
        }

        if ($heuristic !== null && $heuristic['score'] >= $this->applyMatchScore()) {
            // Tu trafia wybór „po słowach karty”: kod z SIWZ rozstrzygnął by wcześniej (strongSkuPick,
            // pierwsza gałąź), a model tej karty nie wskazał. O takim wyborze ma decydować model:
            // gdy nie odpowiedział (timeout, błąd) — pozycja czeka na ponowienie zamiast dostać
            // kartę z 99%; gdy odpowiedział „nic nie pasuje” albo nie był pytany — heurystyka
            // zostaje propozycją z sufitem 70% i etykietą. Skróty norm i lata (ESD, SRC, 2016)
            // w opisie nie są kodem produktu, więc nie zwalniają z tej zasady.
            if ($this->modelStateFor($requirement) === ProductAiSearchService::MODEL_STATE_UNAVAILABLE) {
                $this->lastNoMatchReason = self::NO_MATCH_MODEL_UNAVAILABLE;

                return null;
            }
            // Model ocenił tę kartę poniżej progu (ranking: brak dowodu kluczowego warunku → najwyżej 50,
            // np. 9312+ bez węgla aktywnego) — słowa karty nie odwracają tej oceny i nie dopisują
            // „bez oceny modelu” z 70%.
            $lowModelScore = $this->modelScoreBelowMin($aiCandidates, (int) $heuristic['product']->id);
            if ($lowModelScore !== null) {
                $this->lastModelLowScore = ['sku' => (string) $heuristic['product']->sku, 'score' => $lowModelScore];

                return null;
            }
            $honest = $this->persistableScore($requirement, $heuristic['product'], $heuristic['score']);
            if ($honest !== null) {
                $score = min($honest, self::HEURISTIC_ONLY_CAP);
                if ($this->meetsPersistThreshold($requirement, $heuristic['product'], $score, 'heuristic')) {
                    return [
                        'product' => $heuristic['product'],
                        'score' => $score,
                        'source' => 'heuristic',
                        'heuristic_only' => true,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Diagnostyka `tenders:debug-match`: decyzja przetargu dla wyniku wyszukiwania jednej pozycji —
     * te same bramki co rememberAiCandidates + pickAuto, każdy wiersz z werdyktem, a na końcu
     * prawdziwe resolveBestPick. Nic nie zapisuje do przetargu.
     *
     * @param  array<string, mixed>  $searchResult  wiersz z ProductAiSearchService::searchMany
     * @return array{
     *     candidates: list<array{sku: string, model: int, source: string, verdict: string}>,
     *     pick: array{sku: string, score: int, source: string, heuristic_only: bool}|null,
     *     reason: string|null
     * }
     */
    public function debugPick(TenderItem $item, array $searchResult): array
    {
        $requirement = (string) $item->requirement;
        $products = $this->productsForItems(collect([$item]));
        $source = $this->vectorSearch->enabled() ? 'vector' : 'ai';
        $rows = [];
        foreach ($this->mapAiSearchRows($searchResult['products'] ?? [], self::AI_CANDIDATE_WINDOW, $source) as $row) {
            $product = Product::query()->find($row['id']);
            $entry = ['sku' => $product?->sku ?? (string) $row['id'], 'model' => (int) $row['score'], 'source' => (string) $row['source']];
            $rows[] = $entry + ['verdict' => $product instanceof Product
                ? $this->debugVerdict($requirement, $product, (int) $row['score'], (string) $row['source'])
                : 'brak karty w katalogu'];
        }

        $candidates = $this->rememberAiCandidates($requirement, $searchResult, self::AI_CANDIDATE_WINDOW, $source);
        $this->lastNoMatchReason = null;
        $this->lastModelLowScore = null;
        $pick = $this->resolveBestPick($requirement, $products, $candidates);
        $reason = null;
        if ($pick === null) {
            $reason = $this->lastModelLowScore !== null
                ? 'model ocenił najlepszą kartę '.$this->lastModelLowScore['sku'].' na '.$this->lastModelLowScore['score'].'%'
                : ($this->lastNoMatchReason ?? 'żadna karta nie przeszła bramek');
        }

        return [
            'candidates' => $rows,
            'pick' => $pick === null ? null : [
                'sku' => (string) $pick['product']->sku,
                'score' => (int) $pick['score'],
                'source' => (string) ($pick['source'] ?? ''),
                'heuristic_only' => (bool) ($pick['heuristic_only'] ?? false),
            ],
            'reason' => $reason,
        ];
    }

    /** Werdykt pojedynczego kandydata — kolejność i progi jak w pickAuto. */
    private function debugVerdict(string $requirement, Product $product, int $score, string $source): string
    {
        if (! $this->assortment->compatibleProduct($requirement, $product)) {
            return 'odrzucona: bramka asortymentu';
        }
        if (! $product->hasDescriptionText()) {
            return 'odrzucona: karta bez opisu';
        }
        if ($this->isCatalogRowSource($source) && ! $this->aiSettings->matchAllowsCatalogRows()) {
            return 'odrzucona: wiersz katalogowy/reguły bez zgody admina';
        }
        $exact = $this->honorsSpecificModelCodes($requirement, $product);
        $minScore = $exact ? $this->applyMatchScore() : $this->substituteMatchScore();
        if ($score < $minScore) {
            return 'odrzucona: ocena modelu '.$score.' < '.$minScore;
        }
        $honest = $this->persistableScore($requirement, $product, $score, $this->trustsRowScore($source));
        if ($honest === null) {
            return 'odrzucona: persistableScore (brak dowodów na karcie)';
        }
        if (! $exact && $honest < $this->substituteMatchScore() && $score < $this->substituteMatchScore()) {
            return 'odrzucona: zamiennik poniżej progu '.$this->substituteMatchScore();
        }
        if (! $this->meetsPersistThreshold($requirement, $product, $honest, $source)) {
            return 'odrzucona: zapis '.$honest.' < próg '.$this->minMatchScore();
        }
        $explained = $this->explainMatch($requirement, $product);

        return sprintf(
            'kandydat: zapis %d, dowody %d, twarde %d, cena %.2f zł',
            $honest,
            $explained['score'],
            $this->hardEvidenceLevel($requirement, $product, $explained),
            $this->purchasePln($product),
        );
    }

    /**
     * Ocena modelu (nie wiersza z katalogu) dla karty, gdy jest poniżej progu zapisu; inaczej null.
     *
     * @param  list<array{id: int, sku: string, name: string, score: int, reason: ?string, source: string}>  $aiCandidates
     */
    private function modelScoreBelowMin(array $aiCandidates, int $productId): ?int
    {
        foreach ($aiCandidates as $row) {
            if ((int) $row['id'] !== $productId || $this->isCatalogRowSource((string) $row['source'])) {
                continue;
            }

            return (int) $row['score'] < $this->minMatchScore() ? (int) $row['score'] : null;
        }

        return null;
    }

    private function modelStateFor(string $requirement): string
    {
        return $this->aiModelState[$this->aiCandidatesCacheKey($requirement)] ?? 'unknown';
    }

    /**
     * Zamiennik merytoryczny: SIWZ nazywa model, a karta go nie honoruje (także inny model tej samej
     * marki), albo wskazuje producenta spoza katalogu / innego niż na karcie. Opis bez marki i modelu
     * nie ma czego „zastępować” — trafienie modelu zostaje zwykłym „ai”, bez etykiety „Zamiennik”.
     */
    private function qualifiesAsBrandSubstitute(string $requirement, Product $product): bool
    {
        if ($this->honorsSpecificModelCodes($requirement, $product)) {
            return false;
        }
        if ($this->modelFuzzy->hasNamedModel($requirement) && ! $this->modelFuzzy->matches($requirement, $product)) {
            return true;
        }

        $requestedMfg = $this->requestedManufacturerFromSiwz($requirement);
        if ($requestedMfg === null) {
            return false;
        }
        $canonical = $this->manufacturerContext->matchManufacturer($requestedMfg);
        if ($canonical !== null && $this->manufacturerContext->hasProductsForManufacturer($canonical)) {
            return ! $this->productMatchesManufacturer($product, $canonical);
        }

        return true;
    }

    /**
     * Producent wskazany w SIWZ: „prod.CERVA”, „Producent: CERVA”, „prod. 3M” — nie „produkcja metodą”
     * ani „Produkt spełnia” (wymagana kropka albo „producent…”, nazwa od wielkiej litery lub cyfry).
     */
    public function requestedManufacturerFromSiwz(string $requirement): ?string
    {
        if (preg_match('/\b(?i:prod(?:\.|ucent\w*\.?:?))\s*([A-Z0-9][A-Za-z0-9\-]+)/u', $requirement, $m) === 1) {
            $token = trim($m[1]);
            if ($token !== '' && preg_match('/[A-Za-z]/', $token) === 1) {
                return $token;
            }
        }

        foreach ($this->codeCandidates($requirement) as $code) {
            if (mb_strlen($code) < 3) {
                continue;
            }
            $canonical = $this->manufacturerContext->matchManufacturer($code);
            if ($canonical !== null) {
                return $canonical;
            }
        }

        return null;
    }

    private function productMatchesManufacturer(Product $product, string $canonical): bool
    {
        $canonical = trim($canonical);
        if ($canonical === '') {
            return false;
        }
        $prod = mb_strtolower(trim((string) $product->manufacturer));
        $can = mb_strtolower($canonical);

        return $prod !== '' && ($prod === $can || str_contains($prod, $can) || str_contains($can, $prod));
    }

    /**
     * @param  list<string>  $requirements
     */
    private function prefetchAiCandidates(array $requirements, ?callable $onProgress = null): void
    {
        $queries = [];
        foreach ($requirements as $requirement) {
            $requirement = trim($requirement);
            if ($requirement !== '') {
                $queries[$requirement] = true;
            }
        }
        $queries = array_keys($queries);
        if ($queries === [] || ! $this->aiSettings->isReady()) {
            return;
        }

        try {
            $rows = $this->aiSearch->findMany(
                $queries,
                $this->aiSettings->catalogSearchLimit(),
                AiTask::ProductSearch,
                $this->aiSettings->matchConcurrency(),
                $onProgress,
            );
        } catch (Throwable) {
            return;
        }

        $source = $this->vectorSearch->enabled() ? 'vector' : 'ai';
        foreach ($queries as $i => $requirement) {
            $this->rememberAiCandidates(
                $requirement,
                is_array($rows[$i] ?? null) ? $rows[$i] : [],
                self::AI_CANDIDATE_WINDOW,
                $source
            );
        }
    }

    /**
     * Klucz cache tylko z wymagania — okno jest jedno (AI_CANDIDATE_WINDOW), a chybienie
     * między prefetch a aiTopCandidates oznaczałoby drugie, płatne wywołanie modelu na pozycję.
     */
    private function aiCandidatesCacheKey(string $requirement): string
    {
        return md5($requirement);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<array{id: int, sku: string, name: string, score: int, reason: ?string, source: string}>
     */
    private function rememberAiCandidates(string $requirement, array $result, int $limit, string $source): array
    {
        $cacheKey = $this->aiCandidatesCacheKey($requirement);
        $this->aiModelState[$cacheKey] = is_string($result['model_state'] ?? null) ? $result['model_state'] : 'unknown';
        $hint = $result['external_hint'] ?? null;
        if (is_array($hint) && isset($hint['url'], $hint['title'])) {
            $this->lastExternalHint = [
                'url' => (string) $hint['url'],
                'title' => (string) $hint['title'],
            ];
        }

        $mapped = $this->mapAiSearchRows($result['products'] ?? [], $limit, $source);
        $mapped = $this->mergeCatalogCandidatesForTender($requirement, $mapped, $limit);
        if ($mapped === []) {
            $this->aiCandidatesCache[$cacheKey] = [];

            return [];
        }

        $byId = Product::query()
            ->whereIn('id', array_column($mapped, 'id'))
            ->get()
            ->keyBy('id');
        $out = [];
        foreach ($mapped as $row) {
            $product = $byId->get($row['id']);
            if (! $product instanceof Product) {
                continue;
            }
            if (! $this->assortment->compatibleProduct($requirement, $product)) {
                continue;
            }
            $out[] = $row;
        }

        $this->aiCandidatesCache[$cacheKey] = $out;

        return $out;
    }

    /**
     * Uzasadnienie modelu dla wybranej karty (z brakami, które model wypisał). Jedno miejsce dla
     * dopasowania całego przetargu i pojedynczej pozycji — dotąd cały przetarg zapisywał pozycję
     * bez tego tekstu i użytkownik nie widział, czego karta nie spełnia.
     *
     * @param  array{product: Product, source?: string}  $pick
     * @param  list<array{id: int, sku: string, name: string, score: int, reason: ?string, source: string}>  $aiCandidates
     */
    private function modelReasonForPick(array $pick, array $aiCandidates): ?string
    {
        if (! in_array($pick['source'] ?? '', ['ai', 'vector'], true)) {
            return null;
        }
        foreach ($aiCandidates as $cand) {
            if ((int) $cand['id'] === (int) $pick['product']->id && is_string($cand['reason'] ?? null)) {
                return $cand['reason'];
            }
        }

        return null;
    }

    /**
     * @return list<array{id: int, sku: string, name: string, score: int, reason: ?string, source: string}>
     */
    private function aiTopCandidates(string $requirement, int $limit = self::AI_CANDIDATE_WINDOW): array
    {
        $cacheKey = $this->aiCandidatesCacheKey($requirement);
        if (isset($this->aiCandidatesCache[$cacheKey])) {
            return $this->aiCandidatesCache[$cacheKey];
        }

        if (! $this->aiSettings->isReady()) {
            return [];
        }

        try {
            $result = $this->aiSearch->find($requirement, $limit, AiTask::ProductSearch);
        } catch (Throwable) {
            return [];
        }

        $source = $this->vectorSearch->enabled() ? 'vector' : 'ai';

        return $this->rememberAiCandidates($requirement, $result, $limit, $source);
    }

    /**
     * @param  list<array{id: int, sku: string, name: string, score: int, reason: ?string, source: string}>  $mapped
     * @return list<array{id: int, sku: string, name: string, score: int, reason: ?string, source: string}>
     */
    private function mergeCatalogCandidatesForTender(string $requirement, array $mapped, int $limit): array
    {
        // Bez zgody admina wiersz katalogowy i tak odpada w pickAuto — nie ma po co zajmować
        // nim okna kandydatów ani liczyć zapasowej listy.
        if (! $this->aiSettings->matchAllowsCatalogRows()) {
            return $mapped;
        }
        $catalogRows = $this->aiSearch->catalogRows($requirement, $limit);
        if ($catalogRows === []) {
            return $mapped;
        }

        // Kolejność odpowiedzi wyszukiwarki zostaje (bez sortowania po cenie — o wyborze
        // decydują dowody w pickAuto), wiersze katalogowe idą za wierszami modelu i nigdy
        // nie nadpisują oceny modelu wyższym procentem z listy.
        $seen = [];
        foreach ($mapped as $row) {
            $seen[$row['id']] = true;
        }
        $merged = $mapped;
        foreach ($this->mapAiSearchRows($catalogRows, $limit, ProductAiSearchService::MATCH_SOURCE_CATALOG) as $row) {
            if (isset($seen[$row['id']])) {
                continue;
            }
            $seen[$row['id']] = true;
            $merged[] = $row;
        }

        return array_slice($merged, 0, $limit);
    }

    /**
     * @param  list<array{id: int, sku: string, name: string, score: int, reason: ?string, source: string}>  $rows
     * @return list<array{id: int, sku: string, name: string, score: int, reason: ?string, source: string}>
     */
    private function sortCandidatesByPurchase(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $byId = Product::query()
            ->whereIn('id', array_column($rows, 'id'))
            ->get()
            ->keyBy('id');
        usort($rows, function (array $a, array $b) use ($byId): int {
            $pa = $byId->get($a['id']);
            $pb = $byId->get($b['id']);
            $fa = $pa instanceof Product ? $this->purchasePln($pa) : PHP_FLOAT_MAX;
            $fb = $pb instanceof Product ? $this->purchasePln($pb) : PHP_FLOAT_MAX;
            if ($fa !== $fb) {
                return $fa <=> $fb;
            }

            return $b['score'] <=> $a['score'];
        });

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{id: int, sku: string, name: string, score: int, reason: ?string, source: string}>
     */
    private function mapAiSearchRows(array $rows, int $limit, string $source): array
    {
        $model = [];
        $catalog = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            // Wiersz z zapasowej listy katalogowej albo skrótu deterministycznego ('rule')
            // zostaje takim nawet w fali AI — model go nie wskazał, więc nie wolno mu ufać
            // jak ocenie modelu.
            $rowSource = $row['ai_match_source'] ?? null;
            $isCatalog = is_string($rowSource) && $this->isCatalogRowSource($rowSource);
            $mapped = [
                'id' => $id,
                'sku' => (string) ($row['sku'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'score' => (int) ($row['ai_match_percent'] ?? 0),
                'reason' => is_string($row['ai_match_reason'] ?? null) ? $row['ai_match_reason'] : null,
                'source' => $isCatalog ? $rowSource : $source,
            ];
            if ($isCatalog) {
                $catalog[] = $mapped;
            } else {
                $model[] = $mapped;
            }
        }

        // Okno kandydatów najpierw obejmuje oceny modelu w kolejności odpowiedzi; wiersze
        // katalogowe idą za nimi, żeby nie wypchnęły z okna karty wskazanej przez model.
        return array_slice([...$model, ...$catalog], 0, max(0, $limit));
    }

    /**
     * @param  array{product: Product, score: int}|null  $heuristic
     * @param  list<array{id: int, sku: string, name: string, score: int, reason: ?string, source: string}>  $aiCandidates
     * @return list<array{id: int, sku: string, name: string, score: int, reason: ?string, source: string}>
     */
    private function mergeCandidates(?array $heuristic, array $aiCandidates): array
    {
        $byId = [];
        if ($heuristic !== null) {
            $p = $heuristic['product'];
            $byId[$p->id] = [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'score' => $heuristic['score'],
                'reason' => 'Dopasowanie heurystyczne (SKU / nazwa / materiał)',
                'source' => 'heuristic',
            ];
        }
        foreach ($aiCandidates as $row) {
            $id = $row['id'];
            if (! isset($byId[$id]) || $row['score'] > $byId[$id]['score']) {
                $byId[$id] = $row;
            }
        }

        $list = $this->sortCandidatesByPurchase(array_values($byId));

        return array_slice($list, 0, 5);
    }

    /**
     * Konflikt rodzaju (okulary ≠ rękawice) = brak zapisu.
     * AI nie może zawyżyć % ponad heurystykę / SKU.
     */
    private function persistableScore(string $requirement, Product $product, int $proposed, bool $trustModel = false): ?int
    {
        if (! $this->assortment->compatibleProduct($requirement, $product)) {
            return null;
        }

        $explained = $this->explainMatch($requirement, $product);
        if (($explained['reasons'][0]['code'] ?? '') === 'asortyment_reject') {
            return null;
        }

        $skuish = max(
            $this->skuMatchScore(
                $this->normalize($requirement),
                $this->codeCandidates($requirement),
                $product
            ),
            $this->modelFuzzy->score($requirement, $product)
        );
        $honest = min(max(0, $proposed), max($explained['score'], $skuish));
        // Ocena modelu ≥ progu zapisu dla karty, która przeszła bramki asortymentu: słowa karty nie
        // ściągają jej pod próg. Przetarg 1, poz. 3: ARSO 701 616560 S1 P ESD (nazwa to goły kod)
        // — model 95, explain 58 → zapis 58 < 65 i brak karty, a przy explain < apply model dostawał
        // pełne zaufanie: lepsze dowody przegrywały z gorszymi. Dowody dalej rozstrzygają wybór między
        // kartami (evidence/hard w pickAuto); heurystyka i wiersze katalogowe bez zgody admina
        // nie przekraczają dowodów.
        if ($trustModel && $proposed >= $this->minMatchScore() && $honest < $proposed) {
            return min(96, $proposed);
        }
        if ($honest >= $this->applyMatchScore()) {
            return $honest;
        }
        if ($skuish >= 70) {
            return max($this->minMatchScore(), $skuish);
        }
        if ($trustModel && $proposed >= $this->applyMatchScore()) {
            return min(96, $proposed);
        }

        return null;
    }

    private function applyProduct(
        TenderItem $item,
        Product $product,
        int $score,
        ?string $source = 'heuristic',
        ?string $aiReason = null,
        bool $heuristicOnly = false,
    ): bool {
        $honest = $this->persistableScore(
            $item->requirement,
            $product,
            $score,
            $this->trustsRowScore((string) $source)
        );
        if ($honest === null) {
            return false;
        }

        $explained = $this->explainMatch($item->requirement, $product);
        $reasons = $explained['reasons'];
        if (($reasons[0]['code'] ?? '') === 'asortyment_reject') {
            return false;
        }
        if ($source === 'ai_substitute') {
            $label = $this->substituteReasonLabel($item->requirement);
            array_unshift($reasons, [
                'code' => 'brand_substitute',
                'label' => $label,
                'points' => $honest,
            ]);
        }
        if ($heuristicOnly) {
            $honest = min($honest, self::HEURISTIC_ONLY_CAP);
            array_unshift($reasons, [
                'code' => 'heuristic_only',
                'label' => 'Bez oceny modelu — wybór po słowach karty (najwyżej '.self::HEURISTIC_ONLY_CAP.'%), sprawdź ręcznie.',
                'points' => $honest,
            ]);
        }
        if ($aiReason !== null && $aiReason !== '') {
            array_unshift($reasons, [
                'code' => $source === 'vector' ? 'vector' : 'ai',
                'label' => $aiReason,
                'points' => $honest,
            ]);
        } elseif ($source === 'vector') {
            array_unshift($reasons, [
                'code' => 'vector',
                'label' => 'Dopasowanie wektorowe + AI',
                'points' => $honest,
            ]);
        }

        $item->main_product_id = $product->id;
        if ($item->companion_product_id !== null && (int) $item->companion_product_id === (int) $product->id) {
            $item->clearCompanion();
        }
        $item->custom_name = null;
        $item->custom_url = null;
        $item->ai_match_percent = $honest;
        $item->ai_match_reasons = $this->appendExternalHint($reasons, $this->lastExternalHint);
        $item->match_source = $source;
        $item->status = 'matched';
        $item->loadMissing('tender');
        if ($item->tender !== null) {
            $item->offer_price = $this->pricing->offerFromProduct($item->tender, $product);
        } elseif ($item->offer_price === null) {
            $item->offer_price = OfferPricing::fromPurchase($product->purchase_price);
        }
        $item->save();
        $item->load('mainProduct');
        $this->pricing->recalculateItemMargin($item);

        return true;
    }

    private function substituteReasonLabel(string $requirement): string
    {
        $parts = ['Zamiennik — inna marka/model niż w SIWZ'];
        $mfg = $this->requestedManufacturerFromSiwz($requirement);
        if ($mfg !== null) {
            $parts[] = '(wymagano: '.$mfg.')';
        }
        if ($this->modelFuzzy->hasNamedModel($requirement)) {
            $needles = $this->modelFuzzy->catalogModelNeedles($requirement);
            if ($needles !== []) {
                $parts[] = 'model: '.implode(', ', array_slice($needles, 0, 2));
            }
        }

        return implode(' ', $parts).'.';
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    private function applyNoCatalogMatch(TenderItem $item, Collection $products): void
    {
        if ($item->hasCustomOffer()) {
            return;
        }

        $item->loadMissing('mainProduct');
        $existing = $item->mainProduct;
        if ($existing instanceof Product) {
            $proposed = max((int) ($item->ai_match_percent ?? 0), 100);
            $honest = $this->persistableScore($item->requirement, $existing, $proposed);
            $userDecided = in_array($item->match_source, self::USER_DECIDED_SOURCES, true);
            // Automatyczna karta bez opisu nie zostaje w propozycji; wybór ręczny i z battlecard — tak. Nie zostaje też
            // inna karta automatyczna, gdy kod z SIWZ wskazał kartę bez opisu (HF-803 przy „Półmaska 3M 6503”).
            $codeCardElsewhere = $this->lastUndescribedCodePickId !== null
                && $this->lastUndescribedCodePickId !== (int) $existing->id;
            if ($honest !== null && ! $userDecided && (! $existing->hasDescriptionText() || $codeCardElsewhere)) {
                $this->lastUndescribedSku ??= (string) $existing->sku;
                $honest = null;
            }
            if ($honest !== null) {
                if (! in_array($item->match_source, self::USER_DECIDED_SOURCES, true)) {
                    $this->markExistingNotReconfirmed($item, $existing, $honest);
                }
                $item->save();

                return;
            }
        }

        $item->main_product_id = null;
        $item->clearCompanion();
        $item->status = 'brak';
        $item->ai_match_percent = null;
        $item->match_source = null;
        $item->ai_match_reasons = [
            $this->lastNoMatchReason === self::NO_MATCH_MODEL_UNAVAILABLE
                ? [
                    'code' => self::NO_MATCH_MODEL_UNAVAILABLE,
                    'label' => 'Model nie odpowiedział — pozycja czeka na ponowne dopasowanie (opis bez kodu nie jest dobierany po samych słowach karty).',
                    'points' => 0,
                ]
                : ($this->lastUndescribedSku !== null
                    ? [
                        'code' => self::NO_MATCH_NO_DESCRIPTION,
                        'label' => 'Karta '.$this->lastUndescribedSku.' nie ma opisu — karty bez opisu nie trafiają do propozycji. Pobierz opis karty i dopasuj ponownie.',
                        'points' => 0,
                    ]
                    : ($this->lastModelLowScore !== null
                    ? [
                        // karta jest w katalogu, ale model nie znalazł na niej dowodu kluczowego warunku —
                        // „brak produktu w katalogu” byłoby nieprawdą
                        'code' => 'model_low_score',
                        'label' => 'Model ocenił najlepszą kartę ('.$this->lastModelLowScore['sku'].') na '
                            .$this->lastModelLowScore['score'].'% — brak dowodu kluczowego warunku, karty nie zapisano; sprawdź ręcznie.',
                        'points' => 0,
                    ]
                    : [
                        'code' => 'no_match',
                        'label' => 'Brak produktu w katalogu (szukano w opisach).',
                        'points' => 0,
                    ])),
        ];
        $item->save();
        $this->pricing->recalculateItemMargin($item);
    }

    /**
     * Przetarg 1: poz. 8 (3M 9914 od modelu, 95%) i poz. 10 (apteczka Cederroth od modelu) zostały
     * wyparte w kolejnym przebiegu przez wybór po słowach karty (SPIRO P1, plastry), bo model tym razem
     * nic nie wskazał. Taki wybór nie jest nowym dowodem — poprzednia karta modelu zostaje
     * (applyNoCatalogMatch: sufit 70% i etykieta „nie potwierdzono”), o ile nadal przechodzi bramki.
     *
     * @param  array{product: Product, score: int, source: string, heuristic_only?: bool}  $pick
     */
    private function heuristicWouldReplaceModelPick(TenderItem $item, array $pick): bool
    {
        if (! ($pick['heuristic_only'] ?? false) || $item->main_product_id === null
            || (int) $item->main_product_id === (int) $pick['product']->id
            || ! in_array($item->match_source, self::MODEL_PICK_SOURCES, true)) {
            return false;
        }
        $item->loadMissing('mainProduct');
        $existing = $item->mainProduct;

        return $existing instanceof Product
            && $existing->hasDescriptionText()
            && $this->persistableScore($item->requirement, $existing, 100) !== null;
    }

    /**
     * Nowy przebieg nie potwierdził poprzedniej karty: model nie odpowiedział albo nic nie wskazał,
     * a słowa karty nie wystarczyły. Karta zostaje (oferta i ceny nie znikają), ale procent i powody
     * ze starego przebiegu nie mogą udawać świeżej oceny — sufit 70% i etykieta na górze listy.
     */
    private function markExistingNotReconfirmed(TenderItem $item, Product $existing, int $honest): void
    {
        $score = min($honest, (int) ($item->ai_match_percent ?? $honest), self::HEURISTIC_ONLY_CAP);
        $reasons = $this->explainMatch($item->requirement, $existing)['reasons'];
        $modelScore = $this->modelScoreBelowMin(
            $this->aiCandidatesCache[$this->aiCandidatesCacheKey($item->requirement)] ?? [],
            (int) $existing->id,
        );
        if ($this->lastNoMatchReason === self::NO_MATCH_MODEL_UNAVAILABLE) {
            $label = 'Model nie odpowiedział — zostawiono poprzednią kartę bez ponownej oceny (najwyżej '.self::HEURISTIC_ONLY_CAP.'%), sprawdź ręcznie.';
        } elseif ($modelScore !== null) {
            $score = min($score, $modelScore);
            $label = 'Model ocenił poprzednią kartę na '.$modelScore.'% (poniżej progu, zwykle brak dowodu kluczowego warunku) — zostawiono ją do sprawdzenia.';
        } else {
            $label = 'Ten przebieg nie potwierdził poprzedniej karty — zostawiono ją (najwyżej '.self::HEURISTIC_ONLY_CAP.'%), sprawdź ręcznie.';
        }
        array_unshift($reasons, [
            'code' => self::NOT_RECONFIRMED,
            'label' => $label,
            'points' => $score,
        ]);
        $item->ai_match_percent = $score;
        $item->ai_match_reasons = $reasons;
    }

    /**
     * @param  array{url: string, title: string}  $hint
     */
    private function applyExternalHintAsOffer(TenderItem $item, array $hint): void
    {
        $item->main_product_id = null;
        $item->clearCompanion();
        $item->custom_name = mb_substr($hint['title'], 0, 500);
        $item->custom_url = $hint['url'];
        $item->status = 'matched';
        $item->match_source = 'external';
        $item->ai_match_percent = null;
        $item->ai_match_reasons = $this->appendExternalHint([
            [
                'code' => 'no_match',
                'label' => 'Brak produktu w katalogu (szukano w opisach). Zapisano pierwszy link jako propozycję.',
                'points' => 0,
            ],
            [
                'code' => 'custom_offer',
                'label' => 'Własna propozycja (nie z katalogu SUPON): '.$hint['title'],
                'points' => 0,
                'url' => $hint['url'],
            ],
        ], $hint);
        $item->save();
        $this->pricing->recalculateItemMargin($item);
    }

    /**
     * @param  list<array{code: string, label: string, points: int, url?: string}>  $reasons
     * @param  array{url: string, title: string}|null  $hint
     * @return list<array{code: string, label: string, points: int, url?: string}>
     */
    private function appendExternalHint(array $reasons, ?array $hint): array
    {
        if ($hint === null) {
            return $reasons;
        }
        foreach ($reasons as $row) {
            if (($row['code'] ?? '') === 'external_link' && ($row['url'] ?? '') === $hint['url']) {
                return $reasons;
            }
        }
        $reasons[] = [
            'code' => 'external_link',
            'label' => 'Podpowiedź / zamiennik (nie z katalogu): '.$hint['title'],
            'points' => 0,
            'url' => $hint['url'],
        ];

        return $reasons;
    }

    /**
     * @return array{score: int, reasons: list<array{code: string, label: string, points: int}>}
     */
    public function explainMatch(string $requirement, Product $product): array
    {
        $req = $this->normalize($requirement);
        $reqTokens = $this->significantTokens($req);
        $reqCodes = $this->codeCandidates($requirement);

        $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
        $materials = is_array($payload['materials'] ?? null) ? $payload['materials'] : [];
        $features = is_array($payload['features'] ?? null) ? $payload['features'] : [];
        $useCases = is_array($payload['use_cases'] ?? null) ? $payload['use_cases'] : [];
        $normsPayload = is_array($payload['norms'] ?? null) ? $payload['norms'] : [];
        $attrs = $this->bhpAttributes->forProduct($product);
        $extra = implode(' ', [
            (string) ($product->description ?? ''),
            implode(' ', $features),
            implode(' ', $useCases),
            implode(' ', $materials),
            implode(' ', $normsPayload),
            $this->bhpAttributes->toSearchText($attrs),
        ]);
        $hay = $this->normalize(
            $product->name.' '.$product->sku.' '.$product->manufacturer.' '
            .($product->norms ?? '').' '.($product->category ?? '').' '.$extra
        );

        $reasons = [];
        $score = 0;

        if (! $this->assortment->compatibleProduct($requirement, $product)) {
            $reqFamily = $this->detectAssortmentFamily($req) ?? '?';
            $prodFamily = $this->familyFromKategoria($attrs['kategoria_bhp'] ?? null)
                ?? $this->detectAssortmentFamily($hay.' '.$this->normalize((string) ($product->category ?? '')))
                ?? '?';
            $reasons[] = [
                'code' => 'asortyment_reject',
                'label' => 'Konflikt asortymentu ('.$reqFamily.' vs '.$prodFamily.')',
                'points' => 0,
            ];

            return ['score' => 0, 'reasons' => $reasons];
        }

        $skuHit = $this->skuMatchScore($req, $reqCodes, $product);
        if ($skuHit > 0) {
            $reasons[] = ['code' => 'sku', 'label' => 'Dopasowanie SKU / kodu modelu', 'points' => $skuHit];
            $score += $skuHit;
        }
        $fuzzyHit = $this->modelFuzzy->score($requirement, $product);
        if ($fuzzyHit >= 80) {
            $reasons[] = ['code' => 'fuzzy_model', 'label' => 'Model z SIWZ (literówka)', 'points' => $fuzzyHit];
            $score = max($score, $fuzzyHit);
        }
        $typePts = $this->typeNameScore($req, $product);
        if ($typePts > 0) {
            $reasons[] = ['code' => 'type_name', 'label' => 'Zgodność typu / nazwy (np. kominiarka)', 'points' => $typePts];
            $score += $typePts;
        }

        $mat = $this->materialRequirementScore($req, $hay, $materials);
        if ($mat > 0) {
            $reasons[] = ['code' => 'material', 'label' => 'Materiał / wymaganie techniczne', 'points' => $mat];
            $score += $mat;
        }

        $brand = $this->brandModelScore($reqTokens, $hay, $product);
        if ($brand > 0) {
            $reasons[] = ['code' => 'brand', 'label' => 'Marka / model', 'points' => $brand];
            $score += $brand;
        }

        if ($skuHit === 0) {
            $codePts = $this->modelCodeInTextScore($reqCodes, $hay);
            if ($codePts > 0) {
                $reasons[] = ['code' => 'model_code', 'label' => 'Kod modelu w opisie produktu', 'points' => $codePts];
                $score += $codePts;
            }
        }

        $nameNorm = $this->normalize($product->name);
        if ($nameNorm !== '' && mb_strlen($nameNorm) >= 5
            && (str_contains($req, $nameNorm) || str_contains($nameNorm, $req))) {
            $reasons[] = ['code' => 'name', 'label' => 'Zgodność nazwy z SIWZ', 'points' => 35];
            $score += 35;
        }

        $attrScore = $this->attributeMatchScore($req, $product, $attrs);
        foreach ($attrScore['reasons'] as $reason) {
            $reasons[] = $reason;
        }
        $score += $attrScore['points'];

        $hayTokens = $this->significantTokens($hay);
        $overlap = count(array_intersect($reqTokens, $hayTokens));
        $overlapPts = min(16, $overlap * 4);
        if ($overlapPts > 0) {
            $reasons[] = ['code' => 'overlap', 'label' => 'Wspólne słowa kluczowe ('.$overlap.')', 'points' => $overlapPts];
            $score += $overlapPts;
        }

        if ($skuHit === 0) {
            similar_text($req, mb_substr($hay, 0, 220), $pct);
            $simPts = (int) round($pct * 0.12);
            if ($simPts > 0) {
                $reasons[] = ['code' => 'similar', 'label' => 'Podobieństwo tekstu', 'points' => $simPts];
                $score += $simPts;
            }
        }

        return [
            'score' => min(99, $score),
            'reasons' => $reasons,
        ];
    }
}
