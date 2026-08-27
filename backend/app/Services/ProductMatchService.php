<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Services\Ai\AiSettingsService;
use App\Services\Ai\AiTask;
use App\Services\Vector\ProductVectorSearch;
use App\Support\BhpAttributeNormalizer;
use App\Support\OfferPricing;
use App\Support\PpeAssortment;
use App\Support\ProductModelFuzzy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class ProductMatchService
{
    /** Minimalny wynik dopasowania, poniżej którego nie proponujemy produktu. */
    public const MIN_MATCH_SCORE = 65;

    /** Od tego wyniku zapisujemy pierwszy trafiony produkt AI w ofercie. */
    public const APPLY_MATCH_SCORE = 40;

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

    /** Typowe słowa SIWZ pisane KAPITALIKAMI — to nie są kody modelu. */
    private const GENERIC_SIWZ_CODES = [
        'kurtka', 'bluza', 'spodnie', 'odziez', 'ubranie', 'komplet', 'zestaw',
        'ochronna', 'ochronne', 'robocza', 'robocze', 'odblask', 'ostrzegaw',
        'elektryk', 'spawal', 'laboratory', 'fartuch', 'kamizelka', 'kitel',
        'kombinezon', 'kalesony', 'rekawice', 'rekawica', 'oslona', 'przylbica',
        'polmaska', 'okulary', 'gogle', 'nauszniki', 'szelki', 'trzewiki',
        'obuwie', 'helm', 'kask', 'kominiarka', 'siatkowa', 'odblaskowa',
    ];

    public function __construct(
        private readonly TenderPricingService $pricing,
        private readonly ProductAiSearchService $aiSearch,
        private readonly AiSettingsService $aiSettings,
        private readonly ProductVectorSearch $vectorSearch,
        private readonly BhpAttributeNormalizer $bhpAttributes,
        private readonly ExternalCatalogHintService $externalHints,
        private readonly ProductModelFuzzy $modelFuzzy,
        private readonly PpeAssortment $assortment,
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
        $products = Product::query()->get();
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
                            ->orWhereNull('ai_match_percent')
                            ->orWhere('ai_match_percent', '<', self::MIN_MATCH_SCORE);
                    });
                })
            )->get();

        $startedAt = time();
        $batchCount = $items->count();
        $displayTotal = $progressTotal ?? $batchCount;
        $done = 0;
        $this->writeMatchProgress(
            (int) $tender->id,
            $progressOffset,
            $displayTotal,
            'running',
            null,
            null,
            $startedAt
        );

        $changes = [];
        foreach ($items as $item) {
            $beforeId = $item->main_product_id !== null ? (int) $item->main_product_id : null;
            $beforeSku = $item->mainProduct?->sku;
            if ($item->isManualCustomOffer()) {
                $skipped++;
                $changes[] = $this->matchChangeRow($item, 'skipped_custom', $beforeSku, $beforeSku);
            } else {
                $this->lastExternalHint = null;
                $pick = $this->resolveBestPick($item->requirement, $products);
                $applied = $pick !== null && $this->applyProduct(
                    $item,
                    $pick['product'],
                    $pick['score'],
                    $pick['source'] ?? 'heuristic'
                );
                if (! $applied) {
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
                $startedAt
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
            'changes' => $changedRows,
        ];
    }

    public static function progressCacheKey(int $tenderId): string
    {
        return 'tender-match-progress:'.$tenderId;
    }

    /**
     * @return array{status: string, done: int, total: int, line_no: int|null, requirement: string|null, started_at: int|null}
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
            ];
        }

        return [
            'status' => is_string($raw['status'] ?? null) ? $raw['status'] : 'idle',
            'done' => (int) ($raw['done'] ?? 0),
            'total' => (int) ($raw['total'] ?? 0),
            'line_no' => isset($raw['line_no']) && is_numeric($raw['line_no']) ? (int) $raw['line_no'] : null,
            'requirement' => is_string($raw['requirement'] ?? null) ? $raw['requirement'] : null,
            'started_at' => isset($raw['started_at']) && is_numeric($raw['started_at']) ? (int) $raw['started_at'] : null,
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
    ): void {
        Cache::put(self::progressCacheKey($tenderId), [
            'status' => $status,
            'done' => $done,
            'total' => $total,
            'line_no' => $lineNo,
            'requirement' => $requirement !== null ? mb_substr($requirement, 0, 120) : null,
            'started_at' => $startedAt ?? time(),
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
                $products = $named;
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
            if (! $this->assortmentsCompatible($req, $hay, $product, $attrs)) {
                continue;
            }
            $score = $this->score($req, $reqTokens, $reqCodes, $hay, $product, $materials, $attrs);
            $fuzzy = $this->modelFuzzy->score($requirement, $product);
            if ($fuzzy >= 80) {
                $score = max($score, $fuzzy);
            }
            $scored[] = ['product' => $product, 'score' => $score];
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, max(1, $limit));
    }

    /**
     * Rękawice ≠ obuwie — kategoria z atrybutów ma pierwszeństwo przed zgadywaniem z tekstu.
     *
     * @param  array<string, mixed>  $attrs
     */
    private function assortmentsCompatible(string $req, string $hay, Product $product, array $attrs = []): bool
    {
        $prodText = $hay.' '.$this->normalize((string) ($product->category ?? ''))
            .' '.$this->normalize((string) ($product->name ?? ''));
        $kat = is_string($attrs['kategoria_bhp'] ?? null) ? $attrs['kategoria_bhp'] : null;

        return $this->assortment->compatible($req, $prodText, $kat);
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
        if ($klasa !== '' && (str_contains($req, $this->normalize($klasa))
            || preg_match('/\b'.preg_quote($klasa, '/').'\b/u', $req) === 1)) {
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
     * Kody z SIWZ: SKU z cyfrą oraz krótkie kody WIELKIMI literami (RNITZ, REJS).
     * Pomija gołe 2–4 cyfry (rozmiary, „600”).
     *
     * @return list<string>
     */
    private function stripNormNumbers(string $text): string
    {
        $t = preg_replace('/\ben(?:\s*iso)?\s*\d+(?:\s+\d+)*/u', ' ', $text) ?? $text;

        return preg_replace('/\biso\s*\d+/u', ' ', $t) ?? $t;
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

        return 40;
    }

    private function codeCandidates(string $req): array
    {
        $out = [];
        $normSkip = ['en', 'iso', 'ce', 'ppe', 'kat'];

        if (preg_match_all('/\b[A-Z]{3,10}\b/u', $req, $m2)) {
            foreach ($m2[0] as $raw) {
                $c = $this->normalize($raw);
                if ($c !== '' && ! in_array($c, self::STOPWORDS, true) && ! in_array($c, $normSkip, true)
                    && ! $this->isGenericSiwzCode($c) && ! $this->isClothingSize($c)) {
                    $out[] = $c;
                }
            }
        }

        $stripped = $this->stripNormNumbers($this->normalize($req));
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

        $products = Product::query()->get();
        $heuristic = $this->bestMatch($item->requirement, $products);
        $aiCandidates = $this->aiTopCandidates($item->requirement, 5);

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
        $pick = $this->resolveBestPick($item->requirement, $products);

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

        $aiReason = null;
        if (in_array($pick['source'] ?? '', ['ai', 'vector'], true)) {
            foreach ($aiCandidates as $cand) {
                if ((int) $cand['id'] === (int) $pick['product']->id && is_string($cand['reason'] ?? null)) {
                    $aiReason = $cand['reason'];
                    break;
                }
            }
        }
        if (! $this->applyProduct($item, $pick['product'], $pick['score'], $pick['source'], $aiReason)) {
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
    private function resolveBestPick(string $requirement, Collection $products): ?array
    {
        $skuPick = $this->strongSkuPick($requirement, $products);
        if ($skuPick !== null) {
            $honest = $this->persistableScore($requirement, $skuPick['product'], $skuPick['score']);
            if ($honest !== null) {
                $skuPick['score'] = $honest;

                return $skuPick;
            }
        }

        $described = $this->withDescriptions($products);
        $heuristic = $described->isEmpty() ? null : $this->bestMatch($requirement, $described);
        $aiCandidates = $this->aiTopCandidates($requirement, 5);
        $picked = $this->pickAuto($requirement, $heuristic, $aiCandidates, $described);
        if ($picked === null) {
            return null;
        }

        $picked['product'] = $this->resolveCatalogBySku($picked['product'], $products);
        $honest = $this->persistableScore($requirement, $picked['product'], $picked['score']);
        if ($honest === null) {
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
        return $products->filter(static fn (Product $p): bool => $p->hasUsableDescription())->values();
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
     * @param  Collection<int, Product>  $products
     * @return array{product: Product, score: int, source: string}|null
     */
    private function strongSkuPick(string $requirement, Collection $products): ?array
    {
        $best = null;
        $reqNorm = $this->normalize($requirement);
        $reqCodes = $this->codeCandidates($requirement);
        foreach ($products as $product) {
            if (! $this->assortment->compatibleProduct($requirement, $product)) {
                continue;
            }
            $score = max(
                $this->skuMatchScore($reqNorm, $reqCodes, $product),
                $this->modelFuzzy->score($requirement, $product)
            );
            if ($score < 70) {
                continue;
            }
            $candidate = [
                'product' => $product,
                'score' => max(self::MIN_MATCH_SCORE, $score),
                'source' => 'heuristic',
            ];
            if ($best === null
                || $candidate['score'] > $best['score']
                || ($candidate['score'] === $best['score']
                    && mb_strlen((string) $product->sku) < mb_strlen((string) $best['product']->sku))) {
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
        ) >= 70 || $this->modelFuzzy->score($requirement, $product) >= 80;
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
        if ($heuristic !== null && $heuristic['score'] >= self::APPLY_MATCH_SCORE
            && $this->hasStrongSkuInRequirement($requirement, $heuristic['product'])
            && $this->persistableScore($requirement, $heuristic['product'], $heuristic['score']) !== null) {
            return [
                'product' => $heuristic['product'],
                'score' => $heuristic['score'],
                'source' => 'heuristic',
            ];
        }

        foreach ($aiCandidates as $topAi) {
            if ($topAi['score'] < self::APPLY_MATCH_SCORE) {
                continue;
            }
            $product = $products->firstWhere('id', $topAi['id'])
                ?? Product::query()->find($topAi['id']);
            if (! $product instanceof Product) {
                continue;
            }
            if (! $this->assortment->compatibleProduct($requirement, $product)) {
                continue;
            }
            if (! $this->honorsSpecificModelCodes($requirement, $product)) {
                continue;
            }
            $honest = $this->persistableScore($requirement, $product, $topAi['score']);
            if ($honest === null) {
                continue;
            }
            if (! $this->hasStrongSkuInRequirement($requirement, $product)
                && $this->explainMatch($requirement, $product)['score'] < 40) {
                continue;
            }

            return [
                'product' => $product,
                'score' => $honest,
                'source' => (string) ($topAi['source'] ?? 'ai'),
            ];
        }

        return null;
    }

    /**
     * @return list<array{id: int, sku: string, name: string, score: int, reason: ?string, source: string}>
     */
    private function aiTopCandidates(string $requirement, int $limit = 5): array
    {
        if (! $this->aiSettings->isReady()) {
            return [];
        }

        try {
            $result = $this->aiSearch->search($requirement, $limit, true, AiTask::TenderMatch);
        } catch (Throwable) {
            return [];
        }

        $hint = $result['external_hint'] ?? null;
        if (is_array($hint) && isset($hint['url'], $hint['title'])) {
            $this->lastExternalHint = [
                'url' => (string) $hint['url'],
                'title' => (string) $hint['title'],
            ];
        }

        $source = $this->vectorSearch->enabled() ? 'vector' : 'ai';
        $mapped = $this->mapAiSearchRows($result['products'] ?? [], $limit, $source);
        if ($mapped === []) {
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

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{id: int, sku: string, name: string, score: int, reason: ?string, source: string}>
     */
    private function mapAiSearchRows(array $rows, int $limit, string $source): array
    {
        $out = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $out[] = [
                'id' => $id,
                'sku' => (string) ($row['sku'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'score' => (int) ($row['ai_match_percent'] ?? 0),
                'reason' => is_string($row['ai_match_reason'] ?? null) ? $row['ai_match_reason'] : null,
                'source' => $source,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
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

        $list = array_values($byId);
        usort($list, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($list, 0, 5);
    }

    /**
     * Konflikt rodzaju (okulary ≠ rękawice) = brak zapisu.
     * AI nie może zawyżyć % ponad heurystykę / SKU.
     */
    private function persistableScore(string $requirement, Product $product, int $proposed): ?int
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
        if ($honest >= self::APPLY_MATCH_SCORE) {
            return $honest;
        }
        if ($skuish >= 70) {
            return max(self::MIN_MATCH_SCORE, $skuish);
        }

        return null;
    }

    private function applyProduct(
        TenderItem $item,
        Product $product,
        int $score,
        ?string $source = 'heuristic',
        ?string $aiReason = null,
    ): bool {
        $honest = $this->persistableScore($item->requirement, $product, $score);
        if ($honest === null) {
            return false;
        }

        $explained = $this->explainMatch($item->requirement, $product);
        $reasons = $explained['reasons'];
        if (($reasons[0]['code'] ?? '') === 'asortyment_reject') {
            return false;
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
        $item->custom_name = null;
        $item->custom_url = null;
        $item->ai_match_percent = $honest;
        $item->ai_match_reasons = $this->appendExternalHint($reasons, $this->lastExternalHint);
        $item->match_source = $source;
        $item->status = 'matched';
        if ($item->offer_price === null) {
            $item->offer_price = OfferPricing::fromPurchase($product->purchase_price);
        }
        $item->save();
        $item->load('mainProduct');
        $this->pricing->recalculateItemMargin($item);

        return true;
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    private function applyNoCatalogMatch(TenderItem $item, Collection $products): void
    {
        if ($item->hasCustomOffer()) {
            return;
        }

        $hint = $this->lastExternalHint ?? $this->externalHints->hint($item->requirement);
        $item->loadMissing('mainProduct');
        $existing = $item->mainProduct;
        if ($existing instanceof Product) {
            $proposed = max((int) ($item->ai_match_percent ?? 0), 100);
            if ($this->persistableScore($item->requirement, $existing, $proposed) !== null) {
                $reasons = is_array($item->ai_match_reasons) ? $item->ai_match_reasons : [];
                $item->ai_match_reasons = $this->appendExternalHint($reasons, $hint);
                $item->save();

                return;
            }
        }

        if ($hint !== null) {
            $this->applyExternalHintAsOffer($item, $hint);

            return;
        }

        $item->main_product_id = null;
        $item->status = 'brak';
        $item->ai_match_percent = null;
        $item->match_source = null;
        $item->ai_match_reasons = [
            [
                'code' => 'no_match',
                'label' => 'Brak produktu w katalogu (szukano w opisach). Nie dodano pozycji z internetu.',
                'points' => 0,
            ],
        ];
        $item->save();
        $this->pricing->recalculateItemMargin($item);
    }

    /**
     * @param  array{url: string, title: string}  $hint
     */
    private function applyExternalHintAsOffer(TenderItem $item, array $hint): void
    {
        $item->main_product_id = null;
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

        if (! $this->assortmentsCompatible($req, $hay, $product, $attrs)) {
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
