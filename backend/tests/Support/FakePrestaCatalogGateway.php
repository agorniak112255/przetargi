<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Product;
use App\Services\Presta\PrestaCatalogGateway;
use App\Services\Presta\PrestaSearchQuery;
use App\Support\ProductSizeVariant;

final class FakePrestaCatalogGateway implements PrestaCatalogGateway
{
    /** @var list<array<string, mixed>> */
    public array $rows = [];

    /** @var list<string> */
    public array $images = [];

    public bool $isConfigured = true;

    private readonly PrestaSearchQuery $query;

    public function __construct(?PrestaSearchQuery $query = null)
    {
        $this->query = $query ?? new PrestaSearchQuery(new ProductSizeVariant);
    }

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
        $limit = max(1, min(40, $limit));
        $code = array_values(array_filter(
            $this->rows,
            fn (array $row): bool => $this->query->rowMatchesCode($product, $row)
        ));
        if ($code !== []) {
            return array_slice($code, 0, $limit);
        }

        $named = array_values(array_filter(
            $this->rows,
            fn (array $row): bool => $this->query->rowMatchesBrandAndName($product, $row)
        ));

        return array_slice($named, 0, $limit);
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
