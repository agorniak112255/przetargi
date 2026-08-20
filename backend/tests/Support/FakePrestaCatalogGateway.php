<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Product;
use App\Services\Presta\PrestaCatalogGateway;

final class FakePrestaCatalogGateway implements PrestaCatalogGateway
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var list<string> */
    public array $images = [];

    public bool $isConfigured = true;

    public function configured(): bool
    {
        return $this->isConfigured;
    }

    public function ping(): array
    {
        return [
            'ok' => $this->isConfigured,
            'message' => $this->isConfigured ? 'ok' : 'off',
            'active_products' => count($this->rows),
            'has_image_table' => false,
        ];
    }

    public function findCandidates(Product $product, int $limit = 20): array
    {
        return $this->rows;
    }

    public function findCard(int $prestaId): ?array
    {
        foreach ($this->rows as $row) {
            if ((int) ($row['id_product'] ?? 0) === $prestaId) {
                return $row;
            }
        }

        return null;
    }

    public function imageUrls(int $prestaId, string $linkRewrite): array
    {
        return $this->images;
    }
}
