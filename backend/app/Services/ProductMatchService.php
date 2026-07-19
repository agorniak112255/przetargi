<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\Tender;
use App\Models\TenderItem;
use App\Services\Ai\AiSettingsService;
use Illuminate\Support\Collection;
use Throwable;

final class ProductMatchService
{
    /** Minimalny wynik dopasowania, poniżej którego nie proponujemy produktu. */
    public const MIN_MATCH_SCORE = 65;

    /** Tokeny zbyt ogólne — nie podbijają score overlap. */
    private const STOPWORDS = [
        'rekawice', 'rekawica', 'ochronne', 'ochronna', 'ochronny', 'robocze', 'robocza',
        'produkt', 'art', 'kat', 'para', 'par', 'szt', 'sztuk', 'the', 'and', 'for',
        'with', 'bez', 'oraz', 'typ', 'model', 'kolor', 'rozmiar',
    ];

    public function __construct(
        private readonly TenderPricingService $pricing,
        private readonly ProductAiSearchService $aiSearch,
        private readonly AiSettingsService $aiSettings,
    ) {}

    /**
     * @return array{matched: int, skipped: int, avg_score: float}
     */
    public function matchTender(Tender $tender, bool $onlyEmpty = true): array
    {
        $products = Product::query()->get();
        $matched = 0;
        $skipped = 0;
        $scores = [];

        // onlyEmpty: puste + stare słabe propozycje (< progu) — żeby nie zostawały buty przy 34%
        $items = $tender->items()->when(
            $onlyEmpty,
            fn ($q) => $q->where(function ($q) {
                $q->whereNull('main_product_id')
                    ->orWhereNull('ai_match_percent')
                    ->orWhere('ai_match_percent', '<', self::MIN_MATCH_SCORE);
            })
        )->get();

        foreach ($items as $item) {
            $pick = $this->resolveBestPick($item->requirement, $products);
            if ($pick === null) {
                $item->main_product_id = null;
                $item->status = 'brak';
                $item->ai_match_percent = $this->bestScoreHint($item->requirement, $products);
                $item->ai_match_reasons = [
                    ['code' => 'no_match', 'label' => 'Brak produktu powyżej progu '.self::MIN_MATCH_SCORE.'%', 'points' => 0],
                ];
                $item->match_source = null;
                $item->save();
                $this->pricing->recalculateItemMargin($item);
                $skipped++;

                continue;
            }

            $this->applyProduct($item, $pick['product'], $pick['score'], $pick['source'] ?? 'heuristic');
            $matched++;
            $scores[] = $pick['score'];
        }

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

        return [
            'matched' => $matched,
            'skipped' => $skipped,
            'avg_score' => round($avg, 1),
        ];
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return array{product: Product, score: int}|null
     */
    public function bestMatch(string $requirement, Collection $products): ?array
    {
        $req = $this->normalize($requirement);
        $reqTokens = $this->significantTokens($req);
        $reqCodes = $this->codeCandidates($requirement);
        $best = null;

        foreach ($products as $product) {
            $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
            $materials = is_array($payload['materials'] ?? null) ? $payload['materials'] : [];
            $features = is_array($payload['features'] ?? null) ? $payload['features'] : [];
            $useCases = is_array($payload['use_cases'] ?? null) ? $payload['use_cases'] : [];
            $normsPayload = is_array($payload['norms'] ?? null) ? $payload['norms'] : [];

            $extra = implode(' ', [
                (string) ($product->description ?? ''),
                implode(' ', $features),
                implode(' ', $useCases),
                implode(' ', $materials),
                implode(' ', $normsPayload),
            ]);
            $hay = $this->normalize(
                $product->name.' '.$product->sku.' '.$product->manufacturer.' '
                .($product->norms ?? '').' '.($product->category ?? '').' '.$extra
            );
            if (! $this->assortmentsCompatible($req, $hay, $product)) {
                continue;
            }
            $score = $this->score($req, $reqTokens, $reqCodes, $hay, $product, $materials);
            if ($best === null || $score > $best['score']) {
                $best = ['product' => $product, 'score' => $score];
            }
        }

        return $best;
    }

    /**
     * Rękawice ≠ obuwie (S3/SRC) itd. — bez wspólnej kategorii wynik = pominięcie.
     */
    private function assortmentsCompatible(string $req, string $hay, Product $product): bool
    {
        $prodText = $hay.' '.$this->normalize((string) ($product->category ?? ''))
            .' '.$this->normalize((string) ($product->name ?? ''));
        $reqFamily = $this->detectAssortmentFamily($req);
        $prodFamily = $this->detectAssortmentFamily($prodText);

        if ($reqFamily === null || $prodFamily === null) {
            return true;
        }

        return $reqFamily === $prodFamily;
    }

    private function detectAssortmentFamily(string $text): ?string
    {
        if (preg_match('/\b(rekawic|glove|handschuh)\w*/u', $text) === 1) {
            return 'gloves';
        }
        // normy/klasy typowe dla obuwia BHP
        if (preg_match(
            '/\b(trzewik|polbut|sandal|obuwie|buty|butow|footwear|podeszw|podnosek'
            .'|\bs1p?\b|\bs3\b|\bsb\b|\bob\b|src|hro)\b/u',
            $text
        ) === 1) {
            return 'footwear';
        }
        if (preg_match('/\b(odziez|kurtk|spodn|kombinezon|kamizelk|softshell)\w*/u', $text) === 1) {
            return 'apparel';
        }

        return null;
    }

    /**
     * @param  list<string>  $reqTokens
     * @param  list<string>  $reqCodes
     * @param  list<string>  $materials
     */
    private function score(
        string $req,
        array $reqTokens,
        array $reqCodes,
        string $hay,
        Product $product,
        array $materials,
    ): int {
        $score = 0;
        $skuHit = $this->skuMatchScore($req, $reqCodes, $product);
        $score += $skuHit;

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

        if (preg_match_all('/en\s*[\d]+/i', $req, $m)) {
            foreach ($m[0] as $norm) {
                $n = preg_replace('/\s+/', '', mb_strtolower($norm)) ?? '';
                $pn = preg_replace('/\s+/', '', mb_strtolower((string) $product->norms)) ?? '';
                if ($n !== '' && $pn !== '' && str_contains($pn, $n)) {
                    $score += 20;
                }
            }
        }

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

        $reqCompact = preg_replace('/\s+/', '', $req) ?? $req;

        // pełny SKU jako ciąg w wymaganiu
        if (str_contains($reqCompact, $skuCompact) || preg_match(
            '/(^|[^a-z0-9])'.preg_quote($skuCompact, '/').'([^a-z0-9]|$)/u',
            $reqCompact
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

        // czysto cyfrowe krótkie fragmenty (np. 600) NIGDY nie pasują do dłuższego SKU
        if (ctype_digit($code)) {
            if (mb_strlen($code) < 5) {
                return false;
            }

            // dłuższy kod numeryczny: tylko równość lub pełne ograniczone wystąpienie
            return $code === $skuCompact;
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
            if (mb_strlen($token) < 4 || in_array($token, self::STOPWORDS, true)) {
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
            if (ctype_digit($code) || mb_strlen($code) < 4) {
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
            static fn (string $t): bool => ! in_array($t, self::STOPWORDS, true)
        ));
    }

    /**
     * Kody z SIWZ: SKU z cyfrą oraz krótkie kody WIELKIMI literami (RNITZ, REJS).
     * Pomija gołe 2–4 cyfry (rozmiary, „600”).
     *
     * @return list<string>
     */
    private function codeCandidates(string $req): array
    {
        $out = [];

        // kody z cyfrą (34-274, PK600, 60028…)
        if (preg_match_all('/\b[A-Za-z]{0,6}\d[A-Za-z0-9\-\/]{1,}\b/', $req, $m)) {
            foreach ($m[0] as $raw) {
                $c = preg_replace('/\s+/', '', $this->normalize($raw)) ?? '';
                if ($c === '' || (ctype_digit($c) && mb_strlen($c) < 5)) {
                    continue;
                }
                if (mb_strlen($c) >= 4) {
                    $out[] = $c;
                }
            }
        }

        // modele WIELKIMI literami z oryginału SIWZ (RNITZ, REJS, RDR)
        if (preg_match_all('/\b[A-Z]{3,10}\b/u', $req, $m2)) {
            foreach ($m2[0] as $raw) {
                $c = $this->normalize($raw);
                if ($c !== '' && ! in_array($c, self::STOPWORDS, true)) {
                    $out[] = $c;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Dopasuj jedną pozycję: źródło heurystyczne + top 5 z modelu AI.
     *
     * @return array<string, mixed>
     */
    public function matchItem(TenderItem $item, bool $force = false): array
    {
        // zapisana pozycja z produktem — nie nadpisuj przy ponownym wejściu / kliku
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
        $pick = $this->pickAuto($heuristic, $aiCandidates, $products);

        if ($pick === null) {
            $bestScore = max(
                $heuristic['score'] ?? 0,
                $aiCandidates[0]['score'] ?? 0,
            );
            $item->main_product_id = null;
            $item->status = 'brak';
            $item->ai_match_percent = $bestScore;
            $item->ai_match_reasons = [
                ['code' => 'no_match', 'label' => 'Brak produktu powyżej progu '.self::MIN_MATCH_SCORE.'%', 'points' => 0],
            ];
            $item->match_source = null;
            $item->save();
            $this->pricing->recalculateItemMargin($item);

            return [
                'matched' => false,
                'score' => $bestScore,
                'product_id' => null,
                'offer_price' => $item->offer_price,
                'sources' => $sources,
                'candidates' => $candidates,
                'ai_match_reasons' => $item->ai_match_reasons,
            ];
        }

        $aiReason = null;
        if (($pick['source'] ?? '') === 'ai') {
            foreach ($aiCandidates as $cand) {
                if ((int) $cand['id'] === (int) $pick['product']->id && is_string($cand['reason'] ?? null)) {
                    $aiReason = $cand['reason'];
                    break;
                }
            }
        }
        $this->applyProduct($item, $pick['product'], $pick['score'], $pick['source'], $aiReason);
        $item->load(['mainProduct', 'tender']);
        if ($item->tender !== null) {
            $this->pricing->recalculateTenderTotals($item->tender);
        }

        $p = $pick['product'];

        return [
            'matched' => true,
            'score' => $pick['score'],
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
        $heuristic = $this->bestMatch($requirement, $products);
        if ($heuristic !== null && $heuristic['score'] >= self::MIN_MATCH_SCORE) {
            return [
                'product' => $heuristic['product'],
                'score' => $heuristic['score'],
                'source' => 'heuristic',
            ];
        }

        // drugie źródło: model AI (top 5) — tylko gdy heurystyka nie pewna
        $aiCandidates = $this->aiTopCandidates($requirement, 5);

        return $this->pickAuto($heuristic, $aiCandidates, $products);
    }

    /**
     * @param  array{product: Product, score: int}|null  $heuristic
     * @param  list<array{id: int, sku: string, name: string, score: int, reason: ?string, source: string}>  $aiCandidates
     * @param  Collection<int, Product>  $products
     * @return array{product: Product, score: int, source: string}|null
     */
    private function pickAuto(?array $heuristic, array $aiCandidates, Collection $products): ?array
    {
        if ($heuristic !== null && $heuristic['score'] >= self::MIN_MATCH_SCORE) {
            return [
                'product' => $heuristic['product'],
                'score' => $heuristic['score'],
                'source' => 'heuristic',
            ];
        }

        $topAi = $aiCandidates[0] ?? null;
        if ($topAi !== null && $topAi['score'] >= self::MIN_MATCH_SCORE) {
            $product = $products->firstWhere('id', $topAi['id'])
                ?? Product::query()->find($topAi['id']);
            if ($product instanceof Product) {
                return [
                    'product' => $product,
                    'score' => $topAi['score'],
                    'source' => 'ai',
                ];
            }
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
            @set_time_limit(120);
            $result = $this->aiSearch->search($requirement, $limit);
        } catch (Throwable) {
            return [];
        }

        $out = [];
        foreach ($result['products'] as $row) {
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
                'source' => 'ai',
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
     * @param  Collection<int, Product>  $products
     */
    private function bestScoreHint(string $requirement, Collection $products): int
    {
        $heuristic = $this->bestMatch($requirement, $products);

        return (int) ($heuristic['score'] ?? 0);
    }

    private function applyProduct(
        TenderItem $item,
        Product $product,
        int $score,
        ?string $source = 'heuristic',
        ?string $aiReason = null,
    ): void {
        $explained = $this->explainMatch($item->requirement, $product);
        $reasons = $explained['reasons'];
        if ($aiReason !== null && $aiReason !== '') {
            array_unshift($reasons, [
                'code' => 'ai',
                'label' => $aiReason,
                'points' => $score,
            ]);
        }

        $item->main_product_id = $product->id;
        $item->ai_match_percent = $score;
        $item->ai_match_reasons = $reasons;
        $item->match_source = $source;
        $item->status = 'matched';
        if ($item->offer_price === null) {
            $item->offer_price = round((float) $product->purchase_price * 1.18, 2);
        }
        $item->save();
        $item->load('mainProduct');
        $this->pricing->recalculateItemMargin($item);
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
        $extra = implode(' ', [
            (string) ($product->description ?? ''),
            implode(' ', $features),
            implode(' ', $useCases),
            implode(' ', $materials),
            implode(' ', $normsPayload),
        ]);
        $hay = $this->normalize(
            $product->name.' '.$product->sku.' '.$product->manufacturer.' '
            .($product->norms ?? '').' '.($product->category ?? '').' '.$extra
        );

        $reasons = [];
        $score = 0;

        if (! $this->assortmentsCompatible($req, $hay, $product)) {
            $reqFamily = $this->detectAssortmentFamily($req) ?? '?';
            $prodFamily = $this->detectAssortmentFamily($hay.' '.$this->normalize((string) ($product->category ?? ''))) ?? '?';
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

        $normPts = 0;
        if (preg_match_all('/en\s*[\d]+/i', $req, $m)) {
            foreach ($m[0] as $norm) {
                $n = preg_replace('/\s+/', '', mb_strtolower($norm)) ?? '';
                $pn = preg_replace('/\s+/', '', mb_strtolower((string) $product->norms)) ?? '';
                if ($n !== '' && $pn !== '' && str_contains($pn, $n)) {
                    $normPts += 20;
                }
            }
        }
        if ($normPts > 0) {
            $reasons[] = ['code' => 'norma', 'label' => 'Zgodność normy EN', 'points' => $normPts];
            $score += $normPts;
        }

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

