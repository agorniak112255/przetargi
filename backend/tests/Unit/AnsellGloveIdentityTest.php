<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ProductSearchIdentity;
use Tests\TestCase;

final class AnsellGloveIdentityTest extends TestCase
{
    public function test_reads_glove_model_from_price_list_code(): void
    {
        $identity = app(ProductSearchIdentity::class);

        $this->assertSame('11-919', $identity->ansellGloveModel($this->glove('11919VP100', 'HyFlex 11919VP Size 10,0 VEND')));
        $this->assertSame('38-003', $identity->ansellGloveModel($this->glove('38003PP110', 'AlphaTec 38003PP Size 11.0')));
        $this->assertSame('11-819', $identity->ansellGloveModel($this->glove('11819PRO110', 'HyFlex 11819PRO SIZE 11,0')));
        // kod KleenGuard to numer Kimberly-Clark, nie model Ansell
        $this->assertNull($identity->ansellGloveModel($this->glove('13841', 'KLNGD G40 Gloves PU Black 11')));
    }

    public function test_builds_official_card_url_for_glove(): void
    {
        $urls = app(ProductSearchIdentity::class)->ansellOfficialProductUrls(
            $this->glove('11919VP100', 'HyFlex 11919VP Size 10,0 VEND')
        );

        $this->assertContains('https://www.ansell.com/pl/pl/products/hyflex-11-919', $urls);
    }

    public function test_other_model_of_same_line_is_not_our_card(): void
    {
        $identity = app(ProductSearchIdentity::class);
        $alphatec = $this->glove('38003PP110', 'AlphaTec 38003PP Size 11.0');
        $hyflex = $this->glove('48130VP110', 'HyFlex 48130VP Size 11,0 VEND');

        // hahn-kolb AlphaTec 58-270 i HyFlex 11-842 przechodziły jako nasze 38003PP / 48130VP
        $this->assertFalse($identity->isConfirmedProductCard(
            'https://www.hahn-kolb.net/ANSELL-ALPHATEC-58-270-chemical-protective-gloves-size-6/55525306.sku/en/US/EUR/',
            '',
            '',
            $alphatec
        ));
        $this->assertTrue($identity->pageClaimsAnotherCode(
            'https://www.hahn-kolb.net/ANSELL-HYFLEX-11-842-protective-gloves-for-assembly-work-size-7/55524427.sku/en/US/EUR/',
            '',
            $hyflex
        ));
    }

    public function test_official_card_with_our_model_is_confirmed(): void
    {
        $this->assertTrue(app(ProductSearchIdentity::class)->isConfirmedProductCard(
            'https://www.ansell.com/pl/pl/products/hyflex-11-919',
            'HyFlex® 11-919',
            'Rękawice Ansell HyFlex® 11-919 odporne na przecięcia, powłoka nitrylowa, EN 388.',
            $this->glove('11919VP100', 'HyFlex 11919VP Size 10,0 VEND')
        ));
    }

    public function test_only_ansell_packshots_are_trusted_gallery_images(): void
    {
        $identity = app(ProductSearchIdentity::class);
        $product = $this->glove('13841', 'KLNGD G40 Gloves PU Black 11');

        $this->assertTrue($identity->looksLikeManufacturerGalleryUrl(
            'https://www.ansell.com/-/media/projects/ansell/website/pim/product-assets/hyflex/hyflex-11-919/hyflex-11-919-blue-product-front-image-emea.ashx',
            $product
        ));
        // baner „lifestyle” z tej samej biblioteki mediów nie jest zdjęciem produktu
        $this->assertFalse($identity->looksLikeManufacturerGalleryUrl(
            'https://www.ansell.com/-/media/projects/ansell/website/images/hero/woman-writing-letter.jpg',
            $product
        ));
    }

    private function glove(string $sku, string $name): Product
    {
        return new Product(['sku' => $sku, 'name' => $name, 'manufacturer' => 'Ansell']);
    }
}
