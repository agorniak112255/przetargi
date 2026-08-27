<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Support\BhpAttributeNormalizer;

/** Porównanie side-by-side 2–5 produktów (opcjonalnie vs wymaganie SIWZ). */
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
        $many = $this->compareMany([$a, $b], $requirement);
        $winnerProductId = $many['summary']['winner_product_id'];
        $winner = $many['summary']['tie']
            ? 'tie'
            : ($winnerProductId === $a->id ? 'a' : ($winnerProductId === $b->id ? 'b' : null));
        $rows = array_map(static fn (array $row): array => $row + [
            'a' => $row['values'][0] ?? null,
            'b' => $row['values'][1] ?? null,
        ], $many['rows']);

        return [
            'a' => $many['products'][0],
            'b' => $many['products'][1],
            'products' => $many['products'],
            'requirement' => $many['requirement'],
            'rows' => $rows,
            'summary' => [
                'a_score' => $many['products'][0]['siwz_score'],
                'b_score' => $many['products'][1]['siwz_score'],
                'winner' => $winner,
                'winner_product_id' => $winnerProductId,
                'tie' => $many['summary']['tie'],
                'diffs' => $many['summary']['diffs'],
            ],
        ];
    }

    /**
     * @param  list<Product>  $products
     * @return array{
     *     products: list<array<string, mixed>>,
     *     requirement: ?string,
     *     rows: list<array{key: string, label: string, values: list<mixed>, requirement: mixed, match: string}>,
     *     summary: array{winner_product_id: ?int, tie: bool, diffs: int}
     * }
     */
    public function compareMany(array $products, ?string $requirement = null): array
    {
        if (count($products) < 2 || count($products) > 5) {
            throw new \InvalidArgumentException('Porównanie wymaga od 2 do 5 produktów.');
        }

        $requirement = $requirement !== null ? trim($requirement) : null;
        if ($requirement === '') {
            $requirement = null;
        }

        $cards = array_values(array_map(
            fn (Product $product): array => $this->card($product, $requirement),
            $products
        ));

        $rows = [
            $this->rowMany('sku', 'SKU', array_column($cards, 'sku'), null),
            $this->rowMany('manufacturer', 'Producent', array_column($cards, 'manufacturer'), null),
            $this->rowMany('name', 'Nazwa', array_column($cards, 'name'), null),
            $this->rowMany('price', 'Cena katalogowa', array_column($cards, 'catalog_price_net'), null),
            $this->attributeRow($cards, 'kategoria_bhp', 'Kategoria BHP', $this->reqHint($requirement, 'kategoria')),
            $this->attributeRow($cards, 'typ_wyrobu', 'Typ wyrobu', $this->reqHint($requirement, 'typ')),
            $this->attributeRow($cards, 'material', 'Materiał', $this->reqHint($requirement, 'material')),
            $this->attributeRow($cards, 'klasa_ochrony', 'Klasa ochrony', $this->reqHint($requirement, 'klasa')),
            $this->attributeRow($cards, 'przeznaczenie', 'Przeznaczenie', $this->reqHint($requirement, 'przeznaczenie')),
            $this->attributeRow($cards, 'poziomy_en388', 'EN 388', $this->reqHint($requirement, 'en388')),
            $this->rowMany(
                'normy_en',
                'Normy EN',
                array_map(fn (array $card): ?string => $this->joinList($card['attributes']['normy_en'] ?? []), $cards),
                $this->reqHint($requirement, 'norma')
            ),
            $this->attributeRow($cards, 'rozmiar', 'Rozmiar', null),
            $this->attributeRow($cards, 'kod_producenta', 'Kod producenta', null),
            $this->rowMany('stock', 'Stan', array_column($cards, 'stock'), null),
        ];

        if ($requirement !== null) {
            $rows[] = $this->rowMany(
                'match_siwz',
                'Dopasowanie do SIWZ %',
                array_column($cards, 'siwz_score'),
                null,
            );
        }

        $diffs = 0;
        foreach ($rows as $row) {
            if ($row['match'] === 'diff') {
                $diffs++;
            }
        }

        $winnerProductId = null;
        $tie = false;
        if ($requirement !== null) {
            $scores = array_values(array_filter(
                array_map(
                    static fn (array $card): ?array => $card['siwz_score'] !== null
                        ? ['product_id' => $card['product_id'], 'score' => $card['siwz_score']]
                        : null,
                    $cards
                )
            ));
            if ($scores !== []) {
                $maxScore = max(array_column($scores, 'score'));
                $winners = array_values(array_filter(
                    $scores,
                    static fn (array $score): bool => $score['score'] === $maxScore
                ));
                $tie = count($winners) > 1;
                if (! $tie) {
                    $winnerProductId = (int) $winners[0]['product_id'];
                }
            }
        }

        return [
            'products' => $cards,
            'requirement' => $requirement,
            'rows' => $rows,
            'summary' => [
                'winner_product_id' => $winnerProductId,
                'tie' => $tie,
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
     * @param  list<array<string, mixed>>  $cards
     * @return array{key: string, label: string, values: list<mixed>, requirement: mixed, match: string}
     */
    private function attributeRow(array $cards, string $key, string $label, mixed $requirement): array
    {
        return $this->rowMany(
            $key,
            $label,
            array_map(static fn (array $card): mixed => $card['attributes'][$key] ?? null, $cards),
            $requirement
        );
    }

    /**
     * @param  list<mixed>  $values
     * @return array{key: string, label: string, values: list<mixed>, requirement: mixed, match: string}
     */
    private function rowMany(string $key, string $label, array $values, mixed $requirement): array
    {
        $normalized = array_map(fn (mixed $value): string => $this->normVal($value), $values);
        $match = 'same';
        if (array_filter($normalized, static fn (string $value): bool => $value !== '') === []) {
            $match = 'empty';
        } elseif (count(array_unique($normalized)) > 1) {
            $match = 'diff';
        }

        return [
            'key' => $key,
            'label' => $label,
            'values' => array_values($values),
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
