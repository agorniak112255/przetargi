<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Enrichment\ProductEnrichmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Opis nie powstał, zdjęcie bierzemy z karty z kodem wyrobu (KleenGuard A40 #8662, 05.10.2026): kod niosła karta
 * labproinc.com „…-98800”, a zdjęcie przyszło ze wspólnej puli wszystkich pobranych stron — z icd.pl „szybki do
 * przyłbic ESAB Savage A40”.
 */
final class ThinCardImageSourceTest extends TestCase
{
    use RefreshDatabase;

    private const CODED = 'https://labproinc.com/products/klngd-a40-overboot-white-univ-98800';

    private const OWN_IMAGE = 'https://labproinc.com/cdn/shop/files/98800-overboot.jpg';

    private const ESAB_IMAGE = 'https://icd.pl/media/catalog/product/s/z/szybka-wewnetrzna-do-esab-savage-a40-0700000482.jpg';

    public function test_image_comes_only_from_the_card_carrying_the_product_code(): void
    {
        Storage::fake('public');
        Http::fake([
            self::OWN_IMAGE => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
            self::ESAB_IMAGE => Http::response($this->jpeg(), 200, ['Content-Type' => 'image/jpeg']),
            '*' => Http::response('', 404),
        ]);
        $product = Product::query()->create([
            'sku' => '98800', 'name' => 'KLNGD A40 Overboot White Univ', 'manufacturer' => 'Ansell',
            'catalog_price_net' => 10, 'purchase_price' => 8, 'stock' => 1,
        ]);
        $fetched = [
            // pula wszystkich pobranych stron — szybka ESAB pierwsza
            'image_urls' => [self::ESAB_IMAGE, self::OWN_IMAGE],
            'trusted_image_urls' => [self::ESAB_IMAGE],
        ];
        $pages = [
            ['url' => self::CODED, 'title' => 'KLNGD A40 Overboot White Univ', 'text' => 'Overboot', 'image_urls' => [self::OWN_IMAGE], 'trusted_image_urls' => [self::OWN_IMAGE]],
            ['url' => 'https://icd.pl/szybki-do-przylbic-esab-savage-a40-a50-lux-aristo-tech-hd-warrior-tech.html', 'title' => 'Szybki ESAB Savage A40', 'text' => 'Szybka', 'image_urls' => [self::ESAB_IMAGE], 'trusted_image_urls' => [self::ESAB_IMAGE]],
        ];

        $download = new ReflectionMethod(ProductEnrichmentService::class, 'downloadImagesFromFetchedCards');
        $saved = $download->invoke(app(ProductEnrichmentService::class), $product, $fetched, $pages);

        $this->assertCount(1, $saved);
        $this->assertSame(self::OWN_IMAGE, $saved[0]->source_url);
    }

    private function jpeg(): string
    {
        $im = imagecreatetruecolor(320, 480);
        imagefill($im, 0, 0, imagecolorallocate($im, 200, 40, 40));
        ob_start();
        imagejpeg($im, null, 85);
        imagedestroy($im);

        return (string) ob_get_clean();
    }
}
