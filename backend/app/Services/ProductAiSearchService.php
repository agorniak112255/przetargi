<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Services\Ai\AiTask;
use App\Services\Ai\OpenAiCompatibleClient;
use App\Services\Search\ProductTextSearch;
use App\Services\Vector\ProductVectorSearch;
use App\Support\BhpAttributeNormalizer;
use App\Support\PpeAssortment;
use App\Support\PpeFilterType;
use App\Support\ProductModelFuzzy;
use App\Support\RrfFusion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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

    /** Trafienie w kod modelu jest pewniejsze niż podobieństwo tekstu czy wektora. */
    private const RRF_WEIGHT_PRIORITY = 3.0;

    private const RRF_WEIGHT_TEXT = 1.0;

    private const RRF_WEIGHT_VECTOR = 1.0;

    /** Karta bez opisu mówi o sobie mniej, więc jej trafienie waży mniej. */
    private const RRF_WEIGHT_UNCLASSIFIED = 0.6;

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
        int $limit = 40,
        bool $withExternalHint = true,
        AiTask $task = AiTask::ProductSearch,
        bool $webOnly = false,
    ): array {
        $query = trim($query);
        if ($query === '') {
            throw new RuntimeException('Podaj treść wymagania dla AI.');
        }
        $limit = max(1, min(80, $limit));

        if ($webOnly) {
            return $this->webOnlyResult($query, $limit);
        }

        $intent = $this->understandRequirement($query, $task);

        return $this->finishSearch($query, $intent, $limit, $withExternalHint, $task);
    }

    /**
     * Wiele zapytań: intent i ranking idą równolegle (max $maxConcurrent połączeń).
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
        int $limit = 40,
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
        $limit = max(1, min(80, $limit));
        $maxConcurrent = max(1, min(10, $maxConcurrent));

        $intentRaws = $this->llm->chatJsonMany(
            array_map(fn (string $q): array => $this->understandMessages($q), $clean),
            900,
            $task,
            $maxConcurrent,
        );
        $intents = [];
        foreach ($clean as $i => $query) {
            $raw = $intentRaws[$i] ?? [];
            $intents[$i] = $this->parseIntent(is_array($raw) ? $raw : [], $query);
        }

        $pending = [];
        $done = [];
        foreach ($clean as $i => $query) {
            $prepared = $this->prepareSearch($query, $intents[$i], $limit);
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
            $rankMessages[] = $this->rankMessages(
                $clean[$i],
                $prepared['rank_cards'],
                $limit,
                $intents[$i]['needed'],
                $intents[$i]['constraints'],
            );
        }
        $rankRaws = $this->llm->chatJsonMany($rankMessages, 2500, $task, $maxConcurrent);
        foreach ($rankOrder as $pos => $i) {
            $ranked = $this->rowsFromLlmMatches(
                $clean[$i],
                $pending[$i]['candidates'],
                is_array($rankRaws[$pos] ?? null) ? $rankRaws[$pos] : [],
                $limit,
                $intents[$i]['needed'],
            );
            $done[$i] = $this->searchResult(
                $clean[$i],
                $intents[$i],
                $ranked,
                $ranked === [] ? 'Model nie znalazł pasującego produktu w katalogu. Nie dodano pozycji z internetu.' : null,
                $withExternalHint,
            );
        }
        ksort($done);

        return array_values($done);
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
        $candidates = $this->keepCompatible(
            $this->assortmentText($query, $intent['needed']),
            $this->retrieveCandidates($query, $intent, self::CANDIDATE_POOL)
        );
        $named = $candidates->filter(
            fn (Product $p): bool => $this->modelFuzzy->matches($query, $p)
                && $this->filterType->covers($query, $this->filterHaystack($p))
        )->values();
        if ($named->isNotEmpty()) {
            return [
                'products' => $this->rowsFromNamedModels($query, $named, $limit),
                'note' => null,
                'rank_cards' => null,
                'candidates' => $candidates,
            ];
        }
        if ($candidates->isEmpty()) {
            return [
                'products' => [],
                'note' => 'Brak kart z opisem w katalogu do porównania. Nie dodano produktu z internetu.',
                'rank_cards' => null,
                'candidates' => $candidates,
            ];
        }

        return [
            'products' => [],
            'note' => null,
            'rank_cards' => $this->cardsForRanking($candidates, $intent['constraints']),
            'candidates' => $candidates,
        ];
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
        if ($products === [] && $note !== null) {
            return $this->emptyResult($query, $intent, $withExternalHint, $note);
        }

        return [
            'query' => $query,
            'total' => count($products),
            'products' => $products,
            'needed' => $intent['needed'],
            'search_phrases' => $intent['search_phrases'],
            'ai_note' => null,
            'external_hint' => null,
        ];
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
    ): array {
        $prepared = $this->prepareSearch($query, $intent, $limit);
        if ($prepared['rank_cards'] === null) {
            return $this->searchResult($query, $intent, $prepared['products'], $prepared['note'], $withExternalHint);
        }
        $ranked = $this->rankWithLlm(
            $query,
            $prepared['rank_cards'],
            $limit,
            $intent['needed'],
            $task,
            $intent['constraints'],
        );

        return $this->searchResult(
            $query,
            $intent,
            $ranked,
            $ranked === [] ? 'Model nie znalazł pasującego produktu w katalogu. Nie dodano pozycji z internetu.' : null,
            $withExternalHint,
        );
    }

    /**
     * @return list<array{role: string, content: string}>
     */
    private function understandMessages(string $query): array
    {
        return [
            [
                'role' => 'system',
                'content' => 'Jesteś ekspertem BHP. Z SIWZ wyodrębnij NAZWĘ produktu (rzeczownik), zanim cechy i normy. '
                    .'needed: krótka nazwa na początku (np. kamizelka odblaskowa żółta) — bez EN i bez samego przymiotnika. '
                    .'search_phrases: 2-8 fraz; PIERWSZE 2 to wyłącznie nazwa/synonim (kamizelka, kamizelka odblaskowa). '
                    .'Cechy (siatkowa, nadruk) i normy EN dopiero na końcu. '
                    .'constraints: 0-6 warunków z wymagania (albo równoważna norma/klasa, którą znasz jako ekspert BHP). '
                    .'Dotyczy dowolnej cechy: substancja, stężenie, klasa, typ, norma, napięcie, temperatura, ESD, gramatura. '
                    .'Nie wstawiaj samej nazwy produktu. Pusta tablica, gdy wymaganie to tylko nazwa asortymentu. '
                    .'Przymiotnik wspólny nie zastępuje nazwy: kamizelka ≠ osłona twarzy; rękawy ≠ rękawice. '
                    .'Synonimy: obuwie/buty/trzewiki, kurtka/bluza ochronna. '
                    .'Popraw literówki — w modelu (TEPM-ICE → TEMP-ICE) i w nazwie produktu '
                    .'(podnie → spodnie, rekawice → rękawice, kamizelaka → kamizelka). '
                    .'needed i pierwsze frazy zawsze w poprawnej pisowni. Nie klasyfikuj sztywną listą typów. '
                    .'JSON: {"needed":"nazwa","search_phrases":["najpierw nazwa","potem cechy/normy"],"constraints":[]}.',
            ],
            [
                'role' => 'user',
                'content' => $query,
            ],
        ];
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

            return $this->parseIntent($raw, $query);
        } catch (Throwable) {
            return [
                'needed' => $query,
                'search_phrases' => $this->fallbackPhrases($query),
                'constraints' => $this->fallbackConstraints($query),
            ];
        }
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
        if ($constraints === []) {
            $constraints = $this->fallbackConstraints($query);
        }

        return [
            'needed' => $needed,
            'search_phrases' => array_values(array_unique($phrases)),
            'constraints' => array_values(array_unique($constraints)),
        ];
    }

    /**
     * Czy wymaganie ma warunek poza samą nazwą asortymentu — wtedy ocenia model, nie skrót „ten sam typ”.
     */
    public function isSpecificRequirement(string $query): bool
    {
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
     * @return list<string>
     */
    private function fallbackConstraints(string $query): array
    {
        $out = [];
        foreach ($this->fallbackPhrases($query) as $token) {
            $norm = $this->lexicalNormalize($token);
            if ($norm === '' || $this->isNonTechnicalToken($norm)) {
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

        return preg_match(
            '/^(czarn|bial|zol|niebies|czerw|zielon|szar|granat|pomaranc|brazow|bezow'
            .'|srebrn|zlot|grafit|khaki|navy|black|white|yellow|blue|red|green|grey|gray|orange'
            .'|polar(?!yz)|poliestr|baweln|nylon|elastan|lycra|ociepl|kolor|rozmiar)/u',
            $t
        ) === 1;
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

    private function isGenericAssortmentToken(string $normalized): bool
    {
        return preg_match(
            '/^(rekawic|glove|spodn|kurtk|bluz|czapk|czepek|kominiark|balaclava|helm|kask'
            .'|fartuch|kitel|kamizelk|kombinezon|ogrodniczk|buty|obuwie|trzewik|polbut'
            .'|kalosz|gumiak|gumowc|wellington'
            .'|sztyblet|okular|gogl|nausznik|polmask|respirator|pochlaniacz|filtr'
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
        $searchText = $intent['needed'] !== '' ? $intent['needed'] : $query;
        $requirement = $this->assortmentText($query, $intent['needed']);
        $codeHits = $this->retrieveByModelCode($query.' '.$searchText, $limit);
        $fuzzyHits = $this->retrieveByFuzzyModel($query.' '.$searchText, $limit);
        $filterHits = $this->retrieveByFilterType($query, $limit);
        $priority = $this->uniqueProducts($filterHits->concat($fuzzyHits)->concat($codeHits), $limit);

        if ($this->modelFuzzy->hasNamedModel($query) && $priority->isNotEmpty()) {
            $namedPriority = $this->keepCompatible($requirement, $priority);
            if ($namedPriority->isNotEmpty()) {
                return $namedPriority;
            }
        }

        // Gdy rodzina jest rozpoznana, indeks zwraca cały zgodny asortyment — także karty
        // bez trafienia we frazę, tylko niżej. Wcześniej wymagał tego skan całego katalogu
        // po stronie PHP; teraz warunek idzie do WHERE.
        $family = $this->assortment->family($requirement);
        $rankings = [
            'priority' => $priority->pluck('id')->map(intval(...))->all(),
            'text' => $this->textSearch->search($intent['search_phrases'], $family, self::TEXT_POOL),
            // Zawężenie do rodziny wycina karty, którym rodziny nie dało się ustalić —
            // w tym katalogu to dwie trzecie pozycji. Wracają osobnym źródłem, ale
            // wyłącznie z trafieniem w tekst, więc nie zalewają zgodnego asortymentu.
            'unclassified' => $family === null
                ? []
                : $this->textSearch->searchUnclassified($intent['search_phrases'], self::UNCLASSIFIED_POOL),
            'vector' => $this->retrieveVectorIds($searchText, self::VECTOR_POOL),
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

        $recall = $this->retrieveByFootwearClass($query, $limit)
            ->concat($this->retrieveByHeatRating($query, $limit))
            ->concat($this->retrieveByHeadLiner($query, $limit))
            ->concat($this->retrieveByArticleType($query, $limit));

        return $this->keepCompatible(
            $requirement,
            $this->uniqueProducts($this->hydrate($fused)->concat($recall), $limit)
        )->values();
    }

    /**
     * Literówka w rzeczowniku ("podnie" zamiast "spodnie") zeruje rozpoznanie rodziny,
     * a wraz z nim zawężenie do zgodnego asortymentu i bramkę kompatybilności.
     * Gdy surowe wymaganie nie wskazuje rodziny, dokładamy nazwę odczytaną przez model —
     * ta jest już po korekcie pisowni.
     */
    private function assortmentText(string $query, ?string $needed): string
    {
        $needed = trim((string) $needed);
        if ($needed === '' || $needed === $query || $this->assortment->family($query) !== null) {
            return $query;
        }

        return $needed.' '.$query;
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
            ->filter(fn (Product $p): bool => $this->assortment->compatibleProduct($query, $p))
            ->values();
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
     * Klasa obuwia z nazwy (S2, O2) — działa też bez opisu, bo FULLTEXT gubi tokeny 2-literowe.
     *
     * @return Collection<int, Product>
     */
    private function retrieveByFootwearClass(string $query, int $limit): Collection
    {
        $class = $this->bhpAttributes->footwearClass($query);
        if ($class === null || $this->assortment->family($query) !== PpeAssortment::FAMILY_FOOTWEAR) {
            return collect();
        }

        $token = $this->bhpAttributes->footwearClassToken($class);
        $likeClass = '%'.addcslashes($class, '%_\\').'%';
        $likeToken = '%'.addcslashes($token, '%_\\').'%';

        $rows = $this->productBaseQuery()
            ->where(function (Builder $outer) use ($likeClass, $likeToken): void {
                $outer->where('name', 'like', $likeClass)
                    ->orWhere('sku', 'like', $likeClass)
                    ->orWhere('search_blob', 'like', $likeToken)
                    ->orWhere('search_blob', 'like', $likeClass);
            })
            ->where(function (Builder $outer): void {
                $outer->where('ppe_family', PpeAssortment::FAMILY_FOOTWEAR)
                    ->orWhereNull('ppe_family');
            })
            ->limit(400)
            ->get()
            ->filter(function (Product $product) use ($query, $class): bool {
                $identity = trim($product->name.' '.$product->sku.' '.(string) ($product->category ?? ''));
                if ($this->bhpAttributes->footwearClass($identity) !== $class) {
                    return false;
                }
                $reqType = $this->assortment->articleType($query, PpeAssortment::FAMILY_FOOTWEAR);
                $prodType = $this->assortment->articleType($product->name, PpeAssortment::FAMILY_FOOTWEAR);
                if ($reqType !== null && $prodType !== null && $reqType !== $prodType) {
                    return false;
                }

                return $this->assortment->compatibleProduct($query, $product);
            })
            ->values();

        return $this->preferQueryManufacturers($query, $rows)->take(max(8, $limit))->values();
    }

    /**
     * Rękawice / asortyment z podaną odpornością °C — bez LLM, bo model zwraca jedną kartę.
     *
     * @return Collection<int, Product>
     */
    private function retrieveByHeatRating(string $query, int $limit): Collection
    {
        $minC = $this->bhpAttributes->requiredCelsius($query);
        if ($minC === null) {
            return collect();
        }

        $family = $this->assortment->family($query);
        $rows = $this->productBaseQuery()
            ->where(function (Builder $outer): void {
                foreach (['%°C%', '%° C%', '%stopni%', '%st. C%', '%st.C%', '% C', '% C.', '% C,'] as $like) {
                    $outer->orWhere('name', 'like', $like)
                        ->orWhere('description', 'like', $like)
                        ->orWhere('search_blob', 'like', $like)
                        ->orWhere('norms', 'like', $like);
                }
            })
            ->when(
                $family !== null,
                function (Builder $q) use ($family): void {
                    $q->where(function (Builder $outer) use ($family): void {
                        $outer->where('ppe_family', $family)->orWhereNull('ppe_family');
                    });
                }
            )
            ->limit(400)
            ->get()
            ->filter(function (Product $product) use ($query, $minC): bool {
                $maxC = $this->productHeatCelsius($product);
                if ($maxC === null || $maxC < $minC) {
                    return false;
                }

                return $this->assortment->compatibleProduct($query, $product);
            })
            ->sortByDesc(fn (Product $p): int => $this->productHeatCelsius($p) ?? 0)
            ->values();

        return $rows->take(max(8, $limit))->values();
    }

    /**
     * Czepek / wkładka pod hełm — bez LLM, bo EN 1149/ESD ciągnie kurtki.
     *
     * @return Collection<int, Product>
     */
    private function retrieveByHeadLiner(string $query, int $limit): Collection
    {
        if (! $this->assortment->isUnderHelmetLiner($query)) {
            return collect();
        }

        $rows = $this->productBaseQuery()
            ->where(function (Builder $outer): void {
                $outer->where('name', 'like', '%czepek%')
                    ->orWhere('name', 'like', '%czepk%')
                    ->orWhere('name', 'like', '%kominiark%')
                    ->orWhere('name', 'like', '%balaclava%')
                    ->orWhere('category', 'like', '%czepek%')
                    ->orWhere('category', 'like', '%kominiark%')
                    ->orWhere(function (Builder $inner): void {
                        $inner->where(function (Builder $w): void {
                            $w->where('name', 'like', '%wkładk%')
                                ->orWhere('name', 'like', '%wkladk%')
                                ->orWhere('search_blob', 'like', '%wkladk%');
                        })->where(function (Builder $h): void {
                            $h->where('name', 'like', '%hełm%')
                                ->orWhere('name', 'like', '%helm%')
                                ->orWhere('name', 'like', '%kask%')
                                ->orWhere('description', 'like', '%hełm%')
                                ->orWhere('description', 'like', '%helm%')
                                ->orWhere('search_blob', 'like', '%helm%');
                        });
                    })
                    ->orWhere(function (Builder $cap): void {
                        $cap->where(function (Builder $n): void {
                            $n->where('name', 'like', '%czapk%')
                                ->orWhere('category', 'like', '%czapk%');
                        })->where(function (Builder $ctx): void {
                            $ctx->where('name', 'like', '%ociepl%')
                                ->orWhere('name', 'like', '%hełm%')
                                ->orWhere('name', 'like', '%helm%')
                                ->orWhere('name', 'like', '%kask%')
                                ->orWhere('name', 'like', '%esd%')
                                ->orWhere('description', 'like', '%hełm%');
                        });
                    });
            })
            ->limit(200)
            ->get()
            ->filter(fn (Product $p): bool => $this->assortment->compatibleProduct($query, $p))
            ->values();

        return $rows->take(max(8, $limit))->values();
    }

    /**
     * Kominiarka / kalosz / gogle — wszystkie karty tego kroju, cechy (ESD, 1149) na górze.
     *
     * @return Collection<int, Product>
     */
    private function retrieveByArticleType(string $query, int $limit): Collection
    {
        if ($this->modelFuzzy->hasNamedModel($query)) {
            return collect();
        }
        $likes = $this->assortment->catalogNounLikes($query);
        if ($likes === []) {
            return collect();
        }

        $rows = $this->productBaseQuery()
            ->where(function (Builder $outer) use ($likes): void {
                foreach ($likes as $like) {
                    $esc = '%'.$like.'%';
                    $outer->orWhere('name', 'like', $esc)
                        ->orWhere('sku', 'like', $esc)
                        ->orWhere('category', 'like', $esc)
                        ->orWhere('description', 'like', $esc)
                        ->orWhere('search_blob', 'like', $esc);
                }
            })
            ->limit(400)
            ->get()
            ->filter(fn (Product $p): bool => $this->assortment->compatibleProduct($query, $p))
            ->sortByDesc(fn (Product $p): int => $this->articleTypeScore($query, $p))
            ->values();

        return $rows->take(max(8, $limit))->values();
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
        foreach ($this->fallbackPhrases($query) as $token) {
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

        $q = $this->productBaseQuery();

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
        $q = $this->productBaseQuery();

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
     * Do modelu idą najpierw karty z śladem warunku — zrzut „wszystkie kombinezony”
     * nie może zająć 24 miejsc i wypchnąć Tychema.
     *
     * @param  Collection<int, Product>  $candidates
     * @param  list<string>  $constraints
     * @return Collection<int, Product>
     */
    private function cardsForRanking(Collection $candidates, array $constraints): Collection
    {
        $candidates = $candidates->values();
        if ($candidates->count() <= self::RANK_CARDS || $constraints === []) {
            return $candidates->take(self::RANK_CARDS)->values();
        }

        $needles = $this->constraintNeedles($constraints);
        if ($needles === []) {
            return $candidates->take(self::RANK_CARDS)->values();
        }

        $with = collect();
        $without = collect();
        foreach ($candidates as $product) {
            if (! $product instanceof Product) {
                continue;
            }
            if ($this->haystackHasNeedle($this->rankingHaystack($product), $needles)) {
                $with->push($product);
            } else {
                $without->push($product);
            }
        }

        return $with->concat($without)->take(self::RANK_CARDS)->values();
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
                if ($part === '' || mb_strlen($part) < 4 || $this->isNonTechnicalToken($part)) {
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
     * Sam ranking id — o zgodności rodziny i o miejscu w puli decyduje dopiero fuzja,
     * więc odsiewanie kart bez opisu na tym etapie tylko gubiłoby trafienia.
     *
     * @return list<int>
     */
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
     * @return Builder<Product>
     */
    private function productBaseQuery()
    {
        return Product::query()
            ->with(['images' => static fn ($img) => $img->orderBy('sort_order')->orderBy('id')])
            ->withCount(['substitutes', 'images']);
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
            'query' => $query,
            'total' => 0,
            'products' => [],
            'needed' => $intent['needed'],
            'search_phrases' => $intent['search_phrases'],
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
        $raw = $this->llm->chatJson(
            $this->rankMessages($query, $candidates, $limit, $needed, $constraints),
            null,
            2500,
            null,
            $task,
        );

        return $this->rowsFromLlmMatches($query, $candidates, $raw, $limit, $needed);
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
    ): array {
        $cards = $candidates->map(function (Product $p): array {
            $payload = is_array($p->enrichment_payload) ? $p->enrichment_payload : [];

            return [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => mb_substr((string) $p->name, 0, 120),
                'category' => $p->category,
                'manufacturer' => $p->manufacturer,
                'norms' => $p->norms,
                'description' => mb_substr((string) ($p->description ?? ''), 0, 360),
                'heat_celsius' => $this->productHeatCelsius($p),
                'specs' => array_slice($this->stringList($payload['specs'] ?? null), 0, 8),
                'use_cases' => array_slice($this->stringList($payload['use_cases'] ?? null), 0, 4),
                'features' => array_slice($this->stringList($payload['features'] ?? null), 0, 4),
                'payload_norms' => array_slice($this->stringList($payload['norms'] ?? null), 0, 6),
            ];
        })->values()->all();

        $neededLine = is_string($needed) && trim($needed) !== ''
            ? "\nSzukany produkt (z analizy):\n".trim($needed)
            : '';
        $constraintLine = $constraints === []
            ? ''
            : "\nWarunki, które karta MUSI potwierdzać w norms/specs/description (nie zgaduj):\n- "
                .implode("\n- ", $constraints);
        $maxMatches = max(1, min($limit, self::MAX_MATCHES));
        $json = json_encode($cards, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            [
                'role' => 'system',
                'content' => 'Jesteś ekspertem BHP. Ranking w dwóch krokach — nie mieszaj ich. '
                    .'1) NAZWA: rzeczownik z wymagania = ten sam produkt co w polu name karty '
                    .'(synonimy: buty=obuwie=trzewiki; kurtka≈bluza ochronna). '
                    .'Inny rodzaj → nie zwracaj: kamizelka ≠ osłona twarzy; rękawice ≠ obuwie. '
                    .'Sam ten sam rodzaj (kombinezon, kalosz) NIE wystarczy, gdy wymaganie ma warunek. '
                    .'2) WARUNEK: substancja, stężenie, klasa, typ, norma, napięcie — tylko gdy widać to '
                    .'w polach norms, specs, payload_norms, features, use_cases albo description. '
                    .'Brak potwierdzenia → nie zwracaj (kombinezon pszczelarski / EN 343 ≠ kwas siarkowy). '
                    .'Równoznaczna norma/klasa (np. EN 13034, Typ 3/4, Tychem przy kwasie) = spełnione. '
                    .'Nie zgaduj z nazwy handlowej. '
                    .'Wspólna cecha (siatkowa) albo przypadkowa norma EN NIE wystarczy. '
                    .'Marka/model z SIWZ wygrywa przy literówce (TEPM-ICE=TEMP-ICE); nie zmieniaj marki przez EN. '
                    .'Pochłaniacz/filtr EN 14387: A2B2E2K2 ≠ A2B2E2K2NO — bez NO/Hg/CO z wymagania nie zwracaj karty. '
                    .'Literówka w wymaganiu nie dyskwalifikuje karty — nazwę czytaj z linii "Szukany produkt (z analizy)" '
                    .'(podnie = spodnie, rekawice = rękawice). '
                    .'Brak zgodnej nazwy albo braku dowodu na warunek: {"matches":[]}. '
                    .'JSON: {"matches":[{"id":1,"score":0-100,"reason":"uzasadnienie"}]}. '
                    .'score>=40 tylko przy zgodnej nazwie I spełnionych warunkach. Max '.$maxMatches.'. '
                    .'Zwróć każdą kartę, która spełnia wymaganie — nie skracaj listy na siłę. '
                    .'Tylko id z listy. Nie wymyślaj.',
            ],
            [
                'role' => 'user',
                'content' => "Wymaganie:\n{$query}{$neededLine}{$constraintLine}\n\nKarty katalogu:\n{$json}",
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
    ): array {
        $requirement = $this->assortmentText($query, $needed);
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
            if (! $this->assortment->compatibleProduct($requirement, $product)) {
                continue;
            }
            if (! $this->filterType->covers($requirement, $this->filterHaystack($product))) {
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
