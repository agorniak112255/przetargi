<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\Enrichment\ProductSearchIdentity;
use Tests\TestCase;

final class ProductImageRelevanceTest extends TestCase
{
    public function test_rejects_unrelated_tavily_noise(): void
    {
        $identity = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '60544',
            'name' => 'uvex C300 Foam',
            'manufacturer' => 'uvex',
        ]);

        $this->assertFalse($identity->imageUrlMentionsProduct(
            'https://cdn.example.com/world-map-europe.png',
            $product
        ));
        $this->assertFalse($identity->imageUrlMentionsProduct(
            'https://cdn.example.com/fox-deluxe-beer.jpg',
            $product
        ));
        $this->assertTrue($identity->imageUrlMentionsProduct(
            'https://d3rbxgeqn1ye9j.cloudfront.net/fileadmin/products/uvex-c300-foam-60544.jpg',
            $product
        ));
        // uvex slug z wariantem rozmiaru 6054407
        $this->assertTrue($identity->imageUrlMentionsProduct(
            'https://www.uvex-safety.com/en/products/safety-gloves/uvex-c300-foam-6054407.jpg',
            $product
        ));
        // shop-media = hash bez SKU — galeria karty
        $this->assertTrue($identity->looksLikeProductGalleryUrl(
            'https://d3rbxgeqn1ye9j.cloudfront.net/shop-media/abc123/cb:1/media.jpg'
        ));
        $this->assertFalse($identity->looksLikeProductGalleryUrl(
            'https://d3rbxgeqn1ye9j.cloudfront.net/fileadmin/01_Menue-Pics/map.jpg'
        ));
    }

    public function test_ansell_aliases_match_pim_ashx(): void
    {
        $identity = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '065-06',
            'name' => 'Ringers 065 size 6.0',
            'manufacturer' => 'Ansell',
        ]);

        $url = 'https://www.ansell.com/-/media/projects/ansell/website/pim/product-assets/ringers/r-065/065g_primary.ashx';
        $this->assertTrue(ProductImageDownloader::looksLikeImageUrl($url));
        $this->assertTrue($identity->imageUrlMentionsProduct($url, $product));
        $this->assertContains('r-065', $identity->modelAliases($product));
    }
}
