<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ProductEnrichmentService;
use App\Services\Enrichment\ProductImageCandidateVerifier;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\Enrichment\ProductPageFetcher;
use App\Services\Enrichment\ProductSearchIdentity;
use ReflectionMethod;
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

    public function test_accepts_3m_multimedia_cdn_without_sku_in_filename(): void
    {
        $identity = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '16743-KT',
            'name' => 'Kubek wewnętrzny rPPS 3M™ PPS™, 650 ml, 200 µm, 16743',
            'manufacturer' => '3M',
        ]);
        $url = 'https://multimedia.3m.com/mws/media/1421372J/3m-pps-kit.jpg';

        $this->assertTrue(ProductImageDownloader::looksLikeImageUrl($url));
        $this->assertTrue($identity->looksLikeManufacturerGalleryUrl($url, $product));
        $this->assertTrue($identity->isTrustedPageImageUrl($url, $product));
        $this->assertFalse($identity->imageUrlMentionsProduct($url, $product));
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

    public function test_accepts_shoper_gallery_without_sku_in_filename(): void
    {
        $identity = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'CH-20KV',
            'name' => 'Chodnik elektroizolacyjny 20 KV Secura',
            'manufacturer' => 'SECURA',
        ]);
        $original = 'https://centrumelektronarzedzi.pl/userdata/public/gfx/46764/Chodnik-i-dywanik-elektroizolacyjny.jpg';
        $full = 'https://centrumelektronarzedzi.pl/environment/cache/images/productGfx_46764_0_0/Chodnik-i-dywanik-elektroizolacyjny.webp';
        $thumb = 'https://centrumelektronarzedzi.pl/environment/cache/images/productGfx_46764_120_120/Chodnik-i-dywanik-elektroizolacyjny.webp';
        $seo = 'https://centrumelektronarzedzi.pl/upload/img/seo/centrumelektronarzedzi-pl.png';

        $this->assertTrue($identity->looksLikeManufacturerGalleryUrl($original, $product));
        $this->assertTrue($identity->looksLikeManufacturerGalleryUrl($full, $product));
        $this->assertTrue($identity->isTrustedPageImageUrl($original, $product));
        $this->assertFalse($identity->looksLikeManufacturerGalleryUrl($thumb, $product));
        $this->assertFalse(ProductImageDownloader::isSmallShoperCacheUrl($full));
        $this->assertTrue(ProductImageDownloader::isSmallShoperCacheUrl($thumb));
        $this->assertSame($full, ProductImageDownloader::preferFullSizeUrl(
            'https://centrumelektronarzedzi.pl/environment/cache/images/productGfx_46764_750_750/Chodnik-i-dywanik-elektroizolacyjny.webp'
        ));
        $this->assertSame($original, ProductImageDownloader::shoperOriginalUrl($full));
        $this->assertFalse($identity->looksLikeManufacturerGalleryUrl($seo, $product));
    }

    public function test_strips_magento_cache_hash_to_original_catalog_file(): void
    {
        $cached = 'https://icd.pl/media/catalog/product/cache/619fea8990fc50f1f0f0c116cd818ee3/f/a/fartuch-pros-wodoochronny-121-1.jpg';
        $this->assertSame(
            'https://icd.pl/media/catalog/product/f/a/fartuch-pros-wodoochronny-121-1.jpg',
            ProductImageDownloader::preferFullSizeUrl($cached)
        );
    }

    public function test_redcart_cdn_is_not_junk_and_trusted_from_card(): void
    {
        $img = 'https://static3.redcart.pl/templates/images/thumb/4697/1024/1024/pl/0/templates/images/products/4697/9f854e302fba0cd0f0dafae92d468669.jpg';
        $junk = new ReflectionMethod(ProductPageFetcher::class, 'isJunkImageUrl');
        $this->assertFalse($junk->invoke(app(ProductPageFetcher::class), $img));
        $this->assertTrue($junk->invoke(
            app(ProductPageFetcher::class),
            'https://shop.pl/cart/icon.png'
        ));

        $product = new Product([
            'sku' => '905',
            'name' => 'PELERYNA MĘSKA/DAMSKA',
            'manufacturer' => 'AJ GROUP',
        ]);
        $picked = app(ProductImageCandidateVerifier::class)->select(
            $product,
            [$img],
            [[
                'url' => 'https://dodatkimasarskiezwm.pl/111233-peleryna-meska-wodoochronna-pros-model-905m-pl',
                'text' => 'Peleryna 905M',
            ]],
            1,
            [$img]
        );
        $this->assertSame([$img], $picked);
    }

    public function test_rejects_marketing_assets_from_a_manufacturer_media_server(): void
    {
        // Baner branżowy i przewodnik po asortymencie ładują się jako poprawne PNG/JPG
        // o właściwych wymiarach, więc żaden późniejszy próg ich nie zatrzymywał —
        // a stały w kolejce przed packshotem.
        $junk = new ReflectionMethod(ProductEnrichmentService::class, 'isJunkImageUrl');
        $service = app(ProductEnrichmentService::class);

        $this->assertTrue($junk->invoke(
            $service,
            'https://multimedia.3m.com/mws/media/1812021O/industry-feature-image.png'
        ));
        $this->assertTrue($junk->invoke(
            $service,
            'https://multimedia.3m.com/mws/media/2552127J/e4e-engineered-tapes-reference-guide-end-customer-polish.jpg'
        ));
        $this->assertFalse($junk->invoke(
            $service,
            'https://multimedia.3m.com/mws/media/51586J/3m-tm-470-electroplating-anaod.jpg'
        ));
        $this->assertFalse($junk->invoke(
            $service,
            'https://icd.pl/media/catalog/product/r/e/rekawice-ansell-hyflex-11-618.jpg'
        ));
    }

    public function test_rejects_image_of_another_colour_variant_of_the_same_model(): void
    {
        // ARTRA koduje kolor liczbą obok modelu: 6060 = czarny, 1010 = biały.
        // Na kartach lądowały packshoty innego koloru, bo adres z og:image omijał model.
        $identity = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'ARAGON-920-6060-S2',
            'name' => 'Półbuty ARAGON 920 6060 S2 SRC',
            'manufacturer' => 'ARTRA',
        ]);

        $this->assertSame(['6060'], $identity->productVariantCodes($product));

        $own = 'https://artra.pl/media/ARAGON_920_6060_S2.png';
        $foreign = 'https://artra.pl/media/ARAGON_920_1010_S2.png';

        $this->assertFalse($identity->imageUrlHasForeignVariantCode($own, $product));
        $this->assertTrue($identity->imageUrlConfirmsVariantCode($own, $product));
        $this->assertTrue($identity->imageUrlHasForeignVariantCode($foreign, $product));
        $this->assertFalse($identity->imageUrlConfirmsVariantCode($foreign, $product));

        // Plik nazywający inny model (ARAL 927) nie jest tą regułą oceniany — porównujemy
        // warianty TEGO modelu. Kodu wariantu nie potwierdza, więc zaufany kandydat
        // i tak nie omija modelu wizyjnego.
        $this->assertFalse($identity->imageUrlHasForeignVariantCode(
            'https://artra.pl/media/ARAL_927_4260_S3.jpg',
            $product
        ));
        $this->assertFalse($identity->imageUrlConfirmsVariantCode(
            'https://artra.pl/media/ARAL_927_4260_S3.jpg',
            $product
        ));
    }

    public function test_variant_code_rule_ignores_dimensions_resolutions_and_url_parameters(): void
    {
        $identity = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'ARAGON-920-6060-S2',
            'name' => 'Półbuty ARAGON 920 6060 S2 SRC',
            'manufacturer' => 'ARTRA',
        ]);

        foreach ([
            'https://cdn.example.com/750x750/aragon-920-6060-s2.jpg?v=1764059522',
            'https://cdn.example.com/img/aragon-920-6060-s2-750x750.jpg',
            'https://cdn.example.com/img/aragon-920-6060-s2-1200.jpg',
            // sama szerokość miniatury bez kodu koloru nie jest kodem wariantu
            'https://cdn.example.com/img/aragon-920-s2-1200.jpg',
            // rok wydania normy w nazwie pliku
            'https://cdn.example.com/img/aragon-920-s2-en-iso-20345-2022.jpg',
            // identyfikator CDN w katalogu, nie w nazwie pliku
            'https://cdn.example.com/1010/aragon-920-6060-s2.jpg',
            // nazwa pliku nie mówi nic o modelu — reguła nie ma czego porównać
            'https://cdn.example.com/750x750/foto.jpg?v=1764059522',
        ] as $url) {
            $this->assertFalse(
                $identity->imageUrlHasForeignVariantCode($url, $product),
                $url
            );
        }

        // sam numer modelu kodem wariantu nie jest — inaczej każdy packshot Ansella
        // z identyfikatorem CDN w nazwie pliku byłby „cudzym wariantem”
        $this->assertSame([], $identity->productVariantCodes(new Product([
            'sku' => 'WH25T-00122-04',
            'name' => 'AlphaTec 2500 Plus',
            'manufacturer' => 'Ansell',
        ])));

        // wyrób bez kodu wariantu zachowuje się jak dotąd
        $noCode = new Product([
            'sku' => 'KMR-46',
            'name' => 'Trzewiki KMR S3',
            'manufacturer' => 'ARTRA',
        ]);
        $this->assertSame([], $identity->productVariantCodes($noCode));
        $this->assertFalse($identity->imageUrlHasForeignVariantCode(
            'https://artra.pl/media/kmr-1010-s3.jpg',
            $noCode
        ));
    }

    public function test_trusted_image_of_a_foreign_variant_never_reaches_the_card(): void
    {
        $product = new Product([
            'sku' => 'ARAGON-920-6060-S2',
            'name' => 'Półbuty ARAGON 920 6060 S2 SRC',
            'manufacturer' => 'ARTRA',
        ]);
        $foreign = 'https://artra.pl/media/ARAGON_920_1010_S2.png';

        $picked = app(ProductImageCandidateVerifier::class)->select(
            $product,
            [$foreign],
            [['url' => 'https://artra.pl/produkt/aragon-920-6060-s2', 'text' => 'ARAGON 920 6060 S2']],
            1,
            [$foreign]
        );

        $this->assertSame([], $picked);
    }

    public function test_expected_colour_comes_only_from_plain_words_on_the_card(): void
    {
        $identity = new ProductSearchIdentity;

        $this->assertSame('black', $identity->colorFamily('czarne'));
        $this->assertSame('black', $identity->colorFamily('BLACK'));
        $this->assertNull($identity->colorFamily('6060'));

        // kod liczbowy koloru nie mówi nam, jaki to kolor
        $this->assertNull($identity->expectedColorFamily(new Product([
            'sku' => 'ARAGON-920-6060-S2',
            'name' => 'Półbuty ARAGON 920 6060 S2 SRC',
            'manufacturer' => 'ARTRA',
        ])));

        $this->assertSame('black', $identity->expectedColorFamily(new Product([
            'sku' => 'ARAGON-920-6060-S2',
            'name' => 'Półbuty ARAGON 920 6060 S2 SRC, czarne',
            'manufacturer' => 'ARTRA',
        ])));

        $this->assertSame('brown', $identity->expectedColorFamily(new Product([
            'sku' => 'CH-20KV',
            'name' => 'Chodnik elektroizolacyjny 20 KV Secura',
            'manufacturer' => 'SECURA',
            'description' => 'Mata ma kolor brązowy, z wierzchnią stroną ryflowaną.',
        ])));

        // dwa kolory naraz znaczą „nie wiadomo”, a nie „jeden z nich”
        $this->assertNull($identity->expectedColorFamily(new Product([
            'sku' => 'X-1',
            'name' => 'Kamizelka ostrzegawcza czarno-żółta',
            'manufacturer' => 'ARTRA',
        ])));
    }

    /** Produkcja 23.09.2026: zdjęcia z pierwszego pobierania ARTRY z inną klasą obuwia w nazwie pliku. */
    public function test_image_file_naming_another_footwear_class_is_foreign(): void
    {
        $identity = new ProductSearchIdentity;
        $card = static fn (string $name): Product => new Product(['sku' => $name, 'name' => $name, 'manufacturer' => 'ARTRA']);

        $this->assertTrue($identity->imageUrlNamesAnotherFootwearVariant(
            'https://artra.pl/cdn/shop/files/ARDOR_330_619060_S3L_ESD.png?v=1764059517&width=1728',
            $card('ARDOR 330 Air 619060 S1 PL ESD'),
        ));
        // inny model tej samej linii, inna klasa
        $this->assertTrue($identity->imageUrlNamesAnotherFootwearVariant(
            'https://sklep.example/buty-robocze-trzewiki-artra-archa-942-2360-o2-fo.jpg',
            $card('ARMEN 900 2360 S1'),
        ));
        // S1 PL i S1P to ta sama klasa
        $this->assertFalse($identity->imageUrlNamesAnotherFootwearVariant(
            'https://sklep.example/polbuty-artra-ardor-330-air-619060-s1p-esd.jpg',
            $card('ARDOR 330 Air 619060 S1 PL ESD'),
        ));
        // plik bez klasy niczego nie dowodzi
        $this->assertFalse($identity->imageUrlNamesAnotherFootwearVariant(
            'https://cdn.shopify.com/s/files/1/2/3/files/330Air-1.jpg',
            $card('ARDOR 330 Air 619060 S1 PL ESD'),
        ));
        // karta bez klasy (wkładka) — reguła jej nie dotyczy
        $this->assertFalse($identity->imageUrlNamesAnotherFootwearVariant(
            'https://sklep.example/wkladki-zapasowe-do-butow-artra-softyum-3d-esd-s3.webp',
            $card('SOFTYUM 3D ESD czarna'),
        ));
    }
}
