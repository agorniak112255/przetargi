<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProductImage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

final class ProductImageThumbService
{
    public function __construct(
        private readonly ProductImageWhiteTrim $trim,
    ) {}

    public function jpeg(ProductImage $image): ?string
    {
        $key = $this->cacheKey($image);
        if (Storage::disk('local')->exists($key)) {
            $cached = Storage::disk('local')->get($key);

            return is_string($cached) && $cached !== '' ? $cached : null;
        }

        $original = $this->originalBytes($image);
        if ($original === null) {
            return null;
        }

        $jpeg = $this->trim->toJpeg($original);
        if ($jpeg === null) {
            return null;
        }

        Storage::disk('local')->put($key, $jpeg);

        return $jpeg;
    }

    private function cacheKey(ProductImage $image): string
    {
        $sum = (string) ($image->checksum ?: hash('sha256', $image->path.'|'.(string) $image->source_url));

        return 'product-thumbs/'.$image->id.'-'.$sum.'.jpg';
    }

    private function originalBytes(ProductImage $image): ?string
    {
        $path = (string) $image->path;
        $isRemote = $path === '' || $path === 'remote'
            || str_starts_with($path, 'http://')
            || str_starts_with($path, 'https://');

        if (! $isRemote && Storage::disk('public')->exists($path)) {
            $bytes = Storage::disk('public')->get($path);

            return is_string($bytes) && $bytes !== '' ? $bytes : null;
        }

        $url = (string) ($image->source_url ?: $path);
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return null;
        }

        $response = Http::timeout(8)->get($url);
        if (! $response->successful() || $response->body() === '') {
            return null;
        }

        return $response->body();
    }
}
