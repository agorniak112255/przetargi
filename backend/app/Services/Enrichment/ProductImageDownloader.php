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
        'image/avif' => 'avif',
        'image/gif' => 'gif',
    ];

    public function __construct(
        private readonly BlockedPageReader $blockedPages = new BlockedPageReader,
    ) {}

    /**
     * Odrzuca URL karty produktu (HTML) — wcześniej SKU w ścieżce dawało fałszywy „hit”.
     */
    public static function looksLikeImageUrl(string $url): bool
    {
        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            return false;
        }
        $host = mb_strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
        $path = mb_strtolower((string) (parse_url($url, PHP_URL_PATH) ?? ''));
        if ($path === '' || str_ends_with($path, '/')) {
            return false;
        }
        // .png.webp / .jpg?itok=...
        if (preg_match('/\.(jpe?g|png|webp|gif|avif|bmp)(\.(webp|avif))?(\?|$)/i', $path) === 1) {
            return true;
        }
        // Ansell Sitecore PIM: …/065g_primary.ashx
        if (str_ends_with($path, '.ashx') && (
            str_contains($path, '/media/')
            || str_contains($path, '/pim/')
            || str_contains($path, 'product-assets')
        )) {
            return true;
        }
        // typowe CDN / media / Drupal / uvex shop-media (często bez rozszerzenia w path)
        if (preg_match('#/(media|shop-media|fileadmin|images?|img|cdn|static|uploads|assets|product[-_]?images?|sites/default/files|pim/products|product-assets)/#i', $path) === 1) {
            return true;
        }
        // CloudFront / imgproxy uvex: /images/{hash}/w:992/h:992/...
        if (str_contains($host, 'cloudfront.net') && preg_match('#^/images/[^/]+/#i', $path) === 1) {
            return true;
        }
        // Cloudflare Images: /{konto}/{id-obrazka}/{wariant} — nigdy bez rozszerzenia
        if ($host === 'imagedelivery.net' && preg_match('#^/[^/]+/[^/]+/[^/]+$#', $path) === 1) {
            return true;
        }

        return false;
    }

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
            if (! self::looksLikeImageUrl($url)) {
                Log::info('Product image download skipped', [
                    'product_id' => $product->id,
                    'url' => $url,
                    'error' => 'URL nie wygląda na plik obrazka',
                ]);

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

    /**
     * Ostatnia deska: zrzut karty producenta (gdy pliki mediów za bot-wallem).
     *
     * @param  list<string>  $pageUrls
     */
    public function downloadPageScreenshot(Product $product, array $pageUrls, int $sortOrder = 0): ?ProductImage
    {
        foreach (array_values(array_unique($pageUrls)) as $pageUrl) {
            if (! is_string($pageUrl) || ! str_starts_with($pageUrl, 'http')) {
                continue;
            }
            // tylko sensowna karta produktu, nie listing
            $path = mb_strtolower((string) (parse_url($pageUrl, PHP_URL_PATH) ?? ''));
            if ($path === '' || str_contains($path, '/search') || str_contains($path, '/category')) {
                continue;
            }

            try {
                $bytes = $this->blockedPages->fetchScreenshot($pageUrl);
            } catch (Throwable $e) {
                Log::info('Product page screenshot skipped', [
                    'product_id' => $product->id,
                    'url' => $pageUrl,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($bytes === null) {
                continue;
            }

            $mime = str_starts_with($bytes, "\x89PNG") ? 'image/png' : 'image/jpeg';
            $image = $this->storeBytes($product, $bytes, $mime, $pageUrl.'#screenshot', $sortOrder);
            if ($image !== null) {
                return $image;
            }
        }

        return null;
    }

    private function downloadOne(Product $product, string $url, int $sortOrder): ?ProductImage
    {
        // WP: foo-80x80.jpg → spróbuj pełnego foo.jpg
        $url = preg_replace('/-(\d{2,4})x(\d{2,4})(\.(jpe?g|png|webp))$/i', '$3', $url) ?? $url;
        // Demar: foto_2_s.jpg / _m.jpg → foto_2.jpg
        $url = preg_replace('/_([sm])(\.(jpe?g|png|webp))$/i', '$2', $url) ?? $url;
        // PrestaShop: medium/home → large_default
        $url = preg_replace(
            '#/(\d+)-(?:medium_default|home_default|pdt_\d+|small_default)/#i',
            '/$1-large_default/',
            $url
        ) ?? $url;

        $response = Http::timeout(12)
            ->connectTimeout(4)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                // Preferuj JPEG/WebP — część CDN (uvex) i tak zwróci AVIF; obsługujemy też AVIF.
                'Accept' => 'image/jpeg,image/webp,image/png,image/avif,image/*,*/*;q=0.8',
                'Referer' => $this->refererFor($url),
            ])
            ->withOptions(['allow_redirects' => true])
            ->get($url);

        if (! $response->successful()) {
            throw new \RuntimeException('HTTP '.$response->status());
        }

        $bytes = $response->body();
        $size = strlen($bytes);
        if ($bytes === '' || $size > self::MAX_BYTES) {
            throw new \RuntimeException('Pusty lub zbyt duży plik');
        }

        $mime = (string) ($response->header('Content-Type') ?: '');
        $mime = strtolower(trim(explode(';', $mime)[0] ?? ''));
        if (str_contains($mime, 'text/html') || str_contains($mime, 'application/json')) {
            throw new \RuntimeException('Odpowiedź nie jest obrazem ('.$mime.')');
        }
        if ($mime === '' || ! isset(self::ALLOWED_MIME[$mime])) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = strtolower((string) $finfo->buffer($bytes));
        }
        if (! isset(self::ALLOWED_MIME[$mime])) {
            throw new \RuntimeException('Niedozwolony typ MIME: '.$mime);
        }
        // GIF loadery Magento; zdjęcia produktów prawie zawsze JPG/PNG/WebP
        if ($mime === 'image/gif' && $size < 80_000) {
            throw new \RuntimeException('Pominięto mały GIF (loader/spinner)');
        }
        $dim = @getimagesizefromstring($bytes);
        if (is_array($dim)) {
            $w = (int) ($dim[0] ?? 0);
            $h = (int) ($dim[1] ?? 0);
            // miniatury WP (-80x80) i placeholdery — za małe na kartę produktu
            if ($w > 0 && $h > 0 && ($w < 200 || $h < 200)) {
                throw new \RuntimeException("Obrazek za mały ({$w}x{$h}) — miniatura/placeholder");
            }
        }

        return $this->storeBytes($product, $bytes, $mime, $url, $sortOrder);
    }

    private function storeBytes(
        Product $product,
        string $bytes,
        string $mime,
        string $sourceUrl,
        int $sortOrder,
    ): ?ProductImage {
        $mime = strtolower(trim(explode(';', $mime)[0] ?? ''));
        if (! isset(self::ALLOWED_MIME[$mime])) {
            return null;
        }
        $size = strlen($bytes);
        if ($bytes === '' || $size > self::MAX_BYTES) {
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
            'source_url' => mb_substr($sourceUrl, 0, 2000),
            'is_primary' => $sortOrder === 0,
            'sort_order' => $sortOrder,
            'checksum' => $checksum,
        ]);
    }

    private function refererFor(string $url): string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $scheme.'://'.$host.'/' : 'https://www.google.com/';
    }
}
