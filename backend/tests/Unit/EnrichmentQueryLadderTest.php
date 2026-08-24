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
        $identity = new ProductSearchIdentity();
        $queries = $identity->primaryQueries(new Product([
            'manufacturer' => 'Urgent',
            'sku' => 'URG-914',
            'name' => 'Kurtka ostrzegawcza',
        ]));

        $this->assertSame('URG-914 Urgent', $queries[0] ?? null);
        $this->assertContains('Kurtka ostrzegawcza URG-914 Urgent', $queries);
    }

    public function test_internal_sku_core_is_used_as_query(): void
    {
        $identity = new ProductSearchIdentity();
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
        $identity = new ProductSearchIdentity();
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
        $identity = new ProductSearchIdentity();
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
        $identity = new ProductSearchIdentity();

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
        $identity = new ProductSearchIdentity();
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
        $identity = new ProductSearchIdentity();
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
        $identity = new ProductSearchIdentity();

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

    public function test_catalog_number_with_long_digits_keeps_full_code(): void
    {
        $identity = new ProductSearchIdentity();

        $this->assertSame('', $identity->internalSkuCore(new Product([
            'manufacturer' => 'ATG',
            'sku' => 'MAXIFLEX34874',
            'name' => 'MaxiFlex Ultimate',
        ])));
    }

    public function test_word_like_sku_needs_brand_on_page(): void
    {
        $identity = new ProductSearchIdentity();
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
        $this->assertLessThanOrEqual(3, count($ladder));
        $this->assertContains('Kurtka ostrzegawcza URG-914 Urgent', $ladder);
    }

    public function test_hi_vis_jacket_is_not_waterproof_clothing(): void
    {
        $identity = new ProductSearchIdentity();
        $phrase = $identity->productNameWithManufacturer(new Product([
            'manufacturer' => 'Urgent',
            'sku' => 'URG-914',
            'name' => 'Kurtka ostrzegawcza',
        ]));

        $this->assertStringNotContainsString('wodoochronne', $phrase);
        $this->assertStringContainsString('ostrzegawcza', $phrase);
    }
}
