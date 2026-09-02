<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Presta\PrestaExportGateway;

final class FakePrestaExportGateway implements PrestaExportGateway
{
    public bool $configured = true;

    public string $error = 'Brak klucza Webservice. Ustawienia → Sklep Presta → klucz API.';

    /** @var array<string, array{id_product: int, reference: string, url: string}> */
    public array $existing = [];

    /** @var array<string, int> */
    public array $manufacturers = [];

    /** @var list<string> */
    public array $createdManufacturers = [];

    private int $nextManufacturerId = 100;

    /** @var array<string, int> */
    public array $categories = [];

    /** @var array<string, int> */
    public array $sizeAttributes = [];

    /** @var list<array<string, mixed>> */
    public array $created = [];

    /** @var list<array<string, mixed>> */
    public array $updated = [];

    /** @var list<array<string, mixed>> */
    public array $combinations = [];

    /** @var list<array{presta_id: int, filename: string}> */
    public array $images = [];

    private int $nextId = 9000;

    public function writeConfigured(): bool
    {
        return $this->configured;
    }

    public function writeError(): string
    {
        return $this->configured ? '' : $this->error;
    }

    public function findExisting(string $sku, string $ean): ?array
    {
        if ($sku !== '' && isset($this->existing[$sku])) {
            return $this->existing[$sku];
        }
        if ($ean !== '' && isset($this->existing['ean:'.$ean])) {
            return $this->existing['ean:'.$ean];
        }

        return null;
    }

    public function resolveManufacturerId(string $name): int
    {
        $name = trim($name);
        $key = mb_strtolower($name);
        if ($key === '') {
            return 0;
        }
        if (isset($this->manufacturers[$key])) {
            return $this->manufacturers[$key];
        }
        $id = $this->nextManufacturerId++;
        $this->manufacturers[$key] = $id;
        $this->createdManufacturers[] = $name;

        return $id;
    }

    public function resolveCategoryId(?string $name): int
    {
        $key = mb_strtolower(trim((string) $name));

        return $this->categories[$key] ?? 2;
    }

    public function resolveSizeAttributes(array $sizes, ?string $hint): array
    {
        $mapped = [];
        $missing = [];
        foreach ($sizes as $size) {
            $id = $this->sizeAttributes[$size] ?? $this->sizeAttributes[(string) (int) $size] ?? null;
            if ($id === null) {
                $missing[] = $size;
            } else {
                $mapped[$size] = $id;
            }
        }

        return ['mapped' => $mapped, 'missing' => $missing];
    }

    public function createProduct(array $data): array
    {
        $id = $this->nextId++;
        $this->created[] = $data + ['id_product' => $id];

        return [
            'id_product' => $id,
            'url' => 'https://supon.rzeszow.pl/'.$id.'-'.((string) ($data['link_rewrite'] ?? 'produkt')).'.html',
        ];
    }

    public function updateProduct(int $prestaId, array $data): array
    {
        $this->updated[] = $data + ['id_product' => $prestaId];

        return [
            'id_product' => $prestaId,
            'url' => 'https://supon.rzeszow.pl/'.$prestaId.'-'.((string) ($data['link_rewrite'] ?? 'produkt')).'.html',
        ];
    }

    public function ensureCombinations(int $prestaId, array $combinations): void
    {
        $this->combinations[] = ['presta_id' => $prestaId, 'items' => $combinations];
    }

    public function uploadImage(int $prestaId, string $binary, string $filename): void
    {
        if ($binary === '') {
            return;
        }
        $this->images[] = ['presta_id' => $prestaId, 'filename' => $filename];
    }
}
