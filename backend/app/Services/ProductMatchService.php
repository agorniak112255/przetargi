<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\Tender;
use Illuminate\Support\Collection;

final class ProductMatchService
{
    public function __construct(
        private readonly TenderPricingService $pricing,
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

        $items = $tender->items()->when(
            $onlyEmpty,
            fn ($q) => $q->whereNull('main_product_id')
        )->get();

        foreach ($items as $item) {
            $best = $this->bestMatch($item->requirement, $products);
            if ($best === null || $best['score'] < 35) {
                $item->status = 'brak';
                $item->ai_match_percent = $best['score'] ?? 0;
                $item->save();
                $skipped++;

                continue;
            }

            $item->main_product_id = $best['product']->id;
            $item->ai_match_percent = $best['score'];
            $item->status = 'matched';
            if ($item->offer_price === null) {
                $item->offer_price = round((float) $best['product']->purchase_price * 1.18, 2);
            }
            $item->save();
            $item->load('mainProduct');
            $this->pricing->recalculateItemMargin($item);
            $matched++;
            $scores[] = $best['score'];
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
        $reqTokens = $this->tokens($req);
        $best = null;

        foreach ($products as $product) {
            $payload = is_array($product->enrichment_payload) ? $product->enrichment_payload : [];
            $extra = implode(' ', [
                (string) ($product->description ?? ''),
                implode(' ', is_array($payload['features'] ?? null) ? $payload['features'] : []),
                implode(' ', is_array($payload['use_cases'] ?? null) ? $payload['use_cases'] : []),
                implode(' ', is_array($payload['materials'] ?? null) ? $payload['materials'] : []),
                implode(' ', is_array($payload['norms'] ?? null) ? $payload['norms'] : []),
            ]);
            $hay = $this->normalize(
                $product->name.' '.$product->sku.' '.$product->manufacturer.' '
                .($product->norms ?? '').' '.($product->category ?? '').' '.$extra
            );
            $score = $this->score($req, $reqTokens, $hay, $product);
            if ($best === null || $score > $best['score']) {
                $best = ['product' => $product, 'score' => $score];
            }
        }

        return $best;
    }

    /**
     * @param  list<string>  $reqTokens
     */
    private function score(string $req, array $reqTokens, string $hay, Product $product): int
    {
        $score = 0;

        $skuNorm = $this->normalize($product->sku);
        $skuCompact = preg_replace('/\s+/', '', $skuNorm) ?? $skuNorm;
        $reqCompact = preg_replace('/\s+/', '', $req) ?? $req;

        // dokładny / częściowy kod produktu w SIWZ
        if ($skuCompact !== '' && (str_contains($reqCompact, $skuCompact) || str_contains($req, $skuNorm))) {
            $score += 70;
        } elseif ($skuCompact !== '' && mb_strlen($skuCompact) >= 4) {
            foreach ($this->codeCandidates($req) as $code) {
                if ($code === $skuCompact || str_contains($skuCompact, $code) || str_contains($code, $skuCompact)) {
                    $score += 55;
                    break;
                }
            }
        }

        $nameNorm = $this->normalize($product->name);
        if ($nameNorm !== '' && (str_contains($req, $nameNorm) || str_contains($nameNorm, $req))) {
            $score += 35;
        }

        if (preg_match_all('/en\s*[\d]+/i', $req, $m)) {
            foreach ($m[0] as $norm) {
                $n = preg_replace('/\s+/', '', mb_strtolower($norm));
                $pn = preg_replace('/\s+/', '', mb_strtolower((string) $product->norms));
                if ($pn !== '' && str_contains($pn, $n)) {
                    $score += 25;
                }
            }
        }

        $keywords = [
            'antyprzecieciow' => ['antyprzecieciowe', 'cut', 'powercut', 'powerfit', 'unidur', 'krytech'],
            'chemoodporn' => ['chemoodporne', 'alphatec', 'chemic', 'barierow'],
            'ocieplan' => ['ocieplane', 'winter', 'thermo', 'zimn'],
            'skorzan' => ['skorzane', 'koz', 'eco tec', 'comfotec'],
            'esd' => ['esd', 'carbon', 'elektrostat'],
            'kriogen' => ['kriogeniczne', 'crio', 'cryo'],
            'pu' => ['poliuretan', ' pu ', 'powlek'],
            'montaz' => ['montersk', 'montaz'],
        ];

        foreach ($keywords as $inReq => $inProduct) {
            if (str_contains($req, $inReq)) {
                foreach ($inProduct as $hint) {
                    if (str_contains($hay, $this->normalize($hint))) {
                        $score += 12;
                    }
                }
            }
        }

        $hayTokens = $this->tokens($hay);
        $overlap = count(array_intersect($reqTokens, $hayTokens));
        $score += min(40, $overlap * 8);

        similar_text($req, $hay, $pct);
        $score += (int) round($pct * 0.25);

        return min(99, $score);
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
    private function codeCandidates(string $req): array
    {
        $out = [];
        if (preg_match_all('/\b[a-z0-9][a-z0-9\-\/]{2,}\b/i', $req, $m)) {
            foreach ($m[0] as $raw) {
                $c = preg_replace('/\s+/', '', $this->normalize($raw)) ?? '';
                if (mb_strlen($c) >= 3 && preg_match('/\d/', $c)) {
                    $out[] = $c;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Dopasuj jedną pozycję (np. przycisk AI w wierszu).
     *
     * @return array{matched: bool, score: int, product_id: ?int, product?: array<string, mixed>}
     */
    public function matchItem(\App\Models\TenderItem $item, bool $force = false): array
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
            ];
        }

        $products = Product::query()->get();
        $best = $this->bestMatch($item->requirement, $products);
        if ($best === null || $best['score'] < 30) {
            $item->status = 'brak';
            $item->ai_match_percent = $best['score'] ?? 0;
            $item->save();

            return [
                'matched' => false,
                'score' => $best['score'] ?? 0,
                'product_id' => null,
            ];
        }

        $item->main_product_id = $best['product']->id;
        $item->ai_match_percent = $best['score'];
        $item->status = 'matched';
        if ($item->offer_price === null) {
            $item->offer_price = round((float) $best['product']->purchase_price * 1.18, 2);
        }
        $item->save();
        $item->load(['mainProduct', 'tender']);
        $this->pricing->recalculateItemMargin($item);
        if ($item->tender !== null) {
            $this->pricing->recalculateTenderTotals($item->tender);
        }

        $p = $best['product'];

        return [
            'matched' => true,
            'score' => $best['score'],
            'product_id' => $p->id,
            'product' => [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
            ],
            'offer_price' => $item->offer_price,
        ];
    }
}
