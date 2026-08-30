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

    public function test_code_without_variant_segment_is_asked_for_too(): void
    {
        $product = new Product([
            'manufacturer' => 'MASKPOL',
            'sku' => 'MT-212-2',
            'name' => 'Maska MT 212/2',
            'category' => 'Maski',
        ]);
        $identity = app(ProductSearchIdentity::class);

        $this->assertSame(['MT 212'], $identity->variantBaseCodes($product));
        $this->assertContains('MT 212 MASKPOL', $identity->primaryQueries($product));
        $this->assertContains('MT 212 MASKPOL', $identity->searchQueries($product, 'manufacturer'));

        // kod bez członu z wariantem zostaje bez zmian
        $plain = new Product([
            'manufacturer' => 'Urgent',
            'sku' => 'URG-914',
            'name' => 'Kurtka ostrzegawcza URG-914',
        ]);
        $this->assertSame([], $identity->variantBaseCodes($plain));

        $mapa = new Product([
            'manufacturer' => 'MAPA',
            'sku' => 'KRYTECH-563-11',
            'name' => 'KRYTECH 563',
        ]);
        $this->assertSame(['KRYTECH 563'], $identity->variantBaseCodes($mapa));
        $this->assertContains('KRYTECH 563 MAPA', $identity->primaryQueries($mapa));
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
            // sklep skraca oznaczenie w adresie, człon z wariantem zostaje w treści karty
            [
                'url' => 'https://www.bezpieczni112.pl/maska-mt-212-p-8.html',
                'title' => 'MASKA MT 212',
                'snippet' => '',
            ],
            [
                'url' => 'https://www.bron.pl/maska-przeciwgazowa-maskpol-mt-213-2-cl2-mt-0213',
                'title' => 'Maska przeciwgazowa MASKPOL MT 213/2',
                'snippet' => '',
            ],
        ];

        /** @var list<array{url: string, title: string, snippet: string}> $confirmed */
        $confirmed = $confirm->invoke($service, $hits, $product);

        $this->assertSame(
            [
                'https://www.maskpol.com.pl/maski/maska-mt-212-2',
                'https://www.bezpieczni112.pl/maska-mt-212-p-8.html',
            ],
            array_column($confirmed, 'url')
        );
    }

    public function test_catalog_hit_with_shared_short_sku_requires_brand(): void
    {
        $product = new Product([
            'manufacturer' => 'Ejendals',
            'sku' => '104',
            'name' => 'Rękawica tekstylna TEGERA 104',
        ]);
        $service = app(HybridWebSearchService::class);
        $ref = new ReflectionClass($service);
        $confirm = $ref->getMethod('confirmedCatalogHits');
        $confirm->setAccessible(true);

        $confirmed = $confirm->invoke($service, [
            [
                'url' => 'https://bogarobhp.pl/kombinezon-wodoochronny-model-104-aj-group-pros',
                'title' => 'Kombinezon wodoochronny model 104',
                'snippet' => 'AJ Group / PROS',
            ],
            [
                'url' => 'https://icd.pl/rekawice-tegera-104',
                'title' => 'Rękawice Tegera 104',
                'snippet' => 'Ejendals',
            ],
        ], $product);

        $this->assertSame(
            ['https://icd.pl/rekawice-tegera-104'],
            array_column($confirmed, 'url')
        );
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
        $this->assertLessThanOrEqual(6, count($ladder));
        $this->assertContains('Kurtka ostrzegawcza Urgent', $ladder);
        $this->assertContains('Kurtka ostrzegawcza URG-914 Urgent', $ladder);
    }

    public function test_mapa_open_search_starts_on_polish_catalog(): void
    {
        $product = new Product([
            'manufacturer' => 'MAPA',
            'sku' => 'KRYTECH-563-11',
            'name' => 'KRYTECH 563',
        ]);
        $service = app(HybridWebSearchService::class);
        $ref = new ReflectionClass($service);
        $build = $ref->getMethod('buildQueries');
        $build->setAccessible(true);
        $open = $ref->getMethod('openSearchQueries');
        $open->setAccessible(true);

        /** @var list<string> $ladder */
        $ladder = $open->invoke($service, $product, $build->invoke($service, $product, 'manufacturer'));

        $this->assertNotEmpty($ladder);
        $this->assertStringContainsString('KRYTECH 563', $ladder[0] ?? '');
        $this->assertDoesNotMatchRegularExpression('/^\s*site:/', $ladder[0] ?? '');
        $this->assertStringContainsString('site:mapa-pro.pl', implode(' | ', $ladder));
    }

    public function test_ansell_open_search_starts_on_bpbhp(): void
    {
        $product = new Product([
            'manufacturer' => 'Ansell',
            'sku' => 'GR40T-00121-09',
            'name' => '4000-GR CVRL HOOD 121-G02.5XL',
        ]);
        $service = app(HybridWebSearchService::class);
        $ref = new ReflectionClass($service);
        $build = $ref->getMethod('buildQueries');
        $build->setAccessible(true);
        $open = $ref->getMethod('openSearchQueries');
        $open->setAccessible(true);

        /** @var list<string> $ladder */
        $ladder = $open->invoke($service, $product, $build->invoke($service, $product, 'manufacturer'));

        $this->assertNotEmpty($ladder);
        $this->assertDoesNotMatchRegularExpression('/^\s*site:/', $ladder[0] ?? '');
        $this->assertMatchesRegularExpression('/121/', $ladder[0] ?? '');
        $this->assertStringContainsString('site:bpbhp.pl', implode(' | ', $ladder));
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

    public function test_descriptive_apparel_sku_searches_model_not_type_words(): void
    {
        $identity = new ProductSearchIdentity;
        $cap = new Product([
            'manufacturer' => 'PANTHER',
            'sku' => 'CZAPKA-DASZKIEM-GRZMOT-DUO-74',
            'name' => 'Czapka daszkiem GRZMOT DUO',
        ]);
        $jacket = new Product([
            'manufacturer' => 'PANTHER',
            'sku' => 'KURTKA-OCIEPLANA-TARAJ-HV-55',
            'name' => 'Kurtka ocieplana TARAJ HV',
        ]);
        $sweat = new Product([
            'manufacturer' => 'PANTHER',
            'sku' => 'BLUZA-SZWEDZKA-GRZMOT-BASIC-57',
            'name' => 'Bluza szwedzka GRZMOT BASIC',
        ]);
        $shoes = new Product([
            'manufacturer' => 'MEDIBUT',
            'sku' => 'MEDIBUT-COMO-BASIC-HAPPY',
            'name' => 'Półbuty COMO BASIC HAPPY',
        ]);

        $this->assertSame('GRZMOT-DUO', $identity->internalSkuCore($cap));
        $this->assertSame('TARAJ-HV', $identity->internalSkuCore($jacket));
        $this->assertSame('GRZMOT-BASIC', $identity->internalSkuCore($sweat));
        $this->assertSame('', $identity->internalSkuCore($shoes));
        $this->assertContains('GRZMOT DUO', $identity->shopIdentityPhrases($cap));
        $this->assertContains('TARAJ HV', $identity->shopIdentityPhrases($jacket));
        $this->assertContains('GRZMOT BASIC', $identity->shopIdentityPhrases($sweat));
        $this->assertContains('COMO HAPPY', $identity->shopIdentityPhrases($shoes));
        $this->assertSame('COMO HAPPY', $identity->shopIdentityPhrases($shoes)[0] ?? null);

        $capQ = implode(' | ', $identity->primaryQueries($cap));
        $this->assertStringContainsString('GRZMOT DUO', $capQ);
        $this->assertStringNotContainsString('CZAPKA DASZKIEM PANTHER', $capQ);

        $this->assertTrue($identity->hayMentionsProduct(
            'https://sklep.example/czapka-grzmot-duo Czapka z daszkiem GRZMOT DUO PANTHER',
            $cap
        ));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://sklep.example/kurtka-ocieplana-taraj-hv Kurtka ocieplana TARAJ HV',
            $jacket
        ));
        $this->assertFalse($identity->pageClaimsAnotherCode(
            'https://sklep.example/kurtka-ocieplana-taraj-hv-55',
            'Kurtka ocieplana TARAJ HV 55',
            $jacket
        ));
        $this->assertFalse($identity->hayMentionsProduct(
            'https://sklep.example/kurtka-kardif Kurtka Kardif PANTHER',
            $jacket
        ));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://sklep.example/bluza-szwedzka-grzmot-basic Bluza szwedzka GRZMOT BASIC PANTHER',
            $sweat
        ));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://medibut.pl/polbuty-como-basic Półbuty Medibut COMO BASIC HAPPY',
            $shoes
        ));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://modernbhp.pl/klapki-medyczne-como-happy Klapki medyczne Como Happy',
            $shoes
        ));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://www.uniformshop.pl/obuwie-medyczne-medibut-como-print-happy '
            .'Obuwie medyczne Medibut Como Print happy',
            $shoes
        ));
        $this->assertFalse($identity->hayMentionsProduct(
            'https://www.uniformshop.pl/obuwie-medyczne-medibut-como-biale '
            .'Obuwie medyczne Medibut Como białe',
            $shoes
        ));
        $this->assertContains('panther-safety.com', $identity->catalogSearchHosts($cap));
        $this->assertContains('medibut.pl', $identity->catalogSearchHosts($shoes));
    }

    public function test_name_with_dash_variant_uses_shop_print_not_line_label(): void
    {
        $identity = new ProductSearchIdentity;
        $product = new Product([
            'manufacturer' => 'MEDIBUT',
            'sku' => 'MEDIBUT-COMO-BASIC-HAPPY',
            'name' => 'COMO BASIC - HAPPY',
        ]);

        $this->assertContains('COMO HAPPY', $identity->shopIdentityPhrases($product));
        $this->assertSame('COMO HAPPY MEDIBUT', $identity->primaryQueries($product)[0] ?? null);
        $this->assertTrue($identity->hayMentionsProduct(
            'https://obuwie-medyczne.pl/branze/como-happy COMO HAPPY',
            $product
        ));
        $this->assertTrue($identity->urlOrTitleHasShopIdentity(
            'https://modernbhp.pl/klapki-medyczne-como-happy',
            'Klapki medyczne Como Happy',
            $product
        ));
    }

    public function test_warehouse_article_sku_uses_model_and_catalog_number(): void
    {
        $identity = new ProductSearchIdentity;
        $kent = new Product([
            'manufacturer' => 'CANIS SAFETY',
            'sku' => '212804580000',
            'name' => 'KENT S3',
        ]);
        $beagle = new Product([
            'manufacturer' => 'CANIS SAFETY',
            'sku' => '211600170000',
            'name' => 'BEAGLE',
        ]);

        $this->assertTrue($identity->looksLikeWarehouseArticleSku($kent));
        $this->assertContains('2128-045-800', $identity->catalogArticleCodes($kent));
        $this->assertContains('KENT S3', $identity->shopIdentityPhrases($kent));
        $this->assertSame('KENT S3 CANIS SAFETY', $identity->primaryQueries($kent)[0] ?? null);
        $this->assertContains('212804580000 CANIS SAFETY', $identity->primaryQueries($kent));
        $this->assertStringContainsString('site:cxs.net.pl KENT S3', implode(' | ', $identity->searchQueries($kent, 'manufacturer')));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://www.ceneo.pl/polbut-kent-s3 Cxs Półbut Kent S3 2128-045-800',
            $kent
        ));
        $this->assertFalse($identity->hayMentionsProduct(
            'https://optimumbhp.pl/obuwie-robocze-cxs CXS Canis Marble S3',
            $kent
        ));

        $this->assertContains('BEAGLE', $identity->shopIdentityPhrases($beagle));
        $this->assertContains('2116-001-700', $identity->catalogArticleCodes($beagle));
        $this->assertSame('BEAGLE CANIS SAFETY', $identity->primaryQueries($beagle)[0] ?? null);
        $this->assertContains('211600170000 CANIS SAFETY', $identity->primaryQueries($beagle));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://cxs.net.pl/beagle-s1 Buty BEAGLE CANIS SAFETY',
            $beagle
        ));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://www.monterky-levne.cz/Obuv-kotnikova-DOG-BEAGLE-S1P-seda-d869.htm '
            .'Obuv kotníková DOG BEAGLE S1P 211600170000 CANIS',
            $beagle
        ));
        $this->assertFalse($identity->hayMentionsProduct(
            'https://sklep.example/tablica-uwaga-pies-beagle '
            .'Tablica informacyjna Uwaga pies Beagle CANIS SAFETY',
            $beagle
        ));

        $service = app(HybridWebSearchService::class);
        $ref = new ReflectionClass($service);
        $build = $ref->getMethod('buildQueries');
        $build->setAccessible(true);
        $open = $ref->getMethod('openSearchQueries');
        $open->setAccessible(true);
        /** @var list<string> $ladder */
        $ladder = $open->invoke($service, $beagle, $build->invoke($service, $beagle, 'manufacturer'));
        $this->assertSame('BEAGLE CANIS SAFETY', $ladder[0] ?? null);
        $this->assertContains('BEAGLE CANIS SAFETY', $ladder);
        $this->assertStringContainsString('site:cxs.net.pl BEAGLE', implode(' | ', $ladder));
        $this->assertNotSame('211600170000 CANIS SAFETY', $ladder[0] ?? null);

        $bojar = new Product([
            'manufacturer' => 'CANIS SAFETY',
            'sku' => '321001900010',
            'name' => 'BOJAR',
        ]);
        $plain = new Product([
            'manufacturer' => 'CANIS SAFETY',
            'sku' => '310000300010',
            'name' => 'ASTAR',
        ]);
        $this->assertTrue($identity->looksLikeWarehouseArticleSku($plain));
        $this->assertContains('3100-003-000', $identity->catalogArticleCodes($plain));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://www.gvarant.pl/rekawice-canis-bojar-321001900010budowlane '
            .'Rękawice Canis Bojar 321001900010budowlane Skóra',
            $bojar
        ));
        $this->assertTrue($identity->codeInText(
            'rekawice canis bojar 321001900010budowlane skora',
            '321001900010'
        ));
    }

    public function test_open_search_keeps_full_name_and_manufacturer(): void
    {
        $identity = new ProductSearchIdentity;
        $eider = new Product([
            'manufacturer' => 'Eider',
            'sku' => '9011080',
            'name' => 'Carbon ESD PU Top',
        ]);
        $service = app(HybridWebSearchService::class);
        $ref = new ReflectionClass($service);
        $build = $ref->getMethod('buildQueries');
        $build->setAccessible(true);
        $open = $ref->getMethod('openSearchQueries');
        $open->setAccessible(true);
        /** @var list<string> $ladder */
        $ladder = $open->invoke($service, $eider, $build->invoke($service, $eider, 'manufacturer'));

        $this->assertSame('Carbon ESD PU Top Eider', $identity->primaryQueries($eider)[0] ?? null);
        $this->assertSame('Carbon ESD PU Top Eider', $ladder[0] ?? null);
        $this->assertContains('9011080 Eider', $ladder);
        $this->assertNotSame('Carbon Eider', $ladder[0] ?? null);
    }

    public function test_catalog_code_opens_official_site_after_short_query(): void
    {
        $identity = new ProductSearchIdentity;
        $tx39 = new Product([
            'manufacturer' => 'Portwest',
            'sku' => 'TX39',
            'name' => 'Ogrodniczki robocze',
        ]);
        $this->assertTrue($identity->hasDistinctiveCatalogSku($tx39));
        $this->assertContains('portwest.com', $identity->officialCatalogHosts($tx39));

        $service = app(HybridWebSearchService::class);
        $ref = new ReflectionClass($service);
        $build = $ref->getMethod('buildQueries');
        $build->setAccessible(true);
        $open = $ref->getMethod('openSearchQueries');
        $open->setAccessible(true);
        /** @var list<string> $ladder */
        $ladder = $open->invoke($service, $tx39, $build->invoke($service, $tx39, 'manufacturer'));

        $this->assertSame('TX39 Portwest', $ladder[0] ?? null);
        $this->assertStringContainsString('site:gvarant.pl TX39', implode(' | ', $ladder));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://sklep-system.pl/ogrodniczki-portwest-tx39-bremen Ogrodniczki robocze Portwest TX39 BREMEN',
            $tx39
        ));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://workweargurus.com/portwest-tx39errxl Portwest TX39ERRXL Bremen Bib & Brace',
            $tx39
        ));
        $this->assertTrue($identity->pageClaimsAnotherCode(
            'https://e-bormann.com.pl/ogrodniczki-texo-tx12',
            'Ogrodniczki robocze dwukolorowe PORTWEST Texo TX12',
            $tx39
        ));
    }

    public function test_taille_it_suffix_searches_model_not_partial_letters(): void
    {
        $identity = new ProductSearchIdentity;
        $one = new Product([
            'manufacturer' => 'Rostaing',
            'sku' => 'ONE4ALL-IT08',
            'name' => 'T8 GLOVES FOR PRECISION WORK WITH WEAR INDICATOR',
        ]);
        $opex = new Product([
            'manufacturer' => 'Rostaing',
            'sku' => 'OPSBT11',
            'name' => 'T11 OPEX CUT RESISTANT GLOVES BLACK',
        ]);

        $this->assertSame(['ONE4ALL'], $identity->skuSizeVariants($one));
        $this->assertSame('ONE4ALL', $identity->internalSkuCore($one));
        $this->assertContains('ONE4ALL', $identity->shopIdentityPhrases($one));
        $this->assertSame('ONE4ALL Rostaing', $identity->primaryQueries($one)[0] ?? null);
        $this->assertTrue($identity->hayMentionsProduct(
            'https://www.rostaing.com/gant-de-jardinage-one4all Gant de jardinage ONE4ALL Rostaing',
            $one
        ));
        $this->assertFalse($identity->hayMentionsProduct(
            'https://www.rostaing.com/gant-duranit-plus Gant DURANIT PLUS Rostaing',
            $one
        ));

        $this->assertContains('OPSB', $identity->shopIdentityPhrases($opex));
        $this->assertSame('OPSB', $identity->shopIdentityPhrases($opex)[0] ?? null);
        $this->assertContains('OPSB', $identity->skuSizeVariants($opex));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://www.rostaing.com/gant-d-intervention-noir-renforce-opsb Gant d\'intervention noir renforcé OPSB+ Rostaing',
            $opex
        ));
        $this->assertFalse($identity->hayMentionsProduct(
            'https://www.rostaing.com/gant-duranit-plus Gant DURANIT PLUS Rostaing',
            $opex
        ));
        $this->assertStringContainsString('site:rostaing.com OPSB', implode(' | ', $identity->searchQueries($opex, 'manufacturer')));
    }

    public function test_mislabeled_and_warehouse_codes_use_model_not_noise(): void
    {
        $identity = new ProductSearchIdentity;
        $reis = new Product([
            'manufacturer' => 'Reis',
            'sku' => '005-031',
            'name' => 'sandały S1P non-metalic welur nowość',
        ]);
        $eider = new Product([
            'manufacturer' => 'Eider',
            'sku' => '31310080',
            'name' => 'Showa 310',
        ]);
        $sungboo = new Product([
            'manufacturer' => 'SUNGBOO',
            'sku' => '60494',
            'name' => 'C500 Foam 7,8,9,10,11 10',
        ]);
        $oxon = new Product([
            'manufacturer' => 'OX-ON',
            'sku' => '92066',
            'name' => 'OX-ON Flexible Advanced 1900 CE 06',
        ]);
        $urgent = new Product([
            'manufacturer' => 'URGENT',
            'sku' => 'PROS-URG-A-SPODNIE',
            'name' => 'URG-A (spodnie)',
        ]);
        $lebon = new Product([
            'manufacturer' => 'Lebon',
            'sku' => 'BP/TS/color/5B-3XL',
            'name' => 'Koszulka BP/TS color 5B (long sleeve + wentylacja pod pachami) 3XL wzwyż',
        ]);

        $reisPhrases = implode(' | ', $identity->shopIdentityPhrases($reis));
        $this->assertStringNotContainsString('nowość', mb_strtolower($reisPhrases));
        $this->assertStringNotContainsString('welur', mb_strtolower($reisPhrases));
        $this->assertContains('reis.pl', $identity->officialCatalogHosts($reis));
        $this->assertContains('reis.pl', $identity->catalogSearchHosts($reis));
        $this->assertContains('005-031 Reis', $identity->primaryQueries($reis));

        $genericReis = new Product([
            'manufacturer' => 'Reis',
            'sku' => '002-100',
            'name' => 'półbuty S2 na zam.',
        ]);
        $this->assertContains('reis.pl', $identity->catalogSearchHosts($genericReis));
        $this->assertStringContainsString(
            'site:reis.pl 002-100',
            implode(' | ', $identity->searchQueries($genericReis, 'manufacturer'))
        );

        $gvsHose = new Product([
            'manufacturer' => 'GVS',
            'sku' => '04-322-100',
            'name' => 'GVS 30M Breathing Airline Hose - CEJN',
        ]);
        $this->assertSame('', $identity->firstStrongShopPhrase($gvsHose));
        $gvsQueries = implode(' | ', $identity->searchQueries($gvsHose, 'manufacturer'));
        $this->assertStringContainsString('site:idsblast.com 04-322-100', $gvsQueries);
        $this->assertStringContainsString('04-322-100 GVS', $gvsQueries);
        $this->assertStringNotContainsString('site:gvs.com GVS CEJN', $gvsQueries);

        $this->assertContains('Showa 310', $identity->shopIdentityPhrases($eider));
        $this->assertContains('showa-glove.com', $identity->officialCatalogHosts($eider));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://showa-glove.com/product/310 Showa 310 assembly glove',
            $eider
        ));

        $this->assertContains('C500', $identity->shopIdentityPhrases($sungboo));
        $this->assertContains('uvex-safety.com', $identity->officialCatalogHosts($sungboo));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://www.uvex-safety.com/en/product/phynomic-c500-foam-60494 uvex phynomic C500 foam 60494',
            $sungboo
        ));

        $oxHosts = $identity->officialCatalogHosts($oxon);
        $this->assertContains('ox-on.com', $oxHosts);
        $this->assertNotContains('secubox.eu', $oxHosts);
        $this->assertNotContains('boxmetmedical.pl', $oxHosts);
        $this->assertContains('1900', $identity->shopIdentityPhrases($oxon));
        $this->assertSame('1900', $identity->firstStrongShopPhrase($oxon));

        $this->assertSame('URG-A', $identity->internalSkuCore($urgent));
        $this->assertContains('URG-A', $identity->shopIdentityPhrases($urgent));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://urgent.com.pl/urg-a Spodnie URG-A URGENT',
            $urgent
        ));

        $hsv = new Product([
            'manufacturer' => 'URGENT',
            'sku' => 'PROS-KURTKA-MESKA-HSV-3W1',
            'name' => 'KURTKA MĘSKA HSV KRÓTKA 3 W 1',
        ]);
        $this->assertSame('HSV-3W1', $identity->internalSkuCore($hsv));
        $this->assertContains('HSV 3W1', $identity->shopIdentityPhrases($hsv));
        $this->assertStringContainsString('HSV', $identity->primaryQueries($hsv)[0] ?? '');
        $this->assertStringContainsString('3W1', str_replace(' ', '', $identity->primaryQueries($hsv)[0] ?? ''));
        $this->assertTrue($identity->hayHasProductCode(
            'kurtka męska odblaskowa hsv 3 w 1 urg',
            $hsv
        ));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://www.professionalbhp.com/pl/p/Kurtka-meska-odblaskowa-HSV-3-W-1-URG/1136 '
            .'Kurtka męska odblaskowa HSV 3 W 1 URG',
            $hsv
        ));
        $this->assertFalse($identity->pageClaimsAnotherCode(
            'https://www.professionalbhp.com/pl/p/Kurtka-meska-odblaskowa-HSV-3-W-1-URG/1136',
            'Kurtka męska odblaskowa HSV 3 W 1 URG',
            $hsv
        ));

        $lebonQ = implode(' | ', $identity->primaryQueries($lebon));
        $this->assertNotSame('', $lebonQ);
        $this->assertStringNotContainsString('wzwyż', $lebonQ);
        $this->assertStringContainsString('BP', $lebonQ);
    }

    public function test_catalog_model_matches_without_warehouse_sku(): void
    {
        $identity = new ProductSearchIdentity;
        $kcl = new Product([
            'manufacturer' => 'KCL',
            'sku' => '047106941E',
            'name' => 'CovaSpec 471',
        ]);
        $honeywell = new Product([
            'manufacturer' => 'Honeywell',
            'sku' => '1002933',
            'name' => 'ALTOCHUT 1012 CE',
        ]);
        $joris = new Product([
            'manufacturer' => 'JORI',
            'sku' => '12111',
            'name' => 'BASIC Compo Low S3',
        ]);
        $blank = new Product([
            'manufacturer' => 'CANIS SAFETY',
            'sku' => '420000600000',
            'name' => '-',
        ]);

        $this->assertTrue($identity->looksLikeWarehouseArticleSku($kcl));
        $this->assertTrue($identity->looksLikeWarehouseArticleSku($honeywell));
        $this->assertContains('CovaSpec 471', $identity->shopIdentityPhrases($kcl));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://www.kcl.de/produkte/covaspec-471 CovaSpec 471 Chemikalienschutzhandschuh',
            $kcl
        ));
        $this->assertFalse($identity->pageClaimsAnotherCode(
            'https://www.hygi.de/kcl-covaspec-471-schutzhandschuhe',
            'KCL CovaSpec 471 Schutzhandschuhe – 1 Paar, Größe 6',
            $kcl
        ));
        $this->assertFalse($identity->pageClaimsAnotherCode(
            'https://cas-technik.eu/boe-471-6',
            'KCL CovaSpec 471+ protective gloves',
            $kcl
        ));
        $cejn = new Product([
            'manufacturer' => 'GVS',
            'sku' => '03-022-CF',
            'name' => 'CEJN Double Action Coupler FNPT',
        ]);
        $this->assertSame('CEJN', $identity->leadingNameBrand($cejn));
        $this->assertStringNotContainsString('GVS', $identity->queryWithManufacturer(
            'CEJN Double Action Coupler FNPT',
            $cejn
        ));
        $service = app(HybridWebSearchService::class);
        $ref = new ReflectionClass($service);
        $open = $ref->getMethod('openSearchQueries');
        $open->setAccessible(true);
        $build = $ref->getMethod('buildQueries');
        $build->setAccessible(true);
        $kclLadder = $open->invoke($service, $kcl, $build->invoke($service, $kcl, 'manufacturer'));
        $this->assertStringContainsString('CovaSpec 471', $kclLadder[0] ?? '');
        $this->assertDoesNotMatchRegularExpression('/^\s*site:/', $kclLadder[0] ?? '');
        $this->assertTrue($identity->hayMentionsProduct(
            'https://sps.honeywell.com/altochut-1012 ALTOCHUT 1012 CE harness',
            $honeywell
        ));
        $this->assertFalse($identity->pageClaimsAnotherCode(
            'https://sps.honeywell.com/altochut-1012',
            'ALTOCHUT 1012 CE',
            $honeywell
        ));
        $this->assertNotSame('Low S3', $identity->firstStrongShopPhrase($joris));
        $this->assertStringStartsWith('BASIC Compo Low S3', $identity->primaryQueries($joris)[0] ?? '');
        $this->assertSame('', $identity->usableProductName($blank));
        $this->assertStringNotContainsString(' - ', $identity->productNameWithManufacturer($blank));
        $this->assertStringContainsString('4200-006-000', implode(' | ', $identity->primaryQueries($blank)));

        $cofra = new Product([
            'manufacturer' => 'COFRA',
            'sku' => '00500-016',
            'name' => 'CRACKDOWN D.GREY',
        ]);
        $this->assertTrue($identity->urlOrTitleHasNamedShopIdentity(
            'https://www.cofra.it/en/products/crackdown',
            'CRACKDOWN D.GREY safety shoe',
            $cofra
        ));
        $this->assertStringContainsString('COFRA', $identity->primaryQueries($cofra)[0] ?? '');
        $this->assertFalse($identity->pageClaimsAnotherCode(
            'https://www.ironshop.it/antinfortunistica/493-scarpa-cofra-crackdown-dark-grey.html',
            'Scarpa Cofra Crackdown Dark Grey',
            $cofra
        ));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://www.ironshop.it/antinfortunistica/493-scarpa-cofra-crackdown-dark-grey.html '
            .'Scarpa Cofra Crackdown Dark Grey',
            $cofra
        ));
        $filter = $ref->getMethod('filterResultsByIdentity');
        $filter->setAccessible(true);
        $kept = $filter->invoke($service, [[
            'url' => 'https://www.hygi.de/kcl-handschuhe',
            'title' => 'KCL Schutzhandschuhe',
            'snippet' => 'KCL CovaSpec 471 PU coating size 6',
        ]], $kcl);
        $this->assertNotSame([], $kept);
        $this->assertStringContainsString('hygi.de', (string) ($kept[0]['url'] ?? ''));
    }

    public function test_g10_flex_glove_rejects_knife_and_keeps_sibling_size_card(): void
    {
        $identity = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '54335',
            'name' => 'KG G10 Flex Ntrl Glv Blue XL',
            'manufacturer' => 'Ansell',
        ]);

        $this->assertFalse($identity->isConfirmedProductCard(
            'https://www.bhp-gabi.pl/p24000,31-003686-noz-gerber-flatiron-folding-cleaver-g10-tan.html',
            'Nóż Gerber Flatiron Folding Cleaver G10 Tan',
            'Składany tasak Gerber z rękojeścią G-10. Stal 7Cr17MoV. Ostrze 9,7 cm.',
            $product
        ));
        $this->assertTrue($identity->isConfirmedProductCard(
            'https://labproinc.com/products/kg-g10-flex-ntrl-glv-blue-xs-54331',
            'Kimberly Clark KG G10 Flex Ntrl Glv Blue XS - 54331',
            'Powder free cleanroom nitrile gloves. Product Number 54331. Blue nitrile gloves 3 mil.',
            $product
        ));
        $this->assertFalse($identity->pageClaimsAnotherCode(
            'https://labproinc.com/products/kg-g10-flex-ntrl-glv-blue-xs-54331',
            'Kimberly Clark KG G10 Flex Ntrl Glv Blue XS - 54331',
            $product
        ));
    }

    public function test_ansell_g10_flex_does_not_match_easy_flex_card(): void
    {
        $identity = new ProductSearchIdentity;
        $product = new Product([
            'sku' => '54335',
            'name' => 'KG G10 Flex Ntrl Glv Blue XL',
            'manufacturer' => 'Ansell',
        ]);

        $this->assertFalse($identity->hayMentionsProduct(
            'https://www.gloves.co.uk/ansell-easy-flex-47-200-palm-coated-general-handling-gloves.html '
            .'Ansell ActivArmr 47-200 Palm-Coated General Handling Gloves Easy Flex nitrile coating',
            $product
        ));
        $this->assertTrue($identity->hayMentionsProduct(
            'https://www.ansell.com/us/en/products/kleenguard-g10-flex-blue-nitrile-gloves '
            .'KleenGuard G10 Flex Blue Nitrile Gloves 54335 XL',
            $product
        ));
    }

    public function test_gvs_part_searches_sku_on_rpb_catalogs_not_as_safety_shoe(): void
    {
        $identity = new ProductSearchIdentity;
        $door = new Product([
            'manufacturer' => 'GVS',
            'sku' => '03-815',
            'name' => 'GVS Battery Door Assembly with Battery Door Hinge 03-818 for PX5',
        ]);

        $this->assertContains('idsblast.com', $identity->catalogSearchHosts($door));
        $this->assertSame('idsblast.com', $identity->catalogSearchHosts($door)[0] ?? null);
        $queries = implode(' | ', $identity->searchQueries($door, 'manufacturer'));
        $this->assertStringContainsString('site:idsblast.com 03-815', $queries);
        $this->assertStringContainsString('03-815 GVS', $queries);
        $this->assertTrue($identity->hayMentionsProduct(
            'https://www.idsblast.com/03-815 RPB 03-815 PX5 Battery Door Assembly',
            $door
        ));
        $this->assertTrue($identity->isConfirmedProductCard(
            'https://www.idsblast.com/03-815',
            'RPB 03-815 PX5 Battery Door',
            'Replacement battery door assembly for the RPB PX5 PAPR. Part 03-815.',
            $door
        ));

        $service = app(HybridWebSearchService::class);
        $ref = new ReflectionClass($service);
        $legacy = $ref->getMethod('legacySafetyShoePhrase');
        $legacy->setAccessible(true);
        $this->assertSame('', $legacy->invoke($service, $door));
        $build = $ref->getMethod('buildQueries');
        $build->setAccessible(true);
        $open = $ref->getMethod('openSearchQueries');
        $open->setAccessible(true);
        /** @var list<string> $ladder */
        $ladder = $open->invoke($service, $door, $build->invoke($service, $door, 'manufacturer'));
        $this->assertDoesNotMatchRegularExpression('/buty ochronne/i', implode(' | ', $ladder));
        $this->assertStringContainsString('site:idsblast.com', implode(' | ', $ladder));

        $shoe = new Product([
            'manufacturer' => 'Reis',
            'sku' => '7-003 B',
            'name' => '7-003 B S1 SRC',
        ]);
        $this->assertSame('7-003 B S1 SRC', $legacy->invoke($service, $shoe));

        $coded = $ref->getMethod('resultsCarryProductCode');
        $coded->setAccessible(true);
        $kept = $coded->invoke($service, [
            [
                'url' => 'https://example.com/gvs-papr',
                'title' => 'GVS PX5 powered air respirator',
                'snippet' => 'GVS breathing apparatus',
            ],
            [
                'url' => 'https://www.idsblast.com/03-815',
                'title' => 'RPB 03-815 PX5 Battery Door',
                'snippet' => 'Battery door assembly',
            ],
        ], $door);
        $this->assertCount(1, $kept);
        $this->assertStringContainsString('idsblast.com/03-815', (string) ($kept[0]['url'] ?? ''));
    }
}
