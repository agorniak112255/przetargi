<?php

declare(strict_types=1);

namespace App\Services\Presta;

use App\Models\PrestaProductMatch;
use App\Models\PriceList;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\NbpExchangeRateService;
use App\Support\ProductSizeVariant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class PrestaProductExportService
{
    public function __construct(
        private readonly PrestaExportGateway $gateway,
        private readonly PrestaSettingsService $settings,
        private readonly ProductSizeVariant $sizes,
        private readonly NbpExchangeRateService $fx,
        private readonly PrestaCategoryMapService $categories,
    ) {}

    /**
     * @return array{
     *     product_id: int,
     *     sku: string,
     *     action: string,
     *     presta_id: int,
     *     url: string,
     *     sizes: list<string>,
     *     sizes_missing: list<string>,
     *     images: int
     * }
     */
    public function export(Product $product, bool $force = false): array
    {
        $error = $this->gateway->writeError();
        if ($error !== '') {
            throw new RuntimeException($error);
        }

        $product->loadMissing('images', 'prestaExport');
        $existing = $this->resolveExisting($product);
        if ($existing !== null && ! $force && $this->alreadyExported($product, (int) $existing['id_product'])) {
            return [
                'product_id' => (int) $product->id,
                'sku' => (string) $product->sku,
                'action' => 'exists',
                'presta_id' => (int) $existing['id_product'],
                'url' => (string) $existing['url'],
                'sizes' => $this->sizes->parseSizeList($product->packaging, $product->name, $product->sku),
                'sizes_missing' => [],
                'images' => 0,
            ];
        }

        $payload = $this->payload($product);
        if ($existing !== null) {
            $saved = $this->gateway->updateProduct((int) $existing['id_product'], $payload);
            $action = 'updated';
        } else {
            $saved = $this->gateway->createProduct($payload);
            $action = 'created';
        }

        $prestaId = (int) $saved['id_product'];
        $sizeNames = $this->sizes->parseSizeList($product->packaging, $product->name, $product->sku);
        $hint = trim((string) $product->category.' '.$product->name);
        $resolved = $this->gateway->resolveSizeAttributes($sizeNames, $hint);
        $combinations = [];
        foreach ($resolved['mapped'] as $size => $attributeId) {
            $sizeLabel = (string) $size;
            $combinations[] = [
                'size' => $sizeLabel,
                'attribute_id' => $attributeId,
                'reference' => $this->combinationReference((string) $product->sku, $sizeLabel),
            ];
        }
        $this->gateway->ensureCombinations($prestaId, $combinations);

        $images = 0;
        $hasLocalImages = $product->images->isNotEmpty();
        if ($hasLocalImages && $action === 'updated') {
            $this->gateway->deleteProductImages($prestaId);
        }
        if ($hasLocalImages && ($action === 'created' || $action === 'updated')) {
            $images = $this->uploadImages($product, $prestaId);
        }

        $match = PrestaProductMatch::query()->updateOrCreate(
            ['product_id' => $product->id, 'presta_id' => $prestaId],
            [
                'method' => 'export',
                'score' => 100,
                'status' => PrestaProductMatch::STATUS_EXPORTED,
                'presta_url' => (string) $saved['url'],
                'presta_reference' => mb_substr((string) $product->sku, 0, 128),
                'presta_name' => mb_substr((string) $product->name, 0, 255),
            ]
        );

        return [
            'product_id' => (int) $product->id,
            'sku' => (string) $product->sku,
            'action' => $action,
            'presta_id' => $prestaId,
            'url' => (string) $match->presta_url,
            'sizes' => array_map('strval', array_keys($resolved['mapped'])),
            'sizes_missing' => $resolved['missing'],
            'images' => $images,
        ];
    }

    /**
     * @param  list<int>  $productIds
     * @return array{exported: int, skipped: int, failed: int, items: list<array<string, mixed>>, errors: list<string>}
     */
    public function exportMany(array $productIds, bool $force = false): array
    {
        $exported = 0;
        $skipped = 0;
        $failed = 0;
        $items = [];
        $errors = [];
        $seen = [];
        foreach ($productIds as $id) {
            $id = (int) $id;
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $product = Product::query()->find($id);
            if (! $product instanceof Product) {
                $failed++;
                $errors[] = 'Brak produktu #'.$id;

                continue;
            }
            try {
                $row = $this->export($product, $force);
                $items[] = $row;
                if ($row['action'] === 'exists') {
                    $skipped++;
                } else {
                    $exported++;
                }
            } catch (RuntimeException $e) {
                $failed++;
                $errors[] = $product->sku.': '.$e->getMessage();
            }
        }

        return [
            'exported' => $exported,
            'skipped' => $skipped,
            'failed' => $failed,
            'items' => $items,
            'errors' => array_slice($errors, 0, 20),
        ];
    }

    /**
     * @return list<int>
     */
    public function priceListProductIds(PriceList $priceList): array
    {
        $ids = $priceList->product_ids;
        if (! is_array($ids)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Product $product): array
    {
        $cfg = $this->settings->resolve();
        $name = trim((string) $product->name);
        if ($name === '') {
            $name = trim((string) $product->sku) !== '' ? (string) $product->sku : 'Produkt';
        }
        $description = $this->descriptionHtml((string) ($product->description ?? ''));
        $short = $this->shortDescription((string) ($product->description ?? ''), $name);
        $rewrite = Str::slug($name, '-', 'pl');
        if ($rewrite === '') {
            $rewrite = 'produkt-'.(int) $product->id;
        }
        $ean = preg_replace('/\D+/', '', (string) $product->ean) ?? '';
        if (strlen($ean) < 8 || strlen($ean) > 13) {
            $ean = '';
        }

        return [
            'name' => $name,
            'description' => $description,
            'description_short' => $short,
            'link_rewrite' => $rewrite,
            'reference' => (string) $product->sku,
            'ean13' => $ean,
            'price' => $this->fx->toPln((float) $product->catalog_price_net, $product->currency),
            'id_manufacturer' => $this->gateway->resolveManufacturerId((string) $product->manufacturer),
            'id_category' => $this->categories->resolveId($product->category !== null ? (string) $product->category : null),
            'delivery_label' => $cfg['delivery_label'],
        ];
    }

    /**
     * @return array{id_product: int, reference: string, url: string}|null
     */
    private function resolveExisting(Product $product): ?array
    {
        $match = $product->prestaExport;
        if ($match instanceof PrestaProductMatch && (int) $match->presta_id > 0) {
            return [
                'id_product' => (int) $match->presta_id,
                'reference' => (string) ($match->presta_reference ?? $product->sku),
                'url' => (string) ($match->presta_url ?? ''),
            ];
        }
        $ean = (string) ($product->ean ?? '');

        return $this->gateway->findExisting((string) $product->sku, $ean);
    }

    private function alreadyExported(Product $product, int $prestaId): bool
    {
        $match = $product->prestaExport;

        return $match instanceof PrestaProductMatch
            && (int) $match->presta_id === $prestaId
            && $match->status === PrestaProductMatch::STATUS_EXPORTED;
    }

    private function combinationReference(string $sku, string $size): string
    {
        $sku = trim($sku);
        $size = trim($size);
        if ($sku === '') {
            return $size;
        }

        return mb_substr($sku.'-'.$size, 0, 64);
    }

    private function uploadImages(Product $product, int $prestaId): int
    {
        $count = 0;
        $lastError = null;
        foreach ($product->images as $image) {
            if (! $image instanceof ProductImage) {
                continue;
            }
            $binary = $this->imageBinary($image);
            if ($binary === null) {
                continue;
            }
            try {
                $this->gateway->uploadImage($prestaId, $binary, 'product-'.$product->id.'-'.$image->id.'.jpg');
                $count++;
            } catch (Throwable $e) {
                $lastError = $e;
                Log::warning('Presta: upload zdjęcia nie powiódł się.', [
                    'product_id' => $product->id,
                    'presta_id' => $prestaId,
                    'image_id' => $image->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        if ($count === 0 && $lastError !== null) {
            throw new RuntimeException($lastError->getMessage(), 0, $lastError);
        }

        return $count;
    }

    private function imageBinary(ProductImage $image): ?string
    {
        $path = (string) $image->path;
        if ($path !== '' && $path !== 'remote' && ! str_starts_with($path, 'http://') && ! str_starts_with($path, 'https://')) {
            if (Storage::disk('public')->exists($path)) {
                $bytes = Storage::disk('public')->get($path);

                return is_string($bytes) && $bytes !== '' ? $bytes : null;
            }
        }
        $url = $image->source_url ?: (str_starts_with($path, 'http') ? $path : '');
        if ($url === '') {
            return null;
        }
        try {
            $bytes = file_get_contents($url);
        } catch (Throwable) {
            return null;
        }

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    private function descriptionHtml(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '<p></p>';
        }
        if (str_contains($text, '<p') || str_contains($text, '<div') || str_contains($text, '<ul')) {
            return $text;
        }
        $escaped = htmlspecialchars($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        return '<p>'.nl2br($escaped, false).'</p>';
    }

    private function shortDescription(string $text, string $fallback): string
    {
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($text)) ?? '');
        if ($plain === '') {
            $plain = $fallback;
        }
        if (mb_strlen($plain) > 380) {
            return rtrim(mb_substr($plain, 0, 377)).'…';
        }

        return $plain;
    }
}
