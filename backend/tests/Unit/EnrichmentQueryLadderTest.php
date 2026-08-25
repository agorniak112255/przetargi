<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\Enrichment\HybridWebSearchService;
use App\Services\Enrichment\ProductSearchIdentity;
use ReflectionClass;
use Tests\TestCase;

final class EnrichmentQueryLadderTest extends TestCase
{
    public function test_shortest_query_is_sku_with_manufacturer(): void
    {
        $identity = new ProductSearchIdentity;
        $queries = $identity->primaryQueries(new Product([
            'manufacturer' => 'Urgent',
            'sku' => 'URG-914',
            'name' => 'Kurtka ostrzegawcza',
        ]));

        $this->assertSame('URG-914 Urgent', $queries[0] ?? null);
        $this->assertContains('Kurtka ostrzegawcza URG-914 Urgent', $queries);
    }

    public function test_house_code_with_short_number_searches_by_name_and_brand_first(): void
    {
        $identity = new ProductSearchIdentity;
        $product = new Product([
            'manufacturer' => 'URGENT',
            'sku' => 'PROS-121-S1-GUMA',
            'name' => '121 S1 GUMA',
        ]);

        $queries = $identity->primaryQueries($product);

        $this->assertSame('121 S1 GUMA URGENT', $queries[0] ?? null);
        $this->assertSame(
            '121 S1 GUMA URGENT buty ochronne BHP',
            $identity->productNameWithManufacturer($product)
        );
    }

    public function test_internal_sku_core_is_used_as_query(): void
    {
        $identity = new ProductSearchIdentity;
        $product = new Product([
            'manufacturer' => 'Urgent',
            'sku' => 'URG-C-SPODNIE',
            'name' => 'URG-C (spodnie)',
        ]);

        $this->assertSame('URG-C', $identity->internalSkuCore($product));
        $this->assertContains('URG-C Urgent', $identity->primaryQueries($product));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://optimumbhp.pl/spodnie-robocze-do-pasa-krotkie-urg-c-urgent-szorty-robocze-p138548 '
            .'Spodnie robocze URG-C Urgent',
            $product
        ));
    }

    public function test_internal_sku_is_not_used_as_query(): void
    {
        $identity = new ProductSearchIdentity;
        $queries = $identity->primaryQueries(new Product([
            'manufacturer' => 'Urgent',
            'sku' => 'URG-HSV-WOR-BLUZA',
            'name' => 'Bluza ostrzegawcza HSV',
        ]));

        foreach ($queries as $query) {
            $this->assertStringNotContainsString('URG-HSV-WOR-BLUZA', $query);
        }
        $this->assertSame('Bluza ostrzegawcza HSV Urgent', $queries[0] ?? null);
    }

    public function test_descriptive_sku_matches_by_brand_and_name(): void
    {
        $identity = new ProductSearchIdentity;
        $product = new Product([
            'manufacturer' => 'Urgent',
            'sku' => 'SKARPETY-POMARANCZ-ZOLTE',
            'name' => 'Skarpety pomarańczowo-żółte',
        ]);

        $this->assertTrue($identity->looksLikeInternalSku($product));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://sklep.example/skarpety-urgent-pomaranczowo-zolte Skarpety robocze Urgent pomarańczowe',
            $product
        ));
        $this->assertFalse($identity->hayMentionsProduct(
            'https://sklep.example/rekawice-urgent-1202 Rękawice Urgent kozia',
            $product
        ));
    }

    public function test_two_word_descriptive_sku_is_internal_but_short_code_is_not(): void
    {
        $identity = new ProductSearchIdentity;

        $this->assertTrue($identity->looksLikeInternalSku(new Product([
            'manufacturer' => 'Urgent',
            'sku' => 'WKLADKI-ALUTERMICZNE',
            'name' => 'Wkładki alutermiczne',
        ])));
        $this->assertFalse($identity->looksLikeInternalSku(new Product([
            'manufacturer' => 'Urgent',
            'sku' => 'URG-TOP',
            'name' => 'Czapka z daszkiem',
        ])));
    }

    public function test_model_name_from_sku_is_required_on_page(): void
    {
        $identity = new ProductSearchIdentity;
        $product = new Product([
            'manufacturer' => 'Rostaing',
            'sku' => 'COUPURE-IT11',
            'name' => 'T11 PRO CUT-RESISTANT GLOVES',
        ]);

        $this->assertSame('COUPURE', $identity->internalSkuCore($product));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://gloves.co.uk/rostaing-coupureit-cut-resistant-gloves.html Rostaing COUPURE IT',
            $product
        ));
        // karta rękawic termicznych tej samej marki nie może przejść po słowach z nazwy
        $this->assertFalse($identity->hayMentionsProduct(
            'https://www.thesafetysupplycompany.co.uk/p/9414695/rostaing-heat-resistant-gloves---mc-heatresist.html '
            .'Rostaing Heat Resistant Gloves',
            $product
        ));
    }

    public function test_model_glued_with_size_is_searched_without_the_size(): void
    {
        $identity = new ProductSearchIdentity;
        $product = new Product([
            'manufacturer' => 'Rostaing',
            'sku' => 'ERGOPRIMA45',
            'name' => '45CM ERGO ORANGE CUT CUFFS',
        ]);

        $this->assertSame('ERGOPRIMA', $identity->internalSkuCore($product));
        $this->assertContains('ERGOPRIMA Rostaing', $identity->primaryQueries($product));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://sklep.example/rostaing-ergoprima-rekaw Rostaing ERGOPRIMA rękaw',
            $product
        ));
    }

    public function test_french_size_marker_is_not_part_of_the_model_name(): void
    {
        $identity = new ProductSearchIdentity;

        $this->assertSame('BLACKTACTILTOUCH', $identity->internalSkuCore(new Product([
            'manufacturer' => 'Rostaing',
            'sku' => 'BLACKTACTILTOUCHT11',
            'name' => 'BLACK TACTIL TOUCH',
        ])));
        $this->assertSame('BLACKNIT', $identity->internalSkuCore(new Product([
            'manufacturer' => 'Rostaing',
            'sku' => 'BLACKNITT11',
            'name' => 'BLACKNIT',
        ])));
        // rozmiar 45 to nie taille, więc „T” zostaje częścią modelu
        $this->assertSame('COMFORT', $identity->internalSkuCore(new Product([
            'manufacturer' => 'Rostaing',
            'sku' => 'COMFORT45',
            'name' => 'Comfort 45',
        ])));
    }

    public function test_size_suffix_gives_searchable_code_variants(): void
    {
        $identity = new ProductSearchIdentity;

        $this->assertContains('BLACKSTICK30', $identity->skuSizeVariants(new Product([
            'manufacturer' => 'Rostaing',
            'sku' => 'BLACKSTICK30+T11',
            'name' => 'BLACK STICK 30+',
        ])));
        $this->assertContains('ATTACK6PEOM', $identity->skuSizeVariants(new Product([
            'manufacturer' => 'Rostaing',
            'sku' => 'ATTACK6PEOM-BSCT12',
            'name' => 'ATTACK 6 PEOM',
        ])));
        // „BLACK” samo w sobie pasowałoby do BLACKNIT i BLACKSTICK, więc wypada
        $variants = $identity->skuSizeVariants(new Product([
            'manufacturer' => 'Rostaing',
            'sku' => 'BLACK-FITT10',
            'name' => 'BLACK FIT',
        ]));
        $this->assertContains('BLACK-FIT', $variants);
        $this->assertNotContains('BLACK', $variants);
        // „T” bywa końcówką słowa, nie znacznikiem rozmiaru — oba odczyty muszą być
        $this->assertContains('CROSSFOREST', $identity->skuSizeVariants(new Product([
            'manufacturer' => 'Rostaing',
            'sku' => 'CROSSFOREST10',
            'name' => 'T10 GLOVES FOR CROSS X5 PAIRS',
        ])));
        $this->assertContains('CROSSBOHO', $identity->skuSizeVariants(new Product([
            'manufacturer' => 'Rostaing',
            'sku' => 'CROSSBOHOT06',
            'name' => 'T6 GLOVES ON CROSS X12 PAIRS',
        ])));
        // krótki kod modelu zostaje, bo nie jest zwykłym słowem
        $this->assertContains('BOHO', $identity->skuSizeVariants(new Product([
            'manufacturer' => 'Rostaing',
            'sku' => 'BOHO-IT08',
            'name' => 'BOHO',
        ])));
    }

    public function test_page_with_model_without_size_matches_when_brand_present(): void
    {
        $identity = new ProductSearchIdentity;
        $product = new Product([
            'manufacturer' => 'Rostaing',
            'sku' => 'BLACK-FITT10',
            'name' => 'BLACK FIT',
        ]);

        // nazwa produktu w cenniku to specyfikacja („T10 GLOVES…”), więc o dopasowaniu
        // decyduje wyłącznie wariant kodu w adresie karty producenta
        $product->name = 'T10 GLOVES F CUT GUARD 18 PU';
        $this->assertTrue($identity->hayMentionsProduct(
            'https://www.rostaing.com/gant-anti-coupure-ultra-fin-et-tactile-black-fit.html',
            $product
        ));
        // bez marki wariant kodu nie wystarcza
        $this->assertFalse($identity->hayMentionsProduct(
            'https://sklep.example/black-fit rękawice ogrodowe',
            $product
        ));
        $this->assertContains('BLACK-FIT Rostaing', $identity->primaryQueries($product));
    }

    public function test_short_name_matches_as_whole_phrase_with_brand(): void
    {
        $identity = new ProductSearchIdentity;
        $product = new Product([
            'manufacturer' => 'Rostaing',
            'sku' => 'BLACK-FITT06',
            'name' => 'BLACK FIT',
        ]);

        // „FIT” ma trzy litery, więc para słów nigdy się nie uzbiera — ratuje cała fraza
        $this->assertTrue($identity->hayMatchesNameAndBrand(
            'Rostaing Black Fit rękawice ogrodowe',
            $product
        ));
        $this->assertFalse($identity->hayMatchesNameAndBrand(
            'Rostaing Blackstick 30+ rękawice',
            $product
        ));
    }

    public function test_catalog_number_with_long_digits_keeps_full_code(): void
    {
        $identity = new ProductSearchIdentity;

        $this->assertSame('', $identity->internalSkuCore(new Product([
            'manufacturer' => 'ATG',
            'sku' => 'MAXIFLEX34874',
            'name' => 'MaxiFlex Ultimate',
        ])));
    }

    public function test_word_like_sku_needs_brand_on_page(): void
    {
        $identity = new ProductSearchIdentity;
        $product = new Product([
            'manufacturer' => 'Rostaing',
            'sku' => 'CASQUE',
            'name' => 'Casque de soudage',
        ]);

        // „casque” to po francusku kask — bez marki taka strona to przypadkowe trafienie
        $this->assertFalse($identity->hayMentionsProduct(
            'https://weldas.example/fr/protections-casque-de-soudage Protections pour casque',
            $product
        ));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://sklep.example/rostaing-casque Rostaing casque',
            $product
        ));
    }

    public function test_name_carrying_the_code_in_another_notation_is_not_glued_with_sku(): void
    {
        $identity = new ProductSearchIdentity;
        $queries = $identity->primaryQueries(new Product([
            'manufacturer' => 'MASKPOL',
            'sku' => 'MT-212-2',
            'name' => 'Maska MT 212/2',
        ]));

        $this->assertContains('Maska MT 212/2 MASKPOL', $queries);
        $this->assertNotContains('Maska MT 212/2 MT-212-2 MASKPOL', $queries);
    }

    public function test_only_page_titled_with_our_model_counts_as_product_card(): void
    {
        $product = new Product([
            'manufacturer' => 'MASKPOL',
            'sku' => 'MT-212-2',
            'name' => 'Maska MT 212/2',
            'category' => 'Maski',
        ]);
        $service = app(HybridWebSearchService::class);
        $ref = new ReflectionClass($service);
        $proves = $ref->getMethod('pageProvesProductIdentity');
        $proves->setAccessible(true);

        $body = 'Maska MT 212/2 zapewnia ochronę dróg oddechowych. MASKPOL.';

        // strona zbiorcza producenta wymienia nasz kod, ale kartą produktu nie jest
        $this->assertFalse($proves->invoke(
            $service,
            'https://www.maskpol.com.pl/oferta',
            'Oferta',
            'Maski MT-212/2, MT-213/2, MT-214. Filtropochłaniacze FP 211/1, FP 400. Hełmy HP-05. MASKPOL.',
            $product
        ));
        // sklep bywa skąpy w tytule, ale karta opisuje jeden produkt
        $this->assertTrue($proves->invoke(
            $service,
            'https://sklep.example/maski-przeciwgazowe-wojskowe',
            'Maska przeciwgazowa wojskowa',
            'Symbol: MT-212-2. Maska pełnotwarzowa MASKPOL do ochrony dróg oddechowych.',
            $product
        ));
        $this->assertTrue($proves->invoke(
            $service,
            'https://www.maskpol.com.pl/maski/maska-mt-212-2',
            'Maska MT 212/2',
            $body,
            $product
        ));
        // skrócone oznaczenie w adresie to nadal nasz model
        $this->assertTrue($proves->invoke(
            $service,
            'https://www.bezpieczni112.pl/maski/maska-mt-212',
            'MASKA MT 212',
            $body,
            $product
        ));
        // sąsiedni model tej samej marki odpada
        $this->assertFalse($proves->invoke(
            $service,
            'https://faser.com.pl/produkty/maski-pochlaniacze/maska-pelnotwarzowa-mt-213-2-danka-s/',
            'Maska pełnotwarzowa MT 213/2 DANKA S',
            $body,
            $product
        ));
    }

    public function test_catalog_index_hit_without_product_code_does_not_stop_web_search(): void
    {
        $product = new Product([
            'manufacturer' => 'MASKPOL',
            'sku' => 'MT-212-2',
            'name' => 'Maska MT 212/2',
            'category' => 'Maski',
        ]);
        $service = app(HybridWebSearchService::class);
        $ref = new ReflectionClass($service);
        $confirm = $ref->getMethod('confirmedCatalogHits');
        $confirm->setAccessible(true);

        $hits = [
            [
                'url' => 'https://www.maskpol.com.pl/filtropochlaniacze/filtropochlaniacz-fp-211-1-p3-w-me-ts',
                'title' => 'Filtropochłaniacz FP 211/1-P3/W-ME/TS',
                'snippet' => 'MASKPOL',
            ],
            [
                'url' => 'https://www.maskpol.com.pl/maski/maska-mt-212-2',
                'title' => 'Maska MT 212/2',
                'snippet' => 'MASKPOL',
            ],
        ];

        /** @var list<array{url: string, title: string, snippet: string}> $confirmed */
        $confirmed = $confirm->invoke($service, $hits, $product);

        $this->assertCount(1, $confirmed);
        $this->assertSame('https://www.maskpol.com.pl/maski/maska-mt-212-2', $confirmed[0]['url']);
    }

    public function test_open_search_starts_from_short_queries(): void
    {
        $product = new Product([
            'manufacturer' => 'Urgent',
            'sku' => 'URG-914',
            'name' => 'Kurtka ostrzegawcza',
        ]);
        $service = app(HybridWebSearchService::class);
        $ref = new ReflectionClass($service);

        $build = $ref->getMethod('buildQueries');
        $build->setAccessible(true);
        $open = $ref->getMethod('openSearchQueries');
        $open->setAccessible(true);

        /** @var list<string> $ladder */
        $ladder = $open->invoke($service, $product, $build->invoke($service, $product, 'manufacturer'));

        $this->assertSame('URG-914 Urgent', $ladder[0] ?? null);
        $this->assertLessThanOrEqual(4, count($ladder));
        $this->assertContains('Kurtka ostrzegawcza URG-914 Urgent', $ladder);
    }

    public function test_hi_vis_jacket_is_not_waterproof_clothing(): void
    {
        $identity = new ProductSearchIdentity;
        $phrase = $identity->productNameWithManufacturer(new Product([
            'manufacturer' => 'Urgent',
            'sku' => 'URG-914',
            'name' => 'Kurtka ostrzegawcza',
        ]));

        $this->assertStringNotContainsString('wodoochronne', $phrase);
        $this->assertStringContainsString('ostrzegawcza', $phrase);
    }
}
