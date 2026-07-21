<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Support\BhpAttributeNormalizer;

/**
 * Porównanie side-by-side produktu A vs B (opcjonalnie vs wymaganie SIWZ).
 */
final class ProductCompareService
{
    public function __construct(
        private readonly ProductMatchService $matcher,
        private readonly BhpAttributeNormalizer $bhpAttributes,
    ) {}

    /**
     * @return array{
     *     a: array<string, mixed>,
     *     b: array<string, mixed>,
     *     requirement: ?string,
     *     rows: list<array{key: string, label: string, a: mixed, b: mixed, requirement: mixed, match: string}>,
     *     summary: array{a_score: ?int, b_score: ?int, winner: ?string, diffs: int}
     * }
     */
    public function compare(Product $a, Product $b, ?string $requirement = null): array
    {
        $requirement = $requirement !== null ? trim($requirement) : null;
        if ($requirement === '') {
            $requirement = null;
        }

        $cardA = $this->card($a, $requirement);
        $cardB = $this->card($b, $requirement);

        $rows = [
            $this->row('sku', 'SKU', $cardA['sku'], $cardB['sku'], null),
            $this->row('manufacturer', 'Producent', $cardA['manufacturer'], $cardB['manufacturer'], null),
            $this->row('name', 'Nazwa', $cardA['name'], $cardB['name'], null),
            $this->row('price', 'Cena katalogowa', $cardA['catalog_price_net'], $cardB['catalog_price_net'], null),
            $this->row('kategoria_bhp', 'Kategoria BHP', $cardA['attributes']['kategoria_bhp'] ?? null, $cardB['attributes']['kategoria_bhp'] ?? null, $this->reqHint($requirement, 'kategoria')),
            $this->row('material', 'Materiał', $cardA['attributes']['material'] ?? null, $cardB['attributes']['material'] ?? null, $this->reqHint($requirement, 'material')),
            $this->row('klasa_ochrony', 'Klasa ochrony', $cardA['attributes']['klasa_ochrony'] ?? null, $cardB['attributes']['klasa_ochrony'] ?? null, $this->reqHint($requirement, 'klasa')),
            $this->row('poziomy_en388', 'EN 388', $cardA['attributes']['poziomy_en388'] ?? null, $cardB['attributes']['poziomy_en388'] ?? null, $this->reqHint($requirement, 'en388')),
            $this->row('normy_en', 'Normy EN', $this->joinList($cardA['attributes']['normy_en'] ?? []), $this->joinList($cardB['attributes']['normy_en'] ?? []), $this->reqHint($requirement, 'norma')),
            $this->row('rozmiar', 'Rozmiar', $cardA['attributes']['rozmiar'] ?? null, $cardB['attributes']['rozmiar'] ?? null, null),
            $this->row('kod_producenta', 'Kod producenta', $cardA['attributes']['kod_producenta'] ?? null, $cardB['attributes']['kod_producenta'] ?? null, null),
            $this->row('stock', 'Stan', $cardA['stock'], $cardB['stock'], null),
        ];

        if ($requirement !== null) {
            $rows[] = $this->row(
                'match_siwz',
                'Dopasowanie do SIWZ %',
                $cardA['siwz_score'],
                $cardB['siwz_score'],
                null,
            );
        }

        $diffs = 0;
        foreach ($rows as $row) {
            if ($row['match'] === 'diff') {
                $diffs++;
            }
        }

        $winner = null;
        if ($requirement !== null && $cardA['siwz_score'] !== null && $cardB['siwz_score'] !== null) {
            if ($cardA['siwz_score'] > $cardB['siwz_score']) {
                $winner = 'a';
            } elseif ($cardB['siwz_score'] > $cardA['siwz_score']) {
                $winner = 'b';
            } else {
                $winner = 'tie';
            }
        }

        return [
            'a' => $cardA,
            'b' => $cardB,
            'requirement' => $requirement,
            'rows' => $rows,
            'summary' => [
                'a_score' => $cardA['siwz_score'],
                'b_score' => $cardB['siwz_score'],
                'winner' => $winner,
                'diffs' => $diffs,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function card(Product $product, ?string $requirement): array
    {
        $attrs = $this->bhpAttributes->forProduct($product);
        $siwzScore = null;
        if ($requirement !== null) {
            $siwzScore = $this->matcher->explainMatch($requirement, $product)['score'];
        }

        return [
            'product_id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'manufacturer' => $product->manufacturer,
            'category' => $product->category,
            'catalog_price_net' => $product->catalog_price_net !== null
                ? (float) $product->catalog_price_net
                : null,
            'stock' => (int) ($product->stock ?? 0),
            'attributes' => $attrs,
            'siwz_score' => $siwzScore,
        ];
    }

    /**
     * @return array{key: string, label: string, a: mixed, b: mixed, requirement: mixed, match: string}
     */
    private function row(string $key, string $label, mixed $a, mixed $b, mixed $requirement): array
    {
        $normA = $this->normVal($a);
        $normB = $this->normVal($b);
        $match = 'same';
        if ($normA === '' && $normB === '') {
            $match = 'empty';
        } elseif ($normA !== $normB) {
            $match = 'diff';
        }

        return [
            'key' => $key,
            'label' => $label,
            'a' => $a,
            'b' => $b,
            'requirement' => $requirement,
            'match' => $match,
        ];
    }

    private function normVal(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_float($v) || is_int($v)) {
            return (string) $v;
        }

        return mb_strtolower(trim((string) $v));
    }

    /** @param  list<string>|mixed  $list */
    private function joinList(mixed $list): ?string
    {
        if (! is_array($list) || $list === []) {
            return null;
        }

        return implode(', ', array_map('strval', $list));
    }

    private function reqHint(?string $requirement, string $kind): ?string
    {
        if ($requirement === null) {
            return null;
        }
        $r = mb_strtolower($requirement);

        return match ($kind) {
            'material' => preg_match('/\b(nitryl|lateks|sk[oó]ra|dyneema|hppe|poliamid|pu)\b/u', $r, $m)
                ? $m[1] : null,
            'klasa' => preg_match('/\b(s1p?|s2|s3|sb|ob|src)\b/u', $r, $m)
                ? mb_strtoupper($m[1]) : null,
            'en388' => preg_match('/en\s*388(?::\s*\d{4})?\s*([0-9x]{3,5}[a-f]?)/iu', $requirement, $m)
                ? mb_strtoupper($m[1]) : null,
            'norma' => preg_match('/en(?:\s*iso)?\s*\d{3,5}/iu', $requirement, $m)
                ? $m[0] : null,
            'kategoria' => preg_match('/\b(rekawic|obuwie|buty|odziez)/u', $this->stripPl($r), $m)
                ? $m[1] : null,
            default => null,
        };
    }

    private function stripPl(string $t): string
    {
        return strtr($t, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        ]);
    }
}
