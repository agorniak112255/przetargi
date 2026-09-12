<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\CatalogIndexSearch;
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
        $this->assertContains('https://www.ansell.com/us/en/products/hyflex-11-919', $urls);
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

    public function test_size_and_pack_words_do_not_identify_product(): void
    {
        $identity = app(ProductSearchIdentity::class);
        $product = $this->glove('11618110', 'HyFlex 11618 Size 11,0');

        $this->assertSame(['hyflex'], $identity->nameWords($product));
        $this->assertSame(['alphatec'], $identity->nameWords($this->glove('87320100-PAIR', 'AlphaTec 87320 Pair Pack Size 10.0')));
        // marka + „size” w adresie przepuszczały dowolną rękawicę Ansell jako HyFlex 11618
        $url = 'https://www.example-shop.com/ansell-cut-resistant-gloves-size-8';
        $this->assertFalse($identity->pageAgreesWithBrandAndName($url, $url, $product));
    }

    public function test_reads_model_of_a_line_missing_from_the_map(): void
    {
        // Model wychodził tylko dla ośmiu znanych linii, więc EDGE, DERMASHIELD, FiberTuf
        // i AccuTech szły w zapytanie sklejone („EDGE 48128”) i nie trafiały w kartę,
        // choć karta była w indeksie. Potwierdzeniem jest teraz sam kod cennika.
        $identity = app(ProductSearchIdentity::class);

        $this->assertSame('48-128', $identity->ansellGloveModel($this->glove('48128110', 'EDGE 48128 Size 11.0')));
        $this->assertSame('73-721', $identity->ansellGloveModel($this->glove('73721090', 'DERMASHIELD 73721 SIZE 9,0')));
        $this->assertSame('76-501', $identity->ansellGloveModel($this->glove('76501100', 'FiberTuf 76501 Size 10,0')));
        $this->assertSame('91-225', $identity->ansellGloveModel($this->glove('91225090', 'AccuTech 91225 Size 9.0')));
        // cennik gubi wiodące zero w SKU
        $this->assertSame('09-430', $identity->ansellGloveModel($this->glove('9430100', 'AlphaTec 09430 Size 10,0')));
    }

    public function test_size_stripped_sku_is_not_the_alphatec_09_430_card(): void
    {
        $identity = app(ProductSearchIdentity::class);
        $product = $this->glove('9430100', 'AlphaTec 09430 Size 10,0');
        $peli = 'https://strefa998.pl/system-oswietleniowy-peli/798-peli-model-9430-rals-zolte.html';
        $card = 'https://www.ansell.com/pl/pl/products/alphatec-09-430';
        $shop = 'https://bhp-sklep.com.pl/produkt/ansell-09-430-scorpio-rekawice/';

        $this->assertSame('9430', $identity->catalogSkuWithoutSize($product));
        $this->assertTrue($identity->isAnsellGloveWarehouseRemnant('9430', $product));
        $this->assertNotContains('9430', $identity->productCodes($product));
        $this->assertNotContains('9430', app(CatalogIndexSearch::class)->codes($product));
        $this->assertContains('09-430', $identity->productCodes($product));
        $this->assertContains(
            'https://www.ansell.com/pl/pl/products/alphatec-09-430',
            $identity->ansellOfficialProductUrls($product)
        );
        $this->assertFalse($identity->hayHasProductCode($peli, $product));
        $this->assertFalse($identity->urlOrTitleCarriesCodeFamily($peli, 'Peli 9430 RALS', $product));
        $this->assertTrue($identity->hayMentionsProduct($card.' AlphaTec 09-430', $product));
        $this->assertTrue($identity->hayHasProductCode($shop, $product));
        $this->assertTrue($identity->hayMentionsProduct($shop, $product));
    }

    public function test_number_in_the_name_alone_is_not_a_model(): void
    {
        $identity = app(ProductSearchIdentity::class);

        // kod z nazwy musi być początkiem SKU — inaczej to przypadkowa liczba
        $this->assertNull($identity->ansellGloveModel($this->glove('99999999', 'EDGE 48128 Size 11.0')));
        // cudza marka nie dostaje modelu w konwencji Ansella
        $this->assertNull($identity->ansellGloveModel(new Product([
            'sku' => '48128110',
            'name' => 'EDGE 48128 Size 11.0',
            'manufacturer' => 'uvex',
        ])));
    }

    public function test_builds_official_card_url_for_a_line_missing_from_the_map(): void
    {
        $urls = app(ProductSearchIdentity::class)->ansellOfficialProductUrls(
            $this->glove('48128110', 'EDGE 48128 Size 11.0')
        );

        $this->assertContains('https://www.ansell.com/pl/pl/products/edge-48-128', $urls);
    }

    public function test_glued_price_list_code_is_queried_as_hyphenated_model(): void
    {
        $identity = app(ProductSearchIdentity::class);
        $hyflex = $this->glove('11580120', 'HyFlex 11580 size 12.0');
        $edge = $this->glove('48160100', 'EDGE 48160 size 10.0');

        $this->assertSame('11-580', $identity->ansellGloveModel($hyflex));
        $this->assertSame('HyFlex 11-580', $identity->firstStrongShopPhrase($hyflex));
        $this->assertStringContainsString('11-580', implode(' ', $identity->searchQueries($hyflex, 'manufacturer')));
        $this->assertStringNotContainsString('HyFlex 11580 Ansell', $identity->firstStrongShopPhrase($hyflex));

        $this->assertSame('48-160', $identity->ansellGloveModel($edge));
        $this->assertSame('EDGE 48-160', $identity->firstStrongShopPhrase($edge));
    }

    public function test_alphatec_hyphen_series_builds_coverall_url_not_glove_slug(): void
    {
        $identity = app(ProductSearchIdentity::class);
        $suit = $this->glove('210000047', 'AlphaTec 66-300 model 111-G09, 3XL');

        $this->assertSame('66-300', $identity->ansellCatalogBits($suit)['series']);
        $this->assertSame('111', $identity->ansellCatalogBits($suit)['model']);
        $this->assertContains(
            'https://www.ansell.com/pl/pl/products/alphatec-66-300-ultrasonically-welded-taped-model-111',
            $identity->ansellOfficialProductUrls($suit)
        );
        $this->assertStringContainsString('66-300', $identity->firstStrongShopPhrase($suit));
        $this->assertNotContains('111', $identity->ansellStyleCodes($suit));
    }

    public function test_klngd_price_name_is_searched_as_kleenguard_model(): void
    {
        $identity = app(ProductSearchIdentity::class);
        $g80 = $this->glove('25625', 'KLNGD G80 Gloves Nitrile Gauntlet 11');
        $flex = $this->glove('54335', 'KG G10 Flex Ntrl Glv Blue XL');
        $comfort = $this->glove('54189', 'KG G10 Comfort Plus Ntrl Glv Lt Blue XL');
        $pro = $this->glove('54424', 'KG G10 2Pro Ntrl Glv Blue XL');

        $this->assertSame('KleenGuard G80', $identity->firstStrongShopPhrase($g80));
        $this->assertSame('KleenGuard G10 Flex', $identity->firstStrongShopPhrase($flex));
        $this->assertSame('KleenGuard G10 Comfort Plus', $identity->firstStrongShopPhrase($comfort));
        $this->assertSame('KleenGuard G10 2Pro', $identity->firstStrongShopPhrase($pro));
    }

    public function test_g10_comfort_plus_rejects_flex_card(): void
    {
        $identity = app(ProductSearchIdentity::class);
        $comfort = $this->glove('54189', 'KG G10 Comfort Plus Ntrl Glv Lt Blue XL');
        $flexUrl = 'https://www.ansell.com/pl/pl/products/kleenguard-g10-flex-blue-nitrile-gloves';
        $flexTitle = 'KleenGuard G10 Flex Blue Nitrile Gloves';
        $flexText = 'KleenGuard PPE protective clothing, hand, eye and face protection. '
            .'V30 Nemesis with Cord Connect. Frequently Asked Questions.';

        $this->assertTrue($identity->pageClaimsAnotherCode($flexUrl, $flexTitle, $comfort));
        $this->assertFalse($identity->hayMentionsProduct($flexUrl.' '.$flexTitle.' '.$flexText, $comfort));
        $this->assertFalse($identity->isConfirmedProductCard($flexUrl, $flexTitle, $flexText, $comfort));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://www.ansell.com/us/en/products/kleenguard-g10-comfort-plus-nitrile-gloves '
            .'KleenGuard G10 Comfort Plus Light Blue Nitrile Gloves 54189 XL',
            $comfort
        ));
        $this->assertSame(
            ['https://labproinc.com/products/kg-g10-comfort-plus-ntrl-glv-lt-blue-xl-54189'],
            $identity->kleenGuardCatalogCardUrls($comfort)
        );
        $this->assertSame(
            ['https://labproinc.com/products/kg-g10-flex-ntrl-glv-blue-xl-54335'],
            $identity->kleenGuardCatalogCardUrls($this->glove('54335', 'KG G10 Flex Ntrl Glv Blue XL'))
        );
    }

    private function glove(string $sku, string $name): Product
    {
        return new Product(['sku' => $sku, 'name' => $name, 'manufacturer' => 'Ansell']);
    }
}
