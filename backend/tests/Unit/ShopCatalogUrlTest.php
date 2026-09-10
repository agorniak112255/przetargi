<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Enrichment\ShopCatalogUrl;
use Tests\TestCase;

final class ShopCatalogUrlTest extends TestCase
{
    private ShopCatalogUrl $urls;

    protected function setUp(): void
    {
        parent::setUp();
        $this->urls = new ShopCatalogUrl;
    }

    public function test_iai_only_p_id_html_is_a_card(): void
    {
        $this->assertTrue($this->urls->isProductCard(
            'https://www.gvarant.pl/p28932,polbuty-lemaitre-ales-s3.html'
        ));
        $this->assertFalse($this->urls->isPrettyProduct('https://www.gvarant.pl/polbuty-cadiz-s1ps-fo-sr'));
        $this->assertTrue($this->urls->isIndexListing('https://www.robocze-buty.pl/drewniaki-klapki,44-/'));
        $this->assertTrue($this->urls->isIndexListing('https://www.robocze-buty.pl/puma-safety/'));
    }

    public function test_presta_id_and_html_are_cards(): void
    {
        $this->assertTrue($this->urls->isClassicProduct(
            'https://www.misterworker.com/en/index.php?controller=product&id_product=74275'
        ));
        $this->assertTrue($this->urls->isClassicProduct('https://shop.pl/123-buty-robocze-s3.html'));
        $this->assertTrue($this->urls->isClassicProduct(
            'https://dodatkimasarskiezwm.pl/111237-kalosz-damskimeski-pl'
        ));
        $this->assertFalse($this->urls->isIndexListing(
            'https://dodatkimasarskiezwm.pl/111237-kalosz-damskimeski-pl'
        ));
        $this->assertFalse($this->urls->isPrettyProduct('https://shop.pl/12-buty-robocze'));
        $this->assertTrue($this->urls->isIndexListing('https://shop.pl/12-buty-robocze'));
    }

    public function test_shoper_woo_and_shopify_product_paths(): void
    {
        $this->assertTrue($this->urls->isClassicProduct('https://atlas.pl/produkt/rekawice-nitrilowe'));
        $this->assertTrue($this->urls->isClassicProduct('https://shop.pl/product/softshell-jacket'));
        $this->assertTrue($this->urls->isClassicProduct('https://shop.pl/p/55421'));
        $this->assertTrue($this->urls->isClassicProduct('https://store.myshopify.com/products/fuelcell-rebel'));
        $this->assertTrue($this->urls->isIndexListing('https://store.myshopify.com/collections/running'));
        $this->assertTrue($this->urls->isIndexListing('https://shop.pl/kategoria/buty-robocze'));
        $this->assertTrue($this->urls->isIndexListing('https://shop.pl/c/buty-trekkingowe'));
    }

    public function test_magento_pretty_card_vs_category_and_facet(): void
    {
        $card = 'https://www.deporvillage.pl/buty-do-biegania-new-balance-fuelcell-rebel-v5-zielony';
        $this->assertTrue($this->urls->isPrettyProduct($card));
        $this->assertFalse($this->urls->isIndexListing($card));

        $this->assertFalse($this->urls->isPrettyProduct('https://www.deporvillage.pl/namioty-kemping'));
        $this->assertTrue($this->urls->isIndexListing('https://www.deporvillage.pl/namioty-kemping'));
        $this->assertTrue($this->urls->isIndexListing('https://www.deporvillage.pl/on-running'));
        $this->assertTrue($this->urls->isIndexListing('https://www.deporvillage.pl/sea-to-summit'));
        $this->assertTrue($this->urls->isIndexListing(
            'https://www.deporvillage.pl/namioty-kemping:dla_1_osoby'
        ));
        $this->assertFalse($this->urls->isPrettyProduct(
            'https://www.deporvillage.pl/namioty-kemping:dla_1_osoby'
        ));
    }

    public function test_nested_magento_short_slug_stays_in_sitemap(): void
    {
        $this->assertFalse($this->urls->isIndexListing('https://ox-on.com/gloves/cut-c'));
        $this->assertFalse($this->urls->isPrettyProduct('https://ox-on.com/gloves/cut-c'));
    }

    public function test_soteshop_id_slug_html_is_a_card(): void
    {
        $card = 'https://www.fasterbhp.pl/351,bluza-robocza-brixton-spark-grafitowy-polstar.html';
        $this->assertTrue($this->urls->isClassicProduct($card));
        $this->assertTrue($this->urls->isProductCard($card));
        $this->assertFalse($this->urls->isIndexListing($card));
        $this->assertFalse($this->urls->isFacetListing($card));

        $this->assertTrue($this->urls->isClassicProduct('https://www.fasterbhp.pl/?351,bluza-robocza-brixton'));
        $this->assertTrue($this->urls->isSoteShopCategory('https://www.fasterbhp.pl/odziez-robocza,166.html'));
        $this->assertFalse($this->urls->isSoteShopCategory('https://www.fasterbhp.pl/odziez-robocza,166,1634,2.html'));
        $this->assertTrue($this->urls->isIndexListing('https://www.fasterbhp.pl/odziez-robocza,166.html'));
        $this->assertTrue($this->urls->isIndexListing('https://www.fasterbhp.pl/odziez-robocza,166,1634,2.html'));
        $this->assertTrue($this->urls->isIndexListing('https://www.fasterbhp.pl/?o-sklepie,11'));
        $this->assertFalse($this->urls->isClassicProduct('https://www.fasterbhp.pl/odziez-robocza,166.html'));
        $this->assertFalse($this->urls->isFacetListing('https://www.fasterbhp.pl/odziez-robocza,166.html'));
        $this->assertTrue($this->urls->isIndexListing('https://www.robocze-buty.pl/drewniaki-klapki,44-/'));
        $this->assertTrue($this->urls->isClassicProduct(
            'https://www.gvarant.pl/p28932,polbuty-lemaitre-ales-s3.html'
        ));
    }
}
