<?php

declare(strict_types=1);

namespace App\Services\Presta;

interface PrestaExportGateway
{
    public function writeConfigured(): bool;

    public function writeError(): string;

    /**
     * @return array{id_product: int, reference: string, url: string}|null
     */
    public function findExisting(string $sku, string $ean): ?array;

    public function resolveManufacturerId(string $name): int;

    public function resolveCategoryId(?string $name): int;

    /**
     * @param  list<string>  $sizes
     * @return array{mapped: array<string, int>, missing: list<string>}
     */
    public function resolveSizeAttributes(array $sizes, ?string $hint): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array{id_product: int, url: string}
     */
    public function createProduct(array $data): array;

    /**
     * @param  array<string, mixed>  $data
     * @return array{id_product: int, url: string}
     */
    public function updateProduct(int $prestaId, array $data): array;

    /**
     * @param  list<array{size: string, attribute_id: int, reference: string}>  $combinations
     */
    public function ensureCombinations(int $prestaId, array $combinations): void;

    public function uploadImage(int $prestaId, string $binary, string $filename): void;

    public function productImageCount(int $prestaId): int;
}
