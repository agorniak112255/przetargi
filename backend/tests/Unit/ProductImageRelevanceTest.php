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

    public function test_accepts_actual_uvex_c500_shop_media_image(): void
    {
        $identity = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '60497',
            'name' => 'C500',
            'manufacturer' => 'uvex',
        ]);
        $url = 'https://d3rbxgeqn1ye9j.cloudfront.net/shop-media/'
            .'QTi5MIdcSTJgAnlbWZyNDblY1H4Du67AsQanM6BtpRc/cb:1784591063/'
            .'bWVkaWEvZWUvODYvNGQvMTc1Njk4MzY4MS9hM2M2YjE1MjE1N2ExNTY1YjVhZjQ3YWE3M2FiODI2MC5qcGc';

        $this->assertTrue(ProductImageDownloader::looksLikeImageUrl($url));
        $this->assertTrue($identity->isTrustedPageImageUrl($url, $product));
    }

    public function test_accepts_cloudflare_images_url_without_extension(): void
    {
        $this->assertTrue(ProductImageDownloader::looksLikeImageUrl(
            'https://imagedelivery.net/ICWTp6FWPGokq8hKKaA1Qg/9b927875-23ca-4e3b-1954-86d58f6d8500/medium'
        ));
        $this->assertFalse(ProductImageDownloader::looksLikeImageUrl(
            'https://imagedelivery.net/ICWTp6FWPGokq8hKKaA1Qg'
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

    public function test_rejects_longer_alphanumeric_sku_variant_nb27b(): void
    {
        $identity = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'NB27',
            'name' => 'RUBIFLEX',
            'manufacturer' => 'uvex',
        ]);

        $this->assertFalse($identity->imageUrlMentionsProduct(
            'https://cdn.example.com/media/catalog/product/n/b/nb27b_rubiflex_s.jpg',
            $product
        ));
        $this->assertFalse($identity->imageUrlMentionsProduct(
            'https://cdn.example.com/media/catalog/product/nb27s_green.jpg',
            $product
        ));
        $this->assertTrue($identity->imageUrlMentionsProduct(
            'https://www.uvex-safety.pl/media/catalog/product/nb27_rubiflex_orange.jpg',
            $product
        ));
        $this->assertTrue($identity->imageUrlMentionsProduct(
            'https://www.uvex-safety.pl/pl/produkty/rekawice-ochronne/rekawica-ochronna-uvex-rubiflex-nb27-6000934.jpg',
            $product
        ));
    }

    public function test_rejects_competing_brand_in_image_filename(): void
    {
        $identity = new ProductSearchIdentity;
        $ansell = new Product([
            'sku' => '54335',
            'name' => 'KG G10 Flex Ntrl Glv Blue XL',
            'manufacturer' => 'Ansell',
        ]);
        $portwestUrl = 'https://www.gloves.co.uk/user/products/PORTWEST-A620-PU-COATED-CUT-LEVEL-B-HEAT-RESISTANT-GREY-GLOVES-ik-4.jpg';

        $this->assertTrue($identity->imageUrlMentionsForeignBrand($portwestUrl, $ansell));
        $this->assertFalse($identity->imageUrlMentionsProduct($portwestUrl, $ansell));
        $this->assertFalse($identity->imageUrlMentionsForeignBrand(
            'https://kleenguard.ansell.com/media/54335-g10-flex-xl.jpg',
            $ansell
        ));

        $portwest = new Product([
            'sku' => 'A620',
            'name' => 'A620 PU Coated',
            'manufacturer' => 'Portwest',
        ]);
        $this->assertFalse($identity->imageUrlMentionsForeignBrand($portwestUrl, $portwest));
    }

    public function test_grzmot_line_does_not_accept_pants_image_for_a_cap(): void
    {
        $identity = new ProductSearchIdentity;
        $cap = new Product([
            'sku' => 'CZAPKA-DASZKIEM-GRZMOT-43',
            'name' => 'Czapka daszkiem GRZMOT',
            'manufacturer' => 'PANTHER',
        ]);
        $catalog = 'https://sklep.example/media/catalog/product/g/r/grzmot';

        $this->assertTrue($identity->imageUrlHasForeignType($catalog.'-spodnie.jpg', $cap));
        $this->assertFalse($identity->imageUrlMentionsProduct($catalog.'-spodnie.jpg', $cap));
        $this->assertFalse($identity->imageUrlMentionsProduct($catalog.'-ogrodniczki.jpg', $cap));
        $this->assertFalse($identity->imageUrlMentionsProduct($catalog.'.jpg', $cap));
        $this->assertTrue($identity->imageUrlMentionsProduct($catalog.'-czapka-daszkiem.jpg', $cap));
        $this->assertTrue($identity->imageUrlMentionsProduct(
            'https://sklep.example/media/catalog/product/c/z/czapka-daszkiem-grzmot-43.jpg',
            $cap
        ));
        $this->assertFalse($identity->imageUrlHasForeignType(
            'https://sklep.example/media/catalog/product/p/a/panther-czapka-grzmot.jpg',
            $cap
        ));
        $this->assertSame('czapka / nakrycie głowy z daszkiem', $identity->requiredArticleTypeLabel($cap));
    }
}
