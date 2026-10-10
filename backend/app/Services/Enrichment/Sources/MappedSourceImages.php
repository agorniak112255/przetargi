<?php

declare(strict_types=1);

namespace App\Services\Enrichment\Sources;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Enrichment\ProductImageDownloader;

/**
 * Zdjęcie wskazane przez importer cennika (MappedSourcePin::imageUrl, 10.10.2026): pobrane i ustawione jako GŁÓWNE.
 * W odróżnieniu od PartsTableImages NIC nie odrzuca i nie kasuje — pozostałe zdjęcia karty (B2B, ręczne, z internetu)
 * zostają jako dodatkowe. Plik już na karcie (ten sam plik po ProductImageDownloader::sameFileKey) nie jest pobierany
 * ponownie. Kolejność: ProductImage::resequence, potem zdjęcie z mapy na pierwsze miejsce.
 */
final class MappedSourceImages
{
    public function __construct(private readonly ProductImageDownloader $downloader) {}

    /**
     * @return array{downloaded: ?int, kept: list<int>}
     */
    public function apply(Product $product, SourcePin $pin, bool $dryRun = false): array
    {
        $url = trim((string) $pin->imageUrl());
        $key = $url !== '' ? ProductImageDownloader::sameFileKey($url) : null;
        $image = $key !== null ? $this->imageWithKey($product, $key) : null;
        if ($image === null && $key !== null && ! $dryRun) {
            $saved = $this->downloader->downloadMany($product, [$url], 1);
            $image = $saved[0] ?? null;
        }
        $id = $image !== null ? (int) $image->id : null;

        if (! $dryRun && $id !== null) {
            ProductImage::resequence((int) $product->id);
            $this->makePrimary((int) $product->id, $id);
        }
        $kept = ProductImage::query()->where('product_id', $product->id)->orderBy('sort_order')->orderBy('id')->pluck('id')
            ->map(static fn ($v): int => (int) $v)
            ->reject(static fn (int $v): bool => $v === $id)
            ->values()
            ->all();

        return ['downloaded' => $id, 'kept' => $kept];
    }

    private function imageWithKey(Product $product, string $key): ?ProductImage
    {
        foreach (ProductImage::query()->where('product_id', $product->id)->whereNotNull('source_url')->orderBy('id')->get() as $image) {
            if (ProductImageDownloader::sameFileKey((string) $image->source_url) === $key) {
                return $image;
            }
        }

        return null;
    }

    /** Zdjęcie z mapy na pierwsze miejsce, reszta w kolejności po resequence; zapis tylko zmienionych wierszy. */
    private function makePrimary(int $productId, int $imageId): void
    {
        $images = ProductImage::query()->where('product_id', $productId)->orderBy('sort_order')->orderBy('id')->get();
        $ordered = $images->sortBy(static fn (ProductImage $i): int => (int) $i->id === $imageId ? 0 : 1, SORT_NUMERIC)->values();
        foreach ($ordered as $position => $image) {
            $primary = $position === 0;
            if ((int) $image->sort_order !== $position || (bool) $image->is_primary !== $primary) {
                $image->forceFill(['sort_order' => $position, 'is_primary' => $primary])->save();
            }
        }
    }
}
