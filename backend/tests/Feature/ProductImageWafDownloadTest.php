<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Enrichment\ProductImageDownloader;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

        Http::fake(function (\Illuminate\Http\Client\Request $request) use ($imageUrl, $shot) {
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
