<?php

declare(strict_types=1);

namespace App\Services\Presta;

use App\Models\Product;
use App\Support\ProductModelFuzzy;

final class PrestaProductBinder
{
    public function __construct(
        private readonly ProductModelFuzzy $modelFuzzy,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{presta_id: int, method: string, score: int, action: string, card: array<string, mixed>}>
     */
    public function rank(Product $product, array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $hit = $this->scoreOne($product, $row);
            if ($hit === null) {
                continue;
            }
            $out[] = $hit;
        }

        usort($out, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $seen = [];
        $unique = [];
        foreach ($out as $hit) {
            $id = $hit['presta_id'];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $unique[] = $hit;
        }

        return array_slice($unique, 0, 8);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{presta_id: int, method: string, score: int, action: string, card: array<string, mixed>}|null
     */
    private function scoreOne(Product $product, array $row): ?array
    {
        $id = (int) ($row['id_product'] ?? 0);
        if ($id <= 0) {
            return null;
        }

        $sku = $this->compact((string) $product->sku);
        $ean = preg_replace('/\D+/', '', (string) $product->ean) ?? '';
        $ref = $this->compact((string) ($row['reference'] ?? ''));
        $rowEan = preg_replace('/\D+/', '', (string) ($row['ean13'] ?? '')) ?? '';

        if ($ean !== '' && mb_strlen($ean) >= 8 && $ean === $rowEan) {
            return $this->hit($id, 'ean', 98, 'auto', $row);
        }

        if ($sku !== '' && $ref !== '' && $sku === $ref && $this->brandCompatible($product, $row)) {
            return $this->hit($id, 'reference', 96, 'auto', $row);
        }

        $ghost = new Product;
        $ghost->forceFill([
            'sku' => (string) ($row['reference'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
            'manufacturer' => (string) ($row['manufacturer'] ?? ''),
        ]);
        $fuzzy = $this->modelFuzzy->score(
            trim($product->manufacturer.' '.$product->name.' '.$product->sku),
            $ghost
        );
        if ($fuzzy >= 80 && $this->brandCompatible($product, $row)) {
            return $this->hit($id, 'fuzzy_model', min(94, $fuzzy), $fuzzy >= 86 ? 'auto' : 'review', $row);
        }

        $nameScore = $this->nameScore((string) $product->name, (string) ($row['name'] ?? ''));
        if ($nameScore >= 55 && $this->brandCompatible($product, $row, true)) {
            return $this->hit($id, 'name', $nameScore, 'review', $row);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array{presta_id: int, method: string, score: int, action: string, card: array<string, mixed>}
     */
    private function hit(int $id, string $method, int $score, string $action, array $row): array
    {
        return [
            'presta_id' => $id,
            'method' => $method,
            'score' => $score,
            'action' => $action,
            'card' => $row,
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function brandCompatible(Product $product, array $row, bool $required = false): bool
    {
        $a = $this->compact((string) $product->manufacturer);
        $b = $this->compact((string) ($row['manufacturer'] ?? ''));
        if ($a === '' || $b === '') {
            return ! $required;
        }

        return str_contains($a, $b) || str_contains($b, $a);
    }

    private function nameScore(string $catalog, string $presta): int
    {
        $a = $this->compact($catalog);
        $b = $this->compact($presta);
        if ($a === '' || $b === '' || mb_strlen($a) < 5 || mb_strlen($b) < 5) {
            return 0;
        }
        if ($a === $b || str_contains($b, $a) || str_contains($a, $b)) {
            return 72;
        }
        similar_text($a, $b, $pct);

        return $pct >= 62 ? (int) round($pct * 0.85) : 0;
    }

    private function compact(string $s): string
    {
        $s = mb_strtolower($s);
        $map = ['ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z'];

        return preg_replace('/[^a-z0-9]/', '', strtr($s, $map)) ?? '';
    }
}
