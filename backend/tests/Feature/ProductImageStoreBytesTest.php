<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Enrichment\ProductImageDownloader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Zapis gotowych bajtów z łącznika B2B (storeBytes) omija bramkę pobierania z sieci, więc strona HTML pod nagłówkiem
 * obrazu trafiała na kartę jako .jpg (3M, audyt 08.10.2026: „404 Resource not found” z multimedia.3m.com na 46491,
 * 46551, 40641). Znaczniki strony, XML i SVG odpadają; każde inne bajty z nagłówkiem obrazu zapisują się jak dotąd.
 */
final class ProductImageStoreBytesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_page_xml_or_svg_under_an_image_header_is_not_stored(): void
    {
        $product = Product::query()->create(['sku' => 'S-1', 'name' => 'Półmaska', 'manufacturer' => '3M', 'price' => 10]);
        $downloader = new ProductImageDownloader;

        foreach ([
            "<!DOCTYPE HTML PUBLIC \"-//IETF//DTD HTML 2.0//EN\">\n<html><body>404 Resource not found</body></html>",
            "\xEF\xBB\xBF  <html><head></head></html>",
            '<?xml version="1.0"?><error/>',
            '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
        ] as $i => $bytes) {
            $this->assertNull($downloader->storeBytes($product, $bytes, 'image/jpeg', 'https://multimedia.3m.com/mws/media/'.$i.'Z/x.jpg', $i));
        }
        $this->assertSame(0, ProductImage::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());

        // bajty bez znaczników z nagłówkiem obrazu — jak dotąd (łączniki i ich testy podają tu różne bajty)
        $this->assertNotNull($downloader->storeBytes($product, 'jpeg-bytes', 'image/jpeg', 'https://multimedia.3m.com/mws/media/9Z/x.jpg', 0));
    }
}
