<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ProductImageCandidateVerifier;
use App\Services\Enrichment\ProductImageDownloader;
use App\Services\Enrichment\ProductPageFetcher;
use App\Services\Enrichment\ProductSearchIdentity;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Złe zdjęcia z kart sklepów na cenniku Ansella (05.10.2026): inny model w nazwie pliku, logo witryny jako og:image,
 * og:image innego wyrobu na potwierdzonej karcie.
 */
final class ShopImageGuardsTest extends TestCase
{
    private const FOREIGN_MODEL = 'https://cas-technik.eu/media/75/1c/80/1689063534/08-354tuseouxsipd4p.jpg?ts=1756127514';

    public function test_image_file_naming_another_ansell_glove_model_is_foreign(): void
    {
        $identity = app(ProductSearchIdentity::class);
        $alphatec = new Product(['sku' => '8352100', 'name' => 'AlphaTec 08352', 'manufacturer' => 'Ansell']);

        $this->assertTrue($identity->imageUrlNamesForeignGloveModel(self::FOREIGN_MODEL, $alphatec));
        $this->assertFalse($identity->imageUrlNamesForeignGloveModel('https://cas-technik.eu/media/1/08-352abc.jpg', $alphatec));
        $this->assertFalse(
            $identity->imageUrlNamesForeignGloveModel('https://shop.example/08-352-i-08-354.jpg', $alphatec),
            'plik z naszym modelem obok innego zostaje'
        );
        // audyt 05.10.2026: katalog PIM z naszym modelem i lista modeli rodziny w nazwie pliku
        $suit = new Product(['sku' => '210500027', 'name' => 'AlphaTec 66-300 model 146, 3XL', 'manufacturer' => 'Ansell']);
        $this->assertFalse($identity->imageUrlNamesForeignGloveModel(
            'https://www.ansell.com/-/media/projects/ansell/website/pim/product-assets/alphatec-suits/66300/alphatec-66-330-model-146-static---front.ashx',
            $suit
        ));
        $family = 'https://www.ansell.com/-/media/projects/ansell/website/pim/product-assets/alphatec-gloves-and-aprons/alphatec-epdm/alphatec-epdm-85-501-503-505-image.ashx';
        $this->assertFalse($identity->imageUrlNamesForeignGloveModel($family, new Product(['sku' => '85503110', 'name' => 'ALPHATEC 85503 ISOLATOR 10Tb2432A S11,0', 'manufacturer' => 'Ansell'])));
        $this->assertTrue($identity->imageUrlNamesForeignGloveModel($family, new Product(['sku' => '85600110', 'name' => 'ALPHATEC 85600 8Tw2032A S11,0', 'manufacturer' => 'Ansell'])));
        $this->assertTrue($identity->imageUrlNamesForeignGloveModel(
            'https://www.ansell.com/-/media/projects/ansell/website/pim/product-assets/dermashield/dermashield-73-721/dermashield_73-721_glovestretch.ashx',
            new Product(['sku' => '73711090', 'name' => 'DERMASHIELD 73711', 'manufacturer' => 'Ansell'])
        ), 'zdjęcie sąsiedniego modelu z jego katalogu');

        // numer zdjęcia sklepu bez modelu i katalogi CDN w ścieżce nie są modelem
        $hyflex = new Product(['sku' => '11250160-N', 'name' => 'HyFlex 11250 NARROW NO THUMB S', 'manufacturer' => 'Ansell']);
        $this->assertFalse($identity->imageUrlNamesForeignGloveModel('https://bhp-sklep.com.pl/wp-content/uploads/2023/11/01180035-17286.png', $hyflex));

        Http::fake(['*' => Http::response('', 404)]);
        $picked = app(ProductImageCandidateVerifier::class)->select(
            $alphatec,
            [self::FOREIGN_MODEL],
            [['url' => 'https://cas-technik.eu/alphatec-08-352', 'text' => 'Ansell AlphaTec 08-352']],
            1,
            [self::FOREIGN_MODEL]
        );
        $this->assertSame([], $picked);
    }

    public function test_wordpress_site_logo_is_never_a_product_image(): void
    {
        $logo = 'https://portolana.pl/wp-content/uploads/2025/07/cropped-photo_2025-07-14_18-39-36-Edited-1.png';
        $this->assertTrue(ProductImageDownloader::isSiteIdentityGraphicUrl($logo));
        $this->assertTrue(ProductImageDownloader::isSiteIdentityGraphicUrl('https://shop.example/wp-content/uploads/site-icon-512.png'));
        $this->assertFalse(ProductImageDownloader::isSiteIdentityGraphicUrl('https://shop.example/img/ringers-259-cropped.jpg'));

        $junk = new ReflectionMethod(ProductPageFetcher::class, 'isJunkImageUrl');
        $this->assertTrue($junk->invoke(app(ProductPageFetcher::class), $logo));

        $ringers = new Product(['sku' => '259-13', 'name' => 'Ringers 259', 'manufacturer' => 'Ansell']);
        Http::fake(['*' => Http::response('', 404)]);
        $picked = app(ProductImageCandidateVerifier::class)->select(
            $ringers,
            [$logo],
            [['url' => 'https://portolana.pl/produkt/ringers-259', 'text' => 'Ansell Ringers 259']],
            1,
            [$logo]
        );
        $this->assertSame([], $picked);
    }

    /** PU610 na HyFlex 11-135: og:image potwierdzonej karty sklepu bez kodu wyrobu w adresie. */
    public function test_structured_image_without_product_code_needs_vision_in_strict_mode(): void
    {
        $hyflex = new Product(['sku' => '11135100', 'name' => 'HyFlex 11135', 'manufacturer' => 'Ansell']);
        $og = 'https://www.gloves.co.uk/user/products/large/Ansell-PU610-DG-Gloves.jpg';
        $pages = [['url' => 'https://www.gloves.co.uk/ansell-hyflex-11-135.html', 'text' => 'Ansell HyFlex 11-135']];
        Http::fake(['*' => Http::response('', 404)]);
        $verifier = app(ProductImageCandidateVerifier::class);

        $this->assertSame([$og], $verifier->select($hyflex, [$og], $pages, 1, [$og]), 'zwykła droga bez zmian');
        $this->assertSame([], $verifier->select($hyflex, [$og], $pages, 1, [$og], trustStructured: false));

        // zdjęcie z kodem wyrobu w adresie nie potrzebuje modelu wizyjnego także w trybie ścisłym
        $coded = 'https://www.gloves.co.uk/user/products/large/Ansell-HyFlex-11-135-Gloves.jpg';
        $this->assertSame([$coded], $verifier->select($hyflex, [$coded], $pages, 1, [$coded], trustStructured: false));
    }
}
