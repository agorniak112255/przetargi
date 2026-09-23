<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Enrichment\ProductImageDownloader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Zdjęcie usunięte z karty świadomie. Zapis zdjęcia (ProductImageDownloader::storeBytes, galeria B2B,
 * przeniesienie z PrestaShopu) pyta tu, zanim doda plik — inaczej synchronizacja dostawcy albo ponowne
 * wzbogacanie przywracały je przy następnym przebiegu.
 */
class ProductImageRejection extends Model
{
    public const REASON_MANUAL = 'manual';

    public const REASON_AUDIT = 'audit';

    protected $fillable = [
        'product_id',
        'file_key_hash',
        'file_key',
        'checksum',
        'source_url',
        'reason',
        'user_id',
    ];

    /**
     * Usuwa zdjęcie z karty i zapamiętuje, że nie ma wrócić. Plik na dysku zostaje — sprząta go
     * products:media-report --apply, jak po każdym usuniętym wierszu.
     */
    public static function rejectAndDelete(ProductImage $image, string $reason, ?int $userId = null): void
    {
        $productId = (int) $image->product_id;
        $sourceUrl = (string) ($image->source_url ?? '');
        // bez adresu (plik wgrany lokalnie) kluczem jest ścieżka — i tak nic jej ponownie nie pobierze
        $key = $sourceUrl !== '' ? ProductImageDownloader::sameFileKey($sourceUrl) : 'path:'.$image->path;

        DB::transaction(static function () use ($image, $productId, $sourceUrl, $key, $reason, $userId): void {
            self::query()->updateOrCreate(
                ['product_id' => $productId, 'file_key_hash' => hash('sha256', $key)],
                [
                    'file_key' => $key,
                    'checksum' => $image->checksum !== null && strlen((string) $image->checksum) === 64
                        ? (string) $image->checksum
                        : null,
                    'source_url' => $sourceUrl !== '' ? $sourceUrl : null,
                    'reason' => $reason,
                    'user_id' => $userId,
                ],
            );
            $image->delete();
            ProductImage::resequence($productId);
        });
    }

    /**
     * Klucze plików odrzuconych w tej karcie (sha256 klucza => true) — do sprawdzenia wielu adresów jednym zapytaniem.
     *
     * @return array<string, true>
     */
    public static function blockedKeyHashes(int $productId): array
    {
        $out = [];
        foreach (self::query()->where('product_id', $productId)->pluck('file_key_hash') as $hash) {
            $out[(string) $hash] = true;
        }

        return $out;
    }

    /**
     * @param  array<string, true>|null  $blockedKeyHashes  wynik blockedKeyHashes() przy sprawdzaniu całej galerii
     */
    public static function blocksUrl(int $productId, string $url, ?array $blockedKeyHashes = null): bool
    {
        $hash = hash('sha256', ProductImageDownloader::sameFileKey($url));
        if ($blockedKeyHashes !== null) {
            return isset($blockedKeyHashes[$hash]);
        }

        return self::query()->where('product_id', $productId)->where('file_key_hash', $hash)->exists();
    }

    /** Ten sam plik pod innym adresem — dedup po sumie kontrolnej, jak w storeBytes. */
    public static function blocksChecksum(int $productId, string $checksum): bool
    {
        return self::query()->where('product_id', $productId)->where('checksum', $checksum)->exists();
    }
}
