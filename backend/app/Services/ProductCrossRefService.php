<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Support\BhpAttributeNormalizer;

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

    /** @var array<string, int> */
    private const FOOTWEAR_CLASS_RANK = [
        'OB' => 1,
        'SB' => 2,
        'S1' => 3,
        'S1P' => 4,
        'S2' => 5,
        'S3' => 6,
        'S4' => 7,
        'S5' => 8,
        'S7' => 9,
    ];

    public function __construct(
        private readonly BhpAttributeNormalizer $bhpAttributes,
    ) {}

    /**
     * @return array{
     *     code: string,
     *     seed: ?array<string, mixed>,
     *     matches: list<array<string, mixed>>,
     *     total: int
     * }
     */
    public function findByCode(string $code, int $limit = self::LIMIT): array
    {
        $code = trim($code);
        $limit = max(1, min(40, $limit));

        if ($code === '') {
            return ['code' => $code, 'seed' => null, 'matches' => [], 'total' => 0];
        }

        $seed = $this->resolveSeed($code);
        if ($seed === null) {
            return ['code' => $code, 'seed' => null, 'matches' => [], 'total' => 0];
        }

        $seedAttrs = $this->bhpAttributes->forProduct($seed);
        $seedMfr = mb_strtolower(trim((string) $seed->manufacturer));

        $pool = Product::query()
            ->where('id', '!=', $seed->id)
            ->limit(800)
            ->get();

        $matches = [];
        foreach ($pool as $product) {
            $attrs = $this->bhpAttributes->forProduct($product);

            if (! $this->isEquivalentArticle($seedAttrs, $attrs)) {
                continue;
            }

            $score = $this->attributeScore($seedAttrs, $attrs, $seed, $product);
            if ($score < self::MIN_SCORE) {
                continue;
            }

            $mfr = mb_strtolower(trim((string) $product->manufacturer));
            $crossBrand = $seedMfr !== '' && $mfr !== '' && $mfr !== $seedMfr;

            // ten sam producent + prawie ten sam SKU numeryczny bez wspólnych atrybutów technicznych
            if (! $crossBrand && $this->looksLikeSiblingSku($seed, $product) && $score < 45) {
                continue;
            }

            $matches[] = $this->productCard($product, $score, $crossBrand, $attrs);
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

        $seedType = $seed['typ_wyrobu'] ?? null;
        $candType = $cand['typ_wyrobu'] ?? null;
        if ($seedType !== null && $candType !== null && $seedType !== $candType) {
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

        if (! $this->footwearClassMeetsSeed($seed, $cand)) {
            return false;
        }

        $need = array_intersect($this->markingSet($seed), self::REQUIRED_MARKINGS);
        if ($need !== [] && array_diff($need, $this->markingSet($cand)) !== []) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $seed
     * @param  array<string, mixed>  $cand
     */
    private function footwearClassMeetsSeed(array $seed, array $cand): bool
    {
        $seedClass = isset($seed['klasa_ochrony']) ? mb_strtoupper((string) $seed['klasa_ochrony']) : '';
        $candClass = isset($cand['klasa_ochrony']) ? mb_strtoupper((string) $cand['klasa_ochrony']) : '';
        $seedRank = self::FOOTWEAR_CLASS_RANK[$seedClass] ?? null;
        if ($seedRank === null) {
            return true;
        }
        $candRank = self::FOOTWEAR_CLASS_RANK[$candClass] ?? null;
        if ($candRank === null) {
            return false;
        }

        return $candRank >= $seedRank;
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
     * @return array<string, mixed>
     */
    private function productCard(Product $product, int $score, bool $crossBrand, array $attrs): array
    {
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
            'attributes' => $attrs,
        ];
    }
}
