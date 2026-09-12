<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Enrichment\ProductImageDownloader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ProductImageWafDownloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_downloads_bpbhp_packshot_via_reader_when_shop_returns_403(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD jest wymagane');
        }

        Storage::fake('public');
        $shot = $this->packshotJpeg();
        $pageUrl = 'https://bpbhp.pl/rekawice-jednorazowe-mapa-solo-987';
        $imageUrl = 'https://bpbhp.pl/media/catalog/product/s/o/solo_987_1.jpg';

        Http::fake(function (Request $request) use ($imageUrl, $shot) {
            $url = $request->url();
            if (str_contains($url, 'r.jina.ai')) {
                return Http::response($shot, 200, ['Content-Type' => 'image/jpeg']);
            }
            if ($url === $imageUrl) {
                return Http::response('<html>403 Forbidden</html>', 403, ['Content-Type' => 'text/html']);
            }

            return Http::response('unexpected '.$url, 404);
        });

        $product = Product::query()->create([
            'sku' => '349870338',
            'name' => 'SOLO 987',
            'manufacturer' => 'MAPA',
            'catalog_price_net' => 5,
            'purchase_price' => 5,
            'stock' => 1,
            'shop_source_url' => $pageUrl,
        ]);

        $saved = (new ProductImageDownloader)->downloadMany($product, [$imageUrl], 1);

        $this->assertCount(1, $saved);
        $this->assertSame($imageUrl, $saved[0]->source_url);
        $this->assertTrue(Storage::disk('public')->exists((string) $saved[0]->path));
    }

    public function test_narrow_packshot_is_kept_and_thumbnail_is_rejected(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD jest wymagane');
        }

        Storage::fake('public');
        // cas-technik: oryginał packshotu kombinezonu ma 190x417 — węższy niż 200 px,
        // ale to pełne zdjęcie; miniatura 150x150 i pasek 1200x60 nadal odpadają
        $narrow = 'https://cas-technik.eu/media/ec/f6/7b/1689001579/gr40-t-00-125.jpg?ts=1720625568';
        $thumb = 'https://cas-technik.eu/media/aa/bb/cc/1689001579/thumb.jpg';
        $strip = 'https://cas-technik.eu/media/dd/ee/ff/1689001579/strip.jpg';
        Http::fake([
            $narrow => Http::response($this->jpeg(190, 417), 200, ['Content-Type' => 'image/jpeg']),
            $thumb => Http::response($this->jpeg(150, 150), 200, ['Content-Type' => 'image/jpeg']),
            $strip => Http::response($this->jpeg(1200, 60), 200, ['Content-Type' => 'image/jpeg']),
            '*' => Http::response('nope', 404),
        ]);
        $product = Product::query()->create([
            'sku' => 'GR40T-00125-09',
            'name' => '4000-GR CVRL HOOD SOCKS 125-G02.5XL',
            'manufacturer' => 'Ansell',
            'catalog_price_net' => 5,
            'purchase_price' => 5,
            'stock' => 1,
        ]);

        $downloader = new ProductImageDownloader;
        $saved = $downloader->downloadMany($product, [$thumb, $strip, $narrow], 1);

        $this->assertCount(1, $saved, json_encode($downloader->lastFailures(), JSON_UNESCAPED_UNICODE));
        $this->assertSame($narrow, $saved[0]->source_url);
        $failures = $downloader->lastFailures();
        $this->assertStringContainsString('za mały (150x150)', $failures[$thumb] ?? '');
        $this->assertStringContainsString('za mały (1200x60)', $failures[$strip] ?? '');
    }

    private function jpeg(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 60));
        ob_start();
        imagejpeg($im, null, 85);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    private function packshotJpeg(): string
    {
        $im = imagecreatetruecolor(640, 640);
        $this->assertNotFalse($im);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        $ink = imagecolorallocate($im, 30, 30, 30);
        imagefilledellipse($im, 320, 320, 220, 340, $ink);
        for ($y = 0; $y < 640; $y += 4) {
            imageline($im, 0, $y, 639, $y, imagecolorallocate($im, $y % 200, 40, 80));
        }
        ob_start();
        imagejpeg($im, null, 95);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);
        $this->assertGreaterThanOrEqual(8000, strlen($bytes));

        return $bytes;
    }
}
