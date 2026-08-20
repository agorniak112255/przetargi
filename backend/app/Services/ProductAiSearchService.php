<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Vector\ProductVectorSearch;
use App\Support\PpeAssortment;
use App\Support\ProductModelFuzzy;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

final class ProductAiSearchService
{
    public function __construct(
        private readonly OpenAiCompatibleClient $llm,
        private readonly ProductVectorSearch $vectorSearch,
        private readonly ExternalCatalogHintService $externalHints,
        private readonly ProductModelFuzzy $modelFuzzy,
        private readonly PpeAssortment $assortment,
    ) {}

    /**
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
    public function search(string $query, int $limit = 40, bool $withExternalHint = true): array
    {
        $query = trim($query);
        if ($query === '') {
            throw new RuntimeException('Podaj treść wymagania dla AI.');
        }
        $limit = max(1, min(80, $limit));

        $intent = $this->understandRequirement($query);
        $candidates = $this->keepCompatible($query, $this->retrieveCandidates($query, $intent, 40));
        $named = $candidates->filter(
            fn (Product $p): bool => $this->modelFuzzy->matches($query, $p)
        )->values();
        if ($named->isNotEmpty()) {
            $ranked = $this->rowsFromNamedModels($query, $named, $limit);

            return [
                'query' => $query,
                'total' => count($ranked),
                'products' => $ranked,
                'needed' => $intent['needed'],
                'search_phrases' => $intent['search_phrases'],
                'ai_note' => null,
                'external_hint' => null,
            ];
        }

        if ($candidates->isEmpty()) {
            return $this->emptyResult($query, $intent, $withExternalHint, 'Brak kart z opisem w katalogu do porównania. Nie dodano produktu z internetu.');
        }

        $ranked = $this->rankWithLlm($query, $candidates->take(30)->values(), $limit, $intent['needed']);
        if ($ranked === []) {
            return $this->emptyResult($query, $intent, $withExternalHint, 'Model nie znalazł pasującego produktu w katalogu. Nie dodano pozycji z internetu.');
        }

        return [
            'query' => $query,
            'total' => count($ranked),
            'products' => $ranked,
            'needed' => $intent['needed'],
            'search_phrases' => $intent['search_phrases'],
            'ai_note' => null,
            'external_hint' => null,
        ];
    }

    /**
     * @param  Collection<int, Product>  $candidates
     * @return list<array<string, mixed>>
     */
    public function rankCandidates(string $query, Collection $candidates, int $limit = 5, ?string $needed = null): array
    {
        if ($candidates->isEmpty()) {
            return [];
        }

        return $this->rankWithLlm($query, $candidates->values(), max(1, min(80, $limit)), $needed);
    }

    /**
     * @return array{needed: string, search_phrases: list<string>}
     */
    public function understandRequirement(string $query): array
    {
        try {
            $raw = $this->llm->chatJson([
                [
                    'role' => 'system',
                    'content' => 'Jesteś ekspertem BHP. Z SIWZ wyodrębnij NAZWĘ produktu (rzeczownik), zanim cechy i normy. '
                        .'needed: krótka nazwa na początku (np. kamizelka odblaskowa żółta) — bez EN i bez samego przymiotnika. '
                        .'search_phrases: 2-8 fraz; PIERWSZE 2 to wyłącznie nazwa/synonim (kamizelka, kamizelka odblaskowa). '
                        .'Cechy (siatkowa, nadruk) i normy EN dopiero na końcu. '
                        .'Przymiotnik wspólny nie zastępuje nazwy: kamizelka ≠ osłona twarzy; rękawy ≠ rękawice. '
                        .'Synonimy: obuwie/buty/trzewiki, kurtka/bluza ochronna. '
                        .'Popraw literówki modelu (TEPM-ICE → TEMP-ICE). Nie klasyfikuj sztywną listą typów. '
                        .'JSON: {"needed":"nazwa szukanego produktu","search_phrases":["najpierw nazwa","potem cechy/normy"]}.',
                ],
                [
                    'role' => 'user',
                    'content' => $query,
                ],
            ], null, 4000);

            return $this->parseIntent($raw, $query);
        } catch (Throwable) {
            return [
                'needed' => $query,
                'search_phrases' => $this->fallbackPhrases($query),
            ];
        }
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{needed: string, search_phrases: list<string>}
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

        return [
            'needed' => $needed,
            'search_phrases' => array_values(array_unique($phrases)),
        ];
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
            if (mb_strlen($token) < 4 || in_array($token, $stop, true) || $this->isClothingSizePhrase($token)) {
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

    /**
     * @param  array{needed: string, search_phrases: list<string>}  $intent
     * @return Collection<int, Product>
     */
    private function retrieveCandidates(string $query, array $intent, int $limit): Collection
    {
        $searchText = $intent['needed'] !== '' ? $intent['needed'] : $query;
        $codeHits = $this->retrieveByModelCode($query.' '.$searchText, $limit);
        $fuzzyHits = $this->retrieveByFuzzyModel($query.' '.$searchText, $limit);
        $priority = $this->uniqueProducts($fuzzyHits->concat($codeHits), $limit);

        if ($this->modelFuzzy->hasNamedModel($query) && $priority->isNotEmpty()) {
            $namedPriority = $this->keepCompatible($query, $priority);
            if ($namedPriority->isNotEmpty()) {
                return $namedPriority;
            }
        }

        $likeHits = $this->retrieveLike($intent['search_phrases'], $limit);
        $vectorHits = $likeHits->isEmpty()
            ? $this->retrieveVector($searchText, max($limit, 80))
            : collect();

        $seen = [];
        $merged = collect();
        foreach ($priority->concat($likeHits)->concat($vectorHits) as $product) {
            if (! $product instanceof Product || isset($seen[$product->id])) {
                continue;
            }
            $seen[$product->id] = true;
            $merged->push($product);
            if ($merged->count() >= $limit) {
                break;
            }
        }

        return $this->keepCompatible($query, $merged->values());
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return Collection<int, Product>
     */
    private function keepCompatible(string $query, Collection $products): Collection
    {
        return $products
            ->filter(fn (Product $p): bool => $this->assortment->compatibleProduct($query, $p))
            ->values();
    }

    /**
     * Karty bez opisu też — gdy SIWZ ma kod modelu występujący w SKU/nazwie (6503 → 6503-EN).
     *
     * @return Collection<int, Product>
     */
    private function retrieveByModelCode(string $query, int $limit): Collection
    {
        $codes = $this->modelCodePhrases($query);
        if ($codes === []) {
            return collect();
        }

        $q = Product::query()
            ->with(['images' => static fn ($img) => $img->orderBy('sort_order')->orderBy('id')])
            ->withCount(['substitutes', 'images']);

        $q->where(function ($outer) use ($codes): void {
            foreach ($codes as $code) {
                $like = addcslashes($code, '%_\\');
                $outer->orWhere('sku', 'like', $like.'%')
                    ->orWhere('name', 'like', '%'.$like.'%');
            }
        });

        return $q->limit(max(8, $limit))->get()->values();
    }

    /**
     * Karty bez opisu też — literówka w modelu (TEPM-ICE → TEMP-ICE).
     *
     * @return Collection<int, Product>
     */
    private function retrieveByFuzzyModel(string $query, int $limit): Collection
    {
        $brands = $this->modelFuzzy->manufacturerHints($query);
        $q = Product::query()
            ->with(['images' => static fn ($img) => $img->orderBy('sort_order')->orderBy('id')])
            ->withCount(['substitutes', 'images']);

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
            $nums = $this->modelFuzzy->modelNumbers($query);
            if ($parts === [] && $nums === []) {
                return collect();
            }
            foreach ($parts as $part) {
                $like = '%'.addcslashes($part, '%_\\').'%';
                $q->where(function ($w) use ($like): void {
                    $w->where('name', 'like', $like)->orWhere('sku', 'like', $like);
                });
            }
            if ($nums !== []) {
                $q->where(function ($w) use ($nums): void {
                    foreach ($nums as $num) {
                        $like = '%'.addcslashes($num, '%_\\').'%';
                        $w->orWhere('name', 'like', $like)->orWhere('sku', 'like', $like);
                    }
                });
            }
        } else {
            return collect();
        }

        return $q->limit(800)
            ->get()
            ->filter(fn (Product $p): bool => $this->modelFuzzy->matches($query, $p))
            ->sortByDesc(fn (Product $p): int => $this->modelFuzzy->score($query, $p))
            ->take(max(8, $limit))
            ->values();
    }

    /**
     * @param  Collection<int, Product>  $products
     * @return Collection<int, Product>
     */
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
        $out = [];
        foreach ($products as $product) {
            if (! $product instanceof Product) {
                continue;
            }
            $row = $this->productToRow($product);
            $row['ai_match_percent'] = min(99, max(80, $this->modelFuzzy->score($query, $product)));
            $row['ai_match_reason'] = 'Marka i model z SIWZ (literówka w nazwie modelu jest dopuszczalna).';
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
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
                if ($c === '' || mb_strlen($c) < 4) {
                    continue;
                }
                if (ctype_digit($c) && mb_strlen($c) < 4) {
                    continue;
                }
                $out[] = $c;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return Collection<int, Product>
     */
    private function retrieveVector(string $query, int $limit): Collection
    {
        if (! $this->vectorSearch->enabled()) {
            return collect();
        }

        $hits = $this->vectorSearch->similar($query, max($limit, 80));
        if ($hits === []) {
            return collect();
        }

        $ids = [];
        foreach ($hits as $hit) {
            $id = (int) ($hit['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return collect();
        }

        $byId = Product::query()
            ->with(['images' => static fn ($img) => $img->orderBy('sort_order')->orderBy('id')])
            ->withCount(['substitutes', 'images'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $ordered = collect();
        foreach ($ids as $id) {
            $product = $byId->get($id);
            if (! $product instanceof Product || ! $product->hasUsableDescription()) {
                continue;
            }
            $ordered->push($product);
            if ($ordered->count() >= $limit) {
                break;
            }
        }

        return $ordered->values();
    }

    /**
     * @param  list<string>  $phrases
     * @return Collection<int, Product>
     */
    private function retrieveLike(array $phrases, int $limit): Collection
    {
        $phrases = array_values(array_filter(
            $phrases,
            static fn (string $p): bool => mb_strlen($p) >= 3
        ));
        if ($phrases === []) {
            return collect();
        }

        $q = Product::query()
            ->with(['images' => static fn ($img) => $img->orderBy('sort_order')->orderBy('id')])
            ->withCount(['substitutes', 'images']);
        $this->constrainToDescribed($q);

        $q->where(function ($outer) use ($phrases): void {
            foreach (array_slice($phrases, 0, 14) as $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $outer->orWhere(function ($w) use ($like): void {
                    $w->where('name', 'like', $like)
                        ->orWhere('sku', 'like', $like)
                        ->orWhere('manufacturer', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhere('norms', 'like', $like)
                        ->orWhere('category', 'like', $like)
                        ->orWhere('enrichment_payload', 'like', $like);
                });
            }
        });

        $pool = $q->orderByRaw("CASE WHEN enrichment_status = 'done' THEN 0 ELSE 1 END")
            ->orderByDesc('enriched_at')
            ->limit(160)
            ->get();

        if ($pool->isEmpty()) {
            return collect();
        }

        return $pool->map(function (Product $p) use ($phrases): array {
            $hay = mb_strtolower(implode(' ', [
                (string) $p->name,
                (string) $p->category,
                (string) ($p->norms ?? ''),
                (string) ($p->description ?? ''),
                json_encode($p->enrichment_payload ?? [], JSON_UNESCAPED_UNICODE) ?: '',
            ]));
            $hits = 0;
            foreach ($phrases as $term) {
                if (str_contains($hay, mb_strtolower($term))) {
                    $hits++;
                }
            }

            return ['product' => $p, 'hits' => $hits];
        })
            ->filter(static fn (array $row): bool => $row['hits'] >= 1)
            ->sortByDesc(static fn (array $row): int => $row['hits'])
            ->take($limit)
            ->pluck('product')
            ->values();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<Product>  $q
     */
    private function constrainToDescribed($q): void
    {
        $q->where(function ($w): void {
            $w->where('enrichment_status', Product::ENRICHMENT_DONE)
                ->orWhere(function ($d): void {
                    $d->whereNotNull('description')
                        ->where('description', '!=', '')
                        ->whereRaw('LENGTH(TRIM(description)) >= 24');
                });
        });
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
            $hint = $this->externalHints->hint($intent['needed'] !== '' ? $intent['needed'] : $query);
            if ($hint !== null) {
                $note .= ' Podpowiedź spoza katalogu (link).';
            }
        }

        return [
            'query' => $query,
            'total' => 0,
            'products' => [],
            'needed' => $intent['needed'],
            'search_phrases' => $intent['search_phrases'],
            'ai_note' => $note,
            'external_hint' => $hint,
        ];
    }

    /**
     * @param  Collection<int, Product>  $candidates
     * @return list<array<string, mixed>>
     */
    private function rankWithLlm(string $query, Collection $candidates, int $limit, ?string $needed = null): array
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

        $neededLine = is_string($needed) && trim($needed) !== ''
            ? "\nSzukany produkt (z analizy):\n".trim($needed)
            : '';

        $json = json_encode($cards, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $raw = $this->llm->chatJson([
            [
                'role' => 'system',
                'content' => 'Jesteś ekspertem BHP. Ranking w dwóch krokach — nie mieszaj ich. '
                    .'1) NAZWA: rzeczownik z wymagania = ten sam produkt co w polu name karty '
                    .'(synonimy: buty=obuwie=trzewiki; kurtka≈bluza ochronna). '
                    .'Inny rodzaj → nie zwracaj: kamizelka ≠ osłona twarzy; rękawice ≠ obuwie. '
                    .'Wspólna cecha (siatkowa) albo ta sama norma EN NIE wystarczy. '
                    .'2) Dopiero potem cechy, materiał, klasa i normy — tylko wśród kart z kroku 1. '
                    .'Marka/model z SIWZ wygrywa przy literówce (TEPM-ICE=TEMP-ICE); nie zmieniaj marki przez EN. '
                    .'Brak zgodnej nazwy: {"matches":[]}. '
                    .'JSON: {"matches":[{"id":1,"score":0-100,"reason":"uzasadnienie"}]}. '
                    .'score>=40 tylko przy zgodnej nazwie. Max 5. Tylko id z listy. Nie wymyślaj.',
            ],
            [
                'role' => 'user',
                'content' => "Wymaganie:\n{$query}{$neededLine}\n\nKarty katalogu:\n{$json}",
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
            if (! $this->assortment->compatibleProduct($query, $product)) {
                continue;
            }
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
