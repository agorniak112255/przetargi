<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\AnsellOfficialCatalog;
use App\Services\Enrichment\HybridWebSearchService;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

final class AnsellOfficialCatalogTest extends TestCase
{
    public function test_finds_official_alphatec_page_when_shops_lack_the_model(): void
    {
        $url = 'https://www.ansell.com/pl/pl/products/alphatec-4000-ultrasonically-welded-taped-model-121';
        Http::fake([
            'https://r.jina.ai/'.$url => Http::response(
                "# AlphaTec 4000 Ultrasonically Welded & Taped - Model 121\n\n"
                .'The AlphaTec 4000 model 121 is a full body protection suit tested against chemicals. '
                .str_repeat('opis ', 40),
                200
            ),
            '*' => Http::response('Product Not Found — missing', 200),
        ]);

        $product = new Product([
            'sku' => 'GR40T-00121-09',
            'name' => '4000-GR CVRL HOOD 121-G02.5XL',
            'manufacturer' => 'Ansell',
        ]);
        $hits = app(AnsellOfficialCatalog::class)->find($product);

        $this->assertSame($url, $hits[0]['url'] ?? null);
        $this->assertStringContainsString('Model 121', $hits[0]['title'] ?? '');
    }

    public function test_skips_product_not_found(): void
    {
        Http::fake([
            '*' => Http::response('Title: Product Not Found | Ansell'."\n\n".str_repeat('nav ', 40), 200),
        ]);

        $hits = app(AnsellOfficialCatalog::class)->find(new Product([
            'sku' => 'GR40T-00121-09',
            'name' => '4000-GR CVRL HOOD 121-G02.5XL',
            'manufacturer' => 'Ansell',
        ]));

        $this->assertSame([], $hits);
    }

    public function test_finds_alphatec_2000_apron_stitched_card(): void
    {
        $url = 'https://www.ansell.com/gb/en/products/alphatec-2000-standard-apron-stitched-model-213';
        Http::fake([
            'https://r.jina.ai/'.$url => Http::response(
                "# AlphaTec 2000 Standard - Apron - Stitched - Model 213\n\n"
                .'Chemical protective apron AlphaTec 2000 model 213 with stitched seams. '
                .str_repeat('opis ', 40),
                200
            ),
            '*' => Http::response('Product Not Found — missing', 200),
        ]);

        $hits = app(AnsellOfficialCatalog::class)->find(new Product([
            'sku' => 'WH20S-00213-00',
            'name' => '2000-WH APRON 213',
            'manufacturer' => 'ANSELL',
        ]));

        $this->assertSame($url, $hits[0]['url'] ?? null);
    }

    public function test_finds_bioclean_tsplus_hooded_coverall(): void
    {
        $url = 'https://www.ansell.com/pl/pl/products/bioclean-2000-hooded-coverall-model-111';
        Http::fake([
            'https://r.jina.ai/'.$url => Http::response(
                "# BioClean 2000 Hooded Coverall Model 111\n\n"
                .'Sterile disposable BioClean 2000 coverall with hood, model 111. '
                .str_repeat('opis ', 40),
                200
            ),
            '*' => Http::response('Product Not Found — missing', 200),
        ]);

        $hits = app(AnsellOfficialCatalog::class)->find(new Product([
            'sku' => 'WH20T-00111-09',
            'name' => '2000-WH TSPLUS CVRL HOOD 111.5XL',
            'manufacturer' => 'ANSELL',
        ]));

        $this->assertSame($url, $hits[0]['url'] ?? null);
    }

    public function test_finds_alphatec_2000_standard_for_wh20b_std(): void
    {
        $url = 'https://www.ansell.com/pl/pl/products/alphatec-2000-standard-model-111';
        Http::fake([
            'https://r.jina.ai/'.$url => Http::response(
                "# AlphaTec 2000 Standard - Model 111\n\n"
                .'Chemical protective coverall AlphaTec 2000 Standard with hood, model 111. '
                .str_repeat('opis ', 40),
                200
            ),
            '*' => Http::response('Product Not Found — missing', 200),
        ]);

        $hits = app(AnsellOfficialCatalog::class)->find(new Product([
            'sku' => 'WH20B-00111-12',
            'name' => '2000-WH STD CVRL HOOD 111.8XL',
            'manufacturer' => 'ANSELL',
        ]));

        $this->assertSame($url, $hits[0]['url'] ?? null);
    }

    public function test_finds_alphatec_3000_hood_model_121(): void
    {
        $url = 'https://www.ansell.com/pl/pl/products/alphatec-3000-ultrasonically-welded-taped-model-121';
        Http::fake([
            'https://r.jina.ai/'.$url => Http::response(
                "# AlphaTec 3000 Ultrasonically Welded & Taped - Model 121\n\n"
                .'Chemical protective coverall AlphaTec 3000 model 121 with hood. '
                .str_repeat('opis ', 40),
                200
            ),
            '*' => Http::response('Product Not Found — missing', 200),
        ]);

        $hits = app(AnsellOfficialCatalog::class)->find(new Product([
            'sku' => 'YE30T-00121-07-G02',
            'name' => '3000-YE CVRL HOOD 121-G02.3XL',
            'manufacturer' => 'Ansell',
        ]));

        $this->assertSame($url, $hits[0]['url'] ?? null);
    }

    public function test_falls_back_to_us_locale_when_pl_card_is_missing(): void
    {
        $us = 'https://www.ansell.com/us/en/products/hyflex-11-581';
        Http::fake([
            'https://r.jina.ai/'.$us => Http::response(
                "# HyFlex 11-581\n\nAnsell HyFlex 11-581 cut resistant glove. "
                .str_repeat('opis ', 40),
                200
            ),
            '*' => Http::response('Title: Product Not Found | Ansell'."\n\n".str_repeat('nav ', 40), 200),
        ]);

        $hits = app(AnsellOfficialCatalog::class)->find(new Product([
            'sku' => '11581120',
            'name' => 'HyFlex 11581 SIZE 12,0',
            'manufacturer' => 'Ansell',
        ]));

        $this->assertSame($us, $hits[0]['url'] ?? null);
    }

    public function test_finds_alphatec_glove_link_and_skips_about_us(): void
    {
        $url = 'https://www.ansell.com/pl/pl/products/alphatec-glove-link';
        Http::fake([
            'https://r.jina.ai/'.$url => Http::response(
                "# AlphaTec Glove Link\n\n"
                .'AlphaTec Glove Link 070 connects chemical protective gloves to the suit. '
                .str_repeat('opis ', 40),
                200
            ),
            '*' => Http::response(
                "# About us\n\nProwadzenie świata ku bezpieczniejszej przyszłości. 1893 Dunlop UK. "
                .'Do Not Sell My Personal Information',
                200
            ),
        ]);

        $hits = app(AnsellOfficialCatalog::class)->find(new Product([
            'sku' => 'AC01P-00070-00',
            'name' => 'AlphaTec Glove Link 070',
            'manufacturer' => 'Ansell',
        ]));

        $this->assertSame($url, $hits[0]['url'] ?? null);
    }

    public function test_named_official_card_is_used_without_sku_in_url(): void
    {
        $url = 'https://www.ansell.com/pl/pl/products/alphatec-glove-connector';
        Http::fake([
            'https://r.jina.ai/'.$url => Http::response(
                "# AlphaTec Glove Connector\n\n"
                .'AlphaTec Glove Connector 070 connects chemical protective gloves to the suit. '
                .str_repeat('opis ', 40),
                200
            ),
            '*' => Http::response('Title: Product Not Found | Ansell'."\n\n".str_repeat('nav ', 40), 200),
        ]);

        $product = new Product([
            'sku' => 'AC01P-00070-00',
            'name' => 'AlphaTec Glove Link 070',
            'manufacturer' => 'Ansell',
            'category' => 'rekawice',
        ]);
        $local = (new ReflectionClass(HybridWebSearchService::class))->getMethod('hitsFromLocalSources');
        $local->setAccessible(true);
        $pack = $local->invoke(app(HybridWebSearchService::class), $product);

        $this->assertSame('ansell_official', $pack['provider'] ?? null);
        $this->assertSame($url, $pack['results'][0]['url'] ?? null);
    }

    public function test_finds_kleenguard_g10_comfort_plus_on_labpro(): void
    {
        $url = 'https://labproinc.com/products/kg-g10-comfort-plus-ntrl-glv-lt-blue-xl-54189';
        Http::fake([
            'https://r.jina.ai/'.$url => Http::response(
                "# Kimberly Clark KG G10 Comfort Plus Ntrl Glv Lt Blue XL - 54189\n\n"
                .'Product Number:54189 Powder free cleanroom nitrile gloves. '
                .str_repeat('opis ', 40),
                200
            ),
            '*' => Http::response('Title: Product Not Found | Ansell'."\n\n".str_repeat('nav ', 40), 200),
        ]);

        $hits = app(AnsellOfficialCatalog::class)->find(new Product([
            'sku' => '54189',
            'name' => 'KG G10 Comfort Plus Ntrl Glv Lt Blue XL',
            'manufacturer' => 'Ansell',
        ]));

        $this->assertSame($url, $hits[0]['url'] ?? null);
        $this->assertStringContainsString('54189', $hits[0]['title'] ?? '');
    }
}
