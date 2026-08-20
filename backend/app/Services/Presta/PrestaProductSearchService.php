<?php

declare(strict_types=1);

namespace App\Services\Presta;

use App\Models\PrestaProductMatch;
use App\Models\Product;

final class PrestaProductSearchService
{
    public function __construct(
        private readonly PrestaCatalogGateway $catalog,
        private readonly PrestaProductBinder $binder,
    ) {}

    /**
     * @return array{
     *     product_id: int,
     *     sku: string,
     *     configured: bool,
     *     candidates: list<array<string, mixed>>,
     *     auto: array<string, mixed>|null
     * }
     */
    public function search(Product $product): array
    {
        if (! $this->catalog->configured()) {
            return [
                'product_id' => (int) $product->id,
                'sku' => (string) $product->sku,
                'configured' => false,
                'candidates' => [],
                'auto' => null,
            ];
        }

        $ranked = $this->binder->rank($product, $this->catalog->findCandidates($product));
        $auto = null;
        foreach ($ranked as $hit) {
            if ($hit['action'] === 'auto') {
                $auto = $hit;
                break;
            }
            $this->rememberReview($product, $hit);
        }

        return [
            'product_id' => (int) $product->id,
            'sku' => (string) $product->sku,
            'configured' => true,
            'candidates' => array_map(fn (array $hit): array => $this->present($hit), $ranked),
            'auto' => $auto === null ? null : $this->present($auto),
        ];
    }

    /**
     * @param  list<int>  $productIds
     * @return list<array<string, mixed>>
     */
    public function searchMany(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        $out = [];
        foreach (Product::query()->whereIn('id', $ids)->get() as $product) {
            $out[] = $this->search($product);
        }

        return $out;
    }

    /**
     * @param  array{presta_id: int, method: string, score: int, action: string, card: array<string, mixed>}  $hit
     */
    private function rememberReview(Product $product, array $hit): void
    {
        PrestaProductMatch::query()->updateOrCreate(
            [
                'product_id' => $product->id,
                'presta_id' => $hit['presta_id'],
            ],
            [
                'method' => $hit['method'],
                'score' => $hit['score'],
                'status' => PrestaProductMatch::STATUS_REVIEW,
                'presta_url' => (string) ($hit['card']['url'] ?? ''),
                'presta_reference' => mb_substr((string) ($hit['card']['reference'] ?? ''), 0, 128),
                'presta_name' => mb_substr((string) ($hit['card']['name'] ?? ''), 0, 255),
            ]
        );
    }

    /**
     * @param  array{presta_id: int, method: string, score: int, action: string, card: array<string, mixed>}  $hit
     * @return array<string, mixed>
     */
    private function present(array $hit): array
    {
        $card = $hit['card'];

        return [
            'presta_id' => $hit['presta_id'],
            'method' => $hit['method'],
            'score' => $hit['score'],
            'action' => $hit['action'],
            'reference' => (string) ($card['reference'] ?? ''),
            'ean13' => (string) ($card['ean13'] ?? ''),
            'name' => (string) ($card['name'] ?? ''),
            'manufacturer' => (string) ($card['manufacturer'] ?? ''),
            'url' => (string) ($card['url'] ?? ''),
            'description_preview' => mb_substr(
                trim(html_entity_decode(strip_tags((string) ($card['description_short'] ?: $card['description'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                0,
                280
            ),
        ];
    }
}
