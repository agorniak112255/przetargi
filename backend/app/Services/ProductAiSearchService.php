<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Vector\ProductVectorSearch;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

final class ProductAiSearchService
{
    public function __construct(
        private readonly OpenAiCompatibleClient $llm,
        private readonly ProductVectorSearch $vectorSearch,
    ) {}

    /**
     * @return array{
     *     query: string,
     *     total: int,
     *     products: list<array<string, mixed>>,
     *     facets: array{keywords: list<string>, chemicals: list<string>, norms: list<string>, product_type: string}
     * }
     */
    public function search(string $query, int $limit = 40): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new RuntimeException('Podaj treść wymagania dla AI.');
        }
        $limit = max(1, min(80, $limit));

        $facets = $this->resolveFacets($query);
        $candidates = $this->prefilter($query, $facets, 40);

        if ($candidates->isEmpty()) {
            return [
                'query' => $query,
                'total' => 0,
                'products' => [],
                'facets' => $facets,
                'ai_note' => 'Brak kandydatów w katalogu do oceny przez model.',
            ];
        }

        $ranked = $this->rankCandidates($query, $candidates->take(30)->values(), $limit, $facets);

        return [
            'query' => $query,
            'total' => count($ranked),
            'products' => $ranked,
            'facets' => $facets,
            'ai_note' => $ranked === []
                ? 'Model nie znalazł pasującego produktu w przekazanych pozycjach katalogu.'
                : null,
        ];
    }

    /**
     * Ranking LLM na zadanym zestawie produktów (np. po Qdrant).
     *
     * @param  Collection<int, Product>  $candidates
     * @return list<array<string, mixed>>
     */
    /**
     * @param  array{keywords?: list<string>, chemicals?: list<string>, norms?: list<string>, product_type?: string}  $facets
     */
    public function rankCandidates(string $query, Collection $candidates, int $limit = 5, array $facets = []): array
    {
        if ($candidates->isEmpty()) {
            return [];
        }

        return $this->rankWithLlm($query, $candidates->values(), max(1, min(80, $limit)), $facets);
    }

    /**
     * @return array{keywords: list<string>, chemicals: list<string>, norms: list<string>, product_type: string}
     */
    public function extractFacetsForQuery(string $query): array
    {
        return $this->resolveFacets($query);
    }

    /**
     * @return array{keywords: list<string>, chemicals: list<string>, norms: list<string>, product_type: string}
     */
    private function resolveFacets(string $query): array
    {
        $heuristic = $this->extractFacetsHeuristic($query);
        try {
            return $this->mergeFacets($heuristic, $this->extractFacetsWithLlm($query));
        } catch (Throwable) {
            return $heuristic;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function extractFacetsWithLlm(string $query): array
    {
        $raw = $this->llm->chatJson([
            [
                'role' => 'system',
                'content' => 'Analizujesz wymaganie SIWZ BHP. Zwróć wyłącznie JSON: '
                    .'{"product_type":"fartuch|rękawice|obuwie|kombinezon|…",'
                    .'"search_terms":["hasła do katalogu"],'
                    .'"exclude_types":["typy PPE, które nie pasują"],'
                    .'"norms":["EN …"],'
                    .'"chemicals":["…"]}. '
                    .'Ustal typ z rozumowania, nie z pojedynczych słów. '
                    .'„rękawy” (rękaw odzieży) ≠ rękawice. „fartuch lab.” ≠ kombinezon Tyvek ≠ buty.',
            ],
            [
                'role' => 'user',
                'content' => $query,
            ],
        ], null, 4000);

        if (isset($raw['matches']) && ! isset($raw['product_type']) && ! isset($raw['search_terms'])) {
            throw new RuntimeException('Odpowiedź modelu nie jest analizą wymagania.');
        }

        return $raw;
    }

    /**
     * @param  array{keywords: list<string>, chemicals: list<string>, norms: list<string>, product_type: string}  $heuristic
     * @param  array<string, mixed>  $ai
     * @return array{keywords: list<string>, chemicals: list<string>, norms: list<string>, product_type: string}
     */
    private function mergeFacets(array $heuristic, array $ai): array
    {
        $type = $this->normalizeProductType((string) ($ai['product_type'] ?? ''));
        if ($type === '') {
            $type = $heuristic['product_type'];
        }

        $keywords = $heuristic['keywords'];
        foreach ($ai['search_terms'] ?? [] as $term) {
            if (is_string($term) && mb_strlen(trim($term)) >= 3) {
                $keywords[] = trim($term);
            }
        }

        $chemicals = $heuristic['chemicals'];
        foreach ($ai['chemicals'] ?? [] as $chem) {
            if (is_string($chem) && trim($chem) !== '') {
                $chemicals[] = trim($chem);
            }
        }

        $norms = $heuristic['norms'];
        foreach ($ai['norms'] ?? [] as $norm) {
            if (is_string($norm) && trim($norm) !== '') {
                $norms[] = trim($norm);
            }
        }

        return [
            'keywords' => array_values(array_unique($keywords)),
            'chemicals' => array_values(array_unique($chemicals)),
            'norms' => array_values(array_unique($norms)),
            'product_type' => $type,
        ];
    }

    private function normalizeProductType(string $type): string
    {
        $ascii = $this->ascii($type);
        if ($ascii === '') {
            return '';
        }
        if (str_contains($ascii, 'fartuch') || str_contains($ascii, 'kitel') || str_contains($ascii, 'lab coat')) {
            return 'fartuch';
        }
        if (str_contains($ascii, 'rekawic') || str_contains($ascii, 'glove')) {
            return 'rękawice';
        }
        if (str_contains($ascii, 'obuwie') || str_contains($ascii, 'trzewik') || preg_match('/\bbuty\b/', $ascii) === 1) {
            return 'obuwie';
        }
        if (str_contains($ascii, 'kombinezon') || str_contains($ascii, 'coverall')) {
            return 'kombinezon';
        }
        if (str_contains($ascii, 'kominiark')) {
            return 'kominiarka';
        }

        return mb_strtolower(trim($type));
    }

    /**
     * @return array{keywords: list<string>, chemicals: list<string>, norms: list<string>, product_type: string}
     */
    private function extractFacetsHeuristic(string $query): array
    {
        $lower = mb_strtolower($query);
        $ascii = $this->ascii($lower);
        $tokens = preg_split('/[\s,;\/|+]+/u', $lower) ?: [];
        $stop = [
            'do', 'pracy', 'z', 'na', 'oraz', 'dla', 'the', 'and', 'with', 'od', 'przy',
            'bez', 'jak', 'lub', 'czy', 'jest', 'się', 'pod', 'nad', 'typ', 'rodzaju',
        ];
        $keywords = [];
        foreach ($tokens as $t) {
            $t = trim($t);
            if (mb_strlen($t) < 3 || in_array($t, $stop, true)) {
                continue;
            }
            $keywords[] = $t;
            $stem = $this->stemToken($t);
            if ($stem !== $t && mb_strlen($stem) >= 4) {
                $keywords[] = $stem;
            }
        }

        $norms = [];
        if (preg_match_all('/\bEN\s*\d+[A-Za-z0-9:+\-]*/ui', $query, $m)) {
            foreach ($m[0] as $n) {
                $norms[] = preg_replace('/\s+/', ' ', trim($n)) ?? $n;
            }
        }

        $chemicals = [];
        foreach ([
            'amoniak', 'kwas', 'olej', 'rozpuszczalnik', 'chemia', 'chemiczn',
            'benzyna', 'farba', 'lakier', 'chlor', 'zasad',
        ] as $chem) {
            if (str_contains($lower, $chem) || str_contains($ascii, $this->ascii($chem))) {
                $chemicals[] = $chem;
            }
        }
        // odmiany: rozpuszczalnikami → rozpuszczalnik
        if (str_contains($ascii, 'rozpuszczal')) {
            $chemicals[] = 'rozpuszczalnik';
            $chemicals[] = 'chemiczn';
        }

        $productType = '';
        foreach ([
            'fartuch' => 'fartuch',
            'kitel' => 'fartuch',
            'lab coat' => 'fartuch',
            'labcoat' => 'fartuch',
            'kominiarka' => 'kominiarka',
            'kominiark' => 'kominiarka',
            'czapka' => 'czapka',
            'czepek' => 'czepek',
            'rekawice' => 'rękawice',
            'rękawice' => 'rękawice',
            'buty' => 'buty',
            'obuwie' => 'obuwie',
            'okulary' => 'okulary',
            'kask' => 'kask',
            'helm' => 'hełm',
            'hełm' => 'hełm',
            'kombinezon' => 'kombinezon',
            'odziez' => 'odzież',
            'odzież' => 'odzież',
            'bluza' => 'bluza',
            'nausznik' => 'nauszniki',
            'maska' => 'maska',
        ] as $needle => $type) {
            if (str_contains($ascii, $this->ascii($needle)) || str_contains($lower, $needle)) {
                $productType = $type;
                break;
            }
        }

        return [
            'keywords' => array_values(array_unique($keywords)),
            'chemicals' => array_values(array_unique($chemicals)),
            'norms' => array_values(array_unique($norms)),
            'product_type' => $productType,
        ];
    }

    /**
     * @param  array{keywords: list<string>, chemicals: list<string>, norms: list<string>, product_type: string}  $facets
     * @return Collection<int, Product>
     */
    private function prefilter(string $query, array $facets, int $limit): Collection
    {
        $vectorHits = $this->prefilterVector($query, $facets, max($limit, 80));
        $likeHits = $this->prefilterLike($query, $facets, $limit);

        if ($likeHits->isEmpty()) {
            return $vectorHits->take($limit)->values();
        }
        if ($vectorHits->isEmpty()) {
            return $likeHits->take($limit)->values();
        }

        $seen = [];
        $merged = collect();
        foreach ($likeHits->concat($vectorHits) as $product) {
            if (! $product instanceof Product || isset($seen[$product->id])) {
                continue;
            }
            $seen[$product->id] = true;
            $merged->push($product);
            if ($merged->count() >= $limit) {
                break;
            }
        }

        return $merged->values();
    }

    /**
     * @param  array{keywords: list<string>, chemicals: list<string>, norms: list<string>, product_type: string}  $facets
     * @return Collection<int, Product>
     */
    private function prefilterLike(string $query, array $facets, int $limit): Collection
    {
        $terms = $this->expandSearchTerms($facets);
        if ($terms === []) {
            return collect();
        }

        $type = $facets['product_type'];
        $hasChem = $facets['chemicals'] !== [];

        $q = Product::query()
            ->with(['images' => static fn ($img) => $img->orderBy('sort_order')->orderBy('id')])
            ->withCount(['substitutes', 'images']);

        // typ asortymentu — nie mieszaj rękawic z butami tylko dlatego, że w opisie jest „rozpuszczalnik”
        if ($type === 'rękawice') {
            $q->where(function ($w): void {
                $w->where('name', 'like', '%rękaw%')
                    ->orWhere('name', 'like', '%rekaw%')
                    ->orWhere('name', 'like', '%glove%')
                    ->orWhere('category', 'like', '%rękaw%')
                    ->orWhere('category', 'like', '%rekaw%')
                    ->orWhere('category', 'like', '%glove%')
                    ->orWhere('description', 'like', '%rękaw%')
                    ->orWhere('description', 'like', '%rekaw%')
                    ->orWhere('description', 'like', '%glove%');
            });
            $q->where(function ($w): void {
                $w->where('category', 'not like', '%obuwie%')
                    ->where('category', 'not like', '%buty%')
                    ->where('name', 'not like', '%S3%')
                    ->where('name', 'not like', '% trzewik%');
            });
        } elseif (in_array($type, ['kominiarka', 'czapka', 'czepek', 'hełm', 'kask'], true)) {
            $q->where(function ($w) use ($type): void {
                $w->where('name', 'like', '%'.$type.'%')
                    ->orWhere('name', 'like', '%kominiark%')
                    ->orWhere('name', 'like', '%czapk%')
                    ->orWhere('name', 'like', '%hełm%')
                    ->orWhere('name', 'like', '%helm%')
                    ->orWhere('name', 'like', '%kask%')
                    ->orWhere('category', 'like', '%głow%')
                    ->orWhere('category', 'like', '%glowy%');
            });
            $q->where('name', 'not like', '%rękaw%')
                ->where('name', 'not like', '%rekaw%');
        } elseif (in_array($type, ['fartuch', 'kitel', 'odzież', 'bluza', 'kombinezon'], true)) {
            $q->where(function ($w) use ($type): void {
                $w->where('name', 'like', '%fartuch%')
                    ->orWhere('name', 'like', '%kitel%')
                    ->orWhere('name', 'like', '%lab coat%')
                    ->orWhere('name', 'like', '%bluza%')
                    ->orWhere('name', 'like', '%kombinezon%')
                    ->orWhere('name', 'like', '%odzież%')
                    ->orWhere('name', 'like', '%odziez%')
                    ->orWhere('category', 'like', '%odzież%')
                    ->orWhere('category', 'like', '%odziez%')
                    ->orWhere('category', 'like', '%fartuch%')
                    ->orWhere('description', 'like', '%fartuch%')
                    ->orWhere('description', 'like', '%kitel%');
                if ($type === 'kombinezon') {
                    $w->orWhere('name', 'like', '%tyvek%')
                        ->orWhere('name', 'like', '%coverall%');
                }
            });
            $q->where('name', 'not like', '%rękawic%')
                ->where('name', 'not like', '%rekawic%')
                ->where('name', 'not like', '%trzewik%')
                ->where('category', 'not like', '%obuwie%')
                ->where('category', 'not like', '%rękawic%')
                ->where('category', 'not like', '%rekawic%');
        } elseif ($type === 'buty' || $type === 'obuwie') {
            $q->where(function ($w): void {
                $w->where('category', 'like', '%obuwie%')
                    ->orWhere('category', 'like', '%but%')
                    ->orWhere('name', 'like', '%S3%')
                    ->orWhere('name', 'like', '%S1%')
                    ->orWhere('description', 'like', '%obuwie%')
                    ->orWhere('description', 'like', '%trzewik%');
            });
        }

        $q->where(function ($outer) use ($terms, $hasChem, $type): void {
            foreach (array_slice($terms, 0, 14) as $term) {
                $like = '%'.$term.'%';
                $outer->orWhere(function ($w) use ($like): void {
                    $w->where('name', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhere('norms', 'like', $like)
                        ->orWhere('category', 'like', $like)
                        ->orWhere('enrichment_payload', 'like', $like);
                });
            }
            // chemia / rozpuszczalniki → też rękawice chemiczne / nitryl / EN 374
            if ($hasChem && ($type === 'rękawice' || $type === '')) {
                foreach (['chemic', 'nitryl', 'nitrile', 'EN 374', '374', 'lateks', 'AlphaTec', 'olejoodporn'] as $extra) {
                    $like = '%'.$extra.'%';
                    $outer->orWhere(function ($w) use ($like): void {
                        $w->where('name', 'like', $like)
                            ->orWhere('description', 'like', $like)
                            ->orWhere('norms', 'like', $like)
                            ->orWhere('enrichment_payload', 'like', $like);
                    });
                }
            }
        });

        $pool = $q->orderByRaw("CASE WHEN enrichment_status = 'done' THEN 0 ELSE 1 END")
            ->orderByDesc('enriched_at')
            ->limit(160)
            ->get();

        if ($pool->isEmpty()) {
            return collect();
        }

        $scoreTerms = array_values(array_unique([
            ...$terms,
            ...($hasChem ? ['chemic', 'nitryl', 'nitrile', '374', 'lateks'] : []),
        ]));

        $ranked = $pool->map(function (Product $p) use ($scoreTerms, $type): array {
            $hay = $this->ascii(mb_strtolower(implode(' ', [
                (string) $p->name,
                (string) $p->category,
                (string) ($p->norms ?? ''),
                (string) ($p->description ?? ''),
                json_encode($p->enrichment_payload ?? [], JSON_UNESCAPED_UNICODE) ?: '',
            ])));
            $hits = 0;
            foreach ($scoreTerms as $term) {
                if (str_contains($hay, $this->ascii(mb_strtolower($term)))) {
                    $hits++;
                }
            }
            if ($type === 'rękawice' && (str_contains($hay, 'rekawic') || str_contains($hay, 'glove'))) {
                $hits += 2;
            }
            if (in_array($type, ['fartuch', 'kitel'], true) && (str_contains($hay, 'fartuch') || str_contains($hay, 'kitel'))) {
                $hits += 4;
            }

            return ['product' => $p, 'hits' => $hits];
        })
            ->filter(static fn (array $row): bool => $row['hits'] >= 1)
            ->sortByDesc(static fn (array $row): int => $row['hits'])
            ->take($limit)
            ->pluck('product')
            ->values();

        return $ranked;
    }

    /**
     * @param  array{keywords: list<string>, chemicals: list<string>, norms: list<string>, product_type: string}  $facets
     * @return Collection<int, Product>
     */
    private function prefilterVector(string $query, array $facets, int $limit): Collection
    {
        if (! $this->vectorSearch->enabled()) {
            return collect();
        }

        // więcej kandydatów — potem filtr asortymentu/chemii
        $hits = $this->vectorSearch->similar($query, max($limit * 2, 120));
        if ($hits === []) {
            return collect();
        }

        $ids = array_values(array_unique(array_map(
            static fn (array $h): int => (int) $h['id'],
            $hits
        )));
        $scoreById = [];
        foreach ($hits as $hit) {
            $id = (int) ($hit['id'] ?? 0);
            if ($id > 0 && ! isset($scoreById[$id])) {
                $scoreById[$id] = (float) ($hit['score'] ?? 0);
            }
        }

        $byId = Product::query()
            ->with(['images' => static fn ($img) => $img->orderBy('sort_order')->orderBy('id')])
            ->withCount(['substitutes', 'images'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $ordered = collect();
        foreach ($ids as $id) {
            if ($byId->has($id)) {
                $ordered->push($byId->get($id));
            }
        }

        return $this->filterVectorCandidates($ordered, $facets, $scoreById, $limit);
    }

    /**
     * Filtruje wyniki wektorowe jak ścieżka LIKE: typ asortymentu + sygnały chemiczne.
     *
     * @param  Collection<int, Product>  $products
     * @param  array{keywords: list<string>, chemicals: list<string>, norms: list<string>, product_type: string}  $facets
     * @param  array<int, float>  $scoreById
     * @return Collection<int, Product>
     */
    public function filterVectorCandidates(
        Collection $products,
        array $facets,
        array $scoreById = [],
        int $limit = 40,
    ): Collection {
        $type = $facets['product_type'] ?? '';
        $hasChem = ($facets['chemicals'] ?? []) !== [];
        $terms = $this->expandSearchTerms($facets);

        $ranked = $products
            ->map(function (Product $p) use ($type, $hasChem, $terms, $scoreById): ?array {
                $hay = $this->productHaystack($p);
                if (! $this->matchesAssortmentType($hay, $type)) {
                    return null;
                }
                if ($hasChem && ($type === 'rękawice' || $type === '') && ! $this->hasChemicalSignal($hay)) {
                    return null;
                }

                $hits = 0;
                foreach ($terms as $term) {
                    if (str_contains($hay, $this->ascii(mb_strtolower($term)))) {
                        $hits++;
                    }
                }
                if ($type === 'rękawice' && (str_contains($hay, 'rekawic') || str_contains($hay, 'glove') || str_contains($hay, 'gauntlet'))) {
                    $hits += 2;
                }
                if (in_array($type, ['fartuch', 'kitel'], true) && (str_contains($hay, 'fartuch') || str_contains($hay, 'kitel'))) {
                    $hits += 4;
                }
                if ($hasChem && $this->hasChemicalSignal($hay)) {
                    $hits += 3;
                }

                $vectorScore = $scoreById[$p->id] ?? 0.0;

                return [
                    'product' => $p,
                    'hits' => $hits,
                    'vector' => $vectorScore,
                ];
            })
            ->filter()
            ->sort(function (array $a, array $b): int {
                if ($a['hits'] !== $b['hits']) {
                    return $b['hits'] <=> $a['hits'];
                }

                return $b['vector'] <=> $a['vector'];
            })
            ->take(max(1, $limit))
            ->pluck('product')
            ->values();

        return $ranked;
    }

    private function productHaystack(Product $product): string
    {
        return $this->ascii(mb_strtolower(implode(' ', [
            (string) $product->name,
            (string) $product->category,
            (string) ($product->norms ?? ''),
            (string) ($product->description ?? ''),
            (string) ($product->manufacturer ?? ''),
            json_encode($product->enrichment_payload ?? [], JSON_UNESCAPED_UNICODE) ?: '',
        ])));
    }

    private function matchesAssortmentType(string $hay, string $type): bool
    {
        if ($type === '') {
            return true;
        }

        if (in_array($type, ['kominiarka', 'czapka', 'czepek', 'hełm', 'kask'], true)) {
            $needle = $this->ascii($type);
            $isHead = str_contains($hay, $needle)
                || str_contains($hay, 'kominiark')
                || str_contains($hay, 'czapk')
                || str_contains($hay, 'helm')
                || str_contains($hay, 'kask')
                || str_contains($hay, 'czepek');
            if (! $isHead) {
                return false;
            }

            return ! str_contains($hay, 'rekaw') && ! str_contains($hay, 'glove');
        }

        if ($type === 'rękawice') {
            $isGlove = str_contains($hay, 'rekawic')
                || str_contains($hay, 'glove')
                || str_contains($hay, 'gauntlet')
                || str_contains($hay, 'mitt');
            if (! $isGlove) {
                return false;
            }
            // odrzuć oczywiste obuwie
            if ((str_contains($hay, 'obuwie') || str_contains($hay, 'trzewik') || preg_match('/\bs3\b/', $hay) === 1)
                && ! str_contains($hay, 'rekawic') && ! str_contains($hay, 'glove')) {
                return false;
            }

            return true;
        }

        if (in_array($type, ['fartuch', 'kitel'], true)) {
            $isCoat = str_contains($hay, 'fartuch')
                || str_contains($hay, 'kitel')
                || str_contains($hay, 'labcoat')
                || str_contains($hay, 'lab coat');
            if (! $isCoat) {
                return false;
            }

            return ! $this->looksLikeGloves($hay) && ! $this->looksLikeFootwear($hay);
        }

        if (in_array($type, ['odzież', 'bluza', 'kombinezon'], true)) {
            $isApparel = str_contains($hay, $this->ascii($type))
                || str_contains($hay, 'fartuch')
                || str_contains($hay, 'kitel')
                || str_contains($hay, 'bluza')
                || str_contains($hay, 'odziez')
                || str_contains($hay, 'kombinezon');
            if (! $isApparel) {
                return false;
            }

            return ! $this->looksLikeGloves($hay) && ! $this->looksLikeFootwear($hay);
        }

        if ($type === 'buty' || $type === 'obuwie') {
            return str_contains($hay, 'obuwie')
                || str_contains($hay, 'but')
                || str_contains($hay, 'trzewik')
                || preg_match('/\bs[123]\b/', $hay) === 1;
        }

        // pozostałe typy — luźne dopasowanie tokenu
        return str_contains($hay, $this->ascii($type));
    }

    private function looksLikeGloves(string $hay): bool
    {
        return str_contains($hay, 'rekawic')
            || str_contains($hay, 'glove')
            || str_contains($hay, 'gauntlet');
    }

    private function looksLikeFootwear(string $hay): bool
    {
        return str_contains($hay, 'obuwie')
            || str_contains($hay, 'trzewik')
            || str_contains($hay, 'polbut')
            || preg_match('/\b(buty|s[123]|sb|ob)\b/', $hay) === 1;
    }

    private function hasChemicalSignal(string $hay): bool
    {
        foreach ([
            'chemic', 'nitryl', 'nitrile', 'en 374', 'en374', 'lateks', 'latex',
            'alphatec', 'olejoodporn', 'kwasoodporn', 'kwas', 'rozpuszczal',
            'chemical', 'pvc', 'neopren', 'neoprene', 'butyl', 'viton',
            'rubiflex', 'maxidry', 'gauntlet', 'solvent', 'acid',
        ] as $signal) {
            if (str_contains($hay, $this->ascii($signal))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{keywords: list<string>, chemicals: list<string>, norms: list<string>, product_type: string}  $facets
     * @return list<string>
     */
    private function expandSearchTerms(array $facets): array
    {
        $raw = [
            ...$facets['keywords'],
            ...$facets['chemicals'],
            ...$facets['norms'],
            $facets['product_type'],
        ];
        $out = [];
        foreach ($raw as $t) {
            if (! is_string($t)) {
                continue;
            }
            $t = trim($t);
            if (mb_strlen($t) < 3) {
                continue;
            }
            $out[] = $t;
            $stem = $this->stemToken($t);
            if ($stem !== $t && mb_strlen($stem) >= 4) {
                $out[] = $stem;
            }
        }

        return array_values(array_unique($out));
    }

    private function stemToken(string $token): string
    {
        $t = $this->ascii(mb_strtolower(trim($token)));
        // rękawy (rękaw kurtki) ≠ rękawice
        if (preg_match('/^rekaw(?!ic)/u', $t) === 1) {
            return $t;
        }
        foreach (['ami', 'ach', 'owi', 'owie', 'ami', 'ych', 'ymi', 'ego', 'emu', 'iej', 'ich', 'owi', 'ami'] as $suf) {
            if (str_ends_with($t, $suf) && mb_strlen($t) - mb_strlen($suf) >= 4) {
                return mb_substr($t, 0, -mb_strlen($suf));
            }
        }
        foreach (['em', 'om', 'ow', 'ie', 'y', 'i', 'a', 'e'] as $suf) {
            if (str_ends_with($t, $suf) && mb_strlen($t) - mb_strlen($suf) >= 5) {
                return mb_substr($t, 0, -mb_strlen($suf));
            }
        }

        return $t;
    }

    private function ascii(string $s): string
    {
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];

        return strtr(mb_strtolower($s), $map);
    }

    /**
     * @param  Collection<int, Product>  $candidates
     * @param  array{keywords?: list<string>, chemicals?: list<string>, norms?: list<string>, product_type?: string}  $facets
     * @return list<array<string, mixed>>
     */
    private function rankWithLlm(string $query, Collection $candidates, int $limit, array $facets = []): array
    {
        $cards = $candidates->map(function (Product $p): array {
            $payload = is_array($p->enrichment_payload) ? $p->enrichment_payload : [];

            return [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => mb_substr((string) $p->name, 0, 120),
                'category' => $p->category,
                'manufacturer' => $p->manufacturer,
                'norms' => $p->norms,
                'description' => mb_substr((string) ($p->description ?? ''), 0, 280),
                'use_cases' => array_slice($this->stringList($payload['use_cases'] ?? null), 0, 4),
                'features' => array_slice($this->stringList($payload['features'] ?? null), 0, 4),
            ];
        })->values()->all();

        $hint = is_string($facets['product_type'] ?? null) && $facets['product_type'] !== ''
            ? "\nTyp z analizy wymagania: {$facets['product_type']}."
            : '';

        $json = json_encode($cards, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $raw = $this->llm->chatJson([
            [
                'role' => 'system',
                'content' => 'Jesteś ekspertem BHP. Najpierw zrozum wymaganie SIWZ (jaki to produkt), '
                    .'potem oceń wyłącznie pozycje z listy. '
                    .'JSON: {"matches":[{"id":1,"score":0-100,"reason":"uzasadnienie"}]} . '
                    .'Inny asortyment (fartuch≠kombinezon≠rękawice≠buty; „rękawy” to rękaw odzieży) → nie zwracaj. '
                    .'Jeśli żaden produkt nie jest tym typem: {"matches":[]}. '
                    .'score>=40 tylko przy zgodnym typie. Max 5. Tylko id z listy.',
            ],
            [
                'role' => 'user',
                'content' => "Wymaganie:\n{$query}{$hint}\n\nProdukty:\n{$json}",
            ],
        ], null, 8000);

        $matches = is_array($raw['matches'] ?? null) ? $raw['matches'] : [];
        $byId = $candidates->keyBy('id');
        $out = [];

        foreach ($matches as $m) {
            if (! is_array($m)) {
                continue;
            }
            $id = (int) ($m['id'] ?? 0);
            $score = (int) ($m['score'] ?? 0);
            if ($id <= 0 || $score < 40 || ! $byId->has($id)) {
                continue;
            }
            /** @var Product $product */
            $product = $byId->get($id);
            $row = $this->productToRow($product);
            $row['ai_match_percent'] = min(99, max(0, $score));
            $row['ai_match_reason'] = is_string($m['reason'] ?? null) ? $m['reason'] : null;
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }

        usort($out, static fn (array $a, array $b): int => ($b['ai_match_percent'] <=> $a['ai_match_percent']));

        return $out;
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
