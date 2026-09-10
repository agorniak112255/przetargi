<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ProductImageThumbTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_thumb_trims_white_and_is_public(): void
    {
        $this->requireGd();
        $image = $this->storePaddedImage();

        $res = $this->get('/api/product-images/'.$image->id.'/thumb');
        $res->assertOk();
        $this->assertSame('image/jpeg', $res->headers->get('Content-Type'));

        $out = imagecreatefromstring($res->getContent());
        $this->assertNotFalse($out);
        $this->assertLessThanOrEqual(160, max(imagesx($out), imagesy($out)));
        $this->assertGreaterThan(0.55, $this->nonWhiteRatio($out));
        imagedestroy($out);
        Storage::disk('local')->assertExists('product-thumbs/'.$image->id.'-'.$image->checksum.'.jpg');
    }

    public function test_product_list_exposes_thumb_url(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $image = $this->storePaddedImage();

        $this->getJson('/api/products')
            ->assertOk()
            ->assertJsonPath('data.0.images.0.thumb_url', route('product-images.thumb', $image));
    }

    public function test_product_show_exposes_thumb_url(): void
    {
        Sanctum::actingAs(User::factory()->withRole('admin')->create());
        $image = $this->storePaddedImage();

        $this->getJson('/api/products/'.$image->product_id)
            ->assertOk()
            ->assertJsonPath('images.0.thumb_url', route('product-images.thumb', $image));
    }

    public function test_url_falls_back_to_source_when_local_file_missing(): void
    {
        $product = Product::query()->create([
            'sku' => 'MISS-1',
            'name' => 'Bez pliku',
            'manufacturer' => 'Lemaitre',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
        ]);
        $image = ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => 'products/'.$product->id.'/gone.jpg',
            'source_url' => 'https://example.com/gone.jpg',
            'is_primary' => true,
            'sort_order' => 0,
        ]);

        $this->assertSame('https://example.com/gone.jpg', $image->url());
    }

    private function storePaddedImage(): ProductImage
    {
        $this->requireGd();
        $im = imagecreatetruecolor(200, 200);
        $this->assertNotFalse($im);
        $white = imagecolorallocate($im, 255, 255, 255);
        $black = imagecolorallocate($im, 20, 20, 20);
        imagefill($im, 0, 0, $white);
        imagefilledrectangle($im, 90, 70, 130, 140, $black);
        ob_start();
        imagepng($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        $product = Product::query()->create([
            'sku' => 'THUMB-1',
            'name' => 'But testowy',
            'manufacturer' => 'Lemaitre',
            'catalog_price_net' => 10,
            'purchase_price' => 5,
            'stock' => 1,
        ]);
        $path = 'products/'.$product->id.'/padded.png';
        Storage::disk('public')->put($path, $bytes);

        return ProductImage::query()->create([
            'product_id' => $product->id,
            'path' => $path,
            'is_primary' => true,
            'sort_order' => 0,
            'checksum' => hash('sha256', $bytes),
        ]);
    }

    private function requireGd(): void
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagecreatefromstring')) {
            $this->markTestSkipped('GD jest wymagane');
        }
    }

    private function nonWhiteRatio(\GdImage $im): float
    {
        $width = imagesx($im);
        $height = imagesy($im);
        $content = 0;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($im, $x, $y);
                $r = ($rgba >> 16) & 0xFF;
                $g = ($rgba >> 8) & 0xFF;
                $b = $rgba & 0xFF;
                if (min($r, $g, $b) < 240) {
                    $content++;
                }
            }
        }

        return $content / ($width * $height);
    }
}
