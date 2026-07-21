<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\Enrichment\ProductSearchIdentity;
use Tests\TestCase;

final class ProductImageRelevanceTest extends TestCase
{
    public function test_rejects_lego_beer_maps(): void
    {
        $identity = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '60550',
            'name' => 'uvex C500',
            'manufacturer' => 'uvex',
        ]);

        $this->assertFalse($identity->isTrustedPageImageUrl(
            'https://www.lego.com/cdn/product-assets/product.img.pri/60448/Web/609b8a.jpg',
            $product
        ));
        $this->assertFalse($identity->imageUrlMentionsProduct(
            'https://shop.example.com/product/c500-lego-set.jpg',
            $product
        ));
        $this->assertFalse($identity->imageUrlMentionsProduct(
            'https://cdn.example.com/fox-deluxe-beer.jpg',
            $product
        ));
        $this->assertFalse($identity->imageUrlMentionsProduct(
            'https://cdn.example.com/world-map-europe.png',
            $product
        ));
    }

    public function test_accepts_uvex_shop_media_and_sku_variant(): void
    {
        $identity = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '60544',
            'name' => 'uvex C300 Foam',
            'manufacturer' => 'uvex',
        ]);

        $this->assertTrue($identity->isTrustedPageImageUrl(
            'https://d3rbxgeqn1ye9j.cloudfront.net/shop-media/abc123/cb:1/media.jpg',
            $product
        ));
        $this->assertTrue($identity->imageUrlMentionsProduct(
            'https://www.uvex-safety.com/en/products/safety-gloves/uvex-c300-foam-6054407.jpg',
            $product
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
