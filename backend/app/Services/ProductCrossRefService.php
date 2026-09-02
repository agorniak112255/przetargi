<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Support\BhpAttributeNormalizer;
use App\Support\ProductCrossRefFilters;

/**
 * Cross-reference: kod/SKU → ten sam wyrób u innego producenta.
 * Twarde: typ (kalosz≠trzewik), materiał, klasa (S5≠S3), przeznaczenie, oznaczenia.
 */
final class ProductCrossRefService
{
    private const LIMIT = 12;

    /** Minimalny wynik atrybutowy po twardych filtrach. */
    private const MIN_SCORE = 36;

    /** Oznaczenia z bazy, bez których kandydat nie jest ekwiwalentem. */
    private const REQUIRED_MARKINGS = ['CI', 'HI', 'HRO', 'WR'];

    /** @var array<string, array{group: string, rank: int}> */
    private const CLASS_RANKS = [
        'OB' => ['group' => 'footwear', 'rank' => 1],
        'SB' => ['group' => 'footwear', 'rank' => 2],
        'S1' => ['group' => 'footwear', 'rank' => 3],
        'S1P' => ['group' => 'footwear', 'rank' => 4],
        'S2' => ['group' => 'footwear', 'rank' => 5],
        'S3' => ['group' => 'footwear', 'rank' => 6],
        'S4' => ['group' => 'footwear', 'rank' => 7],
        'S5' => ['group' => 'footwear', 'rank' => 8],
        'S7' => ['group' => 'footwear', 'rank' => 9],
        'FFP1' => ['group' => 'ffp', 'rank' => 1],
        'FFP2' => ['group' => 'ffp', 'rank' => 2],
        'FFP3' => ['group' => 'ffp', 'rank' => 3],
        'KAT1' => ['group' => 'ppe_kat', 'rank' => 1],
        'KAT2' => ['group' => 'ppe_kat', 'rank' => 2],
        'KAT3' => ['group' => 'ppe_kat', 'rank' => 3],
    ];

    public function __construct(
        private readonly BhpAttributeNormalizer $bhpAttributes,
        private readonly ProductCrossRefFilters $filters,
    ) {}

    /**
     * @return array{
     *     code: string,
     *     seed: ?array<string, mixed>,
     *     groups: list<array<string, mixed>>
     * }
     */
    public function optionsForCode(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            return ['code' => $code, 'seed' => null, 'groups' => []];
        }

        $seed = $this->resolveSeed($code);
        if ($seed === null) {
            return ['code' => $code, 'seed' => null, 'groups' => []];
        }
        $seed->loadMissing('images');

        $seedAttrs = $this->bhpAttributes->forProduct($seed);

        return [
            'code' => $code,
            'seed' => $this->productCard($seed, 100, false, $seedAttrs),
            'groups' => $this->filters->groupsFor($seed, $seedAttrs),
        ];
    }

    /**
     * @param  list<string>  $must
     * @return array{
     *     code: string,
     *     seed: ?array<string, mixed>,
     *     matches: list<array<string, mixed>>,
     *     total: int,
     *     applied_filters: list<array<string, mixed>>
     * }
     */
    public function findByCode(string $code, int $limit = self::LIMIT, array $must = []): array
    {
        $code = trim($code);
        $limit = max(1, min(40, $limit));
        $must = $this->filters->sanitizeMust($must);

        if ($code === '') {
            return ['code' => $code, 'seed' => null, 'matches' => [], 'total' => 0, 'applied_filters' => []];
        }

        $seed = $this->resolveSeed($code);
        if ($seed === null) {
            return ['code' => $code, 'seed' => null, 'matches' => [], 'total' => 0, 'applied_filters' => []];
        }
        $seed->loadMissing('images');

        $seedAttrs = $this->bhpAttributes->forProduct($seed);
        $groups = $this->filters->groupsFor($seed, $seedAttrs);
        $applied = $this->filters->resolveApplied($must, $groups);
        $seedMfr = mb_strtolower(trim((string) $seed->manufacturer));

        // Cały katalog — bez limitu i bez wycinania po rodzinie (ppe_family bywa puste).
        $pool = Product::query()->where('id', '!=', $seed->id)->with('images')->get();

        $matches = [];
        foreach ($pool as $product) {
            $attrs = $this->bhpAttributes->forProduct($product);

            if (! $this->isEquivalentArticle($seedAttrs, $attrs)) {
                continue;
            }

            if ($must !== [] && ! $this->filters->matchesAll($must, $product, $attrs)) {
                continue;
            }

            $score = $this->attributeScore($seedAttrs, $attrs, $seed, $product);
            if ($must === [] && $score < self::MIN_SCORE) {
                continue;
            }
            if ($must !== [] && $score < 20) {
                $score = 20 + min(24, 4 * count($must));
            }

            $mfr = mb_strtolower(trim((string) $product->manufacturer));
            $crossBrand = $seedMfr !== '' && $mfr !== '' && $mfr !== $seedMfr;

            // ten sam producent + prawie ten sam SKU numeryczny bez wspólnych atrybutów technicznych
            if ($must === [] && ! $crossBrand && $this->looksLikeSiblingSku($seed, $product) && $score < 45) {
                continue;
            }

            $matches[] = $this->productCard($product, $score, $crossBrand, $attrs, $applied);
        }

        usort($matches, static function (array $a, array $b): int {
            if ($a['cross_brand'] !== $b['cross_brand']) {
                return $a['cross_brand'] ? -1 : 1;
            }

            return $b['match_percent'] <=> $a['match_percent'];
        });

        $matches = array_slice($matches, 0, $limit);

        return [
            'code' => $code,
            'seed' => $this->productCard($seed, 100, false, $seedAttrs),
            'matches' => array_values($matches),
            'total' => count($matches),
            'applied_filters' => $applied,
        ];
    }

    /**
     * @param  array<string, mixed>  $seed
     * @param  array<string, mixed>  $cand
     */
    private function attributeScore(array $seed, array $cand, Product $seedProduct, Product $candProduct): int
    {
        $score = 0;

        if (($seed['kategoria_bhp'] ?? null) !== null
            && ($seed['kategoria_bhp'] === ($cand['kategoria_bhp'] ?? null))) {
            $score += 20;
        } else {
            return 0;
        }

        if (($seed['typ_wyrobu'] ?? null) && ($seed['typ_wyrobu'] === ($cand['typ_wyrobu'] ?? null))) {
            $score += 16;
        }

        $sm = $this->norm((string) ($seed['material'] ?? ''));
        $cm = $this->norm((string) ($cand['material'] ?? ''));
        if ($sm !== '' && $cm !== '' && ($sm === $cm || str_contains($cm, $sm) || str_contains($sm, $cm))) {
            $score += 22;
        } elseif (($seed['rodzina_materialu'] ?? null)
            && ($seed['rodzina_materialu'] === ($cand['rodzina_materialu'] ?? null))) {
            $score += 18;
        } else {
            $seedMats = array_map(fn ($m) => $this->norm((string) $m), is_array($seed['materialy'] ?? null) ? $seed['materialy'] : []);
            $candMats = array_map(fn ($m) => $this->norm((string) $m), is_array($cand['materialy'] ?? null) ? $cand['materialy'] : []);
            if ($seedMats !== [] && array_intersect($seedMats, $candMats) !== []) {
                $score += 14;
            }
        }

        if (($seed['klasa_ochrony'] ?? null) && ($seed['klasa_ochrony'] === ($cand['klasa_ochrony'] ?? null))) {
            $score += 18;
        }

        if (($seed['przeznaczenie'] ?? null) && ($seed['przeznaczenie'] === ($cand['przeznaczenie'] ?? null))) {
            $score += 12;
        }

        if (($seed['poziomy_en388'] ?? null) && ($seed['poziomy_en388'] === ($cand['poziomy_en388'] ?? null))) {
            $score += 16;
        }

        $normsSeed = $this->normNorms(is_array($seed['normy_en'] ?? null) ? $seed['normy_en'] : []);
        $normsCand = $this->normNorms(is_array($cand['normy_en'] ?? null) ? $cand['normy_en'] : []);
        $sharedNorms = array_intersect($normsSeed, $normsCand);
        if ($sharedNorms !== []) {
            $score += min(24, 8 * count($sharedNorms));
        }

        $markSeed = $this->markingSet($seed);
        $markCand = $this->markingSet($cand);
        $sharedMarks = array_intersect($markSeed, $markCand);
        if ($sharedMarks !== []) {
            $score += min(12, 4 * count($sharedMarks));
        }

        if (($seed['rozmiar'] ?? null) && ($seed['rozmiar'] === ($cand['rozmiar'] ?? null))) {
            $score += 4;
        }

        $tech = $score - 20;
        if ($tech < 8) {
            return 0;
        }

        // lekka kara za „sąsiedni” SKU tej samej marki (PROS-1000 vs PROS-1001)
        if ($this->looksLikeSiblingSku($seedProduct, $candProduct)) {
            $score = (int) round($score * 0.55);
        }

        return min(99, $score);
    }

    /**
     * @param  array<string, mixed>  $seed
     * @param  array<string, mixed>  $cand
     */
    private function isEquivalentArticle(array $seed, array $cand): bool
    {
        $seedKat = $seed['kategoria_bhp'] ?? null;
        $candKat = $cand['kategoria_bhp'] ?? null;
        if ($seedKat !== null && $candKat !== null && $seedKat !== $candKat) {
            return false;
        }
        if ($seedKat !== null && $candKat === null) {
            return false;
        }

        $seedType = is_string($seed['typ_wyrobu'] ?? null) ? $seed['typ_wyrobu'] : null;
        $candType = is_string($cand['typ_wyrobu'] ?? null) ? $cand['typ_wyrobu'] : null;
        if ($seedType !== null && $candType !== null && $seedType !== $candType) {
            return false;
        }
        if ($this->respiratoryMaskVsFilter($seedType, $candType)) {
            return false;
        }

        $seedMat = $seed['rodzina_materialu'] ?? null;
        $candMat = $cand['rodzina_materialu'] ?? null;
        if ($seedMat !== null && $candMat !== null && $seedMat !== $candMat) {
            return false;
        }

        $seedUse = $seed['przeznaczenie'] ?? null;
        $candUse = $cand['przeznaczenie'] ?? null;
        if ($seedUse !== null && $candUse !== null && $seedUse !== $candUse) {
            return false;
        }

        if (! $this->classMeetsSeed($seed, $cand)) {
            return false;
        }

        if (($seed['kategoria_bhp'] ?? null) === 'obuwie') {
            $need = array_intersect($this->markingSet($seed), self::REQUIRED_MARKINGS);
            if ($need !== [] && array_diff($need, $this->markingSet($cand)) !== []) {
                return false;
            }
        }

        return true;
    }

    private function respiratoryMaskVsFilter(?string $a, ?string $b): bool
    {
        $masks = ['ffp', 'reusable_half', 'fullface'];
        $filters = ['filter'];

        return $a !== null && $b !== null
            && ((in_array($a, $masks, true) && in_array($b, $filters, true))
                || (in_array($a, $filters, true) && in_array($b, $masks, true)));
    }

    /**
     * @param  array<string, mixed>  $seed
     * @param  array<string, mixed>  $cand
     */
    private function classMeetsSeed(array $seed, array $cand): bool
    {
        $seedKey = $this->classRankKey((string) ($seed['klasa_ochrony'] ?? ''));
        if ($seedKey === null) {
            return true;
        }
        $candKey = $this->classRankKey((string) ($cand['klasa_ochrony'] ?? ''));
        if ($candKey === null) {
            return $seedKey['group'] !== 'footwear';
        }
        if ($seedKey['group'] !== $candKey['group']) {
            return true;
        }

        return $candKey['rank'] >= $seedKey['rank'];
    }

    /**
     * @return array{group: string, rank: int}|null
     */
    private function classRankKey(string $class): ?array
    {
        $c = mb_strtoupper(trim($class));
        if ($c === '') {
            return null;
        }
        if (isset(self::CLASS_RANKS[$c])) {
            return self::CLASS_RANKS[$c];
        }
        if (preg_match('/KAT\.?\s*(I{1,3}|[123])/u', $c, $m) === 1) {
            $n = $m[1];
            $key = match ($n) {
                'I', '1' => 'KAT1',
                'II', '2' => 'KAT2',
                default => 'KAT3',
            };

            return self::CLASS_RANKS[$key];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return list<string>
     */
    private function markingSet(array $attrs): array
    {
        $raw = is_array($attrs['oznaczenia'] ?? null) ? $attrs['oznaczenia'] : [];
        $out = [];
        foreach ($raw as $tag) {
            $t = mb_strtoupper(trim((string) $tag));
            if ($t !== '') {
                $out[] = $t;
            }
        }

        return array_values(array_unique($out));
    }

    private function looksLikeSiblingSku(Product $a, Product $b): bool
    {
        $ma = mb_strtolower(trim((string) $a->manufacturer));
        $mb = mb_strtolower(trim((string) $b->manufacturer));
        if ($ma === '' || $ma !== $mb) {
            return false;
        }

        $sa = preg_replace('/[^a-z0-9]/i', '', (string) $a->sku) ?? '';
        $sb = preg_replace('/[^a-z0-9]/i', '', (string) $b->sku) ?? '';
        if ($sa === '' || $sb === '' || $sa === $sb) {
            return false;
        }

        // wspólny prefiks literowy + różnica tylko w końcówce numerycznej
        if (preg_match('/^([a-z]+)(\d+)$/i', $sa, $maParts) !== 1
            || preg_match('/^([a-z]+)(\d+)$/i', $sb, $mbParts) !== 1) {
            return false;
        }

        return mb_strtolower($maParts[1]) === mb_strtolower($mbParts[1])
            && $maParts[2] !== $mbParts[2]
            && abs((int) $maParts[2] - (int) $mbParts[2]) <= 5;
    }

    private function resolveSeed(string $code): ?Product
    {
        $exact = Product::query()->where('sku', $code)->first();
        if ($exact instanceof Product) {
            return $exact;
        }

        $like = Product::query()
            ->where('sku', 'like', $code.'%')
            ->orderByRaw('CASE WHEN sku = ? THEN 0 ELSE 1 END', [$code])
            ->orderBy('sku')
            ->first();

        if ($like instanceof Product) {
            return $like;
        }

        return Product::query()
            ->where('enrichment_payload->attributes->kod_producenta', $code)
            ->first();
    }

    /**
     * @param  list<string>  $norms
     * @return list<string>
     */
    private function normNorms(array $norms): array
    {
        $out = [];
        foreach ($norms as $n) {
            $c = preg_replace('/\s+/', '', mb_strtolower((string) $n)) ?? '';
            // tylko rdzeń normy EN… bez poziomów/klas, żeby EN 343 ≠ EN 388 nie mieszać przypadkowo,
            // ale EN ISO 20345 i EN 20345 mogły się zbliżyć — bierzemy en+cyfry
            if (preg_match('/en(?:iso)?(\d{3,5})/i', $c, $m) === 1) {
                $out[] = 'en'.$m[1];
            }
        }

        return array_values(array_unique($out));
    }

    private function norm(string $v): string
    {
        $t = mb_strtolower(trim($v));
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];

        return strtr($t, $map);
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @param  list<array<string, mixed>>  $matchedFilters
     * @return array<string, mixed>
     */
    private function productCard(
        Product $product,
        int $score,
        bool $crossBrand,
        array $attrs,
        array $matchedFilters = [],
    ): array {
        $product->loadMissing('images');
        $thumb = $product->images->firstWhere('is_primary', true) ?? $product->images->first();

        return [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'manufacturer' => $product->manufacturer,
            'category' => $product->category,
            'catalog_price_net' => $product->catalog_price_net !== null
                ? (float) $product->catalog_price_net
                : null,
            'match_percent' => $score,
            'cross_brand' => $crossBrand,
            'image_url' => $thumb?->url(),
            'has_description' => $product->hasUsableDescription(),
            'attributes' => $attrs,
            'matched_filters' => $matchedFilters,
        ];
    }
}
