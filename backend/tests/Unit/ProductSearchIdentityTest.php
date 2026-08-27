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
}
