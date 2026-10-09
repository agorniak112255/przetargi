<?php

declare(strict_types=1);

namespace App\Services\Enrichment\PartsTable;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductImageRejection;
use App\Services\Enrichment\ManufacturerProfiles;
use App\Services\Enrichment\ProductImageDownloader;
use App\Support\ImageUrlBlocklist;

/**
 * Zdjęcia karty przypiętej do tabeli części (decyzje właściciela 09/10.10.2026):
 * - zdjęcie z wiersza tabeli (styl w kolorze albo zdjęcie modelu) pobrane i ustawione jako GŁÓWNE;
 * - zdjęcia z internetu spoza hostów producenta i grafiki witryny (ImageUrlBlocklist: banery, StandUpforHealth) —
 *   ProductImageRejection::rejectAndDelete z REASON_PARTS_TABLE (nie wracają przy następnym przebiegu);
 * - inne zdjęcia z hostów producenta (coba.com) — usuwane zwykle (bez odrzucenia), tylko gdy zdjęcie z tabeli jest na
 *   karcie; bez niego zostają;
 * - zdjęcia z konta B2B (b2b_account_id) i wgrane ręcznie (bez source_url) — nietknięte, tracą tylko „główne”.
 *
 * Kolejność: ProductImage::resequence, potem zdjęcie z tabeli na pierwsze miejsce. Uwaga: późniejsze resequence
 * (np. synchronizacja B2B z nowym zdjęciem dostawcy) ustawi znów zdjęcie dostawcy przed zdjęciem z internetu.
 */
final class PartsTableImages
{
    public const REASON_FOREIGN = 'zdjęcie z internetu spoza witryny producenta';

    public const REASON_BANNER = 'grafika witryny, nie zdjęcie wyrobu';

    public const REASON_OTHER_MANUFACTURER = 'inne zdjęcie z witryny producenta (zdjęciem karty jest zdjęcie z tabeli części)';

    public function __construct(
        private readonly ProductImageDownloader $downloader,
        private readonly ManufacturerProfiles $profiles,
    ) {}

    /**
     * @return array{downloaded: ?int, removed: list<array{id: int, url: ?string, reason: string}>, kept: list<int>}
     */
    public function apply(Product $product, PartsTablePin $pin, bool $dryRun = false): array
    {
        $profile = $this->profiles->for($product);
        $tableUrl = trim((string) $pin->imageUrl);
        $tableKey = $tableUrl !== '' ? ProductImageDownloader::sameFileKey($tableUrl) : null;

        $tableImage = $tableKey !== null ? $this->imageWithKey($product, $tableKey) : null;
        if ($tableImage === null && $tableKey !== null && ! $dryRun) {
            $saved = $this->downloader->downloadMany($product, [$tableUrl], 1);
            $tableImage = $saved[0] ?? null;
        }
        $tableId = $tableImage !== null ? (int) $tableImage->id : null;
        // w podglądzie zakładamy, że zdjęcie z tabeli się pobierze (inaczej nie byłoby czego pokazać)
        $tableOnCard = $tableId !== null || ($dryRun && $tableKey !== null);

        $removed = [];
        $kept = [];
        $images = ProductImage::query()->where('product_id', $product->id)->orderBy('sort_order')->orderBy('id')->get();
        foreach ($images as $image) {
            $id = (int) $image->id;
            if ($id === $tableId) {
                continue;
            }
            $url = trim((string) $image->source_url);
            if ($image->b2b_account_id !== null || $url === '') {
                $kept[] = $id;

                continue;
            }
            // Zdjęcie spoza producenta odrzucamy (suma kontrolna na stałe) dopiero, gdy zdjęcie z tabeli jest na karcie —
            // sklep bywa z tym samym plikiem co coba.com, a odrzucona suma zablokowałaby późniejsze pobranie z tabeli.
            $reason = match (true) {
                ImageUrlBlocklist::blocked($url, $profile) !== null => self::REASON_BANNER,
                ! $tableOnCard => null,
                $profile === null || ! $profile->ownsUrl($url) => self::REASON_FOREIGN,
                default => self::REASON_OTHER_MANUFACTURER,
            };
            if ($reason === null) {
                $kept[] = $id;

                continue;
            }
            $removed[] = ['id' => $id, 'url' => $url, 'reason' => $reason];
            if ($dryRun) {
                continue;
            }
            if ($reason === self::REASON_OTHER_MANUFACTURER) {
                $image->delete();
            } else {
                ProductImageRejection::rejectAndDelete($image, ProductImageRejection::REASON_PARTS_TABLE);
            }
        }

        if (! $dryRun) {
            ProductImage::resequence((int) $product->id);
            if ($tableId !== null) {
                $this->makePrimary((int) $product->id, $tableId);
            }
        }

        return ['downloaded' => $tableId, 'removed' => $removed, 'kept' => $kept];
    }

    /** Zdjęcie karty spod tego samego pliku (ProductImageDownloader::sameFileKey) — bez ponownego pobierania. */
    private function imageWithKey(Product $product, string $key): ?ProductImage
    {
        foreach (ProductImage::query()->where('product_id', $product->id)->whereNotNull('source_url')->orderBy('id')->get() as $image) {
            if (ProductImageDownloader::sameFileKey((string) $image->source_url) === $key) {
                return $image;
            }
        }

        return null;
    }

    /** Zdjęcie z tabeli na pierwsze miejsce, reszta w kolejności po resequence; zapis tylko zmienionych wierszy. */
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
