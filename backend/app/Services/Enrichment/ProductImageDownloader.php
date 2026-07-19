<?php

declare(strict_types=1);

namespace App\Services\Enrichment;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class ProductImageDownloader
{
    private const MAX_BYTES = 5_000_000;

    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    /**
     * @param  list<string>  $urls
     * @return list<ProductImage>
     */
    public function downloadMany(Product $product, array $urls, int $max = 5): array
    {
        $saved = [];
        $sort = 0;

        foreach (array_values(array_unique($urls)) as $url) {
            if (count($saved) >= $max) {
                break;
            }
            if (! is_string($url) || ! str_starts_with($url, 'http')) {
                continue;
            }

            try {
                $image = $this->downloadOne($product, $url, $sort);
            } catch (Throwable $e) {
                Log::info('Product image download skipped', [
                    'product_id' => $product->id,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($image === null) {
                continue;
            }

            $saved[] = $image;
            $sort++;
        }

        return $saved;
    }

    private function downloadOne(Product $product, string $url, int $sortOrder): ?ProductImage
    {
        $response = Http::timeout(30)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
            ])
            ->withOptions(['allow_redirects' => true])
            ->get($url);

        if (! $response->successful()) {
            return null;
        }

        $bytes = $response->body();
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            return null;
        }

        $mime = (string) ($response->header('Content-Type') ?: '');
        $mime = strtolower(trim(explode(';', $mime)[0] ?? ''));
        if ($mime === '' || ! isset(self::ALLOWED_MIME[$mime])) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = strtolower((string) $finfo->buffer($bytes));
        }
        if (! isset(self::ALLOWED_MIME[$mime])) {
            return null;
        }

        $checksum = hash('sha256', $bytes);
        $existing = ProductImage::query()
            ->where('product_id', $product->id)
            ->where('checksum', $checksum)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $ext = self::ALLOWED_MIME[$mime];
        $relative = 'products/'.$product->id.'/'.Str::lower(Str::random(16)).'.'.$ext;
        Storage::disk('public')->put($relative, $bytes);

        return ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => $relative,
            'source_url' => mb_substr($url, 0, 2000),
            'is_primary' => $sortOrder === 0,
            'sort_order' => $sortOrder,
            'checksum' => $checksum,
        ]);
    }
}
