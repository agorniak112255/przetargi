<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\ProductSearchIdentity;
use Tests\TestCase;

final class ProductSearchIdentityTest extends TestCase
{
    public function test_strips_brand_prefix_and_builds_google_like_queries(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'PROS-1007',
            'name' => '1007',
            'manufacturer' => 'PROS',
        ]);

        $tokens = $id->matchTokens($product);
        $this->assertContains('pros-1007', $tokens);
        $this->assertContains('1007', $tokens);

        $queries = $id->searchQueries($product, 'industry');
        $joined = implode(' | ', $queries);
        $this->assertStringContainsString('PROS-1007', $joined);
        $this->assertStringContainsString('PROS 1007', $joined);
        // sama marka PROS nie dokłada fałszywego hintu kategorii
        $this->assertStringNotContainsString('ubranie wodoochronne', $joined);
    }

    public function test_urgent_sweatshirt_is_not_treated_as_gloves(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'URG-HSV-WOR-BLUZA',
            'name' => 'URG-HSV-WOR (bluza)',
            'manufacturer' => 'Urgent',
        ]);

        $joined = implode(' | ', $id->searchQueries($product, 'industry'));
        $this->assertStringNotContainsString('rękawice', $joined);
        $this->assertStringContainsString('odzież ostrzegawcza', $joined);
    }

    public function test_internal_price_list_code_is_not_searched_as_model(): void
    {
        $id = new ProductSearchIdentity;
        $internal = new Product([
            'sku' => 'URG-HSV-WOR-BLUZA',
            'name' => 'URG-HSV-WOR (bluza)',
            'manufacturer' => 'Urgent',
        ]);
        $this->assertTrue($id->looksLikeInternalSku($internal));

        // kod z rdzeniem modelu zostaje w zapytaniach
        foreach (['106-SB-ZIMA', '102-S3-TPU', 'ROBFM', 'PROS-1000'] as $sku) {
            $this->assertFalse(
                $id->looksLikeInternalSku(new Product(['sku' => $sku, 'manufacturer' => 'Urgent'])),
                $sku.' nie jest kodem wewnętrznym'
            );
        }

        $joined = implode(' | ', $id->searchQueries($internal, 'industry'));
        $this->assertStringNotContainsString('"URG-HSV-WOR-BLUZA"', $joined);
    }

    public function test_waterproof_name_still_gets_clothing_hint(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'PROS-1001',
            'name' => 'Ubranie wodoochronne 1001',
            'manufacturer' => 'PROS',
        ]);

        $joined = implode(' | ', $id->searchQueries($product, 'industry'));
        $this->assertStringContainsString('ubranie wodoochronne', $joined);
    }

    public function test_matches_pros_model_slash_variant(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'PROS-1001',
            'name' => '1001',
            'manufacturer' => 'PROS',
        ]);

        $hay = 'https://icd.pl/produkt/ubranie-wodoochronne-pros-model-101-001 '
            .'Ubranie wodoochronne PROS model 101/001 - czarny Plavitex';

        $this->assertTrue($id->hayMentionsProduct($hay, $product));
        $this->assertTrue($id->coreInUrlOrTitle(
            'https://icd.pl/produkt/ubranie-wodoochronne-pros-model-101-001',
            'Ubranie wodoochronne PROS model 101/001',
            $product
        ));
    }

    public function test_name_type_must_appear_on_page(): void
    {
        $id = new ProductSearchIdentity;
        $gloves = new Product([
            'sku' => '104',
            'name' => 'Rękawica tekstylna TEGERA 104',
            'manufacturer' => 'Ejendals',
        ]);
        $coverall = new Product([
            'sku' => '104',
            'name' => 'Kombinezon wodoochronny 104',
            'manufacturer' => 'PROS',
        ]);

        $this->assertFalse($id->hayHasRequiredTypeFromName(
            'https://shop.example/tegera-104 Ejendals Tegera 104',
            $gloves
        ));
        $this->assertTrue($id->hayHasRequiredTypeFromName(
            'https://shop.example/rekawice-tegera-104 Rękawice Tegera 104',
            $gloves
        ));
        $this->assertFalse($id->hayHasRequiredTypeFromName(
            'https://shop.example/rekawice-104 Rękawice 104 PROS',
            $coverall
        ));
        $this->assertTrue($id->hayHasRequiredTypeFromName(
            'https://shop.example/kombinezon-104 Kombinezon 104 PROS',
            $coverall
        ));
    }

    public function test_tegera_104_does_not_match_pros_coverall(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '104',
            'name' => 'Rękawica tekstylna TEGERA 104',
            'manufacturer' => 'Ejendals',
        ]);

        $this->assertFalse($id->hayMentionsProduct(
            'https://bogarobhp.pl/kombinezon-wodoochronny-model-104-aj-group-pros '
            .'Kombinezon wodoochronny model 104 produkcji AJ Group / PROS PLAVITEX',
            $product
        ));
        $this->assertTrue($id->hayMentionsProduct(
            'https://icd.pl/rekawice-tegera-104 '
            .'Rękawice Tegera 104 Ejendals',
            $product
        ));
    }

    public function test_rejects_short_numeric_without_brand(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'PROS-1001',
            'name' => '1001',
            'manufacturer' => 'PROS',
        ]);

        $this->assertFalse($id->hayMentionsProduct(
            'https://example.com/product/1001 Random gadget 1001',
            $product
        ));
    }

    public function test_uvex_numeric_sku_still_matches(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '60497',
            'name' => 'C500',
            'manufacturer' => 'uvex',
        ]);

        $this->assertTrue($id->hayMentionsProduct(
            'https://www.uvex-safety.com/en/products/safety-gloves/uvex-c500-cut-protection-glove-6049706/ '
                .'uvex C500 cut protection glove Product no. 60497',
            $product
        ));
        $this->assertTrue($id->coreInUrlOrTitle(
            'https://www.uvex-safety.com/en/products/safety-gloves/uvex-c500-cut-protection-glove-6049706/',
            'uvex C500 cut protection glove',
            $product
        ));

        $queries = $id->searchQueries($product, 'manufacturer');
        $this->assertSame('site:uvex-safety.com 60497 glove OR handschuh', $queries[0]);
        $this->assertSame('site:uvex-safety.com/products 60497', $queries[1]);

        $industryQueries = $id->searchQueries($product, 'industry');
        $this->assertSame('C500 60497 uvex BHP', $industryQueries[0]);
        foreach ($industryQueries as $query) {
            $this->assertMatchesRegularExpression('/\buvex\b/i', $query);
        }
    }

    public function test_ansell_coverall_queries_target_bpbhp_and_style_code(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'GR40T-00121-09',
            'name' => '4000-GR CVRL HOOD 121-G02.5XL',
            'manufacturer' => 'Ansell',
        ]);

        $this->assertContains('121', $id->ansellStyleCodes($product));
        $this->assertContains('4000', $id->ansellStyleCodes($product));

        $joined = implode(' | ', $id->searchQueries($product, 'manufacturer'));
        $this->assertStringContainsString('site:ansell.com', $joined);
        $this->assertStringContainsString('site:bpbhp.pl', $joined);
        $this->assertStringContainsString('121', $joined);
        $this->assertStringContainsString('AlphaTec', $joined);
        $this->assertContains(
            'https://www.ansell.com/pl/pl/products/alphatec-4000-ultrasonically-welded-taped-model-121',
            $id->ansellOfficialProductUrls($product)
        );
        $this->assertTrue($id->hayHasRequiredTypeFromName(
            'https://www.ansell.com/gb/en/products/alphatec-4000-ultrasonically-welded-taped-model-121',
            $product
        ));
        $this->assertTrue($id->hayMentionsProduct(
            'https://www.ansell.com/gb/en/products/alphatec-4000-ultrasonically-welded-taped-model-121 Ansell AlphaTec 4000 Model 121',
            $product
        ));
        $this->assertStringNotContainsString('rękawice', $joined);

        $primary = implode(' | ', $id->primaryQueries($product));
        $this->assertStringContainsString('121', $primary);
        $this->assertStringContainsString('kombinezon', $primary);
        $this->assertStringNotContainsString('rękawice', $primary);

        $this->assertTrue($id->hayMentionsProduct(
            'https://bpbhp.pl/kombinezon-ansell-alphatec-4000-model-121 ANSELL 4000 CVRL HOOD 121',
            $product
        ));
        $this->assertTrue($id->pageClaimsAnotherCode(
            'https://bpbhp.pl/kombinezon-ansell-alphatec-4000-model-111',
            'Kombinezon AlphaTec 4000 model 111',
            $product
        ));
        $this->assertFalse($id->pageClaimsAnotherCode(
            'https://bpbhp.pl/kombinezon-ansell-alphatec-4000-model-121',
            'Kombinezon AlphaTec 4000 model 121',
            $product
        ));
    }

    public function test_ansell_leading_zeros_come_later_in_search(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'OR15S-00138-06',
            'name' => '1500-OR STD CVRL HOOD 138.5XL',
            'manufacturer' => 'Ansell',
        ]);

        $this->assertSame('OR15S-138-06', $id->skuWithoutLeadingZeros('OR15S-00138-06'));
        $this->assertSame('138', $id->ansellCatalogBits($product)['model']);
        $this->assertSame('1500', $id->ansellCatalogBits($product)['series']);

        $early = implode(' | ', $id->ansellSearchPhrases($product, 'early'));
        $late = implode(' | ', $id->ansellSearchPhrases($product, 'late'));
        $this->assertStringContainsString('00138', $early);
        $this->assertStringContainsString('1500-OR', $early);
        $this->assertStringContainsString('HOOD 138', $early);
        $this->assertStringNotContainsString('HOOD 1 ', $early);
        $this->assertStringNotContainsString('00138', $late);
        $this->assertStringContainsString('138', $late);
        $this->assertStringContainsString('OR15S-138-06', $late);
        $this->assertSame('1500-OR STD CVRL HOOD 138', $id->ansellTradeName($product));
        $this->assertSame(
            '1500-NV STD CVRL HOOD 138',
            $id->ansellTradeName(new Product([
                'sku' => 'NV15S-00138-03',
                'name' => '1500-NV STD CVRL HOOD 138.M',
                'manufacturer' => 'Ansell',
            ]))
        );

        $this->assertFalse($id->hayMentionsProduct(
            'https://bpbhp.pl/kombinezon-ansell-alphatec-1500-wh-plus-cvrl-hood-111 Ansell CVRL HOOD 1500',
            $product
        ));
        $this->assertTrue($id->hayMentionsProduct(
            'https://shop.example/1500-or-std-cvrl-hood-138 ANSELL 1500-OR STD CVRL HOOD 138',
            $product
        ));
    }

    public function test_ardon_search_queries_target_official_and_shop_sites(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'M80',
            'name' => 'Buty robocze',
            'manufacturer' => 'ARDON SAFETY S.R.O.',
        ]);

        $joined = implode(' | ', $id->searchQueries($product, 'manufacturer'));
        $this->assertStringContainsString('site:ardon.pl', $joined);
        $this->assertStringContainsString('site:behapownia.pl', $joined);
        $this->assertStringContainsString('site:specto.com.pl', $joined);
        $this->assertStringContainsString('site:kams.com.pl', $joined);
        $this->assertStringContainsString('site:aitbhp.pl', $joined);
        $this->assertStringContainsString('site:optimumbhp.pl', $joined);
    }

    public function test_marelplus_search_queries_target_official_shop(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'CADIZ-S1PS',
            'name' => 'PÓŁBUTY CADIZ S1PS FO SR',
            'manufacturer' => 'MAREL PLUS',
        ]);

        $joined = implode(' | ', $id->searchQueries($product, 'manufacturer'));
        $this->assertStringContainsString('site:marelplus.pl', $joined);
        $this->assertTrue($id->hayMentionsProduct(
            'https://marelplus.pl/polbuty-cadiz-s1ps-fo-sr Półbuty Cadiz S1PS FO SR',
            $product
        ));
    }

    public function test_mapa_search_queries_target_polish_catalog(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'KRYTECH-563-11',
            'name' => 'KRYTECH 563',
            'manufacturer' => 'MAPA',
        ]);

        $this->assertSame('KRYTECH 563', $id->mapaCatalogName($product));
        $this->assertSame(['KRYTECH 563'], $id->variantBaseCodes($product));

        $joined = implode(' | ', $id->searchQueries($product, 'manufacturer'));
        $this->assertStringContainsString('site:mapa-pro.pl KRYTECH 563', $joined);
        $this->assertStringContainsString('site:icd.pl KRYTECH 563', $joined);
        $this->assertTrue($id->hayMentionsProduct(
            'https://www.mapa-pro.pl/produkty/odpornosc-na-przeciecie/prace-precyzyjne/strona-produktu/krytech-563 KryTech 563',
            $product
        ));
        $this->assertFalse($id->hayMentionsProduct(
            'https://www.mapa-pro.pl/produkty/odpornosc-na-przeciecie/prace-precyzyjne/strona-produktu/krytech-643 KryTech 643',
            $product
        ));
    }

    public function test_mapa_article_number_is_not_required_on_shop_url(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '34977068',
            'name' => 'SOLO 977',
            'manufacturer' => 'MAPA',
        ]);

        $this->assertSame('SOLO 977', $id->mapaCatalogName($product));
        $this->assertSame('SOLO 977 MAPA', $id->primaryQueries($product)[0] ?? null);
        $this->assertStringContainsString('site:icd.pl SOLO 977', implode(' | ', $id->searchQueries($product, 'manufacturer')));
        $this->assertTrue($id->hayMentionsProduct(
            'https://icd.pl/rekawice-chemiczne-mapa-solo977.html Rękawice chemiczne MAPA Solo 977',
            $product
        ));
        $this->assertContains('SOLO 977', $id->catalogTradeNames($product));
        $this->assertFalse($id->pageClaimsAnotherCode(
            'https://icd.pl/rekawice-chemiczne-mapa-solo977.html',
            'Rękawice chemiczne MAPA Solo 977',
            $product
        ));
        $this->assertFalse($id->hayMentionsProduct(
            'https://icd.pl/rekawice-chemiczne-mapa-ultranitril472.html Rękawice MAPA Ultranitril 472',
            $product
        ));
    }

    public function test_mapa_multiword_name_matches_shop_slug(): void
    {
        $id = new ProductSearchIdentity;
        $plus = new Product([
            'sku' => '34995428',
            'name' => 'SOLO PLUS 995',
            'manufacturer' => 'MAPA',
        ]);
        $temp = new Product([
            'sku' => '34332028',
            'name' => 'TEMP-TEC 332 SIZE 8',
            'manufacturer' => 'MAPA',
        ]);

        $this->assertSame('SOLO PLUS 995', $id->mapaCatalogName($plus));
        $this->assertSame('TEMP-TEC 332', $id->mapaCatalogName($temp));
        $this->assertContains('SOLO PLUS 995', $id->catalogTradeNames($plus));
        $this->assertContains('TEMP-TEC 332', $id->catalogTradeNames($temp));
        $this->assertFalse($id->pageClaimsAnotherCode(
            'https://www.mapa-pro.pl/produkty/do-uzytku-jednorazowego/strona-produktu/solo-plus-995',
            'Solo Plus 995',
            $plus
        ));
        $this->assertFalse($id->pageClaimsAnotherCode(
            'https://icd.pl/rekawice-chemiczne-mapa-temptec332.html',
            'Rękawice MAPA Temp-Tec 332',
            $temp
        ));
        $this->assertTrue($id->hayMentionsProduct(
            'https://www.mapa-pro.pl/produkty/strona-produktu/temp-tec-332 Temp-Tec 332 MAPA',
            $temp
        ));
        $this->assertFalse($id->hayMentionsProduct(
            'https://icd.pl/rekawice-chemiczne-mapa-solo977.html Rękawice chemiczne MAPA Solo 977',
            $plus
        ));
    }

    public function test_shop_identity_uses_catalog_name_not_internal_sku_tail(): void
    {
        $id = new ProductSearchIdentity;
        $baltik = new Product([
            'sku' => 'BALTIK-BLACK-CZARNY-NYLON-PO-70',
            'name' => 'BALTIK BLACK - czarny nylon powlekany poliuretanem, DMF free',
            'manufacturer' => 'MAREL PLUS',
        ]);
        $argo = new Product([
            'sku' => 'ARGO-KURTKA-OCIEPLANA-POLYES-43',
            'name' => 'ARGO- kurtka ocieplana polyester pongee, szaro-grafitowa',
            'manufacturer' => 'MAREL PLUS',
            'category' => 'Kurtki',
        ]);
        $buty = new Product([
            'sku' => 'P-BUTY-126',
            'name' => 'Półbuty 126',
            'manufacturer' => 'MAREL PLUS',
        ]);

        $this->assertContains('BALTIK BLACK', $id->shopIdentityPhrases($baltik));
        $this->assertContains('ARGO', $id->shopIdentityPhrases($argo));
        $this->assertContains('buty 126', $id->shopIdentityPhrases($buty));

        $baltikQ = implode(' | ', $id->searchQueries($baltik, 'manufacturer'));
        $this->assertStringContainsString('site:marelplus.pl BALTIK BLACK', $baltikQ);
        $this->assertStringNotContainsString('CZARNY-NYLON', $baltikQ);
        $this->assertSame('BALTIK BLACK MAREL PLUS', $id->primaryQueries($baltik)[0] ?? null);

        $this->assertTrue($id->hayMentionsProduct(
            'https://marelplus.pl/rekawice-baltik-black Rękawice Baltik Black MAREL PLUS',
            $baltik
        ));
        $this->assertFalse($id->hayMentionsProduct(
            'https://marelplus.pl/rekawice-nubia Rękawice Nubia MAREL PLUS',
            $baltik
        ));
        $this->assertTrue($id->hayMentionsProduct(
            'https://marelplus.pl/kurtka-argo Kurtka Argo MAREL PLUS',
            $argo
        ));
        $this->assertFalse($id->hayMentionsProduct(
            'https://marelplus.pl/kurtka-kardif Kurtka Kardif MAREL PLUS',
            $argo
        ));
    }

    public function test_search_phrase_always_appends_manufacturer(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '3-60NM',
            'name' => 'Rękawice nitrylowe 3-60NM',
            'manufacturer' => 'Lenard',
        ]);

        $this->assertSame(
            'Rękawice nitrylowe 3-60NM Lenard BHP',
            $id->productNameWithManufacturer($product)
        );
        foreach ($id->searchQueries($product, 'industry') as $query) {
            $this->assertMatchesRegularExpression('/\blenard\b/i', $query, $query);
        }
    }

    public function test_letter_sku_gets_bhp_disambiguator(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'ROBFM',
            'name' => 'ROBFM',
            'manufacturer' => 'JS Gloves',
        ]);

        $this->assertSame('ROBFM JS Gloves BHP', $id->productNameWithManufacturer($product));
    }

    public function test_ansell_product_source_uses_polish_locale(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '065-06',
            'name' => 'RINGERS R065',
            'manufacturer' => 'Ansell',
        ]);

        $this->assertSame(
            'https://www.ansell.com/pl/pl/products/ringers-r065',
            $id->preferredLocaleUrl(
                'https://www.ansell.com/gb/en/products/ringers-r065',
                $product
            )
        );
    }

    public function test_rejects_weight_false_positive_1000g_for_sku_1000(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'PROS-1000',
            'name' => '1000',
            'manufacturer' => 'PROS',
        ]);

        $hay = 'https://roboczystyl.pl/sklep/ochrona-nog/buty-gumowe-pcv-nitryl-eva/'
            .'spodniobuty-pros-sb01-strong-1000g-czarny '
            .'Spodniobuty PROS SB01 STRONG 1000g czarny';

        $this->assertFalse(
            $id->hayMentionsProduct($hay, $product),
            'Gramatura 1000g nie może być uznana za kod PROS-1000'
        );
        $this->assertFalse($id->coreInUrlOrTitle(
            'https://roboczystyl.pl/sklep/.../spodniobuty-pros-sb01-strong-1000g-czarny',
            'Spodniobuty PROS SB01 STRONG 1000g',
            $product
        ));
    }

    public function test_accepts_standalone_code_1000_with_brand(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'PROS-1000',
            'name' => '1000',
            'manufacturer' => 'PROS',
            'category' => 'REKAWICE',
        ]);

        $this->assertTrue($id->hayMentionsProduct(
            'https://shop.example/produkt/pros-1000-rekawice PROS model 1000 rękawice',
            $product
        ));
    }

    public function test_rejects_longer_sku_variant_pages_for_nb27(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'NB27',
            'name' => 'RUBIFLEX',
            'manufacturer' => 'uvex',
        ]);

        $this->assertFalse($id->hayMentionsProduct(
            'https://www.uvex-safety.pl/pl/produkty/rekawice-ochronne/rekawica-ochronna-uvex-rubiflex-s-nb27b/ '
                .'uvex rubiflex s nb27b',
            $product
        ));
        $this->assertTrue($id->hayMentionsProduct(
            'https://www.uvex-safety.pl/pl/produkty/rekawice-ochronne/rekawica-ochronna-uvex-rubiflex-nb27-6000934/ '
                .'uvex rubiflex nb27 orange',
            $product
        ));
    }

    public function test_urgent_glove_series_queries_and_match_despite_pros_label(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'PROS-1000',
            'name' => '1000',
            'manufacturer' => 'PROS',
            'category' => 'REKAWICE',
        ]);

        $this->assertTrue($id->looksLikeUrgentGloveSeries($product));
        $joined = implode(' | ', $id->searchQueries($product, 'industry'));
        $this->assertStringContainsString('Urgent 1000', $joined);
        $this->assertStringContainsString('rękawice', mb_strtolower($joined));

        $hay = 'https://optimumbhp.pl/REKAWICE-ROBOCZE-POWLEKANE-LATEKSEM-1000-URGENT-p138481 '
            .'Urgent 1000 rękawice robocze powlekane lateksem';
        $this->assertTrue($id->hayMentionsProduct($hay, $product));
        $this->assertFalse($id->hayMentionsProduct(
            'https://roboczystyl.pl/spodniobuty-pros-sb01-strong-1000g-czarny Spodniobuty PROS 1000g',
            $product
        ));
    }

    public function test_pilne_sku_is_urgent_glove_series(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'PILNE-1019',
            'name' => '1019 ZIMA Z POLARU',
            'manufacturer' => 'PILNE',
            'category' => 'REKAWICE',
        ]);

        $this->assertTrue($id->looksLikeUrgentGloveSeries($product));
        $joined = implode(' | ', $id->searchQueries($product, 'industry'));
        $this->assertStringContainsString('Urgent 1019', $joined);

        $this->assertTrue($id->hayMentionsProduct(
            'https://urgent.com.pl/rekawice-1019-zima-z-polaru Urgent 1019 zima z polaru',
            $product
        ));
        $this->assertFalse($id->hayMentionsProduct(
            'https://cushmanwakefield.com/offices/1019 Find the perfect office, industrial or commercial real estate',
            $product
        ));
        $this->assertTrue($id->isTrustedPageImageUrl(
            'https://urgent.com.pl/wp-content/uploads/2020/1019-zima.jpg',
            $product
        ));
    }

    public function test_code_matches_regardless_of_separators(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'MT-212-2',
            'name' => 'Maska MT 212/2',
            'manufacturer' => 'MASKPOL',
            'category' => 'Maski',
        ]);

        $this->assertTrue($id->hayHasProductCode('głównym zadaniem maski mt 212/2 jest ochrona', $product));
        $this->assertTrue($id->hayHasProductCode('maska mt212/2 maskpol', $product));
        $this->assertTrue($id->hayHasProductCode('maskpol.com.pl/maski/maska-mt-212-2', $product));
        // dwa człony liczbowe bez separatora to już inny kod
        $this->assertFalse($id->codeInText('maska mt 2122 maskpol', 'MT-212-2'));
        // wariant bez ostatniego członu bywa innym modelem
        $this->assertFalse($id->hayHasProductCode('maska mt 212 maskpol', $product));
    }

    public function test_code_match_does_not_swallow_longer_neighbour_codes(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'MT-212-2',
            'name' => 'Maska MT 212/2',
            'manufacturer' => 'MASKPOL',
        ]);

        $this->assertFalse($id->hayHasProductCode(
            'filtropochłaniacz fp 211/1-p3/w-me/ts maskpol',
            $product
        ));
        $this->assertFalse($id->hayHasProductCode('maska mt 212/23 maskpol', $product));
    }

    public function test_shop_page_id_is_not_read_as_another_model(): void
    {
        $product = new Product([
            'manufacturer' => 'Urgent',
            'sku' => '1202',
            'name' => 'Rękawice 1202 kozia czerwona',
        ]);
        $identity = app(ProductSearchIdentity::class);

        $this->assertFalse($identity->pageClaimsAnotherCode(
            'https://optimumbhp.pl/REKAWICE-ROBOCZE-1202-URGENT-p138481',
            '',
            $product
        ));
    }

    public function test_accessory_card_mentioning_our_model_is_not_our_card(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'MT-212-2',
            'name' => 'Maska MT 212/2',
            'manufacturer' => 'MASKPOL',
        ]);

        $this->assertTrue($id->pageClaimsAnotherCode(
            'https://www.maskpol.com.pl/filtropochlaniacze/filtropochlaniacz-fp-211-1-p3-w-me-ts',
            'Filtropochłaniacz FP 211/1-P3/W-ME/TS',
            $product
        ));
        $this->assertFalse($id->pageClaimsAnotherCode(
            'https://www.maskpol.com.pl/maski/maska-mt-212-2',
            'Maska MT 212/2',
            $product
        ));
        // tytuł bez członu z wariantem nadal opisuje nasz model
        $this->assertFalse($id->pageClaimsAnotherCode(
            'https://www.bezpieczni112.pl/maski/maska-mt-212',
            'MASKA MT 212',
            $product
        ));
        // karta bez żadnego oznaczenia w adresie i tytule zostaje w grze
        $this->assertFalse($id->pageClaimsAnotherCode(
            'https://sklep.example/maska-przeciwgazowa-maskpol',
            'Maska przeciwgazowa MASKPOL',
            $product
        ));
        // norma w tytule to nie kod innego modelu
        $this->assertFalse($id->pageClaimsAnotherCode(
            'https://sklep.example/maska-przeciwgazowa',
            'Maska przeciwgazowa EN 136 MASKPOL',
            $product
        ));
    }

    public function test_jalas_sku_is_not_cas_registry_number(): void
    {
        $id = new ProductSearchIdentity;
        $king = new Product([
            'sku' => '1868',
            'name' => 'Obuwie ochronne - obuwie JALAS® 1868 KING',
            'manufacturer' => 'Ejendals',
        ]);
        $offRoad = new Product([
            'sku' => '1878',
            'name' => 'Obuwie ochronne - wysokie JALAS® 1878 OFF ROAD',
            'manufacturer' => 'Ejendals',
        ]);

        $this->assertFalse($id->codeInText(
            'cas 1868-00-4 3,3-bis(trifluoromethyl)benzophenone',
            '1868'
        ));
        $this->assertTrue($id->codeInText('jalas 1868 king obuwie ochronne', '1868'));

        $tci = 'https://www.tcichemicals.com/PL/pl/p/B3336 '
            .'3,3\'-Bis(trifluoromethyl)benzophenone CAS 1868-00-4 TCI '
            .'Obuwie ochronne obuwie JALAS 1868 KING Ejendals';
        $this->assertTrue($id->looksLikeChemicalCatalogHit($tci));
        $this->assertFalse($id->hayMentionsProduct($tci, $king));

        $acros = 'https://www.acros.com/product/1878-68-8 '
            .'Kwas 4-bromofenylooctowy CAS 1878-68-8 Acros Organics '
            .'Obuwie ochronne wysokie JALAS 1878 OFF ROAD';
        $this->assertFalse($id->hayMentionsProduct($acros, $offRoad));

        $this->assertTrue($id->hayMentionsProduct(
            'https://www.ejendals.com/pl/produkty/jalas-1868-king '
            .'Obuwie ochronne Jalas 1868 KING Ejendals S3',
            $king
        ));
        $this->assertFalse($id->imageUrlMentionsProduct(
            'https://www.tcichemicals.com/assets/structure/1868-00-4.png',
            $king
        ));
    }
}
