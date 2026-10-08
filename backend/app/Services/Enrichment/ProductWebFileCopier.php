<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Models\ProductDocument;
use App\Models\ProductImage;
use App\Models\ProductImageRejection;
use App\Support\ImageUrlBlocklist;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pliki lidera modelu na karcie członka bez sieci (etap 2 opisów z cenników): kopia pliku na dysku (Storage public)
 * pod ścieżką nowej karty i wiersz z tą samą sumą kontrolną i adresem źródła — ta sama suma już na karcie docelowej
 * to istniejący wiersz (jak dedup w ProductImageDownloader::storeBytes / ProductDocumentDownloader). Plik, którego
 * nie ma na dysku (adres zdalny, usunięty), nie jest kopiowany. Zdjęcie świadomie usunięte z karty docelowej
 * (ProductImageRejection — adres albo suma) nie wraca; znana zaślepka (suma z config/image_blocklist.php) i grafika
 * witryny (ImageUrlBlocklist) też nie — kopia nie omija bramek pobierania.
 */
final class ProductWebFileCopier
{
    /** null = zdjęcie nie należy do $from, brak pliku na dysku albo karta docelowa odrzuciła ten plik/adres. */
    public function copyImage(Product $from, ProductImage $img, Product $to): ?ProductImage
    {
        if ((int) $img->product_id !== (int) $from->id || (int) $from->id === (int) $to->id) {
            return null;
        }
        $disk = Storage::disk('public');
        $path = (string) $img->path;
        if (! self::isLocalPath($path) || ! $disk->exists($path)) {
            return null;
        }
        $checksum = trim((string) $img->checksum);
        if ($checksum === '') {
            $checksum = hash('sha256', (string) $disk->get($path));
        }
        $sourceUrl = mb_substr(trim((string) $img->source_url), 0, 2000);
        if (ProductImageRejection::blocksChecksum((int) $to->id, $checksum)
            || ($sourceUrl !== '' && ProductImageRejection::blocksUrl((int) $to->id, $sourceUrl))) {
            return null;
        }
        // bramki zdjęć jak przy pobieraniu: znana zaślepka po sumie („404 nginx” na kartach HR Matting wracałaby kopią
        // na kolejne karty modelu) i grafika witryny po adresie (ImageUrlBlocklist z regułami profilu marki karty)
        if (ProductImageDownloader::knownPlaceholderReason($checksum) !== null
            || ($sourceUrl !== '' && ImageUrlBlocklist::blocked($sourceUrl, app(ManufacturerProfiles::class)->for($to)) !== null)) {
            return null;
        }
        $existing = ProductImage::query()->where('product_id', $to->id)->where('checksum', $checksum)->first();
        if ($existing !== null) {
            return $existing;
        }

        $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $relative = 'products/'.$to->id.'/'.Str::lower(Str::random(16)).($extension !== '' ? '.'.$extension : '');
        $disk->copy($path, $relative);
        $sort = (int) (ProductImage::query()->where('product_id', $to->id)->max('sort_order') ?? -1) + 1;
        $copy = ProductImage::query()->create([
            'product_id' => $to->id,
            'b2b_account_id' => null,
            'path' => $relative,
            'source_url' => $sourceUrl !== '' ? $sourceUrl : null,
            'is_primary' => $sort === 0,
            'sort_order' => $sort,
            'checksum' => $checksum,
        ]);
        ProductImage::resequence((int) $to->id);

        return $copy;
    }

    /**
     * Dokumenty lidera (po id, tylko jego własne) na karcie członka: ten sam plik (suma) albo ten sam adres już na
     * karcie = istniejący wiersz; tekst, rodzaj i tytuł idą razem z plikiem.
     *
     * @param  list<int>  $documentIds
     * @return list<ProductDocument>
     */
    public function copyDocuments(Product $from, array $documentIds, Product $to): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $documentIds))));
        if ($ids === [] || (int) $from->id === (int) $to->id) {
            return [];
        }
        $disk = Storage::disk('public');
        $sort = (int) (ProductDocument::query()->where('product_id', $to->id)->max('sort_order') ?? -1) + 1;
        $out = [];
        foreach (ProductDocument::query()
            ->where('product_id', $from->id)
            ->whereIntegerInRaw('id', $ids)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get() as $document) {
            $path = (string) $document->path;
            if (! self::isLocalPath($path) || ! $disk->exists($path)) {
                continue;
            }
            $checksum = trim((string) $document->checksum);
            if ($checksum === '') {
                $checksum = hash('sha256', (string) $disk->get($path));
            }
            $sourceUrl = mb_substr(trim((string) $document->source_url), 0, 2000);
            $existing = ProductDocument::query()
                ->where('product_id', $to->id)
                ->where(function ($q) use ($checksum, $sourceUrl): void {
                    $q->where('checksum', $checksum);
                    if ($sourceUrl !== '') {
                        $q->orWhere('source_url', $sourceUrl);
                    }
                })
                ->orderBy('id')
                ->first();
            if ($existing !== null) {
                $out[] = $existing;

                continue;
            }

            $extension = mb_strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $relative = 'products/'.$to->id.'/docs/'.Str::lower(Str::random(16)).($extension !== '' ? '.'.$extension : '');
            $disk->copy($path, $relative);
            $out[] = ProductDocument::query()->create([
                'product_id' => $to->id,
                'b2b_account_id' => null,
                'path' => $relative,
                'source_url' => $sourceUrl !== '' ? $sourceUrl : null,
                'title' => $document->title,
                'text' => $document->text,
                'kind' => $document->kind,
                'sort_order' => $sort,
                'checksum' => $checksum,
                'size_bytes' => $document->size_bytes ?? $disk->size($relative),
            ]);
            $sort++;
        }

        return $out;
    }

    /** Ścieżka pliku na dysku public — nie adres zdalny ani znacznik „remote” (ProductImage::publicUrl). */
    private static function isLocalPath(string $path): bool
    {
        return $path !== '' && $path !== 'remote' && ! str_starts_with($path, 'http://') && ! str_starts_with($path, 'https://');
    }
}
