<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Services\Ai\OpenAiCompatibleClient;
use Illuminate\Support\Collection;
use RuntimeException;

final class ProductAiSearchService
{
    public function __construct(
        private readonly OpenAiCompatibleClient $llm,
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

        // Bez osobnego calla LLM na facety — szybciej i taniej.
        $facets = $this->extractFacetsHeuristic($query);
        $candidates = $this->prefilter($query, $facets, 35);

        if ($candidates->isEmpty()) {
            return [
                'query' => $query,
                'total' => 0,
                'products' => [],
                'facets' => $facets,
            ];
        }

        $ranked = $this->rankWithLlm($query, $candidates->take(20)->values(), $limit);

        // awaryjnie: gdy model nic nie zwróci — top po heurystyce prefiltra
        if ($ranked === []) {
            $ranked = $candidates->take($limit)->map(function (Product $p): array {
                $row = $this->productToRow($p);
                $row['ai_match_percent'] = 50;
                $row['ai_match_reason'] = 'Propozycja z wyszukiwania katalogu (model nie zwrócił rankingu)';

                return $row;
            })->values()->all();
        }

        return [
            'query' => $query,
            'total' => count($ranked),
            'products' => $ranked,
            'facets' => $facets,
        ];
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
            'rekawice' => 'rękawice',
            'rękawice' => 'rękawice',
            'buty' => 'buty',
            'obuwie' => 'obuwie',
            'okulary' => 'okulary',
            'kask' => 'kask',
            'helm' => 'hełm',
            'hełm' => 'hełm',
            'odziez' => 'odzież',
            'odzież' => 'odzież',
            'kombinezon' => 'kombinezon',
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
            if ($type === 'rękawice' && (str_contains($hay, 'rekaw') || str_contains($hay, 'glove'))) {
                $hits += 2;
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
     * @return list<array<string, mixed>>
     */
    private function rankWithLlm(string $query, Collection $candidates, int $limit): array
    {
        $cards = $candidates->map(function (Product $p): array {
            $payload = is_array($p->enrichment_payload) ? $p->enrichment_payload : [];

            return [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => mb_substr((string) $p->name, 0, 120),
                'manufacturer' => $p->manufacturer,
                'norms' => $p->norms,
                'description' => mb_substr((string) ($p->description ?? ''), 0, 280),
                'use_cases' => array_slice($this->stringList($payload['use_cases'] ?? null), 0, 4),
                'features' => array_slice($this->stringList($payload['features'] ?? null), 0, 4),
            ];
        })->values()->all();

        $json = json_encode($cards, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $raw = $this->llm->chatJson([
            [
                'role' => 'system',
                'content' => 'Ekspert BHP. Wybierz produkty pasujące do wymagania. '
                    .'JSON: {"matches":[{"id":1,"score":0-100,"reason":"krótko"}]}. '
                    .'Uwzględnij typ (rękawice≠obuwie), materiał i zastosowanie. '
                    .'score>=40 gdy sensownie pasuje. Max 5 pozycji. Tylko id z listy.',
            ],
            [
                'role' => 'user',
                'content' => "Wymaganie:\n{$query}\n\nProdukty:\n{$json}",
            ],
        ], 0.0, 2500);

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
