<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\CatalogIndexSearch;
use App\Services\Enrichment\HybridWebSearchService;
use App\Services\Enrichment\ProductSearchIdentity;
use ReflectionClass;
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

    public function test_letter_color_suffix_exposes_numeric_model(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '109/O',
            'name' => 'Fartuch wodoochronny z PU',
            'manufacturer' => 'AJ GROUP',
        ]);

        $this->assertSame(['109'], $id->variantBaseCodes($product));
        $this->assertContains('109', $id->skuSearchNeedles($product));
        $this->assertContains('site:pros.pl 109', $id->searchQueries($product, 'manufacturer'));
        $this->assertContains('109', app(CatalogIndexSearch::class)->codes($product));
        $this->assertContains('048', app(CatalogIndexSearch::class)->codes(new Product([
            'sku' => '.048',
            'name' => 'Zaciski na rękawice antyprzecięciowe',
            'manufacturer' => 'AJ GROUP',
        ])));
        $this->assertTrue($id->isConfirmedProductCard(
            'https://sklep-bhp.example/fartuchy/199-fartuch-model-109.html',
            'Fartuch model 109',
            'Fartuch model 109 PROS',
            $product
        ));

        $this->assertSame([], $id->variantBaseCodes(new Product([
            'sku' => '101/001',
            'name' => 'Ubranie 101/001',
            'manufacturer' => 'PROS',
        ])));
        $this->assertSame([], $id->variantBaseCodes(new Product([
            'sku' => '108/WZ',
            'name' => 'Fartuch 108WZ',
            'manufacturer' => 'AJ GROUP',
        ])));
        $this->assertNotContains('40', $id->variantBaseCodes(new Product([
            'sku' => 'G3175/40',
            'name' => 'Półbuty TRACK',
            'manufacturer' => 'ARDON',
        ])));
    }

    public function test_sku_with_letter_and_catalog_tail_exposes_shop_model(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '001/A/ELR',
            'name' => 'Spodnie ogrodniczki z elementami odblaskowymi',
            'manufacturer' => 'AJ GROUP',
        ]);
        $img = 'https://pros.pl/7198-large_default/spodnie-ogrodniczki-model-001.jpg';

        $this->assertEqualsCanonicalizing(['001', '001A'], $id->variantBaseCodes($product));
        $this->assertContains('001', $id->skuSearchNeedles($product));
        $this->assertContains('001A', $id->skuSearchNeedles($product));
        $this->assertContains('001', app(CatalogIndexSearch::class)->codes($product));
        $this->assertContains('001a', app(CatalogIndexSearch::class)->codes($product));
        $this->assertTrue($id->imageUrlMentionsProduct($img, $product));
        $this->assertTrue($id->isConfirmedProductCard(
            'https://pros.pl/pl/odziez-wodoochronna-standard/70-spodnie-ogrodniczki-model-001.html',
            'Spodnie ogrodniczki model 001',
            'PROS',
            $product
        ));
    }

    public function test_clothing_set_sku_confirms_ubranie_card(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '101/112',
            'name' => 'Ubranie wodoochronne [kurtka 3/4 i spodnie do pasa]',
            'manufacturer' => 'AJ GROUP',
        ]);
        $ours = 'https://pros.pl/pl/odziez-wodoochronna-standard/244-ubranie-model-101112.html';

        $this->assertContains('101112', app(CatalogIndexSearch::class)->codes($product));
        $this->assertNotContains('ubranie', app(CatalogIndexSearch::class)->codes($product));
        $this->assertNotContains('pasa', app(CatalogIndexSearch::class)->codes($product));
        $this->assertTrue($id->hayHasRequiredTypeFromName($ours, $product));
        $this->assertTrue($id->isConfirmedProductCard($ours, 'Ubranie model 101/112', 'PROS', $product));
    }

    public function test_glued_shop_id_confirms_short_numeric_model(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '902',
            'name' => 'SPODNIE DO PASA',
            'manufacturer' => 'AJ GROUP',
        ]);
        $url = 'https://behapownia.pl/spodnie-wodoochronne-do-pasa-bemoregreen-9022002';

        $this->assertTrue($id->urlHasGluedNumericModel($url, $product));
        $this->assertTrue($id->isConfirmedProductCard($url, '', 'Spodnie wodoochronne do pasa', $product));
        $this->assertFalse($id->urlHasGluedNumericModel(
            'https://centrumelektronarzedzi.pl/pl/p/Pasek-do-spodni-130cm-granatowy-Lahti-Pro-L9020300',
            $product
        ));
        $this->assertFalse($id->isConfirmedProductCard(
            'https://centrumelektronarzedzi.pl/pl/p/Pasek-do-spodni-130cm-granatowy-Lahti-Pro-L9020300',
            'Pasek do spodni 130cm granatowy Lahti Pro L9020300',
            'Pasek do spodni Lahti Pro L9020300',
            $product
        ));
        $this->assertTrue($id->hayHasProductCode(
            'https://shop.pl/zaciski-na-rekawice-048',
            new Product([
                'sku' => '.048',
                'name' => 'Zaciski na rękawice antyprzecięciowe',
                'manufacturer' => 'AJ GROUP',
            ])
        ));
    }

    public function test_gender_letter_suffix_confirms_short_numeric_cape(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '905',
            'name' => 'PELERYNA MĘSKA/DAMSKA',
            'manufacturer' => 'AJ GROUP',
            'category' => 'odziez',
        ]);
        $url = 'https://dodatkimasarskiezwm.pl/111233-peleryna-meska-wodoochronna-pros-model-905m-pl';

        $this->assertSame('peleryna', $id->requiredArticleTypeLabel($product));
        $this->assertContains('peleryn', $id->catalogTypeTokenPrefixes($product));
        $this->assertTrue($id->hayHasRequiredTypeFromName($url, $product));
        $this->assertTrue($id->urlHasGluedNumericModel($url, $product));
        $this->assertTrue($id->isConfirmedProductCard($url, '', 'Peleryna męska wodoochronna PROS', $product));
        $this->assertFalse($id->urlHasGluedNumericModel(
            'https://shop.pl/p/Pasek-Lahti-Pro-L9050300',
            $product
        ));
        $this->assertFalse($id->isConfirmedProductCard(
            'https://shop.pl/p/Pasek-Lahti-Pro-L9050300',
            'Pasek Lahti Pro L9050300',
            'Pasek Lahti Pro L9050300',
            $product
        ));
        $this->assertFalse($id->hayHasRequiredTypeFromName(
            'https://behapownia.pl/kurtka-wodoochronna-pros-model-905',
            $product
        ));
    }

    public function test_full_name_confirms_mat_card_without_sku(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'T5920000',
            'name' => 'Dywanik elektroizolacyjny 20 KV',
            'manufacturer' => 'SECURA',
        ]);
        $url = 'https://centrumelektronarzedzi.pl/pl/p/Chodnik-elektroizolacyjny-20-KV-wymiary-1,1-x-2-m-Secura/48601';
        $title = 'Chodnik elektroizolacyjny 20 KV (wymiary 1,1 x 2 m) Secura';

        $this->assertTrue($id->hayHasDistinctiveNamePhrase($url.' '.$title, $product));
        $this->assertTrue($id->hayHasRequiredTypeFromName($url.' '.$title, $product));
        $this->assertFalse($id->pageClaimsAnotherCode($url, $title, $product));
        $this->assertTrue($id->isConfirmedProductCard($url, $title, '', $product));
        $this->assertFalse($id->hayHasDistinctiveNamePhrase(
            'https://shop.pl/pl/p/Chodnik-elektroizolacyjny-30-KV-Secura/1',
            $product
        ));
    }

    public function test_shop_category_gloves_does_not_reject_named_valve_flap(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'S56212-10',
            'name' => 'Płatek zaworu wydechowego',
            'manufacturer' => 'SECURA',
            'category' => 'REKAWICE ELEKTROIZOLACYJNE',
        ]);
        $url = 'https://domtechniczny24.pl/płatek-zaworu-wydechowego-secura-3000-do-półmasek.html';
        $hay = $url.' Płatek zaworu wydechowego SECURA 3000';

        $this->assertTrue($id->hayHasRequiredTypeFromName($hay, $product));
        $this->assertTrue($id->hayMentionsProduct($hay, $product));
        $this->assertTrue($id->isConfirmedProductCard($url, 'Płatek zaworu wydechowego SECURA 3000', '', $product));
        $this->assertFalse($id->hayMentionsProduct(
            'https://centralabhp.pl/pl/p/Rekawice-elektroizolacyjne-ELSEC-10-kV-SECURA/2820 '
            .'Rękawice elektroizolacyjne ELSEC 10 kV SECURA',
            $product
        ));
    }

    public function test_distinctive_name_confirms_cape_without_sku_in_url(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '910',
            'name' => 'PELERYNA DLA NIEPEŁNOSPRAWNYCH - WÓZEK AKTYWNY',
            'manufacturer' => 'AJ GROUP',
        ]);
        $url = 'https://dodatkimasarskiezwm.pl/111241-peleryna-dla-niepelnosprawnych-wozek-aktywny-pl';

        $this->assertTrue($id->hayHasDistinctiveNamePhrase($url, $product));
        $this->assertTrue($id->hayHasRequiredTypeFromName($url, $product));
        $this->assertFalse($id->pageClaimsAnotherCode($url, '', $product));
        $jacket = new Product([
            'sku' => '205',
            'name' => 'Kurtka oddychająca zapinana na zamek bryzgoszczelny',
            'manufacturer' => 'AJ GROUP',
        ]);
        $de = 'https://dodatkimasarskiezwm.pl/110221-kurtka-oddychajaca-zapinana-na-zamek-bryzgoszczelny-285-aj-group-pros--de';
        $this->assertTrue($id->hayHasDistinctiveNamePhrase($de, $jacket));
        $this->assertFalse($id->pageClaimsAnotherCode($de, 'Kurtka oddychająca 285', $jacket));
        $this->assertTrue($id->isConfirmedProductCard($de, 'Kurtka oddychająca 285', 'PROS', $jacket));
        $this->assertSame(
            'https://dodatkimasarskiezwm.pl/110221-kurtka-oddychajaca-zapinana-na-zamek-bryzgoszczelny-285-aj-group-pros--pl',
            $id->preferredLocaleUrl($de, $jacket)
        );

        $official = 'https://bemoregreen.eu/pl/peleryna/15-peleryna-na-wozek-aktywny-model-910.html';
        $empik = 'https://www.empik.com/peleryna-na-wozek-aktywny-model-910,p1497595532,moda-p';
        $this->assertContains('bemoregreen.eu', $id->officialCatalogHosts($product));
        $joined = implode(' | ', $id->searchQueries($product, 'manufacturer'));
        $this->assertStringContainsString('site:bemoregreen.eu', $joined);
        $this->assertStringContainsString('site:empik.com', $joined);
        $this->assertTrue($id->urlOrTitleCarriesCodeFamily($official, '', $product));
        $this->assertTrue($id->urlOrTitleCarriesCodeFamily($empik, '', $product));
        $this->assertTrue($id->isConfirmedProductCard(
            $official,
            'PELERYNA NA WÓZEK AKTYWNY MODEL 910',
            'AJ Group PROS Plavitex Eco',
            $product
        ));
        $this->assertTrue($id->isConfirmedProductCard(
            $empik,
            'Peleryna na wózek aktywny model 910',
            'AJ Group peleryna na wózek aktywny',
            $product
        ));

        $electric = new Product([
            'sku' => '911',
            'name' => 'PELERYNA DLA NIEPEŁNOSPRAWNYCH - WÓZEK ELEKRTYCZNY',
            'manufacturer' => 'AJ GROUP',
        ]);
        $electricCard = 'https://bemoregreen.eu/pl/peleryna/16-peleryna-na-wozek-elektryczny-model-911.html';
        $electricQueries = $id->searchQueries($electric, 'manufacturer');
        $this->assertSame('site:bemoregreen.eu 911', $electricQueries[0] ?? null);
        $this->assertContains('site:bemoregreen.eu 911', $electricQueries);
        $this->assertContains('site:pros.pl 911', $electricQueries);
        foreach ($id->searchQueries($electric, 'manufacturer') as $query) {
            if (! str_contains($query, 'site:')) {
                continue;
            }
            $this->assertStringContainsString('911', $query);
            $this->assertStringNotContainsString('PELERYNA DLA NIEPEŁNOSPRAWNYCH', $query);
        }
        $this->assertTrue($id->urlOrTitleCarriesCodeFamily($electricCard, '', $electric));
        $this->assertTrue($id->isConfirmedProductCard(
            $electricCard,
            'PELERYNA NA WÓZEK ELEKTRYCZNY MODEL 911',
            'AJ Group PROS',
            $electric
        ));
    }

    public function test_kids_jacket_760_searches_sportpros_first(): void
    {
        $id = new ProductSearchIdentity;
        $kidsJacket = new Product([
            'sku' => '760',
            'name' => 'Kurtka wodoodporna dziecięca',
            'manufacturer' => 'AJ GROUP',
        ]);
        $kidsCard = 'https://sportpros.pl/pl/dzieci/dziewczynki/kurtki/36-kurtka-wodoodporna-sportpros-dla-dziewczat-model-760.html';
        $kidsQueries = $id->searchQueries($kidsJacket, 'manufacturer');
        $this->assertSame('sportpros.pl', $id->officialCatalogHosts($kidsJacket)[0] ?? null);
        $this->assertSame('site:sportpros.pl 760', $kidsQueries[0] ?? null);
        $this->assertContains('site:sportpros.pl 760', $kidsQueries);
        $adultJacket = new Product([
            'sku' => '300',
            'name' => 'Kurtka wodoochronna zapinana na zamek + stójka + rynienka',
            'manufacturer' => 'AJ GROUP',
        ]);
        $adultQueries = $id->searchQueries($adultJacket, 'manufacturer');
        $this->assertSame('site:bemoregreen.eu 300', $adultQueries[0] ?? null);
        $this->assertContains('site:sportpros.pl 300', $adultQueries);
        $this->assertTrue($id->urlOrTitleCarriesCodeFamily($kidsCard, '', $kidsJacket));
        $this->assertTrue($id->isConfirmedProductCard(
            $kidsCard,
            'Kurtka wodoodporna SportPROS dla dziewcząt model 760',
            'AJ Group SportPROS',
            $kidsJacket
        ));
    }

    public function test_short_numeric_sku_rejects_other_model_on_same_shop(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '300',
            'name' => 'Kurtka wodoochronna zapinana na zamek + stójka + rynienka',
            'manufacturer' => 'AJ GROUP',
        ]);
        $ours = 'https://pros.pl/pl/odziez-wodoochronna-standard/63-kurtka-z-zamkiem-model-300.html';
        $other = 'https://pros.pl/pl/odziez-wodoochronna-standard/62-kurtka-z-zamkiem-model-103.html';

        $this->assertTrue($id->isConfirmedProductCard(
            $ours,
            'Kurtka z zamkiem model 300',
            'PROS',
            $product
        ));
        $this->assertTrue($id->pageClaimsAnotherCode($other, 'Kurtka z zamkiem model 103', $product));
        $this->assertFalse($id->isConfirmedProductCard(
            $other,
            'Kurtka z zamkiem model 103',
            'PROS',
            $product
        ));
    }

    public function test_bare_numeric_sku_searches_model_and_catalog_brand(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '725',
            'name' => 'Kurtka przeciwdeszczowa, damska',
            'manufacturer' => 'AJ GROUP',
        ]);
        $waders = 'https://waderspros.com/produkt/kurtka-przeciwdeszczowa-damska-pros-sports-model-725/';
        $paka = 'https://www.pakawork.pl/pl/products/kurtka-przeciwdeszczowa-pros-aj-sport-725-34-3309.html';

        $this->assertSame('PROS', $id->catalogSearchBrand($product));
        $queries = $id->primaryQueries($product);
        $this->assertSame('model 725 kurtka AJ GROUP PROS', $queries[0] ?? null);
        $this->assertContains('725 AJ GROUP PROS', $queries);
        $this->assertTrue($id->isConfirmedProductCard(
            $waders,
            'Kurtka przeciwdeszczowa damska PROS Sports model 725',
            'PROS',
            $product
        ));
        $this->assertTrue($id->isConfirmedProductCard(
            $paka,
            'Kurtka przeciwdeszczowa PROS AJ Sport 725',
            'PROS',
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

        $cap = new Product([
            'sku' => 'CZAPKA-DASZKIEM-GRZMOT-43',
            'name' => 'Czapka daszkiem GRZMOT',
            'manufacturer' => 'PANTHER',
        ]);
        $this->assertFalse($id->hayHasRequiredTypeFromName(
            'https://shop.example/spodnie-grzmot Spodnie GRZMOT PANTHER',
            $cap
        ));
        $this->assertTrue($id->hayHasRequiredTypeFromName(
            'https://shop.example/czapka-grzmot Czapka daszkiem GRZMOT',
            $cap
        ));
    }

    public function test_cofra_leemed_ignores_box_packaging_in_name(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'M040-B000',
            'name' => 'LEEMED (BOX/50PCS)',
            'manufacturer' => 'Cofra',
            'category' => 'SANIWEAR',
        ]);
        $url = 'https://www.cofra.it/en/protettori_vie_respiratorie/prodotto/136';

        $this->assertSame('LEEMED', $id->firstStrongShopPhrase($product));
        $this->assertNotContains('50PCS', $id->shopIdentityPhrases($product));
        $this->assertTrue($id->urlOrTitleHasNamedShopIdentity($url, 'LEEMED', $product));
        $this->assertTrue($id->hayMentionsProduct($url.' LEEMED', $product));

        $joined = implode(' | ', $id->searchQueries($product, 'manufacturer'));
        $this->assertStringContainsString('site:cofra.it LEEMED', $joined);
        $this->assertStringNotContainsString('site:cofra.it 50PCS', $joined);
    }

    public function test_dupont_tychem_quotes_or_in_sku_and_skips_bare_6000(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'TP 0 275T OR CE-L',
            'name' => 'Tychem® 6000 FR ThermoPro Apron',
            'manufacturer' => 'DuPont',
            'category' => 'Ochrona ciała',
        ]);

        $this->assertSame('Tychem 6000', $id->firstStrongShopPhrase($product));
        $this->assertTrue($id->looksLikeUnrelatedRetailHost('https://www.reddit.com/r/foo', $product));
        $this->assertTrue(ProductSearchIdentity::isJunkSearchHost('https://github.com/login'));

        $joined = implode(' | ', $id->searchQueries($product, 'manufacturer'));
        $this->assertStringContainsString('"TP 0 275T OR CE-L"', $joined);
        $this->assertStringContainsString('site:dupont.com Tychem 6000', $joined);
        $this->assertStringNotContainsString('site:dupont.com 6000', $joined);
        foreach ($id->searchQueries($product, 'manufacturer') as $query) {
            if (str_contains($query, '275T') && str_contains($query, ' OR ') && ! str_contains($query, '"')) {
                $this->fail('Niewycytowane OR w SKU: '.$query);
            }
        }
    }

    public function test_sir_waders_match_official_card_without_sku_in_url(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'MB2520',
            'name' => 'SAFETY PU waders S0 GREEN 39 - 47 1 4 Stock 1 €',
            'manufacturer' => 'SiR',
            'category' => 'Sklep - kategorie / Obuwie robocze i ochronne / Półbuty ochronne',
        ]);
        $card = 'https://www.sirsafety.com/safety-pu-waders SAFETY PU waders | Sir Safety System MB2520 S0';

        $this->assertContains('sirsafety.com', $id->officialCatalogHosts($product));
        $this->assertTrue($id->hayHasBrand($card, $product));
        $this->assertTrue($id->hayHasRequiredTypeFromName('https://www.sirsafety.com/safety-pu-waders SAFETY PU waders', $product));
        $this->assertTrue($id->hayMentionsProduct($card, $product));

        $joined = implode(' | ', $id->searchQueries($product, 'manufacturer'));
        $this->assertStringContainsString('site:sirsafety.com', $joined);
        $this->assertStringContainsString('MB2520', $joined);
    }

    public function test_aj_group_jacket_matches_pros_kangurka_card(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '3011',
            'name' => 'Kurtka Kangurka',
            'manufacturer' => 'AJ Group',
            'category' => 'Sklep - kategorie / Ochrona ciała i odzież robocza / Kombinezony robocze / Akcesoria do kombinezonów',
        ]);
        $card = 'https://pros.pl/pl/pros-extreme/249-kangurka-morska-model-3011.html '
            .'Kangurka morska model 3011';

        $this->assertContains('pros.pl', $id->officialCatalogHosts($product));
        $this->assertTrue($id->hayHasBrand($card, $product));
        $this->assertTrue($id->hayHasRequiredTypeFromName($card, $product));
        $this->assertTrue($id->hayMentionsProduct($card, $product));
        $this->assertFalse($id->pageClaimsAnotherCode(
            'https://pros.pl/pl/pros-extreme/249-kangurka-morska-model-3011.html',
            'Kangurka morska model 3011',
            $product
        ));

        $joined = implode(' | ', $id->searchQueries($product, 'manufacturer'));
        $this->assertStringContainsString('site:pros.pl', $joined);
        $this->assertStringNotContainsString('kombinezon', $joined);
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

    public function test_ansell_uses_mapped_shops_and_series_model_not_warehouse_sku(): void
    {
        $id = new ProductSearchIdentity;
        $boot = new Product([
            'sku' => 'YE30T-00192-09-G01',
            'name' => '3000-YE CVRL HOOD PVC BOOT 192-G01.5XL',
            'manufacturer' => 'Ansell',
        ]);
        $hood = new Product([
            'sku' => 'YE30T-00121-07-G02',
            'name' => '3000-YE CVRL HOOD 121-G02.3XL',
            'manufacturer' => 'Ansell',
        ]);

        $this->assertSame('192', $id->ansellCatalogBits($boot)['model']);
        $this->assertSame('121', $id->ansellCatalogBits($hood)['model']);
        $this->assertSame('AlphaTec 3000 192', $id->firstStrongShopPhrase($boot));
        $this->assertSame('AlphaTec 3000 121', $id->firstStrongShopPhrase($hood));
        $this->assertNotContains('G02', $id->shopIdentityPhrases($hood));
        $this->assertNotContains('BOOT 192', $id->shopIdentityPhrases($boot));

        $hosts = $id->ansellSearchHosts($boot);
        $this->assertSame('bpbhp.pl', $hosts[0] ?? null);
        $this->assertContains('ansell.com', $hosts);
        $this->assertContains('optimumbhp.pl', $hosts);
        $this->assertContains('kams.com.pl', $hosts);
        $this->assertContains('behapownia.pl', $hosts);

        $joined = implode(' | ', $id->searchQueries($boot, 'manufacturer'));
        $this->assertStringContainsString('site:bpbhp.pl AlphaTec 3000 192', $joined);
        $this->assertStringContainsString('site:optimumbhp.pl AlphaTec 3000 192', $joined);
        $this->assertStringContainsString('site:kams.com.pl AlphaTec 3000 192', $joined);
        $this->assertStringNotContainsString('site:bpbhp.pl YE30T-00192-09-G01', $joined);
        $this->assertStringNotContainsString('BOOT 192', $id->searchQueries($boot, 'manufacturer')[0] ?? '');

        $this->assertTrue($id->hayHasRequiredTypeFromName(
            'kombinezon ansell alphatec 3000 model 192',
            $boot
        ));
        $this->assertTrue($id->hayMentionsProduct(
            'https://optimumbhp.pl/kombinezon-ansell-alphatec-3000-model-192 '
            .'Kombinezon Ansell AlphaTec 3000 model 192',
            $boot
        ));
    }

    public function test_ansell_chin_strap_size_code_is_model_111(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'GR40-T-00-181-09',
            'name' => '4000-GR C/W HOOD, CHIN STRAP 181.5XL',
            'manufacturer' => 'ANSELL',
        ]);
        $otherSize = new Product([
            'sku' => 'GR40-T-00-182-07',
            'name' => '4000-GR C/W HOOD, CHIN STRAP 182.4XL',
            'manufacturer' => 'ANSELL',
        ]);

        $this->assertSame('111', $id->ansellCatalogBits($product)['model']);
        $this->assertSame('111', $id->ansellCatalogBits($otherSize)['model']);
        $this->assertSame('4000', $id->ansellCatalogBits($product)['series']);
        $this->assertSame('4000-GR C/W HOOD, CHIN STRAP', $id->ansellTradeName($product));
        $this->assertContains('111', $id->ansellStyleCodes($product));
        $this->assertNotContains('181', $id->ansellStyleCodes($product));

        $card = 'https://bpbhp.pl/kombinezon-ansell-alphatec-4000-model-111';
        $title = 'KOMBINEZON ANSELL ALPHATEC 4000 MODEL 111';
        $text = 'Kombinezon Ansell AlphaTec 4000 model 111. Ochrona typu 3/4/5.';
        $this->assertFalse($id->pageClaimsAnotherCode($card, $title, $product));
        $this->assertTrue($id->hayMentionsProduct($card.' '.$title.' '.$text, $product));
        $this->assertTrue($id->isConfirmedProductCard($card, $title, $text, $product));
        $this->assertTrue($id->pageClaimsAnotherCode(
            'https://bpbhp.pl/kombinezon-ansell-alphatec-4000-model-121',
            'Kombinezon AlphaTec 4000 model 121',
            $product
        ));

        $joined = implode(' | ', $id->searchQueries($product, 'manufacturer'));
        $this->assertStringContainsString('site:bpbhp.pl', $joined);
        $this->assertStringContainsString('111', $joined);
        $this->assertStringContainsString('AlphaTec', $joined);
    }

    public function test_ansell_faceseal_and_sock_size_token_keep_catalog_model(): void
    {
        $id = new ProductSearchIdentity;
        $faceseal = new Product([
            'sku' => 'GR40T-00151-09',
            'name' => '4000-GR CVRL FACESEAL 151.5XL',
            'manufacturer' => 'ANSELL',
        ]);
        $sock = new Product([
            'sku' => 'YE30TA00757-07',
            'name' => '3000-YE ENCAP AL AVNT2 SOCK 757.3XL',
            'manufacturer' => 'ANSELL',
        ]);

        $this->assertSame('151', $id->ansellCatalogBits($faceseal)['model']);
        $this->assertSame('757', $id->ansellCatalogBits($sock)['model']);
        $this->assertFalse($id->pageClaimsAnotherCode(
            'https://bpbhp.pl/kombinezon-ansell-alphatec-4000-model-151',
            'Kombinezon AlphaTec 4000 model 151',
            $faceseal
        ));
    }

    public function test_ansell_2000_apron_rejects_3000_card_and_uses_stitched_slug(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'WH20S-00213-00',
            'name' => '2000-WH APRON 213',
            'manufacturer' => 'ANSELL',
        ]);

        $this->assertSame('2000', $id->ansellCatalogBits($product)['series']);
        $this->assertSame('213', $id->ansellCatalogBits($product)['model']);
        $urls = $id->ansellOfficialProductUrls($product);
        $this->assertContains(
            'https://www.ansell.com/gb/en/products/alphatec-2000-standard-apron-stitched-model-213',
            $urls
        );
        $this->assertSame(
            'https://www.ansell.com/pl/pl/products/alphatec-2000-standard-apron-stitched-model-213',
            $urls[0] ?? null
        );

        $ok = 'https://www.ansell.com/gb/en/products/alphatec-2000-standard-apron-stitched-model-213';
        $this->assertFalse($id->pageClaimsAnotherCode($ok, 'AlphaTec 2000 Standard Apron Stitched Model 213', $product));
        $this->assertTrue($id->hayMentionsProduct(
            $ok.' AlphaTec 2000 Standard Apron — Model 213. Chemical protective apron.',
            $product
        ));
        $this->assertTrue($id->pageClaimsAnotherCode(
            'https://www.ansell.com/ap/en/products/alphatec-3000-apron-ultrasonically-welded-model-213',
            'AlphaTec 3000 Apron Ultrasonically Welded Model 213',
            $product
        ));

        $joined = implode(' | ', $id->searchQueries($product, 'manufacturer'));
        $this->assertStringContainsString('AlphaTec 2000 213', $joined);
        $this->assertStringContainsString('fartuch', $joined);
        $this->assertStringNotContainsString('kombinezon', $joined);
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

    public function test_open_search_keeps_more_than_two_shop_hosts(): void
    {
        $product = new Product([
            'sku' => 'G3175/40',
            'name' => 'Obuv TRACK',
            'manufacturer' => 'ARDON SAFETY',
        ]);
        $service = app(HybridWebSearchService::class);
        $ref = new ReflectionClass($service);
        $build = $ref->getMethod('buildQueries');
        $build->setAccessible(true);
        $open = $ref->getMethod('openSearchQueries');
        $open->setAccessible(true);
        /** @var list<string> $ladder */
        $ladder = $open->invoke($service, $product, $build->invoke($service, $product, 'manufacturer'));
        $joined = implode(' | ', $ladder);

        $this->assertStringContainsString('site:kams.com.pl', $joined);
        $this->assertMatchesRegularExpression('/site:kams\\.com\\.pl\\s+G3175\\b/i', $joined);
        $this->assertContains('G3175 ARDON SAFETY', $ladder);
        $identity = new ProductSearchIdentity;
        $this->assertContains('G3175/40 ARDON SAFETY', $identity->searchQueries($product, 'manufacturer'));
        $this->assertContains('G3175/40 ARDON SAFETY', $identity->primaryQueries($product));
        foreach ($ladder as $query) {
            if (str_starts_with($query, 'site:')) {
                $this->assertStringNotContainsString('G3175/40', $query);
                $this->assertStringNotContainsString('ARDON', $query);
            }
        }
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

    public function test_mapa_warehouse_sku_matches_official_model_slug(): void
    {
        $id = new ProductSearchIdentity;
        $krytech = new Product([
            'sku' => '34380358',
            'name' => 'KRYTECH 380',
            'manufacturer' => 'MAPA',
        ]);
        $ultra = new Product([
            'sku' => '34339019',
            'name' => 'ULTRANEO 339',
            'manufacturer' => 'MAPA',
        ]);

        $this->assertTrue($id->looksLikeWarehouseArticleSku($krytech));
        $this->assertTrue($id->hayMentionsProduct(
            'https://www.mapa-pro.pl/produkty/odpornosc-na-przeciecie/ciezkie-prace-manipulacyjne/strona-produktu/krytech-380 KryTech 380',
            $krytech
        ));
        $this->assertTrue($id->urlOrTitleHasShopIdentity(
            'https://pl.rs-online.com/web/p/rekawice-robocze/0440258',
            'MAPA KryTech 380 rękawice',
            $krytech
        ));
        $this->assertTrue($id->hayMentionsProduct(
            'https://www.mapa-pro.pl/produkty/strona-produktu/ultraneo-339 UltraNeo 339',
            $ultra
        ));
    }

    public function test_mapa_polybag_keeps_model_number_not_packaging(): void
    {
        $id = new ProductSearchIdentity;
        $n410 = new Product([
            'sku' => '34410008',
            'name' => 'ULTRANITRIL 410 - Polybag',
            'manufacturer' => 'MAPA',
        ]);
        $n358 = new Product([
            'sku' => '34358008',
            'name' => 'ULTRANITRIL 358 - POLYBAG',
            'manufacturer' => 'MAPA',
        ]);

        $this->assertSame('ULTRANITRIL 410', $id->mapaCatalogName($n410));
        $this->assertSame('ULTRANITRIL 410', $id->firstStrongShopPhrase($n410));
        $this->assertSame('ULTRANITRIL 358', $id->firstStrongShopPhrase($n358));
        $this->assertStringContainsString('site:mapa-pro.pl ULTRANITRIL 410', implode(' | ', $id->searchQueries($n410, 'manufacturer')));
        $this->assertStringNotContainsString('Polybag', $id->firstStrongShopPhrase($n410));

        $this->assertTrue($id->hayMentionsProduct(
            'https://www.mapa-pro.pl/produkty/chemioodporne/strona-produktu/ultranitril-410 UltraNitril 410',
            $n410
        ));
        $this->assertTrue($id->hayMentionsProduct(
            'https://www.mapa-pro.pl/produkty/chemioodporne/strona-produktu/ultranitril-358 UltraNitril 358',
            $n358
        ));
        $this->assertTrue($id->hayMentionsProduct(
            'https://int.rsdelivers.com/fi/product/mapa/34410008/mapa-ultranitril-410-black-yellow-nitrile-chloride/0315057 MAPA UltraNitril 410',
            $n410
        ));
        $this->assertTrue($id->urlOrTitleHasShopIdentity(
            'https://pl.rs-online.com/web/p/rekawice-robocze/0144921',
            'MAPA UltraNitril 410 rękawice',
            $n410
        ));
        $this->assertFalse($id->hayMentionsProduct(
            'https://www.mapa-pro.pl/produkty/chemioodporne/strona-produktu/ultranitril-472 UltraNitril 472',
            $n410
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

    public function test_ansell_product_source_keeps_working_locale(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '065-06',
            'name' => 'RINGERS R065',
            'manufacturer' => 'Ansell',
        ]);

        $this->assertSame(
            'https://www.ansell.com/gb/en/products/ringers-r065',
            $id->preferredLocaleUrl(
                'https://www.ansell.com/gb/en/products/ringers-r065',
                $product
            )
        );
        $this->assertSame(
            'https://www.ansell.com/us/en/products/hyflex-11-581',
            $id->preferredLocaleUrl(
                'https://www.ansell.com/us/en/products/hyflex-11-581',
                $product
            )
        );
        $this->assertSame(
            'https://www.ansell.com/pl/pl/products/bioclean-2000-hooded-coverall-model-111',
            $id->preferredLocaleUrl(
                'https://www.ansell.com/cn/zh-hans/products/bioclean-2000-hooded-coverall-model-111',
                $product
            )
        );
        $this->assertSame(
            'https://www.ansell.com/pl/pl/products/bioclean-2000-hooded-coverall-model-111',
            $id->preferredLocaleUrl(
                'https://www.ansell.com/lac/es/products/bioclean-2000-hooded-coverall-model-111',
                $product
            )
        );
        $this->assertSame(
            'https://www.ansell.com/pl/pl/products/bioclean-2000-hooded-coverall-model-111',
            $id->preferredLocaleUrl(
                'https://www.ansell.com/apac/en/products/bioclean-2000-hooded-coverall-model-111',
                $product
            )
        );
        $blog = 'https://www.ansell.com/hk/en/blogs/critical-insights/bioclean-2000';
        $this->assertSame($blog, $id->preferredLocaleUrl($blog, $product));
        $this->assertTrue($id->looksLikeNonProductCardUrl($blog));
    }

    public function test_ansell_tsplus_uses_bioclean_card_not_alphatec_or_blog(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'WH20T-00111-09',
            'name' => '2000-WH TSPLUS CVRL HOOD 111.5XL',
            'manufacturer' => 'ANSELL',
        ]);

        $this->assertTrue($id->ansellIsBioClean($product));
        $this->assertSame('111', $id->ansellCatalogBits($product)['model']);
        $this->assertSame('2000', $id->ansellCatalogBits($product)['series']);
        $urls = $id->ansellOfficialProductUrls($product);
        $this->assertSame(
            'https://www.ansell.com/pl/pl/products/bioclean-2000-hooded-coverall-model-111',
            $urls[0] ?? null
        );
        $this->assertContains(
            'https://www.ansell.com/gb/en/products/bioclean-2000-hooded-coverall-model-111',
            $urls
        );
        foreach ($urls as $url) {
            $this->assertStringNotContainsString('alphatec', $url);
        }

        $early = implode(' | ', $id->ansellSearchPhrases($product, 'early'));
        $this->assertStringContainsString('BioClean 2000 111', $early);
        $this->assertStringNotContainsString('AlphaTec', $early);

        $card = 'https://www.ansell.com/pl/pl/products/bioclean-2000-hooded-coverall-model-111';
        $this->assertTrue($id->hayMentionsProduct(
            $card.' BioClean 2000 hooded coverall Model 111 sterile',
            $product
        ));
        $this->assertFalse($id->pageClaimsAnotherCode(
            $card,
            'BioClean 2000 Hooded Coverall Model 111',
            $product
        ));
        $this->assertTrue($id->pageClaimsAnotherCode(
            'https://www.ansell.com/gb/en/products/alphatec-2000-standard-model-111',
            'AlphaTec 2000 Standard Model 111',
            $product
        ));
        $this->assertTrue($id->looksLikeNonProductCardUrl(
            'https://www.ansell.com/nz/en/blogs/critical-insights/cleanroom-coveralls'
        ));
        $this->assertFalse($id->isConfirmedProductCard(
            'https://www.ansell.com/hk/en/blogs/critical-insights/bioclean-2000',
            'BioClean 2000 Model 111',
            'Sterile disposable coverall BioClean 2000 model 111 for cleanrooms.',
            $product
        ));
    }

    public function test_ansell_wh20b_std_uses_alphatec_standard_not_bioclean(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'WH20B-00111-12',
            'name' => '2000-WH STD CVRL HOOD 111.8XL',
            'manufacturer' => 'ANSELL',
        ]);

        $this->assertFalse($id->ansellIsBioClean($product));
        $this->assertTrue($id->ansellPrefersStandardSlug($product));
        $this->assertSame('111', $id->ansellCatalogBits($product)['model']);
        $this->assertSame('2000', $id->ansellCatalogBits($product)['series']);

        $urls = $id->ansellOfficialProductUrls($product);
        $this->assertSame(
            'https://www.ansell.com/pl/pl/products/alphatec-2000-standard-model-111',
            $urls[0] ?? null
        );
        $this->assertContains(
            'https://www.ansell.com/gb/en/products/alphatec-2000-standard-bound-model-111',
            $urls
        );
        foreach ($urls as $url) {
            $this->assertStringNotContainsString('bioclean', $url);
        }

        $early = implode(' | ', $id->ansellSearchPhrases($product, 'early'));
        $this->assertStringContainsString('AlphaTec 2000 111', $early);
        $this->assertStringNotContainsString('BioClean', $early);

        $official = 'https://www.ansell.com/pl/pl/products/alphatec-2000-standard-model-111';
        $this->assertTrue($id->hayMentionsProduct(
            $official.' AlphaTec 2000 Standard coverall with hood Model 111',
            $product
        ));
        $this->assertTrue($id->isConfirmedProductCard(
            $official,
            'AlphaTec 2000 Standard Model 111',
            'Chemical protective coverall AlphaTec 2000 Standard with hood, model 111.',
            $product
        ));
        $this->assertTrue($id->pageClaimsAnotherCode(
            'https://www.ansell.com/pl/pl/products/bioclean-2000-coverall-with-hood-model-111',
            'BioClean 2000 Coverall with Hood Model 111',
            $product
        ));
        $this->assertTrue($id->pageClaimsAnotherCode(
            'https://www.ansell.com/gb/en/products/alphatec-2000-ultrasonically-welded-taped-model-111',
            'AlphaTec 2000 Ultrasonically Welded Model 111',
            $product
        ));

        $rubix = 'https://de.rubix.com/de/alphatec-2000-standard-behalter-modell-111/p-G4010103691';
        $this->assertTrue($id->ansellOfficialPathHasModel($rubix, $product));
        $this->assertTrue($id->hayMentionsProduct($rubix, $product));
        $this->assertTrue($id->isConfirmedProductCard(
            $rubix,
            'AlphaTec 2000 Standard Behälter Modell 111',
            'Chemikalienschutzoverall AlphaTec 2000 Standard Modell 111 mit Kapuze.',
            $product
        ));
    }

    public function test_ansell_3000_coverall_rejects_rs_hand_tools_and_wrong_model(): void
    {
        $id = new ProductSearchIdentity;
        $hood121 = new Product([
            'sku' => 'YE30T-00121-07-G02',
            'name' => '3000-YE CVRL HOOD 121-G02.3XL',
            'manufacturer' => 'Ansell',
        ]);
        $hood132 = new Product([
            'sku' => 'YE30T-00132-07',
            'name' => '3000-YE CVRL HOOD 132.3XL',
            'manufacturer' => 'ANSELL',
        ]);

        $this->assertSame('121', $id->ansellCatalogBits($hood121)['model']);
        $this->assertSame('3000', $id->ansellCatalogBits($hood121)['series']);
        $this->assertContains(
            'https://www.ansell.com/pl/pl/products/alphatec-3000-ultrasonically-welded-taped-model-121',
            $id->ansellOfficialProductUrls($hood121)
        );
        $this->assertContains(
            'https://www.ansell.com/pl/pl/products/alphatec-3000-ultrasonically-welded-taped-model-132',
            $id->ansellOfficialProductUrls($hood132)
        );

        $this->assertFalse($id->hayHasRequiredTypeFromName(
            'STAHLWILLE 9.53 mm Ratchet, 193mm Overall RS Stock No.: 127-8061',
            $hood121
        ));
        $this->assertTrue($id->hayHasRequiredTypeFromName(
            'AlphaTec 3000 protective overall with hood model 121',
            $hood121
        ));

        $ratchet = 'https://mt.rsdelivers.com/product/stahlwille/121 '
            .'Stahlwille 3/8in Reversible Ratchet 435QRN 193mm Overall '
            .'Manufacturers Part No.: 12111020 Hand Tools > Spanners, Sockets & Wrenches';
        $this->assertTrue($id->looksLikeUnrelatedHandToolPage($ratchet, $hood121));
        $this->assertFalse($id->hayMentionsProduct($ratchet, $hood121));
        $this->assertFalse($id->isConfirmedProductCard(
            'https://mt.rsdelivers.com/product/stahlwille/121',
            'STAHLWILLE 9.53 mm Ratchet, 193mm Overall',
            'Reversible ratchet 12111020. Hand Tools > Spanners. 193mm Overall.',
            $hood121
        ));
        $this->assertTrue($id->pageClaimsAnotherCode(
            'https://www.ansell.com/gb/en/products/alphatec-3000-ultrasonically-welded-taped-model-111',
            'AlphaTec 3000 Model 111',
            $hood121
        ));

        $driver = 'https://mt.rsdelivers.com/product/ck/t49144-040/ '
            .'CK Insulated Screwdriver RS Stock No.: 132-5274 VDE approved '
            .'Hand Tools > Screwdrivers 207mm Overall';
        $this->assertTrue($id->looksLikeUnrelatedHandToolPage($driver, $hood132));
        $this->assertFalse($id->isConfirmedProductCard(
            'https://mt.rsdelivers.com/product/ck/t49144-040/',
            'CK Insulated, 100 mm Blade VDE/1000V Approved, 207mm Overall',
            'C.K VDE SD Slotted Parallel Screwdrivers. RS Stock No.: 132-5274.',
            $hood132
        ));
        $this->assertTrue($id->pageClaimsAnotherCode(
            'https://www.ansell.com/gb/en/products/alphatec-2000-standard-bound-model-122',
            'AlphaTec 2000 Standard Bound Model 122',
            $hood132
        ));
        $this->assertTrue($id->hayMentionsProduct(
            'https://www.ansell.com/gb/en/products/alphatec-3000-ultrasonically-welded-taped-model-121 '
            .'Ansell AlphaTec 3000 coverall with hood Model 121',
            $hood121
        ));
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

    public function test_canis_shop_page_id_is_not_another_model(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'manufacturer' => 'CANIS SAFETY',
            'sku' => '420000600000',
            'name' => '-',
        ]);
        $url = 'https://www.canis.cz/pl/akcesoria-ochronne_c88493506174732/'
            .'ochrona-oczu_c88493506174866/maski-spawalnicze_c3021273269535846/'
            .'folia-ochronna-do-przylbicy-spawalniczej_p5845';

        $this->assertFalse($id->pageClaimsAnotherCode(
            $url,
            'Folia ochronna do przyłbicy spawalniczej',
            $product
        ));
        $this->assertTrue($id->isConfirmedProductCard(
            $url,
            'Folia ochronna do przyłbicy spawalniczej',
            'Kod: 4200-006-000-00 EAN: 420000600000 CANIS',
            $product
        ));
        $this->assertContains('4200-006-000-00', $id->catalogArticleCodes($product));
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

    public function test_size_suffix_sku_searches_model_and_rejects_other_type(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'G3175/40',
            'name' => 'Obuv TRACK',
            'manufacturer' => 'ARDON SAFETY',
        ]);

        $this->assertSame('G3175', $id->catalogSkuWithoutSize($product));
        $this->assertSame('A5016', $id->catalogSkuWithoutSize(new Product([
            'sku' => 'A5016/9',
            'name' => 'Rękawice BRAD',
            'manufacturer' => 'ARDON SAFETY',
        ])));
        $this->assertSame('PARKA', $id->catalogSkuWithoutSize(new Product([
            'sku' => 'PARKA/XL',
            'name' => 'Parka zimowa',
            'manufacturer' => 'ARDON SAFETY',
        ])));
        $this->assertContains('G3175', $id->skuSizeVariants($product));
        $this->assertSame('G3175 ARDON SAFETY', $id->primaryQueries($product)[0] ?? null);
        $this->assertTrue($id->hasDistinctiveCatalogSku($product));
        $this->assertSame('G 3175', $id->firstStrongShopPhrase($product));
        $this->assertTrue($id->isWeakShopIndexPhrase('TRACK', $product));
        $this->assertTrue($id->isWeakShopIndexPhrase('Obuv', $product));
        $this->assertFalse($id->isWeakShopIndexPhrase('G 3175', $product));
        $this->assertSame('obuwie', $id->requiredArticleTypeLabel($product));
        $indexCodes = app(CatalogIndexSearch::class)->codes($product);
        $this->assertContains('g3175', $indexCodes);
        $this->assertNotContains('track', $indexCodes);
        $this->assertNotContains('obuv', $indexCodes);

        $this->assertTrue($id->hayMentionsProduct(
            'https://kams.com.pl/p6119,track-ardon-buty-do-kostki-g3175-38-46.html '
            .'TRACK ARDON buty do kostki G3175',
            $product
        ));
        $this->assertFalse($id->hayMentionsProduct(
            'https://optimumbhp.pl/kurtka-zimowa-ardon-track '
            .'Kurtka ostrzegawcza ARDON TRACK EN 342',
            $product
        ));
        $this->assertFalse($id->isConfirmedProductCard(
            'https://optimumbhp.pl/kurtka-zimowa-ardon-track',
            'Kurtka ostrzegawcza ARDON TRACK',
            'Kurtka zimowa ARDON TRACK EN 342 EN 343 EN ISO 20471',
            $product
        ));
        $this->assertTrue($id->isConfirmedProductCard(
            'https://kams.com.pl/p6119,track-ardon-buty-do-kostki-z-nubuku-g3175-38-46.html',
            'TRACK ARDON buty do kostki',
            'Buty do kostki z nubuku G3175 38-46 ARDON',
            $product
        ));
    }

    public function test_distributor_prefix_sku_searches_name_and_catalog_code(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'WST068GM',
            'name' => 'ALFA Grey Meteorite',
            'manufacturer' => 'Whirlpool',
        ]);

        $this->assertTrue($id->looksLikeWarehouseArticleSku($product));
        $this->assertSame('ST068GM', $id->distributorPrefixedCatalogSku($product));
        $this->assertSame('ST068GM', $id->catalogSkuWithoutSize($product));
        $this->assertSame('ALFA Grey Meteorite', $id->firstStrongShopPhrase($product));
        $this->assertSame('ALFA Grey Meteorite U-Power', $id->primaryQueries($product)[0] ?? null);
        $this->assertContains('ST068GM', $id->primaryQueries($product));
        $this->assertContains('ST068GM U-Power', $id->primaryQueries($product));
        $this->assertContains('misterworker.com', $id->catalogSearchHosts($product));
        $this->assertContains('u-power.ai', $id->officialCatalogHosts($product));
        $joined = implode(' | ', $id->primaryQueries($product));
        $this->assertStringNotContainsString('Whirlpool', $joined);
        $mfr = implode(' | ', $id->searchQueries($product, 'manufacturer'));
        $this->assertStringNotContainsString('Whirlpool', $mfr);
        $this->assertStringContainsString('site:misterworker.com ST068GM', $mfr);
        $this->assertStringNotContainsString('site:gvarant.pl', $mfr);
        $this->assertStringNotContainsString('WST068GM', $id->productNameWithManufacturer($product));
        $this->assertTrue($id->hayMentionsProduct(
            'https://www.misterworker.com/en/u-power/alfa-grey-meteorite-four-seasons-work-pants-st068gm/74275.html '
            .'ALFA Grey Meteorite Four Seasons Work Pants ST068GM U-Power',
            $product
        ));
        $this->assertFalse($id->hayMentionsProduct(
            'https://www.imdb.com/title/tt123/ APEX movie Charlize Theron',
            $product
        ));
        $other = new Product([
            'sku' => 'WAB123CD',
            'name' => 'Inny model',
            'manufacturer' => 'Whirlpool',
        ]);
        $this->assertSame('AB123CD', $id->distributorPrefixedCatalogSku($other));
        $this->assertSame([], $id->inferredCatalogHosts($other));
        $this->assertSame('', $id->inferredBrandHint($other));
    }

    public function test_mat_legal_suffix_confirms_art_0100_and_rejects_sibling_art1006(): void
    {
        $id = new ProductSearchIdentity;
        $jacket = new Product([
            'sku' => '0100',
            'name' => 'Kurtka Wodoochronna Basic ( gumka w rękawie)',
            'manufacturer' => 'MAT Sp. z o.o.',
        ]);
        $kangurka = new Product([
            'sku' => '0104',
            'name' => 'Kurtka Wodoochronna Kangurka',
            'manufacturer' => 'MAT Sp. z o.o.',
        ]);
        $url0100 = 'https://www.mat.konin.pl/en/odziez-wodoochronna-mat-pcv/63-copy-of-.html';
        $text0100 = 'Rain Jacket Basic PCV art. 0100 This waterproof jacket is made of 350 g/m² '
            .'polyester knit. Reference 0101. Rain Coat MAT PVC art. 1201';
        $url1006 = 'https://www.mat.konin.pl/pl/odziez-wodoochronna-mat-pcv/57-ubranie-wodoochronne-sztormowe-art1006.html';
        $text1006 = 'Ubranie sztormowe wodoochronne art.1006. Kurtka typu kangurka. Indeks 1006. Materiał PVC.';

        $this->assertTrue($id->hayHasBrand($url0100.' '.$text0100, $jacket));
        $this->assertTrue($id->hayHasProductCode($text0100, $jacket));
        $this->assertTrue($id->isConfirmedProductCard($url0100, '', $text0100, $jacket));
        $this->assertFalse($id->isConfirmedProductCard($url1006, '', $text1006, $kangurka));
        $this->assertFalse($id->hayHasProductCode($text1006, $kangurka));
    }

    public function test_junk_hosts_do_not_confirm_generic_name_hits(): void
    {
        $id = new ProductSearchIdentity;
        $sedan = new Product([
            'sku' => 'MB1822',
            'name' => 'SEDAN shoe E0 (RO) YELLOW 39 - 40',
            'manufacturer' => 'SiR',
        ]);
        $wzor = new Product([
            'sku' => 'MEDIBUT-WZOR-012L',
            'name' => 'WZÓR 012L',
            'manufacturer' => 'MEDIBUT',
        ]);

        $this->assertTrue(ProductSearchIdentity::isJunkSearchHost('https://www.olx.pl/motoryzacja/samochody/q-sedan/'));
        $this->assertTrue(ProductSearchIdentity::isJunkSearchHost('https://pl.wiktionary.org/wiki/wz%C3%B3r'));
        $this->assertFalse($id->isConfirmedProductCard(
            'https://www.olx.pl/motoryzacja/samochody/q-sedan/',
            'Sedan — ogłoszenia',
            'Sprzedam sedan benzyna 2018. OLX motoryzacja samochody.',
            $sedan
        ));
        $this->assertFalse($id->isConfirmedProductCard(
            'https://pl.wiktionary.org/wiki/wzór',
            'wzór — Wiktionary',
            'wzór, wzoru, wzorze. Definicja słowa w słowniku.',
            $wzor
        ));
    }

    public function test_cofra_lucky_rejects_filmweb_and_keeps_catalog_card(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '0UE20191',
            'name' => 'LUCKY',
            'manufacturer' => 'Cofra',
        ]);
        $filmweb = 'https://www.filmweb.pl/reviews/recenzja-serialu-Lucky-I-Should-Be-So-Lucky';
        $review = 'Recenzja filmu Lucky (2026) - I Should Be So Lucky. Realizacja, jak to w produkcjach Apple’a, '
            .'stoi na wysokim poziomie, sceny akcji są kompetentne i czytelne.';
        $catalog = 'https://www.cofra.it/en/products/lucky';
        $shoe = 'Cofra LUCKY safety shoe S3. Półbuty ochronne ze skóry, podnosek kompozytowy.';

        $this->assertTrue(ProductSearchIdentity::isJunkSearchHost($filmweb));
        $this->assertFalse($id->hayMentionsProduct($filmweb.' Recenzja filmu Lucky (2026) '.$review, $product));
        $this->assertFalse($id->urlOrTitleHasNamedShopIdentity($filmweb, 'Recenzja filmu Lucky (2026)', $product));
        $this->assertFalse($id->isConfirmedProductCard($filmweb, 'Recenzja filmu Lucky (2026)', $review, $product));
        $this->assertTrue($id->isConfirmedProductCard($catalog, 'LUCKY Cofra', $shoe, $product));
    }

    public function test_warehouse_3m_sku_confirms_shop_model_without_stock_code(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '7100000860',
            'name' => 'Nakładka ochronna Bumpon™ SJ5202 firmy 3M™, kolor jasno brązowy',
            'manufacturer' => '3M',
        ]);
        $url = 'https://www.3m.com/3M/pl_PL/p/d/v0005202/';
        $title = '3M Bumpon SJ5202 nakładka ochronna';
        $text = 'Bumpon SJ5202 protective bumper. 3M adhesive cushion. Nakładka ochronna.';

        $this->assertTrue($id->looksLikeWarehouseArticleSku($product));
        $this->assertSame('SJ5202', $id->firstStrongShopPhrase($product));
        $this->assertFalse($id->codeInText($url.' '.$title, '7100000860'));
        $this->assertTrue($id->hayHasProductCode($url.' '.$title, $product));
        $this->assertTrue($id->urlOrTitleHasShopIdentity($url, $title, $product));
        $this->assertTrue($id->isConfirmedProductCard($url, $title, $text, $product));
        $this->assertTrue($id->looksLikeWarehouseArticleSku(new Product([
            'sku' => 'G62SBCONFIG-182',
            'name' => 'Gogle',
            'manufacturer' => '3M',
        ])));
    }

    public function test_sir_ma1120_confirms_reunion_without_sku_in_url(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'MA1120',
            'name' => 'REUNION glove BA GREY/BLUE 10',
            'manufacturer' => 'SiR',
        ]);
        $url = 'https://www.sirsafety.com/reunion-glove';
        $title = 'REUNION glove';
        $text = 'SIR SAFETY REUNION glove BA GREY/BLUE. Code MA1120.';

        $this->assertNotSame('', $id->firstStrongShopPhrase($product));
        $this->assertStringContainsString('reunion', mb_strtolower($id->firstStrongShopPhrase($product)));
        $this->assertTrue($id->skuDiffersFromStrongShopIdentity($product));
        $this->assertTrue($id->rawSkuIsOfflineNoise($product));
        $this->assertFalse($id->hayHasProductCode($url.' '.$title, $product));
        $this->assertTrue($id->isConfirmedProductCard($url, $title, $text, $product));
    }

    public function test_t51_3m_remaps_to_infield(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'T5100002',
            'name' => 'Gogle GONDOR PC AF AS UV',
            'manufacturer' => '3M',
        ]);

        $this->assertSame('Infield', $id->inferredBrandHint($product));
        $this->assertContains('infield-safety.com', $id->inferredCatalogHosts($product));
        $this->assertContains('infield-safety.com', $id->catalogSearchHosts($product));
        $this->assertStringContainsString('Infield', $id->queryWithManufacturer('GONDOR', $product));
        $this->assertTrue($id->isConfirmedProductCard(
            'https://bpbhp.pl/gogle-ochronne-infield-gondor',
            'Gogle ochronne Infield GONDOR',
            'Infield GONDOR PC AF AS UV gogle ochronne.',
            $product
        ));
    }

    public function test_t51_secura_raptor_uses_infield_host_and_model_name(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => 'T5163000',
            'name' => 'Okulary Raptor przezroczyste',
            'manufacturer' => 'SECURA',
        ]);
        $url = 'https://infield-safety.com/produkte/schutzbrillen/buegelbrillen/gutschein-25/';

        $this->assertSame('Infield', $id->inferredBrandHint($product));
        $this->assertContains('infield-safety.com', $id->officialCatalogHosts($product));
        $this->assertFalse($id->rawSkuIsOfflineNoise($product));
        $this->assertFalse($id->isWeakShopIndexPhrase('Raptor', $product));
        $this->assertContains('raptor', app(CatalogIndexSearch::class)->codes($product));
        $this->assertContains('Raptor', $id->shopIdentityPhrases($product));
        $this->assertNotContains('Okulary', $id->shopIdentityPhrases($product));
        $this->assertNotContains('przezroczyste', $id->shopIdentityPhrases($product));
        $this->assertTrue($id->hayHasRequiredTypeFromName($url.' raptor', $product));
        $this->assertTrue($id->urlOrTitleHasNamedShopIdentity($url, 'raptor schwarz', $product));
        $this->assertTrue($id->looksLikeNonProductCardUrl($url));
        $this->assertFalse($id->isConfirmedProductCard($url, 'raptor schwarz', 'RAPTOR Schutzbrille', $product));
        $this->assertFalse($id->isConfirmedProductCard(
            'https://infield-safety.com/impressum/',
            'Impressum',
            'INFIELD Safety GmbH Nordstraße 10a Telefon: +49 212 23234 0',
            $product
        ));
        $listing = 'RAPTOR Schutzbrille. Wähle eine Option DEFENDOR XL. Wähle eine Option LEVIOR.';
        $this->assertTrue($id->pageLooksLikeMultiProductListing($listing));
        $this->assertFalse($id->isConfirmedProductCard(
            'https://infield-safety.com/produkte/schutzbrillen/buegelbrillen/raptor/',
            'RAPTOR',
            $listing,
            $product
        ));
    }

    public function test_reis_fc_sku_remaps_to_dickies_but_plain_reis_stays(): void
    {
        $id = new ProductSearchIdentity;
        $tiber = new Product([
            'sku' => 'FC23530',
            'name' => 'SICHERHEITSHALBSCHUH TIBER S3',
            'manufacturer' => 'Reis',
        ]);
        $sandal = new Product([
            'sku' => '005-031',
            'name' => 'sandały S1P',
            'manufacturer' => 'Reis',
        ]);

        $this->assertSame('Dickies', $id->inferredBrandHint($tiber));
        $this->assertContains('workwearnation.com', $id->catalogSearchHosts($tiber));
        $this->assertSame('', $id->inferredBrandHint($sandal));
        $this->assertContains('reis.pl', $id->catalogSearchHosts($sandal));
    }

    public function test_showa_worklife_and_pip_6552_remap(): void
    {
        $id = new ProductSearchIdentity;
        $showa = new Product([
            'sku' => '222080',
            'name' => 'WorkLife Tiger Plus',
            'manufacturer' => 'Showa',
        ]);
        $pip = new Product([
            'sku' => '6552004',
            'name' => 'Cocoon Evo Shell Mid FLS S3H',
            'manufacturer' => 'PIP',
        ]);

        $this->assertSame('Otto Schachner', $id->inferredBrandHint($showa));
        $this->assertContains('os-safetycenter.de', $id->catalogSearchHosts($showa));
        $this->assertSame('Honeywell', $id->inferredBrandHint($pip));
        $this->assertContains('automation.honeywell.com', $id->catalogSearchHosts($pip));
    }

    public function test_mat_legal_suffix_resolves_konin_catalog_host(): void
    {
        $id = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '1005OS',
            'name' => 'Ubranie Wodoochronne Ostrzegawcze',
            'manufacturer' => 'MAT Sp. z o.o.',
        ]);

        $this->assertContains('mat.konin.pl', $id->officialCatalogHosts($product));
        $this->assertContains('mat.konin.pl', $id->catalogSearchHosts($product));
    }

    public function test_three_m_uses_catalog_code_from_name_not_warehouse_sku(): void
    {
        $id = new ProductSearchIdentity;
        $nozzle = new Product([
            'sku' => '1366874',
            'name' => 'DMS Dysze 3M™, czerwona, 50601',
            'manufacturer' => '3M',
        ]);
        $zero = new Product([
            'sku' => '2600',
            'name' => 'Gąbka szlifierska 3M™ Softback, Microfine, 02600',
            'manufacturer' => '3M',
        ]);
        $pn = new Product([
            'sku' => 'DC12',
            'name' => '3M™ mleczko polerskie 1L PN60150',
            'manufacturer' => '3M',
        ]);
        $stock = new Product([
            'sku' => '7100269255',
            'name' => '3M™ Pad podłogowy',
            'manufacturer' => '3M',
        ]);
        $cup = new Product([
            'sku' => '50404',
            'name' => 'Kubek do mieszania 3M™, 1550 ml, 50404',
            'manufacturer' => '3M',
        ]);

        $this->assertSame('50601', $id->firstStrongShopPhrase($nozzle));
        $this->assertTrue($id->skuDiffersFromStrongShopIdentity($nozzle));
        $this->assertStringContainsString('site:3m.com 50601', implode(' | ', $id->searchQueries($nozzle, 'manufacturer')));
        $this->assertTrue($id->hayHasProductCode('softback disc 02600 3m', $zero));
        $this->assertSame('PN60150', $id->firstStrongShopPhrase($pn));
        $this->assertTrue($id->skuDiffersFromStrongShopIdentity($pn));
        $this->assertSame(['7100269255'], $id->catalogArticleCodes($stock));
        $this->assertSame('50404', $id->firstStrongShopPhrase($cup));
        $this->assertNotSame('1550', $id->firstStrongShopPhrase($cup));
        $this->assertTrue($id->isOfficialThreeMProductUrl('https://www.3m.com/3M/pl_PL/p/d/v0005202/'));
        $this->assertTrue($id->isOfficialThreeMProductUrl('https://www.3mpolska.pl/3M/pl_PL/p/d/b40069952/'));
        $this->assertFalse($id->isOfficialThreeMProductUrl('https://www.3mpolska.pl/3M/pl_PL/'));
        $this->assertContains('3mpolska.pl', $id->catalogSearchHosts($cup));
        $this->assertTrue($id->isConfirmedProductCard(
            'https://www.3m.com/3M/pl_PL/p/d/v0123456/',
            '3M PPS Mixing Cup',
            'Kubek do mieszania 50404. Pojemność 1550 ml.',
            $cup
        ));
        $this->assertTrue($id->isConfirmedProductCard(
            'https://www.3mpolska.pl/3M/pl_PL/p/d/b40069952/',
            'Kubek do mieszania 3M PPS 50404',
            'Kubek do mieszania 50404. Pojemność 1550 ml.',
            $cup
        ));
    }

    public function test_three_m_three_digit_tape_uses_sku_not_color_words(): void
    {
        $id = new ProductSearchIdentity;
        $tape = new Product([
            'sku' => '427',
            'name' => 'Taśma aluminiowa 3M™ 427, srebrna, 610 mm x 55 m, 0.12 mm',
            'manufacturer' => '3M',
        ]);

        $this->assertSame('427', $id->firstStrongShopPhrase($tape));
        $this->assertContains('427', $id->threeMCatalogCodesFromName($tape));
        $this->assertNotContains('610', $id->threeMCatalogCodesFromName($tape));
        $this->assertTrue($id->isWeakShopIndexPhrase('srebrna', $tape));
        $this->assertTrue($id->isWeakShopIndexPhrase('aluminiowa', $tape));
        $this->assertFalse($id->isWeakShopIndexPhrase('427', $tape));
        $indexCodes = app(CatalogIndexSearch::class)->codes($tape);
        $this->assertContains('427', $indexCodes);
        $this->assertNotContains('srebrna', $indexCodes);
        $this->assertNotContains('aluminiowa', $indexCodes);
        $this->assertStringContainsString('site:3m.com 427', implode(' | ', $id->searchQueries($tape, 'manufacturer')));
        $this->assertTrue($id->hayHasRequiredTypeFromName(
            'https://www.3m.com/3M/en_US/p/d/v0000427/ 3M Aluminum Foil Tape 427',
            $tape
        ));
        $this->assertTrue($id->isConfirmedProductCard(
            'https://www.3m.com/3M/en_US/p/d/v0000427/',
            '3M Aluminum Foil Tape 427',
            '3M Aluminum Foil Tape 427 silver 610 mm.',
            $tape
        ));
    }
}
