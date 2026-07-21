<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Support\BhpAttributeNormalizer;

/**
 * Cross-reference: kod/SKU → ekwiwalenty między producentami.
 * Ranking po atrybutach BHP (kategoria, materiał, normy, klasa) — nie po nazwie/SKU.
 */
final class ProductCrossRefService
{
    private const LIMIT = 12;

    /** Minimalny wynik atrybutowy (bez bonusów marki). */
    private const MIN_SCORE = 28;

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
        $seedKat = $seedAttrs['kategoria_bhp'] ?? null;
        $seedMfr = mb_strtolower(trim((string) $seed->manufacturer));

        $pool = Product::query()
            ->where('id', '!=', $seed->id)
            ->limit(800)
            ->get();

        $matches = [];
        foreach ($pool as $product) {
            $attrs = $this->bhpAttributes->forProduct($product);

            // twarde: inna kategoria BHP = nie zamiennik (buty ≠ rękawice)
            if ($seedKat !== null && ($attrs['kategoria_bhp'] ?? null) !== null
                && $seedKat !== $attrs['kategoria_bhp']) {
                continue;
            }

            // gdy seed ma kategorię, a kandydat nie — odrzuć (za słaby sygnał)
            if ($seedKat !== null && ($attrs['kategoria_bhp'] ?? null) === null) {
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
            // bez wspólnej kategorii nie ma sensu iść dalej
            return 0;
        }

        $sm = $this->norm((string) ($seed['material'] ?? ''));
        $cm = $this->norm((string) ($cand['material'] ?? ''));
        if ($sm !== '' && $cm !== '' && ($sm === $cm || str_contains($cm, $sm) || str_contains($sm, $cm))) {
            $score += 22;
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

        if (($seed['poziomy_en388'] ?? null) && ($seed['poziomy_en388'] === ($cand['poziomy_en388'] ?? null))) {
            $score += 16;
        }

        $normsSeed = $this->normNorms(is_array($seed['normy_en'] ?? null) ? $seed['normy_en'] : []);
        $normsCand = $this->normNorms(is_array($cand['normy_en'] ?? null) ? $cand['normy_en'] : []);
        $sharedNorms = array_intersect($normsSeed, $normsCand);
        if ($sharedNorms !== []) {
            $score += min(24, 8 * count($sharedNorms));
        }

        // rozmiar — tylko lekki bonus (nie decyduje)
        if (($seed['rozmiar'] ?? null) && ($seed['rozmiar'] === ($cand['rozmiar'] ?? null))) {
            $score += 4;
        }

        // bez żadnego wspólnego sygnału technicznego (materiał/norma/klasa/en388) → 0
        $tech = $score - 20; // odjąć punkty za samą kategorię
        if ($tech < 8) {
            return 0;
        }

        // lekka kara za „sąsiedni” SKU tej samej marki (PROS-1000 vs PROS-1001)
        if ($this->looksLikeSiblingSku($seedProduct, $candProduct)) {
            $score = (int) round($score * 0.55);
        }

        return min(99, $score);
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
