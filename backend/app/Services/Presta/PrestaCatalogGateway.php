<?php

declare(strict_types=1);

namespace App\Services\Presta;

use App\Models\Product;

interface PrestaCatalogGateway
{
    public function configured(): bool;

    /**
     * @return array{ok: bool, message: string, active_products: int, has_image_table: bool}
     */
    public function ping(): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function findCandidates(Product $product, int $limit = 20): array;

    /**
     * @return array<string, mixed>|null
     */
    public function findCard(int $prestaId): ?array;

    /**
     * @return list<string>
     */
    public function imageUrls(int $prestaId, string $linkRewrite): array;
}
